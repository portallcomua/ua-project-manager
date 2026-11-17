
<?php
/**
 * UA Project Manager
 * Database installer.
 *
 * @package UA_Project_Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

final class UAPM_Installer {

    /**
     * Create database tables and initial settings.
     */
    public static function activate() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();

        $tables = self::get_table_definitions($charset);

        foreach ($tables as $sql) {
            dbDelta($sql);
        }

        self::create_roles();
        self::create_default_settings();

        update_option('uapm_db_version', UAPM_DB_VERSION);
    }

    /**
     * Database table definitions.
     *
     * @param string $charset Database charset.
     * @return array
     */
    private static function get_table_definitions($charset) {
        global $wpdb;

        $prefix = $wpdb->prefix . 'uapm_';

        return array(

            "CREATE TABLE {$prefix}projects (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                name varchar(255) NOT NULL,
                slug varchar(191) NOT NULL,
                description longtext NULL,
                project_type varchar(50) NOT NULL DEFAULT 'wordpress',
                status varchar(30) NOT NULL DEFAULT 'active',
                version varchar(50) NOT NULL DEFAULT '1.0.0',
                author varchar(191) NULL,
                website_url text NULL,
                documentation_url text NULL,
                github_repository varchar(255) NULL,
                github_branch varchar(100) NOT NULL DEFAULT 'main',
                logo_url text NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY slug (slug),
                KEY status (status),
                KEY project_type (project_type)
            ) {$charset};",

            "CREATE TABLE {$prefix}project_plans (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                project_id bigint(20) unsigned NOT NULL,
                plan_type varchar(30) NOT NULL DEFAULT 'free',
                name varchar(100) NOT NULL,
                price decimal(18,2) NOT NULL DEFAULT 0.00,
                currency varchar(10) NOT NULL DEFAULT 'UAH',
                description longtext NULL,
                features longtext NULL,
                limits_data longtext NULL,
                is_active tinyint(1) NOT NULL DEFAULT 1,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY project_id (project_id),
                KEY plan_type (plan_type)
            ) {$charset};",

            "CREATE TABLE {$prefix}payments (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                project_id bigint(20) unsigned NOT NULL,
                plan_id bigint(20) unsigned NULL,
                customer_id bigint(20) unsigned NULL,
                activation_target varchar(255) NOT NULL,
                activation_type varchar(30) NOT NULL DEFAULT 'domain',
                amount decimal(18,2) NOT NULL DEFAULT 0.00,
                currency varchar(10) NOT NULL DEFAULT 'UAH',
                payment_status varchar(30) NOT NULL DEFAULT 'pending',
                payment_method varchar(50) NOT NULL DEFAULT 'privat_envelope',
                external_reference varchar(191) NULL,
                confirmed_by bigint(20) unsigned NULL,
                confirmed_at datetime NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY project_id (project_id),
                KEY customer_id (customer_id),
                KEY payment_status (payment_status),
                KEY activation_target (activation_target)
            ) {$charset};",

            "CREATE TABLE {$prefix}customers (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                email varchar(191) NOT NULL,
                name varchar(191) NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY email (email)
            ) {$charset};",

            "CREATE TABLE {$prefix}installations (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                project_id bigint(20) unsigned NOT NULL,
                customer_id bigint(20) unsigned NULL,
                site_url text NOT NULL,
                site_domain varchar(191) NOT NULL,
                product_version varchar(50) NULL,
                plan_type varchar(30) NOT NULL DEFAULT 'free',
                installation_token varchar(191) NULL,
                installation_status varchar(30) NOT NULL DEFAULT 'active',
                last_seen datetime NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY project_id (project_id),
                KEY site_domain (site_domain),
                KEY installation_status (installation_status)
            ) {$charset};",

            "CREATE TABLE {$prefix}releases (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                project_id bigint(20) unsigned NOT NULL,
                version varchar(50) NOT NULL,
                release_tag varchar(100) NOT NULL,
                release_url text NULL,
                download_url text NULL,
                changelog longtext NULL,
                is_stable tinyint(1) NOT NULL DEFAULT 1,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY project_id (project_id),
                KEY version (version)
            ) {$charset};",

            "CREATE TABLE {$prefix}activity_log (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint(20) unsigned NULL,
                project_id bigint(20) unsigned NULL,
                event_type varchar(100) NOT NULL,
                description text NULL,
                context longtext NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY user_id (user_id),
                KEY project_id (project_id),
                KEY event_type (event_type),
                KEY created_at (created_at)
            ) {$charset};",

            "CREATE TABLE {$prefix}integrations (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                integration_key varchar(100) NOT NULL,
                integration_name varchar(191) NOT NULL,
                integration_status varchar(30) NOT NULL DEFAULT 'inactive',
                settings longtext NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY integration_key (integration_key)
            ) {$charset};",

            "CREATE TABLE {$prefix}notifications (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint(20) unsigned NULL,
                notification_type varchar(50) NOT NULL,
                title varchar(255) NOT NULL,
                message text NOT NULL,
                is_read tinyint(1) NOT NULL DEFAULT 0,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY user_id (user_id),
                KEY is_read (is_read)
            ) {$charset};",

            "CREATE TABLE {$prefix}settings (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                setting_key varchar(191) NOT NULL,
                setting_value longtext NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY setting_key (setting_key)
            ) {$charset};"
        );
    }

    /**
     * Register custom WordPress roles.
     */
    private static function create_roles() {

        if (!get_role('uapm_manager')) {
            add_role(
                'uapm_manager',
                'Gmaps manager',
                array(
                    'read' => true,
                    'uapm_access_manager' => true,
                )
            );
        }

        $admin = get_role('administrator');

        if ($admin && !$admin->has_cap('uapm_manage_projects')) {
            $admin->add_cap('uapm_manage_projects');
            $admin->add_cap('uapm_manage_payments');
            $admin->add_cap('uapm_manage_integrations');
            $admin->add_cap('uapm_manage_settings');
            $admin->add_cap('uapm_inspect_code');
        }
    }

    /**
     * Add default options.
     */
    private static function create_default_settings() {

        $defaults = array(
            'uapm_currency' => 'UAH',
            'uapm_language' => 'auto',
            'uapm_minimum_payout' => '100',
            'uapm_default_commission' => '0',
            'uapm_github_enabled' => '0',
            'uapm_notifications_enabled' => '1',
            'uapm_privacy_mode' => '1',
        );

        foreach ($defaults as $key => $value) {
            if (false === get_option($key, false)) {
                add_option($key, $value);
            }
        }
    }
}
