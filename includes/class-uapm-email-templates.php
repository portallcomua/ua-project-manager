<?php
/**
 * UA Project Manager — Email Templates.
 *
 * @package UA_Project_Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

class UAPM_Email_Templates {

    const OPTION_KEY = 'uapm_email_templates';

    public static function init() {
        add_action('admin_init', array(__CLASS__, 'register_settings'));
    }

    public static function defaults() {
        return array(
            'payment_received' => array(
                'subject' => 'Оплату отримано',
                'body' => "Вітаємо, {name}!\n\nМи отримали ваш платіж у розмірі {amount}.\n\nДякуємо!",
            ),
            'payment_pending' => array(
                'subject' => 'Очікується підтвердження оплати',
                'body' => "Вітаємо, {name}!\n\nВаш платіж на суму {amount} очікує підтвердження адміністратором.",
            ),
            'payment_confirmed' => array(
                'subject' => 'Оплату підтверджено',
                'body' => "Вітаємо, {name}!\n\nВаш платіж на суму {amount} підтверджено.",
            ),
            'payment_rejected' => array(
                'subject' => 'Платіж не підтверджено',
                'body' => "Вітаємо, {name}!\n\nНа жаль, ваш платіж не вдалося підтвердити. Зверніться до адміністратора.",
            ),
            'commission_accrued' => array(
                'subject' => 'Нараховано комісію',
                'body' => "Вітаємо, {name}!\n\nВам нараховано комісію у розмірі {amount} за клієнта {client}.",
            ),
            'new_client' => array(
                'subject' => 'Новий клієнт',
                'body' => "Вітаємо, {name}!\n\nДо вашого кабінету додано нового клієнта: {client}.",
            ),
            'project_updated' => array(
                'subject' => 'Оновлено проєкт',
                'body' => "Вітаємо, {name}!\n\nІнформацію про проєкт {project} оновлено.",
            ),
            'admin_alert' => array(
                'subject' => 'Повідомлення UA Project Manager',
                'body' => "Нове повідомлення системи:\n\n{message}",
            ),
        );
    }

    public static function get_all() {
        $saved = get_option(self::OPTION_KEY, array());

        if (!is_array($saved)) {
            $saved = array();
        }

        return wp_parse_args($saved, self::defaults());
    }

    public static function get($template_key) {
        $templates = self::get_all();
        $template_key = sanitize_key($template_key);

        return isset($templates[$template_key])
            ? $templates[$template_key]
            : false;
    }

    public static function save($template_key, $subject, $body) {
        $template_key = sanitize_key($template_key);
        $templates = self::get_all();

        if (!isset($templates[$template_key])) {
            return false;
        }

        $templates[$template_key] = array(
            'subject' => sanitize_text_field($subject),
            'body' => sanitize_textarea_field($body),
        );

        return update_option(self::OPTION_KEY, $templates, false);
    }

    public static function render($template_key, $variables = array()) {
        $template = self::get($template_key);

        if (!$template) {
            return false;
        }

        $variables = self::sanitize_variables($variables);

        return array(
            'subject' => self::replace_variables($template['subject'], $variables),
            'body' => self::replace_variables($template['body'], $variables),
        );
    }

    public static function replace_variables($content, $variables = array()) {
        $content = (string) $content;

        foreach ($variables as $key => $value) {
            $placeholder = '{' . sanitize_key($key) . '}';
            $content = str_replace($placeholder, (string) $value, $content);
        }

        return $content;
    }

    private static function sanitize_variables($variables) {
        if (!is_array($variables)) {
            return array();
        }

        $clean = array();

        foreach ($variables as $key => $value) {
            $key = sanitize_key($key);

            if (is_scalar($value)) {
                $clean[$key] = sanitize_text_field((string) $value);
            }
        }

        return $clean;
    }

    public static function send($template_key, $email, $variables = array(), $args = array()) {
        if (!is_email($email)) {
            return false;
        }

        $rendered = self::render($template_key, $variables);

        if (!$rendered) {
            return false;
        }

        $args = wp_parse_args($args, array(
            'headers' => array('Content-Type: text/plain; charset=UTF-8'),
        ));

        return wp_mail(
            sanitize_email($email),
            $rendered['subject'],
            $rendered['body'],
            $args['headers']
        );
    }

    public static function send_to_user($template_key, $user_id, $variables = array(), $args = array()) {
        $user = get_userdata(absint($user_id));

        if (!$user || !is_email($user->user_email)) {
            return false;
        }

        if (empty($variables['name'])) {
            $variables['name'] = $user->display_name;
        }

        return self::send(
            $template_key,
            $user->user_email,
            $variables,
            $args
        );
    }

    public static function register_settings() {
        register_setting(
            'uapm_email_templates_group',
            self::OPTION_KEY,
            array(
                'type' => 'array',
                'sanitize_callback' => array(__CLASS__, 'sanitize_templates'),
                'default' => self::defaults(),
            )
        );
    }

    public static function sanitize_templates($input) {
        $defaults = self::defaults();
        $clean = array();

        if (!is_array($input)) {
            return $defaults;
        }

        foreach ($defaults as $key => $default) {
            $item = isset($input[$key]) && is_array($input[$key])
                ? $input[$key]
                : array();

            $clean[$key] = array(
                'subject' => isset($item['subject'])
                    ? sanitize_text_field($item['subject'])
                    : $default['subject'],
                'body' => isset($item['body'])
                    ? sanitize_textarea_field($item['body'])
                    : $default['body'],
            );
        }

        return $clean;
    }

    public static function get_available_variables() {
        return array(
            'name' => 'Ім’я отримувача',
            'email' => 'Email отримувача',
            'amount' => 'Сума платежу',
            'client' => 'Ім’я клієнта',
            'project' => 'Назва проєкту',
            'message' => 'Текст повідомлення',
            'date' => 'Дата',
            'site_url' => 'Адреса сайту',
        );
    }

    public static function reset($template_key = '') {
        $defaults = self::defaults();

        if ($template_key !== '') {
            $template_key = sanitize_key($template_key);

            if (!isset($defaults[$template_key])) {
                return false;
            }

            $templates = self::get_all();
            $templates[$template_key] = $defaults[$template_key];

            return update_option(self::OPTION_KEY, $templates, false);
        }

        return update_option(self::OPTION_KEY, $defaults, false);
    }
}

UAPM_Email_Templates::init();
