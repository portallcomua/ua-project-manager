<?php
/**
 * UA Project Manager — Admin Assets.
 *
 * @package UA_Project_Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('UAPM_Admin_Assets')) {

    final class UAPM_Admin_Assets {

        public static function init() {
            add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue'), 20);
        }

        public static function enqueue($hook) {
            $hook = (string) $hook;

            if (
                strpos($hook, 'uapm') === false &&
                strpos($hook, 'ua-project-manager') === false
            ) {
                return;
            }

            if (defined('UAPM_URL') && defined('UAPM_VERSION')) {
                wp_enqueue_style(
                    'uapm-admin',
                    UAPM_URL . 'assets/css/admin.css',
                    array(),
                    UAPM_VERSION
                );

                wp_enqueue_script(
                    'uapm-admin',
                    UAPM_URL . 'assets/js/admin.js',
                    array('jquery'),
                    UAPM_VERSION,
                    true
                );
            }

            wp_localize_script(
                'uapm-admin',
                'UAPM_Admin',
                array(
                    'confirmDelete' => __('Are you sure you want to delete this item?', 'ua-project-manager'),
                    'confirmAction' => __('Are you sure you want to perform this action?', 'ua-project-manager'),
                    'selectAll' => __('Select all', 'ua-project-manager'),
                    'deselectAll' => __('Deselect all', 'ua-project-manager'),
                    'loading' => __('Loading...', 'ua-project-manager'),
                    'saved' => __('Changes saved.', 'ua-project-manager'),
                    'error' => __('An error occurred.', 'ua-project-manager'),
                    'copied' => __('Copied!', 'ua-project-manager'),
                    'copyFailed' => __('Copy failed.', 'ua-project-manager'),
                )
            );
        }
    }

    UAPM_Admin_Assets::init();
}
