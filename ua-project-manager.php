<?php
/**
 * Plugin Name: UA Project Manager
 * Plugin URI: https://uaserver.pp.ua/
 * Description: Central management system for UA projects, plugins, applications, GitHub releases, downloads, installations, payments and integrations.
 * Version: 1.0.0
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

/**
 * Main plugin constants.
 */
define('UAPM_VERSION', '1.0.0');
define('UAPM_DB_VERSION', '1.0.0');
define('UAPM_FILE', __FILE__);
define('UAPM_PATH', plugin_dir_path(__FILE__));
define('UAPM_URL', plugin_dir_url(__FILE__));
define('UAPM_BASENAME', plugin_basename(__FILE__));

/**
 * Main plugin class.
 */
final class UAPM_Plugin {

    /**
     * Single instance.
     *
     * @var UAPM_Plugin|null
     */
    private static $instance = null;

    /**
     * Loaded module classes.
     *
     * @var array
     */
    private $modules = array(
        'UAPM_Projects',
        'UAPM_Payments',
        'UAPM_Admin',
    );

    /**
     * Get plugin instance.
     *
     * @return UAPM_Plugin
     */
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        $this->load_dependencies();

        add_action('plugins_loaded', array($this, 'init'), 20);
        add_action('init', array($this, 'load_textdomain'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
    }

    /**
     * Load plugin modules.
     */
    private function load_dependencies() {
        $files = array(
            'includes/class-uapm-installer.php',
            'includes/class-uapm-projects.php',
            'includes/class-uapm-payments.php',
            'includes/class-uapm-admin.php',
        );

        foreach ($files as $file) {
            $path = UAPM_PATH . $file;

            if (file_exists($path)) {
                require_once $path;
            }
        }
    }

    /**
     * Initialize available modules.
     */
    public function init() {
        foreach ($this->modules as $module) {
            if (class_exists($module) && method_exists($module, 'init')) {
                call_user_func(array($module, 'init'));
            }
        }

        do_action('uapm_loaded');
    }

    /**
     * Load translations.
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'ua-project-manager',
            false,
            dirname(UAPM_BASENAME) . '/languages'
        );
    }

    /**
     * Register basic REST API routes.
     */
    public function register_rest_routes() {
        register_rest_route(
            'uapm/v1',
            '/status',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array($this, 'rest_status'),
                'permission_callback' => '__return_true',
            )
        );
    }

    /**
     * Public API status endpoint.
     *
     * Does not expose private project, customer or payment data.
     *
     * @return WP_REST_Response
     */
    public function rest_status() {
        return new WP_REST_Response(
            array(
                'success'      => true,
                'name'         => 'UA Project Manager',
                'version'      => UAPM_VERSION,
                'api_version'  => '1',
                'status'       => 'active',
                'documentation' => 'https://uaserver.pp.ua/',
            ),
            200
        );
    }

    /**
     * Plugin activation.
     */
    public static function activate() {
        if (!current_user_can('activate_plugins')) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $installer_file = UAPM_PATH . 'includes/class-uapm-installer.php';

        if (file_exists($installer_file)) {
            require_once $installer_file;
        }

        if (class_exists('UAPM_Installer')) {
            UAPM_Installer::activate();
        }

        if (!get_option('uapm_installation_date')) {
            add_option('uapm_installation_date', current_time('mysql'));
        }

        update_option('uapm_version', UAPM_VERSION);
        update_option('uapm_db_version', UAPM_DB_VERSION);

        flush_rewrite_rules();

        do_action('uapm_activated');
    }

    /**
     * Plugin deactivation.
     */
    public static function deactivate() {
        flush_rewrite_rules();

        do_action('uapm_deactivated');
    }
}

/**
 * Register activation and deactivation hooks.
 */
register_activation_hook(
    UAPM_FILE,
    array('UAPM_Plugin', 'activate')
);

register_deactivation_hook(
    UAPM_FILE,
    array('UAPM_Plugin', 'deactivate')
);

/**
 * Start plugin.
 */
UAPM_Plugin::instance();
