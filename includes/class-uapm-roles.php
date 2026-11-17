<?php
/**
 * UA Project Manager — Roles and capabilities.
 *
 * @package UA_Project_Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

final class UAPM_Roles {

    const ROLE_MANAGER = 'uapm_manager';

    public static function init() {
        add_action('init', array(__CLASS__, 'ensure_roles'), 5);
    }

    public static function manager_capabilities() {
        return array(
            'read' => true,
            'uapm_access_manager' => true,
            'uapm_view_own_projects' => true,
            'uapm_view_own_clients' => true,
            'uapm_manage_own_clients' => true,
            'uapm_view_own_commissions' => true,
            'uapm_view_own_payments' => true,
        );
    }

    public static function admin_capabilities() {
        return array(
            'uapm_manage_projects',
            'uapm_manage_clients',
            'uapm_manage_payments',
            'uapm_manage_downloads',
            'uapm_manage_installations',
            'uapm_manage_integrations',
            'uapm_manage_settings',
            'uapm_manage_mailings',
            'uapm_view_reports',
            'uapm_manage_codes',
            'uapm_manage_github',
            'uapm_manage_inspector',
            'uapm_inspect_code',
        );
    }

    public static function ensure_roles() {
        self::ensure_manager_role();
        self::ensure_administrator_capabilities();
    }

    private static function ensure_manager_role() {
        $manager = get_role(self::ROLE_MANAGER);

        if (!$manager) {
            add_role(
                self::ROLE_MANAGER,
                self::manager_role_name(),
                self::manager_capabilities()
            );

            $manager = get_role(self::ROLE_MANAGER);
        }

        if (!$manager) {
            return;
        }

        foreach (self::manager_capabilities() as $capability => $grant) {
            if ($grant) {
                $manager->add_cap($capability);
            }
        }

        self::update_manager_role_name();
    }

    private static function ensure_administrator_capabilities() {
        $admin = get_role('administrator');

        if (!$admin) {
            return;
        }

        foreach (self::admin_capabilities() as $capability) {
            $admin->add_cap($capability);
        }
    }

    private static function manager_role_name() {
        if (
            class_exists('UAPM_Localization') &&
            UAPM_Localization::get_language() === 'en'
        ) {
            return 'UPM Manager';
        }

        return 'Менеджер UPM';
    }

    private static function update_manager_role_name() {
        if (!function_exists('wp_roles')) {
            return;
        }

        $roles = wp_roles();

        if (
            !is_object($roles) ||
            empty($roles->roles[self::ROLE_MANAGER])
        ) {
            return;
        }

        $name = self::manager_role_name();

        $roles->roles[self::ROLE_MANAGER]['name'] = $name;
        $roles->role_names[self::ROLE_MANAGER] = $name;

        update_option($roles->role_key, $roles->roles);
    }

    public static function remove_roles() {
        $manager = get_role(self::ROLE_MANAGER);

        if ($manager) {
            remove_role(self::ROLE_MANAGER);
        }

        /*
         * Capabilities адміністратора навмисно не видаляються
         * під час звичайної деактивації плагіна.
         * Це запобігає втраті доступу до даних UPM.
         */
    }

    public static function is_manager($user_id = 0) {
        $user_id = $user_id ? absint($user_id) : get_current_user_id();

        if (!$user_id) {
            return false;
        }

        $user = get_userdata($user_id);

        if (!$user) {
            return false;
        }

        return in_array(self::ROLE_MANAGER, (array) $user->roles, true);
    }

    public static function can_manage_own_clients($user_id = 0) {
        $user_id = $user_id ? absint($user_id) : get_current_user_id();

        return self::is_manager($user_id) &&
            user_can($user_id, 'uapm_manage_own_clients');
    }

    public static function can_manage_admin($user_id = 0) {
        $user_id = $user_id ? absint($user_id) : get_current_user_id();

        return $user_id && (
            user_can($user_id, 'manage_options') ||
            user_can($user_id, 'uapm_manage_projects')
        );
    }
}

UAPM_Roles::init();
