<?php
/**
 * UA Project Manager — Background Queue.
 *
 * @package UA_Project_Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

class UAPM_Queue {

    const TABLE = 'uapm_queue';

    public static function init() {
        add_action('uapm_process_queue', array(__CLASS__, 'process'));
        add_action('init', array(__CLASS__, 'schedule'));
    }

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    public static function install_schema() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            queue_name varchar(100) NOT NULL DEFAULT 'default',
            task_type varchar(100) NOT NULL,
            payload longtext NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            attempts smallint(5) unsigned NOT NULL DEFAULT 0,
            max_attempts smallint(5) unsigned NOT NULL DEFAULT 3,
            scheduled_at datetime NOT NULL,
            locked_at datetime NULL,
            last_error text NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY status_scheduled (status, scheduled_at),
            KEY queue_name (queue_name)
        ) {$charset};";

        dbDelta($sql);
    }

    public static function schedule() {
        if (!wp_next_scheduled('uapm_process_queue')) {
            wp_schedule_event(time() + 60, 'uapm_five_minutes', 'uapm_process_queue');
        }
    }

    public static function register_cron_interval($schedules) {
        $schedules['uapm_five_minutes'] = array(
            'interval' => 300,
            'display' => __('Every five minutes', 'ua-project-manager'),
        );

        return $schedules;
    }

    public static function enqueue($task_type, $payload = array(), $args = array()) {
        global $wpdb;

        $task_type = sanitize_key($task_type);

        if (!$task_type) {
            return false;
        }

        $args = wp_parse_args($args, array(
            'queue_name' => 'default',
            'scheduled_at' => current_time('mysql'),
            'max_attempts' => 3,
        ));

        $payload = maybe_serialize($payload);

        $inserted = $wpdb->insert(
            self::table_name(),
            array(
                'queue_name' => sanitize_key($args['queue_name']),
                'task_type' => $task_type,
                'payload' => $payload,
                'status' => 'pending',
                'attempts' => 0,
                'max_attempts' => max(1, min(10, absint($args['max_attempts']))),
                'scheduled_at' => sanitize_text_field($args['scheduled_at']),
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ),
            array('%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s')
        );

        if (!$inserted) {
            return false;
        }

        if (!wp_next_scheduled('uapm_process_queue')) {
            wp_schedule_single_event(time() + 10, 'uapm_process_queue');
        }

        return (int) $wpdb->insert_id;
    }

    public static function process($limit = 10) {
        global $wpdb;

        $limit = max(1, min(50, absint($limit)));

        self::release_stale_locks();

        $tasks = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . self::table_name() . "
                WHERE status = 'pending'
                AND scheduled_at <= %s
                ORDER BY scheduled_at ASC, id ASC
                LIMIT %d",
                current_time('mysql'),
                $limit
            )
        );

        if (!$tasks) {
            return 0;
        }

        $processed = 0;

        foreach ($tasks as $task) {
            if (!self::lock_task($task->id)) {
                continue;
            }

            self::run_task($task);
            $processed++;
        }

        return $processed;
    }

    private static function lock_task($task_id) {
        global $wpdb;

        $now = current_time('mysql');

        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE " . self::table_name() . "
                SET status = 'processing',
                    locked_at = %s,
                    updated_at = %s
                WHERE id = %d AND status = 'pending'",
                $now,
                $now,
                absint($task_id)
            )
        );

        return 1 === (int) $updated;
    }

    private static function run_task($task) {
        $payload = maybe_unserialize($task->payload);

        if (!is_array($payload)) {
            $payload = array();
        }

        try {
            $result = apply_filters(
                'uapm_queue_execute_' . sanitize_key($task->task_type),
                null,
                $payload,
                $task
            );

            do_action(
                'uapm_queue_task',
                $task->task_type,
                $payload,
                $task,
                $result
            );

            self::complete($task->id);

        } catch (Throwable $exception) {
            self::fail($task, $exception->getMessage());
        }
    }

    public static function complete($task_id) {
        global $wpdb;

        return false !== $wpdb->update(
            self::table_name(),
            array(
                'status' => 'completed',
                'locked_at' => null,
                'last_error' => null,
                'updated_at' => current_time('mysql'),
            ),
            array('id' => absint($task_id)),
            array('%s', '%s', '%s', '%s'),
            array('%d')
        );
    }

    public static function fail($task, $error) {
        global $wpdb;

        $attempts = absint($task->attempts) + 1;
        $max_attempts = max(1, absint($task->max_attempts));

        $status = $attempts >= $max_attempts ? 'failed' : 'pending';
        $delay = min(3600, 60 * (2 ** min($attempts, 6)));

        $scheduled_at = $status === 'pending'
            ? date('Y-m-d H:i:s', current_time('timestamp') + $delay)
            : current_time('mysql');

        return $wpdb->update(
            self::table_name(),
            array(
                'status' => $status,
                'attempts' => $attempts,
                'scheduled_at' => $scheduled_at,
                'locked_at' => null,
                'last_error' => sanitize_textarea_field($error),
                'updated_at' => current_time('mysql'),
            ),
            array('id' => absint($task->id)),
            array('%s', '%d', '%s', '%s', '%s', '%s'),
            array('%d')
        );
    }

    public static function release_stale_locks($minutes = 15) {
        global $wpdb;

        $minutes = max(1, absint($minutes));
        $expired = date(
            'Y-m-d H:i:s',
            current_time('timestamp') - ($minutes * MINUTE_IN_SECONDS)
        );

        return $wpdb->query(
            $wpdb->prepare(
                "UPDATE " . self::table_name() . "
                SET status = 'pending',
                    locked_at = NULL,
                    updated_at = %s
                WHERE status = 'processing'
                AND locked_at < %s",
                current_time('mysql'),
                $expired
            )
        );
    }

    public static function get_tasks($args = array()) {
        global $wpdb;

        $args = wp_parse_args($args, array(
            'status' => '',
            'queue_name' => '',
            'limit' => 50,
            'offset' => 0,
        ));

        $where = array('1=1');
        $values = array();

        if ($args['status'] !== '') {
            $where[] = 'status = %s';
            $values[] = sanitize_key($args['status']);
        }

        if ($args['queue_name'] !== '') {
            $where[] = 'queue_name = %s';
            $values[] = sanitize_key($args['queue_name']);
        }

        $limit = max(1, min(200, absint($args['limit'])));
        $offset = max(0, absint($args['offset']));

        $sql = "SELECT * FROM " . self::table_name() . "
                WHERE " . implode(' AND ', $where) . "
                ORDER BY created_at DESC, id DESC
                LIMIT %d OFFSET %d";

        $values[] = $limit;
        $values[] = $offset;

        return $wpdb->get_results($wpdb->prepare($sql, $values));
    }

    public static function count($status = '') {
        global $wpdb;

        if ($status !== '') {
            return (int) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM ' . self::table_name() . ' WHERE status = %s',
                    sanitize_key($status)
                )
            );
        }

        return (int) $wpdb->get_var(
            'SELECT COUNT(*) FROM ' . self::table_name()
        );
    }

    public static function retry($task_id) {
        global $wpdb;

        return false !== $wpdb->update(
            self::table_name(),
            array(
                'status' => 'pending',
                'attempts' => 0,
                'scheduled_at' => current_time('mysql'),
                'locked_at' => null,
                'last_error' => null,
                'updated_at' => current_time('mysql'),
            ),
            array(
                'id' => absint($task_id),
                'status' => 'failed',
            ),
            array('%s', '%d', '%s', '%s', '%s', '%s'),
            array('%d', '%s')
        );
    }

    public static function delete($task_id) {
        global $wpdb;

        return false !== $wpdb->delete(
            self::table_name(),
            array('id' => absint($task_id)),
            array('%d')
        );
    }

    public static function cleanup($days = 30) {
        global $wpdb;

        $days = max(1, absint($days));
        $date = date(
            'Y-m-d H:i:s',
            current_time('timestamp') - ($days * DAY_IN_SECONDS)
        );

        return $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM " . self::table_name() . "
                WHERE status IN ('completed', 'failed')
                AND updated_at < %s",
                $date
            )
        );
    }
}

add_filter('cron_schedules', array('UAPM_Queue', 'register_cron_interval'));

UAPM_Queue::init();
