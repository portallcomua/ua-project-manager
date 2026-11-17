<?php
if (!defined('ABSPATH')) {
    exit;
}

class UAPM_Integrations {

    public static function init() {
        add_action('admin_post_uapm_save_integration', array(__CLASS__, 'handle_save'));
        add_action('admin_post_uapm_delete_integration', array(__CLASS__, 'handle_delete'));

        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    public static function install_schema() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = $wpdb->prefix . 'uapm_integrations';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            integration_key varchar(100) NOT NULL,
            name varchar(255) NOT NULL,
            description text NULL,
            integration_type varchar(50) NOT NULL DEFAULT 'external',
            status varchar(30) NOT NULL DEFAULT 'inactive',
            access_token_hash varchar(64) NOT NULL DEFAULT '',
            secret_key varchar(255) NOT NULL DEFAULT '',
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
    }

    public static function register_routes() {
        register_rest_route('uapm/v1', '/integrations/register', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'api_register'),
            'permission_callback' => '__return_true'
        ));

        register_rest_route('uapm/v1', '/integrations/heartbeat', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'api_heartbeat'),
            'permission_callback' => '__return_true'
        ));

        register_rest_route('uapm/v1', '/integrations/event', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'api_event'),
            'permission_callback' => '__return_true'
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

        $table = $wpdb->prefix . 'uapm_integrations';

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d LIMIT 1",
                absint($id)
            )
        );
    }

    public static function create($data) {
        global $wpdb;

        $table = $wpdb->prefix . 'uapm_integrations';

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

        $token = wp_generate_password(48, false, false);
        $secret = wp_generate_password(64, false, false);

        $result = $wpdb->insert(
            $table,
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
                'secret_key' => self::encrypt_secret($secret),
                'settings' => '{}',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            )
        );

        if (!$result) {
            return new WP_Error(
                'uapm_integration_create_failed',
                'Не вдалося створити інтеграцію. Перевірте, чи не існує такий ключ.'
            );
        }

        $integration_id = absint($wpdb->insert_id);

        self::log_event($integration_id, 'created', array(
            'integration_key' => $key
        ));

        return array(
            'id' => $integration_id,
            'integration_key' => $key,
            'access_token' => $token,
            'secret_key' => $secret
        );
    }

    public static function update($id, $data) {
        global $wpdb;

        $table = $wpdb->prefix . 'uapm_integrations';

        $allowed_statuses = array('active', 'inactive', 'disabled');

        $status = isset($data['status'])
            ? sanitize_key($data['status'])
            : 'inactive';

        if (!in_array($status, $allowed_statuses, true)) {
            return false;
        }

        $result = $wpdb->update(
            $table,
            array(
                'name' => sanitize_text_field($data['name']),
                'description' => sanitize_textarea_field($data['description']),
                'status' => $status,
                'updated_at' => current_time('mysql')
            ),
            array('id' => absint($id))
        );

        return $result !== false;
    }

    public static function delete($id) {
        global $wpdb;

        $table = $wpdb->prefix . 'uapm_integrations';

        self::log_event(absint($id), 'deleted');

        return $wpdb->delete(
            $table,
            array('id' => absint($id)),
            array('%d')
        );
    }

    public static function authenticate($request) {
        global $wpdb;

        $token = $request->get_header('x-uapm-token');

        if (!$token) {
            $token = $request->get_param('token');
        }

        if (!$token || strlen($token) < 32) {
            return new WP_Error(
                'uapm_missing_token',
                'Токен інтеграції не передано.',
                array('status' => 401)
            );
        }

        $hash = hash('sha256', sanitize_text_field($token));
        $table = $wpdb->prefix . 'uapm_integrations';

        $integration = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE access_token_hash = %s
                 AND status = 'active'
                 LIMIT 1",
                $hash
            )
        );

        if (!$integration) {
            return new WP_Error(
                'uapm_invalid_token',
                'Недійсний токен або інтеграцію вимкнено.',
                array('status' => 403)
            );
        }

        return $integration;
    }

    public static function api_register($request) {
        $key = sanitize_key($request->get_param('integration_key'));
        $name = sanitize_text_field($request->get_param('name'));

        if (!$key || !$name) {
            return new WP_Error(
                'uapm_invalid_registration',
                'Необхідно передати integration_key та name.',
                array('status' => 400)
            );
        }

        $existing = self::get_by_key($key);

        if ($existing) {
            return new WP_Error(
                'uapm_integration_exists',
                'Інтеграція з таким ключем вже зареєстрована.',
                array('status' => 409)
            );
        }

        $result = self::create(array(
            'integration_key' => $key,
            'name' => $name,
            'description' => $request->get_param('description'),
            'integration_type' => $request->get_param('integration_type')
        ));

        if (is_wp_error($result)) {
            return $result;
        }

        return new WP_REST_Response(array(
            'success' => true,
            'integration_id' => $result['id'],
            'integration_key' => $result['integration_key'],
            'access_token' => $result['access_token'],
            'secret_key' => $result['secret_key'],
            'message' => 'Збережіть ключі. Повторно вони не відображатимуться.'
        ), 201);
    }

    public static function api_heartbeat($request) {
        global $wpdb;

        $integration = self::authenticate($request);

        if (is_wp_error($integration)) {
            return $integration;
        }

        $table = $wpdb->prefix . 'uapm_integrations';

        $wpdb->update(
            $table,
            array(
                'last_seen' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ),
            array('id' => absint($integration->id))
        );

        self::log_event(
            $integration->id,
            'heartbeat'
        );

        return rest_ensure_response(array(
            'success' => true,
            'integration_id' => absint($integration->id),
            'server_time' => current_time('mysql')
        ));
    }

    public static function api_event($request) {
        $integration = self::authenticate($request);

        if (is_wp_error($integration)) {
            return $integration;
        }

        $event = sanitize_key($request->get_param('event'));

        $allowed_events = array(
            'installed',
            'activated',
            'updated',
            'deactivated',
            'uninstalled',
            'error',
            'custom'
        );

        if (!in_array($event, $allowed_events, true)) {
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

        self::log_event(
            $integration->id,
            $event,
            $payload
        );

        do_action(
            'uapm_integration_event',
            absint($integration->id),
            $event,
            $payload
        );

        return rest_ensure_response(array(
            'success' => true,
            'event' => $event,
            'recorded' => true
        ));
    }

    public static function get_by_key($key) {
        global $wpdb;

        $table = $wpdb->prefix . 'uapm_integrations';

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE integration_key = %s LIMIT 1",
                sanitize_key($key)
            )
        );
    }

    public static function log_event($integration_id, $event, $payload = array()) {
        global $wpdb;

        $table = $wpdb->prefix . 'uapm_integration_events';

        $wpdb->insert(
            $table,
            array(
                'integration_id' => absint($integration_id),
                'event_type' => sanitize_key($event),
                'payload' => wp_json_encode($payload),
                'created_at' => current_time('mysql')
            )
        );
    }

    private static function encrypt_secret($secret) {
        if (!function_exists('openssl_encrypt')) {
            return '';
        }

        $key = hash('sha256', wp_salt('auth'), true);
        $iv = random_bytes(16);

        $encrypted = openssl_encrypt(
            $secret,
            'AES-256-CBC',
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($encrypted === false) {
            return '';
        }

        return base64_encode($iv . $encrypted);
    }

    public static function render_page() {
        if (!current_user_can('uapm_manage_integrations')) {
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
                        <td>
                            <input type="text" name="integration_key" required>
                        </td>
                    </tr>
                    <tr>
                        <th>Назва</th>
                        <td>
                            <input type="text" name="name" required>
                        </td>
                    </tr>
                    <tr>
                        <th>Опис</th>
                        <td>
                            <textarea name="description" rows="3"></textarea>
                        </td>
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
                        <th>Дія</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($integrations as $item) : ?>
                        <tr>
                            <td><?php echo esc_html($item->id); ?></td>
                            <td><?php echo esc_html($item->name); ?></td>
                            <td><code><?php echo esc_html($item->integration_key); ?></code></td>
                            <td><?php echo esc_html($item->integration_type); ?></td>
                            <td><?php echo esc_html($item->status); ?></td>
                            <td><?php echo esc_html($item->last_seen ? $item->last_seen : 'Ще не підключалась'); ?></td>
                            <td>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                    <input type="hidden" name="action" value="uapm_delete_integration">
                                    <input type="hidden" name="integration_id" value="<?php echo esc_attr($item->id); ?>">
                                    <?php wp_nonce_field('uapm_delete_integration_' . $item->id); ?>
                                    <button
                                        type="submit"
                                        class="button button-small"
                                        onclick="return confirm('Видалити інтеграцію?');"
                                    >
                                        Видалити
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (empty($integrations)) : ?>
                        <tr>
                            <td colspan="7">Інтеграцій поки немає.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public static function handle_save() {
        if (!current_user_can('uapm_manage_integrations')) {
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
                : 'external'
        ));

        $url = add_query_arg(
            array(
                'page' => 'uapm-integrations',
                'created' => is_wp_error($result) ? 0 : 1
            ),
            admin_url('admin.php')
        );

        wp_safe_redirect($url);
        exit;
    }

    public static function handle_delete() {
        if (!current_user_can('uapm_manage_integrations')) {
            wp_die('Недостатньо прав.');
        }

        $id = isset($_POST['integration_id'])
            ? absint($_POST['integration_id'])
            : 0;

        check_admin_referer('uapm_delete_integration_' . $id);

        self::delete($id);

        wp_safe_redirect(add_query_arg(
            array(
                'page' => 'uapm-integrations',
                'deleted' => 1
            ),
            admin_url('admin.php')
        ));

        exit;
    }
}

UAPM_Integrations::install_schema();
UAPM_Integrations::init();