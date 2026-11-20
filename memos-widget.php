<?php
/**
 * Plugin Name: Memos Widget
 * Description: 在侧边栏紧凑优雅地显示Memos最新动态
 * Version: 1.6.0
 * Author: 令爷
 * Author URI: https://www.zengqueling.com
 */

// 防止直接访问此文件
if (!defined('ABSPATH')) {
    exit;
}

define('MEMOS_WIDGET_VERSION', '1.6.0');

// 新版 REST 基址（Memos 移除 /api/v1 后）
define('MEMOS_WIDGET_ENDPOINT_REST', '/api/memos');
// 旧版 REST 基址（早期版本），仅作为回退
define('MEMOS_WIDGET_ENDPOINT_LEGACY', '/api/v1/memos');

// 注册后台设置页面与菜单
add_action('admin_menu', 'memos_widget_add_admin_menu');
function memos_widget_add_admin_menu() {
    add_options_page(
        'Memos Widget 设置',
        'Memos Widget',
        'manage_options',
        'memos-widget-settings',
        'memos_widget_render_settings_page'
    );
}

// 注册设置选项
add_action('admin_init', 'memos_widget_settings_init');
function memos_widget_settings_init() {
    register_setting('memos_widget_settings_group', 'memos_widget_api_url', array(
        'type' => 'string',
        'sanitize_callback' => 'esc_url_raw',
        'default' => ''
    ));
    register_setting('memos_widget_settings_group', 'memos_widget_access_token', array(
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default' => ''
    ));
}

// 当修改 API 地址或 Access Token 时清空缓存
add_action('update_option_memos_widget_api_url', 'memos_widget_clear_transients');
add_action('update_option_memos_widget_access_token', 'memos_widget_clear_transients');

// 插件版本变化时自动清空旧缓存（升级后立即生效，无需等待 5 分钟）
add_action('admin_init', 'memos_widget_maybe_flush_on_upgrade');
function memos_widget_maybe_flush_on_upgrade() {
    $stored = get_option('memos_widget_version');
    if ($stored !== MEMOS_WIDGET_VERSION) {
        memos_widget_clear_transients();
        update_option('memos_widget_version', MEMOS_WIDGET_VERSION);
    }
}

function memos_widget_clear_transients() {
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_memos_widget_cache_%' OR option_name LIKE '_transient_timeout_memos_widget_cache_%'");
}

// 快捷设置链接（在插件列表页面）
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'memos_widget_action_links');
function memos_widget_action_links($links) {
    $settings_link = '<a href="options-general.php?page=memos-widget-settings">设置</a>';
    array_unshift($links, $settings_link);
    return $links;
}

/**
 * 从 API 返回体里解析出 memo 列表，兼容多种历史返回结构
 */
function memos_widget_extract_list($data) {
    if (!is_array($data)) {
        return array();
    }
    if (isset($data['memos']) && is_array($data['memos'])) {
        return $data['memos'];
    }
    if (isset($data['data']) && is_array($data['data'])) {
        return $data['data'];
    }
    // 兼容最老的直接返回数组的接口
    if (isset($data[0]) && is_array($data[0])) {
        return $data;
    }
    return array();
}

/**
 * 取 memo 的 uid（新版返回 name = "memos/<uid>"）
 */
function memos_widget_memo_uid($memo) {
    if (!empty($memo['uid'])) {
        return $memo['uid'];
    }
    if (!empty($memo['name']) && is_string($memo['name']) && strpos($memo['name'], '/') !== false) {
        $parts = explode('/', $memo['name']);
        return end($parts);
    }
    if (!empty($memo['id'])) {
        return $memo['id'];
    }
    return '';
}

/**
 * 把 Markdown 正文压成适合侧边栏展示的纯文本摘要
 */
function memos_widget_make_excerpt($content, $length) {
    $text = (string) $content;

    // 图片语法
    $text = preg_replace('/!\[[^\]]*\]\([^)]*\)/u', '', $text);
    // 链接只保留文字
    $text = preg_replace('/\[([^\]]*)\]\([^)]*\)/u', '$1', $text);
    // 标题、引用标记
    $text = preg_replace('/^\s{0,3}#{1,6}\s*/mu', '', $text);
    $text = preg_replace('/^\s{0,3}>\s?/mu', '', $text);
    // 常见行内强调符号
    $text = str_replace(array('**', '__', '~~', '`'), '', $text);
    // 折叠空白与换行
    $text = preg_replace('/\s+/u', ' ', $text);
    $text = trim($text);

    if ($length > 0 && mb_strlen($text) > $length) {
        $text = mb_substr($text, 0, $length) . '...';
    }
    return $text;
}

