<?php
if (!defined('ABSPATH')) {
    exit;
}

class UAPM_Diagnostics {

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'uapm_diagnostics';
    }

    public static function init() {
        if (defined('UAPM_DB_VERSION') && get_option('uapm_modules_schema_ver') !== UAPM_DB_VERSION) {
            self::install_schema();
        }
        add_action('admin_post_uapm_run_diagnostics', array(__CLASS__, 'handle_run'));
        add_action('admin_post_uapm_clear_diagnostics', array(__CLASS__, 'handle_clear'));

        add_action('wp_ajax_uapm_diagnostics_status', array(__CLASS__, 'ajax_status'));
    }

    public static function install_schema() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE " . self::table() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            check_type varchar(100) NOT NULL,
            severity varchar(20) NOT NULL DEFAULT 'info',
            message text NOT NULL,
            details longtext NULL,
            source varchar(255) NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY check_type (check_type),
            KEY severity (severity),
            KEY created_at (created_at)
        ) {$charset};";

        dbDelta($sql);
    }

    public static function run() {
        global $wpdb;

        $results = array();

        $results = array_merge($results, self::check_database_tables());
        $results = array_merge($results, self::check_required_files());
        $results = array_merge($results, self::check_php_files());
        $results = array_merge($results, self::check_project_records());
        $results = array_merge($results, self::check_payment_records());
        $results = array_merge($results, self::check_queue());
        $results = array_merge($results, self::check_scheduled_events());

        foreach ($results as $result) {
            self::save_result($result);
        }

        update_option('uapm_diagnostics_last_run', current_time('mysql'), false);

        return $results;
    }

    private static function save_result($result) {
        global $wpdb;

        $wpdb->insert(
            self::table(),
            array(
                'check_type' => sanitize_key($result['type']),
                'severity' => sanitize_key($result['severity']),
                'message' => sanitize_text_field($result['message']),
                'details' => isset($result['details'])
                    ? wp_json_encode($result['details'])
                    : '',
                'source' => sanitize_text_field($result['source'] ?? ''),
                'created_at' => current_time('mysql'),
            )
        );
    }

    private static function result($type, $severity, $message, $details = array(), $source = '') {
        return array(
            'type' => $type,
            'severity' => $severity,
            'message' => $message,
            'details' => $details,
            'source' => $source,
        );
    }

    private static function check_database_tables() {
        global $wpdb;

        $required = array(
            'uapm_projects',
            'uapm_project_plans',
            'uapm_payments',
            'uapm_customers',
            'uapm_installations',
            'uapm_releases',
            'uapm_activity_log',
            'uapm_integrations',
            'uapm_notifications',
            'uapm_licenses',
            'uapm_license_sites',
        );

        $results = array();

        foreach ($required as $suffix) {
            $table = $wpdb->prefix . $suffix;
            $found = $wpdb->get_var(
                $wpdb->prepare('SHOW TABLES LIKE %s', $table)
            );

            $results[] = self::result(
                'database_table',
                $found === $table ? 'success' : 'error',
                $found === $table
                    ? 'Таблиця існує: ' . $suffix
                    : 'Відсутня таблиця: ' . $suffix,
                array('table' => $table),
                'database'
            );
        }

        return $results;
    }

    private static function check_required_files() {
        $files = array(
            'ua-project-manager.php',
            'includes/class-uapm-installer.php',
            'includes/class-uapm-projects.php',
            'includes/class-uapm-payments.php',
            'includes/class-uapm-admin.php',
            'includes/class-uapm-code-generator.php',
            'includes/class-uapm-code-inspector.php',
            'includes/class-uapm-repository.php',
            'includes/class-uapm-rest-api.php',
            'includes/class-uapm-settings.php',
        );

        $results = array();

        foreach ($files as $file) {
            $path = UAPM_PATH . $file;
            $exists = file_exists($path);

            $results[] = self::result(
                'plugin_file',
                $exists ? 'success' : 'error',
                $exists ? 'Файл присутній: ' . $file : 'Відсутній файл: ' . $file,
                array('file' => $file),
                'filesystem'
            );
        }

        return $results;
    }

    private static function check_php_files() {
        $directory = UAPM_PATH . 'includes/';

        if (!is_dir($directory)) {
            return array(
                self::result(
                    'php_syntax',
                    'error',
                    'Каталог includes не знайдено.',
                    array(),
                    'filesystem'
                ),
            );
        }

        $files = glob($directory . '*.php');
        $results = array();

        foreach ($files as $file) {
            $contents = file_get_contents($file);

            if ($contents === false) {
                continue;
            }

            $has_php_open = strpos($contents, '<?php') === 0;

            $results[] = self::result(
                'php_structure',
                $has_php_open ? 'success' : 'warning',
                $has_php_open
                    ? 'PHP-файл має коректний початок.'
                    : 'Перевірте початок PHP-файлу.',
                array(
                    'file' => basename($file),
                    'size' => filesize($file),
                ),
                'filesystem'
            );
        }

        return $results;
    }

    private static function check_project_records() {
        global $wpdb;

        $table = $wpdb->prefix . 'uapm_projects';

        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

        return array(
            self::result(
                'projects',
                'info',
                'Кількість проєктів: ' . $count,
                array('count' => $count),
                'database'
            ),
        );
    }

    private static function check_payment_records() {
        global $wpdb;

        $table = $wpdb->prefix . 'uapm_payments';

        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

        return array(
            self::result(
                'payments',
                'info',
                'Кількість платіжних записів: ' . $count,
                array('count' => $count),
                'database'
            ),
        );
    }

    private static function check_queue() {
        global $wpdb;

        $table = $wpdb->prefix . 'uapm_queue';

        $exists = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $table)
        );

        if ($exists !== $table) {
            return array(
                self::result(
                    'queue',
                    'warning',
                    'Таблиця черги ще не створена.',
                    array(),
                    'database'
                ),
            );
        }

        $failed = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} WHERE status = 'failed'"
        );

        return array(
            self::result(
                'queue',
                $failed > 0 ? 'warning' : 'success',
                $failed > 0
                    ? 'У черзі є завдання з помилками: ' . $failed
                    : 'Черга не містить завдань з помилками.',
                array('failed' => $failed),
                'database'
            ),
        );
    }

    private static function check_scheduled_events() {
        $events = array(
            'uapm_process_queue',
        );

        $results = array();

        foreach ($events as $event) {
            $scheduled = wp_next_scheduled($event);

            $results[] = self::result(
                'scheduled_event',
                $scheduled ? 'success' : 'warning',
                $scheduled
                    ? 'Подію заплановано: ' . $event
                    : 'Подію не заплановано: ' . $event,
                array('timestamp' => $scheduled ?: null),
                'wp_cron'
            );
        }

        return $results;
    }

    public static function get_results($limit = 200) {
        global $wpdb;

        $limit = max(1, min(1000, absint($limit)));

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . self::table() . "
                ORDER BY id DESC LIMIT %d",
                $limit
            )
        );
    }

    public static function get_summary() {
        global $wpdb;

        $table = self::table();

        return array(
            'success' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$table} WHERE severity = 'success'"
            ),
            'warning' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$table} WHERE severity = 'warning'"
            ),
            'error' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$table} WHERE severity = 'error'"
            ),
            'info' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$table} WHERE severity = 'info'"
            ),
        );
    }

    public static function handle_run() {
        if (!current_user_can('uapm_manage_settings')) {
            wp_die('Недостатньо прав.');
        }

        check_admin_referer('uapm_run_diagnostics');

        self::run();

        wp_safe_redirect(admin_url('admin.php?page=uapm-diagnostics&checked=1'));
        exit;
    }

    public static function handle_clear() {
        if (!current_user_can('uapm_manage_settings')) {
            wp_die('Недостатньо прав.');
        }

        check_admin_referer('uapm_clear_diagnostics');

        global $wpdb;

        $wpdb->query('TRUNCATE TABLE ' . self::table());

        delete_option('uapm_diagnostics_last_run');

        wp_safe_redirect(admin_url('admin.php?page=uapm-diagnostics&cleared=1'));
        exit;
    }

    public static function ajax_status() {
        if (!current_user_can('uapm_manage_settings')) {
            wp_send_json_error(array('message' => 'Недостатньо прав.'), 403);
        }

        check_ajax_referer('uapm_diagnostics_status', 'nonce');

        wp_send_json_success(array(
            'summary' => self::get_summary(),
            'last_run' => get_option('uapm_diagnostics_last_run', ''),
        ));
    }

    public static function render_page() {
        if (!current_user_can('uapm_manage_settings')) {
            wp_die('Недостатньо прав.');
        }

        $results = self::get_results(300);
        $summary = self::get_summary();
        $last_run = get_option('uapm_diagnostics_last_run', '');

        echo '<div class="wrap uapm-wrap">';
        echo '<h1>Діагностика системи</h1>';

        echo '<div class="uapm-stats">';

        foreach (array(
            'success' => 'Успішно',
            'warning' => 'Попередження',
            'error' => 'Помилки',
            'info' => 'Інформація',
        ) as $key => $label) {
            echo '<div class="uapm-card">';
            echo '<strong>' . esc_html($summary[$key]) . '</strong>';
            echo '<span>' . esc_html($label) . '</span>';
            echo '</div>';
        }

        echo '</div>';

        echo '<div class="uapm-card">';
        echo '<p>Останній запуск: ' . esc_html($last_run ?: 'Ще не запускалася') . '</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:8px">';
        echo '<input type="hidden" name="action" value="uapm_run_diagnostics">';
        wp_nonce_field('uapm_run_diagnostics');
        submit_button('Запустити діагностику', 'primary', 'submit', false);
        echo '</form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block" onsubmit="return confirm(\'Очистити результати діагностики?\')">';
        echo '<input type="hidden" name="action" value="uapm_clear_diagnostics">';
        wp_nonce_field('uapm_clear_diagnostics');
        submit_button('Очистити журнал', 'delete', 'submit', false);
        echo '</form>';
        echo '</div>';

        echo '<div class="uapm-card">';
        echo '<table class="widefat striped">';
        echo '<thead><tr><th>Дата</th><th>Перевірка</th><th>Рівень</th><th>Повідомлення</th><th>Деталі</th></tr></thead><tbody>';

        if (!$results) {
            echo '<tr><td colspan="5">Результатів поки немає.</td></tr>';
        }

        foreach ($results as $item) {
            $details = json_decode($item->details, true);

            echo '<tr>';
            echo '<td>' . esc_html($item->created_at) . '</td>';
            echo '<td>' . esc_html($item->check_type) . '</td>';
            echo '<td>' . esc_html($item->severity) . '</td>';
            echo '<td>' . esc_html($item->message) . '</td>';
            echo '<td><code>' . esc_html(wp_json_encode($details, JSON_UNESCAPED_UNICODE)) . '</code></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div></div>';
    }
}