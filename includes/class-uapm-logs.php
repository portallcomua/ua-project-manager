<?php
if (!defined('ABSPATH')) {
    exit;
}

class UAPM_Logs {

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'uapm_logs';
    }

    public static function init() {
        if (defined('UAPM_DB_VERSION') && get_option('uapm_modules_schema_ver') !== UAPM_DB_VERSION) {
            self::install_schema();
        }
        add_action('admin_post_uapm_logs_clear', array(__CLASS__, 'handle_clear'));
        add_action('admin_post_uapm_logs_export', array(__CLASS__, 'handle_export'));

        add_action('wp_ajax_uapm_logs_get', array(__CLASS__, 'ajax_get'));
    }

    public static function install_schema() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE " . self::table() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            level varchar(20) NOT NULL DEFAULT 'info',
            channel varchar(100) NOT NULL DEFAULT 'system',
            message text NOT NULL,
            context longtext NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY level (level),
            KEY channel (channel),
            KEY user_id (user_id),
            KEY created_at (created_at)
        ) {$charset};";

        dbDelta($sql);
    }

    public static function write($level, $channel, $message, $context = array()) {
        global $wpdb;

        $allowed_levels = array('debug', 'info', 'warning', 'error', 'critical');

        $level = sanitize_key($level);
        $channel = sanitize_key($channel);

        if (!in_array($level, $allowed_levels, true)) {
            $level = 'info';
        }

        if (!$channel || !is_scalar($message)) {
            return false;
        }

        return $wpdb->insert(
            self::table(),
            array(
                'level' => $level,
                'channel' => $channel,
                'message' => sanitize_text_field($message),
                'context' => wp_json_encode($context, JSON_UNESCAPED_UNICODE),
                'user_id' => get_current_user_id(),
                'created_at' => current_time('mysql'),
            ),
            array('%s', '%s', '%s', '%s', '%d', '%s')
        ) !== false;
    }

    public static function debug($channel, $message, $context = array()) {
        return self::write('debug', $channel, $message, $context);
    }

    public static function info($channel, $message, $context = array()) {
        return self::write('info', $channel, $message, $context);
    }

    public static function warning($channel, $message, $context = array()) {
        return self::write('warning', $channel, $message, $context);
    }

    public static function error($channel, $message, $context = array()) {
        return self::write('error', $channel, $message, $context);
    }

    public static function critical($channel, $message, $context = array()) {
        return self::write('critical', $channel, $message, $context);
    }

    public static function get_all($args = array()) {
        global $wpdb;

        $defaults = array(
            'level' => '',
            'channel' => '',
            'search' => '',
            'limit' => 200,
            'offset' => 0,
        );

        $args = wp_parse_args($args, $defaults);

        $where = array('1=1');
        $values = array();

        if ($args['level']) {
            $where[] = 'level = %s';
            $values[] = sanitize_key($args['level']);
        }

        if ($args['channel']) {
            $where[] = 'channel = %s';
            $values[] = sanitize_key($args['channel']);
        }

        if ($args['search']) {
            $where[] = 'message LIKE %s';
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

        if (!empty($args['level'])) {
            $where[] = 'level = %s';
            $values[] = sanitize_key($args['level']);
        }

        if (!empty($args['channel'])) {
            $where[] = 'channel = %s';
            $values[] = sanitize_key($args['channel']);
        }

        if (!empty($args['search'])) {
            $where[] = 'message LIKE %s';
            $values[] = '%' . $wpdb->esc_like($args['search']) . '%';
        }

        $sql = 'SELECT COUNT(*) FROM ' . self::table()
            . ' WHERE ' . implode(' AND ', $where);

        if ($values) {
            $sql = $wpdb->prepare($sql, $values);
        }

        return (int) $wpdb->get_var($sql);
    }

    public static function get_error_count($hours = 24) {
        global $wpdb;

        $hours = max(1, min(8760, absint($hours)));

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM " . self::table() . "
                WHERE level IN ('error', 'critical')
                AND created_at >= DATE_SUB(%s, INTERVAL %d HOUR)",
                current_time('mysql'),
                $hours
            )
        );
    }

    public static function handle_clear() {
        if (!current_user_can('uapm_manage_settings')) {
            wp_die('Недостатньо прав.');
        }

        check_admin_referer('uapm_logs_clear');

        global $wpdb;

        $wpdb->query('TRUNCATE TABLE ' . self::table());

        self::info('system', 'Журнал системних повідомлень очищено.');

        wp_safe_redirect(admin_url('admin.php?page=uapm-logs&cleared=1'));
        exit;
    }

    public static function handle_export() {
        if (!current_user_can('uapm_manage_settings')) {
            wp_die('Недостатньо прав.');
        }

        check_admin_referer('uapm_logs_export');

        $items = self::get_all(array('limit' => 1000));

        nocache_headers();

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="uapm-logs-' . gmdate('Y-m-d') . '.csv"');

        $output = fopen('php://output', 'w');

        if (!$output) {
            wp_die('Не вдалося створити CSV.');
        }

        fwrite($output, "\xEF\xBB\xBF");

        fputcsv(
            $output,
            array('ID', 'Дата', 'Рівень', 'Канал', 'Повідомлення', 'Користувач', 'Контекст'),
            ';'
        );

        foreach ($items as $item) {
            $row = array(
                $item->id,
                $item->created_at,
                $item->level,
                $item->channel,
                $item->message,
                $item->user_id,
                $item->context,
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

    public static function ajax_get() {
        if (!current_user_can('uapm_manage_settings')) {
            wp_send_json_error(array('message' => 'Недостатньо прав.'), 403);
        }

        check_ajax_referer('uapm_logs_get', 'nonce');

        $items = self::get_all(array(
            'level' => isset($_POST['level']) ? sanitize_key(wp_unslash($_POST['level'])) : '',
            'channel' => isset($_POST['channel']) ? sanitize_key(wp_unslash($_POST['channel'])) : '',
            'search' => isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '',
            'limit' => 100,
        ));

        wp_send_json_success(array(
            'items' => $items,
            'count' => self::count(),
            'errors_24h' => self::get_error_count(24),
        ));
    }

    public static function render_page() {
        if (!current_user_can('uapm_manage_settings')) {
            wp_die('Недостатньо прав.');
        }

        $level = isset($_GET['level'])
            ? sanitize_key(wp_unslash($_GET['level']))
            : '';

        $channel = isset($_GET['channel'])
            ? sanitize_key(wp_unslash($_GET['channel']))
            : '';

        $items = self::get_all(array(
            'level' => $level,
            'channel' => $channel,
            'limit' => 300,
        ));

        echo '<div class="wrap uapm-wrap">';
        echo '<h1>Системні журнали</h1>';

        echo '<div class="uapm-card">';
        echo '<p><strong>Помилки за останні 24 години:</strong> ';
        echo esc_html(self::get_error_count(24));
        echo '</p>';

        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="uapm-logs">';

        echo '<select name="level">';
        echo '<option value="">Усі рівні</option>';

        foreach (array('debug', 'info', 'warning', 'error', 'critical') as $item_level) {
            echo '<option value="' . esc_attr($item_level) . '" ' . selected($level, $item_level, false) . '>';
            echo esc_html($item_level);
            echo '</option>';
        }

        echo '</select> ';

        echo '<input type="text" name="channel" value="' . esc_attr($channel) . '" placeholder="Канал"> ';

        submit_button('Фільтрувати', 'secondary', 'submit', false);

        echo '</form>';
        echo '</div>';

        echo '<div class="uapm-card">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:8px">';
        echo '<input type="hidden" name="action" value="uapm_logs_export">';
        wp_nonce_field('uapm_logs_export');
        submit_button('Експорт CSV', 'secondary', 'submit', false);
        echo '</form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block" onsubmit="return confirm(\'Очистити системний журнал?\')">';
        echo '<input type="hidden" name="action" value="uapm_logs_clear">';
        wp_nonce_field('uapm_logs_clear');
        submit_button('Очистити журнал', 'delete', 'submit', false);
        echo '</form>';
        echo '</div>';

        echo '<div class="uapm-card">';
        echo '<table class="widefat striped">';
        echo '<thead><tr><th>Дата</th><th>Рівень</th><th>Канал</th><th>Повідомлення</th><th>Користувач</th></tr></thead><tbody>';

        if (!$items) {
            echo '<tr><td colspan="5">Записів поки немає.</td></tr>';
        }

        foreach ($items as $item) {
            echo '<tr>';
            echo '<td>' . esc_html($item->created_at) . '</td>';
            echo '<td>' . esc_html(strtoupper($item->level)) . '</td>';
            echo '<td>' . esc_html($item->channel) . '</td>';
            echo '<td>' . esc_html($item->message) . '</td>';
            echo '<td>' . esc_html($item->user_id ? $item->user_id : 'Система') . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div></div>';
    }
}