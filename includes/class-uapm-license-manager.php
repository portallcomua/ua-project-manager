<?php
if (!defined('ABSPATH')) {
    exit;
}

class UAPM_License_Manager {

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'uapm_licenses';
    }

    public static function init() {
        self::install_schema();

        add_action('rest_api_init', array(__CLASS__, 'register_routes'));

        add_action('admin_post_uapm_create_license', array(__CLASS__, 'handle_create'));
        add_action('admin_post_uapm_revoke_license', array(__CLASS__, 'handle_revoke'));
        add_action('admin_post_uapm_delete_license', array(__CLASS__, 'handle_delete'));
    }

    public static function install_schema() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            project_id bigint(20) unsigned NOT NULL,
            customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
            license_key varchar(100) NOT NULL,
            license_hash varchar(255) NOT NULL,
            target_type varchar(30) NOT NULL DEFAULT 'domain',
            target_value varchar(255) NOT NULL DEFAULT '',
            plan varchar(50) NOT NULL DEFAULT 'pro',
            status varchar(30) NOT NULL DEFAULT 'active',
            expires_at datetime NULL,
            max_sites int(11) unsigned NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY license_key (license_key),
            KEY project_id (project_id),
            KEY customer_id (customer_id),
            KEY status (status)
        ) {$charset};";

        dbDelta($sql);
    }

    private static function generate_key() {
        return 'UAPM-' .
            strtoupper(wp_generate_password(5, false, false)) . '-' .
            strtoupper(wp_generate_password(5, false, false)) . '-' .
            strtoupper(wp_generate_password(5, false, false));
    }

    public static function create($data) {
        global $wpdb;

        $project_id = absint($data['project_id'] ?? 0);
        $project = class_exists('UAPM_Projects')
            ? UAPM_Projects::get($project_id)
            : null;

        if (!$project) {
            return new WP_Error('invalid_project', 'Проєкт не знайдено.');
        }

        $target_type = sanitize_key($data['target_type'] ?? 'domain');

        if (!in_array($target_type, array('domain', 'email'), true)) {
            return new WP_Error('invalid_target_type', 'Неправильний тип активації.');
        }

        $target_value = sanitize_text_field($data['target_value'] ?? '');

        if ($target_type === 'email') {
            $target_value = sanitize_email($target_value);

            if (!is_email($target_value)) {
                return new WP_Error('invalid_email', 'Вкажіть коректний email.');
            }
        } else {
            $target_value = self::normalize_domain($target_value);

            if (!$target_value) {
                return new WP_Error('invalid_domain', 'Вкажіть коректний домен.');
            }
        }

        $plan = sanitize_key($data['plan'] ?? 'pro');
        $expires_at = null;

        if (!empty($data['expires_at'])) {
            $timestamp = strtotime($data['expires_at']);

            if (!$timestamp) {
                return new WP_Error('invalid_expiry', 'Неправильна дата завершення.');
            }

            $expires_at = gmdate('Y-m-d H:i:s', $timestamp);
        }

        $key = self::generate_key();

        $inserted = $wpdb->insert(
            self::table(),
            array(
                'project_id' => $project_id,
                'customer_id' => absint($data['customer_id'] ?? 0),
                'license_key' => $key,
                'license_hash' => wp_hash_password($key),
                'target_type' => $target_type,
                'target_value' => $target_value,
                'plan' => $plan,
                'status' => 'active',
                'expires_at' => $expires_at,
                'max_sites' => max(1, absint($data['max_sites'] ?? 1)),
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            )
        );

        if (!$inserted) {
            return new WP_Error('license_create_failed', 'Не вдалося створити ліцензію.');
        }

        $license_id = (int) $wpdb->insert_id;

        do_action('uapm_license_created', $license_id, $project_id);

        return array(
            'id' => $license_id,
            'license_key' => $key,
        );
    }

    private static function normalize_domain($domain) {
        $domain = strtolower(trim($domain));

        if ($domain === '') {
            return '';
        }

        if (strpos($domain, '://') !== false) {
            $domain = wp_parse_url($domain, PHP_URL_HOST);
        }

        $domain = preg_replace('/:\d+$/', '', $domain);
        $domain = preg_replace('/^www\./', '', $domain);

        if (!$domain || !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $domain)) {
            return '';
        }

        return $domain;
    }

    public static function get($license_id) {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM " . self::table() . " WHERE id = %d",
                absint($license_id)
            )
        );
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

        if (!empty($args['project_id'])) {
            $where[] = 'project_id = %d';
            $values[] = absint($args['project_id']);
        }

        if (!empty($args['status'])) {
            $where[] = 'status = %s';
            $values[] = sanitize_key($args['status']);
        }

        if (!empty($args['search'])) {
            $like = '%' . $wpdb->esc_like($args['search']) . '%';
            $where[] = '(target_value LIKE %s OR license_key LIKE %s)';
            $values[] = $like;
            $values[] = $like;
        }

        $limit = max(1, min(500, absint($args['limit'])));
        $offset = absint($args['offset']);

        $sql = "SELECT * FROM " . self::table() .
            " WHERE " . implode(' AND ', $where) .
            " ORDER BY id DESC LIMIT %d OFFSET %d";

        $values[] = $limit;
        $values[] = $offset;

        return $wpdb->get_results($wpdb->prepare($sql, $values));
    }

    public static function validate($license_key, $project_id, $target_value = '') {
        global $wpdb;

        $license_key = sanitize_text_field($license_key);
        $project_id = absint($project_id);

        if (!$license_key || !$project_id) {
            return new WP_Error('invalid_license', 'Ліцензію не знайдено.');
        }

        $license = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM " . self::table() . " WHERE license_key = %s AND project_id = %d LIMIT 1",
                $license_key,
                $project_id
            )
        );

        if (!$license || !wp_check_password($license_key, $license->license_hash)) {
            return new WP_Error('invalid_license', 'Недійсний ліцензійний ключ.');
        }

        if ($license->status !== 'active') {
            return new WP_Error('license_inactive', 'Ліцензію деактивовано.');
        }

        if (!empty($license->expires_at) && strtotime($license->expires_at) < current_time('timestamp')) {
            return new WP_Error('license_expired', 'Термін дії ліцензії завершився.');
        }

        if ($target_value !== '') {
            $target = $license->target_type === 'email'
                ? sanitize_email($target_value)
                : self::normalize_domain($target_value);

            if (!$target || !hash_equals($license->target_value, $target)) {
                return new WP_Error('target_mismatch', 'Ліцензія не призначена для цього користувача або домену.');
            }
        }

        return array(
            'valid' => true,
            'license_id' => (int) $license->id,
            'project_id' => (int) $license->project_id,
            'plan' => $license->plan,
            'expires_at' => $license->expires_at,
            'max_sites' => (int) $license->max_sites,
        );
    }

    public static function revoke($license_id) {
        global $wpdb;

        $license = self::get($license_id);

        if (!$license) {
            return new WP_Error('license_not_found', 'Ліцензію не знайдено.');
        }

        $updated = $wpdb->update(
            self::table(),
            array(
                'status' => 'revoked',
                'updated_at' => current_time('mysql'),
            ),
            array('id' => absint($license_id))
        );

        if ($updated === false) {
            return new WP_Error('license_revoke_failed', 'Не вдалося деактивувати ліцензію.');
        }

        do_action('uapm_license_revoked', absint($license_id), $license->project_id);

        return true;
    }

    public static function delete($license_id) {
        global $wpdb;

        $license_id = absint($license_id);

        if (!self::get($license_id)) {
            return new WP_Error('license_not_found', 'Ліцензію не знайдено.');
        }

        $deleted = $wpdb->delete(
            self::table(),
            array('id' => $license_id),
            array('%d')
        );

        return $deleted
            ? true
            : new WP_Error('license_delete_failed', 'Не вдалося видалити ліцензію.');
    }

    public static function register_routes() {
        register_rest_route('uapm/v1', '/license/validate', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'rest_validate'),
            'permission_callback' => '__return_true',
        ));
    }

    public static function rest_validate($request) {
        $license_key = sanitize_text_field($request->get_param('license_key'));
        $project_id = absint($request->get_param('project_id'));
        $target_value = sanitize_text_field($request->get_param('target_value'));

        $result = self::validate($license_key, $project_id, $target_value);

        if (is_wp_error($result)) {
            return new WP_REST_Response(array(
                'valid' => false,
                'code' => $result->get_error_code(),
                'message' => $result->get_error_message(),
            ), 200);
        }

        return rest_ensure_response($result);
    }

    public static function handle_create() {
        if (!current_user_can('uapm_manage_payments')) {
            wp_die('Недостатньо прав.');
        }

        check_admin_referer('uapm_create_license');

        $result = self::create(wp_unslash($_POST));

        $url = admin_url('admin.php?page=uapm-licenses');

        if (is_wp_error($result)) {
            $url = add_query_arg(
                'uapm_error',
                rawurlencode($result->get_error_message()),
                $url
            );
        } else {
            $url = add_query_arg(
                array(
                    'uapm_success' => 'created',
                    'license_id' => $result['id'],
                ),
                $url
            );
            set_transient(
                'uapm_new_license_' . get_current_user_id(),
                $result['license_key'],
                300
            );
        }

        wp_safe_redirect($url);
        exit;
    }

    public static function handle_revoke() {
        if (!current_user_can('uapm_manage_payments')) {
            wp_die('Недостатньо прав.');
        }

        check_admin_referer('uapm_revoke_license');

        $result = self::revoke(absint($_POST['license_id'] ?? 0));

        $url = admin_url('admin.php?page=uapm-licenses');

        if (is_wp_error($result)) {
            $url = add_query_arg('uapm_error', rawurlencode($result->get_error_message()), $url);
        } else {
            $url = add_query_arg('uapm_success', 'revoked', $url);
        }

        wp_safe_redirect($url);
        exit;
    }

    public static function handle_delete() {
        if (!current_user_can('uapm_manage_payments')) {
            wp_die('Недостатньо прав.');
        }

        check_admin_referer('uapm_delete_license');

        $result = self::delete(absint($_POST['license_id'] ?? 0));

        $url = admin_url('admin.php?page=uapm-licenses');

        if (is_wp_error($result)) {
            $url = add_query_arg('uapm_error', rawurlencode($result->get_error_message()), $url);
        } else {
            $url = add_query_arg('uapm_success', 'deleted', $url);
        }

        wp_safe_redirect($url);
        exit;
    }

    public static function render_page() {
        if (!current_user_can('uapm_manage_payments')) {
            wp_die('Недостатньо прав.');
        }

        $projects = UAPM_Projects::get_all(array('limit' => 200));
        $licenses = self::get_all(array('limit' => 200));

        echo '<div class="wrap uapm-wrap">';
        echo '<h1>Ліцензії</h1>';

        if (!empty($_GET['uapm_error'])) {
            echo '<div class="notice notice-error"><p>' .
                esc_html(wp_unslash($_GET['uapm_error'])) .
                '</p></div>';
        }

        $new_key = get_transient('uapm_new_license_' . get_current_user_id());

        if ($new_key) {
            delete_transient('uapm_new_license_' . get_current_user_id());

            echo '<div class="notice notice-success"><p><strong>Новий ліцензійний ключ:</strong></p>';
            echo '<code>' . esc_html($new_key) . '</code>';
            echo '<p>Скопіюйте ключ зараз. Повторно він не відображатиметься.</p></div>';
        }

        echo '<div class="uapm-card">';
        echo '<h2>Створити ліцензію</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="uapm_create_license">';
        wp_nonce_field('uapm_create_license');

        echo '<p><label>Проєкт<br><select name="project_id" required>';
        echo '<option value="">Оберіть проєкт</option>';

        foreach ($projects as $project) {
            echo '<option value="' . esc_attr($project->id) . '">' .
                esc_html($project->name) .
                '</option>';
        }

        echo '</select></label></p>';

        echo '<p><label>Тип прив’язки<br><select name="target_type">';
        echo '<option value="domain">Домен сайту</option>';
        echo '<option value="email">Email користувача</option>';
        echo '</select></label></p>';

        echo '<p><input type="text" name="target_value" class="regular-text" placeholder="Домен або email" required></p>';
        echo '<p><input type="text" name="plan" value="pro" placeholder="Тариф"></p>';
        echo '<p><input type="number" name="max_sites" value="1" min="1" max="1000"></p>';
        echo '<p><label>Дата завершення (необов’язково)<br><input type="date" name="expires_at"></label></p>';

        submit_button('Створити ліцензію');
        echo '</form>';
        echo '</div>';

        echo '<div class="uapm-card">';
        echo '<h2>Список ліцензій</h2>';
        echo '<table class="widefat striped">';
        echo '<thead><tr><th>ID</th><th>Проєкт</th><th>Прив’язка</th><th>Тариф</th><th>Статус</th><th>Завершення</th><th>Дії</th></tr></thead><tbody>';

        if (!$licenses) {
            echo '<tr><td colspan="7">Ліцензій поки немає.</td></tr>';
        }

        foreach ($licenses as $license) {
            $project = UAPM_Projects::get($license->project_id);

            echo '<tr>';
            echo '<td>' . esc_html($license->id) . '</td>';
            echo '<td>' . esc_html($project ? $project->name : '—') . '</td>';
            echo '<td>' . esc_html($license->target_value) . '</td>';
            echo '<td>' . esc_html($license->plan) . '</td>';
            echo '<td>' . esc_html($license->status) . '</td>';
            echo '<td>' . esc_html($license->expires_at ?: 'Без обмеження') . '</td>';
            echo '<td>';

            if ($license->status === 'active') {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block">';
                echo '<input type="hidden" name="action" value="uapm_revoke_license">';
                echo '<input type="hidden" name="license_id" value="' . esc_attr($license->id) . '">';
                wp_nonce_field('uapm_revoke_license');
                echo '<button class="button">Деактивувати</button>';
                echo '</form> ';
            }

            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block" onsubmit="return confirm(\'Видалити ліцензію?\')">';
            echo '<input type="hidden" name="action" value="uapm_delete_license">';
            echo '<input type="hidden" name="license_id" value="' . esc_attr($license->id) . '">';
            wp_nonce_field('uapm_delete_license');
            echo '<button class="button button-link-delete">Видалити</button>';
            echo '</form>';

            echo '</td></tr>';
        }

        echo '</tbody></table>';
        echo '</div></div>';
    }
}