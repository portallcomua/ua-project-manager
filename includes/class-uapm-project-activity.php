<?php
if (!defined('ABSPATH')) {
    exit;
}

class UAPM_Project_Activity {

    const TABLE = 'uapm_project_activity';

    public static function init() {
        if (defined('UAPM_DB_VERSION') && get_option('uapm_modules_schema_ver') !== UAPM_DB_VERSION) {
            self::install_schema();
        }
        add_action('admin_menu', array(__CLASS__, 'register_menu'), 108);
        add_action('admin_post_uapm_add_project_activity', array(__CLASS__, 'handle_add'));
        add_action('admin_post_uapm_delete_project_activity', array(__CLASS__, 'handle_delete'));

        add_action('uapm_project_created', array(__CLASS__, 'log_project_created'), 10, 2);
        add_action('uapm_project_updated', array(__CLASS__, 'log_project_updated'), 10, 2);
        add_action('uapm_task_created', array(__CLASS__, 'log_task_created'), 10, 2);
        add_action('uapm_task_status_changed', array(__CLASS__, 'log_task_status'), 10, 3);
        add_action('uapm_comment_created', array(__CLASS__, 'log_comment_created'), 10, 2);
        add_action('uapm_attachment_uploaded', array(__CLASS__, 'log_attachment_uploaded'), 10, 2);
        add_action('uapm_calendar_event_created', array(__CLASS__, 'log_calendar_event'), 10, 2);
    }

