<?php
if (!defined('ABSPATH')) {
    exit;
}

class UAPM_Activity_Feed {

    const TABLE = 'uapm_activity_feed';

    public static function init() {
        if (defined('UAPM_DB_VERSION') && get_option('uapm_modules_schema_ver') !== UAPM_DB_VERSION) {
            self::install_schema();
        }
        add_action('admin_menu', array(__CLASS__, 'register_menu'), 85);

        add_action('uapm_project_created', array(__CLASS__, 'log_project_created'), 10, 2);
        add_action('uapm_project_updated', array(__CLASS__, 'log_project_updated'), 10, 2);
        add_action('uapm_payment_received', array(__CLASS__, 'log_payment_received'), 10, 2);
        add_action('uapm_pro_access_changed', array(__CLASS__, 'log_pro_changed'), 10, 4);
        add_action('uapm_installation_registered', array(__CLASS__, 'log_installation'), 10, 2);
        add_action('uapm_license_created', array(__CLASS__, 'log_license_created'), 10, 2);
        add_action('uapm_license_revoked', array(__CLASS__, 'log_license_revoked'), 10, 2);
        add_action('uapm_email_sent', array(__CLASS__, 'log_email_sent'), 10, 3);
    }

    public static function install_schema() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_type varchar(100) NOT NULL,
            title varchar(255) NOT NULL,
            description text NULL,
            object_type varchar(50) NULL,
            object_id bigint(20) unsigned NOT NULL DEFAULT 0,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY event_type (event_type),
            KEY object_type (object_type),
            KEY object_id (object_id),
            KEY created_at (created_at)
        ) {$charset};";

        dbDelta($sql);
    }

    public static function add($event_type, $title, $description = '', $object_type = '', $object_id = 0) {
        global $wpdb;

        $event_type = sanitize_key($event_type);
        $title = sanitize_text_field($title);

        if (!$event_type || !$title) {
            return false;
        }

        $table = $wpdb->prefix . self::TABLE;

        $result = $wpdb->insert(
            $table,
            array(
                'event_type' => $event_type,
                'title' => $title,
                'description' => sanitize_textarea_field($description),
                'object_type' => sanitize_key($object_type),
                'object_id' => absint($object_id),
                'user_id' => get_current_user_id(),
                'created_at' => current_time('mysql'),
            ),
            array('%s', '%s', '%s', '%s', '%d', '%d', '%s')
        );

        return $result ? (int) $wpdb->insert_id : false;
    }

    public static function get_all($args = array()) {
        global $wpdb;

        $defaults = array(
            'event_type' => '',
            'limit' => 50,
            'offset' => 0,
        );

        $args = wp_parse_args($args, $defaults);
        $table = $wpdb->prefix . self::TABLE;

        $where = '1=1';
        $params = array();

        if (!empty($args['event_type'])) {
            $where .= ' AND event_type = %s';
            $params[] = sanitize_key($args['event_type']);
        }

        $limit = max(1, min(200, absint($args['limit'])));
        $offset = absint($args['offset']);

        $sql = "SELECT * FROM {$table} WHERE {$where}
                ORDER BY id DESC LIMIT %d OFFSET %d";

        $params[] = $limit;
        $params[] = $offset;

        return $wpdb->get_results(
            $wpdb->prepare($sql, $params)
        );
    }

    public static function count($event_type = '') {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE;

        if ($event_type !== '') {
            return (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE event_type = %s",
                    sanitize_key($event_type)
                )
            );
        }

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    }

    public static function log_project_created($project_id, $project = null) {
        $name = is_object($project) && isset($project->name)
            ? $project->name
            : 'Проєкт #' . absint($project_id);

        self::add(
            'project_created',
            'Створено проєкт',
            $name,
            'project',
            $project_id
        );
    }

    public static function log_project_updated($project_id, $project = null) {
        self::add(
            'project_updated',
            'Оновлено проєкт',
            'Проєкт #' . absint($project_id),
            'project',
            $project_id
        );
    }

    public static function log_payment_received($payment_id, $payment = null) {
        self::add(
            'payment_received',
            'Отримано платіж',
            'Платіж #' . absint($payment_id),
            'payment',
            $payment_id
        );
    }

    public static function log_pro_changed($payment_id, $status, $target = '', $project_id = 0) {
        self::add(
            'pro_access_changed',
            'Змінено PRO-доступ',
            'Статус: ' . sanitize_text_field($status) . '; ціль: ' . sanitize_text_field($target),
            'payment',
            $payment_id
        );
    }

    public static function log_installation($installation_id, $installation = null) {
        self::add(
            'installation_registered',
            'Зареєстровано інсталяцію',
            'Інсталяція #' . absint($installation_id),
            'installation',
            $installation_id
        );
    }

    public static function log_license_created($license_id, $license = null) {
        self::add(
            'license_created',
            'Створено ліцензію',
            'Ліцензія #' . absint($license_id),
            'license',
            $license_id
        );
    }

    public static function log_license_revoked($license_id, $license = null) {
        self::add(
            'license_revoked',
            'Відкликано ліцензію',
            'Ліцензія #' . absint($license_id),
            'license',
            $license_id
        );
    }

    public static function log_email_sent($template_key, $recipient, $data = array()) {
        self::add(
            'email_sent',
            'Надіслано електронний лист',
            sanitize_text_field($template_key) . ': ' . sanitize_email($recipient),
            'email',
            0
        );
    }

    public static function register_menu() {
        add_submenu_page(
            'uapm',
            'Стрічка активності',
            'Стрічка активності',
            'uapm_manage_projects',
            'uapm-activity-feed',
            array(__CLASS__, 'render_page')
        );
    }

    public static function render_page() {
        if (!current_user_can('uapm_manage_projects')) {
            wp_die('Недостатньо прав.');
        }

        $event_type = isset($_GET['event_type'])
            ? sanitize_key(wp_unslash($_GET['event_type']))
            : '';

        $page = isset($_GET['paged'])
            ? max(1, absint($_GET['paged']))
            : 1;

        $per_page = 30;
        $offset = ($page - 1) * $per_page;

        $items = self::get_all(array(
            'event_type' => $event_type,
            'limit' => $per_page,
            'offset' => $offset,
        ));

        $total = self::count($event_type);
        $pages = (int) ceil($total / $per_page);

        echo '<div class="wrap">';
        echo '<h1>Стрічка активності</h1>';

        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="uapm-activity-feed">';
        echo '<select name="event_type">';
        echo '<option value="">Усі події</option>';

        $types = array(
            'project_created' => 'Створення проєкту',
            'project_updated' => 'Оновлення проєкту',
            'payment_received' => 'Отримання платежу',
            'pro_access_changed' => 'Зміна PRO-доступу',
            'installation_registered' => 'Нова інсталяція',
            'license_created' => 'Створення ліцензії',
            'license_revoked' => 'Відкликання ліцензії',
            'email_sent' => 'Надсилання листа',
        );

        foreach ($types as $key => $label) {
            echo '<option value="' . esc_attr($key) . '" ' . selected($event_type, $key, false) . '>';
            echo esc_html($label);
            echo '</option>';
        }

        echo '</select> ';
        submit_button('Фільтрувати', 'secondary', '', false);
        echo '</form>';

        echo '<table class="widefat striped" style="margin-top:15px">';
        echo '<thead><tr>';
        echo '<th>Дата</th><th>Подія</th><th>Опис</th><th>Об’єкт</th><th>ID</th>';
        echo '</tr></thead><tbody>';

        if ($items) {
            foreach ($items as $item) {
                echo '<tr>';
                echo '<td>' . esc_html($item->created_at) . '</td>';
                echo '<td>' . esc_html($item->title) . '</td>';
                echo '<td>' . esc_html($item->description) . '</td>';
                echo '<td>' . esc_html($item->object_type) . '</td>';
                echo '<td>' . esc_html($item->object_id) . '</td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="5">Подій поки немає.</td></tr>';
        }

        echo '</tbody></table>';

        if ($pages > 1) {
            echo '<div class="tablenav"><div class="tablenav-pages">';
            echo wp_kses_post(
                paginate_links(array(
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'current' => $page,
                    'total' => $pages,
                ))
            );
            echo '</div></div>';
        }

        echo '</div>';
    }
}