<?php
/**
 * Plugin Name: Memos Widget
 * Description: 在侧边栏显示Memos最新动态
 * Version: 1.0
 * Author: 令爷
 * Author URI: https://www.zengqueling.com
 */

// 防止直接访问此文件
if (!defined('ABSPATH')) {
    exit;
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
            '1.0',
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
        
        // 初始化小工具的JavaScript代码
        echo '<script>
            document.addEventListener("DOMContentLoaded", function() {
                new MemosWidget(
                    document.getElementById("memos-container-' . $this->id . '"),
                    "' . esc_js($instance['api_url']) . '",
                    ' . (!empty($instance['page_size']) ? esc_js($instance['page_size']) : '5') . ',
                    ' . (!empty($instance['content_length']) ? esc_js($instance['content_length']) : '65') . '
                );
            });
        </script>';

        echo $args['after_widget'];
    }

    public function form($instance) {
        $title = !empty($instance['title']) ? $instance['title'] : '最新Memos动态';
        $api_url = !empty($instance['api_url']) ? $instance['api_url'] : '';
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
            <label for="<?php echo $this->get_field_id('api_url'); ?>">Memos API地址：</label>
            <input class="widefat" id="<?php echo $this->get_field_id('api_url'); ?>"
                   name="<?php echo $this->get_field_name('api_url'); ?>" type="text"
                   value="<?php echo esc_attr($api_url); ?>">
            <small>例如：https://your-memos-url （不带斜杠）</small>
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
        <?php
    }

    public function update($new_instance, $old_instance) {
        $instance = array();
        $instance['title'] = (!empty($new_instance['title'])) ? strip_tags($new_instance['title']) : '';
        $instance['api_url'] = (!empty($new_instance['api_url'])) ? strip_tags($new_instance['api_url']) : '';
        $instance['page_size'] = (!empty($new_instance['page_size'])) ? absint($new_instance['page_size']) : 5;
        $instance['content_length'] = (!empty($new_instance['content_length'])) ? absint($new_instance['content_length']) : 50;
        return $instance;
    }
}
