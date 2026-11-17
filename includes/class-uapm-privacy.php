<?php
if (!defined('ABSPATH')) {
    exit;
}

class UAPM_Privacy {

    public static function init() {
        add_action('admin_post_uapm_privacy_export', array(__CLASS__, 'handle_export'));
        add_action('admin_post_uapm_privacy_delete', array(__CLASS__, 'handle_delete'));

        add_filter('wp_privacy_personal_data_exporters', array(__CLASS__, 'register_exporter'));
        add_filter('wp_privacy_personal_data_erasers', array(__CLASS__, 'register_eraser'));
    }

    public static function register_exporter($exporters) {
        $exporters['uapm-data'] = array(
            'exporter_friendly_name' => 'UA Project Manager',
            'callback' => array(__CLASS__, 'privacy_exporter'),
        );

        return $exporters;
    }

    public static function register_eraser($erasers) {
        $erasers['uapm-data'] = array(
            'eraser_friendly_name' => 'UA Project Manager',
            'callback' => array(__CLASS__, 'privacy_eraser'),
        );

        return $erasers;
    }

    public static function privacy_exporter($email_address, $page = 1) {
        global $wpdb;

        $email_address = sanitize_email($email_address);

        if (!is_email($email_address)) {
            return array(
                'data' => array(),
                'done' => true,
            );
        }

        $page = max(1, absint($page));
        $per_page = 100;
        $offset = ($page - 1) * $per_page;

        $items = array();
        $customers = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}uapm_customers
                WHERE email = %s LIMIT %d OFFSET %d",
                $email_address,
                $per_page,
                $offset
            )
        );

        foreach ($customers as $customer) {
            $items[] = array(
                'group_id' => 'uapm-customers',
                'group_label' => 'Дані клієнта UA Project Manager',
                'item_id' => 'customer-' . $customer->id,
                'data' => array(
                    array('name' => 'Ім’я', 'value' => $customer->name),
                    array('name' => 'Email', 'value' => $customer->email),
                    array('name' => 'Телефон', 'value' => $customer->phone),
                    array('name' => 'Компанія', 'value' => $customer->company),
                    array('name' => 'Країна', 'value' => $customer->country),
                    array('name' => 'Статус', 'value' => $customer->status),
                    array('name' => 'Згода на розсилку', 'value' => $customer->mailing_consent ? 'Так' : 'Ні'),
                ),
            );
        }

        $payments = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, project_id, customer_email, customer_name, plan,
                amount, received_amount, currency, status, activation_status,
                target, created_at, paid_at
                FROM {$wpdb->prefix}uapm_payments
                WHERE customer_email = %s
                ORDER BY id ASC
                LIMIT %d OFFSET %d",
                $email_address,
                $per_page,
                $offset
            )
        );

        foreach ($payments as $payment) {
            $items[] = array(
                'group_id' => 'uapm-payments',
                'group_label' => 'Платіжні дані UA Project Manager',
                'item_id' => 'payment-' . $payment->id,
                'data' => array(
                    array('name' => 'Проєкт', 'value' => $payment->project_id),
                    array('name' => 'Тариф', 'value' => $payment->plan),
                    array('name' => 'Сума', 'value' => $payment->amount . ' ' . $payment->currency),
                    array('name' => 'Отримано', 'value' => $payment->received_amount . ' ' . $payment->currency),
                    array('name' => 'Статус платежу', 'value' => $payment->status),
                    array('name' => 'Статус PRO', 'value' => $payment->activation_status),
                    array('name' => 'Ціль активації', 'value' => $payment->target),
                    array('name' => 'Створено', 'value' => $payment->created_at),
                    array('name' => 'Оплачено', 'value' => $payment->paid_at),
                ),
            );
        }

        $done = count($customers) < $per_page && count($payments) < $per_page;

        return array(
            'data' => $items,
            'done' => $done,
        );
    }

    public static function privacy_eraser($email_address, $page = 1) {
        global $wpdb;

        $email_address = sanitize_email($email_address);

        if (!is_email($email_address)) {
            return array(
                'items_removed' => false,
                'items_retained' => false,
                'messages' => array('Некоректна email-адреса.'),
                'done' => true,
            );
        }

        $wpdb->delete(
            $wpdb->prefix . 'uapm_customers',
            array('email' => $email_address),
            array('%s')
        );

        $wpdb->update(
            $wpdb->prefix . 'uapm_payments',
            array(
                'customer_email' => '',
                'customer_name' => 'Видалені дані',
            ),
            array('customer_email' => $email_address),
            array('%s', '%s'),
            array('%s')
        );

        return array(
            'items_removed' => true,
            'items_retained' => true,
            'messages' => array(
                'Контактні дані клієнта видалено.',
                'Платіжні записи збережено з огляду на облік фінансових операцій, але персональні поля знеособлено.',
            ),
            'done' => true,
        );
    }

    public static function handle_export() {
        if (!current_user_can('uapm_manage_settings')) {
            wp_die('Недостатньо прав.');
        }

        check_admin_referer('uapm_privacy_export');

        $email = isset($_POST['email'])
            ? sanitize_email(wp_unslash($_POST['email']))
            : '';

        if (!is_email($email)) {
            wp_die('Вкажіть коректну email-адресу.');
        }

        $export = self::privacy_exporter($email, 1);

        nocache_headers();

        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename="uapm-privacy-export.json"');

        echo wp_json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function handle_delete() {
        if (!current_user_can('uapm_manage_settings')) {
            wp_die('Недостатньо прав.');
        }

        check_admin_referer('uapm_privacy_delete');

        $email = isset($_POST['email'])
            ? sanitize_email(wp_unslash($_POST['email']))
            : '';

        if (!is_email($email)) {
            wp_die('Вкажіть коректну email-адресу.');
        }

        $result = self::privacy_eraser($email, 1);

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page' => 'uapm-privacy',
                    'deleted' => 1,
                    'retained' => !empty($result['items_retained']) ? 1 : 0,
                ),
                admin_url('admin.php')
            )
        );
        exit;
    }

    public static function render_page() {
        if (!current_user_can('uapm_manage_settings')) {
            wp_die('Недостатньо прав.');
        }

        echo '<div class="wrap uapm-wrap">';
        echo '<h1>Конфіденційність та персональні дані</h1>';

        echo '<div class="uapm-card">';
        echo '<h2>Експорт даних клієнта</h2>';
        echo '<p>Завантаження контактних і платіжних даних за email.</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="uapm_privacy_export">';
        wp_nonce_field('uapm_privacy_export');

        echo '<p><input type="email" name="email" placeholder="client@example.com" required></p>';
        submit_button('Завантажити дані', 'secondary');
        echo '</form>';
        echo '</div>';

        echo '<div class="uapm-card">';
        echo '<h2>Видалення персональних даних</h2>';
        echo '<p>Контактні дані видаляються, а фінансові записи зберігаються знеособленими.</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Підтверджуєте видалення персональних даних цього клієнта?\')">';
        echo '<input type="hidden" name="action" value="uapm_privacy_delete">';
        wp_nonce_field('uapm_privacy_delete');

        echo '<p><input type="email" name="email" placeholder="client@example.com" required></p>';
        submit_button('Видалити персональні дані', 'delete');
        echo '</form>';
        echo '</div>';

        if (isset($_GET['deleted'])) {
            echo '<div class="notice notice-success"><p>Операцію видалення виконано. Фінансові записи знеособлено.</p></div>';
        }

        echo '</div>';
    }
}