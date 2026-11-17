<?php
if (!defined('ABSPATH')) {
    exit;
}

class UAPM_System_Health {

    public static function init() {
        add_action('admin_post_uapm_run_health_check', array(__CLASS__, 'handle_check'));
    }

    public static function run_checks() {
        global $wpdb;

        $checks = array();

        $checks[] = self::check_php_version();
        $checks[] = self::check_wordpress_version();
        $checks[] = self::check_database();
        $checks[] = self::check_uploads_directory();
        $checks[] = self::check_tables();
        $checks[] = self::check_cron();
        $checks[] = self::check_memory();
        $checks[] = self::check_ssl();
        $checks[] = self::check_rest_api();
        $checks[] = self::check_plugin_files();

        return $checks;
    }

    private static function result($name, $status, $message, $details = '') {
        return array(
            'name' => $name,
            'status' => $status,
            'message' => $message,
            'details' => $details,
        );
    }

    private static function check_php_version() {
        $version = PHP_VERSION;
        $ok = version_compare($version, '7.4', '>=');

        return self::result(
            'PHP',
            $ok ? 'success' : 'error',
            $ok ? 'Версія PHP сумісна.' : 'Потрібен PHP 7.4 або новіший.',
            'Поточна версія: ' . $version
        );
    }

    private static function check_wordpress_version() {
        global $wp_version;

        $ok = version_compare($wp_version, '6.0', '>=');

        return self::result(
            'WordPress',
            $ok ? 'success' : 'warning',
            $ok ? 'Версія WordPress підтримується.' : 'Рекомендується WordPress 6.0 або новіший.',
            'Поточна версія: ' . $wp_version
        );
    }

    private static function check_database() {
        global $wpdb;

        $version = $wpdb->db_version();
        $ok = !empty($version);

        return self::result(
            'База даних',
            $ok ? 'success' : 'error',
            $ok ? 'Підключення до бази даних працює.' : 'Не вдалося визначити версію бази даних.',
            'Версія: ' . $version
        );
    }

    private static function check_uploads_directory() {
        $uploads = wp_upload_dir();
        $path = $uploads['basedir'];

        $exists = is_dir($path);
        $writable = $exists && wp_is_writable($path);

        return self::result(
            'Каталог завантажень',
            $writable ? 'success' : 'error',
            $writable ? 'Каталог доступний для запису.' : 'Каталог завантажень недоступний для запису.',
            $path
        );
    }

    private static function check_tables() {
        global $wpdb;

        $required = array(
            'projects',
            'project_plans',
            'payments',
            'customers',
            'installations',
            'releases',
            'activity_log',
            'integrations',
        );

        $missing = array();

        foreach ($required as $table) {
            $name = $wpdb->prefix . 'uapm_' . $table;
            $found = $wpdb->get_var(
                $wpdb->prepare('SHOW TABLES LIKE %s', $name)
            );

            if ($found !== $name) {
                $missing[] = $table;
            }
        }

        return self::result(
            'Таблиці UPM',
            empty($missing) ? 'success' : 'error',
            empty($missing) ? 'Основні таблиці створені.' : 'Відсутні таблиці.',
            empty($missing) ? '' : implode(', ', $missing)
        );
    }

    private static function check_cron() {
        $scheduled = wp_next_scheduled('uapm_process_queue');

        return self::result(
            'WP-Cron',
            $scheduled ? 'success' : 'warning',
            $scheduled ? 'Обробник черги запланований.' : 'Обробник черги не запланований.',
            $scheduled ? wp_date('Y-m-d H:i:s', $scheduled) : 'Перевірте налаштування черги.'
        );
    }

    private static function check_memory() {
        $limit = ini_get('memory_limit');
        $bytes = wp_convert_hr_to_bytes($limit);
        $minimum = 128 * MB_IN_BYTES;

        return self::result(
            'Пам’ять PHP',
            $bytes >= $minimum ? 'success' : 'warning',
            $bytes >= $minimum ? 'Ліміт пам’яті достатній.' : 'Рекомендується щонайменше 128 МБ.',
            'Ліміт: ' . $limit
        );
    }

    private static function check_ssl() {
        $https = is_ssl();

        return self::result(
            'HTTPS',
            $https ? 'success' : 'warning',
            $https ? 'Захищене з’єднання активне.' : 'Сайт працює без HTTPS.',
            home_url()
        );
    }

    private static function check_rest_api() {
        $url = rest_url('uapm/v1/status');

        return self::result(
            'REST API',
            $url ? 'success' : 'warning',
            $url ? 'URL REST API сформовано.' : 'Не вдалося сформувати URL REST API.',
            $url
        );
    }

    private static function check_plugin_files() {
        $required = array(
            'ua-project-manager.php',
            'includes/class-uapm-installer.php',
            'includes/class-uapm-projects.php',
            'includes/class-uapm-payments.php',
            'includes/class-uapm-admin.php',
        );

        $missing = array();

        foreach ($required as $file) {
            if (!file_exists(UAPM_PATH . $file)) {
                $missing[] = $file;
            }
        }

        return self::result(
            'Файли плагіна',
            empty($missing) ? 'success' : 'error',
            empty($missing) ? 'Основні файли присутні.' : 'Відсутні необхідні файли.',
            empty($missing) ? '' : implode(', ', $missing)
        );
    }

    public static function handle_check() {
        if (!current_user_can('uapm_manage_settings')) {
            wp_die('Недостатньо прав.');
        }

        check_admin_referer('uapm_run_health_check');

        $results = self::run_checks();

        update_option('uapm_health_check_results', $results, false);
        update_option('uapm_health_check_time', current_time('mysql'), false);

        wp_safe_redirect(admin_url('admin.php?page=uapm-system-health&checked=1'));
        exit;
    }

    public static function render_page() {
        if (!current_user_can('uapm_manage_settings')) {
            wp_die('Недостатньо прав.');
        }

        $results = get_option('uapm_health_check_results', array());
        $checked_at = get_option('uapm_health_check_time', '');

        echo '<div class="wrap uapm-wrap">';
        echo '<h1>Стан системи</h1>';

        echo '<div class="uapm-card">';
        echo '<p>Перевірка сумісності, бази даних, файлів і системних служб.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="uapm_run_health_check">';
        wp_nonce_field('uapm_run_health_check');
        submit_button('Запустити перевірку', 'primary');
        echo '</form>';

        if ($checked_at) {
            echo '<p>Остання перевірка: <strong>' . esc_html($checked_at) . '</strong></p>';
        }

        echo '</div>';

        if (!$results) {
            echo '<div class="uapm-card"><p>Перевірку ще не запускали.</p></div>';
        } else {
            echo '<div class="uapm-card">';
            echo '<table class="widefat striped">';
            echo '<thead><tr><th>Компонент</th><th>Результат</th><th>Повідомлення</th><th>Деталі</th></tr></thead><tbody>';

            foreach ($results as $item) {
                $status = $item['status'] ?? 'warning';

                $label = array(
                    'success' => 'OK',
                    'warning' => 'Увага',
                    'error' => 'Помилка',
                );

                echo '<tr>';
                echo '<td><strong>' . esc_html($item['name'] ?? '') . '</strong></td>';
                echo '<td>' . esc_html($label[$status] ?? $status) . '</td>';
                echo '<td>' . esc_html($item['message'] ?? '') . '</td>';
                echo '<td>' . esc_html($item['details'] ?? '') . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
            echo '</div>';
        }

        echo '</div>';
    }
}