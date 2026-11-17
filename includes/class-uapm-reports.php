<?php
if (!defined('ABSPATH')) {
    exit;
}

class UAPM_Reports {

    public static function init() {
        add_action('admin_post_uapm_export_report', array(__CLASS__, 'handle_export'));
    }

    public static function get_overview($date_from = '', $date_to = '') {
        global $wpdb;

        $payments = $wpdb->prefix . 'uapm_payments';
        $projects = $wpdb->prefix . 'uapm_projects';
        $installations = $wpdb->prefix . 'uapm_installations';
        $customers = $wpdb->prefix . 'uapm_customers';

        $date_from = self::normalize_date($date_from, 'first');
        $date_to = self::normalize_date($date_to, 'last');

        $payment_where = '';
        $install_where = '';
        $customer_where = '';

        if ($date_from) {
            $payment_where .= $wpdb->prepare(' AND created_at >= %s', $date_from);
            $install_where .= $wpdb->prepare(' AND created_at >= %s', $date_from);
            $customer_where .= $wpdb->prepare(' AND created_at >= %s', $date_from);
        }

        if ($date_to) {
            $payment_where .= $wpdb->prepare(' AND created_at <= %s', $date_to);
            $install_where .= $wpdb->prepare(' AND created_at <= %s', $date_to);
            $customer_where .= $wpdb->prepare(' AND created_at <= %s', $date_to);
        }

        return array(
            'projects' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$projects} WHERE status != 'archived'"),
            'installations' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$installations} WHERE 1=1 {$install_where}"),
            'customers' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$customers} WHERE 1=1 {$customer_where}"),
            'payments' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$payments} WHERE 1=1 {$payment_where}"),
            'received' => self::get_received_by_currency($date_from, $date_to),
            'pending' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$payments} WHERE status IN ('pending','partial') {$payment_where}"),
            'revoked' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$payments} WHERE status = 'revoked' {$payment_where}"),
        );
    }

    private static function normalize_date($date, $edge) {
        if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return '';
        }

        $timestamp = strtotime($date);

        if (!$timestamp) {
            return '';
        }

        return $edge === 'last'
            ? date('Y-m-d 23:59:59', $timestamp)
            : date('Y-m-d 00:00:00', $timestamp);
    }

    private static function get_received_by_currency($date_from = '', $date_to = '') {
        global $wpdb;

        $table = $wpdb->prefix . 'uapm_payments';
        $where = array("status IN ('partial','paid')");
        $values = array();

        if ($date_from) {
            $where[] = 'created_at >= %s';
            $values[] = $date_from;
        }

        if ($date_to) {
            $where[] = 'created_at <= %s';
            $values[] = $date_to;
        }

        $sql = "SELECT currency, SUM(received_amount) AS total
            FROM {$table}
            WHERE " . implode(' AND ', $where) . "
            GROUP BY currency";

        if ($values) {
            $sql = $wpdb->prepare($sql, $values);
        }

        return $wpdb->get_results($sql);
    }

    public static function get_monthly_revenue($months = 12) {
        global $wpdb;

        $table = $wpdb->prefix . 'uapm_payments';
        $months = max(1, min(36, absint($months)));

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DATE_FORMAT(created_at, '%%Y-%%m') AS month,
                    currency,
                    SUM(received_amount) AS total
                FROM {$table}
                WHERE status IN ('partial','paid')
                AND created_at >= DATE_SUB(NOW(), INTERVAL %d MONTH)
                GROUP BY month, currency
                ORDER BY month ASC",
                $months
            )
        );
    }

    public static function get_top_projects($limit = 10) {
        global $wpdb;

        $payments = $wpdb->prefix . 'uapm_payments';
        $projects = $wpdb->prefix . 'uapm_projects';
        $limit = max(1, min(50, absint($limit)));

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.id, p.name, p.slug,
                    COUNT(pay.id) AS payment_count,
                    SUM(pay.received_amount) AS received_amount
                FROM {$projects} p
                LEFT JOIN {$payments} pay
                    ON pay.project_id = p.id
                    AND pay.status IN ('partial','paid')
                WHERE p.status != 'archived'
                GROUP BY p.id
                ORDER BY received_amount DESC
                LIMIT %d",
                $limit
            )
        );
    }

    public static function get_recent_payments($limit = 20) {
        global $wpdb;

        $payments = $wpdb->prefix . 'uapm_payments';
        $projects = $wpdb->prefix . 'uapm_projects';
        $limit = max(1, min(100, absint($limit)));

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT pay.*, p.name AS project_name
                FROM {$payments} pay
                LEFT JOIN {$projects} p ON p.id = pay.project_id
                ORDER BY pay.created_at DESC
                LIMIT %d",
                $limit
            )
        );
    }

    public static function export_csv($date_from = '', $date_to = '') {
        global $wpdb;

        $table = $wpdb->prefix . 'uapm_payments';

        $date_from = self::normalize_date($date_from, 'first');
        $date_to = self::normalize_date($date_to, 'last');

        $where = array('1=1');
        $values = array();

        if ($date_from) {
            $where[] = 'created_at >= %s';
            $values[] = $date_from;
        }

        if ($date_to) {
            $where[] = 'created_at <= %s';
            $values[] = $date_to;
        }

        $sql = "SELECT id, project_id, customer_email, target, amount,
            received_amount, currency, status, activation_status,
            created_at, paid_at
            FROM {$table}
            WHERE " . implode(' AND ', $where) . "
            ORDER BY created_at DESC";

        if ($values) {
            $sql = $wpdb->prepare($sql, $values);
        }

        return $wpdb->get_results($sql, ARRAY_A);
    }

    public static function handle_export() {
        if (!current_user_can('uapm_manage_settings')) {
            wp_die('Недостатньо прав.');
        }

        check_admin_referer('uapm_export_report');

        $from = sanitize_text_field(wp_unslash($_POST['date_from'] ?? ''));
        $to = sanitize_text_field(wp_unslash($_POST['date_to'] ?? ''));

        $rows = self::export_csv($from, $to);

        nocache_headers();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=uapm-report-' . gmdate('Y-m-d') . '.csv');

        $output = fopen('php://output', 'w');

        // BOM для коректного відображення українського тексту в Excel.
        fwrite($output, "\xEF\xBB\xBF");

        fputcsv($output, array(
            'ID',
            'Project ID',
            'Customer Email',
            'Target',
            'Amount',
            'Received Amount',
            'Currency',
            'Status',
            'Activation Status',
            'Created At',
            'Paid At',
        ), ';');

        foreach ($rows as $row) {
            fputcsv($output, $row, ';');
        }

        fclose($output);
        exit;
    }

    public static function render_page() {
        if (!current_user_can('uapm_manage_settings')) {
            wp_die('Недостатньо прав.');
        }

        $from = sanitize_text_field(wp_unslash($_GET['date_from'] ?? ''));
        $to = sanitize_text_field(wp_unslash($_GET['date_to'] ?? ''));

        $stats = self::get_overview($from, $to);
        $recent = self::get_recent_payments(20);
        $top_projects = self::get_top_projects(10);
        $monthly = self::get_monthly_revenue(12);

        echo '<div class="wrap uapm-wrap">';
        echo '<h1>Звіти та аналітика</h1>';

        echo '<form method="get" class="uapm-card">';
        echo '<input type="hidden" name="page" value="uapm-reports">';
        echo '<label>Від: <input type="date" name="date_from" value="' . esc_attr($from) . '"></label> ';
        echo '<label>До: <input type="date" name="date_to" value="' . esc_attr($to) . '"></label> ';
        submit_button('Застосувати', 'secondary', '', false);
        echo '</form>';

        echo '<div class="uapm-stats">';

        $cards = array(
            'projects' => 'Проєкти',
            'installations' => 'Встановлення',
            'customers' => 'Клієнти',
            'payments' => 'Платежі',
            'pending' => 'Очікують оплату',
            'revoked' => 'Скасовані',
        );

        foreach ($cards as $key => $label) {
            echo '<div class="uapm-card">';
            echo '<strong>' . esc_html($stats[$key]) . '</strong>';
            echo '<span>' . esc_html($label) . '</span>';
            echo '</div>';
        }

        echo '</div>';

        echo '<div class="uapm-card">';
        echo '<h2>Отримані кошти за валютами</h2>';

        if (!$stats['received']) {
            echo '<p>Даних поки немає.</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>Валюта</th><th>Отримано</th></tr></thead><tbody>';

            foreach ($stats['received'] as $item) {
                echo '<tr><td>' . esc_html($item->currency) . '</td>';
                echo '<td>' . esc_html(number_format_i18n((float) $item->total, 2)) . '</td></tr>';
            }

            echo '</tbody></table>';
        }

        echo '</div>';

        echo '<div class="uapm-card">';
        echo '<h2>Динаміка надходжень за місяцями</h2>';

        if (!$monthly) {
            echo '<p>Даних поки немає.</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>Місяць</th><th>Валюта</th><th>Сума</th></tr></thead><tbody>';

            foreach ($monthly as $item) {
                echo '<tr>';
                echo '<td>' . esc_html($item->month) . '</td>';
                echo '<td>' . esc_html($item->currency) . '</td>';
                echo '<td>' . esc_html(number_format_i18n((float) $item->total, 2)) . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        echo '</div>';

        echo '<div class="uapm-card">';
        echo '<h2>Проєкти за надходженнями</h2>';
        echo '<table class="widefat striped"><thead><tr><th>Проєкт</th><th>Платежі</th><th>Отримано</th></tr></thead><tbody>';

        if (!$top_projects) {
            echo '<tr><td colspan="3">Даних поки немає.</td></tr>';
        }

        foreach ($top_projects as $project) {
            echo '<tr>';
            echo '<td>' . esc_html($project->name) . '</td>';
            echo '<td>' . esc_html($project->payment_count) . '</td>';
            echo '<td>' . esc_html(number_format_i18n((float) $project->received_amount, 2)) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';

        echo '<div class="uapm-card">';
        echo '<h2>Останні платежі</h2>';
        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Проєкт</th><th>Клієнт</th><th>Сума</th><th>Отримано</th><th>Статус</th><th>Дата</th></tr></thead><tbody>';

        if (!$recent) {
            echo '<tr><td colspan="7">Платежів поки немає.</td></tr>';
        }

        foreach ($recent as $payment) {
            echo '<tr>';
            echo '<td>' . esc_html($payment->id) . '</td>';
            echo '<td>' . esc_html($payment->project_name ?: '—') . '</td>';
            echo '<td>' . esc_html($payment->customer_email) . '</td>';
            echo '<td>' . esc_html($payment->amount . ' ' . $payment->currency) . '</td>';
            echo '<td>' . esc_html($payment->received_amount . ' ' . $payment->currency) . '</td>';
            echo '<td>' . esc_html($payment->status) . '</td>';
            echo '<td>' . esc_html($payment->created_at) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';

        echo '<div class="uapm-card">';
        echo '<h2>Експорт платежів</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="uapm_export_report">';
        wp_nonce_field('uapm_export_report');
        echo '<label>Від: <input type="date" name="date_from"></label> ';
        echo '<label>До: <input type="date" name="date_to"></label> ';
        submit_button('Завантажити CSV', 'primary', 'submit', false);
        echo '</form>';
        echo '</div>';

        echo '</div>';
    }
}