<?php
if (!defined('ABSPATH')) {
    exit;
}

class UAPM_License_Sites {

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'uapm_license_sites';
    }

    public static function init() {
        self::install_schema();

        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
        add_action('admin_post_uapm_remove_license_site', array(__CLASS__, 'handle_remove'));
    }

    public static function install_schema() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            license_id bigint(20) unsigned NOT NULL,
            project_id bigint(20) unsigned NOT NULL,
            site_url varchar(255) NOT NULL,
            site_hash char(64) NOT NULL,
            site_name varchar(190) NOT NULL DEFAULT '',
            status varchar(30) NOT NULL DEFAULT 'active',
            last_check datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY license_site (license_id,site_hash),
            KEY project_id (project_id),
            KEY status (status)
        ) {$charset};";

        dbDelta($sql);
    }

    private static function normalize_site($url) {
        $url = esc_url_raw(trim((string) $url));

        if (!$url) {
            return '';
        }

        $parts = wp_parse_url($url);

        if (empty($parts['host'])) {
            return '';
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');

        if (!in_array($scheme, array('http', 'https'), true)) {
            return '';
        }

        $host = strtolower($parts['host']);
        $host = preg_replace('/^www\./', '', $host);

        return $scheme . '://' . $host;
    }

    public static function register_site($license_key, $project_id, $site_url, $site_name = '') {
        global $wpdb;

        $license = UAPM_License_Manager::validate(
            $license_key,
            $project_id
        );

        if (is_wp_error($license)) {
            return $license;
        }

        $site_url = self::normalize_site($site_url);

        if (!$site_url) {
            return new WP_Error('invalid_site', 'Некоректна адреса сайту.');
        }

        $license_record = UAPM_License_Manager::get($license['license_id']);

        if (!$license_record) {
            return new WP_Error('license_not_found', 'Ліцензію не знайдено.');
        }

        $site_hash = hash('sha256', $site_url);
        $table = self::table();

        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE license_id = %d AND site_hash = %s LIMIT 1",
                $license['license_id'],
                $site_hash
            )
        );

        if (!$existing) {
            $count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE license_id = %d AND status = 'active'",
                    $license['license_id']
                )
            );

            if ($count >= (int) $license_record->max_sites) {
                return new WP_Error(
                    'site_limit_reached',
                    'Досягнуто ліміт сайтів для цієї ліцензії.'
                );
            }

            $now = current_time('mysql');

            $inserted = $wpdb->insert(
                $table,
                array(
                    'license_id' => $license['license_id'],
                    'project_id' => absint($project_id),
                    'site_url' => $site_url,
                    'site_hash' => $site_hash,
                    'site_name' => sanitize_text_field($site_name),
                    'status' => 'active',
                    'last_check' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                )
            );

            if (!$inserted) {
                return new WP_Error('site_register_failed', 'Не вдалося зареєструвати сайт.');
            }

            $site_id = (int) $wpdb->insert_id;

            do_action('uapm_license_site_registered', $site_id, $license['license_id']);

            return array(
                'registered' => true,
                'site_id' => $site_id,
                'license_id' => $license['license_id'],
                'plan' => $license['plan'],
            );
        }

        if ($existing->status !== 'active') {
            return new WP_Error('site_inactive', 'Цей сайт деактивовано в ліцензії.');
        }

        $wpdb->update(
            $table,
            array(
                'site_name' => sanitize_text_field($site_name),
                'last_check' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ),
            array('id' => $existing->id)
        );

        return array(
            'registered' => true,
            'site_id' => (int) $existing->id,
            'license_id' => $license['license_id'],
            'plan' => $license['plan'],
        );
    }

    public static function check_site($license_key, $project_id, $site_url) {
        global $wpdb;

        $license = UAPM_License_Manager::validate(
            $license_key,
            $project_id
        );

        if (is_wp_error($license)) {
            return $license;
        }

        $site_url = self::normalize_site($site_url);

        if (!$site_url) {
            return new WP_Error('invalid_site', 'Некоректна адреса сайту.');
        }

        $site_hash = hash('sha256', $site_url);

        $site = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM " . self::table() . "
                WHERE license_id = %d AND site_hash = %s AND status = 'active'
                LIMIT 1",
                $license['license_id'],
                $site_hash
            )
        );

        if (!$site) {
            return new WP_Error('site_not_registered', 'Сайт не зареєстровано для цієї ліцензії.');
        }

        $wpdb->update(
            self::table(),
            array(
                'last_check' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ),
            array('id' => $site->id)
        );

        return array(
            'valid' => true,
            'license_id' => $license['license_id'],
            'site_id' => (int) $site->id,
            'plan' => $license['plan'],
            'expires_at' => $license['expires_at'],
        );
    }

    public static function get_all($license_id = 0) {
        global $wpdb;

        $table = self::table();

        if ($license_id) {
            return $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE license_id = %d ORDER BY id DESC",
                    absint($license_id)
                )
            );
        }

        return $wpdb->get_results(
            "SELECT * FROM {$table} ORDER BY id DESC LIMIT 500"
        );
    }

    public static function deactivate($site_id) {
        global $wpdb;

        $site_id = absint($site_id);

        $site = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM " . self::table() . " WHERE id = %d",
                $site_id
            )
        );

        if (!$site) {
            return new WP_Error('site_not_found', 'Сайт не знайдено.');
        }

        $updated = $wpdb->update(
            self::table(),
            array(
                'status' => 'inactive',
                'updated_at' => current_time('mysql'),
            ),
            array('id' => $site_id)
        );

        if ($updated === false) {
            return new WP_Error('site_deactivate_failed', 'Не вдалося деактивувати сайт.');
        }

        do_action('uapm_license_site_deactivated', $site_id, $site->license_id);

        return true;
    }

    public static function register_routes() {
        register_rest_route('uapm/v1', '/license/site/register', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'rest_register'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('uapm/v1', '/license/site/check', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'rest_check'),
            'permission_callback' => '__return_true',
        ));
    }

    public static function rest_register($request) {
        $result = self::register_site(
            $request->get_param('license_key'),
            absint($request->get_param('project_id')),
            $request->get_param('site_url'),
            $request->get_param('site_name')
        );

        if (is_wp_error($result)) {
            return new WP_REST_Response(array(
                'registered' => false,
                'code' => $result->get_error_code(),
                'message' => $result->get_error_message(),
            ), 400);
        }

        return rest_ensure_response($result);
    }

    public static function rest_check($request) {
        $result = self::check_site(
            $request->get_param('license_key'),
            absint($request->get_param('project_id')),
            $request->get_param('site_url')
        );

        if (is_wp_error($result)) {
            return new WP_REST_Response(array(
                'valid' => false,
                'code' => $result->get_error_code(),
                'message' => $result->get_error_message(),
            ), 200);
        }

        return rest_ensure_response($result);
    }

    public static function handle_remove() {
        if (!current_user_can('uapm_manage_payments')) {
            wp_die('Недостатньо прав.');
        }

        check_admin_referer('uapm_remove_license_site');

        $result = self::deactivate(absint($_POST['site_id'] ?? 0));

        $url = admin_url('admin.php?page=uapm-license-sites');

        if (is_wp_error($result)) {
            $url = add_query_arg(
                'uapm_error',
                rawurlencode($result->get_error_message()),
                $url
            );
        } else {
            $url = add_query_arg('uapm_success', 'deactivated', $url);
        }

        wp_safe_redirect($url);
        exit;
    }

    public static function render_page() {
        if (!current_user_can('uapm_manage_payments')) {
            wp_die('Недостатньо прав.');
        }

        $sites = self::get_all();

        echo '<div class="wrap uapm-wrap">';
        echo '<h1>Сайти, прив’язані до ліцензій</h1>';

        if (!empty($_GET['uapm_error'])) {
            echo '<div class="notice notice-error"><p>' .
                esc_html(wp_unslash($_GET['uapm_error'])) .
                '</p></div>';
        }

        echo '<div class="uapm-card">';
        echo '<table class="widefat striped">';
        echo '<thead><tr><th>Сайт</th><th>Назва</th><th>Ліцензія</th><th>Статус</th><th>Остання перевірка</th><th>Дія</th></tr></thead><tbody>';

        if (!$sites) {
            echo '<tr><td colspan="6">Зареєстрованих сайтів немає.</td></tr>';
        }

        foreach ($sites as $site) {
            echo '<tr>';
            echo '<td><a href="' . esc_url($site->site_url) . '" target="_blank" rel="noopener noreferrer">' .
                esc_html($site->site_url) .
                '</a></td>';
            echo '<td>' . esc_html($site->site_name) . '</td>';
            echo '<td>#' . esc_html($site->license_id) . '</td>';
            echo '<td>' . esc_html($site->status) . '</td>';
            echo '<td>' . esc_html($site->last_check ?: '—') . '</td>';
            echo '<td>';

            if ($site->status === 'active') {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Деактивувати сайт у цій ліцензії?\')">';
                echo '<input type="hidden" name="action" value="uapm_remove_license_site">';
                echo '<input type="hidden" name="site_id" value="' . esc_attr($site->id) . '">';
                wp_nonce_field('uapm_remove_license_site');
                echo '<button class="button">Деактивувати</button>';
                echo '</form>';
            } else {
                echo '—';
            }

            echo '</td></tr>';
        }

        echo '</tbody></table>';
        echo '</div></div>';
    }
}