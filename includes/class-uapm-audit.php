<?php
if (!defined('ABSPATH')) {
    exit;
}

class UAPM_Audit {

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'uapm_audit';
    }

    public static function init() {
        if (defined('UAPM_DB_VERSION') && get_option('uapm_modules_schema_ver') !== UAPM_DB_VERSION) {
            self::install_schema();
        }
        add_action('admin_post_uapm_audit_clear', array(__CLASS__, 'handle_clear'));
        add_action('admin_post_uapm_audit_export', array(__CLASS__, 'handle_export'));

        add_action('uapm_payment_received', array(__CLASS__, 'log_payment'), 10, 2);
        add_action('uapm_pro_access_changed', array(__CLASS__, 'log_pro_change'), 10, 4);
        add_action('uapm_project_created', array(__CLASS__, 'log_project_created'), 10, 1);
        add_action('uapm_project_updated', array(__CLASS__, 'log_project_updated'), 10, 1);
        add_action('uapm_integration_registered', array(__CLASS__, 'log_integration'), 10, 1);
    }

    public static function install_schema() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE " . self::table() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            event_type varchar(100) NOT NULL,
            object_type varchar(100) NOT NULL DEFAULT '',
            object_id bigint(20) unsigned NOT NULL DEFAULT 0,
            description text NOT NULL,
            context longtext NULL,
            ip_hash varchar(64) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY event_type (event_type),
            KEY object_id (object_id),
            KEY created_at (created_at)
        ) {$charset};";

        dbDelta($sql);
    }

    public static function log($event_type, $description, $object_type = '', $object_id = 0, $context = array()) {
        global $wpdb;

        $event_type = sanitize_key($event_type);

        if (!$event_type || !is_scalar($description)) {
            return false;
        }

        $ip = isset($_SERVER['REMOTE_ADDR'])
            ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
            : '';

        $ip_hash = $ip
            ? hash_hmac('sha256', $ip, wp_salt('auth'))
            : '';

        $result = $wpdb->insert(
            self::table(),
            array(
                'user_id' => get_current_user_id(),
                'event_type' => $event_type,
                'object_type' => sanitize_key($object_type),
                'object_id' => absint($object_id),
                'description' => sanitize_text_field($description),
                'context' => wp_json_encode($context, JSON_UNESCAPED_UNICODE),
                'ip_hash' => $ip_hash,
                'created_at' => current_time('mysql'),
            ),
            array('%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s')
        );

        return $result !== false;
    }

    public static function log_payment($payment_id, $payment = null) {
        self::log(
            'payment_received',
            'Зафіксовано надходження коштів за платежем #' . absint($payment_id),
            'payment',
            $payment_id
        );
    }

    public static function log_pro_change($payment_id, $status, $target = '', $project_id = 0) {
        self::log(
            'pro_access_changed',
            'Змінено доступ PRO: ' . sanitize_text_field($status),
            'payment',
            $payment_id,
            array(
                'target' => sanitize_text_field($target),
                'project_id' => absint($project_id),
            )
        );
    }

    public static function log_project_created($project_id) {
        self::log(
            'project_created',
            'Створено проєкт #' . absint($project_id),
            'project',
            $project_id
        );
    }

    public static function log_project_updated($project_id) {
        self::log(
            'project_updated',
            'Оновлено проєкт #' . absint($project_id),
            'project',
            $project_id
        );
    }

    public static function log_integration($integration_id) {
        self::log(
            'integration_registered',
            'Зареєстровано інтеграцію #' . absint($integration_id),
            'integration',
            $integration_id
        );
    }

    public static function get_all($args = array()) {
        global $wpdb;

        $defaults = array(
            'event_type' => '',
            'user_id' => 0,
            'search' => '',
            'limit' => 100,
            'offset' => 0,
        );

        $args = wp_parse_args($args, $defaults);

        $where = array('1=1');
        $values = array();

        if ($args['event_type']) {
            $where[] = 'event_type = %s';
            $values[] = sanitize_key($args['event_type']);
        }

        if ($args['user_id']) {
            $where[] = 'user_id = %d';
            $values[] = absint($args['user_id']);
        }

        if ($args['search']) {
            $where[] = 'description LIKE %s';
            $values[] = '%' . $wpdb->esc_like($args['search']) . '%';
        }

        $limit = max(1, min(1000, absint($args['limit'])));
        $offset = absint($args['offset']);

        $sql = 'SELECT * FROM ' . self::table()
            . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY id DESC LIMIT %d OFFSET %d';

        $values[] = $limit;
        $values[] = $offset;

        return $wpdb->get_results(
            $wpdb->prepare($sql, $values)
        );
    }

    public static function count($args = array()) {
        global $wpdb;

        $where = array('1=1');
        $values = array();

        if (!empty($args['event_type'])) {
            $where[] = 'event_type = %s';
            $values[] = sanitize_key($args['event_type']);
        }

        if (!empty($args['user_id'])) {
            $where[] = 'user_id = %d';
            $values[] = absint($args['user_id']);
        }

        if (!empty($args['search'])) {
            $where[] = 'description LIKE %s';
            $values[] = '%' . $wpdb->esc_like($args['search']) . '%';
        }

        $sql = 'SELECT COUNT(*) FROM ' . self::table()
            . ' WHERE ' . implode(' AND ', $where);

        if ($values) {
            $sql = $wpdb->prepare($sql, $values);
        }

        return (int) $wpdb->get_var($sql);
    }

    public static function handle_clear() {
        if (!current_user_can('uapm_manage_settings')) {
            wp_die('Недостатньо прав.');
        }

        check_admin_referer('uapm_audit_clear');

        global $wpdb;
        $wpdb->query('TRUNCATE TABLE ' . self::table());

        self::log(
            'audit_cleared',
            'Журнал аудиту очищено.'
        );

        wp_safe_redirect(admin_url('admin.php?page=uapm-audit&cleared=1'));
        exit;
    }

    public static function handle_export() {
        if (!current_user_can('uapm_manage_settings')) {
            wp_die('Недостатньо прав.');
        }

        check_admin_referer('uapm_audit_export');

        $items = self::get_all(array('limit' => 1000));

        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="uapm-audit-' . gmdate('Y-m-d') . '.csv"');

        $output = fopen('php://output', 'w');

        if (!$output) {
            wp_die('Не вдалося створити CSV.');
        }

        fwrite($output, "\xEF\xBB\xBF");

        fputcsv(
            $output,
            array('ID', 'Дата', 'Користувач', 'Подія', 'Об’єкт', 'ID об’єкта', 'Опис'),
            ';'
        );

        foreach ($items as $item) {
            $row = array(
                $item->id,
                $item->created_at,
                $item->user_id,
                $item->event_type,
                $item->object_type,
                $item->object_id,
                $item->description,
            );

            foreach ($row as &$value) {
                $value = (string) $value;

                if (preg_match('/^[\s]*[=+\-@]/u', $value)) {
                    $value = "'" . $value;
                }
            }
            unset($value);

            fputcsv($output, $row, ';');
        }

        fclose($output);
        exit;
    }

    public static function render_page() {
        if (!current_user_can('uapm_manage_settings')) {
            wp_die('Недостатньо прав.');
        }

        $items = self::get_all(array('limit' => 300));

        echo '<div class="wrap uapm-wrap">';
        echo '<h1>Журнал аудиту</h1>';
        echo '<p>Історія важливих дій у системі UA Project Manager.</p>';

        echo '<div class="uapm-card">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:8px">';
        echo '<input type="hidden" name="action" value="uapm_audit_export">';
        wp_nonce_field('uapm_audit_export');
        submit_button('Експорт CSV', 'secondary', 'submit', false);
        echo '</form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block" onsubmit="return confirm(\'Ви впевнені, що хочете очистити журнал?\')">';
        echo '<input type="hidden" name="action" value="uapm_audit_clear">';
        wp_nonce_field('uapm_audit_clear');
        submit_button('Очистити журнал', 'delete', 'submit', false);
        echo '</form>';
        echo '</div>';

        echo '<div class="uapm-card">';
        echo '<table class="widefat striped">';
        echo '<thead><tr><th>Дата</th><th>Користувач</th><th>Подія</th><th>Об’єкт</th><th>Опис</th></tr></thead><tbody>';

        if (!$items) {
            echo '<tr><td colspan="5">Записів поки немає.</td></tr>';
        }

        foreach ($items as $item) {
            $user = $item->user_id
                ? get_userdata($item->user_id)
                : false;

            echo '<tr>';
            echo '<td>' . esc_html($item->created_at) . '</td>';
            echo '<td>' . esc_html($user ? $user->user_login : 'Система') . '</td>';
            echo '<td>' . esc_html($item->event_type) . '</td>';
            echo '<td>' . esc_html($item->object_type . ' #' . $item->object_id) . '</td>';
            echo '<td>' . esc_html($item->description) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div></div>';
    }
}