<?php
/**
 * UA Project Manager
 * Scheduled maintenance.
 *
 * @package UA_Project_Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

final class UAPM_Cron {

    const HOOK = 'uapm_scheduled_maintenance';

    public static function init() {
        add_filter('cron_schedules', array(__CLASS__, 'add_schedules'));
        add_action(self::HOOK, array(__CLASS__, 'run_maintenance'));
        add_action('init', array(__CLASS__, 'schedule_events'));
    }

    public static function add_schedules($schedules) {
        if (!isset($schedules['uapm_five_minutes'])) {
            $schedules['uapm_five_minutes'] = array(
                'interval' => 300,
                'display' => 'Кожні 5 хвилин',
            );
        }

        return $schedules;
    }

    public static function activate() {
        self::schedule_events();
    }

    public static function deactivate() {
        self::clear_events();
        delete_transient('uapm_maintenance_running');
    }

    public static function schedule_events() {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(
                time() + 300,
                'uapm_five_minutes',
                self::HOOK
            );
        }
    }

    public static function clear_events() {
        wp_clear_scheduled_hook(self::HOOK);
    }

    public static function run_maintenance() {
        if (get_transient('uapm_maintenance_running')) {
            return false;
        }

        set_transient('uapm_maintenance_running', 1, 240);

        $success = true;

        try {
            self::clean_old_logs();
            self::clean_old_notifications();
            self::clean_expired_transients();
            self::check_pending_payments();
            self::process_queue();
            self::check_expired_licenses();

            update_option(
                'uapm_last_maintenance',
                current_time('mysql'),
                false
            );

            do_action('uapm_maintenance_completed');

        } catch (Throwable $exception) {
            $success = false;

            if (class_exists('UAPM_Logs') && method_exists('UAPM_Logs', 'error')) {
                UAPM_Logs::error(
                    'Помилка планового обслуговування: ' . $exception->getMessage(),
                    array(
                        'file' => $exception->getFile(),
                        'line' => $exception->getLine(),
                    )
                );
            }
        } finally {
            delete_transient('uapm_maintenance_running');
        }

        return $success;
    }

    private static function table_exists($table_name) {
        global $wpdb;

        $allowed = array(
            'uapm_logs',
            'uapm_notifications',
            'uapm_licenses',
        );

        if (!in_array($table_name, $allowed, true)) {
            return false;
        }

        $table = $wpdb->prefix . $table_name;

        $found = $wpdb->get_var(
            $wpdb->prepare(
                'SHOW TABLES LIKE %s',
                $wpdb->esc_like($table)
            )
        );

        return $found === $table;
    }

    private static function clean_old_logs() {
        global $wpdb;

        if (!self::table_exists('uapm_logs')) {
            return;
        }

        $table = '`' . str_replace('`', '', $wpdb->prefix . 'uapm_logs') . '`';

        $wpdb->query(
            "DELETE FROM {$table}
             WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 90 DAY)"
        );
    }

    private static function clean_old_notifications() {
        global $wpdb;

        if (!self::table_exists('uapm_notifications')) {
            return;
        }

        $table = '`' . str_replace('`', '', $wpdb->prefix . 'uapm_notifications') . '`';

        $wpdb->query(
            "DELETE FROM {$table}
             WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 180 DAY)
             AND is_read = 1"
        );
    }

    private static function clean_expired_transients() {
        global $wpdb;

        $pattern = $wpdb->esc_like('_transient_timeout_uapm_') . '%';

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options}
                 WHERE option_name LIKE %s
                 AND CAST(option_value AS UNSIGNED) < %d",
                $pattern,
                time()
            )
        );

        $site_pattern = $wpdb->esc_like('_site_transient_timeout_uapm_') . '%';

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options}
                 WHERE option_name LIKE %s
                 AND CAST(option_value AS UNSIGNED) < %d",
                $site_pattern,
                time()
            )
        );
    }

    private static function check_pending_payments() {
        if (class_exists('UAPM_Payments')) {
            do_action('uapm_cron_check_pending_payments');
        }
    }

    private static function process_queue() {
        if (class_exists('UAPM_Queue')) {
            do_action('uapm_cron_process_queue');
        }
    }

    private static function check_expired_licenses() {
        global $wpdb;

        if (!self::table_exists('uapm_licenses')) {
            return;
        }

        $table = '`' . str_replace('`', '', $wpdb->prefix . 'uapm_licenses') . '`';

        $wpdb->query(
            "UPDATE {$table}
             SET status = 'expired'
             WHERE status = 'active'
             AND expires_at IS NOT NULL
             AND expires_at != '0000-00-00 00:00:00'
             AND expires_at < UTC_TIMESTAMP()"
        );

        do_action('uapm_expired_licenses_checked');
    }

    public static function get_last_run() {
        return (string) get_option('uapm_last_maintenance', '');
    }

    public static function get_next_run() {
        $timestamp = wp_next_scheduled(self::HOOK);

        return $timestamp ? (int) $timestamp : 0;
    }

    public static function run_now() {
        if (
            !current_user_can('uapm_manage_settings') &&
            !current_user_can('manage_options')
        ) {
            return new WP_Error(
                'uapm_forbidden',
                'Недостатньо прав для запуску обслуговування.'
            );
        }

        return self::run_maintenance();
    }
}