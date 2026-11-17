<?php
if (!defined('ABSPATH')) {
    exit;
}

class UAPM_Buttons {

    public static function init() {
        add_action('admin_post_uapm_save_button', array(__CLASS__, 'save_button'));
        add_action('admin_post_uapm_delete_button', array(__CLASS__, 'delete_button'));
        add_shortcode('uapm_button', array(__CLASS__, 'shortcode'));
    }

    private static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'uapm_buttons';
    }

    public static function install_schema() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(255) NOT NULL,
            button_text VARCHAR(255) NOT NULL,
            button_url TEXT NOT NULL,
            button_type VARCHAR(50) DEFAULT 'download',
            style VARCHAR(50) DEFAULT 'primary',
            target VARCHAR(20) DEFAULT '_self',
            status VARCHAR(30) DEFAULT 'active',
            clicks BIGINT UNSIGNED DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY project_id (project_id),
            KEY status (status)
        ) $charset;";

        dbDelta($sql);
    }

    public static function create($data) {
        global $wpdb;

        $project_id = absint($data['project_id'] ?? 0);
        $name = sanitize_text_field($data['name'] ?? '');
        $button_text = sanitize_text_field($data['button_text'] ?? '');
        $button_url = esc_url_raw($data['button_url'] ?? '');

        if (!$project_id || !$name || !$button_text || !$button_url) {
            return new WP_Error('uapm_invalid_button', 'Заповніть усі обов’язкові поля.');
        }

        if (!class_exists('UAPM_Projects') || !UAPM_Projects::get($project_id)) {
            return new WP_Error('uapm_project_missing', 'Проєкт не знайдено.');
        }

        $now = current_time('mysql');

        $result = $wpdb->insert(
            self::table_name(),
            array(
                'project_id' => $project_id,
                'name' => $name,
                'button_text' => $button_text,
                'button_url' => $button_url,
                'button_type' => sanitize_key($data['button_type'] ?? 'download'),
                'style' => sanitize_key($data['style'] ?? 'primary'),
                'target' => in_array(($data['target'] ?? '_self'), array('_self', '_blank'), true)
                    ? $data['target']
                    : '_self',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            )
        );

        return $result ? (int) $wpdb->insert_id : new WP_Error('uapm_button_error', 'Не вдалося створити кнопку.');
    }

    public static function get($id) {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table_name() . " WHERE id = %d",
            absint($id)
        ));
    }

    public static function get_all($args = array()) {
        global $wpdb;

        $defaults = array(
            'project_id' => 0,
            'status' => '',
            'limit' => 100,
            'offset' => 0,
        );

        $args = wp_parse_args($args, $defaults);

        $where = array('1=1');
        $values = array();

        if ($args['project_id']) {
            $where[] = 'project_id = %d';
            $values[] = absint($args['project_id']);
        }

        if ($args['status'] !== '') {
            $where[] = 'status = %s';
            $values[] = sanitize_key($args['status']);
        }

        $limit = min(500, max(1, absint($args['limit'])));
        $offset = absint($args['offset']);

        $sql = "SELECT * FROM " . self::table_name()
            . " WHERE " . implode(' AND ', $where)
            . " ORDER BY id DESC LIMIT %d OFFSET %d";

        $values[] = $limit;
        $values[] = $offset;

        return $wpdb->get_results($wpdb->prepare($sql, $values));
    }

    public static function save_button() {
        if (!current_user_can('uapm_manage_projects')) {
            wp_die('Недостатньо прав.');
        }

        check_admin_referer('uapm_save_button');

        $result = self::create(array(
            'project_id' => absint($_POST['project_id'] ?? 0),
            'name' => wp_unslash($_POST['name'] ?? ''),
            'button_text' => wp_unslash($_POST['button_text'] ?? ''),
            'button_url' => wp_unslash($_POST['button_url'] ?? ''),
            'button_type' => wp_unslash($_POST['button_type'] ?? 'download'),
            'style' => wp_unslash($_POST['style'] ?? 'primary'),
            'target' => wp_unslash($_POST['target'] ?? '_self'),
        ));

        $url = admin_url('admin.php?page=uapm-buttons');

        if (is_wp_error($result)) {
            $url = add_query_arg('uapm_error', rawurlencode($result->get_error_message()), $url);
        } else {
            $url = add_query_arg('uapm_saved', '1', $url);
        }

        wp_safe_redirect($url);
        exit;
    }

    public static function delete_button() {
        if (!current_user_can('uapm_manage_projects')) {
            wp_die('Недостатньо прав.');
        }

        check_admin_referer('uapm_delete_button');

        global $wpdb;

        $id = absint($_POST['button_id'] ?? 0);

        if ($id) {
            $wpdb->delete(self::table_name(), array('id' => $id), array('%d'));
        }

        wp_safe_redirect(admin_url('admin.php?page=uapm-buttons'));
        exit;
    }

    public static function shortcode($atts) {
        $atts = shortcode_atts(array(
            'id' => 0,
            'class' => '',
        ), $atts, 'uapm_button');

        $button = self::get(absint($atts['id']));

        if (!$button || $button->status !== 'active') {
            return '';
        }

        $class = sanitize_html_class($atts['class']);
        $style = sanitize_html_class($button->style);
        $classes = trim('uapm-button uapm-button-' . $style . ' ' . $class);

        $url = add_query_arg(
            array(
                'uapm_button_click' => absint($button->id),
            ),
            home_url('/')
        );

        $target = $button->target === '_blank' ? '_blank' : '_self';
        $rel = $target === '_blank' ? 'noopener noreferrer' : '';

        return sprintf(
            '<a class="%1$s" href="%2$s" target="%3$s" rel="%4$s">%5$s</a>',
            esc_attr($classes),
            esc_url($url),
            esc_attr($target),
            esc_attr($rel),
            esc_html($button->button_text)
        );
    }

    public static function track_click() {
        if (empty($_GET['uapm_button_click'])) {
            return;
        }

        global $wpdb;

        $id = absint($_GET['uapm_button_click']);

        $button = self::get($id);

        if (!$button || $button->status !== 'active') {
            wp_die('Кнопку не знайдено.');
        }

        $wpdb->query($wpdb->prepare(
            "UPDATE " . self::table_name() . " SET clicks = clicks + 1 WHERE id = %d",
            $id
        ));

        wp_safe_redirect($button->button_url);
        exit;
    }

    public static function render_page() {
        if (!current_user_can('uapm_manage_projects')) {
            wp_die('Недостатньо прав.');
        }

        $buttons = self::get_all();

        echo '<div class="wrap uapm-admin">';
        echo '<h1>Кнопки проєктів</h1>';

        if (isset($_GET['uapm_saved'])) {
            echo '<div class="notice notice-success"><p>Кнопку створено.</p></div>';
        }

        if (isset($_GET['uapm_error'])) {
            echo '<div class="notice notice-error"><p>' . esc_html(wp_unslash($_GET['uapm_error'])) . '</p></div>';
        }

        echo '<h2>Створити кнопку</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="uapm_save_button">';
        wp_nonce_field('uapm_save_button');

        echo '<table class="form-table">';
        echo '<tr><th><label for="uapm-button-project">ID проєкту</label></th><td><input required type="number" min="1" id="uapm-button-project" name="project_id"></td></tr>';
        echo '<tr><th><label for="uapm-button-name">Назва кнопки</label></th><td><input required class="regular-text" id="uapm-button-name" name="name"></td></tr>';
        echo '<tr><th><label for="uapm-button-text">Текст кнопки</label></th><td><input required class="regular-text" id="uapm-button-text" name="button_text"></td></tr>';
        echo '<tr><th><label for="uapm-button-url">Посилання</label></th><td><input required type="url" class="regular-text" id="uapm-button-url" name="button_url"></td></tr>';
        echo '<tr><th><label for="uapm-button-type">Тип</label></th><td><select id="uapm-button-type" name="button_type"><option value="download">Завантаження</option><option value="purchase">Купити PRO</option><option value="documentation">Документація</option><option value="custom">Інше</option></select></td></tr>';
        echo '<tr><th><label for="uapm-button-style">Стиль</label></th><td><select id="uapm-button-style" name="style"><option value="primary">Основна</option><option value="secondary">Додаткова</option><option value="success">Успіх</option></select></td></tr>';
        echo '<tr><th><label for="uapm-button-target">Відкривати</label></th><td><select id="uapm-button-target" name="target"><option value="_self">У цьому вікні</option><option value="_blank">У новій вкладці</option></select></td></tr>';
        echo '</table>';

        submit_button('Створити кнопку');
        echo '</form>';

        echo '<h2>Створені кнопки</h2>';
        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Назва</th><th>Проєкт</th><th>Shortcode</th><th>Кліки</th><th>Дія</th></tr></thead><tbody>';

        if ($buttons) {
            foreach ($buttons as $button) {
                echo '<tr>';
                echo '<td>' . absint($button->id) . '</td>';
                echo '<td>' . esc_html($button->name) . '</td>';
                echo '<td>' . absint($button->project_id) . '</td>';
                echo '<td><code>[uapm_button id="' . absint($button->id) . '"]</code></td>';
                echo '<td>' . absint($button->clicks) . '</td>';
                echo '<td><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                echo '<input type="hidden" name="action" value="uapm_delete_button">';
                echo '<input type="hidden" name="button_id" value="' . absint($button->id) . '">';
                wp_nonce_field('uapm_delete_button');
                submit_button('Видалити', 'delete small', 'submit', false);
                echo '</form></td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="6">Кнопок поки немає.</td></tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }
}

add_action('init', array('UAPM_Buttons', 'track_click'));