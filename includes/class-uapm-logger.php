<?php
/**
 * UA Project Manager — Logger.
 *
 * @package UA_Project_Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

class UAPM_Logger {

    const TABLE = 'uapm_logs';

    public static function init() {
        add_action('uapm_log', array(__CLASS__, 'log'), 10, 4);
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
            level varchar(20) NOT NULL DEFAULT 'info',
            source varchar(100) NOT NULL DEFAULT 'system',
            message text NOT NULL,
            context longtext NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            ip_address varchar(45) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY level (level),
            KEY source (source),
            KEY user_id (user_id),
            KEY created_at (created_at)
        ) {$charset};";

        dbDelta($sql);
    }

    public static function log($level, $message, $context = array(), $source = 'system') {
        global $wpdb;

        $allowed_levels = array('debug', 'info', 'warning', 'error', 'critical');
        $level = sanitize_key($level);

        if (!in_array($level, $allowed_levels, true)) {
            $level = 'info';
        }

        if (!is_scalar($message)) {
            $message = wp_json_encode($message);
        }

        $context = is_array($context) ? $context : array();

        $wpdb->insert(
            self::table_name(),
            array(
                'level' => $level,
                'source' => sanitize_key($source),
                'message' => sanitize_textarea_field((string) $message),
                'context' => maybe_serialize(self::sanitize_context($context)),
                'user_id' => get_current_user_id(),
                'ip_address' => self::get_ip_address(),
                'created_at' => current_time('mysql'),
            ),
            array('%s', '%s', '%s', '%s', '%d', '%s', '%s')
        );

        return (int) $wpdb->insert_id;
    }

    public static function debug($message, $context = array(), $source = 'system') {
        return self::log('debug', $message, $context, $source);
    }

    public static function info($message, $context = array(), $source = 'system') {
        return self::log('info', $message, $context, $source);
    }

    public static function warning($message, $context = array(), $source = 'system') {
        return self::log('warning', $message, $context, $source);
    }

    public static function error($message, $context = array(), $source = 'system') {
        return self::log('error', $message, $context, $source);
    }

    public static function critical($message, $context = array(), $source = 'system') {
        return self::log('critical', $message, $context, $source);
    }

    private static function sanitize_context($context) {
        $clean = array();

        foreach ($context as $key => $value) {
            $key = sanitize_key($key);

            if (preg_match('/password|secret|token|api.?key|authorization|cookie/i', $key)) {
                $clean[$key] = '[REDACTED]';
                continue;
            }

            if (is_array($value)) {
                $clean[$key] = self::sanitize_context($value);
            } elseif (is_scalar($value) || $value === null) {
                $clean[$key] = sanitize_text_field((string) $value);
            } else {
                $clean[$key] = '[UNSUPPORTED]';
            }
        }

        return $clean;
    }

    private static function get_ip_address() {
        $ip = isset($_SERVER['REMOTE_ADDR'])
            ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
            : '';

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

    public static function get_logs($args = array()) {
        global $wpdb;

        $args = wp_parse_args($args, array(
            'level' => '',
            'source' => '',
            'search' => '',
            'limit' => 50,
            'offset' => 0,
        ));

        $where = array('1=1');
        $values = array();

        if ($args['level'] !== '') {
            $where[] = 'level = %s';
            $values[] = sanitize_key($args['level']);
        }

        if ($args['source'] !== '') {
            $where[] = 'source = %s';
            $values[] = sanitize_key($args['source']);
        }

        if ($args['search'] !== '') {
            $where[] = 'message LIKE %s';
            $values[] = '%' . $wpdb->esc_like(sanitize_text_field($args['search'])) . '%';
        }

        $values[] = max(1, min(200, absint($args['limit'])));
        $values[] = max(0, absint($args['offset']));

        $sql = "SELECT * FROM " . self::table_name() . "
                WHERE " . implode(' AND ', $where) . "
                ORDER BY id DESC
                LIMIT %d OFFSET %d";

        $logs = $wpdb->get_results($wpdb->prepare($sql, $values));

        foreach ($logs as $log) {
            $log->context = maybe_unserialize($log->context);
        }

        return $logs;
    }

    public static function count($level = '') {
        global $wpdb;

        if ($level !== '') {
            return (int) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM ' . self::table_name() . ' WHERE level = %s',
                    sanitize_key($level)
                )
            );
        }

        return (int) $wpdb->get_var(
            'SELECT COUNT(*) FROM ' . self::table_name()
        );
    }

    public static function delete_old($days = 90) {
        global $wpdb;

        $days = max(1, absint($days));
        $date = date(
            'Y-m-d H:i:s',
            current_time('timestamp') - ($days * DAY_IN_SECONDS)
        );

        return $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM ' . self::table_name() . ' WHERE created_at < %s',
                $date
            )
        );
    }

    public static function clear() {
        global $wpdb;

        return $wpdb->query('TRUNCATE TABLE ' . self::table_name());
    }
}

UAPM_Logger::init();
