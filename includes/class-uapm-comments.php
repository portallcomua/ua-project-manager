<?php
if (!defined('ABSPATH')) {
    exit;
}

class UAPM_Comments {

    const TABLE = 'uapm_comments';

    public static function init() {
        self::install_schema();

        add_action('admin_menu', array(__CLASS__, 'register_menu'), 105);
        add_action('admin_post_uapm_add_comment', array(__CLASS__, 'handle_add'));
        add_action('admin_post_uapm_delete_comment', array(__CLASS__, 'handle_delete'));
    }

    public static function install_schema() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            project_id bigint(20) unsigned NOT NULL DEFAULT 0,
            task_id bigint(20) unsigned NOT NULL DEFAULT 0,
            parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            content longtext NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY project_id (project_id),
            KEY task_id (task_id),
            KEY parent_id (parent_id),
            KEY user_id (user_id),
            KEY created_at (created_at)
        ) {$charset};";

        dbDelta($sql);
    }

    public static function create($data) {
        global $wpdb;

        if (!is_array($data)) {
            return new WP_Error('uapm_comment_invalid', 'Некоректні дані коментаря.');
        }

        $content = isset($data['content'])
            ? sanitize_textarea_field($data['content'])
            : '';

        if ($content === '') {
            return new WP_Error('uapm_comment_empty', 'Напишіть текст коментаря.');
        }

        $project_id = isset($data['project_id'])
            ? absint($data['project_id'])
            : 0;

        $task_id = isset($data['task_id'])
            ? absint($data['task_id'])
            : 0;

        $parent_id = isset($data['parent_id'])
            ? absint($data['parent_id'])
            : 0;

        if ($project_id && class_exists('UAPM_Projects') &&
            !UAPM_Projects::get($project_id)) {
            return new WP_Error('uapm_comment_project', 'Проєкт не знайдено.');
        }

        if ($task_id && class_exists('UAPM_Tasks') &&
            !UAPM_Tasks::get($task_id)) {
            return new WP_Error('uapm_comment_task', 'Завдання не знайдено.');
        }

        if ($parent_id) {
            $parent = self::get($parent_id);

            if (!$parent) {
                return new WP_Error('uapm_comment_parent', 'Батьківський коментар не знайдено.');
            }
        }

        $now = current_time('mysql');

        $result = $wpdb->insert(
            $wpdb->prefix . self::TABLE,
            array(
                'project_id' => $project_id,
                'task_id' => $task_id,
                'parent_id' => $parent_id,
                'user_id' => get_current_user_id(),
                'content' => $content,
                'created_at' => $now,
                'updated_at' => $now,
            ),
            array('%d', '%d', '%d', '%d', '%s', '%s', '%s')
        );

        if (!$result) {
            return new WP_Error('uapm_comment_create', 'Не вдалося зберегти коментар.');
        }

        $comment_id = (int) $wpdb->insert_id;

        do_action('uapm_comment_created', $comment_id, $data);

        return $comment_id;
    }

    public static function get($comment_id) {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}" . self::TABLE . " WHERE id = %d",
                absint($comment_id)
            )
        );
    }

    public static function get_all($args = array()) {
        global $wpdb;

        $defaults = array(
            'project_id' => 0,
            'task_id' => 0,
            'parent_id' => null,
            'limit' => 100,
            'offset' => 0,
        );

        $args = wp_parse_args($args, $defaults);

        $where = array('1=1');
        $params = array();
        $table = $wpdb->prefix . self::TABLE;

        if (!empty($args['project_id'])) {
            $where[] = 'project_id = %d';
            $params[] = absint($args['project_id']);
        }

        if (!empty($args['task_id'])) {
            $where[] = 'task_id = %d';
            $params[] = absint($args['task_id']);
        }

        if ($args['parent_id'] !== null) {
            $where[] = 'parent_id = %d';
            $params[] = absint($args['parent_id']);
        }

        $limit = max(1, min(200, absint($args['limit'])));
        $offset = absint($args['offset']);

        $params[] = $limit;
        $params[] = $offset;

        $sql = "SELECT * FROM {$table}
                WHERE " . implode(' AND ', $where) . "
                ORDER BY created_at ASC, id ASC
                LIMIT %d OFFSET %d";

        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }

    public static function delete($comment_id) {
        global $wpdb;

        $comment = self::get($comment_id);

        if (!$comment) {
            return false;
        }

        if (!current_user_can('uapm_manage_projects') &&
            (int) $comment->user_id !== get_current_user_id()) {
            return new WP_Error('uapm_comment_forbidden', 'Немає прав для видалення цього коментаря.');
        }

        $table = $wpdb->prefix . self::TABLE;

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE parent_id = %d",
                absint($comment_id)
            )
        );

        $result = $wpdb->delete(
            $table,
            array('id' => absint($comment_id)),
            array('%d')
        );

        if ($result) {
            do_action('uapm_comment_deleted', absint($comment_id));
        }

        return (bool) $result;
    }

    public static function register_menu() {
        add_submenu_page(
            'uapm',
            'Коментарі',
            'Коментарі',
            'uapm_manage_projects',
            'uapm-comments',
            array(__CLASS__, 'render_page')
        );
    }

    public static function render_page() {
        if (!current_user_can('uapm_manage_projects')) {
            wp_die('Недостатньо прав.');
        }

        $project_id = isset($_GET['project_id'])
            ? absint($_GET['project_id'])
            : 0;

        $task_id = isset($_GET['task_id'])
            ? absint($_GET['task_id'])
            : 0;

        $comments = self::get_all(array(
            'project_id' => $project_id,
            'task_id' => $task_id,
            'parent_id' => null,
            'limit' => 100,
        ));

        echo '<div class="wrap">';
        echo '<h1>Коментарі та обговорення</h1>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="uapm_add_comment">';
        echo '<input type="hidden" name="project_id" value="' . esc_attr($project_id) . '">';
        echo '<input type="hidden" name="task_id" value="' . esc_attr($task_id) . '">';
        wp_nonce_field('uapm_add_comment');

        echo '<p><textarea name="content" class="large-text" rows="4" placeholder="Напишіть коментар..." required></textarea></p>';
        submit_button('Опублікувати коментар');
        echo '</form>';

        echo '<hr><h2>Обговорення</h2>';

        if (!$comments) {
            echo '<p>Коментарів поки немає.</p>';
        }

        foreach ($comments as $comment) {
            $user = get_userdata($comment->user_id);
            $author = $user ? $user->display_name : 'Користувач';

            echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:12px;margin:12px 0">';
            echo '<strong>' . esc_html($author) . '</strong>';
            echo '<small style="color:#646970;margin-left:8px">' . esc_html($comment->created_at) . '</small>';
            echo '<p>' . nl2br(esc_html($comment->content)) . '</p>';

            $replies = self::get_all(array(
                'parent_id' => $comment->id,
                'limit' => 50,
            ));

            foreach ($replies as $reply) {
                $reply_user = get_userdata($reply->user_id);
                $reply_author = $reply_user ? $reply_user->display_name : 'Користувач';

                echo '<div style="margin:10px 0 0 20px;padding:10px;border-left:3px solid #2271b1;background:#f6f7f7">';
                echo '<strong>' . esc_html($reply_author) . '</strong>';
                echo '<small style="margin-left:8px;color:#646970">' . esc_html($reply->created_at) . '</small>';
                echo '<p>' . nl2br(esc_html($reply->content)) . '</p>';
                echo '</div>';
            }

            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="uapm_add_comment">';
            echo '<input type="hidden" name="project_id" value="' . esc_attr($project_id) . '">';
            echo '<input type="hidden" name="task_id" value="' . esc_attr($task_id) . '">';
            echo '<input type="hidden" name="parent_id" value="' . esc_attr($comment->id) . '">';
            wp_nonce_field('uapm_add_comment');
            echo '<p><textarea name="content" class="large-text" rows="2" placeholder="Відповісти..." required></textarea></p>';
            submit_button('Відповісти', 'secondary small');
            echo '</form>';

            echo '</div>';
        }

        echo '</div>';
    }

    public static function handle_add() {
        if (!is_user_logged_in()) {
            wp_die('Потрібна авторизація.', '', array('response' => 401));
        }

        check_admin_referer('uapm_add_comment');

        if (!current_user_can('uapm_manage_projects') &&
            !current_user_can('uapm_view_own_projects')) {
            wp_die('Недостатньо прав.', '', array('response' => 403));
        }

        $data = array(
            'content' => isset($_POST['content'])
                ? sanitize_textarea_field(wp_unslash($_POST['content']))
                : '',
            'project_id' => isset($_POST['project_id'])
                ? absint($_POST['project_id'])
                : 0,
            'task_id' => isset($_POST['task_id'])
                ? absint($_POST['task_id'])
                : 0,
            'parent_id' => isset($_POST['parent_id'])
                ? absint($_POST['parent_id'])
                : 0,
        );

        $result = self::create($data);

        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()));
        }

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page' => 'uapm-comments',
                    'project_id' => $data['project_id'],
                    'task_id' => $data['task_id'],
                    'saved' => 1,
                ),
                admin_url('admin.php')
            )
        );

        exit;
    }

    public static function handle_delete() {
        $comment_id = isset($_POST['comment_id'])
            ? absint($_POST['comment_id'])
            : 0;

        check_admin_referer('uapm_delete_comment_' . $comment_id);

        $result = self::delete($comment_id);

        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()));
        }

        wp_safe_redirect(admin_url('admin.php?page=uapm-comments'));
        exit;
    }
}