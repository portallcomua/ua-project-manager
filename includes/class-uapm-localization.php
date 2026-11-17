<?php
if (!defined('ABSPATH')) {
    exit;
}

class UAPM_Localization {

    private static $strings = array(
        'uk' => array(
            'dashboard' => 'Головна',
            'projects' => 'Проєкти',
            'plans' => 'Тарифи',
            'payments' => 'Платежі',
            'customers' => 'Клієнти',
            'installations' => 'Встановлення',
            'downloads' => 'Завантаження',
            'integrations' => 'Інтеграції',
            'github' => 'Репозиторії',
            'inspector' => 'Перевірка коду',
            'analytics' => 'Аналітика',
            'reports' => 'Звіти',
            'settings' => 'Налаштування',
            'notifications' => 'Сповіщення',
            'mailings' => 'Розсилки',
            'buttons' => 'Кнопки',
            'licenses' => 'Ліцензії',
            'webhooks' => 'Webhooks',
            'queue' => 'Черга завдань',
            'save' => 'Зберегти',
            'cancel' => 'Скасувати',
            'delete' => 'Видалити',
            'edit' => 'Редагувати',
            'create' => 'Створити',
            'search' => 'Пошук',
            'status' => 'Статус',
            'active' => 'Активний',
            'inactive' => 'Неактивний',
            'pending' => 'Очікує',
            'paid' => 'Оплачено',
            'revoked' => 'Скасовано',
            'loading' => 'Завантаження...',
            'success' => 'Успішно виконано.',
            'error' => 'Сталася помилка.',
            'no_results' => 'Записів не знайдено.',
            'confirm_delete' => 'Ви впевнені, що хочете видалити цей запис?',
            'access_denied' => 'У вас недостатньо прав для цієї дії.',
        ),
        'en' => array(
            'dashboard' => 'Dashboard',
            'projects' => 'Projects',
            'plans' => 'Plans',
            'payments' => 'Payments',
            'customers' => 'Customers',
            'installations' => 'Installations',
            'downloads' => 'Downloads',
            'integrations' => 'Integrations',
            'github' => 'Repositories',
            'inspector' => 'Code Inspector',
            'analytics' => 'Analytics',
            'reports' => 'Reports',
            'settings' => 'Settings',
            'notifications' => 'Notifications',
            'mailings' => 'Mailings',
            'buttons' => 'Buttons',
            'licenses' => 'Licenses',
            'webhooks' => 'Webhooks',
            'queue' => 'Task Queue',
            'save' => 'Save',
            'cancel' => 'Cancel',
            'delete' => 'Delete',
            'edit' => 'Edit',
            'create' => 'Create',
            'search' => 'Search',
            'status' => 'Status',
            'active' => 'Active',
            'inactive' => 'Inactive',
            'pending' => 'Pending',
            'paid' => 'Paid',
            'revoked' => 'Revoked',
            'loading' => 'Loading...',
            'success' => 'Operation completed successfully.',
            'error' => 'An error occurred.',
            'no_results' => 'No records found.',
            'confirm_delete' => 'Are you sure you want to delete this record?',
            'access_denied' => 'You do not have permission to perform this action.',
        ),
    );

    public static function init() {
        add_filter('plugin_locale', array(__CLASS__, 'filter_locale'), 10, 2);
        add_action('init', array(__CLASS__, 'load_textdomain'));
    }

    public static function get_language() {
        $settings = get_option('uapm_settings', array());

        if (!empty($settings['language']) && in_array($settings['language'], array('uk', 'en'), true)) {
            return $settings['language'];
        }

        $locale = determine_locale();

        return strpos($locale, 'uk') === 0 ? 'uk' : 'en';
    }

    public static function filter_locale($locale, $domain) {
        if ($domain !== 'ua-project-manager') {
            return $locale;
        }

        return self::get_language() === 'uk' ? 'uk_UA' : 'en_US';
    }

    public static function load_textdomain() {
        load_plugin_textdomain(
            'ua-project-manager',
            false,
            dirname(plugin_basename(UAPM_FILE)) . '/languages'
        );
    }

    public static function get($key, $fallback = '') {
        $language = self::get_language();

        if (isset(self::$strings[$language][$key])) {
            return self::$strings[$language][$key];
        }

        if ($fallback !== '') {
            return $fallback;
        }

        return $key;
    }

    public static function translate($text) {
        $text = (string) $text;

        if (self::get_language() === 'uk') {
            return $text;
        }

        $map = array(
            'Головна' => 'Dashboard',
            'Проєкти' => 'Projects',
            'Тарифи' => 'Plans',
            'Платежі' => 'Payments',
            'Клієнти' => 'Customers',
            'Встановлення' => 'Installations',
            'Завантаження' => 'Downloads',
            'Інтеграції' => 'Integrations',
            'Перевірка коду' => 'Code Inspector',
            'Аналітика' => 'Analytics',
            'Звіти' => 'Reports',
            'Налаштування' => 'Settings',
            'Сповіщення' => 'Notifications',
            'Розсилки' => 'Mailings',
            'Ліцензії' => 'Licenses',
            'Зберегти' => 'Save',
            'Скасувати' => 'Cancel',
            'Видалити' => 'Delete',
            'Редагувати' => 'Edit',
            'Створити' => 'Create',
            'Пошук' => 'Search',
            'Статус' => 'Status',
            'Активний' => 'Active',
            'Неактивний' => 'Inactive',
            'Очікує' => 'Pending',
            'Оплачено' => 'Paid',
            'Скасовано' => 'Revoked',
            'Записів не знайдено.' => 'No records found.',
            'У вас недостатньо прав для цієї дії.' => 'You do not have permission to perform this action.',
        );

        return isset($map[$text]) ? $map[$text] : $text;
    }

    public static function get_languages() {
        return array(
            'uk' => 'Українська',
            'en' => 'English',
        );
    }

    public static function render_language_switcher() {
        $current = self::get_language();

        echo '<div class="uapm-language-switcher">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="uapm_set_language">';
        wp_nonce_field('uapm_set_language');

        echo '<select name="language" onchange="this.form.submit()">';

        foreach (self::get_languages() as $code => $label) {
            echo '<option value="' . esc_attr($code) . '" ' .
                selected($current, $code, false) . '>';
            echo esc_html($label);
            echo '</option>';
        }

        echo '</select>';
        echo '</form>';
        echo '</div>';
    }

    public static function handle_language_change() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html(self::get('access_denied')));
        }

        check_admin_referer('uapm_set_language');

        $language = sanitize_key(wp_unslash($_POST['language'] ?? 'uk'));

        if (!in_array($language, array('uk', 'en'), true)) {
            $language = 'uk';
        }

        $settings = get_option('uapm_settings', array());

        if (!is_array($settings)) {
            $settings = array();
        }

        $settings['language'] = $language;

        update_option('uapm_settings', $settings);

        wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=uapm'));
        exit;
    }
}

add_action('admin_post_uapm_set_language', array('UAPM_Localization', 'handle_language_change'));