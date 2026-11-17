<?php
/**
 * UA Project Manager — Localization.
 *
 * @package UA_Project_Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

final class UAPM_Localization {

    private static $initialized = false;

    private static $translations = array(
        'Dashboard' => 'Панель керування',
        'Projects' => 'Проєкти',
        'All projects' => 'Усі проєкти',
        'Active projects' => 'Активні проєкти',
        'In development' => 'У розробці',
        'Latest projects' => 'Останні проєкти',
        'Open projects' => 'Відкрити проєкти',
        'Create and manage your products and applications.' => 'Створення та керування продуктами й застосунками.',
        'Payments' => 'Платежі',
        'Pending payments' => 'Очікувані платежі',
        'Partial payments' => 'Часткові платежі',
        'Confirmed payments' => 'Підтверджені платежі',
        'Open payments' => 'Відкрити платежі',
        'Record received amounts and manually confirm or revoke PRO access.' => 'Облік отриманих коштів і ручне підтвердження або скасування PRO-доступу.',
        'Clients' => 'Клієнти',
        'Customers' => 'Замовники',
        'Installations' => 'Встановлення',
        'Downloads' => 'Завантаження',
        'Integrations' => 'Інтеграції',
        'GitHub' => 'GitHub',
        'Repositories' => 'Репозиторії',
        'Code Generator' => 'Генератор коду',
        'Code Inspector' => 'Перевірка коду',
        'Analytics' => 'Аналітика',
        'Reports' => 'Звіти',
        'Settings' => 'Налаштування',
        'Notifications' => 'Сповіщення',
        'Mailings' => 'Розсилки',
        'Licenses' => 'Ліцензії',
        'Plans' => 'Тарифи',
        'Task Queue' => 'Черга завдань',
        'All' => 'Усі',
        'Name' => 'Назва',
        'Title' => 'Заголовок',
        'Description' => 'Опис',
        'Type' => 'Тип',
        'Version' => 'Версія',
        'Status' => 'Статус',
        'Date' => 'Дата',
        'Created' => 'Створено',
        'Updated' => 'Оновлено',
        'Actions' => 'Дії',
        'Action' => 'Дія',
        'Search' => 'Пошук',
        'Filter' => 'Фільтрувати',
        'Filter results' => 'Фільтрувати результати',
        'Save' => 'Зберегти',
        'Save changes' => 'Зберегти зміни',
        'Cancel' => 'Скасувати',
        'Delete' => 'Видалити',
        'Edit' => 'Редагувати',
        'Create' => 'Створити',
        'Add new' => 'Додати',
        'Add' => 'Додати',
        'Update' => 'Оновити',
        'Download' => 'Завантажити',
        'Upload' => 'Завантажити файл',
        'Export' => 'Експорт',
        'Import' => 'Імпорт',
        'Generate' => 'Згенерувати',
        'Generate and download PHP file' => 'Згенерувати та завантажити PHP-файл',
        'View' => 'Переглянути',
        'Open' => 'Відкрити',
        'Close' => 'Закрити',
        'Enable' => 'Увімкнути',
        'Disable' => 'Вимкнути',
        'Enabled' => 'Увімкнено',
        'Disabled' => 'Вимкнено',
        'Active' => 'Активний',
        'Inactive' => 'Неактивний',
        'Pending' => 'Очікує',
        'Paid' => 'Оплачено',
        'Partial' => 'Частково',
        'Revoked' => 'Скасовано',
        'Archived' => 'В архіві',
        'Completed' => 'Завершено',
        'Failed' => 'Помилка',
        'Success' => 'Успішно',
        'Error' => 'Помилка',
        'Warning' => 'Попередження',
        'No records found.' => 'Записів не знайдено.',
        'No projects found.' => 'Проєктів не знайдено.',
        'You do not have permission to access this page.' => 'У вас немає дозволу на перегляд цієї сторінки.',
        'You do not have permission to perform this action.' => 'У вас недостатньо прав для цієї дії.',
        'Are you sure you want to delete this record?' => 'Ви впевнені, що хочете видалити цей запис?',
        'Central management of projects, releases, installations and payments.' => 'Централізоване керування проєктами, релізами, встановленнями та платежами.',
        'All projects' => 'Усі проєкти',
        'Project name' => 'Назва проєкту',
        'Project description' => 'Опис проєкту',
        'Project URL' => 'Посилання на проєкт',
        'Repository URL' => 'Посилання на репозиторій',
        'GitHub username' => 'Ім’я користувача GitHub',
        'GitHub token' => 'Токен GitHub',
        'Main currency' => 'Основна валюта',
        'Minimum payout' => 'Мінімальна виплата',
        'Default commission (%)' => 'Комісія за замовчуванням (%)',
        'Items per page' => 'Записів на сторінці',
        'Language' => 'Мова',
        'Automatically detect WordPress language' => 'Автоматично визначати мову WordPress',
        'Ukrainian' => 'Українська',
        'English' => 'Англійська',
        'Security and notifications' => 'Безпека та сповіщення',
        'Enable notifications' => 'Увімкнути сповіщення',
        'Privacy mode' => 'Режим конфіденційності',
        'Save settings' => 'Зберегти налаштування',
        'Settings saved.' => 'Налаштування збережено.',
        'Record payment' => 'Зареєструвати платіж',
        'Payment amount' => 'Сума платежу',
        'Payment date' => 'Дата платежу',
        'Payment method' => 'Спосіб оплати',
        'Client' => 'Клієнт',
        'Manager' => 'Менеджер',
        'Commission' => 'Комісія',
        'Commission rate' => 'Ставка комісії',
        'Received amount' => 'Отримана сума',
        'Remaining amount' => 'Залишок до оплати',
        'Confirm payment' => 'Підтвердити платіж',
        'Revoke PRO' => 'Скасувати PRO-доступ',
        'PRO access' => 'PRO-доступ',
        'Project details' => 'Дані проєкту',
        'Client details' => 'Дані клієнта',
        'Contact information' => 'Контактна інформація',
        'Email address' => 'Електронна пошта',
        'Phone number' => 'Номер телефону',
        'Website' => 'Вебсайт',
        'Site URL' => 'Адреса сайту',
        'Plugin' => 'Плагін',
        'Plugin name' => 'Назва плагіна',
        'Plugin version' => 'Версія плагіна',
        'Last seen' => 'Останній зв’язок',
        'Activate' => 'Активувати',
        'Deactivate' => 'Деактивувати',
        'All statuses' => 'Усі статуси',
        'Active installations' => 'Активні встановлення',
        'Deactivated' => 'Деактивовано',
        'No installations found.' => 'Встановлень не знайдено.',
        'Integration name' => 'Назва інтеграції',
        'API key' => 'Ключ API',
        'API token' => 'Токен API',
        'Secret key' => 'Секретний ключ',
        'Connection status' => 'Стан підключення',
        'Connected' => 'Підключено',
        'Not connected' => 'Не підключено',
        'Test connection' => 'Перевірити підключення',
        'Code' => 'Код',
        'File' => 'Файл',
        'File name' => 'Назва файлу',
        'Line' => 'Рядок',
        'Result' => 'Результат',
        'Details' => 'Деталі',
        'OK' => 'OK',
        'No errors found.' => 'Помилок не знайдено.',
        'Export CSV' => 'Експортувати CSV',
        'Export analytics to CSV' => 'Експортувати аналітику в CSV',
        'Currency' => 'Валюта',
        'Ukrainian hryvnia' => 'Українська гривня',
        'US dollar' => 'Долар США',
        'Euro' => 'Євро',
        'British pound' => 'Фунт стерлінгів',
        'Loading...' => 'Завантаження...',
        'Please wait...' => 'Зачекайте...',
        'Operation completed successfully.' => 'Операцію успішно виконано.',
        'An error occurred.' => 'Сталася помилка.',
        'Nothing to display.' => 'Немає даних для відображення.',
    );

    public static function init() {
        if (self::$initialized) {
            return;
        }

        self::$initialized = true;

        add_filter('plugin_locale', array(__CLASS__, 'filter_locale'), 10, 2);
        add_filter('gettext', array(__CLASS__, 'translate_gettext'), 20, 3);
        add_filter('gettext_with_context', array(__CLASS__, 'translate_gettext_context'), 20, 4);
        add_action('init', array(__CLASS__, 'load_textdomain'), 1);
        add_action('admin_bar_menu', array(__CLASS__, 'admin_bar_language_switcher'), 100);
    }

    public static function get_language() {
        $settings = get_option('uapm_settings', array());

        if (
            is_array($settings) &&
            !empty($settings['language']) &&
            in_array($settings['language'], array('uk', 'en'), true)
        ) {
            return $settings['language'];
        }

        $locale = function_exists('determine_locale')
            ? determine_locale()
            : get_locale();

        return strpos(strtolower((string) $locale), 'uk') === 0 ? 'uk' : 'en';
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

    public static function translate_gettext($translation, $original, $domain) {
        if ($domain !== 'ua-project-manager') {
            return $translation;
        }

        return self::translate_string($translation, $original);
    }

    public static function translate_gettext_context($translation, $original, $context, $domain) {
        if ($domain !== 'ua-project-manager') {
            return $translation;
        }

        return self::translate_string($translation, $original);
    }

    private static function translate_string($translation, $original) {
        $language = self::get_language();

        if ($language === 'uk') {
            if (isset(self::$translations[$original])) {
                return self::$translations[$original];
            }

            return $translation;
        }

        $reverse = array_flip(self::$translations);

        if (isset($reverse[$original])) {
            return $reverse[$original];
        }

        if (isset($reverse[$translation])) {
            return $reverse[$translation];
        }

        return $translation;
    }

    public static function get($key, $fallback = '') {
        $strings = array(
            'access_denied' => array(
                'uk' => 'У вас недостатньо прав для цієї дії.',
                'en' => 'You do not have permission to perform this action.',
            ),
            'save' => array('uk' => 'Зберегти', 'en' => 'Save'),
            'cancel' => array('uk' => 'Скасувати', 'en' => 'Cancel'),
        );

        if (isset($strings[$key])) {
            return $strings[$key][self::get_language()];
        }

        return $fallback !== '' ? $fallback : $key;
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
        echo '<label class="screen-reader-text" for="uapm-language-switcher">';
        echo esc_html($current === 'uk' ? 'Мова інтерфейсу' : 'Interface language');
        echo '</label>';
        echo '<select id="uapm-language-switcher" name="language" aria-label="' .
            esc_attr($current === 'uk' ? 'Мова інтерфейсу' : 'Interface language') .
            '" onchange="this.form.submit()">';

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

    public static function admin_bar_language_switcher($admin_bar) {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        $admin_bar->add_node(array(
            'id' => 'uapm-language',
            'title' => self::get_language() === 'uk' ? 'UPM: Українська' : 'UPM: English',
            'href' => admin_url('admin.php?page=uapm-settings'),
        ));
    }

    public static function handle_language_change() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html(self::get('access_denied')));
        }

        check_admin_referer('uapm_set_language');

        $language = isset($_POST['language'])
            ? sanitize_key(wp_unslash($_POST['language']))
            : 'uk';

        if (!in_array($language, array('uk', 'en'), true)) {
            $language = 'uk';
        }

        $settings = get_option('uapm_settings', array());

        if (!is_array($settings)) {
            $settings = array();
        }

        $settings['language'] = $language;
        update_option('uapm_settings', $settings, false);

        $redirect = wp_get_referer();

        if (!$redirect) {
            $redirect = admin_url('admin.php?page=uapm');
        }

        wp_safe_redirect($redirect);
        exit;
    }
}

UAPM_Localization::init();

add_action(
    'admin_post_uapm_set_language',
    array('UAPM_Localization', 'handle_language_change')
);

$uapm_assets_file = __DIR__ . '/class-uapm-admin-assets.php';

if (file_exists($uapm_assets_file)) {
    require_once $uapm_assets_file;
}
