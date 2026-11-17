<?php
if (!defined('ABSPATH')) {
    exit;
}

class UAPM_Notes {

    const TABLE = 'uapm_notes';

    public static function init() {
        self::install_schema();

        add_action('admin_menu', array(__CLASS__, 'register_menu'), 95);
        add_action('admin_post_uapm_save_note', array(__CLASS__, 'handle_save'));
        add_action('admin_post_uapm_delete_note', array(__CLASS__, 'handle_delete'));
    }

    public static function install_schema() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            title varchar(255) NOT NULL,
            content longtext NOT NULL,
            project_id bigint(20) unsigned NOT NULL DEFAULT 0,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            is_pinned tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY project_id (project_id),
            KEY user_id (user_id),
            KEY is_pinned (is_pinned),
            KEY updated_at (updated_at)
        ) {$charset};";

        dbDelta($sql);
    }

    public static function create($data) {
        global $wpdb;

        if (!is_array($data)) {
            return new WP_Error('uapm_invalid_note', 'Некоректні дані нотатки.');
        }

        $title = isset($data['title'])
            ? sanitize_text_field($data['title'])
            : '';

        $content = isset($data['content'])
            ? wp_kses_post($data['content'])
            : '';

        if ($title === '' || $content === '') {
            return new WP_Error('uapm_empty_note', 'Заповніть назву та текст нотатки.');
        }

        $project_id = isset($data['project_id'])
            ? absint($data['project_id'])
            : 0;

        if ($project_id && class_exists('UAPM_Projects')) {
            if (!UAPM_Projects::get($project_id)) {
                return new WP_Error('uapm_project_missing', 'Проєкт не знайдено.');
            }
        }

        $now = current_time('mysql');

        $result = $wpdb->insert(
            $wpdb->prefix . self::TABLE,
            array(
                'title' => $title,
                'content' => $content,
                'project_id' => $project_id,
                'user_id' => get_current_user_id(),
                'is_pinned' => !empty($data['is_pinned']) ? 1 : 0,
                'created_at' => $now,
                'updated_at' => $now,
            ),
            array('%s', '%s', '%d', '%d', '%d', '%s', '%s')
        );

        if (!$result) {
            return new WP_Error('uapm_note_create_failed', 'Не вдалося створити нотатку.');
        }

        do_action('uapm_note_created', (int) $wpdb->insert_id);

        return (int) $wpdb->insert_id;
    }

    public static function update($note_id, $data) {
        global $wpdb;

        $note_id = absint($note_id);
        $note = self::get($note_id);

        if (!$note) {
            return new WP_Error('uapm_note_missing', 'Нотатку не знайдено.');
        }

        if (!current_user_can('uapm_manage_projects') &&
            (int) $note->user_id !== get_current_user_id()) {
            return new WP_Error('uapm_note_forbidden', 'Немає доступу до цієї нотатки.');
        }

        $update = array();

        if (isset($data['title'])) {
            $title = sanitize_text_field($data['title']);

            if ($title === '') {
                return new WP_Error('uapm_note_title_empty', 'Назва нотатки не може бути порожньою.');
            }

            $update['title'] = $title;
        }

        if (isset($data['content'])) {
            $content = wp_kses_post($data['content']);

            if ($content === '') {
                return new WP_Error('uapm_note_content_empty', 'Текст нотатки не може бути порожнім.');
            }

            $update['content'] = $content;
        }

        if (isset($data['project_id'])) {
            $project_id = absint($data['project_id']);

            if ($project_id && class_exists('UAPM_Projects') &&
                !UAPM_Projects::get($project_id)) {
                return new WP_Error('uapm_project_missing', 'Проєкт не знайдено.');
            }

            $update['project_id'] = $project_id;
        }

        if (isset($data['is_pinned'])) {
            $update['is_pinned'] = !empty($data['is_pinned']) ? 1 : 0;
        }

        if (!$update) {
            return true;
        }

        $update['updated_at'] = current_time('mysql');

        $result = $wpdb->update(
            $wpdb->prefix . self::TABLE,
            $update,
            array('id' => $note_id)
        );

        if ($result === false) {
            return new WP_Error('uapm_note_update_failed', 'Не вдалося оновити нотатку.');
        }

        do_action('uapm_note_updated', $note_id);

        return true;
    }

    public static function delete($note_id) {
        global $wpdb;

        $note = self::get($note_id);

        if (!$note) {
            return false;
        }

        if (!current_user_can('uapm_manage_projects') &&
            (int) $note->user_id !== get_current_user_id()) {
            return new WP_Error('uapm_note_forbidden', 'Немає доступу до цієї нотатки.');
        }

        $result = $wpdb->delete(
            $wpdb->prefix . self::TABLE,
            array('id' => absint($note_id)),
            array('%d')
        );

        if ($result) {
            do_action('uapm_note_deleted', absint($note_id));
        }

        return (bool) $result;
    }

    public static function get($note_id) {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}" . self::TABLE . " WHERE id = %d",
                absint($note_id)
            )
        );
    }

    public static function get_all($args = array()) {
        global $wpdb;

        $defaults = array(
            'project_id' => 0,
            'user_id' => 0,
            'search' => '',
            'limit' => 50,
            'offset' => 0,
        );

        $args = wp_parse_args($args, $defaults);

        $where = array('1=1');
        $params = array();
        $table = $wpdb->prefix . self::TABLE;

        if (!current_user_can('uapm_manage_projects')) {
            $where[] = 'user_id = %d';
            $params[] = get_current_user_id();
        } elseif (!empty($args['user_id'])) {
            $where[] = 'user_id = %d';
            $params[] = absint($args['user_id']);
        }

        if (!empty($args['project_id'])) {
            $where[] = 'project_id = %d';
            $params[] = absint($args['project_id']);
        }

        if (!empty($args['search'])) {
            $where[] = '(title LIKE %s OR content LIKE %s)';
            $like = '%' . $wpdb->esc_like(sanitize_text_field($args['search'])) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $limit = max(1, min(200, absint($args['limit'])));
        $offset = absint($args['offset']);

        $params[] = $limit;
        $params[] = $offset;

        $sql = "SELECT * FROM {$table}
                WHERE " . implode(' AND ', $where) . "
                ORDER BY is_pinned DESC, updated_at DESC
                LIMIT %d OFFSET %d";

        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }

    public static function register_menu() {
        add_submenu_page(
            'uapm',
            'Нотатки',
            'Нотатки',
            'uapm_manage_projects',
            'uapm-notes',
            array(__CLASS__, 'render_page')
        );
    }

    public static function render_page() {
        if (!current_user_can('uapm_manage_projects')) {
            wp_die('Недостатньо прав.');
        }

        $notes = self::get_all(array('limit' => 100));

        echo '<div class="wrap">';
        echo '<h1>Нотатки проєктів</h1>';

        if (isset($_GET['saved'])) {
            echo '<div class="notice notice-success"><p>Нотатку збережено.</p></div>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="uapm_save_note">';
        wp_nonce_field('uapm_save_note');

        echo '<h2>Нова нотатка</h2>';
        echo '<p><input type="text" name="title" class="large-text" placeholder="Назва нотатки" required></p>';
        echo '<p><textarea name="content" class="large-text" rows="5" placeholder="Текст нотатки" required></textarea></p>';

        if (class_exists('UAPM_Projects')) {
            $projects = UAPM_Projects::get_all(array('limit' => 200));

            echo '<p><select name="project_id">';
            echo '<option value="0">Без прив’язки до проєкту</option>';

            foreach ($projects as $project) {
                echo '<option value="' . esc_attr($project->id) . '">' . esc_html($project->name) . '</option>';
            }

            echo '</select></p>';
        }

        echo '<p><label><input type="checkbox" name="is_pinned" value="1"> Закріпити нотатку</label></p>';

        submit_button('Додати нотатку');
        echo '</form>';

        echo '<hr><h2>Збережені нотатки</h2>';

        if (!$notes) {
            echo '<p>Нотаток поки немає.</p>';
        } else {
            echo '<table class="widefat striped">';
            echo '<thead><tr><th>Назва</th><th>Проєкт</th><th>Дата оновлення</th><th>Дія</th></tr></thead><tbody>';

            foreach ($notes as $note) {
                $project_name = '—';

                if ($note->project_id && class_exists('UAPM_Projects')) {
                    $project = UAPM_Projects::get($note->project_id);

                    if ($project) {
                        $project_name = $project->name;
                    }
                }

                echo '<tr>';
                echo '<td><strong>' . esc_html($note->title) . '</strong>';

                if ((int) $note->is_pinned === 1) {
                    echo ' <span aria-label="Закріплено">📌</span>';
                }

                echo '<br>' . wp_kses_post(wpautop($note->content)) . '</td>';
                echo '<td>' . esc_html($project_name) . '</td>';
                echo '<td>' . esc_html($note->updated_at) . '</td>';
                echo '<td>';

                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Видалити нотатку?\')">';
                echo '<input type="hidden" name="action" value="uapm_delete_note">';
                echo '<input type="hidden" name="note_id" value="' . esc_attr($note->id) . '">';
                wp_nonce_field('uapm_delete_note_' . $note->id);
                submit_button('Видалити', 'delete small', 'submit', false);
                echo '</form>';

                echo '</td></tr>';
            }

            echo '</tbody></table>';
        }

        echo '</div>';
    }

    public static function handle_save() {
        if (!current_user_can('uapm_manage_projects')) {
            wp_die('Недостатньо прав.', '', array('response' => 403));
        }

        check_admin_referer('uapm_save_note');

        $data = array(
            'title' => isset($_POST['title'])
                ? sanitize_text_field(wp_unslash($_POST['title']))
                : '',
            'content' => isset($_POST['content'])
                ? wp_kses_post(wp_unslash($_POST['content']))
                : '',
            'project_id' => isset($_POST['project_id'])
                ? absint($_POST['project_id'])
                : 0,
            'is_pinned' => !empty($_POST['is_pinned']),
        );

        $result = self::create($data);

        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()));
        }

        wp_safe_redirect(
            add_query_arg(
                array('page' => 'uapm-notes', 'saved' => 1),
                admin_url('admin.php')
            )
        );

        exit;
    }

    public static function handle_delete() {
        $note_id = isset($_POST['note_id']) ? absint($_POST['note_id']) : 0;

        if (!current_user_can('uapm_manage_projects')) {
            wp_die('Недостатньо прав.', '', array('response' => 403));
        }

        check_admin_referer('uapm_delete_note_' . $note_id);

        $result = self::delete($note_id);

        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()));
        }

        wp_safe_redirect(
            add_query_arg(
                array('page' => 'uapm-notes', 'deleted' => 1),
                admin_url('admin.php')
            )
        );

        exit;
    }
}