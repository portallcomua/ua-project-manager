<?php
if (!defined('ABSPATH')) {
    exit;
}

class UAPM_Search {

    public static function init() {
        add_action('wp_ajax_uapm_global_search', array(__CLASS__, 'ajax_search'));
        add_action('admin_menu', array(__CLASS__, 'register_menu'), 90);
    }

    public static function register_menu() {
        add_submenu_page(
            'uapm',
            'Пошук',
            'Пошук',
            'uapm_manage_projects',
            'uapm-search',
            array(__CLASS__, 'render_page')
        );
    }

    public static function search($query, $limit = 30) {
        global $wpdb;

        $query = sanitize_text_field($query);
        $limit = max(1, min(100, absint($limit)));

        if (mb_strlen($query) < 2) {
            return array();
        }

        $like = '%' . $wpdb->esc_like($query) . '%';
        $results = array();

        $definitions = array(
            'projects' => array(
                'table' => 'uapm_projects',
                'type' => 'Проєкт',
                'fields' => array('name', 'slug', 'description'),
                'page' => 'uapm-projects',
                'id_field' => 'id',
            ),
            'customers' => array(
                'table' => 'uapm_customers',
                'type' => 'Клієнт',
                'fields' => array('name', 'email', 'company'),
                'page' => 'uapm-customers',
                'id_field' => 'id',
            ),
            'payments' => array(
                'table' => 'uapm_payments',
                'type' => 'Платіж',
                'fields' => array('customer_email', 'customer_name', 'target'),
                'page' => 'uapm-payments',
                'id_field' => 'id',
            ),
            'licenses' => array(
                'table' => 'uapm_licenses',
                'type' => 'Ліцензія',
                'fields' => array('license_key', 'customer_email', 'target'),
                'page' => 'uapm-licenses',
                'id_field' => 'id',
            ),
            'installations' => array(
                'table' => 'uapm_installations',
                'type' => 'Інсталяція',
                'fields' => array('site_url', 'site_name', 'domain'),
                'page' => 'uapm-installations',
                'id_field' => 'id',
            ),
        );

        foreach ($definitions as $definition) {
            $table = $wpdb->prefix . $definition['table'];

            $exists = $wpdb->get_var(
                $wpdb->prepare('SHOW TABLES LIKE %s', $table)
            );

            if ($exists !== $table) {
                continue;
            }

            $columns = $wpdb->get_col("SHOW COLUMNS FROM `{$table}`", 0);

            if (!is_array($columns) || !$columns) {
                continue;
            }

            $conditions = array();
            $params = array();

            foreach ($definition['fields'] as $field) {
                if (!in_array($field, $columns, true)) {
                    continue;
                }

                $conditions[] = "`{$field}` LIKE %s";
                $params[] = $like;
            }

            if (!$conditions) {
                continue;
            }

            $id_field = $definition['id_field'];

            if (!in_array($id_field, $columns, true)) {
                continue;
            }

            $sql = "SELECT * FROM `{$table}`
                    WHERE (" . implode(' OR ', $conditions) . ")
                    ORDER BY `{$id_field}` DESC
                    LIMIT %d";

            $params[] = $limit;

            $rows = $wpdb->get_results(
                $wpdb->prepare($sql, $params)
            );

            foreach ($rows as $row) {
                $label = '';

                foreach ($definition['fields'] as $field) {
                    if (!empty($row->$field)) {
                        $label = (string) $row->$field;
                        break;
                    }
                }

                $results[] = array(
                    'type' => $definition['type'],
                    'label' => $label ? $label : '#' . absint($row->$id_field),
                    'id' => absint($row->$id_field),
                    'url' => add_query_arg(
                        array(
                            'page' => $definition['page'],
                            'search' => $query,
                        ),
                        admin_url('admin.php')
                    ),
                );
            }
        }

        return array_slice($results, 0, $limit);
    }

    public static function ajax_search() {
        if (!current_user_can('uapm_manage_projects')) {
            wp_send_json_error(array('message' => 'Недостатньо прав.'), 403);
        }

        check_ajax_referer('uapm_global_search', 'nonce');

        $query = isset($_POST['query'])
            ? sanitize_text_field(wp_unslash($_POST['query']))
            : '';

        $results = self::search($query, 30);

        wp_send_json_success($results);
    }

    public static function render_page() {
        if (!current_user_can('uapm_manage_projects')) {
            wp_die('Недостатньо прав.');
        }

        $query = isset($_GET['q'])
            ? sanitize_text_field(wp_unslash($_GET['q']))
            : '';

        $results = $query ? self::search($query) : array();

        echo '<div class="wrap">';
        echo '<h1>Глобальний пошук UA Project Manager</h1>';

        echo '<form method="get" style="margin:20px 0">';
        echo '<input type="hidden" name="page" value="uapm-search">';
        echo '<input type="search" name="q" value="' . esc_attr($query) . '" class="regular-text" placeholder="Назва, email, домен, платіж...">';
        submit_button('Знайти', 'primary', '', false);
        echo '</form>';

        if ($query && !$results) {
            echo '<p>За вашим запитом нічого не знайдено.</p>';
        }

        if ($results) {
            echo '<table class="widefat striped">';
            echo '<thead><tr><th>Тип</th><th>Знайдений запис</th><th>ID</th><th>Дія</th></tr></thead><tbody>';

            foreach ($results as $result) {
                echo '<tr>';
                echo '<td>' . esc_html($result['type']) . '</td>';
                echo '<td>' . esc_html($result['label']) . '</td>';
                echo '<td>' . esc_html($result['id']) . '</td>';
                echo '<td><a href="' . esc_url($result['url']) . '">Відкрити</a></td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        echo '</div>';
    }
}