<?php
if (!defined('ABSPATH')) {
    exit;
}

class UAPM_Export {

    public static function init() {
        add_action('admin_post_uapm_export_projects', array(__CLASS__, 'export_projects'));
        add_action('admin_post_uapm_export_payments', array(__CLASS__, 'export_payments'));
        add_action('admin_post_uapm_export_customers', array(__CLASS__, 'export_customers'));
        add_action('admin_post_uapm_export_installations', array(__CLASS__, 'export_installations'));
        add_action('admin_post_uapm_export_activity', array(__CLASS__, 'export_activity'));
    }

    private static function authorize($action) {
        if (!current_user_can('uapm_manage_settings')) {
            wp_die('Недостатньо прав для експорту.');
        }

        check_admin_referer($action);
    }

    private static function send_csv($filename, $headers, $rows) {
        nocache_headers();

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($filename) . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');

        if (!$output) {
            wp_die('Не вдалося створити CSV-файл.');
        }

        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, $headers, ';');

        foreach ($rows as $row) {
            $safe_row = array();

            foreach ($row as $value) {
                if (is_array($value) || is_object($value)) {
                    $value = wp_json_encode($value, JSON_UNESCAPED_UNICODE);
                }

                $value = (string) $value;

                if (preg_match('/^[\s]*[=+\-@]/u', $value)) {
                    $value = "'" . $value;
                }

                $safe_row[] = $value;
            }

            fputcsv($output, $safe_row, ';');
        }

