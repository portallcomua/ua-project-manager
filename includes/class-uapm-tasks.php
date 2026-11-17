<?php
if (!defined('ABSPATH')) {
    exit;
}

class UAPM_Tasks {

    const TABLE = 'uapm_tasks';

    public static function init() {
        self::install_schema();

        add_action('admin_menu', array(__CLASS__, 'register_menu'), 100);
        add_action('admin_post_uapm_save_task', array(__CLASS__, 'handle_save'));
        add_action('admin_post_uapm_update_task', array(__CLASS__, 'handle_update'));
        add_action('admin_post_uapm_delete_task', array(__CLASS__, 'handle_delete'));
    }

    public static function install_schema() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            project_id bigint(20) unsigned NOT NULL DEFAULT 0,
            title varchar(255) NOT NULL,
            description longtext NULL,
            status varchar(30) NOT NULL DEFAULT 'pending',
            priority varchar(20) NOT NULL DEFAULT 'normal',
            assigned_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            due_date datetime NULL,
            completed_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY project_id (project_id),
            KEY status (status),
            KEY priority (priority),
            KEY assigned_user_id (assigned_user_id),
            KEY due_date (due_date)
        ) {$charset};";

        dbDelta($sql);
    }

    public static function create($data) {
        global $wpdb;

        if (!is_array($data)) {
            return new WP_Error('uapm_task_invalid', 'Некоректні дані завдання.');
        }

        $title = isset($data['title'])
            ? sanitize_text_field($data['title'])
            : '';

        if ($title === '') {
            return new WP_Error('uapm_task_title', 'Вкажіть назву завдання.');
        }

        $project_id = isset($data['project_id']) ? absint($data['project_id']) : 0;

        if ($project_id && class_exists('UAPM_Projects') &&
            !UAPM_Projects::get($project_id)) {
            return new WP_Error('uapm_task_project', 'Проєкт не знайдено.');
        }

        $assigned_user_id = isset($data['assigned_user_id'])
            ? absint($data['assigned_user_id'])
            : 0;

        if ($assigned_user_id && !get_userdata($assigned_user_id)) {
            return new WP_Error('uapm_task_user', 'Користувача не знайдено.');
        }

        $due_date = self::sanitize_due_date(
            isset($data['due_date']) ? $data['due_date'] : ''
        );

        if (is_wp_error($due_date)) {
            return $due_date;
        }

        $now = current_time('mysql');

        $result = $wpdb->insert(
            $wpdb->prefix . self::TABLE,
            array(
                'project_id' => $project_id,
                'title' => $title,
                'description' => isset($data['description'])
                    ? sanitize_textarea_field($data['description'])
                    : '',
                'status' => 'pending',
                'priority' => self::sanitize_priority(
                    isset($data['priority']) ? $data['priority'] : 'normal'
                ),
                'assigned_user_id' => $assigned_user_id,
                'created_by' => get_current_user_id(),
                'due_date' => $due_date,
                'completed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ),
            array('%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s')
        );

        if (!$result) {
            return new WP_Error('uapm_task_create', 'Не вдалося створити завдання.');
        }

        $task_id = (int) $wpdb->insert_id;

        do_action('uapm_task_created', $task_id, $data);

        return $task_id;
    }

    public static function get($task_id) {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}" . self::TABLE . " WHERE id = %d",
                absint($task_id)
            )
        );
    }

    public static function update_status($task_id, $status) {
        global $wpdb;

        $task = self::get($task_id);

        if (!$task) {
            return new WP_Error('uapm_task_missing', 'Завдання не знайдено.');
        }

        $allowed = array('pending', 'in_progress', 'completed', 'cancelled');

        if (!in_array($status, $allowed, true)) {
            return new WP_Error('uapm_task_status', 'Невідомий статус завдання.');
        }

        $completed_at = $status === 'completed'
            ? current_time('mysql')
            : null;

        $result = $wpdb->update(
            $wpdb->prefix . self::TABLE,
            array(
                'status' => $status,
                'completed_at' => $completed_at,
                'updated_at' => current_time('mysql'),
            ),
            array('id' => absint($task_id)),
            array('%s', '%s', '%s'),
            array('%d')
        );

        if ($result === false) {
            return new WP_Error('uapm_task_update', 'Не вдалося оновити завдання.');
        }

        do_action('uapm_task_status_changed', absint($task_id), $status, $task->status);

        return true;
    }

    public static function get_all($args = array()) {
        global $wpdb;

        $defaults = array(
            'project_id' => 0,
            'status' => '',
            'assigned_user_id' => 0,
            'search' => '',
            'limit' => 50,
            'offset' => 0,
        );

        $args = wp_parse_args($args, $defaults);

        $where = array('1=1');
        $params = array();
        $table = $wpdb->prefix . self::TABLE;

        if (!current_user_can('uapm_manage_projects')) {
            $where[] = '(created_by = %d OR assigned_user_id = %d)';
            $user_id = get_current_user_id();
            $params[] = $user_id;
            $params[] = $user_id;
        }

        if (!empty($args['project_id'])) {
            $where[] = 'project_id = %d';
            $params[] = absint($args['project_id']);
        }

        if (!empty($args['status'])) {
            $where[] = 'status = %s';
            $params[] = sanitize_key($args['status']);
        }

        if (!empty($args['assigned_user_id'])) {
            $where[] = 'assigned_user_id = %d';
            $params[] = absint($args['assigned_user_id']);
        }

        if (!empty($args['search'])) {
            $where[] = '(title LIKE %s OR description LIKE %s)';
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
                ORDER BY
                    FIELD(status, 'pending', 'in_progress', 'completed', 'cancelled'),
                    FIELD(priority, 'urgent', 'high', 'normal', 'low'),
                    due_date ASC,
                    id DESC
                LIMIT %d OFFSET %d";

        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }

    public static function count($status = '') {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE;

        if (!current_user_can('uapm_manage_projects')) {
            $user_id = get_current_user_id();

            if ($status !== '') {
                return (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$table}
                         WHERE (created_by = %d OR assigned_user_id = %d)
                         AND status = %s",
                        $user_id,
                        $user_id,
                        sanitize_key($status)
                    )
                );
            }

            return (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table}
                     WHERE created_by = %d OR assigned_user_id = %d",
                    $user_id,
                    $user_id
                )
            );
        }

        if ($status !== '') {
            return (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE status = %s",
                    sanitize_key($status)
                )
            );
        }

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    }

    private static function sanitize_priority($priority) {
        $allowed = array('low', 'normal', 'high', 'urgent');

        return in_array($priority, $allowed, true)
            ? $priority
            : 'normal';
    }

    private static function sanitize_due_date($value) {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $date = DateTime::createFromFormat('Y-m-d\TH:i', $value);

        if (!$date || $date->format('Y-m-d\TH:i') !== $value) {
            return new WP_Error('uapm_task_date', 'Некоректна дата виконання.');
        }

        return $date->format('Y-m-d H:i:s');
    }

    public static function register_menu() {
        add_submenu_page(
            'uapm',
            'Завдання',
            'Завдання',
            'uapm_manage_projects',
            'uapm-tasks',
            array(__CLASS__, 'render_page')
        );
    }

    public static function render_page() {
        if (!current_user_can('uapm_manage_projects')) {
            wp_die('Недостатньо прав.');
        }

        $tasks = self::get_all(array('limit' => 100));

        echo '<div class="wrap">';
        echo '<h1>Завдання проєктів</h1>';

        if (isset($_GET['saved'])) {
            echo '<div class="notice notice-success"><p>Завдання створено.</p></div>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="uapm_save_task">';
        wp_nonce_field('uapm_save_task');

        echo '<h2>Нове завдання</h2>';
        echo '<p><input type="text" name="title" class="large-text" placeholder="Назва завдання" required></p>';
        echo '<p><textarea name="description" class="large-text" rows="3" placeholder="Опис"></textarea></p>';

        echo '<p><label>Пріоритет ';
        echo '<select name="priority">';
        echo '<option value="low">Низький</option>';
        echo '<option value="normal" selected>Звичайний</option>';
        echo '<option value="high">Високий</option>';
        echo '<option value="urgent">Терміновий</option>';
        echo '</select></label></p>';

        echo '<p><label>Термін виконання ';
        echo '<input type="datetime-local" name="due_date">';
        echo '</label></p>';

        echo '<p><label>Відповідальний ';
        echo '<select name="assigned_user_id">';
        echo '<option value="0">Не призначено</option>';

        $users = get_users(array('fields' => array('ID', 'display_name')));

        foreach ($users as $user) {
            echo '<option value="' . esc_attr($user->ID) . '">' . esc_html($user->display_name) . '</option>';
        }

        echo '</select></label></p>';

        if (class_exists('UAPM_Projects')) {
            $projects = UAPM_Projects::get_all(array('limit' => 200));

            echo '<p><label>Проєкт ';
            echo '<select name="project_id">';
            echo '<option value="0">Без прив’язки</option>';

            foreach ($projects as $project) {
                echo '<option value="' . esc_attr($project->id) . '">' . esc_html($project->name) . '</option>';
            }

            echo '</select></label></p>';
        }

        submit_button('Створити завдання');
        echo '</form>';

        echo '<hr><h2>Список завдань</h2>';
        echo '<table class="widefat striped">';
        echo '<thead><tr><th>Завдання</th><th>Статус</th><th>Пріоритет</th><th>Відповідальний</th><th>Термін</th><th>Дія</th></tr></thead><tbody>';

        if ($tasks) {
            foreach ($tasks as $task) {
                $user = $task->assigned_user_id
                    ? get_userdata($task->assigned_user_id)
                    : false;

                $user_name = $user ? $user->display_name : '—';

                $statuses = array(
                    'pending' => 'Очікує',
                    'in_progress' => 'У роботі',
                    'completed' => 'Виконано',
                    'cancelled' => 'Скасовано',
                );

                echo '<tr>';
                echo '<td><strong>' . esc_html($task->title) . '</strong><br>';
                echo esc_html($task->description) . '</td>';
                echo '<td>' . esc_html(isset($statuses[$task->status]) ? $statuses[$task->status] : $task->status) . '</td>';
                echo '<td>' . esc_html($task->priority) . '</td>';
                echo '<td>' . esc_html($user_name) . '</td>';
                echo '<td>' . esc_html($task->due_date ? $task->due_date : '—') . '</td>';
                echo '<td>';

                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                echo '<input type="hidden" name="action" value="uapm_update_task">';
                echo '<input type="hidden" name="task_id" value="' . esc_attr($task->id) . '">';
                wp_nonce_field('uapm_update_task_' . $task->id);

                echo '<select name="status">';

                foreach ($statuses as $key => $label) {
                    echo '<option value="' . esc_attr($key) . '" ' . selected($task->status, $key, false) . '>';
                    echo esc_html($label);
                    echo '</option>';
                }

                echo '</select> ';
                submit_button('Оновити', 'secondary small', 'submit', false);
                echo '</form>';

                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Видалити завдання?\')">';
                echo '<input type="hidden" name="action" value="uapm_delete_task">';
                echo '<input type="hidden" name="task_id" value="' . esc_attr($task->id) . '">';
                wp_nonce_field('uapm_delete_task_' . $task->id);
                submit_button('Видалити', 'delete small', 'submit', false);
                echo '</form>';

                echo '</td></tr>';
            }
        } else {
            echo '<tr><td colspan="6">Завдань поки немає.</td></tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    public static function handle_save() {
        if (!current_user_can('uapm_manage_projects')) {
            wp_die('Недостатньо прав.', '', array('response' => 403));
        }

        check_admin_referer('uapm_save_task');

        $data = array(
            'title' => isset($_POST['title'])
                ? sanitize_text_field(wp_unslash($_POST['title']))
                : '',
            'description' => isset($_POST['description'])
                ? sanitize_textarea_field(wp_unslash($_POST['description']))
                : '',
            'project_id' => isset($_POST['project_id'])
                ? absint($_POST['project_id'])
                : 0,
            'priority' => isset($_POST['priority'])
                ? sanitize_key(wp_unslash($_POST['priority']))
                : 'normal',
            'assigned_user_id' => isset($_POST['assigned_user_id'])
                ? absint($_POST['assigned_user_id'])
                : 0,
            'due_date' => isset($_POST['due_date'])
                ? sanitize_text_field(wp_unslash($_POST['due_date']))
                : '',
        );

        $result = self::create($data);

        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()));
        }

        wp_safe_redirect(
            add_query_arg(
                array('page' => 'uapm-tasks', 'saved' => 1),
                admin_url('admin.php')
            )
        );

        exit;
    }

    public static function handle_update() {
        $task_id = isset($_POST['task_id']) ? absint($_POST['task_id']) : 0;

        if (!current_user_can('uapm_manage_projects')) {
            wp_die('Недостатньо прав.', '', array('response' => 403));
        }

        check_admin_referer('uapm_update_task_' . $task_id);

        $status = isset($_POST['status'])
            ? sanitize_key(wp_unslash($_POST['status']))
            : '';

        $result = self::update_status($task_id, $status);

        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()));
        }

        wp_safe_redirect(admin_url('admin.php?page=uapm-tasks'));
        exit;
    }

    public static function handle_delete() {
        global $wpdb;

        $task_id = isset($_POST['task_id']) ? absint($_POST['task_id']) : 0;

        if (!current_user_can('uapm_manage_projects')) {
            wp_die('Недостатньо прав.', '', array('response' => 403));
        }

        check_admin_referer('uapm_delete_task_' . $task_id);

        $result = $wpdb->delete(
            $wpdb->prefix . self::TABLE,
            array('id' => $task_id),
            array('%d')
        );

        if ($result === false) {
            wp_die('Не вдалося видалити завдання.');
        }

        do_action('uapm_task_deleted', $task_id);

        wp_safe_redirect(admin_url('admin.php?page=uapm-tasks'));
        exit;
    }
}