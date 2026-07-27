<?php
/**
 * Plugin Name: Memos Widget
 * Description: 在侧边栏显示Memos最新动态
 * Version: 1.3
 * Author: 令爷
 * Author URI: https://www.zengqueling.com
 */

// 防止直接访问此文件
if (!defined('ABSPATH')) {
    exit;
}

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

// 渲染后台设置页面
function memos_widget_render_settings_page() {
    ?>
    <div class="wrap">
        <h1>Memos Widget 设置</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('memos_widget_settings_group');
            do_settings_sections('memos_widget_settings_group');
            $api_url = get_option('memos_widget_api_url', '');
            $access_token = get_option('memos_widget_access_token', '');
            ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><label for="memos_widget_api_url">Memos API 地址</label></th>
                    <td>
                        <input type="url" id="memos_widget_api_url" name="memos_widget_api_url" value="<?php echo esc_attr($api_url); ?>" class="regular-text" placeholder="https://memo.zengqueling.com" required />
                        <p class="description">输入你的 Memos 实例访问地址，例如：<code>https://memo.zengqueling.com</code></p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label for="memos_widget_access_token">Access Token（访问令牌）</label></th>
                    <td>
                        <input type="text" id="memos_widget_access_token" name="memos_widget_access_token" value="<?php echo esc_attr($access_token); ?>" class="regular-text" placeholder="memos_pat_xxxxxxxx" />
                        <p class="description">新版 Memos (v0.22+) 需填入在 Memos 后台（<b>设置 -> 账号 -> 个人访问令牌</b>）中生成的 Token。</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('保存设置'); ?>
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
            '1.3',
            true
        );
    }

    public function widget($args, $instance) {
        echo $args['before_widget'];
        if (!empty($instance['title'])) {
            echo $args['before_title'] . apply_filters('widget_title', $instance['title']) . $args['after_title'];
        }

        echo '<div id="memos-container-' . $this->id . '">';

        // 优先读取全局后台设置
        $api_url = get_option('memos_widget_api_url', '');
        if (empty($api_url) && !empty($instance['api_url'])) {
            $api_url = $instance['api_url'];
        }
        $access_token = get_option('memos_widget_access_token', '');
        if (empty($access_token) && !empty($instance['access_token'])) {
            $access_token = $instance['access_token'];
        }

        $page_size = !empty($instance['page_size']) ? absint($instance['page_size']) : 5;
        $content_length = !empty($instance['content_length']) ? absint($instance['content_length']) : 65;

        $api_url = rtrim($api_url, '/');

        if (empty($api_url)) {
            echo '<p class="memos-error">请在 WordPress 后台设置中配置 Memos API 地址</p>';
        } else {
            // 服务器端通过 Transient 缓存数据 5 分钟
            $transient_key = 'memos_widget_cache_' . md5($api_url . '_' . $access_token . '_' . $page_size);
            $memos_data = get_transient($transient_key);

            if ($memos_data === false) {
                $request_args = array(
                    'timeout' => 10,
                    'headers' => array(
                        'Content-Type' => 'application/json'
                    )
                );
                if (!empty($access_token)) {
                    $request_args['headers']['Authorization'] = 'Bearer ' . $access_token;
                }

                $response = wp_remote_get($api_url . '/api/v1/memos?pageSize=' . $page_size, $request_args);

                if (is_wp_error($response)) {
                    $error_message = $response->get_error_message();
                    echo '<p class="memos-error">获取 Memos 动态失败：' . esc_html($error_message) . '</p>';
                    $memos_data = null;
                } else {
                    $code = wp_remote_retrieve_response_code($response);
                    $body = wp_remote_retrieve_body($response);
                    if ($code !== 200) {
                        if ($code === 401) {
                            echo '<p class="memos-error">获取 Memos 动态失败：401 未授权（请在后台设置正确的 Access Token）</p>';
                        } else {
                            echo '<p class="memos-error">获取 Memos 动态失败：HTTP ' . esc_html($code) . '</p>';
                        }
                        $memos_data = null;
                    } else {
                        $memos_data = json_decode($body, true);
                        if ($memos_data) {
                            set_transient($transient_key, $memos_data, 300);
                        }
                    }
                }
            }

            if ($memos_data) {
                $this->render_memos_html($memos_data, $api_url, $content_length);
            }
        }

        echo '</div>';

        // 引入默认样式
        $this->render_styles();

        echo $args['after_widget'];
    }

    private function render_memos_html($memos, $api_url, $content_length) {
        $memos_list = array();
        if (isset($memos['memos']) && is_array($memos['memos'])) {
            $memos_list = $memos['memos'];
        } elseif (is_array($memos)) {
            $memos_list = $memos;
        } elseif (isset($memos['data']) && is_array($memos['data'])) {
            $memos_list = $memos['data'];
        }

        if (empty($memos_list)) {
            echo '<p>暂无动态</p>';
            return;
        }

        echo '<ul class="memos-list">';
        foreach ($memos_list as $memo) {
            $content = isset($memo['content']) ? $memo['content'] : '';
            if (mb_strlen($content) > $content_length) {
                $truncated = mb_substr($content, 0, $content_length) . '...';
            } else {
                $truncated = $content;
            }

            $raw_time = isset($memo['createTime']) ? $memo['createTime'] : (isset($memo['displayTime']) ? $memo['displayTime'] : '');
            $time_str = '';
            if (!empty($raw_time)) {
                $timestamp = strtotime($raw_time);
                if ($timestamp) {
                    $time_str = date('Y-m-d', $timestamp);
                }
            }

            $memo_id = '';
            if (!empty($memo['uid'])) {
                $memo_id = $memo['uid'];
            } elseif (!empty($memo['name']) && strpos($memo['name'], '/') !== false) {
                $parts = explode('/', $memo['name']);
                $memo_id = end($parts);
            } elseif (!empty($memo['id'])) {
                $memo_id = $memo['id'];
            }

            $more_url = !empty($memo_id) ? $api_url . '/m/' . $memo_id : $api_url;

            echo '<li class="memos-item">';
            echo '<div class="memos-content">' . esc_html($truncated) . '</div>';
            if (!empty($time_str)) {
                echo '<span class="memos-time">' . esc_html($time_str) . '</span>';
            }
            echo '<a class="memos-more" href="' . esc_url($more_url) . '" target="_blank">[more]</a>';
            echo '</li>';
        }
        echo '</ul>';
    }

    private function render_styles() {
        static $style_rendered = false;
        if ($style_rendered) {
            return;
        }
        $style_rendered = true;
        ?>
        <style>
            .memos-list {
                list-style: none;
                padding: 0;
                margin: 0;
            }
            .memos-item {
                padding: 15px;
                margin-bottom: 12px;
                background-color: #f8f9fa;
                border-radius: 8px;
                transition: all 0.3s ease;
                position: relative;
            }
            .memos-item:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            }
            .memos-content {
                color: #2c3e50;
                font-size: 14px;
                line-height: 1.6;
                margin-bottom: 10px;
                word-wrap: break-word;
                white-space: pre-wrap;
            }
            .memos-time {
                color: #94a3b8;
                font-size: 12px;
                margin-right: 10px;
            }
            .memos-more {
                color: #3b82f6;
                text-decoration: none;
                font-size: 12px;
                transition: color 0.2s ease;
            }
            .memos-more:hover {
                color: #2563eb;
                text-decoration: underline;
            }
            .memos-error {
                color: #ef4444;
                font-size: 13px;
            }
            @media (max-width: 768px) {
                .memos-item {
                    padding: 12px;
                    margin-bottom: 10px;
                }
                .memos-content {
                    font-size: 13px;
                }
            }
        </style>
        <?php
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
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_memos_widget_cache_%' OR option_name LIKE '_transient_timeout_memos_widget_cache_%'");
        
        return $instance;
    }
}