        fclose($output);
        exit;
    }

    public static function export_projects() {
        self::authorize('uapm_export_projects');

        global $wpdb;

        $table = $wpdb->prefix . 'uapm_projects';

        $items = $wpdb->get_results(
            "SELECT * FROM {$table} ORDER BY id DESC",
            ARRAY_A
        );

        $rows = array();

        foreach ($items as $item) {
            $rows[] = array(
                $item['id'] ?? '',
                $item['name'] ?? '',
                $item['slug'] ?? '',
                $item['description'] ?? '',
                $item['project_type'] ?? '',
                $item['status'] ?? '',
                $item['version'] ?? '',
                $item['github_repo'] ?? '',
                $item['created_at'] ?? '',
                $item['updated_at'] ?? '',
            );
        }

        self::send_csv(
            'uapm-projects-' . gmdate('Y-m-d') . '.csv',
            array('ID', 'Назва', 'Slug', 'Опис', 'Тип', 'Статус', 'Версія', 'GitHub', 'Створено', 'Оновлено'),
            $rows
        );
    }

    public static function export_payments() {
        self::authorize('uapm_export_payments');

        global $wpdb;

        $table = $wpdb->prefix . 'uapm_payments';

        $items = $wpdb->get_results(
            "SELECT * FROM {$table} ORDER BY id DESC",
            ARRAY_A
        );

        $rows = array();

        foreach ($items as $item) {
            $rows[] = array(
                $item['id'] ?? '',
                $item['project_id'] ?? '',
                $item['customer_email'] ?? '',
                $item['customer_name'] ?? '',
                $item['plan'] ?? '',
                $item['amount'] ?? '',
                $item['received_amount'] ?? '',
                $item['currency'] ?? '',
                $item['status'] ?? '',
                $item['activation_status'] ?? '',
                $item['target'] ?? '',
                $item['created_at'] ?? '',
                $item['paid_at'] ?? '',
            );
        }

        self::send_csv(
            'uapm-payments-' . gmdate('Y-m-d') . '.csv',
            array('ID', 'Проєкт', 'Email', 'Клієнт', 'Тариф', 'Сума', 'Отримано', 'Валюта', 'Статус платежу', 'PRO', 'Ціль активації', 'Створено', 'Оплачено'),
            $rows
        );
    }

    public static function export_customers() {
        self::authorize('uapm_export_customers');

        global $wpdb;

        $table = $wpdb->prefix . 'uapm_customers';

        $items = $wpdb->get_results(
            "SELECT * FROM {$table} ORDER BY id DESC",
            ARRAY_A
        );

        $rows = array();

        foreach ($items as $item) {
            $rows[] = array(
                $item['id'] ?? '',
                $item['name'] ?? '',
                $item['email'] ?? '',
                $item['phone'] ?? '',
                $item['company'] ?? '',
                $item['country'] ?? '',
                $item['customer_type'] ?? '',
                $item['status'] ?? '',
                $item['mailing_consent'] ?? '',
                $item['created_at'] ?? '',
            );
        }

        self::send_csv(
            'uapm-customers-' . gmdate('Y-m-d') . '.csv',
            array('ID', 'Ім’я', 'Email', 'Телефон', 'Компанія', 'Країна', 'Тип', 'Статус', 'Згода на розсилку', 'Створено'),
            $rows
        );
    }

    public static function export_installations() {
        self::authorize('uapm_export_installations');

        global $wpdb;

        $table = $wpdb->prefix . 'uapm_installations';

        $items = $wpdb->get_results(
            "SELECT * FROM {$table} ORDER BY id DESC",
            ARRAY_A
        );

        $rows = array();

        foreach ($items as $item) {
            $rows[] = array(
                $item['id'] ?? '',
                $item['project_id'] ?? '',
                $item['site_url'] ?? '',
                $item['site_name'] ?? '',
                $item['version'] ?? '',
                $item['status'] ?? '',
                $item['last_seen'] ?? '',
                $item['created_at'] ?? '',
            );
        }

        self::send_csv(
            'uapm-installations-' . gmdate('Y-m-d') . '.csv',
            array('ID', 'Проєкт', 'URL сайту', 'Назва сайту', 'Версія', 'Статус', 'Остання активність', 'Створено'),
            $rows
        );
    }

    public static function export_activity() {
        self::authorize('uapm_export_activity');

        global $wpdb;

        $table = $wpdb->prefix . 'uapm_activity_log';

        $items = $wpdb->get_results(
            "SELECT * FROM {$table} ORDER BY id DESC LIMIT 10000",
            ARRAY_A
        );

        $rows = array();

        foreach ($items as $item) {
            $rows[] = array(
                $item['id'] ?? '',
                $item['event_type'] ?? '',
                $item['description'] ?? '',
                $item['user_id'] ?? '',
                $item['project_id'] ?? '',
                $item['created_at'] ?? '',
            );
        }

        self::send_csv(
            'uapm-activity-' . gmdate('Y-m-d') . '.csv',
            array('ID', 'Подія', 'Опис', 'Користувач', 'Проєкт', 'Дата'),
            $rows
        );
    }

    public static function render_page() {
        if (!current_user_can('uapm_manage_settings')) {
            wp_die('Недостатньо прав.');
        }

        $exports = array(
            array(
                'title' => 'Проєкти',
                'description' => 'Експорт списку всіх проєктів.',
                'action' => 'uapm_export_projects',
                'nonce' => 'uapm_export_projects',
            ),
            array(
                'title' => 'Платежі',
                'description' => 'Експорт платежів, сум надходжень та статусів PRO.',
                'action' => 'uapm_export_payments',
                'nonce' => 'uapm_export_payments',
            ),
            array(
                'title' => 'Клієнти',
                'description' => 'Експорт контактів та згоди на розсилку.',
                'action' => 'uapm_export_customers',
                'nonce' => 'uapm_export_customers',
            ),
            array(
                'title' => 'Інсталяції',
                'description' => 'Експорт підключених сайтів та версій.',
                'action' => 'uapm_export_installations',
                'nonce' => 'uapm_export_installations',
            ),
            array(
                'title' => 'Журнал активності',
                'description' => 'Експорт останніх 10 000 записів журналу.',
                'action' => 'uapm_export_activity',
                'nonce' => 'uapm_export_activity',
            ),
        );

        echo '<div class="wrap uapm-wrap">';
        echo '<h1>Експорт даних</h1>';
        echo '<p>Файли створюються у форматі CSV та сумісні з Excel.</p>';

        foreach ($exports as $export) {
            echo '<div class="uapm-card">';
            echo '<h2>' . esc_html($export['title']) . '</h2>';
            echo '<p>' . esc_html($export['description']) . '</p>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="' . esc_attr($export['action']) . '">';
            wp_nonce_field($export['nonce']);
            submit_button('Завантажити CSV', 'secondary', 'submit', false);
            echo '</form>';
            echo '</div>';
        }

        echo '</div>';
    }
}