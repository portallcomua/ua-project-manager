<?php
if (!defined('ABSPATH')) {
    exit;
}

class UAPM_Activity_Log {

    public static function init() {
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

    public static function add($event, $description, $object_type = '', $object_id = 0, $meta = array()) {
        global $wpdb;

        $table = self::table_name();

        $wpdb->insert(
            $table,
            array(
                'user_id' => get_current_user_id(),
                'event' => sanitize_key($event),
                'object_type' => sanitize_key($object_type),
                'object_id' => absint($object_id),
                'description' => sanitize_textarea_field($description),
                'meta' => wp_json_encode($meta),
                'created_at' => current_time('mysql'),
            ),
            array('%d', '%s', '%s', '%d', '%s', '%s', '%s')
        );
    }

    public static function get_all($limit = 50, $offset = 0, $event = '') {
        global $wpdb;

        $where = '1=1';
        $values = array();

        if ($event !== '') {
            $where .= ' AND event = %s';
            $values[] = sanitize_key($event);
        }

        $limit = min(500, max(1, absint($limit)));
        $offset = absint($offset);

        $sql = "SELECT * FROM " . self::table_name()
            . " WHERE $where ORDER BY id DESC LIMIT %d OFFSET %d";

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
            array(
                'target' => $target,
                'details' => $details,
            )
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
        self::add(
            'project_created',
            'Створено новий проєкт.',
            'project',
            $project_id
        );
    }

    public static function log_project_updated($project_id) {
        self::add(
            'project_updated',
            'Оновлено проєкт.',
            'project',
            $project_id
        );
    }

    public static function render_page() {
        if (!current_user_can('uapm_manage_projects')) {
            wp_die('Недостатньо прав.');
        }

        $events = self::get_all(100);

        echo '<div class="wrap uapm-admin">';
        echo '<h1>Журнал активності</h1>';
        echo '<table class="widefat striped">';
        echo '<thead><tr><th>Дата</th><th>Подія</th><th>Опис</th><th>Тип</th><th>ID</th><th>Користувач</th></tr></thead><tbody>';

        if ($events) {
            foreach ($events as $event) {
                $user = $event->user_id ? get_user_by('id', $event->user_id) : false;

                echo '<tr>';
                echo '<td>' . esc_html($event->created_at) . '</td>';
                echo '<td>' . esc_html($event->event) . '</td>';
                echo '<td>' . esc_html($event->description) . '</td>';
                echo '<td>' . esc_html($event->object_type) . '</td>';
                echo '<td>' . absint($event->object_id) . '</td>';
                echo '<td>' . esc_html($user ? $user->user_login : 'Система') . '</td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="6">Подій поки немає.</td></tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }
}