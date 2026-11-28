<?php
/**
 * UA Project Manager — activity log.
 *
 * @package UA_Project_Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

final class UAPM_Activity_Log {

    public static function init() {
        if (defined('UAPM_DB_VERSION') && get_option('uapm_modules_schema_ver') !== UAPM_DB_VERSION) {
            self::install_schema();
        }
        add_action('uapm_pro_access_changed', array(__CLASS__, 'log_pro_change'), 10, 4);
        add_action('uapm_payment_received', array(__CLASS__, 'log_payment_received'), 10, 2);
        add_action('uapm_installation_registered', array(__CLASS__, 'log_installation'), 10, 2);
        add_action('uapm_project_created', array(__CLASS__, 'log_project_created'), 10, 1);
        add_action('uapm_project_updated', array(__CLASS__, 'log_project_updated'), 10, 1);
    }

    private static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'uapm_activity_log';
    }

    public static function install_schema() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
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
        ) {$charset};";

        dbDelta($sql);
    }

    public static function add($event, $description, $object_type = '', $object_id = 0, $meta = array()) {
        global $wpdb;

        $event = sanitize_key($event);
        $description = sanitize_textarea_field($description);
        $object_type = sanitize_key($object_type);
        $object_id = absint($object_id);
        $meta = is_array($meta) ? $meta : array();

        $data = array(
            'user_id' => get_current_user_id(),
            'project_id' => 0,
            'event_type' => $event,
            'description' => $description,
            'context' => wp_json_encode($meta),
            'event' => $event,
            'object_type' => $object_type,
            'object_id' => $object_id,
            'meta' => wp_json_encode($meta),
            'created_at' => current_time('mysql'),
        );

        $wpdb->insert(self::table_name(), $data);
    }

    public static function get_all($limit = 50, $offset = 0, $event = '') {
        global $wpdb;

        $where = '1=1';
        $values = array();

        if ($event !== '') {
            $where .= ' AND (event = %s OR event_type = %s)';
            $values[] = sanitize_key($event);
            $values[] = sanitize_key($event);
        }

        $limit = min(500, max(1, absint($limit)));
        $offset = absint($offset);

        $sql = "SELECT * FROM " . self::table_name() . " WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
        $values[] = $limit;
        $values[] = $offset;

        return $wpdb->get_results($wpdb->prepare($sql, $values));
    }

    public static function log_pro_change($payment_id, $target, $status, $details = array()) {
        self::add(
            'pro_access_changed',
            'Змінено статус PRO-доступу: ' . sanitize_text_field($status),
            'payment',
            $payment_id,
            array('target' => $target, 'details' => $details)
        );
    }

    public static function log_payment_received($payment_id, $amount) {
        self::add(
            'payment_received',
            'Отримано платіж: ' . number_format_i18n((float) $amount, 2),
            'payment',
            $payment_id,
            array('amount' => (float) $amount)
        );
    }

    public static function log_installation($installation_id, $project_id) {
        self::add(
            'installation_registered',
            'Зареєстровано нове встановлення.',
            'installation',
            $installation_id,
            array('project_id' => absint($project_id))
        );
    }

    public static function log_project_created($project_id) {
        self::add('project_created', 'Створено новий проєкт.', 'project', $project_id);
    }

    public static function log_project_updated($project_id) {
        self::add('project_updated', 'Оновлено проєкт.', 'project', $project_id);
    }

    public static function render_page() {
        if (!current_user_can('uapm_manage_projects')) {
            wp_die('Недостатньо прав.');
        }

        $events = self::get_all(100);

        echo '<div class="wrap uapm-admin">';
        echo '<h1>Журнал активності</h1>';
        echo '<table class="widefat striped"><thead><tr><th>Дата</th><th>Подія</th><th>Опис</th><th>Тип</th><th>ID</th><th>Користувач</th></tr></thead><tbody>';

        foreach ($events as $event) {
            $user = $event->user_id ? get_user_by('id', $event->user_id) : false;
            $event_name = !empty($event->event) ? $event->event : $event->event_type;
            $object_type = isset($event->object_type) ? $event->object_type : '';
            $object_id = isset($event->object_id) ? $event->object_id : 0;

            echo '<tr>';
            echo '<td>' . esc_html($event->created_at) . '</td>';
            echo '<td>' . esc_html($event_name) . '</td>';
            echo '<td>' . esc_html($event->description) . '</td>';
            echo '<td>' . esc_html($object_type) . '</td>';
            echo '<td>' . absint($object_id) . '</td>';
            echo '<td>' . esc_html($user ? $user->user_login : 'Система') . '</td>';
            echo '</tr>';
        }

        if (!$events) {
            echo '<tr><td colspan="6">Подій поки немає.</td></tr>';
        }

        echo '</tbody></table></div>';
    }
}

UAPM_Activity_Log::init();
