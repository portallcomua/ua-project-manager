<?php
/**
 * UA Project Manager — Universal Plugin Integrations Catalog.
 *
 * @package UA_Project_Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('UAPM_Integrations')) {

final class UAPM_Integrations {

    public static function init() {
        add_action('admin_post_uapm_save_integration', array(__CLASS__, 'handle_save'));
        add_action('admin_post_uapm_delete_integration', array(__CLASS__, 'handle_delete'));
        add_action('admin_post_uapm_toggle_integration', array(__CLASS__, 'handle_toggle'));
        add_action('admin_post_uapm_check_integration', array(__CLASS__, 'handle_check'));
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'uapm_integrations';
    }

    private static function events_table() {
        global $wpdb;
        return $wpdb->prefix . 'uapm_integration_events';
    }

    private static function can_manage() {
        return current_user_can('manage_options') ||
            current_user_can('uapm_manage_integrations');
    }

    public static function install_schema() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $table = self::table();
        $events = self::events_table();

        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            integration_key varchar(100) NOT NULL,
            name varchar(255) NOT NULL DEFAULT '',
            description text NULL,
            integration_type varchar(50) NOT NULL DEFAULT 'wordpress',
            status varchar(30) NOT NULL DEFAULT 'inactive',
            access_token_hash varchar(64) NOT NULL DEFAULT '',
            secret_key longtext NULL,
            settings longtext NULL,
            last_seen datetime NULL,
            integration_name varchar(191) NOT NULL DEFAULT '',
            integration_status varchar(30) NOT NULL DEFAULT 'inactive',
            plugin_author varchar(191) NOT NULL DEFAULT '',
            plugin_url text NULL,
            github_url text NULL,
            main_file varchar(255) NOT NULL DEFAULT '',
            plugin_version varchar(100) NOT NULL DEFAULT '',
            latest_version varchar(100) NOT NULL DEFAULT '',
            github_release_url text NULL,
            github_checked datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY integration_key (integration_key),
            KEY status (status),
            KEY integration_type (integration_type)
        ) {$charset};");

        dbDelta("CREATE TABLE {$events} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            integration_id bigint(20) unsigned NOT NULL,
            event_type varchar(100) NOT NULL,
            payload longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY integration_id (integration_id),
            KEY event_type (event_type),
            KEY created_at (created_at)
        ) {$charset};");

        self::seed_catalog();
    }

    private static function seed_catalog() {
        global $wpdb;

        $items = array(
            array(
                'integration_key' => 'ua-pay-box',
                'name' => 'UA Pay Box',
                'description' => 'Платіжний модуль та керування доступом.',
                'plugin_author' => 'UA Tools',
                'github_url' => 'https://github.com/portallcomua/UA-Pay-Box-',
                'main_file' => '',
            ),
            array(
                'integration_key' => 'ua-click-counter',
                'name' => 'UA Click Counter',
                'description' => 'Облік натискань на кнопки та посилання.',
                'plugin_author' => 'UA Tools',
                'github_url' => 'https://github.com/portallcomua/ua-click-counter',
                'main_file' => 'ua-click-counter.php',
            ),
            array(
                'integration_key' => 'ua-plugin-monitor',
                'name' => 'UA Plugins Monitor',
                'description' => 'Моніторинг встановлень і стану плагінів.',
                'plugin_author' => 'UA Tools',
                'github_url' => 'https://github.com/portallcomua/ua-plugin-monitor',
                'main_file' => 'ua-plugin-monitor2.php',
            ),
            array(
                'integration_key' => 'cms-web-finder',
                'name' => 'CMS Web Finder',
                'description' => 'Пошук сайтів і робота зі списками контактів.',
                'plugin_author' => 'UA Tools',
                'github_url' => 'https://github.com/portallcomua/cms-web-finger',
                'main_file' => 'cms-web-finder.php',
            ),
            array(
                'integration_key' => 'ua-forms',
                'name' => 'UA Forms',
                'description' => 'Конструктор форм WordPress, шаблони, заявки та Pro-доступ.',
                'plugin_author' => 'UAServer',
                'github_url' => 'https://github.com/portallcomua/UA-Forms',
                'main_file' => 'ua-forms.php',
            ),
        );

        foreach ($items as $item) {
            $exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM " . self::table() . " WHERE integration_key = %s LIMIT 1",
                    $item['integration_key']
                )
            );

            if ($exists) {
                continue;
            }

            $wpdb->insert(
                self::table(),
                array_merge($item, array(
                    'integration_type' => 'wordpress',
                    'status' => 'inactive',
                    'integration_name' => $item['name'],
                    'integration_status' => 'inactive',
                    'access_token_hash' => '',
                    'secret_key' => '',
                    'settings' => '{}',
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                ))
            );
        }
    }

    public static function get_all() {
        global $wpdb;

        return $wpdb->get_results(
            "SELECT * FROM " . self::table() . " ORDER BY name ASC"
        );
    }

    public static function get($id) {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM " . self::table() . " WHERE id = %d LIMIT 1",
                absint($id)
            )
        );
    }

    public static function get_by_key($key) {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM " . self::table() . " WHERE integration_key = %s LIMIT 1",
                sanitize_key($key)
            )
        );
    }

    public static function create($data) {
        global $wpdb;

        if (!self::can_manage()) {
            return new WP_Error('uapm_forbidden', 'Недостатньо прав.');
        }

        $key = sanitize_key($data['integration_key'] ?? '');
        $name = sanitize_text_field($data['name'] ?? '');

        if (!$key || !$name) {
            return new WP_Error('uapm_invalid', 'Вкажіть ключ і назву плагіна.');
        }

        if (self::get_by_key($key)) {
            return new WP_Error('uapm_duplicate', 'Такий ключ уже існує.');
        }

        $result = $wpdb->insert(
            self::table(),
            array(
                'integration_key' => $key,
                'name' => $name,
                'integration_name' => $name,
                'description' => sanitize_textarea_field($data['description'] ?? ''),
                'integration_type' => 'wordpress',
                'status' => 'inactive',
                'integration_status' => 'inactive',
                'plugin_author' => sanitize_text_field($data['plugin_author'] ?? ''),
                'plugin_url' => esc_url_raw($data['plugin_url'] ?? ''),
                'github_url' => esc_url_raw($data['github_url'] ?? ''),
                'main_file' => sanitize_text_field($data['main_file'] ?? ''),
                'plugin_version' => sanitize_text_field($data['plugin_version'] ?? ''),
                'latest_version' => '',
                'github_release_url' => '',
                'github_checked' => null,
                'access_token_hash' => '',
                'secret_key' => '',
                'settings' => '{}',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            )
        );

        if (!$result) {
            return new WP_Error('uapm_database_error', 'Не вдалося зберегти запис.');
        }

        return (int) $wpdb->insert_id;
    }

    public static function update($id, $data) {
        global $wpdb;

        if (!self::can_manage()) {
            return new WP_Error('uapm_forbidden', 'Недостатньо прав.');
        }

        $item = self::get($id);

        if (!$item) {
            return new WP_Error('uapm_not_found', 'Запис не знайдено.');
        }

        $status = sanitize_key($data['status'] ?? $item->status);

        if (!in_array($status, array('active', 'inactive', 'disabled'), true)) {
            $status = 'inactive';
        }

        $name = sanitize_text_field($data['name'] ?? $item->name);

        $result = $wpdb->update(
            self::table(),
            array(
                'name' => $name,
                'integration_name' => $name,
                'description' => sanitize_textarea_field($data['description'] ?? $item->description),
                'plugin_author' => sanitize_text_field($data['plugin_author'] ?? $item->plugin_author),
                'plugin_url' => esc_url_raw($data['plugin_url'] ?? $item->plugin_url),
                'github_url' => esc_url_raw($data['github_url'] ?? $item->github_url),
                'main_file' => sanitize_text_field($data['main_file'] ?? $item->main_file),
                'plugin_version' => sanitize_text_field($data['plugin_version'] ?? $item->plugin_version),
                'status' => $status,
                'integration_status' => $status,
                'updated_at' => current_time('mysql'),
            ),
            array('id' => absint($id))
        );

        return false === $result
            ? new WP_Error('uapm_database_error', 'Не вдалося оновити запис.')
            : true;
    }

    public static function delete($id) {
        global $wpdb;

        if (!self::can_manage()) {
            return false;
        }

        $id = absint($id);

        $wpdb->delete(
            self::events_table(),
            array('integration_id' => $id),
            array('%d')
        );

        return false !== $wpdb->delete(
            self::table(),
            array('id' => $id),
            array('%d')
        );
    }

    public static function normalize_repository($url) {
        $url = trim((string) $url);
        $url = preg_replace('#^https?://github\.com/#i', '', $url);
        $url = preg_replace('/\.git$/i', '', $url);
        $url = trim($url, '/');

        if (!preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $url)) {
            return new WP_Error('uapm_invalid_github', 'Некоректне посилання на GitHub.');
        }

        return $url;
    }

    public static function check_github($id) {
        global $wpdb;

        $item = self::get($id);

        if (!$item || empty($item->github_url)) {
            return new WP_Error('uapm_no_github', 'Не вказано GitHub-репозиторій.');
        }

        $repository = self::normalize_repository($item->github_url);

        if (is_wp_error($repository)) {
            return $repository;
        }

        $response = wp_remote_get(
            'https://api.github.com/repos/' . $repository . '/releases/latest',
            array(
                'timeout' => 20,
                'redirection' => 0,
                'headers' => array(
                    'Accept' => 'application/vnd.github+json',
                    'User-Agent' => 'UA-Project-Manager',
                    'X-GitHub-Api-Version' => '2022-11-28',
                ),
            )
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200 || !is_array($data) || empty($data['tag_name'])) {
            return new WP_Error(
                'uapm_github_release',
                'GitHub не повернув останній реліз. Перевірте репозиторій і наявність Releases.'
            );
        }

        $release_url = !empty($data['html_url'])
            ? esc_url_raw($data['html_url'])
            : '';

        $wpdb->update(
            self::table(),
            array(
                'latest_version' => sanitize_text_field($data['tag_name']),
                'github_release_url' => $release_url,
                'github_checked' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ),
            array('id' => absint($id))
        );

        return array(
            'version' => sanitize_text_field($data['tag_name']),
            'url' => $release_url,
        );
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

    public static function authenticate($request) {
        global $wpdb;

        $token = $request->get_header('x-uapm-token');

        if (!is_string($token) || strlen($token) < 32 || strlen($token) > 128) {
            return new WP_Error('uapm_missing_token', 'Не передано токен.', array('status' => 401));
        }

        $hash = hash('sha256', $token);

        $integration = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM " . self::table() . "
                 WHERE access_token_hash = %s AND status = 'active' LIMIT 1",
                $hash
            )
        );

        if (!$integration) {
            return new WP_Error('uapm_invalid_token', 'Токен недійсний або інтеграцію вимкнено.', array('status' => 403));
        }

        $rate_key = 'uapm_int_rate_' . absint($integration->id);
        $requests = (int) get_transient($rate_key);

        if ($requests >= 120) {
            return new WP_Error('uapm_rate_limit', 'Перевищено ліміт запитів.', array('status' => 429));
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
            self::table(),
            array('last_seen' => current_time('mysql')),
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
        $allowed = array('installed', 'activated', 'updated', 'deactivated', 'uninstalled', 'error', 'custom');

        if (!in_array($event, $allowed, true)) {
            return new WP_Error('uapm_invalid_event', 'Невідомий тип події.', array('status' => 400));
        }

        $payload = $request->get_param('payload');
        $payload = is_array($payload) ? self::sanitize_payload($payload) : array();

        if (!self::log_event($integration->id, $event, $payload)) {
            return new WP_Error('uapm_event_save_failed', 'Не вдалося зберегти подію.', array('status' => 500));
        }

        do_action('uapm_integration_event', absint($integration->id), $event, $payload);

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

        return false !== $wpdb->insert(
            self::events_table(),
            array(
                'integration_id' => absint($integration_id),
                'event_type' => sanitize_key($event),
                'payload' => wp_json_encode(self::sanitize_payload($payload)),
                'created_at' => current_time('mysql'),
            ),
            array('%d', '%s', '%s', '%s')
        );
    }

    public static function render() {
        self::render_page();
    }

    public static function render_page() {
        if (!self::can_manage()) {
            wp_die('Недостатньо прав.');
        }

        $items = self::get_all();
        $message = get_transient('uapm_catalog_message_' . get_current_user_id());
        delete_transient('uapm_catalog_message_' . get_current_user_id());

        ?>
        <div class="wrap">
            <h1>Каталог плагінів та інтеграцій</h1>
            <p>Додавайте сторонні плагіни за їхніми даними. Код дочірніх плагінів не копіюється в UA Project Manager.</p>

            <?php if ($message) : ?>
                <div class="notice notice-info"><p><?php echo esc_html($message); ?></p></div>
            <?php endif; ?>

            <h2>Додати плагін</h2>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="uapm_save_integration">
                <?php wp_nonce_field('uapm_save_integration'); ?>

                <table class="form-table">
                    <tr>
                        <th>Системний ключ</th>
                        <td><input class="regular-text" name="integration_key" required pattern="[a-zA-Z0-9_-]+"></td>
                    </tr>
                    <tr>
                        <th>Назва плагіна</th>
                        <td><input class="regular-text" name="name" required></td>
                    </tr>
                    <tr>
                        <th>Автор</th>
                        <td><input class="regular-text" name="plugin_author"></td>
                    </tr>
                    <tr>
                        <th>Опис</th>
                        <td><textarea class="large-text" rows="2" name="description"></textarea></td>
                    </tr>
                    <tr>
                        <th>Сторінка плагіна</th>
                        <td><input class="large-text" type="url" name="plugin_url" placeholder="https://..."></td>
                    </tr>
                    <tr>
                        <th>GitHub репозиторій</th>
                        <td><input class="large-text" type="url" name="github_url" placeholder="https://github.com/user/repository"></td>
                    </tr>
                    <tr>
                        <th>Головний файл</th>
                        <td><input class="regular-text" name="main_file" placeholder="plugin-name.php"></td>
                    </tr>
                    <tr>
                        <th>Поточна версія</th>
                        <td><input class="regular-text" name="plugin_version" placeholder="1.0.0"></td>
                    </tr>
                </table>

                <?php submit_button('Додати до каталогу'); ?>
            </form>

            <hr>

            <h2>Каталог плагінів (<?php echo esc_html(count($items)); ?>)</h2>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Плагін</th>
                        <th>Автор</th>
                        <th>Поточна версія</th>
                        <th>Останній реліз</th>
                        <th>GitHub</th>
                        <th>Сторінка</th>
                        <th>Перевірено</th>
                        <th>Дії</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($items) : foreach ($items as $item) : ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($item->name); ?></strong>
                            <br><code><?php echo esc_html($item->integration_key); ?></code>
                            <br><?php echo esc_html($item->description); ?>
                            <?php if (!empty($item->main_file)) : ?>
                                <br><small>Файл: <?php echo esc_html($item->main_file); ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($item->plugin_author); ?></td>
                        <td><?php echo esc_html($item->plugin_version); ?></td>
                        <td>
                            <?php echo esc_html($item->latest_version); ?>
                            <?php if (!empty($item->github_release_url)) : ?>
                                <br><a href="<?php echo esc_url($item->github_release_url); ?>" target="_blank" rel="noopener">Реліз</a>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($item->github_url) : ?>
                                <a href="<?php echo esc_url($item->github_url); ?>" target="_blank" rel="noopener">GitHub</a>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($item->plugin_url) : ?>
                                <a href="<?php echo esc_url($item->plugin_url); ?>" target="_blank" rel="noopener">Відкрити</a>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($item->github_checked ?: 'Ще не перевірено'); ?></td>
                        <td>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block">
                                <input type="hidden" name="action" value="uapm_check_integration">
                                <input type="hidden" name="integration_id" value="<?php echo esc_attr($item->id); ?>">
                                <?php wp_nonce_field('uapm_check_integration_' . $item->id); ?>
                                <button class="button button-small">Перевірити GitHub</button>
                            </form>

                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block">
                                <input type="hidden" name="action" value="uapm_toggle_integration">
                                <input type="hidden" name="integration_id" value="<?php echo esc_attr($item->id); ?>">
                                <input type="hidden" name="new_status" value="<?php echo $item->status === 'active' ? 'inactive' : 'active'; ?>">
                                <?php wp_nonce_field('uapm_toggle_integration_' . $item->id); ?>
                                <button class="button button-small"><?php echo $item->status === 'active' ? 'Вимкнути' : 'Увімкнути'; ?></button>
                            </form>

                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block">
                                <input type="hidden" name="action" value="uapm_delete_integration">
                                <input type="hidden" name="integration_id" value="<?php echo esc_attr($item->id); ?>">
                                <?php wp_nonce_field('uapm_delete_integration_' . $item->id); ?>
                                <button class="button button-small" onclick="return confirm('Видалити запис каталогу?');">Видалити</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; else : ?>
                    <tr><td colspan="8">Каталог порожній.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>

            <p class="description">Перевірка GitHub отримує останній опублікований реліз. Якщо в репозиторії немає Releases, перевірка повідомить про це.</p>
        </div>
        <?php
    }

    private static function redirect($message = '') {
        if ($message !== '') {
            set_transient(
                'uapm_catalog_message_' . get_current_user_id(),
                $message,
                60
            );
        }

        wp_safe_redirect(admin_url('admin.php?page=uapm-integrations'));
        exit;
    }

    public static function handle_save() {
        if (!self::can_manage()) {
            wp_die('Недостатньо прав.');
        }

        check_admin_referer('uapm_save_integration');

        $result = self::create(wp_unslash($_POST));

        self::redirect(
            is_wp_error($result)
                ? $result->get_error_message()
                : 'Плагін додано до каталогу.'
        );
    }

    public static function handle_toggle() {
        if (!self::can_manage()) {
            wp_die('Недостатньо прав.');
        }

        $id = absint($_POST['integration_id'] ?? 0);
        check_admin_referer('uapm_toggle_integration_' . $id);

        $result = self::update($id, array(
            'status' => sanitize_key($_POST['new_status'] ?? 'inactive'),
        ));

        self::redirect(
            is_wp_error($result)
                ? $result->get_error_message()
                : 'Статус змінено.'
        );
    }

    public static function handle_delete() {
        if (!self::can_manage()) {
            wp_die('Недостатньо прав.');
        }

        $id = absint($_POST['integration_id'] ?? 0);
        check_admin_referer('uapm_delete_integration_' . $id);

        self::delete($id);
        self::redirect('Запис каталогу видалено.');
    }

    public static function handle_check() {
        if (!self::can_manage()) {
            wp_die('Недостатньо прав.');
        }

        $id = absint($_POST['integration_id'] ?? 0);
        check_admin_referer('uapm_check_integration_' . $id);

        $result = self::check_github($id);

        self::redirect(
            is_wp_error($result)
                ? $result->get_error_message()
                : 'GitHub перевірено. Остання версія: ' . $result['version']
        );
    }
}

}

UAPM_Integrations::install_schema();
UAPM_Integrations::init();