/**
 * 请求 Memos API。
 * 优先使用新版 /api/memos；若实例尚未升级（返回 404/410），自动回退到 /api/v1/memos。
 * 注意：Connect 路径 /memos.api.v1.MemoService/* 也已废弃，这里一律不走。
 *
 * @return array { data: array|null, error: string, code: int, endpoint: string }
 */
function memos_widget_fetch_memos($api_url, $access_token, $page_size) {
    $api_url = rtrim($api_url, '/');

    $rest_url = $api_url . MEMOS_WIDGET_ENDPOINT_REST . '?pageSize=' . absint($page_size);
    $legacy_url = $api_url . MEMOS_WIDGET_ENDPOINT_LEGACY . '?pageSize=' . absint($page_size);

    // 记住上次成功的接口风格，减少一次无效请求
    $preferred = get_option('memos_widget_api_style', 'rest');
    $endpoints = ($preferred === 'legacy')
        ? array('legacy' => $legacy_url, 'rest' => $rest_url)
        : array('rest' => $rest_url, 'legacy' => $legacy_url);

    $last_error = '';
    $last_code = 0;

    foreach ($endpoints as $style => $url) {
        $request_args = array(
            'timeout' => 10,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            )
        );
        if (!empty($access_token)) {
            $request_args['headers']['Authorization'] = 'Bearer ' . $access_token;
        }

        $response = wp_remote_get($url, $request_args);

        if (is_wp_error($response)) {
            return array(
                'data' => null,
                'error' => $response->get_error_message(),
                'code' => 0,
                'endpoint' => $url
            );
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        if ($code === 200) {
            $list = memos_widget_extract_list($decoded);
            if (!empty($list) || (is_array($decoded) && isset($decoded['memos']))) {
                update_option('memos_widget_api_style', $style);
                return array(
                    'data' => $decoded,
                    'error' => '',
                    'code' => 200,
                    'endpoint' => $url
                );
            }
            // 200 但结构无法解析，换下一个端点再试
            $last_error = '返回数据格式无法解析';
            $last_code = 200;
            continue;
        }

        // 端点已废弃或不存在 → 尝试下一个端点
        if (in_array($code, array(404, 410, 501), true)) {
            $msg = '';
            if (is_array($decoded) && !empty($decoded['message'])) {
                $msg = (string) $decoded['message'];
            }
            $last_error = ($msg !== '') ? $msg : ('HTTP ' . $code);
            $last_code = $code;
            continue;
        }

        // 鉴权失败等，直接返回
        $msg = '';
        if (is_array($decoded) && !empty($decoded['message'])) {
            $msg = (string) $decoded['message'];
        }
        if ($code === 401) {
            $msg = '401 未授权（请在后台设置正确的 Access Token）';
        } elseif ($msg === '') {
            $msg = 'HTTP ' . $code;
        }
        return array(
            'data' => null,
            'error' => $msg,
            'code' => $code,
            'endpoint' => $url
        );
    }

    return array(
        'data' => null,
        'error' => ($last_error !== '') ? $last_error : '无法连接 Memos API',
        'code' => $last_code,
        'endpoint' => $rest_url
    );
}

/**
 * 获取 memo 列表（带 transient 缓存）
 *
 * @return array { data: array|null, error: string }
 */
function memos_widget_get_memos($page_size) {
    $api_url = get_option('memos_widget_api_url', '');
    $access_token = get_option('memos_widget_access_token', '');

    if (empty($api_url)) {
        return array('data' => null, 'error' => '请在 WordPress 后台设置中配置 Memos API 地址');
    }

    $api_url = rtrim($api_url, '/');
    $transient_key = 'memos_widget_cache_' . md5($api_url . '_' . $access_token . '_' . $page_size);
    $cached = get_transient($transient_key);

    if ($cached !== false) {
        return array('data' => $cached, 'error' => '');
    }

    $result = memos_widget_fetch_memos($api_url, $access_token, $page_size);
    if (!empty($result['error'])) {
        return array('data' => null, 'error' => $result['error']);
    }

    set_transient($transient_key, $result['data'], 300);
    return array('data' => $result['data'], 'error' => '');
}

