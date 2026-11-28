<?php
if (!defined('ABSPATH')) {
    exit;
}

class UAPM_Activity_Monitor {

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'uapm_activity_monitor';
    }

    public static function init() {
        if (defined('UAPM_DB_VERSION') && get_option('uapm_modules_schema_ver') !== UAPM_DB_VERSION) {
            self::install_schema();
        }
        add_action('wp_login', array(__CLASS__, 'track_login'), 10, 2);
        add_action('wp_logout', array(__CLASS__, 'track_logout'));
        add_action('user_register', array(__CLASS__, 'track_registration'));
        add_action('profile_update', array(__CLASS__, 'track_profile_update'), 10, 2);
        add_action('activated_plugin', array(__CLASS__, 'track_plugin_activation'));
        add_action('deactivated_plugin', array(__CLASS__, 'track_plugin_deactivation'));
        add_action('upgrader_process_complete', array(__CLASS__, 'track_upgrade'), 10, 2);

        add_action('admin_post_uapm_clear_activity_monitor', array(__CLASS__, 'handle_clear'));
    }

    public static function install_schema() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE " . self::table() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            event_type varchar(100) NOT NULL,
            event_title varchar(255) NOT NULL,
            event_details longtext NULL,
            ip_hash varchar(64) NULL,
            user_agent varchar(255) NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY event_type (event_type),
            KEY created_at (created_at)
        ) {$charset};";

        dbDelta($sql);
    }

    public static function log($event_type, $title, $details = array(), $user_id = 0) {
        global $wpdb;

        $event_type = sanitize_key($event_type);
        $title = sanitize_text_field($title);

        if (!$event_type || !$title) {
            return false;
        }

        $ip = self::get_client_ip();
        $ip_hash = $ip ? hash_hmac('sha256', $ip, wp_salt('auth')) : '';

        $user_agent = isset($_SERVER['HTTP_USER_AGENT'])
            ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']))
            : '';

        $inserted = $wpdb->insert(
            self::table(),
            array(
                'user_id' => absint($user_id ?: get_current_user_id()),
                'event_type' => $event_type,
                'event_title' => $title,
                'event_details' => wp_json_encode($details),
                'ip_hash' => $ip_hash,
                'user_agent' => substr($user_agent, 0, 255),
                'created_at' => current_time('mysql'),
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        return $inserted ? (int) $wpdb->insert_id : false;
    }

    private static function get_client_ip() {
        if (empty($_SERVER['REMOTE_ADDR'])) {
            return '';
        }

        return sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
    }

    public static function track_login($user_login, $user) {
        self::log('login', 'Вхід користувача', array(
            'login' => sanitize_user($user_login),
        ), $user->ID);
    }

    public static function track_logout() {
        self::log('logout', 'Вихід користувача');
    }

    public static function track_registration($user_id) {
        self::log('registration', 'Зареєстровано користувача', array(), $user_id);
    }

    public static function track_profile_update($user_id, $old_user_data) {
        self::log('profile_update', 'Оновлено профіль користувача', array(), $user_id);
    }

    public static function track_plugin_activation($plugin) {
        self::log('plugin_activation', 'Активовано плагін', array(
            'plugin' => sanitize_text_field($plugin),
        ));
    }

    public static function track_plugin_deactivation($plugin) {
        self::log('plugin_deactivation', 'Деактивовано плагін', array(
            'plugin' => sanitize_text_field($plugin),
        ));
    }

    public static function track_upgrade($upgrader, $options) {
        if (!is_array($options)) {
            return;
        }

        self::log('upgrade', 'Оновлення WordPress', array(
            'type' => sanitize_key($options['type'] ?? ''),
            'action' => sanitize_key($options['action'] ?? ''),
            'plugins' => array_map(
                'sanitize_text_field',
                (array) ($options['plugins'] ?? array())
            ),
            'themes' => array_map(
                'sanitize_text_field',
                (array) ($options['themes'] ?? array())
            ),
        ));
    }

    public static function get_all($args = array()) {
        global $wpdb;

        $defaults = array(
            'event_type' => '',
            'user_id' => 0,
            'date_from' => '',
            'date_to' => '',
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

        if ($args['date_from'] && preg_match('/^\d{4}-\d{2}-\d{2}$/', $args['date_from'])) {
            $where[] = 'created_at >= %s';
            $values[] = $args['date_from'] . ' 00:00:00';
        }

        if ($args['date_to'] && preg_match('/^\d{4}-\d{2}-\d{2}$/', $args['date_to'])) {
            $where[] = 'created_at <= %s';
            $values[] = $args['date_to'] . ' 23:59:59';
        }

        $limit = max(1, min(500, absint($args['limit'])));
        $offset = absint($args['offset']);

        $sql = "SELECT * FROM " . self::table() . "
            WHERE " . implode(' AND ', $where) . "
            ORDER BY id DESC LIMIT %d OFFSET %d";

        $values[] = $limit;
        $values[] = $offset;

        return $wpdb->get_results($wpdb->prepare($sql, $values));
    }

    public static function count($event_type = '') {
        global $wpdb;

        if ($event_type) {
            return (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM " . self::table() . " WHERE event_type = %s",
                    sanitize_key($event_type)
                )
            );
        }

        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM " . self::table()
        );
    }

    public static function delete_old($days = 180) {
        global $wpdb;

        $days = max(1, min(3650, absint($days)));

        return $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM " . self::table() . "
                WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            )
        );
    }

    public static function handle_clear() {
        if (!current_user_can('uapm_manage_settings')) {
            wp_die('Недостатньо прав.');
        }

        check_admin_referer('uapm_clear_activity_monitor');

        global $wpdb;

        $wpdb->query('TRUNCATE TABLE ' . self::table());

        wp_safe_redirect(admin_url('admin.php?page=uapm-activity-monitor&cleared=1'));
        exit;
    }

    public static function render_page() {
        if (!current_user_can('uapm_manage_settings')) {
            wp_die('Недостатньо прав.');
        }

        $event_type = sanitize_key(wp_unslash($_GET['event_type'] ?? ''));
        $events = self::get_all(array(
            'event_type' => $event_type,
            'limit' => 200,
        ));

        echo '<div class="wrap uapm-wrap">';
        echo '<h1>Монітор активності</h1>';

        echo '<div class="uapm-card">';
        echo '<p>Загальна кількість подій: <strong>' . esc_html(self::count()) . '</strong></p>';

        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="uapm-activity-monitor">';
        echo '<select name="event_type">';
        echo '<option value="">Усі події</option>';

        $types = array(
            'login' => 'Вхід',
            'logout' => 'Вихід',
            'registration' => 'Реєстрація',
            'profile_update' => 'Профіль',
            'plugin_activation' => 'Активація плагіна',
            'plugin_deactivation' => 'Деактивація плагіна',
            'upgrade' => 'Оновлення',
        );

        foreach ($types as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' .
                selected($event_type, $value, false) . '>';
            echo esc_html($label);
            echo '</option>';
        }

        echo '</select> ';
        submit_button('Фільтрувати', 'secondary', '', false);
        echo '</form>';
        echo '</div>';

        echo '<div class="uapm-card">';
        echo '<table class="widefat striped">';
        echo '<thead><tr><th>Дата</th><th>Користувач</th><th>Подія</th><th>Деталі</th></tr></thead><tbody>';

        if (!$events) {
            echo '<tr><td colspan="4">Подій не знайдено.</td></tr>';
        }

        foreach ($events as $event) {
            $user = $event->user_id ? get_userdata($event->user_id) : false;
            $details = json_decode($event->event_details, true);

            echo '<tr>';
            echo '<td>' . esc_html($event->created_at) . '</td>';
            echo '<td>' . esc_html($user ? $user->user_login : 'Система') . '</td>';
            echo '<td>' . esc_html($event->event_title) . '</td>';
            echo '<td><code>' . esc_html(wp_json_encode($details, JSON_UNESCAPED_UNICODE)) . '</code></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';

        echo '<div class="uapm-card">';
        echo '<h2>Очищення журналу</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Очистити весь журнал активності?\')">';
        echo '<input type="hidden" name="action" value="uapm_clear_activity_monitor">';
        wp_nonce_field('uapm_clear_activity_monitor');
        submit_button('Очистити журнал', 'delete');
        echo '</form>';
        echo '</div>';

        echo '</div>';
    }
}