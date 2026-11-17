<?php
if (!defined('ABSPATH')) {
    exit;
}

class UAPM_Installations {

    private static $table = 'uapm_installations';

    public static function init() {
        self::install_schema();

        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
        add_action('admin_post_uapm_installation_action', array(__CLASS__, 'handle_admin_action'));
    }

    public static function install_schema() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = $wpdb->prefix . self::$table;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id BIGINT UNSIGNED NOT NULL,
            customer_id BIGINT UNSIGNED DEFAULT 0,
            site_url VARCHAR(500) DEFAULT '',
            site_name VARCHAR(255) DEFAULT '',
            plugin_slug VARCHAR(191) DEFAULT '',
            plugin_version VARCHAR(100) DEFAULT '',
            wordpress_version VARCHAR(50) DEFAULT '',
            php_version VARCHAR(50) DEFAULT '',
            platform VARCHAR(50) DEFAULT 'wordpress',
            install_token_hash VARCHAR(255) DEFAULT '',
            status VARCHAR(30) DEFAULT 'active',
            first_seen DATETIME DEFAULT NULL,
            last_seen DATETIME DEFAULT NULL,
            deactivated_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY project_id (project_id),
            KEY customer_id (customer_id),
            KEY status (status),
            KEY last_seen (last_seen),
            KEY site_url (site_url(191))
        ) $charset;";

        dbDelta($sql);
    }

    public static function register_routes() {
        register_rest_route('uapm/v1', '/installation/register', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'register_installation'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('uapm/v1', '/installation/heartbeat', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'heartbeat'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('uapm/v1', '/installation/deactivate', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'deactivate'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('uapm/v1', '/installations', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'rest_list'),
            'permission_callback' => function () {
                return current_user_can('uapm_manage_projects');
            },
        ));
    }

    private static function table_name() {
        global $wpdb;
        return $wpdb->prefix . self::$table;
    }

    private static function verify_token($installation_id, $token) {
        global $wpdb;

        if (!$installation_id || !$token) {
            return false;
        }

        $hash = $wpdb->get_var($wpdb->prepare(
            "SELECT install_token_hash FROM " . self::table_name() . " WHERE id = %d",
            $installation_id
        ));

        return $hash && wp_check_password($token, $hash);
    }

    public static function register_installation($request) {
        global $wpdb;

        $project_id = absint($request->get_param('project_id'));
        $site_url = esc_url_raw($request->get_param('site_url'));
        $site_name = sanitize_text_field($request->get_param('site_name'));
        $plugin_slug = sanitize_title($request->get_param('plugin_slug'));
        $plugin_version = sanitize_text_field($request->get_param('plugin_version'));

        if (!$project_id || !$site_url || !$plugin_slug) {
            return new WP_Error(
                'uapm_invalid_installation',
                'Необхідні project_id, site_url та plugin_slug.',
                array('status' => 400)
            );
        }

        if (!class_exists('UAPM_Projects') || !UAPM_Projects::get($project_id)) {
            return new WP_Error(
                'uapm_project_not_found',
                'Проєкт не знайдено.',
                array('status' => 404)
            );
        }

        $site_host = wp_parse_url($site_url, PHP_URL_HOST);

        if (!$site_host || !in_array(wp_parse_url($site_url, PHP_URL_SCHEME), array('http', 'https'), true)) {
            return new WP_Error(
                'uapm_invalid_site',
                'Некоректна адреса сайту.',
                array('status' => 400)
            );
        }

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table_name() . " WHERE project_id = %d AND site_url = %s LIMIT 1",
            $project_id,
            $site_url
        ));

        $token = wp_generate_password(48, false, false);
        $now = current_time('mysql');

        $data = array(
            'project_id' => $project_id,
            'site_url' => $site_url,
            'site_name' => $site_name,
            'plugin_slug' => $plugin_slug,
            'plugin_version' => $plugin_version,
            'wordpress_version' => sanitize_text_field($request->get_param('wordpress_version')),
            'php_version' => sanitize_text_field($request->get_param('php_version')),
            'platform' => sanitize_key($request->get_param('platform') ?: 'wordpress'),
            'install_token_hash' => wp_hash_password($token),
            'status' => 'active',
            'last_seen' => $now,
            'updated_at' => $now,
        );

        if ($existing) {
            $wpdb->update(self::table_name(), $data, array('id' => $existing->id));
            $installation_id = (int) $existing->id;
        } else {
            $data['created_at'] = $now;
            $data['first_seen'] = $now;

            $wpdb->insert(self::table_name(), $data);
            $installation_id = (int) $wpdb->insert_id;
        }

        if (!$installation_id) {
            return new WP_Error(
                'uapm_installation_failed',
                'Не вдалося зареєструвати встановлення.',
                array('status' => 500)
            );
        }

        return rest_ensure_response(array(
            'success' => true,
            'installation_id' => $installation_id,
            'installation_token' => $token,
            'message' => 'Встановлення зареєстровано.',
        ));
    }

    public static function heartbeat($request) {
        global $wpdb;

        $id = absint($request->get_param('installation_id'));
        $token = sanitize_text_field($request->get_param('installation_token'));

        if (!self::verify_token($id, $token)) {
            return new WP_Error(
                'uapm_invalid_token',
                'Недійсний токен встановлення.',
                array('status' => 403)
            );
        }

        $data = array(
            'last_seen' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
            'status' => 'active',
        );

        $version = sanitize_text_field($request->get_param('plugin_version'));
        if ($version !== '') {
            $data['plugin_version'] = $version;
        }

        $wp_version = sanitize_text_field($request->get_param('wordpress_version'));
        if ($wp_version !== '') {
            $data['wordpress_version'] = $wp_version;
        }

        $php_version = sanitize_text_field($request->get_param('php_version'));
        if ($php_version !== '') {
            $data['php_version'] = $php_version;
        }

        $wpdb->update(self::table_name(), $data, array('id' => $id));

        return rest_ensure_response(array(
            'success' => true,
            'message' => 'Зв’язок оновлено.',
        ));
    }

    public static function deactivate($request) {
        global $wpdb;

        $id = absint($request->get_param('installation_id'));
        $token = sanitize_text_field($request->get_param('installation_token'));

        if (!self::verify_token($id, $token)) {
            return new WP_Error(
                'uapm_invalid_token',
                'Недійсний токен встановлення.',
                array('status' => 403)
            );
        }

        $now = current_time('mysql');

        $wpdb->update(
            self::table_name(),
            array(
                'status' => 'deactivated',
                'deactivated_at' => $now,
                'updated_at' => $now,
            ),
            array('id' => $id)
        );

        return rest_ensure_response(array(
            'success' => true,
            'message' => 'Встановлення деактивовано.',
        ));
    }

    public static function get_all($args = array()) {
        global $wpdb;

        $defaults = array(
            'project_id' => 0,
            'status' => '',
            'search' => '',
            'limit' => 50,
            'offset' => 0,
        );

        $args = wp_parse_args($args, $defaults);

        $where = array('1=1');
        $values = array();

        if ($args['project_id']) {
            $where[] = 'project_id = %d';
            $values[] = absint($args['project_id']);
        }

        if ($args['status'] !== '') {
            $where[] = 'status = %s';
            $values[] = sanitize_key($args['status']);
        }

        if ($args['search'] !== '') {
            $where[] = '(site_url LIKE %s OR site_name LIKE %s OR plugin_slug LIKE %s)';
            $search = '%' . $wpdb->esc_like($args['search']) . '%';
            $values[] = $search;
            $values[] = $search;
            $values[] = $search;
        }

        $limit = min(500, max(1, absint($args['limit'])));
        $offset = absint($args['offset']);

        $sql = "SELECT * FROM " . self::table_name()
            . " WHERE " . implode(' AND ', $where)
            . " ORDER BY last_seen DESC LIMIT %d OFFSET %d";

        $values[] = $limit;
        $values[] = $offset;

        return $wpdb->get_results($wpdb->prepare($sql, $values));
    }

    public static function count($args = array()) {
        global $wpdb;

        $where = array('1=1');
        $values = array();

        if (!empty($args['project_id'])) {
            $where[] = 'project_id = %d';
            $values[] = absint($args['project_id']);
        }

        if (!empty($args['status'])) {
            $where[] = 'status = %s';
            $values[] = sanitize_key($args['status']);
        }

        $sql = "SELECT COUNT(*) FROM " . self::table_name()
            . " WHERE " . implode(' AND ', $where);

        if ($values) {
            $sql = $wpdb->prepare($sql, $values);
        }

        return (int) $wpdb->get_var($sql);
    }

    public static function rest_list($request) {
        return rest_ensure_response(array(
            'items' => self::get_all(array(
                'project_id' => absint($request->get_param('project_id')),
                'status' => sanitize_key($request->get_param('status')),
                'search' => sanitize_text_field($request->get_param('search')),
                'limit' => absint($request->get_param('limit')) ?: 50,
                'offset' => absint($request->get_param('offset')),
            )),
            'total' => self::count(array(
                'project_id' => absint($request->get_param('project_id')),
                'status' => sanitize_key($request->get_param('status')),
            )),
        ));
    }

    public static function handle_admin_action() {
        if (!current_user_can('uapm_manage_projects')) {
            wp_die('Недостатньо прав.');
        }

        check_admin_referer('uapm_installation_action');

        global $wpdb;

        $id = absint($_POST['installation_id'] ?? 0);
        $action = sanitize_key($_POST['installation_action'] ?? '');

        if ($id && $action === 'deactivate') {
            $wpdb->update(
                self::table_name(),
                array(
                    'status' => 'deactivated',
                    'deactivated_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                ),
                array('id' => $id)
            );
        }

        if ($id && $action === 'activate') {
            $wpdb->update(
                self::table_name(),
                array(
                    'status' => 'active',
                    'deactivated_at' => null,
                    'updated_at' => current_time('mysql'),
                ),
                array('id' => $id)
            );
        }

        wp_safe_redirect(admin_url('admin.php?page=uapm-installations'));
        exit;
    }
}