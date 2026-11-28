<?php
/**
 * UA Project Manager — Clients.
 *
 * @package UA_Project_Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

class UAPM_Clients {

    public static function init() {
        if (defined('UAPM_DB_VERSION') && get_option('uapm_modules_schema_ver') !== UAPM_DB_VERSION) {
            self::install_schema();
        }
        add_action('admin_post_uapm_save_client', array(__CLASS__, 'handle_save'));
        add_action('admin_post_uapm_delete_client', array(__CLASS__, 'handle_delete'));
        add_action('admin_post_uapm_manager_save_client', array(__CLASS__, 'handle_manager_save'));
        add_action('admin_post_uapm_manager_archive_client', array(__CLASS__, 'handle_manager_archive'));

        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    public static function install_schema() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = $wpdb->prefix . 'uapm_clients';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            name varchar(255) NOT NULL DEFAULT '',
            email varchar(190) NOT NULL DEFAULT '',
            company varchar(255) NOT NULL DEFAULT '',
            phone varchar(50) NOT NULL DEFAULT '',
            country varchar(100) NOT NULL DEFAULT '',
            notes text NULL,
            status varchar(30) NOT NULL DEFAULT 'active',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY email (email),
            KEY status (status)
        ) {$charset};";

        dbDelta($sql);
    }

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'uapm_clients';
    }

    private static function clean_data($data) {
        return array(
            'name' => isset($data['name'])
                ? sanitize_text_field($data['name'])
                : '',
            'email' => isset($data['email'])
                ? strtolower(sanitize_email($data['email']))
                : '',
            'company' => isset($data['company'])
                ? sanitize_text_field($data['company'])
                : '',
            'phone' => isset($data['phone'])
                ? sanitize_text_field($data['phone'])
                : '',
            'country' => isset($data['country'])
                ? sanitize_text_field($data['country'])
                : '',
            'notes' => isset($data['notes'])
                ? sanitize_textarea_field($data['notes'])
                : '',
            'user_id' => isset($data['user_id'])
                ? absint($data['user_id'])
                : 0,
        );
    }

    private static function validate_data($data) {
        if (empty($data['name']) || !is_email($data['email'])) {
            return new WP_Error(
                'uapm_invalid_client',
                'Вкажіть ім’я клієнта та коректну електронну адресу.'
            );
        }

        return true;
    }

    public static function create($data) {
        global $wpdb;

        $data = self::clean_data($data);
        $valid = self::validate_data($data);

        if (is_wp_error($valid)) {
            return $valid;
        }

        if (self::get_by_email($data['email'])) {
            return new WP_Error(
                'uapm_client_exists',
                'Клієнт із такою електронною адресою вже існує.'
            );
        }

        $result = $wpdb->insert(
            self::table(),
            array(
                'user_id' => $data['user_id'],
                'name' => $data['name'],
                'email' => $data['email'],
                'company' => $data['company'],
                'phone' => $data['phone'],
                'country' => $data['country'],
                'notes' => $data['notes'],
                'status' => 'active',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            )
        );

        if (!$result) {
            return new WP_Error(
                'uapm_client_create_failed',
                'Не вдалося створити запис клієнта.'
            );
        }

        $id = absint($wpdb->insert_id);

        do_action('uapm_client_created', $id, $data);

        return $id;
    }

    public static function update($id, $data) {
        global $wpdb;

        $id = absint($id);
        $client = self::get($id);

        if (!$client) {
            return new WP_Error(
                'uapm_client_not_found',
                'Клієнта не знайдено.'
            );
        }

        $data = self::clean_data($data);
        $valid = self::validate_data($data);

        if (is_wp_error($valid)) {
            return $valid;
        }

        $existing = self::get_by_email($data['email']);

        if ($existing && absint($existing->id) !== $id) {
            return new WP_Error(
                'uapm_client_exists',
                'Інший клієнт уже використовує цю електронну адресу.'
            );
        }

        $result = $wpdb->update(
            self::table(),
            array(
                'name' => $data['name'],
                'email' => $data['email'],
                'company' => $data['company'],
                'phone' => $data['phone'],
                'country' => $data['country'],
                'notes' => $data['notes'],
                'updated_at' => current_time('mysql'),
            ),
            array('id' => $id)
        );

        if (false === $result) {
            return new WP_Error(
                'uapm_client_update_failed',
                'Не вдалося оновити клієнта.'
            );
        }

        do_action('uapm_client_updated', $id, $data);

        return true;
    }

    public static function update_for_manager($id, $manager_id, $data) {
        $client = self::get($id);

        if (!$client || absint($client->user_id) !== absint($manager_id)) {
            return new WP_Error(
                'uapm_client_forbidden',
                'Цей клієнт не належить вашому обліковому запису.'
            );
        }

        // Preserve existing notes because the manager form does not expose this field.
        if (!isset($data['notes']) || '' === trim((string) $data['notes'])) {
            $data['notes'] = $client->notes;
        }

        return self::update($id, $data);
    }

    public static function get($id) {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . self::table() . ' WHERE id = %d LIMIT 1',
                absint($id)
            )
        );
    }

    public static function get_by_email($email) {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . self::table() . ' WHERE email = %s LIMIT 1',
                strtolower(sanitize_email($email))
            )
        );
    }

    public static function get_all($args = array()) {
        global $wpdb;

        $args = wp_parse_args($args, array(
            'search' => '',
            'status' => '',
            'user_id' => null,
            'limit' => 100,
            'offset' => 0,
        ));

        $where = array('1=1');
        $params = array();

        if ($args['search'] !== '') {
            $search = '%' . $wpdb->esc_like(
                sanitize_text_field($args['search'])
            ) . '%';

            $where[] = '(name LIKE %s OR email LIKE %s OR company LIKE %s)';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        if ($args['status'] !== '') {
            $where[] = 'status = %s';
            $params[] = sanitize_key($args['status']);
        }

        if (null !== $args['user_id']) {
            $where[] = 'user_id = %d';
            $params[] = absint($args['user_id']);
        }

        $limit = max(1, min(500, absint($args['limit'])));
        $offset = absint($args['offset']);

        $sql = 'SELECT * FROM ' . self::table() .
            ' WHERE ' . implode(' AND ', $where) .
            ' ORDER BY created_at DESC LIMIT %d OFFSET %d';

        $params[] = $limit;
        $params[] = $offset;

        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }

    public static function get_manager_clients($manager_id, $args = array()) {
        $args['user_id'] = absint($manager_id);
        return self::get_all($args);
    }

    public static function count($status = '', $user_id = null) {
        global $wpdb;

        $where = array('1=1');
        $params = array();

        if ($status !== '') {
            $where[] = 'status = %s';
            $params[] = sanitize_key($status);
        }

        if (null !== $user_id) {
            $where[] = 'user_id = %d';
            $params[] = absint($user_id);
        }

        $sql = 'SELECT COUNT(*) FROM ' . self::table() .
            ' WHERE ' . implode(' AND ', $where);

        if (!empty($params)) {
            return absint($wpdb->get_var($wpdb->prepare($sql, $params)));
        }

        return absint($wpdb->get_var($sql));
    }

    public static function delete($id) {
        global $wpdb;

        $client = self::get($id);

        if (!$client) {
            return false;
        }

        return $wpdb->update(
            self::table(),
            array(
                'status' => 'archived',
                'updated_at' => current_time('mysql'),
            ),
            array('id' => absint($id))
        );
    }

    public static function archive_for_manager($id, $manager_id) {
        $client = self::get($id);

        if (!$client || absint($client->user_id) !== absint($manager_id)) {
            return new WP_Error(
                'uapm_client_forbidden',
                'Цей клієнт не належить вашому обліковому запису.'
            );
        }

        return self::delete($id);
    }

    public static function register_routes() {
        register_rest_route('uapm/v1', '/clients', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'api_list'),
            'permission_callback' => array(__CLASS__, 'api_permission'),
        ));

        register_rest_route('uapm/v1', '/clients/(?P<id>\d+)', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'api_get'),
            'permission_callback' => array(__CLASS__, 'api_permission'),
        ));
    }

    public static function api_permission() {
        return current_user_can('uapm_manage_projects') ||
            current_user_can('manage_options');
    }

    public static function api_list($request) {
        $items = self::get_all(array(
            'search' => $request->get_param('search'),
            'status' => $request->get_param('status'),
            'limit' => $request->get_param('limit') ?: 100,
        ));

        $result = array();

        foreach ($items as $item) {
            $result[] = self::public_data($item);
        }

        return rest_ensure_response(array(
            'success' => true,
            'count' => count($result),
            'clients' => $result,
        ));
    }

    public static function api_get($request) {
        $client = self::get(absint($request['id']));

        if (!$client) {
            return new WP_Error(
                'uapm_client_not_found',
                'Клієнта не знайдено.',
                array('status' => 404)
            );
        }

        return rest_ensure_response(array(
            'success' => true,
            'client' => self::public_data($client),
        ));
    }

    private static function public_data($client) {
        return array(
            'id' => absint($client->id),
            'name' => $client->name,
            'email' => $client->email,
            'company' => $client->company,
            'phone' => $client->phone,
            'country' => $client->country,
            'status' => $client->status,
            'created_at' => $client->created_at,
        );
    }

    public static function handle_save() {
        if (!current_user_can('uapm_manage_projects')) {
            wp_die('Недостатньо прав.');
        }

        check_admin_referer('uapm_save_client');

        $data = self::posted_data();
        $id = isset($_POST['client_id']) ? absint($_POST['client_id']) : 0;

        if ($id) {
            self::update($id, $data);
        } else {
            self::create($data);
        }

        wp_safe_redirect(add_query_arg(
            array('page' => 'uapm-clients'),
            admin_url('admin.php')
        ));
        exit;
    }

    public static function handle_delete() {
        if (!current_user_can('uapm_manage_projects')) {
            wp_die('Недостатньо прав.');
        }

        $id = isset($_POST['client_id'])
            ? absint($_POST['client_id'])
            : 0;

        check_admin_referer('uapm_delete_client_' . $id);

        self::delete($id);

        wp_safe_redirect(add_query_arg(
            array('page' => 'uapm-clients'),
            admin_url('admin.php')
        ));
        exit;
    }

    public static function handle_manager_save() {
        if (
            !is_user_logged_in() ||
            !current_user_can('uapm_manage_own_clients') ||
            current_user_can('uapm_manage_projects')
        ) {
            wp_die('Недостатньо прав.');
        }

        check_admin_referer('uapm_manager_save_client');

        $manager_id = get_current_user_id();
        $data = self::posted_data();

        $id = isset($_POST['client_id'])
            ? absint($_POST['client_id'])
            : 0;

        if ($id) {
            $result = self::update_for_manager($id, $manager_id, $data);
        } else {
            $data['user_id'] = $manager_id;
            $result = self::create($data);
        }

        $url = add_query_arg(
            'uapm_client_notice',
            is_wp_error($result) ? 'error' : 'saved',
            home_url('/gpm/')
        );

        if (is_wp_error($result)) {
            $url = add_query_arg(
                'uapm_client_message',
                rawurlencode($result->get_error_message()),
                $url
            );
        }

        wp_safe_redirect($url);
        exit;
    }

    public static function handle_manager_archive() {
        if (
            !is_user_logged_in() ||
            !current_user_can('uapm_manage_own_clients') ||
            current_user_can('uapm_manage_projects')
        ) {
            wp_die('Недостатньо прав.');
        }

        $id = isset($_POST['client_id'])
            ? absint($_POST['client_id'])
            : 0;

        check_admin_referer('uapm_manager_archive_client_' . $id);

        $result = self::archive_for_manager($id, get_current_user_id());

        $url = add_query_arg(
            'uapm_client_notice',
            is_wp_error($result) ? 'error' : 'archived',
            home_url('/gpm/')
        );

        if (is_wp_error($result)) {
            $url = add_query_arg(
                'uapm_client_message',
                rawurlencode($result->get_error_message()),
                $url
            );
        }

        wp_safe_redirect($url);
        exit;
    }

    private static function posted_data() {
        return array(
            'name' => isset($_POST['name'])
                ? wp_unslash($_POST['name'])
                : '',
            'email' => isset($_POST['email'])
                ? wp_unslash($_POST['email'])
                : '',
            'company' => isset($_POST['company'])
                ? wp_unslash($_POST['company'])
                : '',
            'phone' => isset($_POST['phone'])
                ? wp_unslash($_POST['phone'])
                : '',
            'country' => isset($_POST['country'])
                ? wp_unslash($_POST['country'])
                : '',
            'notes' => isset($_POST['notes'])
                ? wp_unslash($_POST['notes'])
                : '',
        );
    }

    public static function render_page() {
        if (!current_user_can('uapm_manage_projects')) {
            wp_die('Недостатньо прав.');
        }

        $clients = self::get_all();

        ?>
        <div class="wrap">
            <h1>Клієнти UPM</h1>

            <h2>Додати клієнта</h2>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="uapm_save_client">
                <input type="hidden" name="client_id" value="0">
                <?php wp_nonce_field('uapm_save_client'); ?>

                <table class="form-table">
                    <tr>
                        <th>Ім’я</th>
                        <td><input type="text" name="name" required class="regular-text"></td>
                    </tr>
                    <tr>
                        <th>Email</th>
                        <td><input type="email" name="email" required class="regular-text"></td>
                    </tr>
                    <tr>
                        <th>Компанія</th>
                        <td><input type="text" name="company" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th>Телефон</th>
                        <td><input type="text" name="phone" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th>Країна</th>
                        <td><input type="text" name="country" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th>Нотатки</th>
                        <td><textarea name="notes" rows="3" class="large-text"></textarea></td>
                    </tr>
                </table>

                <?php submit_button('Додати клієнта'); ?>
            </form>

            <hr>

            <h2>Список клієнтів</h2>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Ім’я</th>
                        <th>Email</th>
                        <th>Компанія</th>
                        <th>Телефон</th>
                        <th>Статус</th>
                        <th>Дія</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($clients as $client) : ?>
                        <tr>
                            <td><?php echo esc_html($client->id); ?></td>
                            <td><?php echo esc_html($client->name); ?></td>
                            <td><?php echo esc_html($client->email); ?></td>
                            <td><?php echo esc_html($client->company); ?></td>
                            <td><?php echo esc_html($client->phone); ?></td>
                            <td><?php echo esc_html($client->status); ?></td>
                            <td>
                                <?php if ($client->status !== 'archived') : ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                        <input type="hidden" name="action" value="uapm_delete_client">
                                        <input type="hidden" name="client_id" value="<?php echo esc_attr($client->id); ?>">
                                        <?php wp_nonce_field('uapm_delete_client_' . $client->id); ?>
                                        <button type="submit" class="button button-small">
                                            Архівувати
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (empty($clients)) : ?>
                        <tr>
                            <td colspan="7">Клієнтів поки немає.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}

UAPM_Clients::init();
