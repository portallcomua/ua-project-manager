<?php
if (!defined('ABSPATH')) {
    exit;
}

class UAPM_Settings {

    public static function init() {
        add_action('admin_post_uapm_save_settings', array(__CLASS__, 'save'));
    }

    public static function defaults() {
        return array(
            'currency' => 'UAH',
            'language' => 'auto',
            'minimum_payout' => 100,
            'default_commission' => 0,
            'github_enabled' => 0,
            'github_token' => '',
            'github_username' => 'portallcomua',
            'privat_envelope_url' => '',
            'notifications_enabled' => 1,
            'privacy_mode' => 1,
            'installation_reporting' => 1,
            'mailing_enabled' => 0,
            'items_per_page' => 25,
        );
    }

    public static function get_all() {
        return wp_parse_args(
            get_option('uapm_settings', array()),
            self::defaults()
        );
    }

    public static function get($key, $default = null) {
        $settings = self::get_all();

        return isset($settings[$key])
            ? $settings[$key]
            : $default;
    }

    public static function save() {
        if (!current_user_can('uapm_manage_settings')) {
            wp_die('Недостатньо прав.');
        }

        check_admin_referer('uapm_save_settings');

        $settings = self::get_all();

        $settings['currency'] = sanitize_text_field(
            wp_unslash($_POST['currency'] ?? 'UAH')
        );

        $allowed_currencies = array('UAH', 'USD', 'EUR', 'GBP');

        if (!in_array($settings['currency'], $allowed_currencies, true)) {
            $settings['currency'] = 'UAH';
        }

        $settings['language'] = sanitize_key(
            wp_unslash($_POST['language'] ?? 'auto')
        );

        if (!in_array($settings['language'], array('auto', 'uk', 'en'), true)) {
            $settings['language'] = 'auto';
        }

        $settings['minimum_payout'] = max(
            0,
            (float) ($_POST['minimum_payout'] ?? 100)
        );

        $settings['default_commission'] = min(
            100,
            max(0, (float) ($_POST['default_commission'] ?? 0))
        );

        $settings['github_username'] = sanitize_text_field(
            wp_unslash($_POST['github_username'] ?? 'portallcomua')
        );

        $settings['privat_envelope_url'] = esc_url_raw(
            wp_unslash($_POST['privat_envelope_url'] ?? '')
        );

        $settings['items_per_page'] = min(
            100,
            max(5, absint($_POST['items_per_page'] ?? 25))
        );

        $checkboxes = array(
            'github_enabled',
            'notifications_enabled',
            'privacy_mode',
            'installation_reporting',
            'mailing_enabled',
        );

        foreach ($checkboxes as $key) {
            $settings[$key] = isset($_POST[$key]) ? 1 : 0;
        }

        $token = trim((string) wp_unslash($_POST['github_token'] ?? ''));

        if ($token !== '') {
            $settings['github_token'] = sanitize_text_field($token);
        }

        update_option('uapm_settings', $settings, false);

        wp_safe_redirect(add_query_arg(
            array(
                'page' => 'uapm-settings',
                'uapm_saved' => '1',
            ),
            admin_url('admin.php')
        ));
        exit;
    }

