<?php
/**
 * UA Project Manager — notifications.
 *
 * @package UA_Project_Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

final class UAPM_Notifications {

    public static function init() {
        if (defined('UAPM_DB_VERSION') && get_option('uapm_modules_schema_ver') !== UAPM_DB_VERSION) {
            self::install_schema();
        }
        add_action('uapm_pro_access_changed', array(__CLASS__, 'handle_pro_change'), 10, 4);
        add_action('uapm_payment_received', array(__CLASS__, 'handle_payment_received'), 10, 2);
        add_action('uapm_integration_event', array(__CLASS__, 'handle_integration_event'), 10, 3);

        add_action('admin_post_uapm_mark_notification_read', array(__CLASS__, 'handle_mark_read'));
        add_action('admin_post_uapm_delete_notification', array(__CLASS__, 'handle_delete'));
    }

    public static function install_schema() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = $wpdb->prefix . 'uapm_notifications';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            notification_type varchar(50) NOT NULL DEFAULT 'info',
            title varchar(255) NOT NULL,
            message text NOT NULL,
            related_type varchar(50) NOT NULL DEFAULT '',
            related_id bigint(20) unsigned NOT NULL DEFAULT 0,
            is_read tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY is_read (is_read),
            KEY created_at (created_at)
        ) {$charset};";

        dbDelta($sql);
    }

    public static function create($data) {
        global $wpdb;

        $title = isset($data['title']) ? sanitize_text_field($data['title']) : '';
        $message = isset($data['message']) ? sanitize_textarea_field($data['message']) : '';

        if (!$title || !$message) {
            return false;
        }

        $user_id = isset($data['user_id']) ? absint($data['user_id']) : 0;

        $inserted = $wpdb->insert(
            $wpdb->prefix . 'uapm_notifications',
            array(
                'user_id' => $user_id,
                'notification_type' => isset($data['type']) ? sanitize_key($data['type']) : 'info',
                'title' => $title,
                'message' => $message,
                'related_type' => isset($data['related_type']) ? sanitize_key($data['related_type']) : '',
                'related_id' => isset($data['related_id']) ? absint($data['related_id']) : 0,
                'is_read' => 0,
                'created_at' => current_time('mysql'),
            )
        );

        if (!$inserted) {
            return false;
        }

        $id = absint($wpdb->insert_id);

        do_action('uapm_notification_created', $id, $data);

        if ($user_id) {
            self::send_email($user_id, $title, $message);
        }

        return $id;
    }

    public static function get_all($user_id = 0, $limit = 50) {
        global $wpdb;

        $table = $wpdb->prefix . 'uapm_notifications';
        $limit = max(1, min(200, absint($limit)));

        if ($user_id) {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC LIMIT %d",
                absint($user_id),
                $limit
            ));
        }

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d",
            $limit
        ));
    }

    public static function get_unread_count($user_id = 0) {
        global $wpdb;

        $table = $wpdb->prefix . 'uapm_notifications';

        if ($user_id) {
            return absint($wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND is_read = 0",
                absint($user_id)
            )));
        }

        return absint($wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE is_read = 0"));
    }

    public static function mark_read($id, $user_id = 0) {
        global $wpdb;

        $where = array('id' => absint($id));
        if ($user_id) {
            $where['user_id'] = absint($user_id);
        }

        return $wpdb->update($wpdb->prefix . 'uapm_notifications', array('is_read' => 1), $where);
    }

    public static function delete($id, $user_id = 0) {
        global $wpdb;

        $where = array('id' => absint($id));
        if ($user_id) {
            $where['user_id'] = absint($user_id);
        }

        return $wpdb->delete($wpdb->prefix . 'uapm_notifications', $where);
    }

    public static function send_email($user_id, $title, $message) {
        $user = get_user_by('id', absint($user_id));

        if (!$user || !is_email($user->user_email) || !get_option('uapm_notifications_enabled', 1)) {
            return false;
        }

        return wp_mail(
            $user->user_email,
            '[UA Project Manager] ' . sanitize_text_field($title),
            wpautop(esc_html($message)),
            array('Content-Type: text/html; charset=UTF-8')
        );
    }

    public static function notify_administrators($title, $message, $related_type = '', $related_id = 0) {
        $users = get_users(array('role' => 'administrator', 'fields' => array('ID')));

        foreach ($users as $user) {
            self::create(array(
                'user_id' => absint($user->ID),
                'title' => $title,
                'message' => $message,
                'type' => 'info',
                'related_type' => $related_type,
                'related_id' => $related_id,
            ));
        }
    }

    public static function handle_pro_change($target, $type, $status, $payment_id = 0) {
        self::notify_administrators(
            'Зміна статусу PRO',
            sprintf('Для %s змінено статус PRO: %s. Платіж #%d.', sanitize_text_field($target), sanitize_text_field($status), absint($payment_id)),
            'payment',
            $payment_id
        );
    }

    public static function handle_payment_received($payment_id, $amount) {
        self::notify_administrators(
            'Надходження платежу',
            sprintf('Зафіксовано надходження %.2f. Платіж #%d очікує перевірки.', (float) $amount, absint($payment_id)),
            'payment',
            $payment_id
        );
    }

    public static function handle_integration_event($integration_id, $event, $payload) {
        if (!in_array($event, array('error', 'uninstalled'), true)) {
            return;
        }

        self::notify_administrators(
            'Подія інтеграції UPM',
            sprintf('Інтеграція #%d повідомила про подію: %s.', absint($integration_id), sanitize_text_field($event)),
            'integration',
            $integration_id
        );
    }

    public static function handle_mark_read() {
        if (!is_user_logged_in()) {
            wp_die('Потрібна авторизація.');
        }

        $id = absint($_POST['notification_id'] ?? 0);
        check_admin_referer('uapm_mark_notification_' . $id);

        self::mark_read($id, get_current_user_id());

        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }

    public static function handle_delete() {
        if (!is_user_logged_in()) {
            wp_die('Потрібна авторизація.');
        }

        $id = absint($_POST['notification_id'] ?? 0);
        check_admin_referer('uapm_delete_notification_' . $id);

        self::delete($id, get_current_user_id());

        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }

    public static function render_page() {
        if (!current_user_can('uapm_manage_settings')) {
            wp_die('Недостатньо прав.');
        }

        $notifications = self::get_all(0, 100);

        echo '<div class="wrap"><h1>Сповіщення UPM</h1>';
        echo '<p>Непрочитаних: <strong>' . esc_html(self::get_unread_count()) . '</strong></p>';
        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Тип</th><th>Заголовок</th><th>Повідомлення</th><th>Користувач</th><th>Дата</th><th>Статус</th></tr></thead><tbody>';

        foreach ($notifications as $item) {
            echo '<tr>';
            echo '<td>' . absint($item->id) . '</td>';
            echo '<td>' . esc_html($item->notification_type) . '</td>';
            echo '<td>' . esc_html($item->title) . '</td>';
            echo '<td>' . esc_html($item->message) . '</td>';
            echo '<td>' . absint($item->user_id) . '</td>';
            echo '<td>' . esc_html($item->created_at) . '</td>';
            echo '<td>' . ($item->is_read ? 'Прочитано' : 'Нове') . '</td>';
            echo '</tr>';
        }

        if (!$notifications) {
            echo '<tr><td colspan="7">Сповіщень поки немає.</td></tr>';
        }

        echo '</tbody></table></div>';
    }
}

UAPM_Notifications::init();
