<?php
/**
 * UA Project Manager — Roles and Capabilities.
 *
 * @package UA_Project_Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('UAPM_Roles')) {

    final class UAPM_Roles {

        const MANAGER_ROLE = 'uapm_manager';

        public static function init() {
            add_action('init', array(__CLASS__, 'register_roles'), 5);
        }

        public static function register_roles() {
            self::create_manager_role();
            self::add_admin_capabilities();
        }

        public static function create_manager_role() {
            $capabilities = self::manager_capabilities();

            $role = get_role(self::MANAGER_ROLE);

            if (!$role) {
                add_role(
                    self::MANAGER_ROLE,
                    __('UAPM Manager', 'ua-project-manager'),
                    $capabilities
                );

                return;
            }

            foreach ($capabilities as $capability => $enabled) {
                if ($enabled) {
                    $role->add_cap($capability);
                }
            }
        }

        public static function manager_capabilities() {
            return array(
                'read' => true,
                'uapm_manage_projects' => true,
                'uapm_view_projects' => true,
                'uapm_manage_clients' => true,
                'uapm_view_clients' => true,
                'uapm_manage_integrations' => true,
                'uapm_view_integrations' => true,
                'uapm_manage_installations' => true,
                'uapm_view_installations' => true,
                'uapm_manage_downloads' => true,
                'uapm_view_downloads' => true,
                'uapm_manage_tasks' => true,
                'uapm_view_tasks' => true,
                'uapm_manage_notes' => true,
                'uapm_view_notes' => true,
                'uapm_manage_comments' => true,
                'uapm_view_comments' => true,
                'uapm_manage_activity' => true,
                'uapm_view_activity' => true,
                'uapm_view_dashboard' => true,
                'uapm_view_reports' => true,
                'uapm_manage_notifications' => true,
                'uapm_view_notifications' => true,
                'uapm_manage_portal' => true,
                'uapm_view_portal' => true,
            );
        }

        public static function admin_capabilities() {
            return array(
                'uapm_manage_projects',
                'uapm_view_projects',
                'uapm_manage_clients',
                'uapm_view_clients',
                'uapm_manage_payments',
                'uapm_view_payments',
                'uapm_manage_integrations',
                'uapm_view_integrations',
                'uapm_manage_installations',
                'uapm_view_installations',
                'uapm_manage_downloads',
                'uapm_view_downloads',
                'uapm_manage_tasks',
                'uapm_view_tasks',
                'uapm_manage_notes',
                'uapm_view_notes',
                'uapm_manage_comments',
                'uapm_view_comments',
                'uapm_manage_activity',
                'uapm_view_activity',
                'uapm_view_dashboard',
                'uapm_view_reports',
                'uapm_manage_notifications',
                'uapm_view_notifications',
                'uapm_manage_settings',
                'uapm_manage_system',
                'uapm_manage_security',
                'uapm_manage_backups',
                'uapm_manage_api',
                'uapm_manage_webhooks',
                'uapm_manage_licenses',
                'uapm_manage_portal',
                'uapm_view_portal',
                'uapm_manage_code_generator',
                'uapm_manage_code_inspector',
                'uapm_manage_analytics',
                'uapm_manage_logs',
                'uapm_manage_export',
                'uapm_manage_import',
                'uapm_manage_privacy',
                'uapm_manage_email_templates',
                'uapm_manage_repository',
                'uapm_manage_updater',
                'uapm_manage_diagnostics',
            );
        }

        public static function add_admin_capabilities() {
            $admin = get_role('administrator');

            if (!$admin) {
                return;
            }

            foreach (self::admin_capabilities() as $capability) {
                $admin->add_cap($capability);
            }
        }

        public static function remove_manager_role() {
            remove_role(self::MANAGER_ROLE);
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

            return in_array(self::MANAGER_ROLE, (array) $user->roles, true);
        }

        public static function has_capability($capability, $user_id = 0) {
            $user_id = $user_id ? absint($user_id) : get_current_user_id();

            if (!$user_id) {
                return false;
            }

            return user_can($user_id, $capability);
        }

        public static function get_manager_role() {
            return get_role(self::MANAGER_ROLE);
        }
    }

    UAPM_Roles::init();
}