    public static function render_page() {
        if (!current_user_can('uapm_manage_settings')) {
            wp_die('Недостатньо прав.');
        }

        $settings = self::get_all();

        echo '<div class="wrap uapm-admin">';
        echo '<h1>Налаштування UA Project Manager</h1>';

        if (isset($_GET['uapm_saved'])) {
            echo '<div class="notice notice-success"><p>Налаштування збережено.</p></div>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="uapm_save_settings">';
        wp_nonce_field('uapm_save_settings');

        echo '<h2>Основні налаштування</h2>';
        echo '<table class="form-table">';

        echo '<tr><th><label for="uapm-currency">Основна валюта</label></th><td>';
        echo '<select id="uapm-currency" name="currency">';

        foreach (array('UAH' => 'Гривня (UAH)', 'USD' => 'Долар (USD)', 'EUR' => 'Євро (EUR)', 'GBP' => 'Фунт (GBP)') as $code => $label) {
            echo '<option value="' . esc_attr($code) . '" ' . selected($settings['currency'], $code, false) . '>' . esc_html($label) . '</option>';
        }

        echo '</select></td></tr>';

        echo '<tr><th><label for="uapm-language">Мова</label></th><td>';
        echo '<select id="uapm-language" name="language">';
        echo '<option value="auto" ' . selected($settings['language'], 'auto', false) . '>Автоматично за WordPress</option>';
        echo '<option value="uk" ' . selected($settings['language'], 'uk', false) . '>Українська</option>';
        echo '<option value="en" ' . selected($settings['language'], 'en', false) . '>English</option>';
        echo '</select></td></tr>';

        echo '<tr><th><label for="uapm-minimum-payout">Мінімальна виплата</label></th><td>';
        echo '<input type="number" min="0" step="0.01" id="uapm-minimum-payout" name="minimum_payout" value="' . esc_attr($settings['minimum_payout']) . '">';
        echo '</td></tr>';

        echo '<tr><th><label for="uapm-commission">Комісія за замовчуванням (%)</label></th><td>';
        echo '<input type="number" min="0" max="100" step="0.01" id="uapm-commission" name="default_commission" value="' . esc_attr($settings['default_commission']) . '">';
        echo '</td></tr>';

        echo '<tr><th><label for="uapm-items-per-page">Записів на сторінці</label></th><td>';
        echo '<input type="number" min="5" max="100" id="uapm-items-per-page" name="items_per_page" value="' . esc_attr($settings['items_per_page']) . '">';
        echo '</td></tr>';

        echo '</table>';

        echo '<h2>GitHub</h2>';
        echo '<table class="form-table">';

        self::checkbox_row(
            'github_enabled',
            'Увімкнути інтеграцію GitHub',
            $settings
        );

        echo '<tr><th><label for="uapm-github-username">GitHub користувач</label></th><td>';
        echo '<input class="regular-text" id="uapm-github-username" name="github_username" value="' . esc_attr($settings['github_username']) . '">';
        echo '</td></tr>';

        echo '<tr><th><label for="uapm-github-token">GitHub токен</label></th><td>';
        echo '<input type="password" autocomplete="new-password" class="regular-text" id="uapm-github-token" name="github_token" value="" placeholder="' . esc_attr($settings['github_token'] ? 'Токен збережено — введіть новий для заміни' : 'Необов’язково') . '">';
        echo '<p class="description">Залиште порожнім, щоб не змінювати збережений токен.</p>';
        echo '</td></tr>';

        echo '</table>';

        echo '<h2>Оплати та виплати</h2>';
        echo '<table class="form-table">';

        echo '<tr><th><label for="uapm-envelope-url">Посилання на Приват Конверт</label></th><td>';
        echo '<input type="url" class="regular-text" id="uapm-envelope-url" name="privat_envelope_url" value="' . esc_attr($settings['privat_envelope_url']) . '">';
        echo '<p class="description">Перехід за посиланням не підтверджує оплату. Платіж підтверджується адміністратором вручну.</p>';
        echo '</td></tr>';

        echo '</table>';

        echo '<h2>Безпека та сповіщення</h2>';
        echo '<table class="form-table">';

        self::checkbox_row(
            'notifications_enabled',
            'Увімкнути сповіщення',
            $settings
        );

        self::checkbox_row(
            'privacy_mode',
            'Режим конфіденційності',
            $settings
        );

        self::checkbox_row(
            'installation_reporting',
            'Дозволити облік встановлень через інтеграцію',
            $settings
        );

        self::checkbox_row(
            'mailing_enabled',
            'Дозволити поштові розсилки',
            $settings
        );

        echo '</table>';

        submit_button('Зберегти налаштування');

        echo '</form>';
        echo '</div>';
    }

    private static function checkbox_row($key, $label, $settings) {
        echo '<tr><th>' . esc_html($label) . '</th><td>';
        echo '<label><input type="checkbox" name="' . esc_attr($key) . '" value="1" ' . checked(!empty($settings[$key]), true, false) . '> Увімкнено</label>';
        echo '</td></tr>';
    }
}