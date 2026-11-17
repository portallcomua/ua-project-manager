<?php
/**
 * UA Project Manager
 * Database installer and role setup.
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

        update_option('uapm_db_version', defined('UAPM_DB_VERSION') ? UAPM_DB_VERSION : '1.0.0');
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
            add_role(
                'uapm_manager',
                'Менеджер UPM',
                array(
                    'read' => true,
                    'uapm_access_manager' => true,
                )
            );
        } else {
            $manager_role->add_cap('read');
            $manager_role->add_cap('uapm_access_manager');

            $roles = wp_roles();

            if (isset($roles->roles['uapm_manager'])) {
                $roles->roles['uapm_manager']['name'] = 'Менеджер UPM';
                update_option($roles->role_key, $roles->roles);
            }
        }

        $admin = get_role('administrator');

        if ($admin) {
            $capabilities = array(
                'uapm_manage_projects',
                'uapm_manage_payments',
                'uapm_manage_integrations',
                'uapm_manage_settings',
                'uapm_inspect_code',
            );

            foreach ($capabilities as $capability) {
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