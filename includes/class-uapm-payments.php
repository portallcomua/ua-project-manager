<?php
/**
 * UA Project Manager — payments, partial receipts and manager commissions.
 *
 * @package UA_Project_Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

final class UAPM_Payments {

    public static function init() {
        if (defined('UAPM_DB_VERSION') && get_option('uapm_modules_schema_ver') !== UAPM_DB_VERSION) {
            self::install_schema();
        }
        add_action('admin_post_uapm_record_payment', array(__CLASS__, 'handle_record_payment'));
        add_action('admin_post_uapm_confirm_payment', array(__CLASS__, 'handle_confirm_payment'));
        add_action('admin_post_uapm_revoke_payment', array(__CLASS__, 'handle_revoke_payment'));
        add_action('admin_post_uapm_set_commission', array(__CLASS__, 'handle_set_commission'));
    }

    private static function payments_table() {
        global $wpdb;
        return $wpdb->prefix . 'uapm_payments';
    }

    private static function transactions_table() {
        global $wpdb;
        return $wpdb->prefix . 'uapm_payment_transactions';
    }

    private static function commissions_table() {
        global $wpdb;
        return $wpdb->prefix . 'uapm_manager_commissions';
    }

    public static function install_schema() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $payments = self::payments_table();
        $transactions = self::transactions_table();
        $commissions = self::commissions_table();

        dbDelta("CREATE TABLE {$payments} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            project_id bigint(20) unsigned NOT NULL,
            plan_id bigint(20) unsigned NULL,
            customer_id bigint(20) unsigned NULL,
            customer_email varchar(191) NULL,
            activation_target varchar(255) NOT NULL,
            activation_type varchar(30) NOT NULL DEFAULT 'domain',
            amount decimal(18,2) NOT NULL DEFAULT 0.00,
            currency varchar(10) NOT NULL DEFAULT 'UAH',
            payment_status varchar(30) NOT NULL DEFAULT 'pending',
            payment_method varchar(50) NOT NULL DEFAULT 'privat_envelope',
            external_reference varchar(191) NULL,
            confirmed_by bigint(20) unsigned NULL,
            confirmed_at datetime NULL,
            manager_id bigint(20) unsigned NULL,
            commission_rate decimal(8,4) NOT NULL DEFAULT 0.0000,
            commission_amount decimal(18,2) NOT NULL DEFAULT 0.00,
            received_amount decimal(18,2) NOT NULL DEFAULT 0.00,
            activation_status varchar(30) NOT NULL DEFAULT 'provisional',
            paid_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY project_id (project_id),
            KEY customer_id (customer_id),
            KEY customer_email (customer_email),
            KEY payment_status (payment_status),
            KEY activation_target (activation_target),
            KEY manager_id (manager_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$transactions} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            payment_id bigint(20) unsigned NOT NULL,
            amount decimal(18,2) NOT NULL DEFAULT 0.00,
            currency varchar(10) NOT NULL DEFAULT 'UAH',
            commission_rate decimal(8,4) NOT NULL DEFAULT 0.0000,
            commission_amount decimal(18,2) NOT NULL DEFAULT 0.00,
            reference varchar(191) NULL,
            note text NULL,
            received_by bigint(20) unsigned NULL,
            received_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY payment_id (payment_id),
            KEY received_at (received_at)
        ) {$charset};");

        dbDelta("CREATE TABLE {$commissions} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            manager_id bigint(20) unsigned NOT NULL,
            customer_email varchar(191) NOT NULL,
            commission_rate decimal(8,4) NOT NULL DEFAULT 0.0000,
            updated_by bigint(20) unsigned NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY manager_customer (manager_id,customer_email),
            KEY manager_id (manager_id)
        ) {$charset};");
    }

    private static function can_manage_payments() {
        return current_user_can('uapm_manage_payments') || current_user_can('manage_options');
    }

    public static function create_request($data) {
        global $wpdb;

        if (!is_array($data)) {
            return new WP_Error('uapm_invalid_data', 'Некоректні дані платежу.');
        }

        $project_id = absint($data['project_id'] ?? 0);
        $plan_id = absint($data['plan_id'] ?? 0);
        $customer_id = absint($data['customer_id'] ?? 0);
        $manager_id = absint($data['manager_id'] ?? 0);
        $target = sanitize_text_field($data['activation_target'] ?? '');
        $type = sanitize_key($data['activation_type'] ?? 'domain');
        $amount = round((float) ($data['amount'] ?? 0), 2);
        $currency = strtoupper(sanitize_text_field($data['currency'] ?? 'UAH'));
        $email = sanitize_email($data['customer_email'] ?? '');

        if (!$project_id || !class_exists('UAPM_Projects') || !UAPM_Projects::get($project_id)) {
            return new WP_Error('uapm_invalid_project', 'Проєкт не знайдено.');
        }

        if (!in_array($type, array('domain', 'email'), true)) {
            return new WP_Error('uapm_invalid_target_type', 'Непідтримуваний тип цілі активації.');
        }

        if ($type === 'email') {
            $target = sanitize_email($target);
            if (!is_email($target)) {
                return new WP_Error('uapm_invalid_email', 'Вкажіть коректний email для активації.');
            }
            $email = $email ?: $target;
        } else {
            $target = self::normalize_domain($target);
            if (!$target) {
                return new WP_Error('uapm_invalid_domain', 'Вкажіть коректний домен.');
            }
        }

        if ($email && !is_email($email)) {
            return new WP_Error('uapm_invalid_customer_email', 'Некоректний email клієнта.');
        }

        if ($amount <= 0 || !is_finite($amount)) {
            return new WP_Error('uapm_invalid_amount', 'Сума платежу має бути більшою за нуль.');
        }

        if (!preg_match('/^[A-Z]{3,10}$/', $currency)) {
            return new WP_Error('uapm_invalid_currency', 'Некоректна валюта.');
        }

        if ($plan_id) {
            $plan = UAPM_Projects::get_plan($plan_id);
            if (!$plan || (int) $plan->project_id !== $project_id || $plan->plan_type !== 'pro') {
                return new WP_Error('uapm_invalid_plan', 'Тариф PRO не належить цьому проєкту.');
            }
        }

        if ($customer_id && !$email) {
            $customer = $wpdb->get_row($wpdb->prepare(
                "SELECT email FROM {$wpdb->prefix}uapm_customers WHERE id = %d",
                $customer_id
            ));
            if ($customer && is_email($customer->email)) {
                $email = sanitize_email($customer->email);
            }
        }

        $rate = ($manager_id && $email) ? self::get_manager_commission($manager_id, $email) : 0;
        $now = current_time('mysql');

        $inserted = $wpdb->insert(self::payments_table(), array(
            'project_id' => $project_id,
            'plan_id' => $plan_id ?: null,
            'customer_id' => $customer_id ?: null,
            'customer_email' => $email ?: null,
            'activation_target' => $target,
            'activation_type' => $type,
            'amount' => number_format($amount, 2, '.', ''),
            'currency' => $currency,
            'payment_status' => 'pending',
            'payment_method' => sanitize_key($data['payment_method'] ?? 'privat_envelope'),
            'manager_id' => $manager_id ?: null,
            'commission_rate' => number_format($rate, 4, '.', ''),
            'commission_amount' => '0.00',
            'received_amount' => '0.00',
            'activation_status' => 'provisional',
            'created_at' => $now,
            'updated_at' => $now,
        ));

        if (!$inserted) {
            return new WP_Error('uapm_database_error', 'Не вдалося створити платіж.');
        }

        $id = (int) $wpdb->insert_id;
        self::log($project_id, 'payment_request_created', 'Створено платіжний запит #' . $id . '.');
        do_action('uapm_payment_request_created', $id, $data);

        return $id;
    }

    public static function get($payment_id) {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::payments_table() . " WHERE id = %d",
            absint($payment_id)
        ));
    }

    public static function get_all($args = array()) {
        global $wpdb;

        $args = wp_parse_args($args, array(
            'project_id' => 0,
            'status' => '',
            'manager_id' => 0,
            'limit' => 50,
            'offset' => 0,
        ));

        $where = array('1=1');
        $values = array();

        if (!empty($args['project_id'])) {
            $where[] = 'project_id = %d';
            $values[] = absint($args['project_id']);
        }
        if (!empty($args['status'])) {
            $where[] = 'payment_status = %s';
            $values[] = sanitize_key($args['status']);
        }
        if (!empty($args['manager_id'])) {
            $where[] = 'manager_id = %d';
            $values[] = absint($args['manager_id']);
        }

        $values[] = min(200, max(1, absint($args['limit'])));
        $values[] = max(0, absint($args['offset']));

        $sql = "SELECT * FROM " . self::payments_table() .
            " WHERE " . implode(' AND ', $where) .
            " ORDER BY id DESC LIMIT %d OFFSET %d";

        return $wpdb->get_results($wpdb->prepare($sql, $values));
    }

    public static function record_receipt($payment_id, $amount, $details = array()) {
        global $wpdb;

        if (!self::can_manage_payments()) {
            return new WP_Error('uapm_forbidden', 'Недостатньо прав для внесення платежу.');
        }

        $payment_id = absint($payment_id);
        $amount = round((float) $amount, 2);

        if (!$payment_id || $amount <= 0 || !is_finite($amount)) {
            return new WP_Error('uapm_invalid_amount', 'Вкажіть коректну отриману суму.');
        }

        $wpdb->query('START TRANSACTION');

        $payment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::payments_table() . " WHERE id = %d FOR UPDATE",
            $payment_id
        ));

        if (!$payment || in_array($payment->payment_status, array('paid', 'revoked'), true)) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('uapm_payment_closed', 'Платіж не знайдено або його вже закрито.');
        }

        $remaining = round((float) $payment->amount - (float) $payment->received_amount, 2);
        if ($amount > $remaining + 0.0001) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('uapm_overpayment', 'Отримана сума перевищує залишок платежу.');
        }

        $rate = (float) $payment->commission_rate;
        $commission = round($amount * $rate / 100, 2);

        $inserted = $wpdb->insert(self::transactions_table(), array(
            'payment_id' => $payment_id,
            'amount' => number_format($amount, 2, '.', ''),
            'currency' => $payment->currency,
            'commission_rate' => number_format($rate, 4, '.', ''),
            'commission_amount' => number_format($commission, 2, '.', ''),
            'reference' => sanitize_text_field($details['reference'] ?? ''),
            'note' => sanitize_textarea_field($details['note'] ?? ''),
            'received_by' => get_current_user_id(),
            'received_at' => current_time('mysql'),
        ));

        if (!$inserted) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('uapm_database_error', 'Не вдалося записати транзакцію.');
        }

        $transaction_id = (int) $wpdb->insert_id;
        $new_received = round((float) $payment->received_amount + $amount, 2);
        $new_commission = round((float) $payment->commission_amount + $commission, 2);
        $new_status = ($new_received >= (float) $payment->amount - 0.0001) ? 'pending' : 'partial';

        $updated = $wpdb->update(self::payments_table(), array(
            'received_amount' => number_format($new_received, 2, '.', ''),
            'commission_amount' => number_format($new_commission, 2, '.', ''),
            'payment_status' => $new_status,
            'updated_at' => current_time('mysql'),
        ), array('id' => $payment_id));

        if (false === $updated) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('uapm_database_error', 'Не вдалося оновити суму платежу.');
        }

        $wpdb->query('COMMIT');

        self::log((int) $payment->project_id, 'payment_receipt_recorded', 'Отримано ' . number_format($amount, 2, '.', '') . ' ' . $payment->currency . ' за платіж #' . $payment_id . '.');

        do_action('uapm_payment_receipt_recorded', $payment_id, $transaction_id, $amount);
        do_action('uapm_payment_received', $payment_id, $amount);

        if (!empty($payment->manager_id)) {
            do_action('uapm_manager_commission_accrued', (int) $payment->manager_id, $payment_id, $commission, $payment->currency);
        }

        return $transaction_id;
    }

    public static function confirm_payment($payment_id) {
        global $wpdb;

        if (!self::can_manage_payments()) {
            return new WP_Error('uapm_forbidden', 'Недостатньо прав для підтвердження платежу.');
        }

        $payment = self::get($payment_id);
        if (!$payment) {
            return new WP_Error('uapm_payment_not_found', 'Платіж не знайдено.');
        }
        if ($payment->payment_status === 'revoked') {
            return new WP_Error('uapm_payment_revoked', 'Скасований платіж не можна підтвердити.');
        }
        if ((float) $payment->received_amount + 0.0001 < (float) $payment->amount) {
            return new WP_Error('uapm_payment_incomplete', 'Для активації PRO необхідно отримати всю суму.');
        }

        $now = current_time('mysql');
        $result = $wpdb->update(self::payments_table(), array(
            'payment_status' => 'paid',
            'activation_status' => 'active',
            'confirmed_by' => get_current_user_id(),
            'confirmed_at' => $now,
            'paid_at' => $now,
            'updated_at' => $now,
        ), array('id' => absint($payment_id)));

        if (false === $result) {
            return new WP_Error('uapm_database_error', 'Не вдалося підтвердити платіж.');
        }

        self::log((int) $payment->project_id, 'payment_confirmed', 'Платіж #' . absint($payment_id) . ' підтверджено.');
        do_action('uapm_payment_confirmed', absint($payment_id), $payment);
        do_action('uapm_pro_access_changed', (int) $payment->project_id, $payment->activation_target, $payment->activation_type, true, absint($payment_id));

        return true;
    }

    public static function revoke_payment($payment_id, $reason = '') {
        global $wpdb;

        if (!self::can_manage_payments()) {
            return new WP_Error('uapm_forbidden', 'Недостатньо прав для скасування PRO.');
        }

        $payment = self::get($payment_id);
        if (!$payment) {
            return new WP_Error('uapm_payment_not_found', 'Платіж не знайдено.');
        }

        $result = $wpdb->update(self::payments_table(), array(
            'payment_status' => 'revoked',
            'activation_status' => 'revoked',
            'updated_at' => current_time('mysql'),
        ), array('id' => absint($payment_id)));

        if (false === $result) {
            return new WP_Error('uapm_database_error', 'Не вдалося скасувати PRO.');
        }

        $reason = sanitize_textarea_field($reason);
        self::log((int) $payment->project_id, 'payment_revoked', 'Платіж #' . absint($payment_id) . ' скасовано. ' . $reason);
        do_action('uapm_payment_revoked', absint($payment_id), $payment, $reason);
        do_action('uapm_pro_access_changed', (int) $payment->project_id, $payment->activation_target, $payment->activation_type, false, absint($payment_id));

        return true;
    }

    public static function get_activation_status($project_id, $target, $type = 'domain') {
        global $wpdb;

        $project_id = absint($project_id);
        $type = sanitize_key($type);
        $target = ($type === 'domain') ? self::normalize_domain($target) : (($type === 'email') ? sanitize_email($target) : '');

        if (!$project_id || !$target) {
            return array('active' => false, 'confirmed' => false, 'status' => 'invalid');
        }

        $payment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::payments_table() . "
             WHERE project_id = %d AND activation_target = %s AND activation_type = %s
             ORDER BY id DESC LIMIT 1",
            $project_id,
            $target,
            $type
        ));

        if (!$payment) {
            return array('active' => false, 'confirmed' => false, 'status' => 'not_found');
        }

        $confirmed = ($payment->payment_status === 'paid' && $payment->activation_status === 'active');

        return array(
            'active' => $confirmed,
            'confirmed' => $confirmed,
            'status' => $payment->payment_status,
            'payment_id' => (int) $payment->id,
            'amount' => (float) $payment->amount,
            'received_amount' => (float) $payment->received_amount,
            'currency' => $payment->currency,
            'activation_target' => $payment->activation_target,
            'activation_type' => $payment->activation_type,
        );
    }

    public static function get_transactions($payment_id) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::transactions_table() . " WHERE payment_id = %d ORDER BY id ASC",
            absint($payment_id)
        ));
    }

    public static function set_manager_commission($manager_id, $customer_email, $rate) {
        global $wpdb;

        if (!self::can_manage_payments()) {
            return new WP_Error('uapm_forbidden', 'Недостатньо прав для налаштування комісії.');
        }

        $manager_id = absint($manager_id);
        $email = sanitize_email($customer_email);
        $rate = (float) $rate;

        if (!$manager_id || !get_user_by('id', $manager_id)) {
            return new WP_Error('uapm_invalid_manager', 'Менеджера не знайдено.');
        }
        if (!is_email($email)) {
            return new WP_Error('uapm_invalid_email', 'Вкажіть коректний email клієнта.');
        }
        if ($rate < 0 || $rate > 100 || !is_finite($rate)) {
            return new WP_Error('uapm_invalid_rate', 'Комісія має бути від 0 до 100%.');
        }

        $table = self::commissions_table();
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE manager_id = %d AND customer_email = %s",
            $manager_id,
            $email
        ));

        $data = array(
            'manager_id' => $manager_id,
            'customer_email' => $email,
            'commission_rate' => number_format($rate, 4, '.', ''),
            'updated_by' => get_current_user_id(),
            'updated_at' => current_time('mysql'),
        );

        $result = $existing
            ? $wpdb->update($table, $data, array('id' => absint($existing)))
            : $wpdb->insert($table, $data);

        return false === $result
            ? new WP_Error('uapm_database_error', 'Не вдалося зберегти комісію.')
            : true;
    }

    public static function get_manager_commission($manager_id, $customer_email) {
        global $wpdb;

        $rate = $wpdb->get_var($wpdb->prepare(
            "SELECT commission_rate FROM " . self::commissions_table() . " WHERE manager_id = %d AND customer_email = %s",
            absint($manager_id),
            sanitize_email($customer_email)
        ));

        if ($rate !== null) {
            return (float) $rate;
        }

        return (float) get_option('uapm_default_commission', 0);
    }

    public static function get_manager_totals($manager_id) {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT currency, SUM(received_amount) AS received_total, SUM(commission_amount) AS commission_total
             FROM " . self::payments_table() . "
             WHERE manager_id = %d GROUP BY currency",
            absint($manager_id)
        ), ARRAY_A);

        return is_array($rows) ? $rows : array();
    }

    public static function get_manager_payout_status($manager_id) {
        $totals = self::get_manager_totals($manager_id);
        $minimum = max(0, (float) get_option('uapm_minimum_payout', 100));
        $result = array();

        foreach ($totals as $row) {
            $commission = (float) $row['commission_total'];
            $result[] = array(
                'currency' => $row['currency'],
                'commission_total' => $commission,
                'minimum_payout' => $minimum,
                'payout_available' => $commission >= $minimum,
                'remaining' => max(0, round($minimum - $commission, 2)),
            );
        }

        return $result;
    }

    public static function get_payment_url() {
        return esc_url_raw(get_option('uapm_privat_envelope_url', get_option('uapm_payment_url', '')));
    }

    private static function normalize_domain($value) {
        $value = trim((string) $value);
        if (!$value) {
            return '';
        }

        if (strpos($value, '://') === false) {
            $value = 'https://' . $value;
        }

        $host = wp_parse_url($value, PHP_URL_HOST);
        if (!$host) {
            return '';
        }

        $host = strtolower(trim($host, '.'));
        if (!preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(\.([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/', $host)) {
            return '';
        }

        return $host;
    }

    private static function log($project_id, $event, $message) {
        global $wpdb;

        $table = $wpdb->prefix . 'uapm_activity_log';
        $wpdb->insert($table, array(
            'user_id' => get_current_user_id(),
            'project_id' => absint($project_id),
            'event_type' => sanitize_key($event),
            'event' => sanitize_key($event),
            'description' => sanitize_text_field($message),
            'context' => null,
            'meta' => null,
            'object_type' => 'payment',
            'object_id' => 0,
            'created_at' => current_time('mysql'),
        ));
    }

    private static function verify_request($action) {
        check_admin_referer($action);
    }

    private static function redirect_notice($type, $message) {
        wp_safe_redirect(add_query_arg(array(
            'page' => 'uapm',
            'uapm_notice' => sanitize_key($type),
            'uapm_message' => rawurlencode($message),
        ), admin_url('admin.php')));
        exit;
    }

    public static function handle_record_payment() {
        if (!self::can_manage_payments()) {
            wp_die('Недостатньо прав.');
        }

        self::verify_request('uapm_record_payment');

        $result = self::record_receipt(
            absint($_POST['payment_id'] ?? 0),
            (float) wp_unslash($_POST['amount'] ?? 0),
            array(
                'reference' => wp_unslash($_POST['reference'] ?? ''),
                'note' => wp_unslash($_POST['note'] ?? ''),
            )
        );

        if (is_wp_error($result)) {
            self::redirect_notice('error', $result->get_error_message());
        }

        self::redirect_notice('success', 'Надходження записано. PRO активується лише після повної оплати та підтвердження.');
    }

    public static function handle_confirm_payment() {
        if (!self::can_manage_payments()) {
            wp_die('Недостатньо прав.');
        }

        self::verify_request('uapm_confirm_payment');
        $result = self::confirm_payment(absint($_POST['payment_id'] ?? 0));

        if (is_wp_error($result)) {
            self::redirect_notice('error', $result->get_error_message());
        }

        self::redirect_notice('success', 'Платіж підтверджено. PRO активовано.');
    }

    public static function handle_revoke_payment() {
        if (!self::can_manage_payments()) {
            wp_die('Недостатньо прав.');
        }

        self::verify_request('uapm_revoke_payment');
        $result = self::revoke_payment(
            absint($_POST['payment_id'] ?? 0),
            wp_unslash($_POST['reason'] ?? '')
        );

        if (is_wp_error($result)) {
            self::redirect_notice('error', $result->get_error_message());
        }

        self::redirect_notice('success', 'PRO-доступ скасовано.');
    }

    public static function handle_set_commission() {
        if (!self::can_manage_payments()) {
            wp_die('Недостатньо прав.');
        }

        self::verify_request('uapm_set_commission');
        $result = self::set_manager_commission(
            absint($_POST['manager_id'] ?? 0),
            wp_unslash($_POST['customer_email'] ?? ''),
            (float) wp_unslash($_POST['commission_rate'] ?? 0)
        );

        if (is_wp_error($result)) {
            self::redirect_notice('error', $result->get_error_message());
        }

        self::redirect_notice('success', 'Комісію менеджера збережено.');
    }
}

UAPM_Payments::init();
