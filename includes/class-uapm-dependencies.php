<?php
if (!defined('ABSPATH')) {
    exit;
}

class UAPM_Dependencies {

    public static function init() {
        add_action('admin_notices', array(__CLASS__, 'show_notices'));
        add_action('admin_menu', array(__CLASS__, 'register_menu'), 110);
        add_action('admin_menu', array(__CLASS__, 'register_module_menus'), 111);
        add_action('admin_post_uapm_check_dependencies', array(__CLASS__, 'handle_check'));
    }

    public static function get_requirements() {
        global $wpdb;

        $uploads = wp_upload_dir();
        $mysql_version = 'Невідомо';

        if (isset($wpdb) && method_exists($wpdb, 'db_version')) {
            $mysql_version = $wpdb->db_version();
        }

        $wp_version = get_bloginfo('version');

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
                'current' => $wp_version,
                'ok' => version_compare($wp_version, '6.0', '>='),
            ),
            'mysql' => array(
                'label' => 'MySQL',
                'required' => '5.7',
                'current' => $mysql_version,
                'ok' => $mysql_version !== 'Невідомо'
                    && version_compare($mysql_version, '5.7', '>='),
            ),
            'https' => array(
                'label' => 'HTTPS',
                'required' => 'Увімкнено',
                'current' => is_ssl() ? 'Увімкнено' : 'Вимкнено',
                'ok' => is_ssl(),
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
                'current' => !empty($uploads['basedir']) && wp_is_writable($uploads['basedir'])
                    ? 'Доступна'
                    : 'Недоступна',
                'ok' => !empty($uploads['basedir']) && wp_is_writable($uploads['basedir']),
            ),
        );
    }

    public static function check_modules() {
        $modules = array(
            'UAPM_Installer' => 'Інсталятор',
            'UAPM_Security' => 'Безпека',
            'UAPM_Roles' => 'Ролі користувачів',
            'UAPM_Settings' => 'Налаштування',
            'UAPM_Projects' => 'Проєкти',
            'UAPM_Advertising' => 'Реклама',
            'UAPM_Referrals' => 'Реферальна система',
            'UAPM_Customers' => 'Замовники',
            'UAPM_Clients' => 'Клієнти',
            'UAPM_Payments' => 'Платежі',
            'UAPM_License_Manager' => 'Ліцензії',
            'UAPM_License_Sites' => 'Сайти ліцензій',
            'UAPM_GitHub' => 'Репозиторії та релізи',
            'UAPM_Updater' => 'Оновлення',
            'UAPM_Downloads' => 'Завантаження',
            'UAPM_Installations' => 'Встановлення',
            'UAPM_Integrations' => 'Інтеграції',
            'UAPM_Notifications' => 'Сповіщення',
            'UAPM_Email_Templates' => 'Шаблони листів',
            'UAPM_Queue' => 'Черга завдань',
            'UAPM_Webhooks' => 'Webhook',
            'UAPM_Code_Generator' => 'Генератор коду',
            'UAPM_Code_Inspector' => 'Перевірка коду',
            'UAPM_Analytics' => 'Аналітика',
            'UAPM_Reports' => 'Звіти',
            'UAPM_Mailings' => 'Розсилки',
            'UAPM_Buttons' => 'Кнопки',
            'UAPM_API' => 'API',
            'UAPM_Activity_Log' => 'Журнал активності',
            'UAPM_Activity_Monitor' => 'Монітор активності',
            'UAPM_Activity_Feed' => 'Стрічка активності',
            'UAPM_Audit' => 'Аудит',
            'UAPM_Logs' => 'Системні журнали',
            'UAPM_Privacy' => 'Конфіденційність',
            'UAPM_Backups' => 'Резервні копії',
            'UAPM_Export' => 'Експорт',
            'UAPM_Import' => 'Імпорт',
            'UAPM_Diagnostics' => 'Діагностика',
            'UAPM_System_Health' => 'Стан системи',
            'UAPM_Dashboard_Widgets' => 'Віджети панелі',
            'UAPM_Localization' => 'Локалізація',
            'UAPM_Search' => 'Пошук',
            'UAPM_Notes' => 'Нотатки',
            'UAPM_Tasks' => 'Завдання',
            'UAPM_Comments' => 'Коментарі',
            'UAPM_Attachments' => 'Файли',
            'UAPM_Activity_Calendar' => 'Календар',
            'UAPM_Project_Activity' => 'Активність проєктів',
            'UAPM_Milestones' => 'Етапи',
            'UAPM_Dependencies' => 'Перевірка залежностей',
            'UAPM_Admin' => 'Адміністративна панель',
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
            echo '<strong>UA Project Manager:</strong> є невиконані системні вимоги. ';
            echo '<a href="' . esc_url(admin_url('admin.php?page=uapm-dependencies')) . '">Переглянути перевірку</a>.';
            echo '</p></div>';
        }

        if ($report['modules_loaded'] < $report['modules_total']) {
            echo '<div class="notice notice-error"><p>';
            echo '<strong>UA Project Manager:</strong> не всі внутрішні модулі завантажені. ';
            echo '<a href="' . esc_url(admin_url('admin.php?page=uapm-dependencies')) . '">Переглянути список модулів</a>.';
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

    public static function register_module_menus() {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (class_exists('UAPM_Integrations')) {
            add_submenu_page(
                'uapm',
                'Інтеграції',
                'Інтеграції',
                'manage_options',
                'uapm-integrations',
                array('UAPM_Integrations', 'render_page')
            );
        }

        if (class_exists('UAPM_GitHub')) {
            add_submenu_page(
                'uapm',
                'GitHub та релізи',
                'GitHub та релізи',
                'manage_options',
                'uapm-github',
                array('UAPM_GitHub', 'render_page')
            );
        }
    }

    public static function handle_check() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостатньо прав.');
        }

        check_admin_referer('uapm_check_dependencies');

        $report = self::get_report();

        update_option('uapm_dependencies_last_report', $report, false);

        wp_safe_redirect(
            add_query_arg(
                'uapm_checked',
                '1',
                admin_url('admin.php?page=uapm-dependencies')
            )
        );

        exit;
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостатньо прав.');
        }

        $report = self::get_report();

        echo '<div class="wrap">';
        echo '<h1>Залежності UA Project Manager</h1>';
        echo '<p>Перевірка системних вимог і внутрішніх модулів плагіна.</p>';

        if (isset($_GET['uapm_checked'])) {
            echo '<div class="notice notice-success"><p>Перевірку завершено.</p></div>';
        }

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
                echo '<strong style="color:green">OK</strong>';
            } else {
                echo '<strong style="color:#b32d2e">Проблема</strong>';
            }

            echo '</td></tr>';
        }

        echo '</tbody></table>';

        echo '<h2>Внутрішні модулі</h2>';
        echo '<p>Завантажено: <strong>' . esc_html($report['modules_loaded']) . '</strong> із ' . esc_html($report['modules_total']) . '</p>';

        echo '<table class="widefat striped">';
        echo '<thead><tr><th>Модуль</th><th>Клас</th><th>Стан</th></tr></thead><tbody>';

        foreach ($report['modules'] as $module) {
            echo '<tr>';
            echo '<td>' . esc_html($module['label']) . '</td>';
            echo '<td><code>' . esc_html($module['class']) . '</code></td>';
            echo '<td>';

            if ($module['loaded']) {
                echo '<strong style="color:green">Завантажено</strong>';
            } else {
                echo '<strong style="color:#b32d2e">Не завантажено</strong>';
            }

            echo '</td></tr>';
        }

        echo '</tbody></table>';

        echo '<p><strong>Загальний стан:</strong> ';
        echo $report['ready']
            ? '<span style="color:green">Усі перевірки пройдено</span>'
            : '<span style="color:#b32d2e">Потрібне виправлення</span>';
        echo '</p>';

        echo '<p>Остання перевірка: ' . esc_html($report['checked_at']) . '</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="uapm_check_dependencies">';
        wp_nonce_field('uapm_check_dependencies');
        echo '<button type="submit" class="button button-primary">Перевірити ще раз</button>';
        echo '</form>';

        echo '</div>';
    }
}

UAPM_Dependencies::init();
