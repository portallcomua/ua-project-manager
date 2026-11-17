<?php
/**
 * UA Project Manager
 * Payments, partial receipts, PRO access and manager commissions.
 *
 * @package UA_Project_Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

final class UAPM_Payments {

    public static function init() {
        self::install_schema();

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

    private static function install_schema() {
        static $installed = false;

        if ($installed) {
            return;
        }

        $installed = true;

        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();

        $payments = self::payments_table();
        $transactions = self::transactions_table();
        $commissions = self::commissions_table();

        $sql_payments = "CREATE TABLE {$payments} (
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
        ) {$charset};";

        $sql_transactions = "CREATE TABLE {$transactions} (
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
        ) {$charset};";

        $sql_commissions = "CREATE TABLE {$commissions} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            manager_id bigint(20) unsigned NOT NULL,
            customer_email varchar(191) NOT NULL,
            commission_rate decimal(8,4) NOT NULL DEFAULT 0.0000,
            updated_by bigint(20) unsigned NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY manager_customer (manager_id,customer_email),
            KEY manager_id (manager_id)
        ) {$charset};";

        dbDelta($sql_payments);
        dbDelta($sql_transactions);
        dbDelta($sql_commissions);
    }

    private static function can_manage_payments() {
        return current_user_can('uapm_manage_payments') ||
            current_user_can('manage_options');
    }

    /**
     * Create a pending PRO payment request.
     * Access remains provisional until an administrator confirms payment.
     */
    public static function create_request($data) {
        global $wpdb;

        if (!is_array($data)) {
            return new WP_Error('uapm_invalid_data', __('Invalid payment data.', 'ua-project-manager'));
        }

        $project_id = isset($data['project_id']) ? absint($data['project_id']) : 0;
        $plan_id = isset($data['plan_id']) ? absint($data['plan_id']) : 0;
        $customer_id = isset($data['customer_id']) ? absint($data['customer_id']) : 0;
        $manager_id = isset($data['manager_id']) ? absint($data['manager_id']) : 0;

        $target = isset($data['activation_target'])
            ? sanitize_text_field($data['activation_target'])
            : '';

        $type = isset($data['activation_type'])
            ? sanitize_key($data['activation_type'])
            : 'domain';

        $amount = isset($data['amount']) ? (float) $data['amount'] : 0;

        $currency = isset($data['currency'])
            ? strtoupper(sanitize_text_field($data['currency']))
            : 'UAH';

        $customer_email = isset($data['customer_email'])
            ? sanitize_email($data['customer_email'])
            : '';

        if (!$project_id || !class_exists('UAPM_Projects') || !UAPM_Projects::get($project_id)) {
            return new WP_Error('uapm_invalid_project', __('Project not found.', 'ua-project-manager'));
        }

        if (!$target) {
            return new WP_Error('uapm_missing_target', __('Activation domain or email is required.', 'ua-project-manager'));
        }

        if (!in_array($type, array('domain', 'email'), true)) {
            return new WP_Error('uapm_invalid_target_type', __('Activation type must be domain or email.', 'ua-project-manager'));
        }

        if ($type === 'email') {
            $target = sanitize_email($target);

            if (!is_email($target)) {
                return new WP_Error('uapm_invalid_email', __('Enter a valid activation email.', 'ua-project-manager'));
            }

            if (!$customer_email) {
                $customer_email = $target;
            }
        }

        if ($type === 'domain') {
            $target = self::normalize_domain($target);

            if (!$target) {
                return new WP_Error('uapm_invalid_domain', __('Enter a valid activation domain.', 'ua-project-manager'));
            }
        }

        if ($customer_email && !is_email($customer_email)) {
            return new WP_Error('uapm_invalid_customer_email', __('Invalid customer email.', 'ua-project-manager'));
        }

        if ($amount <= 0 || !is_finite($amount)) {
            return new WP_Error('uapm_invalid_amount', __('Payment amount must be greater than zero.', 'ua-project-manager'));
        }

        if (!preg_match('/^[A-Z]{3,10}$/', $currency)) {
            return new WP_Error('uapm_invalid_currency', __('Invalid currency.', 'ua-project-manager'));
        }

        if ($plan_id) {
            $plan = UAPM_Projects::get_plan($plan_id);

            if (!$plan || (int) $plan->project_id !== $project_id || $plan->plan_type !== 'pro') {
                return new WP_Error('uapm_invalid_plan', __('Selected PRO plan does not belong to this project.', 'ua-project-manager'));
            }
        }

        if ($customer_id) {
            $customer = $wpdb->get_row(
                $wpdb->prepare(
                    'SELECT email FROM ' . $wpdb->prefix . 'uapm_customers WHERE id = %d',
                    $customer_id
                )
            );

            if ($customer && is_email($customer->email) && !$customer_email) {
                $customer_email = sanitize_email($customer->email);
            }
        }

        $commission_rate = 0;

        if ($manager_id && $customer_email) {
            $commission_rate = self::get_manager_commission($manager_id, $customer_email);
        }

        $now = current_time('mysql');

        $inserted = $wpdb->insert(
            self::payments_table(),
            array(
                'project_id' => $project_id,
                'plan_id' => $plan_id ?: null,
                'customer_id' => $customer_id ?: null,
                'customer_email' => $customer_email ?: null,
                'activation_target' => $target,
                'activation_type' => $type,
                'amount' => number_format($amount, 2, '.', ''),
                'currency' => $currency,
                'payment_status' => 'pending',
                'payment_method' => isset($data['payment_method'])
                    ? sanitize_key($data['payment_method'])
                    : 'privat_envelope',
                'manager_id' => $manager_id ?: null,
                'commission_rate' => number_format($commission_rate, 4, '.', ''),
                'commission_amount' => '0.00',
                'received_amount' => '0.00',
                'activation_status' => 'provisional',
                'created_at' => $now,
                'updated_at' => $now,
            )
        );

        if (!$inserted) {
            return new WP_Error('uapm_database_error', __('Could not create payment request.', 'ua-project-manager'));
        }

        $payment_id = (int) $wpdb->insert_id;

        self::log($project_id, 'payment_request_created', 'PRO payment request #' . $payment_id . ' created.');

        do_action('uapm_payment_request_created', $payment_id, $data);

        return $payment_id;
    }

    public static function get($payment_id) {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . self::payments_table() . ' WHERE id = %d',
                absint($payment_id)
            )
        );
    }

    public static function get_all($args = array()) {
        global $wpdb;

        $defaults = array(
            'project_id' => 0,
            'status' => '',
            'manager_id' => 0,
            'limit' => 50,
            'offset' => 0,
        );

        $args = wp_parse_args($args, $defaults);

        $where = array('1=1');
        $values = array();

        if ($args['project_id']) {
            $where[] = 'project_id = %d';
            $values[] = absint($args['project_id']);
        }

        if ($args['status']) {
            $where[] = 'payment_status = %s';
            $values[] = sanitize_key($args['status']);
        }

        if ($args['manager_id']) {
            $where[] = 'manager_id = %d';
            $values[] = absint($args['manager_id']);
        }

        $limit = min(200, max(1, absint($args['limit'])));
        $offset = max(0, absint($args['offset']));

        $sql = 'SELECT * FROM ' . self::payments_table() .
            ' WHERE ' . implode(' AND ', $where) .
            ' ORDER BY id DESC LIMIT %d OFFSET %d';

        $values[] = $limit;
        $values[] = $offset;

        return $wpdb->get_results($wpdb->prepare($sql, $values));
    }

    /**
     * Record actual received money.
     * Uses a database transaction to prevent duplicate concurrent receipts.
     */
    public static function record_receipt($payment_id, $amount, $details = array()) {
        global $wpdb;

        if (!self::can_manage_payments()) {
            return new WP_Error('uapm_forbidden', __('You cannot record payments.', 'ua-project-manager'));
        }

        $payment_id = absint($payment_id);
        $amount = round((float) $amount, 2);

        if (!$payment_id || $amount <= 0 || !is_finite($amount)) {
            return new WP_Error('uapm_invalid_amount', __('Received amount must be greater than zero.', 'ua-project-manager'));
        }

        $wpdb->query('START TRANSACTION');

        $payment = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . self::payments_table() . ' WHERE id = %d FOR UPDATE',
                $payment_id
            )
        );

        if (!$payment) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('uapm_payment_not_found', __('Payment request not found.', 'ua-project-manager'));
        }

        if (in_array($payment->payment_status, array('paid', 'revoked'), true)) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('uapm_payment_closed', __('This payment request is already closed.', 'ua-project-manager'));
        }

        $remaining = round((float) $payment->amount - (float) $payment->received_amount, 2);

        if ($amount > $remaining + 0.0001) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('uapm_overpayment', __('Received amount exceeds the remaining payment balance.', 'ua-project-manager'));
        }

        $rate = (float) $payment->commission_rate;
        $commission = round($amount * $rate / 100, 2);

        $inserted = $wpdb->insert(
            self::transactions_table(),
            array(
                'payment_id' => $payment_id,
                'amount' => number_format($amount, 2, '.', ''),
                'currency' => $payment->currency,
                'commission_rate' => number_format($rate, 4, '.', ''),
                'commission_amount' => number_format($commission, 2, '.', ''),
                'reference' => isset($details['reference'])
                    ? sanitize_text_field($details['reference'])
                    : '',
                'note' => isset($details['note'])
                    ? sanitize_textarea_field($details['note'])
                    : '',
                'received_by' => get_current_user_id(),
                'received_at' => current_time('mysql'),
            )
        );

        if (!$inserted) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('uapm_database_error', __('Could not record received payment.', 'ua-project-manager'));
        }

        $transaction_id = (int) $wpdb->insert_id;
        $new_received = round((float) $payment->received_amount + $amount, 2);
        $new_commission = round((float) $payment->commission_amount + $commission, 2);

        $new_status = $new_received >= (float) $payment->amount - 0.0001
            ? 'pending'
            : 'partial';

        $updated = $wpdb->update(
            self::payments_table(),
            array(
                'received_amount' => number_format($new_received, 2, '.', ''),
                'commission_amount' => number_format($new_commission, 2, '.', ''),
                'payment_status' => $new_status,
                'updated_at' => current_time('mysql'),
            ),
            array('id' => $payment_id)
        );

        if (false === $updated) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('uapm_database_error', __('Could not update payment balance.', 'ua-project-manager'));
        }

        $wpdb->query('COMMIT');

        self::log(
            (int) $payment->project_id,
            'payment_receipt_recorded',
            'Received ' . number_format($amount, 2, '.', '') . ' ' . $payment->currency . ' for payment #' . $payment_id
        );

        do_action('uapm_payment_receipt_recorded', $payment_id, $transaction_id, $amount);

        return $transaction_id;
    }

    public static function confirm_payment($payment_id) {
        global $wpdb;

        if (!self::can_manage_payments()) {
            return new WP_Error('uapm_forbidden', __('You cannot confirm payments.', 'ua-project-manager'));
        }

        $payment = self::get($payment_id);

        if (!$payment) {
            return new WP_Error('uapm_payment_not_found', __('Payment request not found.', 'ua-project-manager'));
        }

        if ($payment->payment_status === 'revoked') {
            return new WP_Error('uapm_payment_revoked', __('A revoked payment request cannot be confirmed.', 'ua-project-manager'));
        }

        if ((float) $payment->received_amount + 0.0001 < (float) $payment->amount) {
            return new WP_Error('uapm_payment_incomplete', __('The full requested amount has not been received yet.', 'ua-project-manager'));
        }

        $now = current_time('mysql');

        $result = $wpdb->update(
            self::payments_table(),
            array(
                'payment_status' => 'paid',
                'activation_status' => 'active',
                'confirmed_by' => get_current_user_id(),
                'confirmed_at' => $now,
                'paid_at' => $now,
                'updated_at' => $now,
            ),
            array('id' => absint($payment_id))
        );

        if (false === $result) {
            return new WP_Error('uapm_database_error', __('Could not confirm payment.', 'ua-project-manager'));
        }

        self::log((int) $payment->project_id, 'payment_confirmed', 'Payment #' . absint($payment_id) . ' confirmed.');

        do_action('uapm_payment_confirmed', absint($payment_id), $payment);

        do_action(
            'uapm_pro_access_changed',
            (int) $payment->project_id,
            $payment->activation_target,
            $payment->activation_type,
            true,
            absint($payment_id)
        );

        return true;
    }

    public static function revoke_payment($payment_id, $reason = '') {
        global $wpdb;

        if (!self::can_manage_payments()) {
            return new WP_Error('uapm_forbidden', __('You cannot revoke PRO access.', 'ua-project-manager'));
        }

        $payment = self::get($payment_id);

        if (!$payment) {
            return new WP_Error('uapm_payment_not_found', __('Payment request not found.', 'ua-project-manager'));
        }

        $result = $wpdb->update(
            self::payments_table(),
            array(
                'payment_status' => 'revoked',
                'activation_status' => 'revoked',
                'updated_at' => current_time('mysql'),
            ),
            array('id' => absint($payment_id))
        );

        if (false === $result) {
            return new WP_Error('uapm_database_error', __('Could not revoke PRO access.', 'ua-project-manager'));
        }

        $reason = sanitize_textarea_field($reason);

        self::log(
            (int) $payment->project_id,
            'payment_revoked',
            'Payment #' . absint($payment_id) . ' revoked. ' . $reason
        );

        do_action('uapm_payment_revoked', absint($payment_id), $payment, $reason);

        do_action(
            'uapm_pro_access_changed',
            (int) $payment->project_id,
            $payment->activation_target,
            $payment->activation_type,
            false,
            absint($payment_id)
        );

        return true;
    }

    /**
     * Return current PRO activation state.
     * Pending and partial requests are provisional.
     */
    public static function get_activation_status($project_id, $target, $type = 'domain') {
        global $wpdb;

        $project_id = absint($project_id);
        $type = sanitize_key($type);

        if ($type === 'domain') {
            $target = self::normalize_domain($target);
        } elseif ($type === 'email') {
            $target = sanitize_email($target);
        } else {
            $target = '';
        }

        if (!$project_id || !$target) {
            return array(
                'active' => false,
                'confirmed' => false,
                'status' => 'invalid',
            );
        }

        $payment = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . self::payments_table() .
                ' WHERE project_id = %d AND activation_target = %s AND activation_type = %s
                ORDER BY id DESC LIMIT 1',
                $project_id,
                $target,
                $type
            )
        );

        if (!$payment) {
            return array(
                'active' => false,
                'confirmed' => false,
                'status' => 'not_found',
            );
        }

        $active = in_array(
            $payment->payment_status,
            array('pending', 'partial', 'paid'),
            true
        );

        return array(
            'active' => $active,
            'confirmed' => $payment->payment_status === 'paid',
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

        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . self::transactions_table() .
                ' WHERE payment_id = %d ORDER BY id ASC',
                absint($payment_id)
            )
        );
    }

    public static function set_manager_commission($manager_id, $customer_email, $rate) {
        global $wpdb;

        if (!self::can_manage_payments()) {
            return new WP_Error('uapm_forbidden', __('You cannot manage commissions.', 'ua-project-manager'));
        }

        $manager_id = absint($manager_id);
        $customer_email = sanitize_email($customer_email);
        $rate = (float) $rate;

        if (!$manager_id || !get_user_by('id', $manager_id)) {
            return new WP_Error('uapm_invalid_manager', __('Manager account not found.', 'ua-project-manager'));
        }

        if (!is_email($customer_email)) {
            return new WP_Error('uapm_invalid_email', __('Enter a valid customer email.', 'ua-project-manager'));
        }

        if ($rate < 0 || $rate > 100 || !is_finite($rate)) {
            return new WP_Error('uapm_invalid_rate', __('Commission must be between 0 and 100 percent.', 'ua-project-manager'));
        }

        $table = self::commissions_table();

        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE manager_id = %d AND customer_email = %s",
                $manager_id,
                $customer_email
            )
        );

        $record = array(
            'manager_id' => $manager_id,
            'customer_email' => $customer_email,
            'commission_rate' => number_format($rate, 4, '.', ''),
            'updated_by' => get_current_user_id(),
            'updated_at' => current_time('mysql'),
        );

        if ($existing) {
            $result = $wpdb->update(
                $table,
                $record,
                array('id' => absint($existing))
            );
        } else {
            $result = $wpdb->insert($table, $record);
        }

        if (false === $result) {
            return new WP_Error('uapm_database_error', __('Could not save manager commission.', 'ua-project-manager'));
        }

        return true;
    }

    public static function get_manager_commission($manager_id, $customer_email) {
        global $wpdb;

        $rate = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT commission_rate FROM ' . self::commissions_table() .
                ' WHERE manager_id = %d AND customer_email = %s',
                absint($manager_id),
                sanitize_email($customer_email)
            )
        );

        return null === $rate ? 0.0 : (float) $rate;
    }

    public static function get_manager_totals($manager_id) {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT currency, SUM(received_amount) AS received_total,
                 SUM(commission_amount) AS commission_total
                 FROM ' . self::payments_table() .
                ' WHERE manager_id = %d
                 GROUP BY currency',
                absint($manager_id)
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : array();
    }

    public static function get_payment_url() {
        $url = get_option('uapm_privat_envelope_url', '');

        if (!$url) {
            $url = get_option('uapm_payment_url', '');
        }

        return esc_url_raw($url);
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

        $wpdb->insert(
            $wpdb->prefix . 'uapm_activity_log',
            array(
                'user_id' => get_current_user_id(),
                'project_id' => absint($project_id),
                'event_type' => sanitize_key($event),
                'description' => sanitize_text_field($message),
                'context' => null,
                'created_at' => current_time('mysql'),
            )
        );
    }

    private static function verify_request($action) {
        if (!isset($_REQUEST['_wpnonce'])) {
            wp_die(esc_html__('Security verification failed.', 'ua-project-manager'));
        }

        check_admin_referer($action);
    }

    public static function handle_record_payment() {
        if (!self::can_manage_payments()) {
            wp_die(esc_html__('Access denied.', 'ua-project-manager'));
        }

        self::verify_request('uapm_record_payment');

        $payment_id = isset($_POST['payment_id'])
            ? absint($_POST['payment_id'])
            : 0;

        $amount = isset($_POST['amount'])
            ? (float) wp_unslash($_POST['amount'])
            : 0;

        $details = array(
            'reference' => isset($_POST['reference'])
                ? wp_unslash($_POST['reference'])
                : '',
            'note' => isset($_POST['note'])
                ? wp_unslash($_POST['note'])
                : '',
        );

        $result = self::record_receipt($payment_id, $amount, $details);

        if (is_wp_error($result)) {
            self::redirect_notice('error', $result->get_error_message());
        }

        self::redirect_notice('success', __('Received payment recorded. PRO still requires manual confirmation.', 'ua-project-manager'));
    }

    public static function handle_confirm_payment() {
        if (!self::can_manage_payments()) {
            wp_die(esc_html__('Access denied.', 'ua-project-manager'));
        }

        self::verify_request('uapm_confirm_payment');

        $payment_id = isset($_POST['payment_id'])
            ? absint($_POST['payment_id'])
            : 0;

        $result = self::confirm_payment($payment_id);

        if (is_wp_error($result)) {
            self::redirect_notice('error', $result->get_error_message());
        }

        self::redirect_notice('success', __('Payment confirmed. PRO access is active.', 'ua-project-manager'));
    }

    public static function handle_revoke_payment() {
        if (!self::can_manage_payments()) {
            wp_die(esc_html__('Access denied.', 'ua-project-manager'));
        }

        self::verify_request('uapm_revoke_payment');

        $payment_id = isset($_POST['payment_id'])
            ? absint($_POST['payment_id'])
            : 0;

        $reason = isset($_POST['reason'])
            ? wp_unslash($_POST['reason'])
            : '';

        $result = self::revoke_payment($payment_id, $reason);

        if (is_wp_error($result)) {
            self::redirect_notice('error', $result->get_error_message());
        }

        self::redirect_notice('success', __('PRO access revoked.', 'ua-project-manager'));
    }

    public static function handle_set_commission() {
        if (!self::can_manage_payments()) {
            wp_die(esc_html__('Access denied.', 'ua-project-manager'));
        }

        self::verify_request('uapm_set_commission');

        $manager_id = isset($_POST['manager_id'])
            ? absint($_POST['manager_id'])
            : 0;

        $email = isset($_POST['customer_email'])
            ? wp_unslash($_POST['customer_email'])
            : '';

        $rate = isset($_POST['commission_rate'])
            ? (float) wp_unslash($_POST['commission_rate'])
            : 0;

        $result = self::set_manager_commission($manager_id, $email, $rate);

        if (is_wp_error($result)) {
            self::redirect_notice('error', $result->get_error_message());
        }

        self::redirect_notice('success', __('Manager commission saved.', 'ua-project-manager'));
    }

    private static function redirect_notice($type, $message) {
        $url = add_query_arg(
            array(
                'page' => 'uapm',
                'uapm_notice' => sanitize_key($type),
                'uapm_message' => rawurlencode($message),
            ),
            admin_url('admin.php')
        );

        wp_safe_redirect($url);
        exit;
    }
}
