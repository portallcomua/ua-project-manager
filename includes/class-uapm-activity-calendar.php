<?php
if (!defined('ABSPATH')) {
    exit;
}

class UAPM_Activity_Calendar {

    const TABLE = 'uapm_calendar_events';

    public static function init() {
        if (defined('UAPM_DB_VERSION') && get_option('uapm_modules_schema_ver') !== UAPM_DB_VERSION) {
            self::install_schema();
        }
        add_action('admin_menu', array(__CLASS__, 'register_menu'), 107);
        add_action('admin_post_uapm_save_calendar_event', array(__CLASS__, 'handle_save'));
        add_action('admin_post_uapm_delete_calendar_event', array(__CLASS__, 'handle_delete'));
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
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            title varchar(255) NOT NULL,
            description longtext NULL,
            event_type varchar(40) NOT NULL DEFAULT 'event',
            start_at datetime NOT NULL,
            end_at datetime NULL,
            all_day tinyint(1) NOT NULL DEFAULT 0,
            status varchar(30) NOT NULL DEFAULT 'scheduled',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY project_id (project_id),
            KEY task_id (task_id),
            KEY user_id (user_id),
            KEY start_at (start_at),
            KEY status (status)
        ) {$charset};";

        dbDelta($sql);
    }

    public static function create($data) {
        global $wpdb;

        if (!is_array($data)) {
            return new WP_Error('uapm_calendar_invalid', 'Некоректні дані події.');
        }

        $title = isset($data['title'])
            ? sanitize_text_field($data['title'])
            : '';

        if ($title === '') {
            return new WP_Error('uapm_calendar_title', 'Вкажіть назву події.');
        }

        $start_at = self::sanitize_datetime(
            isset($data['start_at']) ? $data['start_at'] : ''
        );

        if (!$start_at) {
            return new WP_Error('uapm_calendar_date', 'Вкажіть правильну дату початку.');
        }

        $end_at = '';

        if (!empty($data['end_at'])) {
            $end_at = self::sanitize_datetime($data['end_at']);

            if (!$end_at) {
                return new WP_Error('uapm_calendar_end', 'Некоректна дата завершення.');
            }

            if (strtotime($end_at) < strtotime($start_at)) {
                return new WP_Error('uapm_calendar_order', 'Дата завершення не може бути раніше початку.');
            }
        }

        $event_type = isset($data['event_type'])
            ? sanitize_key($data['event_type'])
            : 'event';

        $allowed_types = array('event', 'meeting', 'deadline', 'reminder', 'release');

        if (!in_array($event_type, $allowed_types, true)) {
            $event_type = 'event';
        }

        $project_id = isset($data['project_id']) ? absint($data['project_id']) : 0;
        $task_id = isset($data['task_id']) ? absint($data['task_id']) : 0;

        if ($project_id && class_exists('UAPM_Projects') &&
            !UAPM_Projects::get($project_id)) {
            return new WP_Error('uapm_calendar_project', 'Проєкт не знайдено.');
        }

        if ($task_id && class_exists('UAPM_Tasks') &&
            !UAPM_Tasks::get($task_id)) {
            return new WP_Error('uapm_calendar_task', 'Завдання не знайдено.');
        }

        $now = current_time('mysql');

        $result = $wpdb->insert(
            $wpdb->prefix . self::TABLE,
            array(
                'project_id' => $project_id,
                'task_id' => $task_id,
                'user_id' => get_current_user_id(),
                'title' => $title,
                'description' => isset($data['description'])
                    ? sanitize_textarea_field($data['description'])
                    : '',
                'event_type' => $event_type,
                'start_at' => $start_at,
                'end_at' => $end_at ? $end_at : null,
                'all_day' => !empty($data['all_day']) ? 1 : 0,
                'status' => 'scheduled',
                'created_at' => $now,
                'updated_at' => $now,
            ),
            array('%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s')
        );

        if (!$result) {
            return new WP_Error('uapm_calendar_save', 'Не вдалося зберегти подію.');
        }

        $event_id = (int) $wpdb->insert_id;

        do_action('uapm_calendar_event_created', $event_id, $data);

        return $event_id;
    }

    public static function update($event_id, $data) {
        global $wpdb;

        $event = self::get($event_id);

        if (!$event) {
            return new WP_Error('uapm_calendar_missing', 'Подію не знайдено.');
        }

        $title = isset($data['title'])
            ? sanitize_text_field($data['title'])
            : $event->title;

        $start_at = isset($data['start_at'])
            ? self::sanitize_datetime($data['start_at'])
            : $event->start_at;

        if (!$start_at || $title === '') {
            return new WP_Error('uapm_calendar_invalid', 'Перевірте назву та дату події.');
        }

        $end_at = $event->end_at;

        if (array_key_exists('end_at', $data)) {
            $end_at = $data['end_at']
                ? self::sanitize_datetime($data['end_at'])
                : null;

            if ($data['end_at'] && !$end_at) {
                return new WP_Error('uapm_calendar_end', 'Некоректна дата завершення.');
            }
        }

        if ($end_at && strtotime($end_at) < strtotime($start_at)) {
            return new WP_Error('uapm_calendar_order', 'Дата завершення не може бути раніше початку.');
        }

        $result = $wpdb->update(
            $wpdb->prefix . self::TABLE,
            array(
                'title' => $title,
                'description' => isset($data['description'])
                    ? sanitize_textarea_field($data['description'])
                    : $event->description,
                'start_at' => $start_at,
                'end_at' => $end_at,
                'all_day' => isset($data['all_day'])
                    ? (!empty($data['all_day']) ? 1 : 0)
                    : (int) $event->all_day,
                'updated_at' => current_time('mysql'),
            ),
            array('id' => absint($event_id)),
            array('%s', '%s', '%s', '%s', '%d', '%s'),
            array('%d')
        );

        return $result !== false;
    }

    public static function get($event_id) {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}" . self::TABLE . " WHERE id = %d",
                absint($event_id)
            )
        );
    }

    public static function get_all($args = array()) {
        global $wpdb;

        $defaults = array(
            'project_id' => 0,
            'task_id' => 0,
            'month' => '',
            'status' => '',
            'limit' => 200,
        );

        $args = wp_parse_args($args, $defaults);

        $where = array('1=1');
        $params = array();

        if (!empty($args['project_id'])) {
            $where[] = 'project_id = %d';
            $params[] = absint($args['project_id']);
        }

        if (!empty($args['task_id'])) {
            $where[] = 'task_id = %d';
            $params[] = absint($args['task_id']);
        }

        if (!empty($args['status'])) {
            $where[] = 'status = %s';
            $params[] = sanitize_key($args['status']);
        }

        if (preg_match('/^\d{4}-\d{2}$/', (string) $args['month'])) {
            $start = $args['month'] . '-01 00:00:00';
            $end = date('Y-m-d H:i:s', strtotime($start . ' +1 month'));

            $where[] = 'start_at >= %s AND start_at < %s';
            $params[] = $start;
            $params[] = $end;
        }

        $params[] = max(1, min(500, absint($args['limit'])));

        $sql = "SELECT * FROM {$wpdb->prefix}" . self::TABLE . "
                WHERE " . implode(' AND ', $where) . "
                ORDER BY start_at ASC
                LIMIT %d";

        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }

    public static function delete($event_id) {
        global $wpdb;

        $event = self::get($event_id);

        if (!$event) {
            return false;
        }

        if (!current_user_can('uapm_manage_projects') &&
            (int) $event->user_id !== get_current_user_id()) {
            return new WP_Error('uapm_calendar_forbidden', 'Немає прав для видалення події.');
        }

        $result = $wpdb->delete(
            $wpdb->prefix . self::TABLE,
            array('id' => absint($event_id)),
            array('%d')
        );

        if ($result) {
            do_action('uapm_calendar_event_deleted', absint($event_id));
        }

        return (bool) $result;
    }

    private static function sanitize_datetime($value) {
        $value = sanitize_text_field((string) $value);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value) &&
            !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $value)) {
            return false;
        }

        $value = str_replace('T', ' ', $value);

        if (strlen($value) === 16) {
            $value .= ':00';
        }

        $date = DateTime::createFromFormat('Y-m-d H:i:s', $value);

        if (!$date || $date->format('Y-m-d H:i:s') !== $value) {
            return false;
        }

        return $value;
    }

    public static function register_menu() {
        add_submenu_page(
            'uapm',
            'Календар проєктів',
            'Календар',
            'uapm_manage_projects',
            'uapm-calendar',
            array(__CLASS__, 'render_page')
        );
    }

    public static function render_page() {
        if (!current_user_can('uapm_manage_projects')) {
            wp_die('Недостатньо прав.');
        }

        $month = isset($_GET['month']) &&
            preg_match('/^\d{4}-\d{2}$/', $_GET['month'])
            ? sanitize_text_field(wp_unslash($_GET['month']))
            : current_time('Y-m');

        $events = self::get_all(array(
            'month' => $month,
            'limit' => 500,
        ));

        echo '<div class="wrap">';
        echo '<h1>Календар проєктів</h1>';

        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="uapm-calendar">';
        echo '<input type="month" name="month" value="' . esc_attr($month) . '">';
        submit_button('Показати', 'secondary', '', false);
        echo '</form>';

        echo '<h2>Нова подія</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="uapm_save_calendar_event">';
        wp_nonce_field('uapm_save_calendar_event');

        echo '<p><input type="text" name="title" class="regular-text" placeholder="Назва події" required></p>';
        echo '<p><textarea name="description" class="large-text" rows="3" placeholder="Опис"></textarea></p>';
        echo '<p><label>Тип події ';
        echo '<select name="event_type">';
        echo '<option value="event">Подія</option>';
        echo '<option value="meeting">Зустріч</option>';
        echo '<option value="deadline">Дедлайн</option>';
        echo '<option value="reminder">Нагадування</option>';
        echo '<option value="release">Реліз</option>';
        echo '</select></label></p>';
        echo '<p><label>Початок <input type="datetime-local" name="start_at" required></label></p>';
        echo '<p><label>Завершення <input type="datetime-local" name="end_at"></label></p>';
        echo '<p><label><input type="checkbox" name="all_day" value="1"> Увесь день</label></p>';
        submit_button('Створити подію');
        echo '</form>';

        echo '<hr><h2>Події за ' . esc_html($month) . '</h2>';

        if (!$events) {
            echo '<p>На цей місяць подій немає.</p>';
        } else {
            echo '<table class="widefat striped">';
            echo '<thead><tr><th>Дата</th><th>Подія</th><th>Тип</th><th>Опис</th><th>Автор</th><th>Дія</th></tr></thead><tbody>';

            foreach ($events as $event) {
                $user = get_userdata($event->user_id);
                $author = $user ? $user->display_name : '—';

                echo '<tr>';
                echo '<td>' . esc_html($event->start_at) . '</td>';
                echo '<td><strong>' . esc_html($event->title) . '</strong></td>';
                echo '<td>' . esc_html($event->event_type) . '</td>';
                echo '<td>' . esc_html($event->description) . '</td>';
                echo '<td>' . esc_html($author) . '</td>';
                echo '<td>';

                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Видалити подію?\')">';
                echo '<input type="hidden" name="action" value="uapm_delete_calendar_event">';
                echo '<input type="hidden" name="event_id" value="' . esc_attr($event->id) . '">';
                wp_nonce_field('uapm_delete_calendar_event_' . $event->id);
                submit_button('Видалити', 'delete small', 'submit', false);
                echo '</form>';

                echo '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        echo '</div>';
    }

    public static function handle_save() {
        if (!current_user_can('uapm_manage_projects')) {
            wp_die('Недостатньо прав.', '', array('response' => 403));
        }

        check_admin_referer('uapm_save_calendar_event');

        $data = array(
            'title' => isset($_POST['title'])
                ? sanitize_text_field(wp_unslash($_POST['title']))
                : '',
            'description' => isset($_POST['description'])
                ? sanitize_textarea_field(wp_unslash($_POST['description']))
                : '',
            'event_type' => isset($_POST['event_type'])
                ? sanitize_key($_POST['event_type'])
                : 'event',
            'start_at' => isset($_POST['start_at'])
                ? sanitize_text_field(wp_unslash($_POST['start_at']))
                : '',
            'end_at' => isset($_POST['end_at'])
                ? sanitize_text_field(wp_unslash($_POST['end_at']))
                : '',
            'all_day' => !empty($_POST['all_day']),
        );

        $result = self::create($data);

        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()));
        }

        wp_safe_redirect(admin_url('admin.php?page=uapm-calendar&saved=1'));
        exit;
    }

    public static function handle_delete() {
        $event_id = isset($_POST['event_id'])
            ? absint($_POST['event_id'])
            : 0;

        check_admin_referer('uapm_delete_calendar_event_' . $event_id);

        $result = self::delete($event_id);

        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()));
        }

        wp_safe_redirect(admin_url('admin.php?page=uapm-calendar&deleted=1'));
        exit;
    }
}