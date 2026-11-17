<?php
if (!defined('ABSPATH')) {
    exit;
}

class UAPM_Analytics {

    public static function init() {
        add_action('admin_post_uapm_export_analytics', array(__CLASS__, 'export_csv'));
    }

    private static function table($name) {
        global $wpdb;
        return $wpdb->prefix . 'uapm_' . $name;
    }

    public static function get_overview($date_from = '', $date_to = '') {
        global $wpdb;

        $date_from = $date_from ?: date('Y-m-01 00:00:00');
        $date_to = $date_to ?: date('Y-m-d 23:59:59');

        $payments = self::table('payments');
        $installations = self::table('installations');
        $projects = self::table('projects');
        $customers = self::table('customers');

        $result = array(
            'projects' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $projects"),
            'installations' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $installations WHERE status = 'active'"),
            'customers' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $customers"),
            'payments' => 0,
            'revenue' => 0,
            'pending' => 0,
            'partial' => 0,
        );

        $result['payments'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $payments WHERE created_at BETWEEN %s AND %s",
            $date_from,
            $date_to
        ));

        $result['revenue'] = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(received_amount), 0) FROM $payments WHERE created_at BETWEEN %s AND %s",
            $date_from,
            $date_to
        ));

        $result['pending'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM $payments WHERE payment_status = 'pending'"
        );

        $result['partial'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM $payments WHERE payment_status = 'partial'"
        );

        return $result;
    }

    public static function get_project_statistics($project_id) {
        global $wpdb;

        $project_id = absint($project_id);

        if (!$project_id) {
            return array();
        }

        $payments = self::table('payments');
        $installations = self::table('installations');
        $downloads = self::table('downloads');

        return array(
            'installations' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $installations WHERE project_id = %d",
                $project_id
            )),
            'active_installations' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $installations WHERE project_id = %d AND status = 'active'",
                $project_id
            )),
            'payments' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $payments WHERE project_id = %d",
                $project_id
            )),
            'revenue' => (float) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(received_amount), 0) FROM $payments WHERE project_id = %d",
                $project_id
            )),
            'downloads' => self::table_exists($downloads)
                ? (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $downloads WHERE project_id = %d",
                    $project_id
                ))
                : 0,
        );
    }

    public static function get_revenue_by_currency($date_from = '', $date_to = '') {
        global $wpdb;

        $payments = self::table('payments');

        $date_from = $date_from ?: date('Y-m-01 00:00:00');
        $date_to = $date_to ?: date('Y-m-d 23:59:59');

        return $wpdb->get_results($wpdb->prepare(
            "SELECT currency, SUM(received_amount) AS revenue
             FROM $payments
             WHERE created_at BETWEEN %s AND %s
             GROUP BY currency
             ORDER BY currency ASC",
            $date_from,
            $date_to
        ));
    }

    public static function get_monthly_revenue($months = 12) {
        global $wpdb;

        $payments = self::table('payments');
        $months = min(60, max(1, absint($months)));

        return $wpdb->get_results($wpdb->prepare(
            "SELECT DATE_FORMAT(created_at, '%%Y-%%m') AS month,
                    currency,
                    SUM(received_amount) AS revenue,
                    COUNT(*) AS payment_count
             FROM $payments
             WHERE created_at >= DATE_SUB(CURRENT_DATE(), INTERVAL %d MONTH)
             GROUP BY month, currency
             ORDER BY month ASC",
            $months
        ));
    }

    public static function get_top_projects($limit = 10) {
        global $wpdb;

        $projects = self::table('projects');
        $payments = self::table('payments');

        $limit = min(100, max(1, absint($limit)));

        return $wpdb->get_results($wpdb->prepare(
            "SELECT p.id, p.name, p.slug,
                    COUNT(pay.id) AS payment_count,
                    COALESCE(SUM(pay.received_amount), 0) AS revenue
             FROM $projects p
             LEFT JOIN $payments pay ON pay.project_id = p.id
             GROUP BY p.id
             ORDER BY revenue DESC
             LIMIT %d",
            $limit
        ));
    }

    public static function get_recent_activity($limit = 20) {
        global $wpdb;

        $table = self::table('activity_log');
        $limit = min(100, max(1, absint($limit)));

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table ORDER BY created_at DESC LIMIT %d",
            $limit
        ));
    }

    private static function table_exists($table) {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare(
            'SHOW TABLES LIKE %s',
            $wpdb->esc_like($table)
        )) === $table;
    }

    public static function log_activity($event, $object_type = '', $object_id = 0, $description = '', $meta = array()) {
        global $wpdb;

        $wpdb->insert(
            self::table('activity_log'),
            array(
                'user_id' => get_current_user_id(),
                'event' => sanitize_key($event),
                'object_type' => sanitize_key($object_type),
                'object_id' => absint($object_id),
                'description' => sanitize_textarea_field($description),
                'meta' => wp_json_encode($meta),
                'created_at' => current_time('mysql'),
            ),
            array('%d', '%s', '%s', '%d', '%s', '%s', '%s')
        );
    }

    public static function export_csv() {
        if (!current_user_can('uapm_manage_projects')) {
            wp_die('Недостатньо прав.');
        }

        check_admin_referer('uapm_export_analytics');

        $from = sanitize_text_field($_GET['date_from'] ?? '');
        $to = sanitize_text_field($_GET['date_to'] ?? '');

        $data = self::get_overview($from, $to);

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=uapm-analytics.csv');

        $output = fopen('php://output', 'w');

        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        fputcsv($output, array('Показник', 'Значення'));
        fputcsv($output, array('Проєкти', $data['projects']));
        fputcsv($output, array('Активні встановлення', $data['installations']));
        fputcsv($output, array('Клієнти', $data['customers']));
        fputcsv($output, array('Платежі за період', $data['payments']));
        fputcsv($output, array('Отримано коштів', $data['revenue']));
        fputcsv($output, array('Очікують підтвердження', $data['pending']));
        fputcsv($output, array('Часткові платежі', $data['partial']));

        fclose($output);
        exit;
    }

    public static function render_page() {
        if (!current_user_can('uapm_manage_projects')) {
            wp_die('Недостатньо прав.');
        }

        $data = self::get_overview();

        echo '<div class="wrap uapm-admin">';
        echo '<h1>Аналітика UA Project Manager</h1>';

        echo '<div class="uapm-stats-grid">';

        self::render_stat('Проєкти', $data['projects']);
        self::render_stat('Активні встановлення', $data['installations']);
        self::render_stat('Клієнти', $data['customers']);
        self::render_stat('Платежі', $data['payments']);
        self::render_stat('Отримано коштів', number_format_i18n($data['revenue'], 2) . ' грн');
        self::render_stat('Очікують підтвердження', $data['pending']);
        self::render_stat('Часткові платежі', $data['partial']);

        echo '</div>';

        echo '<h2>Дохід за валютами</h2>';
        $currencies = self::get_revenue_by_currency();

        if ($currencies) {
            echo '<table class="widefat striped"><thead><tr><th>Валюта</th><th>Отримано</th></tr></thead><tbody>';

            foreach ($currencies as $currency) {
                echo '<tr>';
                echo '<td>' . esc_html($currency->currency) . '</td>';
                echo '<td>' . esc_html(number_format_i18n((float) $currency->revenue, 2)) . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        } else {
            echo '<p>Платежів поки немає.</p>';
        }

        echo '<h2>Топ проєктів за отриманими платежами</h2>';

        $top = self::get_top_projects();

        if ($top) {
            echo '<table class="widefat striped"><thead><tr><th>Проєкт</th><th>Платежі</th><th>Отримано</th></tr></thead><tbody>';

            foreach ($top as $project) {
                echo '<tr>';
                echo '<td>' . esc_html($project->name) . '</td>';
                echo '<td>' . esc_html($project->payment_count) . '</td>';
                echo '<td>' . esc_html(number_format_i18n((float) $project->revenue, 2)) . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        $export_url = wp_nonce_url(
            admin_url('admin-post.php?action=uapm_export_analytics'),
            'uapm_export_analytics'
        );

        echo '<p><a class="button button-primary" href="' . esc_url($export_url) . '">Експортувати CSV</a></p>';
        echo '</div>';
    }

    private static function render_stat($label, $value) {
        echo '<div class="uapm-stat-card">';
        echo '<span>' . esc_html($label) . '</span>';
        echo '<strong>' . esc_html($value) . '</strong>';
        echo '</div>';
    }
}