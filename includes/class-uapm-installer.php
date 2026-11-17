
<?php
/**
 * UA Project Manager — database installer.
 *
 * @package UA_Project_Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

final class UAPM_Installer {

    public static function activate() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();

        foreach (self::get_table_definitions($charset) as $sql) {
            dbDelta($sql);
        }

        self::create_roles();
        self::create_default_settings();

        foreach (array('UAPM_Queue', 'UAPM_Integrations', 'UAPM_Downloads', 'UAPM_Payments', 'UAPM_Installations', 'UAPM_Notifications', 'UAPM_Activity_Log', 'UAPM_Customers') as $class) {
            if (class_exists($class) && method_exists($class, 'install_schema')) {
                call_user_func(array($class, 'install_schema'));
            }
        }

        update_option('uapm_db_version', defined('UAPM_DB_VERSION') ? UAPM_DB_VERSION : '1.2.0');
    }

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
                customer_email varchar(191) NULL,
                activation_target varchar(255) NOT NULL,
                activation_type varchar(30) NOT NULL DEFAULT 'domain',
                amount decimal(18,2) NOT NULL DEFAULT 0.00,
                currency varchar(10) NOT NULL DEFAULT 'UAH',
                payment_status varchar(30) NOT NULL DEFAULT 'pending',
                payment_method varchar(50) NOT NULL DEFAULT 'privat_envelope',
                external_reference varchar(191) NULL,
                confirmed_by bigint(20) unsigned NULL,
                confirmed_at datetime NULL,
                manager_id bigint(20) unsigned NULL,
                commission_rate decimal(8,4) NOT NULL DEFAULT 0.0000,
                commission_amount decimal(18,2) NOT NULL DEFAULT 0.00,
                received_amount decimal(18,2) NOT NULL DEFAULT 0.00,
                activation_status varchar(30) NOT NULL DEFAULT 'provisional',
                paid_at datetime NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY project_id (project_id),
                KEY customer_id (customer_id),
                KEY customer_email (customer_email),
                KEY payment_status (payment_status),
                KEY activation_target (activation_target),
                KEY manager_id (manager_id)
            ) {$charset};",

            "CREATE TABLE {$prefix}payment_transactions (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                payment_id bigint(20) unsigned NOT NULL,
                amount decimal(18,2) NOT NULL DEFAULT 0.00,
                currency varchar(10) NOT NULL DEFAULT 'UAH',
                commission_rate decimal(8,4) NOT NULL DEFAULT 0.0000,
                commission_amount decimal(18,2) NOT NULL DEFAULT 0.00,
                reference varchar(191) NULL,
                note text NULL,
                received_by bigint(20) unsigned NULL,
                received_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY payment_id (payment_id),
                KEY received_at (received_at)
            ) {$charset};",

            "CREATE TABLE {$prefix}manager_commissions (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                manager_id bigint(20) unsigned NOT NULL,
                customer_email varchar(191) NOT NULL,
                commission_rate decimal(8,4) NOT NULL DEFAULT 0.0000,
                updated_by bigint(20) unsigned NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY manager_customer (manager_id,customer_email),
                KEY manager_id (manager_id)
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
                customer_id bigint(20) unsigned DEFAULT 0,
                site_url varchar(500) DEFAULT '',
                site_name varchar(255) DEFAULT '',
                plugin_slug varchar(191) DEFAULT '',
                plugin_version varchar(100) DEFAULT '',
                wordpress_version varchar(50) DEFAULT '',
                php_version varchar(50) DEFAULT '',
                platform varchar(50) DEFAULT 'wordpress',
                install_token_hash varchar(255) DEFAULT '',
                status varchar(30) DEFAULT 'active',
                consent_given tinyint(1) NOT NULL DEFAULT 0,
                consent_at datetime NULL,
                first_seen datetime NULL,
                last_seen datetime NULL,
                deactivated_at datetime NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY project_id (project_id),
                KEY customer_id (customer_id),
                KEY status (status),
                KEY last_seen (last_seen),
                KEY site_url (site_url(191))
            ) {$charset};",

            "CREATE TABLE {$prefix}releases (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                project_id bigint(20) unsigned NOT NULL,
                version varchar(50) NOT NULL,
                release_tag varchar(100) NOT NULL,
                release_url text NULL,
                download_url text NULL,
                assets_json longtext NULL,
                changelog longtext NULL,
                is_stable tinyint(1) NOT NULL DEFAULT 1,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY project_id (project_id),
                KEY version (version)
            ) {$charset};",

            "CREATE TABLE {$prefix}downloads (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                project_id bigint(20) unsigned NOT NULL DEFAULT 0,
                release_id bigint(20) unsigned NOT NULL DEFAULT 0,
                asset_id bigint(20) unsigned NOT NULL DEFAULT 0,
                asset_name varchar(255) NOT NULL DEFAULT '',
                download_url text NOT NULL,
                visitor_hash varchar(64) NOT NULL DEFAULT '',
                user_id bigint(20) unsigned NOT NULL DEFAULT 0,
                event_type varchar(30) NOT NULL DEFAULT 'click',
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY project_id (project_id),
                KEY release_id (release_id),
                KEY asset_id (asset_id),
                KEY event_type (event_type),
                KEY created_at (created_at)
            ) {$charset};",

            "CREATE TABLE {$prefix}activity_log (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint(20) unsigned NULL,
                project_id bigint(20) unsigned NULL,
                event_type varchar(100) NOT NULL DEFAULT '',
                description text NULL,
                context longtext NULL,
                event varchar(100) NOT NULL DEFAULT '',
                object_type varchar(50) NOT NULL DEFAULT '',
                object_id bigint(20) unsigned NOT NULL DEFAULT 0,
                meta longtext NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY user_id (user_id),
                KEY project_id (project_id),
                KEY event_type (event_type),
                KEY event (event),
                KEY created_at (created_at)
            ) {$charset};",

            "CREATE TABLE {$prefix}integrations (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                integration_key varchar(100) NOT NULL,
                name varchar(255) NOT NULL DEFAULT '',
                description text NULL,
                integration_type varchar(50) NOT NULL DEFAULT 'external',
                status varchar(30) NOT NULL DEFAULT 'inactive',
                access_token_hash varchar(64) NOT NULL DEFAULT '',
                secret_key longtext NULL,
                settings longtext NULL,
                last_seen datetime NULL,
                integration_name varchar(191) NOT NULL DEFAULT '',
                integration_status varchar(30) NOT NULL DEFAULT 'inactive',
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY integration_key (integration_key),
                KEY status (status),
                KEY integration_type (integration_type)
            ) {$charset};",

            "CREATE TABLE {$prefix}integration_events (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                integration_id bigint(20) unsigned NOT NULL,
                event_type varchar(100) NOT NULL,
                payload longtext NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY integration_id (integration_id),
                KEY event_type (event_type),
                KEY created_at (created_at)
            ) {$charset};",

            "CREATE TABLE {$prefix}notifications (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint(20) unsigned NOT NULL DEFAULT 0,
                notification_type varchar(50) NOT NULL DEFAULT 'info',
                title varchar(255) NOT NULL,
                message text NOT NULL,
                related_type varchar(50) NOT NULL DEFAULT '',
                related_id bigint(20) unsigned NOT NULL DEFAULT 0,
                is_read tinyint(1) NOT NULL DEFAULT 0,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY user_id (user_id),
                KEY is_read (is_read),
                KEY created_at (created_at)
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

    private static function create_roles() {
        $manager_role = get_role('uapm_manager');

        if (!$manager_role) {
            add_role('uapm_manager', 'Менеджер UPM', array(
                'read' => true,
                'uapm_access_manager' => true,
            ));
        } else {
            $manager_role->add_cap('read');
            $manager_role->add_cap('uapm_access_manager');
        }

        $admin = get_role('administrator');

        if ($admin) {
            foreach (array(
                'uapm_manage_projects',
                'uapm_manage_payments',
                'uapm_manage_integrations',
                'uapm_manage_settings',
                'uapm_inspect_code',
            ) as $capability) {
                $admin->add_cap($capability);
            }
        }
    }

    private static function create_default_settings() {
        $defaults = array(
            'uapm_currency' => 'UAH',
            'uapm_language' => 'auto',
            'uapm_minimum_payout' => '100',
            'uapm_default_commission' => '0',
            'uapm_github_enabled' => '0',
            'uapm_notifications_enabled' => '1',
            'uapm_privacy_mode' => '1',
            'uapm_privat_envelope_url' => '',
        );

        foreach ($defaults as $key => $value) {
            if (false === get_option($key, false)) {
                add_option($key, $value);
            }
        }
    }
}