    public static function install_schema() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            project_id bigint(20) unsigned NOT NULL DEFAULT 0,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            activity_type varchar(60) NOT NULL DEFAULT 'activity',
            title varchar(255) NOT NULL,
            description longtext NULL,
            object_type varchar(60) NOT NULL DEFAULT '',
            object_id bigint(20) unsigned NOT NULL DEFAULT 0,
            visibility varchar(20) NOT NULL DEFAULT 'team',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY project_id (project_id),
            KEY user_id (user_id),
            KEY activity_type (activity_type),
            KEY object_id (object_id),
            KEY created_at (created_at)
        ) {$charset};";

        dbDelta($sql);
    }

    public static function add($data) {
        global $wpdb;

        if (!is_array($data)) {
            return new WP_Error('uapm_activity_invalid', 'Некоректні дані активності.');
        }

        $title = isset($data['title'])
            ? sanitize_text_field($data['title'])
            : '';

        if ($title === '') {
            return new WP_Error('uapm_activity_title', 'Вкажіть назву події.');
        }

        $project_id = isset($data['project_id'])
            ? absint($data['project_id'])
            : 0;

        $visibility = isset($data['visibility'])
            ? sanitize_key($data['visibility'])
            : 'team';

        if (!in_array($visibility, array('team', 'private'), true)) {
            $visibility = 'team';
        }

        $result = $wpdb->insert(
            $wpdb->prefix . self::TABLE,
            array(
                'project_id' => $project_id,
                'user_id' => get_current_user_id(),
                'activity_type' => isset($data['activity_type'])
                    ? sanitize_key($data['activity_type'])
                    : 'activity',
                'title' => $title,
                'description' => isset($data['description'])
                    ? sanitize_textarea_field($data['description'])
                    : '',
                'object_type' => isset($data['object_type'])
                    ? sanitize_key($data['object_type'])
                    : '',
                'object_id' => isset($data['object_id'])
                    ? absint($data['object_id'])
                    : 0,
                'visibility' => $visibility,
                'created_at' => current_time('mysql'),
            ),
            array('%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
        );

        if (!$result) {
            return new WP_Error('uapm_activity_save', 'Не вдалося зберегти запис активності.');
        }

        return (int) $wpdb->insert_id;
    }

    public static function get_all($args = array()) {
        global $wpdb;

        $defaults = array(
            'project_id' => 0,
            'user_id' => 0,
            'activity_type' => '',
            'visibility' => '',
            'limit' => 50,
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

        if (!empty($args['user_id'])) {
            $where[] = 'user_id = %d';
            $params[] = absint($args['user_id']);
        }

        if (!empty($args['activity_type'])) {
            $where[] = 'activity_type = %s';
            $params[] = sanitize_key($args['activity_type']);
        }

        if (!empty($args['visibility'])) {
            $where[] = 'visibility = %s';
            $params[] = sanitize_key($args['visibility']);
        }

        if (!current_user_can('uapm_manage_projects')) {
            $where[] = '(visibility = %s OR user_id = %d)';
            $params[] = 'team';
            $params[] = get_current_user_id();
        }

        $params[] = max(1, min(200, absint($args['limit'])));
        $params[] = absint($args['offset']);

        $sql = "SELECT * FROM {$table}
                WHERE " . implode(' AND ', $where) . "
                ORDER BY created_at DESC, id DESC
                LIMIT %d OFFSET %d";

        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }

    public static function delete($activity_id) {
        global $wpdb;

        $activity = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}" . self::TABLE . " WHERE id = %d",
                absint($activity_id)
            )
        );

        if (!$activity) {
            return false;
        }

        if (!current_user_can('uapm_manage_projects') &&
            (int) $activity->user_id !== get_current_user_id()) {
            return new WP_Error('uapm_activity_forbidden', 'Немає прав для видалення цього запису.');
        }

        return (bool) $wpdb->delete(
            $wpdb->prefix . self::TABLE,
            array('id' => absint($activity_id)),
            array('%d')
        );
    }

    public static function log_project_created($project_id, $data = array()) {
        self::system_add(array(
            'project_id' => $project_id,
            'activity_type' => 'project_created',
            'title' => 'Створено проєкт',
            'description' => isset($data['name']) ? $data['name'] : '',
            'object_type' => 'project',
            'object_id' => $project_id,
        ));
    }

    public static function log_project_updated($project_id, $data = array()) {
        self::system_add(array(
            'project_id' => $project_id,
            'activity_type' => 'project_updated',
            'title' => 'Оновлено проєкт',
            'description' => isset($data['name']) ? $data['name'] : '',
            'object_type' => 'project',
            'object_id' => $project_id,
        ));
    }

    public static function log_task_created($task_id, $data = array()) {
        $project_id = isset($data['project_id']) ? absint($data['project_id']) : 0;

        self::system_add(array(
            'project_id' => $project_id,
            'activity_type' => 'task_created',
            'title' => 'Створено завдання',
            'object_type' => 'task',
            'object_id' => $task_id,
        ));
    }

    public static function log_task_status($task_id, $old_status, $new_status) {
        self::system_add(array(
            'activity_type' => 'task_status',
            'title' => 'Змінено статус завдання',
            'description' => sanitize_text_field($old_status . ' → ' . $new_status),
            'object_type' => 'task',
            'object_id' => $task_id,
        ));
    }

    public static function log_comment_created($comment_id, $data = array()) {
        self::system_add(array(
            'project_id' => isset($data['project_id']) ? absint($data['project_id']) : 0,
            'activity_type' => 'comment_created',
            'title' => 'Додано коментар',
            'object_type' => 'comment',
            'object_id' => $comment_id,
        ));
    }

    public static function log_attachment_uploaded($attachment_id, $data = array()) {
        self::system_add(array(
            'project_id' => isset($data['project_id']) ? absint($data['project_id']) : 0,
            'activity_type' => 'attachment_uploaded',
            'title' => 'Завантажено файл',
            'object_type' => 'attachment',
            'object_id' => $attachment_id,
        ));
    }

    public static function log_calendar_event($event_id, $data = array()) {
        self::system_add(array(
            'activity_type' => 'calendar_event',
            'title' => 'Створено подію календаря',
            'object_type' => 'calendar_event',
            'object_id' => $event_id,
        ));
    }

    private static function system_add($data) {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . self::TABLE,
            array(
                'project_id' => isset($data['project_id']) ? absint($data['project_id']) : 0,
                'user_id' => get_current_user_id(),
                'activity_type' => isset($data['activity_type'])
                    ? sanitize_key($data['activity_type'])
                    : 'activity',
                'title' => isset($data['title'])
                    ? sanitize_text_field($data['title'])
                    : 'Активність',
                'description' => isset($data['description'])
                    ? sanitize_textarea_field($data['description'])
                    : '',
                'object_type' => isset($data['object_type'])
                    ? sanitize_key($data['object_type'])
                    : '',
                'object_id' => isset($data['object_id'])
                    ? absint($data['object_id'])
                    : 0,
                'visibility' => 'team',
                'created_at' => current_time('mysql'),
            ),
            array('%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
        );
    }

    public static function register_menu() {
        add_submenu_page(
            'uapm',
            'Активність проєктів',
            'Активність',
            'uapm_manage_projects',
            'uapm-project-activity',
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

        $activities = self::get_all(array(
            'project_id' => $project_id,
            'limit' => 100,
        ));

        echo '<div class="wrap">';
        echo '<h1>Активність проєктів</h1>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="uapm_add_project_activity">';
        echo '<input type="hidden" name="project_id" value="' . esc_attr($project_id) . '">';
        wp_nonce_field('uapm_add_project_activity');

        echo '<p><input type="text" name="title" class="regular-text" placeholder="Назва запису" required></p>';
        echo '<p><textarea name="description" class="large-text" rows="3" placeholder="Опис"></textarea></p>';
        echo '<p><label>Видимість ';
        echo '<select name="visibility"><option value="team">Для команди</option><option value="private">Особистий запис</option></select>';
        echo '</label></p>';

        submit_button('Додати запис');
        echo '</form>';

        echo '<hr><h2>Історія</h2>';

        if (!$activities) {
            echo '<p>Записів активності поки немає.</p>';
        } else {
            echo '<table class="widefat striped">';
            echo '<thead><tr><th>Дата</th><th>Подія</th><th>Опис</th><th>Користувач</th><th>Тип</th><th>Дія</th></tr></thead><tbody>';

            foreach ($activities as $activity) {
                $user = get_userdata($activity->user_id);
                $author = $user ? $user->display_name : 'Система';

                echo '<tr>';
                echo '<td>' . esc_html($activity->created_at) . '</td>';
                echo '<td><strong>' . esc_html($activity->title) . '</strong></td>';
                echo '<td>' . esc_html($activity->description) . '</td>';
                echo '<td>' . esc_html($author) . '</td>';
                echo '<td>' . esc_html($activity->activity_type) . '</td>';
                echo '<td>';

                if (current_user_can('uapm_manage_projects') ||
                    (int) $activity->user_id === get_current_user_id()) {
                    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Видалити запис?\')">';
                    echo '<input type="hidden" name="action" value="uapm_delete_project_activity">';
                    echo '<input type="hidden" name="activity_id" value="' . esc_attr($activity->id) . '">';
                    wp_nonce_field('uapm_delete_project_activity_' . $activity->id);
                    submit_button('Видалити', 'delete small', 'submit', false);
                    echo '</form>';
                }

                echo '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        echo '</div>';
    }

    public static function handle_add() {
        if (!current_user_can('uapm_manage_projects')) {
            wp_die('Недостатньо прав.', '', array('response' => 403));
        }

        check_admin_referer('uapm_add_project_activity');

        $result = self::add(array(
            'project_id' => isset($_POST['project_id'])
                ? absint($_POST['project_id'])
                : 0,
            'title' => isset($_POST['title'])
                ? sanitize_text_field(wp_unslash($_POST['title']))
                : '',
            'description' => isset($_POST['description'])
                ? sanitize_textarea_field(wp_unslash($_POST['description']))
                : '',
            'visibility' => isset($_POST['visibility'])
                ? sanitize_key($_POST['visibility'])
                : 'team',
            'activity_type' => 'manual',
        ));

        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()));
        }

        wp_safe_redirect(admin_url('admin.php?page=uapm-project-activity&saved=1'));
        exit;
    }

    public static function handle_delete() {
        $activity_id = isset($_POST['activity_id'])
            ? absint($_POST['activity_id'])
            : 0;

        check_admin_referer('uapm_delete_project_activity_' . $activity_id);

        $result = self::delete($activity_id);

        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()));
        }

        wp_safe_redirect(admin_url('admin.php?page=uapm-project-activity&deleted=1'));
        exit;
    }
}