/**
 * 渲染 memo 列表 HTML
 */
function memos_widget_render_list_html($memos, $api_url, $content_length) {
    $memos_list = memos_widget_extract_list($memos);

    if (empty($memos_list)) {
        echo '<p class="memos-empty">暂无动态</p>';
        return;
    }

    echo '<div class="memos-widget-wrapper">';
    echo '<ul class="memos-list">';
    foreach ($memos_list as $memo) {
        $truncated = memos_widget_make_excerpt(isset($memo['content']) ? $memo['content'] : '', $content_length);

        $raw_time = isset($memo['createTime']) ? $memo['createTime'] : (isset($memo['displayTime']) ? $memo['displayTime'] : '');
        $time_str = '';
        if (!empty($raw_time)) {
            $timestamp = strtotime($raw_time);
            if ($timestamp) {
                $time_str = date('Y-m-d', $timestamp);
            }
        }

        $tags = array();
        if (isset($memo['tags']) && is_array($memo['tags'])) {
            $tags = $memo['tags'];
        }

        $memo_id = memos_widget_memo_uid($memo);
        $more_url = !empty($memo_id) ? $api_url . '/memos/' . rawurlencode($memo_id) : $api_url;

        echo '<li class="memos-item">';
        echo '<div class="memos-content">' . esc_html($truncated) . '</div>';

        if (!empty($tags)) {
            echo '<div class="memos-tags">';
            foreach ($tags as $tag) {
                $tag_name = ltrim((string) $tag, '#');
                echo '<span class="memos-tag">#' . esc_html($tag_name) . '</span>';
            }
            echo '</div>';
        }

        echo '<div class="memos-footer">';
        if (!empty($time_str)) {
            echo '<span class="memos-time">';
            echo '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:2px"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>';
            echo esc_html($time_str);
            echo '</span>';
        }
        echo '<a class="memos-more" href="' . esc_url($more_url) . '" target="_blank">查看原文 ↗</a>';
        echo '</div>'; // .memos-footer

        echo '</li>';
    }
    echo '</ul>';
    echo '</div>'; // .memos-widget-wrapper
}

/**
 * 输出默认样式（页面内只输出一次）
 */
function memos_widget_render_styles() {
    static $style_rendered = false;
    if ($style_rendered) {
        return;
    }
    $style_rendered = true;
    ?>
    <style>
        .memos-widget-wrapper {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }
        .memos-list {
            list-style: none;
            padding: 0;
            margin: 0;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .memos-item {
            padding: 10px 12px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.02);
            transition: all 0.2s ease;
            position: relative;
            overflow: hidden;
        }
        .memos-item::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            width: 3px;
            height: 100%;
            background: linear-gradient(180deg, #3b82f6 0%, #60a5fa 100%);
            opacity: 0;
            transition: opacity 0.2s ease;
        }
        .memos-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.08);
            border-color: #cbd5e1;
        }
        .memos-item:hover::before {
            opacity: 1;
        }
        .memos-content {
            color: #1e293b;
            font-size: 13px;
            line-height: 1.5;
            margin-bottom: 6px;
            word-wrap: break-word;
            white-space: pre-wrap;
        }
        .memos-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            margin-bottom: 6px;
        }
        .memos-tag {
            font-size: 10px;
            color: #3b82f6;
            background: #eff6ff;
            padding: 1px 6px;
            border-radius: 6px;
            font-weight: 500;
        }
        .memos-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 4px;
            padding-top: 4px;
            border-top: 1px dashed #f1f5f9;
        }
        .memos-time {
            color: #94a3b8;
            font-size: 11px;
            display: flex;
            align-items: center;
        }
        .memos-more {
            color: #3b82f6;
            text-decoration: none;
            font-size: 11px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            transition: all 0.2s ease;
        }
        .memos-more:hover {
            color: #1d4ed8;
            transform: translateX(2px);
        }
        .memos-error {
            color: #ef4444;
            font-size: 12px;
            padding: 8px 10px;
            background: #fef2f2;
            border-radius: 6px;
            border: 1px solid #fee2e2;
        }
        .memos-empty {
            color: #94a3b8;
            font-size: 12px;
        }
        @media (prefers-color-scheme: dark) {
            .memos-item {
                background: #1e293b;
                border-color: #334155;
            }
            .memos-item:hover {
                border-color: #475569;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
            }
            .memos-content {
                color: #f1f5f9;
            }
            .memos-footer {
                border-top-color: #334155;
            }
            .memos-tag {
                background: #1e3a8a;
                color: #93c5fd;
            }
        }
    </style>
    <?php
}

