<?php
if (!defined('ABSPATH')) {
    exit;
}

class UAPM_Roles {

    public static function init() {
        add_action('init', array(__CLASS__, 'ensure_roles'));
        add_action('admin_init', array(__CLASS__, 'ensure_capabilities'));
    }

    public static function activate() {
        self::ensure_roles();
        self::ensure_capabilities();
    }

    public static function ensure_roles() {
        $admin = get_role('administrator');

        if ($admin) {
            $capabilities = self::admin_capabilities();

            foreach ($capabilities as $capability) {
                $admin->add_cap($capability);
            }
        }

        $manager_capabilities = self::manager_capabilities();

        $manager = get_role('uapm_manager');

        if (!$manager) {
            add_role(
                'uapm_manager',
                'UA Project Manager',
                array(
                    'read' => true,
                )
            );

            $manager = get_role('uapm_manager');
        }

        if ($manager) {
            foreach ($manager_capabilities as $capability) {
                $manager->add_cap($capability);
            }
        }
    }

    public static function ensure_capabilities() {
        $admin = get_role('administrator');

        if (!$admin) {
            return;
        }

        foreach (self::admin_capabilities() as $capability) {
            $admin->add_cap($capability);
        }
    }

    private static function admin_capabilities() {
        return array(
            'uapm_manage_projects',
            'uapm_manage_payments',
            'uapm_manage_integrations',
            'uapm_manage_settings',
            'uapm_inspect_code',
            'uapm_manage_customers',
            'uapm_manage_installations',
            'uapm_manage_licenses',
            'uapm_manage_downloads',
            'uapm_manage_mailings',
            'uapm_manage_reports',
            'uapm_manage_backups',
            'uapm_manage_webhooks',
            'uapm_manage_queue',
            'uapm_manage_audit',
            'uapm_manage_logs',
            'uapm_manage_privacy',
            'uapm_manage_diagnostics',
            'uapm_import_data',
            'uapm_export_data',
        );
    }

    private static function manager_capabilities() {
        return array(
            'read',
            'uapm_view_own_projects',
            'uapm_view_own_clients',
            'uapm_manage_own_clients',
            'uapm_view_own_commissions',
            'uapm_view_own_payments',
        );
    }

    public static function user_can_manage($user_id = 0) {
        $user_id = $user_id ? absint($user_id) : get_current_user_id();

        $user = get_userdata($user_id);

        if (!$user) {
            return false;
        }

        return user_can($user, 'uapm_manage_projects');
    }

    public static function user_can_manage_payments($user_id = 0) {
        $user_id = $user_id ? absint($user_id) : get_current_user_id();

        $user = get_userdata($user_id);

        if (!$user) {
            return false;
        }

        return user_can($user, 'uapm_manage_payments');
    }

    public static function user_can_manage_settings($user_id = 0) {
        $user_id = $user_id ? absint($user_id) : get_current_user_id();

        $user = get_userdata($user_id);

        if (!$user) {
            return false;
        }

        return user_can($user, 'uapm_manage_settings');
    }

    public static function user_can_inspect_code($user_id = 0) {
        $user_id = $user_id ? absint($user_id) : get_current_user_id();

        $user = get_userdata($user_id);

        if (!$user) {
            return false;
        }

        return user_can($user, 'uapm_inspect_code');
    }

    public static function remove_plugin_capabilities() {
        $roles = array('administrator', 'uapm_manager');

        foreach ($roles as $role_name) {
            $role = get_role($role_name);

            if (!$role) {
                continue;
            }

            foreach (self::admin_capabilities() as $capability) {
                $role->remove_cap($capability);
            }

            foreach (self::manager_capabilities() as $capability) {
                if ($role_name === 'uapm_manager') {
                    $role->remove_cap($capability);
                }
            }
        }

        remove_role('uapm_manager');
    }
}