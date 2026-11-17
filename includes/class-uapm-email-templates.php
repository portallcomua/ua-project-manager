<?php
if (!defined('ABSPATH')) {
    exit;
}

class UAPM_Email_Templates {

    const OPTION_KEY = 'uapm_email_templates';

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'register_menu'), 80);
        add_action('admin_post_uapm_save_email_templates', array(__CLASS__, 'handle_save'));
        add_action('admin_post_uapm_test_email', array(__CLASS__, 'handle_test_email'));
    }

    public static function defaults() {
        return array(
            'payment_pending_subject' => 'Очікується підтвердження оплати — {site_name}',
            'payment_pending_body' => "Вітаємо, {customer_name}!\n\nВаш запит на активацію тарифу {plan_name} створено.\nСума: {amount} {currency}.\nСтатус оплати: очікує підтвердження.\n\nПісля перевірки надходження коштів адміністратор підтвердить оплату.\n\n{site_name}",

            'payment_confirmed_subject' => 'Оплату підтверджено — {site_name}',
            'payment_confirmed_body' => "Вітаємо, {customer_name}!\n\nОплату за тариф {plan_name} підтверджено.\nВаш PRO-доступ активний.\n\nПроєкт: {project_name}\nТермін дії: {expiry_date}\n\n{site_name}",

            'payment_revoked_subject' => 'PRO-доступ вимкнено — {site_name}',
            'payment_revoked_body' => "Вітаємо, {customer_name}!\n\nPRO-доступ для проєкту {project_name} вимкнено.\nЯкщо ви вважаєте це помилкою, зв'яжіться з адміністрацією.\n\n{site_name}",

            'welcome_subject' => 'Вітаємо в {site_name}',
            'welcome_body' => "Вітаємо, {customer_name}!\n\nВаш обліковий запис створено.\n\n{site_name}",

            'admin_payment_subject' => 'Новий платіж очікує перевірки',
            'admin_payment_body' => "Створено новий платіжний запит.\n\nКлієнт: {customer_name}\nEmail: {customer_email}\nПроєкт: {project_name}\nТариф: {plan_name}\nСума: {amount} {currency}\nЦіль активації: {target}\n\nПеревірте надходження коштів у панелі UA Project Manager."
        );
    }

    public static function get_templates() {
        $saved = get_option(self::OPTION_KEY, array());

        if (!is_array($saved)) {
            $saved = array();
        }

        return wp_parse_args($saved, self::defaults());
    }

    public static function get_template($key) {
        $templates = self::get_templates();

        if (!isset($templates[$key])) {
            return '';
        }

        return (string) $templates[$key];
    }

    public static function render($template, $data = array()) {
        $replacements = array(
            '{site_name}' => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
            '{site_url}' => home_url('/'),
            '{admin_email}' => get_option('admin_email'),
            '{customer_name}' => '',
            '{customer_email}' => '',
            '{project_name}' => '',
            '{plan_name}' => '',
            '{amount}' => '',
            '{currency}' => '',
            '{target}' => '',
            '{expiry_date}' => '',
            '{payment_id}' => '',
        );

        foreach ($data as $key => $value) {
            $placeholder = '{' . sanitize_key($key) . '}';

            if (array_key_exists($placeholder, $replacements)) {
                $replacements[$placeholder] = is_scalar($value)
                    ? (string) $value
                    : '';
            }
        }

        return strtr((string) $template, $replacements);
    }

    public static function send($template_key, $recipient, $data = array(), $headers = array()) {
        $recipient = sanitize_email($recipient);

        if (!$recipient || !is_email($recipient)) {
            return new WP_Error(
                'uapm_invalid_email',
                'Некоректна адреса електронної пошти.'
            );
        }

        $templates = self::get_templates();

        $subject_key = $template_key . '_subject';
        $body_key = $template_key . '_body';

        if (
            empty($templates[$subject_key]) ||
            empty($templates[$body_key])
        ) {
            return new WP_Error(
                'uapm_template_missing',
                'Шаблон листа не знайдено.'
            );
        }

        $subject = self::render($templates[$subject_key], $data);
        $body = self::render($templates[$body_key], $data);

        $subject = sanitize_text_field($subject);

        $mail_headers = array('Content-Type: text/plain; charset=UTF-8');

        if (is_array($headers)) {
            foreach ($headers as $header) {
                if (is_string($header) && strpos($header, "\n") === false && strpos($header, "\r") === false) {
                    $mail_headers[] = $header;
                }
            }
        }

        $sent = wp_mail(
            $recipient,
            $subject,
            $body,
            $mail_headers
        );

        if (!$sent) {
            return new WP_Error(
                'uapm_email_failed',
                'Не вдалося надіслати лист.'
            );
        }

        do_action(
            'uapm_email_sent',
            $template_key,
            $recipient,
            $data
        );

        return true;
    }

    public static function send_admin($template_key, $data = array()) {
        $admin_email = sanitize_email(get_option('admin_email'));

        if (!$admin_email || !is_email($admin_email)) {
            return new WP_Error(
                'uapm_admin_email_missing',
                'Не налаштована адреса адміністратора.'
            );
        }

        return self::send($template_key, $admin_email, $data);
    }

    public static function register_menu() {
        add_submenu_page(
            'uapm',
            'Шаблони листів',
            'Шаблони листів',
            'uapm_manage_settings',
            'uapm-email-templates',
            array(__CLASS__, 'render_page')
        );
    }

    public static function render_page() {
        if (!current_user_can('uapm_manage_settings')) {
            wp_die('Недостатньо прав.');
        }

        $templates = self::get_templates();

        $labels = array(
            'payment_pending' => 'Платіж очікує підтвердження',
            'payment_confirmed' => 'Оплату підтверджено',
            'payment_revoked' => 'PRO-доступ вимкнено',
            'welcome' => 'Вітальний лист',
            'admin_payment' => 'Повідомлення адміністратору',
        );

        echo '<div class="wrap">';
        echo '<h1>Шаблони електронних листів</h1>';

        if (isset($_GET['saved'])) {
            echo '<div class="notice notice-success"><p>Шаблони збережено.</p></div>';
        }

        if (isset($_GET['test_sent'])) {
            echo '<div class="notice notice-success"><p>Тестовий лист надіслано.</p></div>';
        }

        echo '<p>Доступні змінні: ';
        echo '<code>{site_name}</code>, ';
        echo '<code>{site_url}</code>, ';
        echo '<code>{customer_name}</code>, ';
        echo '<code>{customer_email}</code>, ';
        echo '<code>{project_name}</code>, ';
        echo '<code>{plan_name}</code>, ';
        echo '<code>{amount}</code>, ';
        echo '<code>{currency}</code>, ';
        echo '<code>{target}</code>, ';
        echo '<code>{expiry_date}</code>, ';
        echo '<code>{payment_id}</code>.</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="uapm_save_email_templates">';

        wp_nonce_field('uapm_save_email_templates');

        foreach ($labels as $key => $label) {
            echo '<hr>';
            echo '<h2>' . esc_html($label) . '</h2>';

            echo '<p><label><strong>Тема листа</strong></label></p>';
            echo '<input type="text" class="large-text" name="templates[' . esc_attr($key) . '_subject]" value="' . esc_attr($templates[$key . '_subject']) . '">';

            echo '<p><label><strong>Текст листа</strong></label></p>';
            echo '<textarea class="large-text" rows="8" name="templates[' . esc_attr($key) . '_body]">' . esc_textarea($templates[$key . '_body']) . '</textarea>';
        }

        submit_button('Зберегти шаблони');
        echo '</form>';

        echo '<hr>';
        echo '<h2>Тестове повідомлення</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="uapm_test_email">';
        wp_nonce_field('uapm_test_email');

        echo '<p><input type="email" name="test_email" class="regular-text" placeholder="email@example.com" required></p>';

        submit_button('Надіслати тестовий лист', 'secondary');
        echo '</form>';

        echo '</div>';
    }

    public static function handle_save() {
        if (!current_user_can('uapm_manage_settings')) {
            wp_die('Недостатньо прав.');
        }

        check_admin_referer('uapm_save_email_templates');

        $defaults = self::defaults();
        $submitted = isset($_POST['templates']) && is_array($_POST['templates'])
            ? wp_unslash($_POST['templates'])
            : array();

        $clean = array();

        foreach ($defaults as $key => $default_value) {
            if (strpos($key, '_subject') !== false) {
                $clean[$key] = isset($submitted[$key])
                    ? sanitize_text_field($submitted[$key])
                    : $default_value;
            } else {
                $clean[$key] = isset($submitted[$key])
                    ? sanitize_textarea_field($submitted[$key])
                    : $default_value;
            }
        }

        update_option(self::OPTION_KEY, $clean, false);

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page' => 'uapm-email-templates',
                    'saved' => 1,
                ),
                admin_url('admin.php')
            )
        );

        exit;
    }

    public static function handle_test_email() {
        if (!current_user_can('uapm_manage_settings')) {
            wp_die('Недостатньо прав.');
        }

        check_admin_referer('uapm_test_email');

        $recipient = isset($_POST['test_email'])
            ? sanitize_email(wp_unslash($_POST['test_email']))
            : '';

        if (!$recipient || !is_email($recipient)) {
            wp_die('Вкажіть коректну адресу електронної пошти.');
        }

        $sent = self::send(
            'welcome',
            $recipient,
            array(
                'customer_name' => 'Тестовий користувач',
                'project_name' => 'Тестовий проєкт',
                'plan_name' => 'PRO',
                'amount' => '100',
                'currency' => 'UAH',
                'target' => $recipient,
                'expiry_date' => 'Необмежено',
                'payment_id' => 'TEST',
            )
        );

        if (is_wp_error($sent)) {
            wp_die(esc_html($sent->get_error_message()));
        }

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page' => 'uapm-email-templates',
                    'test_sent' => 1,
                ),
                admin_url('admin.php')
            )
        );

        exit;
    }
}