/**
 * 输出（或返回）完整的动态列表
 */
function memos_widget_render_output($page_size, $content_length) {
    $result = memos_widget_get_memos($page_size);

    if (!empty($result['error'])) {
        echo '<p class="memos-error">获取 Memos 动态失败：' . esc_html($result['error']) . '</p>';
    } elseif ($result['data']) {
        memos_widget_render_list_html($result['data'], rtrim(get_option('memos_widget_api_url', ''), '/'), $content_length);
    }

    memos_widget_render_styles();
}

// 短代码支持：[memos_widget limit="5" length="65" title=""]
add_shortcode('memos_widget', 'memos_widget_shortcode');
function memos_widget_shortcode($atts) {
    $atts = shortcode_atts(array(
        'limit' => 5,
        'length' => 65,
        'title' => '',
    ), $atts, 'memos_widget');

    $page_size = absint($atts['limit']);
    $content_length = absint($atts['length']);

    ob_start();
    if (!empty($atts['title'])) {
        echo '<h3 class="memos-widget-title">' . esc_html($atts['title']) . '</h3>';
    }
    memos_widget_render_output($page_size > 0 ? $page_size : 5, $content_length > 0 ? $content_length : 65);
    return ob_get_clean();
}

// 渲染后台设置页面
function memos_widget_render_settings_page() {
    $api_url = get_option('memos_widget_api_url', '');
    $access_token = get_option('memos_widget_access_token', '');

    $test_result = null;
    if (isset($_POST['memos_widget_test']) && isset($_POST['memos_widget_test_nonce'])
        && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['memos_widget_test_nonce'])), 'memos_widget_test')) {
        $test_result = memos_widget_fetch_memos($api_url, $access_token, 3);
    }
    ?>
    <div class="wrap">
        <h1>Memos Widget 设置</h1>

        <?php if (isset($_GET['settings-updated'])): ?>
            <div class="notice notice-success is-dismissible"><p>设置已保存。</p></div>
        <?php endif; ?>

        <?php if ($test_result !== null): ?>
            <?php if (empty($test_result['error'])): ?>
                <div class="notice notice-success"><p>
                    <b>连接成功</b>：已通过 <code><?php echo esc_html($test_result['endpoint']); ?></code> 获取到
                    <?php echo count(memos_widget_extract_list($test_result['data'])); ?> 条动态。
                </p></div>
            <?php else: ?>
                <div class="notice notice-error"><p>
                    <b>连接失败</b>：<?php echo esc_html($test_result['error']); ?><br>
                    请求地址：<code><?php echo esc_html($test_result['endpoint']); ?></code>
                </p></div>
            <?php endif; ?>
        <?php endif; ?>

        <form method="post" action="options.php">
            <?php
            settings_fields('memos_widget_settings_group');
            do_settings_sections('memos_widget_settings_group');
            ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><label for="memos_widget_api_url">Memos API 地址</label></th>
                    <td>
                        <input type="url" id="memos_widget_api_url" name="memos_widget_api_url" value="<?php echo esc_attr($api_url); ?>" class="regular-text" placeholder="https://memo.zengqueling.com" required />
                        <p class="description">只填实例根地址，例如 <code>https://memo.zengqueling.com</code>，插件会自动拼接 <code>/api/memos</code>。</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label for="memos_widget_access_token">Access Token（访问令牌）</label></th>
                    <td>
                        <input type="text" id="memos_widget_access_token" name="memos_widget_access_token" value="<?php echo esc_attr($access_token); ?>" class="regular-text" placeholder="memos_pat_xxxxxxxx" />
                        <p class="description">公开可读的 Memos 可留空；若实例需要鉴权，请填入 Memos 后台（<b>设置 → 账号 → 个人访问令牌</b>）生成的 Token。</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">接口版本</th>
                    <td>
                        <p class="description">
                            当前使用：<code><?php echo get_option('memos_widget_api_style', 'rest') === 'legacy' ? esc_html(MEMOS_WIDGET_ENDPOINT_LEGACY) : esc_html(MEMOS_WIDGET_ENDPOINT_REST); ?></code><br>
                            新版 Memos 仅保留 <code>/api/memos</code>，<code>/api/v1/*</code> 与 <code>/memos.api.v1.MemoService/*</code> 均已废弃并返回 410。
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">其他调用方式</th>
                    <td>
                        <p class="description">如果主题没有侧边栏小工具区，可在页面/文章中插入短代码：<code>[memos_widget]</code>（可加参数 <code>limit="5" length="65" title="最新动态"</code>）。</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('保存设置'); ?>
        </form>

        <hr>
        <form method="post">
            <?php wp_nonce_field('memos_widget_test', 'memos_widget_test_nonce'); ?>
            <input type="hidden" name="memos_widget_test" value="1" />
            <p class="description">测试当前已保存的配置能否正常读取动态（会按需自动回退到旧接口）。</p>
            <?php submit_button('测试连接', 'secondary', 'submit', false); ?>
        </form>
    </div>
    <?php
}

// 注册小工具
function register_memos_widget() {
    register_widget('Memos_Widget');
}
add_action('widgets_init', 'register_memos_widget');

// 小工具类
class Memos_Widget extends WP_Widget {
    public function __construct() {
        parent::__construct(
            'memos_widget',
            '最新Memos动态',
            array('description' => '显示最新的Memos动态')
        );

        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    public function enqueue_scripts() {
        wp_enqueue_script(
            'memos-widget-js',
            plugins_url('memos-widget.js', __FILE__),
            array(),
            MEMOS_WIDGET_VERSION,
            true
        );
    }

    public function widget($args, $instance) {
        echo $args['before_widget'];
        if (!empty($instance['title'])) {
            echo $args['before_title'] . apply_filters('widget_title', $instance['title']) . $args['after_title'];
        }

        echo '<div id="memos-container-' . esc_attr($this->id) . '">';

        $page_size = !empty($instance['page_size']) ? absint($instance['page_size']) : 5;
        $content_length = !empty($instance['content_length']) ? absint($instance['content_length']) : 65;

        memos_widget_render_output($page_size, $content_length);

        echo '</div>';
        echo $args['after_widget'];
    }

    public function form($instance) {
        $title = !empty($instance['title']) ? $instance['title'] : '最新Memos动态';
        $page_size = !empty($instance['page_size']) ? $instance['page_size'] : '5';
        $content_length = !empty($instance['content_length']) ? $instance['content_length'] : '50';
        ?>
        <p>
            <label for="<?php echo $this->get_field_id('title'); ?>">标题：</label>
            <input class="widefat" id="<?php echo $this->get_field_id('title'); ?>"
                   name="<?php echo $this->get_field_name('title'); ?>" type="text"
                   value="<?php echo esc_attr($title); ?>">
        </p>
        <p>
            <label for="<?php echo $this->get_field_id('page_size'); ?>">显示条数：</label>
            <input class="widefat" id="<?php echo $this->get_field_id('page_size'); ?>"
                   name="<?php echo $this->get_field_name('page_size'); ?>" type="number"
                   min="1" max="50" value="<?php echo esc_attr($page_size); ?>">
            <small>默认显示5条</small>
        </p>
        <p>
            <label for="<?php echo $this->get_field_id('content_length'); ?>">内容截取长度：</label>
            <input class="widefat" id="<?php echo $this->get_field_id('content_length'); ?>"
                   name="<?php echo $this->get_field_name('content_length'); ?>" type="number"
                   min="10" max="500" value="<?php echo esc_attr($content_length); ?>">
            <small>默认截取50个字符</small>
        </p>
        <p>
            <small>提示：Memos API 地址和 Access Token 请在 WordPress 后台 <a href="options-general.php?page=memos-widget-settings">设置 -> Memos Widget</a> 中统一配置。</small>
        </p>
        <?php
    }

    public function update($new_instance, $old_instance) {
        $instance = array();
        $instance['title'] = (!empty($new_instance['title'])) ? strip_tags($new_instance['title']) : '';
        $instance['page_size'] = (!empty($new_instance['page_size'])) ? absint($new_instance['page_size']) : 5;
        $instance['content_length'] = (!empty($new_instance['content_length'])) ? absint($new_instance['content_length']) : 50;

        // 当更新小工具设置时，清空缓存
        memos_widget_clear_transients();

        return $instance;
    }
}
