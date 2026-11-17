<?php
/**
 * UA Project Manager
 * Additional administration pages.
 *
 * @package UA_Project_Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

final class UAPM_Admin_Sections {

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'register_menu'), 30);
    }

    public static function register_menu() {

        add_submenu_page(
            'uapm',
            'Клієнти',
            'Клієнти',
            'uapm_manage_projects',
            'uapm-clients',
            array(__CLASS__, 'render_clients')
        );

        add_submenu_page(
            'uapm',
            'Інтеграції',
            'Інтеграції',
            'uapm_manage_integrations',
            'uapm-integrations',
            array(__CLASS__, 'render_integrations')
        );

        add_submenu_page(
            'uapm',
            'Завантаження',
            'Завантаження',
            'uapm_manage_projects',
            'uapm-downloads',
            array(__CLASS__, 'render_downloads')
        );

        add_submenu_page(
            'uapm',
            'Встановлення',
            'Встановлення',
            'uapm_manage_projects',
            'uapm-installations',
            array(__CLASS__, 'render_installations')
        );

        add_submenu_page(
            'uapm',
            'GitHub',
            'GitHub',
            'uapm_manage_settings',
            'uapm-github',
            array(__CLASS__, 'render_github')
        );

        add_submenu_page(
            'uapm',
            'Генератор коду',
            'Генератор коду',
            'uapm_manage_projects',
            'uapm-code-generator',
            array(__CLASS__, 'render_code_generator')
        );

        add_submenu_page(
            'uapm',
            'Перевірка коду',
            'Перевірка коду',
            'uapm_inspect_code',
            'uapm-code-inspector',
            array(__CLASS__, 'render_code_inspector')
        );

        add_submenu_page(
            'uapm',
            'Аналітика',
            'Аналітика',
            'uapm_manage_projects',
            'uapm-analytics',
            array(__CLASS__, 'render_analytics')
        );

        add_submenu_page(
            'uapm',
            'Налаштування',
            'Налаштування',
            'uapm_manage_settings',
            'uapm-settings',
            array(__CLASS__, 'render_settings')
        );
    }

    private static function allowed() {
        return current_user_can('manage_options') ||
            current_user_can('uapm_manage_projects');
    }

    public static function render_clients() {
        if (!self::allowed()) {
            wp_die('Недостатньо прав.');
        }

        if (!class_exists('UAPM_Clients')) {
            self::unavailable('Модуль клієнтів не завантажено.');
            return;
        }

        UAPM_Clients::render_page();
    }

    public static function render_integrations() {
        if (!current_user_can('uapm_manage_integrations') && !current_user_can('manage_options')) {
            wp_die('Недостатньо прав.');
        }

        if (class_exists('UAPM_Integrations')) {
            UAPM_Integrations::render_page();
            return;
        }

        self::unavailable('Модуль інтеграцій не завантажено.');
    }

    public static function render_downloads() {
        if (!self::allowed()) {
            wp_die('Недостатньо прав.');
        }

        if (class_exists('UAPM_Downloads')) {
            UAPM_Downloads::render_stats();
            return;
        }

        self::unavailable('Модуль завантажень не завантажено.');
    }

    public static function render_installations() {

        if (!self::allowed()) {
            wp_die('Недостатньо прав.');
        }

        if (!class_exists('UAPM_Installations')) {
            self::unavailable('Модуль встановлень не завантажено.');
            return;
        }

        $search = isset($_GET['s'])
            ? sanitize_text_field(wp_unslash($_GET['s']))
            : '';

        $status = isset($_GET['status'])
            ? sanitize_key(wp_unslash($_GET['status']))
            : '';

        $allowed_statuses = array(
            '',
            'active',
            'deactivated',
            'inactive',
        );

        if (!in_array($status, $allowed_statuses, true)) {
            $status = '';
        }

        $items = UAPM_Installations::get_all(array(
            'search' => $search,
            'status' => $status,
            'limit' => 500,
        ));

        echo '<div class="wrap">';
        echo '<h1>Встановлення проєктів</h1>';

        echo '<p>Усього встановлень: <strong>';
        echo esc_html(UAPM_Installations::count());
        echo '</strong> | Активних: <strong>';
        echo esc_html(UAPM_Installations::count(array('status' => 'active')));
        echo '</strong></p>';

        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '">';
        echo '<input type="hidden" name="page" value="uapm-installations">';

        echo '<input type="search" name="s" value="' . esc_attr($search) . '" placeholder="Пошук сайту або плагіна"> ';

        echo '<select name="status">';

        $labels = array(
            '' => 'Усі статуси',
            'active' => 'Активні',
            'deactivated' => 'Деактивовані',
            'inactive' => 'Неактивні',
        );

        foreach ($labels as $key => $label) {
            echo '<option value="' . esc_attr($key) . '" ' .
                selected($status, $key, false) . '>';
            echo esc_html($label);
            echo '</option>';
        }

        echo '</select> ';
        echo '<button class="button">Фільтрувати</button>';
        echo '</form>';

        echo '<table class="widefat striped" style="margin-top:15px">';
        echo '<thead><tr>';
        echo '<th>ID</th>';
        echo '<th>Сайт</th>';
        echo '<th>Проєкт</th>';
        echo '<th>Плагін</th>';
        echo '<th>Версія</th>';
        echo '<th>Статус</th>';
        echo '<th>Останній зв’язок</th>';
        echo '<th>Дія</th>';
        echo '</tr></thead><tbody>';

        if (empty($items)) {
            echo '<tr><td colspan="8">Встановлень поки немає.</td></tr>';
        }

        foreach ($items as $item) {

            $project = class_exists('UAPM_Projects')
                ? UAPM_Projects::get(absint($item->project_id))
                : null;

            echo '<tr>';

            echo '<td>' . esc_html($item->id) . '</td>';

            echo '<td><a href="' . esc_url($item->site_url) . '" target="_blank" rel="noopener noreferrer">';
            echo esc_html($item->site_name ? $item->site_name : $item->site_url);
            echo '</a><br><small>' . esc_html($item->site_url) . '</small></td>';

            echo '<td>' . esc_html($project ? $project->name : 'Проєкт #' . $item->project_id) . '</td>';

            echo '<td>' . esc_html($item->plugin_slug) . '</td>';
            echo '<td>' . esc_html($item->plugin_version) . '</td>';
            echo '<td>' . esc_html($item->status) . '</td>';
            echo '<td>' . esc_html($item->last_seen) . '</td>';

            echo '<td>';

            if (in_array($item->status, array('active', 'inactive'), true)) {

                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';

                echo '<input type="hidden" name="action" value="uapm_installation_action">';
                echo '<input type="hidden" name="installation_id" value="' . esc_attr($item->id) . '">';
                echo '<input type="hidden" name="installation_action" value="' .
                    esc_attr($item->status === 'active' ? 'deactivate' : 'activate') . '">';

                wp_nonce_field('uapm_installation_action');

                echo '<button class="button button-small">';
                echo esc_html($item->status === 'active' ? 'Деактивувати' : 'Активувати');
                echo '</button>';

                echo '</form>';
            }

            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    public static function render_github() {
        if (!self::allowed()) {
            wp_die('Недостатньо прав.');
        }

        if (class_exists('UAPM_GitHub')) {
            UAPM_GitHub::render_page();
            return;
        }

        self::unavailable('Модуль GitHub не завантажено.');
    }

    public static function render_code_generator() {

        if (!self::allowed()) {
            wp_die('Недостатньо прав.');
        }

        echo '<div class="wrap">';
        echo '<h1>Генератор коду</h1>';

        if (class_exists('UAPM_Code_Generator')) {
            UAPM_Code_Generator::render_form();
        } else {
            echo '<p>Модуль генератора коду не завантажено.</p>';
        }

        echo '</div>';
    }

    public static function render_code_inspector() {

        if (!self::allowed() && !current_user_can('uapm_inspect_code')) {
            wp_die('Недостатньо прав.');
        }

        if (class_exists('UAPM_Code_Inspector')) {
            UAPM_Code_Inspector::render_form();
            return;
        }

        self::unavailable('Модуль перевірки коду не завантажено.');
    }

    public static function render_analytics() {

        if (!self::allowed()) {
            wp_die('Недостатньо прав.');
        }

        if (class_exists('UAPM_Analytics')) {
            UAPM_Analytics::render_page();
            return;
        }

        self::unavailable('Модуль аналітики не завантажено.');
    }

    public static function render_settings() {

        if (
            !current_user_can('manage_options') &&
            !current_user_can('uapm_manage_settings')
        ) {
            wp_die('Недостатньо прав.');
        }

        if (class_exists('UAPM_Settings')) {
            UAPM_Settings::render_page();
            return;
        }

        self::unavailable('Модуль налаштувань не завантажено.');
    }

    private static function unavailable($message) {
        echo '<div class="wrap">';
        echo '<h1>UA Project Manager</h1>';
        echo '<div class="notice notice-error"><p>';
        echo esc_html($message);
        echo '</p></div>';
        echo '</div>';
    }
}

UAPM_Admin_Sections::init();
