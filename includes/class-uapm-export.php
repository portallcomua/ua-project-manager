
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
            $safe = array();
            foreach ($row as $value) {
                if (is_array($value) || is_object($value)) {
                    $value = wp_json_encode($value, JSON_UNESCAPED_UNICODE);
                }
                $value = (string) $value;
                if (preg_match('/^[\s]*[=+\-@]/u', $value)) {
                    $value = "'" . $value;
                }
                $safe[] = $value;
            }
            fputcsv($output, $safe, ';');
        }

        fclose($output);
        exit;
    }

    public static function export_projects() {
        self::authorize('uapm_export_projects');
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT id,name,slug,description,project_type,status,version,github_repository,created_at,updated_at
             FROM {$wpdb->prefix}uapm_projects ORDER BY id DESC",
            ARRAY_A
        );

        self::send_csv('uapm-projects-' . gmdate('Y-m-d') . '.csv',
            array('ID','Назва','Slug','Опис','Тип','Статус','Версія','GitHub','Створено','Оновлено'),
            $rows ?: array());
    }

    public static function export_payments() {
        self::authorize('uapm_export_payments');
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT id,project_id,customer_email,plan_id,amount,received_amount,currency,payment_status,activation_status,activation_target,activation_type,manager_id,commission_rate,commission_amount,created_at,paid_at
             FROM {$wpdb->prefix}uapm_payments ORDER BY id DESC",
            ARRAY_A
        );

        self::send_csv('uapm-payments-' . gmdate('Y-m-d') . '.csv',
            array('ID','Проєкт','Email','Тариф ID','Сума','Отримано','Валюта','Статус платежу','PRO','Ціль активації','Тип цілі','Менеджер ID','Комісія %','Комісія','Створено','Оплачено'),
            $rows ?: array());
    }

    public static function export_customers() {
        self::authorize('uapm_export_customers');
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT id,name,email,phone,company,country,customer_type,status,mailing_consent,notes,created_at,updated_at
             FROM {$wpdb->prefix}uapm_customers ORDER BY id DESC",
            ARRAY_A
        );

        self::send_csv('uapm-customers-' . gmdate('Y-m-d') . '.csv',
            array('ID','Ім’я','Email','Телефон','Компанія','Країна','Тип','Статус','Згода на розсилку','Примітки','Створено','Оновлено'),
            $rows ?: array());
    }

    public static function export_installations() {
        self::authorize('uapm_export_installations');
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT id,project_id,customer_id,site_url,site_name,plugin_slug,plugin_version,wordpress_version,php_version,platform,status,last_seen,created_at
             FROM {$wpdb->prefix}uapm_installations ORDER BY id DESC",
            ARRAY_A
        );

        self::send_csv('uapm-installations-' . gmdate('Y-m-d') . '.csv',
            array('ID','Проєкт','Клієнт ID','URL сайту','Назва сайту','Slug плагіна','Версія плагіна','WordPress','PHP','Платформа','Статус','Остання активність','Створено'),
            $rows ?: array());
    }

    public static function export_activity() {
        self::authorize('uapm_export_activity');
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT id,event_type,description,user_id,project_id,created_at
             FROM {$wpdb->prefix}uapm_activity_log ORDER BY id DESC LIMIT 10000",
            ARRAY_A
        );

        self::send_csv('uapm-activity-' . gmdate('Y-m-d') . '.csv',
            array('ID','Подія','Опис','Користувач','Проєкт','Дата'),
            $rows ?: array());
    }

    public static function render_page() {
        if (!current_user_can('uapm_manage_settings')) {
            wp_die('Недостатньо прав.');
        }

        $exports = array(
            array('title' => 'Проєкти', 'description' => 'Експорт списку проєктів.', 'action' => 'uapm_export_projects'),
            array('title' => 'Платежі', 'description' => 'Експорт сум, надходжень і статусів PRO.', 'action' => 'uapm_export_payments'),
            array('title' => 'Клієнти', 'description' => 'Експорт контактів і згод на розсилку.', 'action' => 'uapm_export_customers'),
            array('title' => 'Інсталяції', 'description' => 'Експорт підключених сайтів.', 'action' => 'uapm_export_installations'),
            array('title' => 'Журнал активності', 'description' => 'Експорт останніх 10 000 подій.', 'action' => 'uapm_export_activity'),
        );

        echo '<div class="wrap uapm-wrap"><h1>Експорт даних</h1><p>CSV-файли сумісні з Excel.</p>';

        foreach ($exports as $export) {
            echo '<div class="uapm-card"><h2>' . esc_html($export['title']) . '</h2>';
            echo '<p>' . esc_html($export['description']) . '</p>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="' . esc_attr($export['action']) . '">';
            wp_nonce_field($export['action']);
            submit_button('Завантажити CSV', 'secondary', 'submit', false);
            echo '</form></div>';
        }

        echo '</div>';
    }
}
