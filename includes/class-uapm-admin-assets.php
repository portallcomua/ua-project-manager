<?php
/**
 * UA Project Manager — Admin assets.
 *
 * @package UA_Project_Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

final class UAPM_Admin_Assets {

    public static function init() {
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue'), 20);
    }

    public static function enqueue($hook_suffix) {
        if (!self::is_uapm_page($hook_suffix)) {
            return;
        }

        $css_path = UAPM_PATH . 'assets/css/admin.css';
        $js_path  = UAPM_PATH . 'assets/js/admin.js';

        if (file_exists($css_path)) {
            wp_enqueue_style(
                'uapm-admin-design',
                UAPM_URL . 'assets/css/admin.css',
                array(),
                (string) filemtime($css_path)
            );
        }

        if (file_exists($js_path)) {
            wp_enqueue_script(
                'uapm-admin-interactions',
                UAPM_URL . 'assets/js/admin.js',
                array('jquery'),
                (string) filemtime($js_path),
                true
            );
        }
    }

    private static function is_uapm_page($hook_suffix) {
        $page = isset($_GET['page'])
            ? sanitize_key(wp_unslash($_GET['page']))
            : '';

        if ($page && strpos($page, 'uapm') === 0) {
            return true;
        }

        return strpos((string) $hook_suffix, 'uapm') !== false;
    }
}

UAPM_Admin_Assets::init();
