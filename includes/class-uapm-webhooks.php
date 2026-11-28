<?php
if (!defined('ABSPATH')) {
    exit;
}

class UAPM_Webhooks {

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'uapm_webhooks';
    }

    private static function logs_table() {
        global $wpdb;
        return $wpdb->prefix . 'uapm_webhook_logs';
    }

    public static function init() {
        if (defined('UAPM_DB_VERSION') && get_option('uapm_modules_schema_ver') !== UAPM_DB_VERSION) {
            self::install_schema();
        }
        add_action('admin_post_uapm_create_webhook', array(__CLASS__, 'handle_create'));
        add_action('admin_post_uapm_delete_webhook', array(__CLASS__, 'handle_delete'));

        add_action('uapm_payment_confirmed', array(__CLASS__, 'payment_confirmed'), 10, 2);
        add_action('uapm_payment_revoked', array(__CLASS__, 'payment_revoked'), 10, 2);
        add_action('uapm_license_created', array(__CLASS__, 'license_created'), 10, 2);
        add_action('uapm_license_revoked', array(__CLASS__, 'license_revoked'), 10, 2);
        add_action('uapm_license_site_registered', array(__CLASS__, 'site_registered'), 10, 2);
        add_action('uapm_license_site_deactivated', array(__CLASS__, 'site_deactivated'), 10, 2);
    }

    public static function install_schema() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();

        $sql_webhooks = "CREATE TABLE " . self::table() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(190) NOT NULL,
            endpoint_url varchar(500) NOT NULL,
            secret_hash varchar(255) NOT NULL,
            events_json longtext NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY status (status)
        ) {$charset};";

        $sql_logs = "CREATE TABLE " . self::logs_table() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            webhook_id bigint(20) unsigned NOT NULL,
            event_name varchar(100) NOT NULL,
            response_code int(11) NOT NULL DEFAULT 0,
            response_body text NULL,
            delivery_status varchar(30) NOT NULL DEFAULT 'pending',
            attempts int(11) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY webhook_id (webhook_id),
            KEY delivery_status (delivery_status)
        ) {$charset};";

        dbDelta($sql_webhooks);
        dbDelta($sql_logs);
    }

    public static function create($data) {
        global $wpdb;

        if (!current_user_can('uapm_manage_integrations')) {
            return new WP_Error('forbidden', 'Недостатньо прав.');
        }

        $name = sanitize_text_field($data['name'] ?? '');
        $url = esc_url_raw($data['endpoint_url'] ?? '');

        if (!$name || !wp_http_validate_url($url)) {
            return new WP_Error('invalid_webhook', 'Вкажіть назву та коректну URL-адресу.');
        }

        $host = wp_parse_url($url, PHP_URL_HOST);

        if (!$host || preg_match('/^(localhost|127\.|10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/i', $host)) {
            return new WP_Error('unsafe_endpoint', 'Адреса webhook не може вести на локальну мережу.');
        }

        $secret = wp_generate_password(48, false, false);

        $events = isset($data['events']) && is_array($data['events'])
            ? array_values(array_filter(array_map('sanitize_key', $data['events'])))
            : array();

        $allowed_events = self::allowed_events();
        $events = array_values(array_intersect($events, $allowed_events));

        $now = current_time('mysql');

        $inserted = $wpdb->insert(
            self::table(),
            array(
                'name' => $name,
                'endpoint_url' => $url,
                'secret_hash' => wp_hash_password($secret),
                'events_json' => wp_json_encode($events),
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            )
        );

        if (!$inserted) {
            return new WP_Error('webhook_create_failed', 'Не вдалося створити webhook.');
        }

        $id = (int) $wpdb->insert_id;

        set_transient('uapm_webhook_secret_' . get_current_user_id(), array(
            'id' => $id,
            'secret' => $secret,
        ), 300);

        return array(
            'id' => $id,
            'secret' => $secret,
        );
    }

    private static function allowed_events() {
        return array(
            'payment_confirmed',
            'payment_revoked',
            'license_created',
            'license_revoked',
            'license_site_registered',
            'license_site_deactivated',
        );
    }

    public static function dispatch($event_name, $payload = array()) {
        global $wpdb;

        $event_name = sanitize_key($event_name);

        if (!in_array($event_name, self::allowed_events(), true)) {
            return;
        }

        $webhooks = $wpdb->get_results(
            "SELECT * FROM " . self::table() . " WHERE status = 'active'"
        );

        foreach ($webhooks as $webhook) {
            $events = json_decode($webhook->events_json, true);

            if (!is_array($events) || !in_array($event_name, $events, true)) {
                continue;
            }

            self::deliver($webhook, $event_name, $payload);
        }
    }

    private static function deliver($webhook, $event_name, $payload) {
        global $wpdb;

        $body = array(
            'event' => $event_name,
            'webhook_id' => (int) $webhook->id,
            'created_at' => gmdate('c'),
            'data' => $payload,
        );

        $json = wp_json_encode($body);

        $signature = hash_hmac('sha256', $json, $webhook->secret_hash);

        $response = wp_remote_post($webhook->endpoint_url, array(
            'timeout' => 15,
            'redirection' => 0,
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-UAPM-Event' => $event_name,
                'X-UAPM-Signature' => $signature,
                'User-Agent' => 'UA-Project-Manager-Webhook',
            ),
            'body' => $json,
        ));

        $response_code = is_wp_error($response)
            ? 0
            : (int) wp_remote_retrieve_response_code($response);

        $response_body = is_wp_error($response)
            ? $response->get_error_message()
            : wp_remote_retrieve_body($response);

        $status = (
            $response_code >= 200 &&
            $response_code < 300
        ) ? 'success' : 'failed';

        $wpdb->insert(
            self::logs_table(),
            array(
                'webhook_id' => (int) $webhook->id,
                'event_name' => $event_name,
                'response_code' => $response_code,
                'response_body' => substr(wp_strip_all_tags($response_body), 0, 5000),
                'delivery_status' => $status,
                'attempts' => 1,
                'created_at' => current_time('mysql'),
            )
        );
    }

    public static function payment_confirmed($payment_id, $payment = null) {
        self::dispatch('payment_confirmed', array(
            'payment_id' => absint($payment_id),
            'payment' => is_object($payment) ? get_object_vars($payment) : array(),
        ));
    }

    public static function payment_revoked($payment_id, $payment = null) {
        self::dispatch('payment_revoked', array(
            'payment_id' => absint($payment_id),
            'payment' => is_object($payment) ? get_object_vars($payment) : array(),
        ));
    }

    public static function license_created($license_id, $project_id) {
        self::dispatch('license_created', array(
            'license_id' => absint($license_id),
            'project_id' => absint($project_id),
        ));
    }

    public static function license_revoked($license_id, $project_id) {
        self::dispatch('license_revoked', array(
            'license_id' => absint($license_id),
            'project_id' => absint($project_id),
        ));
    }

    public static function site_registered($site_id, $license_id) {
        self::dispatch('license_site_registered', array(
            'site_id' => absint($site_id),
            'license_id' => absint($license_id),
        ));
    }

    public static function site_deactivated($site_id, $license_id) {
        self::dispatch('license_site_deactivated', array(
            'site_id' => absint($site_id),
            'license_id' => absint($license_id),
        ));
    }

    public static function get_all() {
        global $wpdb;

        return $wpdb->get_results(
            "SELECT * FROM " . self::table() . " ORDER BY id DESC"
        );
    }

    public static function get_logs($webhook_id = 0, $limit = 100) {
        global $wpdb;

        $limit = max(1, min(500, absint($limit)));

        if ($webhook_id) {
            return $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM " . self::logs_table() . "
                    WHERE webhook_id = %d ORDER BY id DESC LIMIT %d",
                    absint($webhook_id),
                    $limit
                )
            );
        }

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . self::logs_table() . "
                ORDER BY id DESC LIMIT %d",
                $limit
            )
        );
    }

    public static function delete($webhook_id) {
        global $wpdb;

        $webhook_id = absint($webhook_id);

        if (!$webhook_id) {
            return new WP_Error('invalid_webhook', 'Некоректний webhook.');
        }

        $wpdb->delete(
            self::logs_table(),
            array('webhook_id' => $webhook_id),
            array('%d')
        );

        $deleted = $wpdb->delete(
            self::table(),
            array('id' => $webhook_id),
            array('%d')
        );

        return $deleted
            ? true
            : new WP_Error('webhook_delete_failed', 'Не вдалося видалити webhook.');
    }

    public static function handle_create() {
        if (!current_user_can('uapm_manage_integrations')) {
            wp_die('Недостатньо прав.');
        }

        check_admin_referer('uapm_create_webhook');

        $result = self::create(wp_unslash($_POST));

        $url = admin_url('admin.php?page=uapm-webhooks');

        if (is_wp_error($result)) {
            $url = add_query_arg(
                'uapm_error',
                rawurlencode($result->get_error_message()),
                $url
            );
        } else {
            $url = add_query_arg('uapm_success', 'created', $url);
        }

        wp_safe_redirect($url);
        exit;
    }

    public static function handle_delete() {
        if (!current_user_can('uapm_manage_integrations')) {
            wp_die('Недостатньо прав.');
        }

        check_admin_referer('uapm_delete_webhook');

        $result = self::delete(absint($_POST['webhook_id'] ?? 0));

        $url = admin_url('admin.php?page=uapm-webhooks');

        if (is_wp_error($result)) {
            $url = add_query_arg(
                'uapm_error',
                rawurlencode($result->get_error_message()),
                $url
            );
        } else {
            $url = add_query_arg('uapm_success', 'deleted', $url);
        }

        wp_safe_redirect($url);
        exit;
    }

    public static function render_page() {
        if (!current_user_can('uapm_manage_integrations')) {
            wp_die('Недостатньо прав.');
        }

        $webhooks = self::get_all();
        $new_secret = get_transient('uapm_webhook_secret_' . get_current_user_id());

        if ($new_secret) {
            delete_transient('uapm_webhook_secret_' . get_current_user_id());
        }

        echo '<div class="wrap uapm-wrap">';
        echo '<h1>Webhooks</h1>';

        if (!empty($_GET['uapm_error'])) {
            echo '<div class="notice notice-error"><p>' .
                esc_html(wp_unslash($_GET['uapm_error'])) .
                '</p></div>';
        }

        if ($new_secret) {
            echo '<div class="notice notice-success"><p><strong>Webhook створено. Збережіть секрет зараз:</strong></p>';
            echo '<code>' . esc_html($new_secret['secret']) . '</code>';
            echo '<p>Повторно секрет не відображатиметься.</p></div>';
        }

        echo '<div class="uapm-card">';
        echo '<h2>Додати webhook</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="uapm_create_webhook">';
        wp_nonce_field('uapm_create_webhook');

        echo '<p><input type="text" name="name" class="regular-text" placeholder="Назва webhook" required></p>';
        echo '<p><input type="url" name="endpoint_url" class="large-text" placeholder="https://example.com/webhook" required></p>';
        echo '<p><strong>Події:</strong></p>';

        $labels = array(
            'payment_confirmed' => 'Платіж підтверджено',
            'payment_revoked' => 'Платіж скасовано',
            'license_created' => 'Створено ліцензію',
            'license_revoked' => 'Ліцензію деактивовано',
            'license_site_registered' => 'Сайт зареєстровано',
            'license_site_deactivated' => 'Сайт деактивовано',
        );

        foreach ($labels as $event => $label) {
            echo '<label style="display:block;margin:6px 0">';
            echo '<input type="checkbox" name="events[]" value="' . esc_attr($event) . '"> ';
            echo esc_html($label);
            echo '</label>';
        }

        submit_button('Створити webhook');
        echo '</form>';
        echo '</div>';

        echo '<div class="uapm-card">';
        echo '<h2>Активні webhook</h2>';
        echo '<table class="widefat striped">';
        echo '<thead><tr><th>Назва</th><th>Адреса</th><th>Події</th><th>Статус</th><th>Дія</th></tr></thead><tbody>';

        if (!$webhooks) {
            echo '<tr><td colspan="5">Webhook ще не створено.</td></tr>';
        }

        foreach ($webhooks as $webhook) {
            $events = json_decode($webhook->events_json, true);
            $events = is_array($events) ? $events : array();

            echo '<tr>';
            echo '<td>' . esc_html($webhook->name) . '</td>';
            echo '<td>' . esc_html($webhook->endpoint_url) . '</td>';
            echo '<td>' . esc_html(implode(', ', $events)) . '</td>';
            echo '<td>' . esc_html($webhook->status) . '</td>';
            echo '<td>';

            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Видалити webhook та журнал його подій?\')">';
            echo '<input type="hidden" name="action" value="uapm_delete_webhook">';
            echo '<input type="hidden" name="webhook_id" value="' . esc_attr($webhook->id) . '">';
            wp_nonce_field('uapm_delete_webhook');
            echo '<button class="button button-link-delete">Видалити</button>';
            echo '</form>';

            echo '</td></tr>';
        }

        echo '</tbody></table>';
        echo '</div>';

        echo '<div class="uapm-card">';
        echo '<h2>Останні доставки</h2>';
        echo '<table class="widefat striped">';
        echo '<thead><tr><th>Webhook ID</th><th>Подія</th><th>HTTP</th><th>Статус</th><th>Дата</th></tr></thead><tbody>';

        $logs = self::get_logs(0, 100);

        if (!$logs) {
            echo '<tr><td colspan="5">Записів поки немає.</td></tr>';
        }

        foreach ($logs as $log) {
            echo '<tr>';
            echo '<td>' . esc_html($log->webhook_id) . '</td>';
            echo '<td>' . esc_html($log->event_name) . '</td>';
            echo '<td>' . esc_html($log->response_code) . '</td>';
            echo '<td>' . esc_html($log->delivery_status) . '</td>';
            echo '<td>' . esc_html($log->created_at) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div></div>';
    }
}