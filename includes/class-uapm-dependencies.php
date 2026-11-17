<?php
if (!defined('ABSPATH')) {
    exit;
}

class UAPM_Dependencies {

    public static function init() {
        add_action('admin_notices', array(__CLASS__, 'show_notices'));
        add_action('admin_menu', array(__CLASS__, 'register_menu'), 110);
        add_action('admin_post_uapm_check_dependencies', array(__CLASS__, 'handle_check'));
    }

    public static function get_requirements() {
        return array(
            'php' => array(
                'label' => 'PHP',
                'required' => '7.4',
                'current' => PHP_VERSION,
                'ok' => version_compare(PHP_VERSION, '7.4', '>='),
            ),
            'wordpress' => array(
                'label' => 'WordPress',
                'required' => '6.0',
                'current' => get_bloginfo('version'),
                'ok' => version_compare(get_bloginfo('version'), '6.0', '>='),
            ),
            'mysql' => array(
                'label' => 'MySQL',
                'required' => '5.7',
                'current' => self::get_database_version(),
                'ok' => version_compare(self::get_database_version(), '5.7', '>='),
            ),
            'https' => array(
                'label' => 'HTTPS',
                'required' => 'Увімкнено',
                'current' => is_ssl() ? 'Увімкнено' : 'Вимкнено',
                'ok' => is_ssl(),
            ),
            'curl' => array(
                'label' => 'cURL',
                'required' => 'Увімкнено',
                'current' => function_exists('curl_init') ? 'Увімкнено' : 'Вимкнено',
                'ok' => function_exists('curl_init'),
            ),
            'json' => array(
                'label' => 'JSON',
                'required' => 'Увімкнено',
                'current' => function_exists('json_encode') ? 'Увімкнено' : 'Вимкнено',
                'ok' => function_exists('json_encode'),
            ),
            'zip' => array(
                'label' => 'ZIP',
                'required' => 'Увімкнено',
                'current' => class_exists('ZipArchive') ? 'Увімкнено' : 'Вимкнено',
                'ok' => class_exists('ZipArchive'),
            ),
            'uploads' => array(
                'label' => 'Папка завантажень',
                'required' => 'Доступна для запису',
                'current' => wp_is_writable(wp_upload_dir()['basedir'])
                    ? 'Доступна'
                    : 'Недоступна',
                'ok' => wp_is_writable(wp_upload_dir()['basedir']),
            ),
        );
    }

    private static function get_database_version() {
        global $wpdb;

        return isset($wpdb->db_version)
            ? $wpdb->db_version()
            : '0';
    }

    public static function check_modules() {
        $modules = array(
            'UAPM_Installer' => 'Інсталятор',
            'UAPM_Projects' => 'Проєкти',
            'UAPM_Payments' => 'Платежі',
            'UAPM_Admin' => 'Адміністративна панель',
            'UAPM_Code_Generator' => 'Генератор коду',
            'UAPM_Code_Inspector' => 'Перевірка коду',
            'UAPM_GitHub' => 'Репозиторії та релізи',
            'UAPM_REST_API' => 'REST API',
            'UAPM_Downloads' => 'Завантаження',
            'UAPM_Integrations' => 'Інтеграції',
            'UAPM_Notifications' => 'Сповіщення',
            'UAPM_Clients' => 'Клієнти',
            'UAPM_Installations' => 'Встановлення',
            'UAPM_Analytics' => 'Аналітика',
            'UAPM_Mailings' => 'Розсилки',
            'UAPM_Buttons' => 'Кнопки',
            'UAPM_API' => 'API',
            'UAPM_Settings' => 'Налаштування',
            'UAPM_Activity_Log' => 'Журнал активності',
            'UAPM_Updater' => 'Оновлення',
            'UAPM_Backups' => 'Резервні копії',
            'UAPM_Customers' => 'Замовники',
            'UAPM_License_Manager' => 'Ліцензії',
            'UAPM_License_Sites' => 'Сайти ліцензій',
            'UAPM_Webhooks' => 'Webhook',
            'UAPM_Queue' => 'Черга завдань',
            'UAPM_Reports' => 'Звіти',
            'UAPM_Localization' => 'Локалізація',
            'UAPM_Activity_Monitor' => 'Монітор активності',
            'UAPM_System_Health' => 'Стан системи',
            'UAPM_Diagnostics' => 'Діагностика',
            'UAPM_Export' => 'Експорт',
            'UAPM_Import' => 'Імпорт',
            'UAPM_Audit' => 'Аудит',
            'UAPM_Logs' => 'Системні журнали',
            'UAPM_Privacy' => 'Конфіденційність',
            'UAPM_Roles' => 'Ролі користувачів',
            'UAPM_Dashboard_Widgets' => 'Віджети панелі',
            'UAPM_Security' => 'Безпека',
            'UAPM_Email_Templates' => 'Шаблони листів',
            'UAPM_Cron' => 'Планувальник',
            'UAPM_Activity_Feed' => 'Стрічка активності',
            'UAPM_Search' => 'Пошук',
            'UAPM_Notes' => 'Нотатки',
            'UAPM_Tasks' => 'Завдання',
            'UAPM_Comments' => 'Коментарі',
            'UAPM_Attachments' => 'Файли',
            'UAPM_Activity_Calendar' => 'Календар',
            'UAPM_Project_Activity' => 'Активність проєктів',
            'UAPM_Milestones' => 'Етапи',
        );

        $result = array();

        foreach ($modules as $class => $label) {
            $result[] = array(
                'class' => $class,
                'label' => $label,
                'loaded' => class_exists($class),
            );
        }

        return $result;
    }

