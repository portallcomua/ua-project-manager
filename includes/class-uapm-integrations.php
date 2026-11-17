<?php
/**
 * UA Project Manager
 * Secure external integrations manager.
 *
 * @package UA_Project_Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

final class UAPM_Integrations {

    public static function init() {
        add_action('admin_post_uapm_save_integration', array(__CLASS__, 'handle_save'));
        add_action('admin_post_uapm_delete_integration', array(__CLASS__, 'handle_delete'));
        add_action('admin_post_uapm_toggle_integration', array(__CLASS__, 'handle_toggle'));
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    public static function install_schema() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $table = $wpdb->prefix . 'uapm_integrations';
        $events = $wpdb->prefix . 'uapm_integration_events';

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            integration_key varchar(100) NOT NULL,
            name varchar(255) NOT NULL,
            description text NULL,
            integration_type varchar(50) NOT NULL DEFAULT 'external',
            status varchar(30) NOT NULL DEFAULT 'inactive',
            access_token_hash varchar(64) NOT NULL DEFAULT '',
            secret_key longtext NULL,
            settings longtext NULL,
            last_seen datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY integration_key (integration_key),
            KEY status (status),
            KEY integration_type (integration_type)
        ) {$charset};";

        dbDelta($sql);

        $events_sql = "CREATE TABLE {$events} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            integration_id bigint(20) unsigned NOT NULL,
            event_type varchar(100) NOT NULL,
            payload longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY integration_id (integration_id),
            KEY event_type (event_type),
            KEY created_at (created_at)
        ) {$charset};";

        dbDelta($events_sql);
    }

    private static function can_manage() {
        return current_user_can('uapm_manage_integrations') ||
            current_user_can('manage_options');
    }

    public static function register_routes() {
        register_rest_route('uapm/v1', '/integrations/heartbeat', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'api_heartbeat'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('uapm/v1', '/integrations/event', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'api_event'),
            'permission_callback' => '__return_true',
        ));
    }

    public static function get_all() {
        global $wpdb;

        $table = $wpdb->prefix . 'uapm_integrations';

        return $wpdb->get_results(
            "SELECT id, integration_key, name, description,
                    integration_type, status, last_seen,
                    created_at, updated_at
             FROM {$table}
             ORDER BY name ASC"
        );
    }

    public static function get($id) {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}uapm_integrations
                 WHERE id = %d LIMIT 1",
                absint($id)
            )
        );
    }

    public static function get_by_key($key) {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}uapm_integrations
                 WHERE integration_key = %s LIMIT 1",
                sanitize_key($key)
            )
        );
    }

    public static function create($data) {
        global $wpdb;

        if (!self::can_manage()) {
            return new WP_Error('uapm_forbidden', 'Недостатньо прав.');
        }

        $key = isset($data['integration_key'])
            ? sanitize_key($data['integration_key'])
            : '';

        $name = isset($data['name'])
            ? sanitize_text_field($data['name'])
            : '';

        if (!$key || !$name) {
            return new WP_Error(
                'uapm_invalid_integration',
                'Вкажіть ключ та назву інтеграції.'
            );
        }

        if (self::get_by_key($key)) {
            return new WP_Error(
                'uapm_duplicate_integration',
                'Інтеграція з таким ключем вже існує.'
            );
        }

        $token = bin2hex(random_bytes(32));

        $result = $wpdb->insert(
            $wpdb->prefix . 'uapm_integrations',
            array(
                'integration_key' => $key,
                'name' => $name,
                'description' => isset($data['description'])
                    ? sanitize_textarea_field($data['description'])
                    : '',
                'integration_type' => isset($data['integration_type'])
                    ? sanitize_key($data['integration_type'])
                    : 'external',
                'status' => 'active',
                'access_token_hash' => hash('sha256', $token),
                'secret_key' => '',
                'settings' => '{}',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            )
        );

        if (!$result) {
            return new WP_Error(
                'uapm_database_error',
                'Не вдалося створити інтеграцію.'
            );
        }

        $id = (int) $wpdb->insert_id;

        self::log_event($id, 'created', array());

        return array(
            'id' => $id,
            'integration_key' => $key,
            'access_token' => $token,
        );
    }

    public static function update($id, $data) {
        global $wpdb;

        if (!self::can_manage()) {
            return new WP_Error('uapm_forbidden', 'Недостатньо прав.');
        }

        $id = absint($id);
        $integration = self::get($id);

        if (!$integration) {
            return new WP_Error('uapm_not_found', 'Інтеграцію не знайдено.');
        }

        $statuses = array('active', 'inactive', 'disabled');

        $status = isset($data['status'])
            ? sanitize_key($data['status'])
            : $integration->status;

        if (!in_array($status, $statuses, true)) {
            return new WP_Error('uapm_invalid_status', 'Некоректний статус.');
        }

        $result = $wpdb->update(
            $wpdb->prefix . 'uapm_integrations',
            array(
                'name' => isset($data['name'])
                    ? sanitize_text_field($data['name'])
                    : $integration->name,
                'description' => isset($data['description'])
                    ? sanitize_textarea_field($data['description'])
                    : $integration->description,
                'status' => $status,
                'updated_at' => current_time('mysql'),
            ),
            array('id' => $id)
        );

        return false === $result
            ? new WP_Error('uapm_database_error', 'Не вдалося оновити інтеграцію.')
            : true;
    }

    public static function delete($id) {
        global $wpdb;

        if (!self::can_manage()) {
            return false;
        }

        $id = absint($id);

        if (!$id) {
            return false;
        }

        self::log_event($id, 'deleted', array());

        $wpdb->delete(
            $wpdb->prefix . 'uapm_integration_events',
            array('integration_id' => $id),
            array('%d')
        );

        return false !== $wpdb->delete(
            $wpdb->prefix . 'uapm_integrations',
            array('id' => $id),
            array('%d')
        );
    }

    public static function authenticate($request) {
        global $wpdb;

        $token = $request->get_header('x-uapm-token');

        if (!$token || !is_string($token) || strlen($token) < 32 || strlen($token) > 128) {
            return new WP_Error(
                'uapm_missing_token',
                'Не передано дійсний токен інтеграції.',
                array('status' => 401)
            );
        }

        $hash = hash('sha256', $token);

        $integration = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}uapm_integrations
                 WHERE access_token_hash = %s
                 AND status = 'active'
                 LIMIT 1",
                $hash
            )
        );

        if (!$integration) {
            return new WP_Error(
                'uapm_invalid_token',
                'Токен недійсний або інтеграцію вимкнено.',
                array('status' => 403)
            );
        }

        $rate_key = 'uapm_int_rate_' . absint($integration->id);
        $requests = (int) get_transient($rate_key);

        if ($requests >= 120) {
            return new WP_Error(
                'uapm_rate_limit',
                'Перевищено ліміт запитів.',
                array('status' => 429)
            );
        }

        set_transient($rate_key, $requests + 1, MINUTE_IN_SECONDS);

        return $integration;
    }

    public static function api_heartbeat($request) {
        global $wpdb;

        $integration = self::authenticate($request);

        if (is_wp_error($integration)) {
            return $integration;
        }

        $wpdb->update(
            $wpdb->prefix . 'uapm_integrations',
            array(
                'last_seen' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ),
            array('id' => absint($integration->id))
        );

        self::log_event($integration->id, 'heartbeat');

        return rest_ensure_response(array(
            'success' => true,
            'integration_id' => absint($integration->id),
            'server_time' => current_time('mysql'),
        ));
    }

    public static function api_event($request) {
        $integration = self::authenticate($request);

        if (is_wp_error($integration)) {
            return $integration;
        }

        $event = sanitize_key($request->get_param('event'));

        $allowed = array(
            'installed',
            'activated',
            'updated',
            'deactivated',
            'uninstalled',
            'error',
            'custom',
        );

        if (!in_array($event, $allowed, true)) {
            return new WP_Error(
                'uapm_invalid_event',
                'Невідомий тип події.',
                array('status' => 400)
            );
        }

        $payload = $request->get_param('payload');

        if (!is_array($payload)) {
            $payload = array();
        }

        $payload = self::sanitize_payload($payload);

        $saved = self::log_event(
            $integration->id,
            $event,
            $payload
        );

        if (!$saved) {
            return new WP_Error(
                'uapm_event_save_failed',
                'Не вдалося зберегти подію.',
                array('status' => 500)
            );
        }

        do_action(
            'uapm_integration_event',
            absint($integration->id),
            $event,
            $payload
        );

        return rest_ensure_response(array(
            'success' => true,
            'event' => $event,
            'recorded' => true,
        ));
    }

    private static function sanitize_payload($payload, $depth = 0) {
        if ($depth > 5 || !is_array($payload)) {
            return array();
        }

        $clean = array();

        foreach ($payload as $key => $value) {
            if (count($clean) >= 100) {
                break;
            }

            $key = sanitize_key($key);

            if (is_array($value)) {
                $clean[$key] = self::sanitize_payload($value, $depth + 1);
            } elseif (is_scalar($value)) {
                $clean[$key] = sanitize_text_field((string) $value);
            }
        }

        return $clean;
    }

    public static function log_event($integration_id, $event, $payload = array()) {
        global $wpdb;

        $result = $wpdb->insert(
            $wpdb->prefix . 'uapm_integration_events',
            array(
                'integration_id' => absint($integration_id),
                'event_type' => sanitize_key($event),
                'payload' => wp_json_encode(self::sanitize_payload($payload)),
                'created_at' => current_time('mysql'),
            ),
            array('%d', '%s', '%s', '%s')
        );

        return false !== $result;
    }

    public static function render_page() {
        if (!self::can_manage()) {
            wp_die('Недостатньо прав.');
        }

        $integrations = self::get_all();
        ?>
        <div class="wrap">
            <h1>Інтеграції UPM</h1>

            <h2>Додати інтеграцію</h2>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="uapm_save_integration">
                <?php wp_nonce_field('uapm_save_integration'); ?>

                <table class="form-table">
                    <tr>
                        <th>Ключ інтеграції</th>
                        <td><input type="text" name="integration_key" required></td>
                    </tr>
                    <tr>
                        <th>Назва</th>
                        <td><input type="text" name="name" required></td>
                    </tr>
                    <tr>
                        <th>Опис</th>
                        <td><textarea name="description" rows="3"></textarea></td>
                    </tr>
                    <tr>
                        <th>Тип</th>
                        <td>
                            <select name="integration_type">
                                <option value="external">Зовнішня</option>
                                <option value="wordpress">WordPress</option>
                                <option value="payment">Платіжна</option>
                                <option value="analytics">Аналітика</option>
                                <option value="communication">Комунікації</option>
                            </select>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Створити інтеграцію'); ?>
            </form>

            <hr>

            <h2>Підключені інтеграції</h2>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Назва</th>
                        <th>Ключ</th>
                        <th>Тип</th>
                        <th>Статус</th>
                        <th>Останній зв'язок</th>
                        <th>Дії</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($integrations) : ?>
                        <?php foreach ($integrations as $item) : ?>
                            <tr>
                                <td><?php echo esc_html($item->id); ?></td>
                                <td><?php echo esc_html($item->name); ?></td>
                                <td><code><?php echo esc_html($item->integration_key); ?></code></td>
                                <td><?php echo esc_html($item->integration_type); ?></td>
                                <td><?php echo esc_html($item->status); ?></td>
                                <td><?php echo esc_html($item->last_seen ? $item->last_seen : 'Ще не підключалась'); ?></td>
                                <td>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block">
                                        <input type="hidden" name="action" value="uapm_toggle_integration">
                                        <input type="hidden" name="integration_id" value="<?php echo esc_attr($item->id); ?>">
                                        <input type="hidden" name="new_status" value="<?php echo $item->status === 'active' ? 'inactive' : 'active'; ?>">
                                        <?php wp_nonce_field('uapm_toggle_integration_' . $item->id); ?>
                                        <button class="button button-small" type="submit">
                                            <?php echo $item->status === 'active' ? 'Вимкнути' : 'Увімкнути'; ?>
                                        </button>
                                    </form>

                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block">
                                        <input type="hidden" name="action" value="uapm_delete_integration">
                                        <input type="hidden" name="integration_id" value="<?php echo esc_attr($item->id); ?>">
                                        <?php wp_nonce_field('uapm_delete_integration_' . $item->id); ?>
                                        <button class="button button-small" type="submit" onclick="return confirm('Видалити інтеграцію?');">
                                            Видалити
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr><td colspan="7">Інтеграцій поки немає.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <p class="description">
                Токен інтеграції показується лише під час її створення. Зберігайте його в безпечному місці.
            </p>
        </div>
        <?php
    }

    public static function handle_save() {
        if (!self::can_manage()) {
            wp_die('Недостатньо прав.');
        }

        check_admin_referer('uapm_save_integration');

        $result = self::create(array(
            'integration_key' => isset($_POST['integration_key'])
                ? wp_unslash($_POST['integration_key'])
                : '',
            'name' => isset($_POST['name'])
                ? wp_unslash($_POST['name'])
                : '',
            'description' => isset($_POST['description'])
                ? wp_unslash($_POST['description'])
                : '',
            'integration_type' => isset($_POST['integration_type'])
                ? wp_unslash($_POST['integration_type'])
                : 'external',
        ));

        if (is_wp_error($result)) {
            set_transient(
                'uapm_integration_admin_message_' . get_current_user_id(),
                $result->get_error_message(),
                60
            );
        } else {
            set_transient(
                'uapm_integration_new_token_' . get_current_user_id(),
                $result,
                300
            );
        }

        wp_safe_redirect(
            add_query_arg(
                array('page' => 'uapm-integrations'),
                admin_url('admin.php')
            )
        );
        exit;
    }

    public static function handle_toggle() {
        if (!self::can_manage()) {
            wp_die('Недостатньо прав.');
        }

        $id = isset($_POST['integration_id'])
            ? absint($_POST['integration_id'])
            : 0;

        check_admin_referer('uapm_toggle_integration_' . $id);

        self::update($id, array(
            'status' => isset($_POST['new_status'])
                ? sanitize_key($_POST['new_status'])
                : 'inactive',
        ));

        wp_safe_redirect(
            add_query_arg('page', 'uapm-integrations', admin_url('admin.php'))
        );
        exit;
    }

    public static function handle_delete() {
        if (!self::can_manage()) {
            wp_die('Недостатньо прав.');
        }

        $id = isset($_POST['integration_id'])
            ? absint($_POST['integration_id'])
            : 0;

        check_admin_referer('uapm_delete_integration_' . $id);

        self::delete($id);

        wp_safe_redirect(
            add_query_arg('page', 'uapm-integrations', admin_url('admin.php'))
        );
        exit;
    }
}

UAPM_Integrations::install_schema();
UAPM_Integrations::init();
