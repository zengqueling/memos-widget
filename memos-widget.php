<?php
/**
 * Plugin Name: Memos Widget
 * Description: 在侧边栏显示Memos最新动态
 * Version: 1.2
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

        // 在前端加载JavaScript文件
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    public function enqueue_scripts() {
        wp_enqueue_script(
            'memos-widget-js',
            plugins_url('memos-widget.js', __FILE__),
            array(),
            '1.2',
            true
        );
    }

    public function widget($args, $instance) {
        echo $args['before_widget'];
        if (!empty($instance['title'])) {
            echo $args['before_title'] . apply_filters('widget_title', $instance['title']) . $args['after_title'];
        }

        // 小工具内容
        echo '<div id="memos-container-' . $this->id . '"></div>';
        
        // 从全局后台设置中读取 API 地址和 Access Token
        $api_url = get_option('memos_widget_api_url', '');
        if (empty($api_url) && !empty($instance['api_url'])) {
            $api_url = $instance['api_url'];
        }
        $access_token = get_option('memos_widget_access_token', '');
        if (empty($access_token) && !empty($instance['access_token'])) {
            $access_token = $instance['access_token'];
        }

        $page_size = !empty($instance['page_size']) ? $instance['page_size'] : '5';
        $content_length = !empty($instance['content_length']) ? $instance['content_length'] : '65';

        // 初始化小工具的JavaScript代码
        echo '<script>
            document.addEventListener("DOMContentLoaded", function() {
                new MemosWidget(
                    document.getElementById("memos-container-' . $this->id . '"),
                    "' . esc_js($api_url) . '",
                    ' . esc_js($page_size) . ',
                    ' . esc_js($content_length) . ',
                    "' . esc_js($access_token) . '"
                );
            });
        </script>';

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
        return $instance;
    }
}