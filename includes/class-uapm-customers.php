
<?php
if (!defined('ABSPATH')) {
    exit;
}

class UAPM_Customers {

    private static $schema_checked = false;

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'uapm_customers';
    }

    public static function init() {
        add_action('admin_post_uapm_save_customer', array(__CLASS__, 'handle_save'));
        add_action('admin_post_uapm_delete_customer', array(__CLASS__, 'handle_delete'));
    }

    public static function install_schema() {
        global $wpdb;

        if (self::$schema_checked) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            name varchar(190) NOT NULL DEFAULT '',
            email varchar(191) NOT NULL DEFAULT '',
            phone varchar(60) NOT NULL DEFAULT '',
            company varchar(190) NOT NULL DEFAULT '',
            country varchar(100) NOT NULL DEFAULT '',
            customer_type varchar(30) NOT NULL DEFAULT 'individual',
            status varchar(30) NOT NULL DEFAULT 'active',
            mailing_consent tinyint(1) NOT NULL DEFAULT 0,
            notes longtext NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY email (email),
            KEY status (status)
        ) {$charset};";

        dbDelta($sql);

        self::$schema_checked = true;
    }

    public static function create($data) {
        global $wpdb;

        self::install_schema();

        $data = self::sanitize_data($data);

        if (!$data['email'] || !is_email($data['email'])) {
            return new WP_Error('invalid_email', 'Вкажіть коректну електронну адресу.');
        }

        $existing = self::get_by_email($data['email']);

        if ($existing) {
            return self::update($existing->id, $data);
        }

        $now = current_time('mysql');

        $insert_data = array_merge($data, array(
            'created_at' => $now,
            'updated_at' => $now,
        ));

        $inserted = $wpdb->insert(self::table(), $insert_data);

        if (!$inserted) {
            return new WP_Error(
                'customer_create_failed',
                'Не вдалося створити клієнта: ' . $wpdb->last_error
            );
        }

        $id = (int) $wpdb->insert_id;

        do_action('uapm_customer_created', $id, $data);

        return $id;
    }

    public static function update($customer_id, $data) {
        global $wpdb;

        self::install_schema();

        $customer_id = absint($customer_id);

        if (!$customer_id || !self::get($customer_id)) {
            return new WP_Error('customer_not_found', 'Клієнта не знайдено.');
        }

        $data = self::sanitize_data($data);

        if (!$data['email'] || !is_email($data['email'])) {
            return new WP_Error('invalid_email', 'Вкажіть коректну електронну адресу.');
        }

        $duplicate = self::get_by_email($data['email']);

        if ($duplicate && (int) $duplicate->id !== $customer_id) {
            return new WP_Error(
                'duplicate_email',
                'Клієнт з такою електронною адресою вже існує.'
            );
        }

        $data['updated_at'] = current_time('mysql');

        $updated = $wpdb->update(
            self::table(),
            $data,
            array('id' => $customer_id)
        );

        if ($updated === false) {
            return new WP_Error(
                'customer_update_failed',
                'Не вдалося оновити клієнта: ' . $wpdb->last_error
            );
        }

        do_action('uapm_customer_updated', $customer_id, $data);

        return true;
    }

    private static function sanitize_data($data) {
        $customer_type = isset($data['customer_type'])
            ? sanitize_key($data['customer_type'])
            : 'individual';

        $status = isset($data['status'])
            ? sanitize_key($data['status'])
            : 'active';

        return array(
            'user_id' => absint($data['user_id'] ?? 0),
            'name' => sanitize_text_field($data['name'] ?? ''),
            'email' => sanitize_email($data['email'] ?? ''),
            'phone' => sanitize_text_field($data['phone'] ?? ''),
            'company' => sanitize_text_field($data['company'] ?? ''),
            'country' => sanitize_text_field($data['country'] ?? ''),
            'customer_type' => in_array(
                $customer_type,
                array('individual', 'business'),
                true
            ) ? $customer_type : 'individual',
            'status' => in_array(
                $status,
                array('active', 'inactive', 'blocked'),
                true
            ) ? $status : 'active',
            'mailing_consent' => empty($data['mailing_consent']) ? 0 : 1,
            'notes' => sanitize_textarea_field($data['notes'] ?? ''),
        );
    }

    public static function get($customer_id) {
        global $wpdb;

        self::install_schema();

        return $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . self::table() . ' WHERE id = %d',
                absint($customer_id)
            )
        );
    }

    public static function get_by_email($email) {
        global $wpdb;

        self::install_schema();

        return $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . self::table() . ' WHERE email = %s LIMIT 1',
                sanitize_email($email)
            )
        );
    }

    public static function get_by_user_id($user_id) {
        global $wpdb;

        self::install_schema();

        return $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . self::table() . ' WHERE user_id = %d LIMIT 1',
                absint($user_id)
            )
        );
    }

    public static function get_all($args = array()) {
        global $wpdb;

        self::install_schema();

        $defaults = array(
            'search' => '',
            'status' => '',
            'mailing_consent' => null,
            'limit' => 50,
            'offset' => 0,
            'orderby' => 'created_at',
            'order' => 'DESC',
        );

        $args = wp_parse_args($args, $defaults);

        $allowed_orderby = array('id', 'name', 'email', 'created_at', 'updated_at');
        $orderby = in_array($args['orderby'], $allowed_orderby, true)
            ? $args['orderby']
            : 'created_at';

        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';

        $where = array('1=1');
        $values = array();

        if ($args['search'] !== '') {
            $like = '%' . $wpdb->esc_like($args['search']) . '%';
            $where[] = '(name LIKE %s OR email LIKE %s OR company LIKE %s)';
            array_push($values, $like, $like, $like);
        }

        if ($args['status'] !== '') {
            $where[] = 'status = %s';
            $values[] = sanitize_key($args['status']);
        }

        if ($args['mailing_consent'] !== null) {
            $where[] = 'mailing_consent = %d';
            $values[] = absint($args['mailing_consent']);
        }

        $limit = max(1, min(500, absint($args['limit'])));
        $offset = absint($args['offset']);

        $sql = 'SELECT * FROM ' . self::table() .
            ' WHERE ' . implode(' AND ', $where) .
            " ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";

        $values[] = $limit;
        $values[] = $offset;

        return $wpdb->get_results($wpdb->prepare($sql, $values));
    }

    public static function count($args = array()) {
        global $wpdb;

        self::install_schema();

        $where = array('1=1');
        $values = array();

        if (!empty($args['search'])) {
            $like = '%' . $wpdb->esc_like($args['search']) . '%';
            $where[] = '(name LIKE %s OR email LIKE %s OR company LIKE %s)';
            array_push($values, $like, $like, $like);
        }

        if (!empty($args['status'])) {
            $where[] = 'status = %s';
            $values[] = sanitize_key($args['status']);
        }

        if (isset($args['mailing_consent'])) {
            $where[] = 'mailing_consent = %d';
            $values[] = absint($args['mailing_consent']);
        }

        $sql = 'SELECT COUNT(*) FROM ' . self::table() .
            ' WHERE ' . implode(' AND ', $where);

        if ($values) {
            $sql = $wpdb->prepare($sql, $values);
        }

        return (int) $wpdb->get_var($sql);
    }

    public static function delete($customer_id) {
        global $wpdb;

        self::install_schema();

        $customer_id = absint($customer_id);

        if (!$customer_id || !self::get($customer_id)) {
            return new WP_Error('customer_not_found', 'Клієнта не знайдено.');
        }

        $deleted = $wpdb->delete(
            self::table(),
            array('id' => $customer_id),
            array('%d')
        );

        if (!$deleted) {
            return new WP_Error(
                'customer_delete_failed',
                'Не вдалося видалити клієнта: ' . $wpdb->last_error
            );
        }

        do_action('uapm_customer_deleted', $customer_id);

        return true;
    }

    public static function handle_save() {
        if (!current_user_can('uapm_manage_projects')) {
            wp_die('Недостатньо прав.', '', array('response' => 403));
        }

        check_admin_referer('uapm_save_customer');

        $data = wp_unslash($_POST);
        $customer_id = absint($data['customer_id'] ?? 0);

        $result = $customer_id
            ? self::update($customer_id, $data)
            : self::create($data);

        $url = admin_url('admin.php?page=uapm-customers');

        if (is_wp_error($result)) {
            $url = add_query_arg(
                'uapm_error',
                rawurlencode($result->get_error_message()),
                $url
            );
        } else {
            $url = add_query_arg('uapm_success', 'saved', $url);
        }

        wp_safe_redirect($url);
        exit;
    }

    public static function handle_delete() {
        if (!current_user_can('uapm_manage_projects')) {
            wp_die('Недостатньо прав.', '', array('response' => 403));
        }

        check_admin_referer('uapm_delete_customer');

        $result = self::delete(absint($_POST['customer_id'] ?? 0));

        $url = admin_url('admin.php?page=uapm-customers');

        if (is_wp_error($result)) {
            $url = add_query_arg(
                'uapm_error',
                rawurlencode($result->get_error_message()),
                $url
            );
        } else {
            $url = add_query_arg('uapm_success', 'deleted', $url);
        }

        wp_safe_redirect($url);
        exit;
    }

    public static function render_page() {
        if (!current_user_can('uapm_manage_projects')) {
            wp_die('Недостатньо прав.', '', array('response' => 403));
        }

        $search = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));

        $customers = self::get_all(array(
            'search' => $search,
            'limit' => 100,
        ));

        echo '<div class="wrap uapm-wrap">';
        echo '<h1>Клієнти</h1>';

        if (!empty($_GET['uapm_error'])) {
            echo '<div class="notice notice-error"><p>' .
                esc_html(wp_unslash($_GET['uapm_error'])) .
                '</p></div>';
        }

        if (!empty($_GET['uapm_success'])) {
            echo '<div class="notice notice-success"><p>Операцію виконано.</p></div>';
        }

        echo '<div class="uapm-card">';
        echo '<h2>Додати клієнта</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="uapm_save_customer">';
        wp_nonce_field('uapm_save_customer');

        echo '<p><input type="text" name="name" placeholder="Ім’я або назва компанії" required class="regular-text"></p>';
        echo '<p><input type="email" name="email" placeholder="Email" required class="regular-text"></p>';
        echo '<p><input type="text" name="phone" placeholder="Телефон" class="regular-text"></p>';
        echo '<p><input type="text" name="company" placeholder="Компанія" class="regular-text"></p>';
        echo '<p><input type="text" name="country" placeholder="Країна" class="regular-text"></p>';
        echo '<p><label><input type="checkbox" name="mailing_consent" value="1"> Дозвіл на отримання розсилок</label></p>';

        submit_button('Додати клієнта');
        echo '</form>';
        echo '</div>';

        echo '<div class="uapm-card">';
        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="uapm-customers">';
        echo '<input type="search" name="s" value="' . esc_attr($search) . '" placeholder="Пошук клієнтів">';
        submit_button('Знайти', 'secondary', '', false);
        echo '</form>';

        echo '<table class="widefat striped">';
        echo '<thead><tr><th>Ім’я</th><th>Email</th><th>Телефон</th><th>Компанія</th><th>Розсилка</th><th>Статус</th><th>Дії</th></tr></thead><tbody>';

        if (!$customers) {
            echo '<tr><td colspan="7">Клієнтів не знайдено.</td></tr>';
        }

        foreach ($customers as $customer) {
            echo '<tr>';
            echo '<td>' . esc_html($customer->name) . '</td>';
            echo '<td><a href="mailto:' . esc_attr($customer->email) . '">' . esc_html($customer->email) . '</a></td>';
            echo '<td>' . esc_html($customer->phone) . '</td>';
            echo '<td>' . esc_html($customer->company) . '</td>';
            echo '<td>' . (!empty($customer->mailing_consent) ? 'Так' : 'Ні') . '</td>';
            echo '<td>' . esc_html($customer->status) . '</td>';
            echo '<td>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Видалити клієнта?\')">';
            echo '<input type="hidden" name="action" value="uapm_delete_customer">';
            echo '<input type="hidden" name="customer_id" value="' . esc_attr($customer->id) . '">';
            wp_nonce_field('uapm_delete_customer');
            echo '<button class="button button-link-delete">Видалити</button>';
            echo '</form>';
            echo '</td></tr>';
        }

        echo '</tbody></table>';
        echo '</div></div>';
    }
}
