<?php
/**
 * Plugin Name: UA Project Manager
 * Plugin URI: https://uaserver.pp.ua/
 * Description: Central management system for UA projects, plugins, releases, licenses, payments and integrations.
 * Version: 1.0.4
 * Author: UA Tools
 * Author URI: https://uaserver.pp.ua/
 * Text Domain: ua-project-manager
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('UAPM_VERSION')) {
    define('UAPM_VERSION', '1.0.4');
}

if (!defined('UAPM_DB_VERSION')) {
    define('UAPM_DB_VERSION', '1.3.0');
}

if (!defined('UAPM_FILE')) {
    define('UAPM_FILE', __FILE__);
}

if (!defined('UAPM_PATH')) {
    define('UAPM_PATH', plugin_dir_path(__FILE__));
}

if (!defined('UAPM_URL')) {
    define('UAPM_URL', plugin_dir_url(__FILE__));
}

if (!defined('UAPM_BASENAME')) {
    define('UAPM_BASENAME', plugin_basename(__FILE__));
}

final class UAPM_Plugin {

    private static $instance = null;
    private $loaded = array();
    private $errors = array();
    private $initialized = false;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        add_action('plugins_loaded', array($this, 'load_plugin'), 20);
    }

    public function load_plugin() {
        if ($this->initialized) {
            return;
        }

        $this->initialized = true;

        $this->load_modules();
        $this->initialize_modules();

        if (class_exists('UAPM_Installer')) {
            $installed_version = get_option('uapm_db_version', '');

            if (version_compare($installed_version, UAPM_DB_VERSION, '<')) {
                UAPM_Installer::activate();
                update_option('uapm_db_version', UAPM_DB_VERSION);
            }
        }


        do_action('uapm_loaded', $this);
    }

    private function load_modules() {
        $files = array(
            'class-uapm-security.php',
            'class-uapm-installer.php',
            'class-uapm-roles.php',
            'class-uapm-settings.php',
            'class-uapm-projects.php',
            'class-uapm-customers.php',
            'class-uapm-clients.php',
            'class-uapm-payments.php',
            'class-uapm-license-manager.php',
            'class-uapm-license-sites.php',
            'class-uapm-repository.php',
            'class-uapm-updater.php',
            'class-uapm-downloads.php',
            'class-uapm-installations.php',
            'class-uapm-installation-compat.php',
            'class-uapm-integrations.php',
            'class-uapm-notifications.php',
            'class-uapm-email-templates.php',
            'class-uapm-queue.php',
            'class-uapm-cron.php',
            'class-uapm-webhooks.php',
            'class-uapm-code-generator.php',
            'class-uapm-code-inspector.php',
            'class-uapm-analytics.php',
            'class-uapm-reports.php',
            'class-uapm-mailings.php',
            'class-uapm-buttons.php',
            'class-uapm-rest-api.php',
            'class-uapm-api.php',
            'class-uapm-activity-log.php',
            'class-uapm-activity-monitor.php',
            'class-uapm-activity-feed.php',
            'class-uapm-audit.php',
            'class-uapm-logs.php',
            'class-uapm-privacy.php',
            'class-uapm-backups.php',
            'class-uapm-export.php',
            'class-uapm-import.php',
            'class-uapm-diagnostics.php',
            'class-uapm-system-health.php',
            'class-uapm-dashboard-widgets.php',
            'class-uapm-localization.php',
            'class-uapm-search.php',
            'class-uapm-notes.php',
            'class-uapm-tasks.php',
            'class-uapm-comments.php',
            'class-uapm-attachments.php',
            'class-uapm-activity-calendar.php',
            'class-uapm-project-activity.php',
            'class-uapm-milestones.php',
            'class-uapm-dependencies.php',
            'class-uapm-admin.php',
            'class-uapm-admin-sections.php',
            'class-uapm-portal.php',
        );

        foreach ($files as $file) {
            $path = UAPM_PATH . 'includes/' . $file;

            if (!file_exists($path)) {
                $this->errors[] = 'includes/' . $file;
                continue;
            }

            require_once $path;
            $this->loaded[] = $file;
        }
    }

    private function initialize_modules() {
        /*
         * These modules initialize themselves when loaded:
         *
         * UAPM_Roles
         * UAPM_Clients
         * UAPM_GitHub
         * UAPM_Downloads
         * UAPM_Installations
         * UAPM_Installation_Compat
         * UAPM_Integrations
         * UAPM_Notifications
         * UAPM_Email_Templates
         * UAPM_Queue
         * UAPM_Code_Inspector
         * UAPM_REST_API
         * UAPM_Dependencies
         * UAPM_Portal
         * UAPM_Admin_Sections
         *
         * Do not initialize them again here.
         */

        $classes = array(
            'UAPM_Settings',
            'UAPM_Projects',
            'UAPM_Customers',
            'UAPM_License_Manager',
            'UAPM_License_Sites',
            'UAPM_Updater',
            'UAPM_Cron',
            'UAPM_Webhooks',
            'UAPM_Code_Generator',
            'UAPM_Analytics',
            'UAPM_Mailings',
            'UAPM_API',
            'UAPM_Activity_Monitor',
            'UAPM_Activity_Feed',
            'UAPM_Audit',
            'UAPM_Logs',
            'UAPM_Privacy',
            'UAPM_Backups',
            'UAPM_Export',
            'UAPM_Import',
            'UAPM_Diagnostics',
            'UAPM_System_Health',
            'UAPM_Dashboard_Widgets',
            'UAPM_Search',
            'UAPM_Notes',
            'UAPM_Tasks',
            'UAPM_Comments',
            'UAPM_Attachments',
            'UAPM_Activity_Calendar',
            'UAPM_Project_Activity',
            'UAPM_Milestones',
            'UAPM_Admin',
        );

        foreach ($classes as $class) {
            if (!class_exists($class)) {
                continue;
            }

            if (!is_callable(array($class, 'init'))) {
                continue;
            }

            call_user_func(array($class, 'init'));
        }
    }

    public function get_loaded_modules() {
        return $this->loaded;
    }

    public function get_loading_errors() {
        return $this->errors;
    }

    private function register_dependency_notices() {
        add_action(
            'admin_notices',
            array($this, 'render_dependency_notices')
        );
    }

    public function render_dependency_notices() {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (!empty($this->errors)) {
            echo '<div class="notice notice-error">';
            echo '<p><strong>UA Project Manager:</strong> Не знайдено файли модулів:</p>';
            echo '<ul>';

            foreach ($this->errors as $file) {
                echo '<li>' . esc_html($file) . '</li>';
            }

            echo '</ul></div>';
        }

        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $dependencies = array(
            'UA Pay Box' => array(
                'slug' => 'ua-pay-box/ua-pay-box.php',
                'description' => 'Платежі та керування PRO-доступом.',
            ),
            'UA Click Counter' => array(
                'slug' => 'ua-click-counter/ua-click-counter.php',
                'description' => 'Облік натискань на кнопки та посилання.',
            ),
            'UA Plugins Monitor' => array(
                'slug' => 'ua-plugins-monitor/ua-plugins-monitor.php',
                'description' => 'Моніторинг встановлень та оновлень.',
            ),
            'CMS Web Finder' => array(
                'slug' => 'cms-web-finder/cms-web-finder.php',
                'description' => 'Передача списків сайтів та кампаній.',
            ),
        );

        $missing = array();

        foreach ($dependencies as $name => $dependency) {
            if (!is_plugin_active($dependency['slug'])) {
                $missing[$name] = $dependency;
            }
        }

        if (empty($missing)) {
            return;
        }

        echo '<div class="notice notice-warning">';
        echo '<p><strong>UA Project Manager:</strong> доступні додаткові інтеграції.</p>';
        echo '<ul>';

        foreach ($missing as $name => $dependency) {
            echo '<li><strong>' . esc_html($name) . '</strong>';
            echo ' — ' . esc_html($dependency['description']);
            echo ' (необов’язкова інтеграція)</li>';
        }

        echo '</ul>';
        echo '<p>Основні функції UA Project Manager повинні працювати незалежно від цих плагінів.</p>';
        echo '<p><a href="' . esc_url(admin_url('plugins.php')) . '">Переглянути встановлені плагіни</a></p>';
        echo '</div>';
    }
}

function uapm() {
    return UAPM_Plugin::instance();
}

function uapm_is_plugin_active($plugin_file) {
    if (!function_exists('is_plugin_active')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    return is_plugin_active($plugin_file);
}

function uapm_activate_plugin() {

    $installer = UAPM_PATH . 'includes/class-uapm-installer.php';

    if (file_exists($installer)) {
        require_once $installer;

        if (class_exists('UAPM_Installer')) {
            UAPM_Installer::activate();
        }
    }

    $portal = UAPM_PATH . 'includes/class-uapm-portal.php';

    if (file_exists($portal)) {
        require_once $portal;

        if (
            class_exists('UAPM_Portal') &&
            is_callable(array('UAPM_Portal', 'register_rewrite'))
        ) {
            UAPM_Portal::register_rewrite();
        }
    }

    update_option('uapm_db_version', UAPM_DB_VERSION);
    update_option('uapm_version', UAPM_VERSION);

    flush_rewrite_rules();
}

function uapm_deactivate_plugin() {
    flush_rewrite_rules();
}

register_activation_hook(__FILE__, 'uapm_activate_plugin');
register_deactivation_hook(__FILE__, 'uapm_deactivate_plugin');

uapm();