    public static function get_report() {
        $requirements = self::get_requirements();
        $modules = self::check_modules();

        $requirements_ok = 0;
        foreach ($requirements as $item) {
            if ($item['ok']) {
                $requirements_ok++;
            }
        }

        $modules_loaded = 0;
        foreach ($modules as $module) {
            if ($module['loaded']) {
                $modules_loaded++;
            }
        }

        return array(
            'requirements' => $requirements,
            'modules' => $modules,
            'requirements_ok' => $requirements_ok,
            'requirements_total' => count($requirements),
            'modules_loaded' => $modules_loaded,
            'modules_total' => count($modules),
            'ready' => $requirements_ok === count($requirements)
                && $modules_loaded === count($modules),
            'checked_at' => current_time('mysql'),
        );
    }

    public static function show_notices() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $report = self::get_report();

        if ($report['requirements_ok'] < $report['requirements_total']) {
            echo '<div class="notice notice-warning"><p>';
            echo '<strong>UA Project Manager:</strong> виявлено невиконані системні вимоги. ';
            echo '<a href="' . esc_url(admin_url('admin.php?page=uapm-dependencies')) . '">Переглянути перевірку</a>.';
            echo '</p></div>';
        }
    }

    public static function register_menu() {
        add_submenu_page(
            'uapm',
            'Залежності системи',
            'Залежності',
            'manage_options',
            'uapm-dependencies',
            array(__CLASS__, 'render_page')
        );
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостатньо прав.');
        }

        $report = self::get_report();

        echo '<div class="wrap">';
        echo '<h1>Системні залежності UA Project Manager</h1>';

        echo '<p>Перевірка середовища WordPress, серверних компонентів та підключених модулів.</p>';

        echo '<h2>Системні вимоги</h2>';
        echo '<table class="widefat striped">';
        echo '<thead><tr><th>Компонент</th><th>Потрібно</th><th>Зараз</th><th>Результат</th></tr></thead><tbody>';

        foreach ($report['requirements'] as $item) {
            echo '<tr>';
            echo '<td>' . esc_html($item['label']) . '</td>';
            echo '<td>' . esc_html($item['required']) . '</td>';
            echo '<td>' . esc_html($item['current']) . '</td>';
            echo '<td>';

            if ($item['ok']) {
                echo '<span style="color:green;font-weight:bold">OK</span>';
            } else {
                echo '<span style="color:#b32d2e;font-weight:bold">Проблема</span>';
            }

            echo '</td></tr>';
        }

        echo '</tbody></table>';

        echo '<h2>Підключені модулі</h2>';
        echo '<p>Завантажено: <strong>' . esc_html($report['modules_loaded']) . '</strong> із ' . esc_html($report['modules_total']) . '</p>';

        echo '<table class="widefat striped">';
        echo '<thead><tr><th>Модуль</th><th>Клас</th><th>Стан</th></tr></thead><tbody>';

        foreach ($report['modules'] as $module) {
            echo '<tr>';
            echo '<td>' . esc_html($module['label']) . '</td>';
            echo '<td><code>' . esc_html($module['class']) . '</code></td>';
            echo '<td>';

            if ($module['loaded']) {
                echo '<span style="color:green">Завантажено</span>';
            } else {
                echo '<span style="color:#b32d2e">Не завантажено</span>';
            }

            echo '</td></tr>';
        }

        echo '</tbody></table>';

        echo '<h2>Підсумок</h2>';

        if ($report['ready']) {
            echo '<div class="notice notice-success inline"><p>Усі перевірені вимоги виконані, усі модулі завантажені.</p></div>';
        } else {
            echo '<div class="notice notice-warning inline"><p>Система ще не готова до повноцінної роботи. Перевірте відсутні компоненти та системні вимоги.</p></div>';
        }

        echo '<p>Остання перевірка: ' . esc_html($report['checked_at']) . '</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="uapm_check_dependencies">';
        wp_nonce_field('uapm_check_dependencies');
        submit_button('Повторити перевірку');
        echo '</form>';

        echo '</div>';
    }

    public static function handle_check() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостатньо прав.');
        }

        check_admin_referer('uapm_check_dependencies');

        $report = self::get_report();

        update_option('uapm_dependencies_last_report', $report, false);

        wp_safe_redirect(admin_url('admin.php?page=uapm-dependencies&checked=1'));
        exit;
    }
}