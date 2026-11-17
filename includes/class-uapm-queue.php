<?php
if (!defined('ABSPATH')) {
    exit;
}

class UAPM_Queue {

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'uapm_queue';
    }

    public static function init() {
        self::install_schema();

        add_action('uapm_process_queue', array(__CLASS__, 'process'));
        add_action('admin_post_uapm_retry_queue_item', array(__CLASS__, 'handle_retry'));
        add_action('admin_post_uapm_delete_queue_item', array(__CLASS__, 'handle_delete'));

        if (!wp_next_scheduled('uapm_process_queue')) {
            wp_schedule_event(time() + 60, 'uapm_every_minute', 'uapm_process_queue');
        }

        add_filter('cron_schedules', array(__CLASS__, 'add_cron_interval'));
    }

    public static function add_cron_interval($schedules) {
        $schedules['uapm_every_minute'] = array(
            'interval' => 60,
            'display' => 'UA Project Manager: щохвилини',
        );

        return $schedules;
    }

    public static function install_schema() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE " . self::table() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            task_type varchar(100) NOT NULL,
            payload longtext NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            attempts int(11) unsigned NOT NULL DEFAULT 0,
            max_attempts int(11) unsigned NOT NULL DEFAULT 5,
            available_at datetime NOT NULL,
            locked_at datetime NULL,
            last_error text NULL,
            created_at datetime NOT NULL,
            completed_at datetime NULL,
            PRIMARY KEY  (id),
            KEY status_available (status, available_at),
            KEY task_type (task_type)
        ) {$charset};";

        dbDelta($sql);
    }

    public static function enqueue($task_type, $payload = array(), $delay = 0, $max_attempts = 5) {
        global $wpdb;

        $task_type = sanitize_key($task_type);

        if (!$task_type || !is_array($payload)) {
            return new WP_Error('invalid_task', 'Некоректне завдання.');
        }

        $allowed = apply_filters('uapm_allowed_queue_tasks', array(
            'send_mail',
            'send_webhook',
            'process_download',
            'sync_repository',
            'process_installation',
            'generate_report',
        ));

        if (!in_array($task_type, $allowed, true)) {
            return new WP_Error('task_not_allowed', 'Цей тип завдання не дозволений.');
        }

        $now = current_time('timestamp');
        $available = $now + max(0, absint($delay));

        $inserted = $wpdb->insert(
            self::table(),
            array(
                'task_type' => $task_type,
                'payload' => wp_json_encode($payload),
                'status' => 'pending',
                'attempts' => 0,
                'max_attempts' => max(1, min(20, absint($max_attempts))),
                'available_at' => date('Y-m-d H:i:s', $available),
                'created_at' => current_time('mysql'),
            )
        );

        if (!$inserted) {
            return new WP_Error('queue_insert_failed', 'Не вдалося додати завдання до черги.');
        }

        if (!wp_next_scheduled('uapm_process_queue')) {
            wp_schedule_single_event(time() + 10, 'uapm_process_queue');
        }

        return (int) $wpdb->insert_id;
    }

    public static function process() {
        global $wpdb;

        $lock_name = 'uapm_queue_lock';

        if (get_transient($lock_name)) {
            return;
        }

        set_transient($lock_name, 1, 50);

        try {
            $processed = 0;
            $maximum = 10;

            while ($processed < $maximum) {
                $task = self::claim_next_task();

                if (!$task) {
                    break;
                }

                self::execute_task($task);
                $processed++;
            }
        } finally {
            delete_transient($lock_name);
        }

        $pending = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM " . self::table() . "
            WHERE status = 'pending' AND available_at <= '" . esc_sql(current_time('mysql')) . "'"
        );

        if ($pending > 0) {
            wp_schedule_single_event(time() + 15, 'uapm_process_queue');
        }
    }

    private static function claim_next_task() {
        global $wpdb;

        $table = self::table();
        $now = current_time('mysql');

        $wpdb->query('START TRANSACTION');

        $task = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                WHERE status = 'pending' AND available_at <= %s
                ORDER BY id ASC LIMIT 1 FOR UPDATE",
                $now
            )
        );

        if (!$task) {
            $wpdb->query('COMMIT');
            return null;
        }

        $updated = $wpdb->update(
            $table,
            array(
                'status' => 'processing',
                'locked_at' => $now,
                'attempts' => (int) $task->attempts + 1,
            ),
            array(
                'id' => (int) $task->id,
                'status' => 'pending',
            ),
            array('%s', '%s', '%d'),
            array('%d', '%s')
        );

        if (!$updated) {
            $wpdb->query('ROLLBACK');
            return null;
        }

        $wpdb->query('COMMIT');

        $task->status = 'processing';
        $task->locked_at = $now;
        $task->attempts = (int) $task->attempts + 1;

        return $task;
    }

    private static function execute_task($task) {
        $payload = json_decode($task->payload, true);

        if (!is_array($payload)) {
            self::fail_task($task, 'Некоректний JSON payload.');
            return;
        }

        try {
            $result = apply_filters(
                'uapm_execute_queue_task',
                null,
                $task->task_type,
                $payload,
                $task
            );

            if (is_wp_error($result)) {
                self::fail_task($task, $result->get_error_message());
                return;
            }

            if ($result === false) {
                self::fail_task($task, 'Обробник завдання повернув помилку.');
                return;
            }

            do_action('uapm_queue_task_completed', $task, $payload);

            global $wpdb;

            $wpdb->update(
                self::table(),
                array(
                    'status' => 'completed',
                    'completed_at' => current_time('mysql'),
                    'locked_at' => null,
                    'last_error' => null,
                ),
                array('id' => (int) $task->id),
                array('%s', '%s', '%s', '%s'),
                array('%d')
            );

        } catch (Throwable $exception) {
            self::fail_task($task, $exception->getMessage());
        }
    }

    private static function fail_task($task, $error) {
        global $wpdb;

        $attempts = (int) $task->attempts;
        $max_attempts = (int) $task->max_attempts;

        if ($attempts >= $max_attempts) {
            $status = 'failed';
            $delay = 0;
        } else {
            $status = 'pending';
            $delay = min(3600, 60 * pow(2, max(0, $attempts - 1)));
        }

        $wpdb->update(
            self::table(),
            array(
                'status' => $status,
                'available_at' => date(
                    'Y-m-d H:i:s',
                    current_time('timestamp') + $delay
                ),
                'locked_at' => null,
                'last_error' => sanitize_textarea_field($error),
            ),
            array('id' => (int) $task->id),
            array('%s', '%s', '%s', '%s'),
            array('%d')
        );

        do_action('uapm_queue_task_failed', $task, $error, $status);
    }

    public static function get_all($args = array()) {
        global $wpdb;

        $defaults = array(
            'status' => '',
            'task_type' => '',
            'limit' => 100,
            'offset' => 0,
        );

        $args = wp_parse_args($args, $defaults);

        $where = array('1=1');
        $values = array();

        if ($args['status']) {
            $where[] = 'status = %s';
            $values[] = sanitize_key($args['status']);
        }

        if ($args['task_type']) {
            $where[] = 'task_type = %s';
            $values[] = sanitize_key($args['task_type']);
        }

        $limit = max(1, min(500, absint($args['limit'])));
        $offset = absint($args['offset']);

        $sql = "SELECT * FROM " . self::table() . "
            WHERE " . implode(' AND ', $where) . "
            ORDER BY id DESC LIMIT %d OFFSET %d";

        $values[] = $limit;
        $values[] = $offset;

        return $wpdb->get_results($wpdb->prepare($sql, $values));
    }

    public static function count($status = '') {
        global $wpdb;

        if ($status) {
            return (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM " . self::table() . " WHERE status = %s",
                    sanitize_key($status)
                )
            );
        }

        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM " . self::table()
        );
    }

    public static function retry($task_id) {
        global $wpdb;

        $task_id = absint($task_id);

        if (!$task_id) {
            return new WP_Error('invalid_task', 'Некоректне завдання.');
        }

        $updated = $wpdb->update(
            self::table(),
            array(
                'status' => 'pending',
                'attempts' => 0,
                'available_at' => current_time('mysql'),
                'locked_at' => null,
                'last_error' => null,
            ),
            array('id' => $task_id, 'status' => 'failed'),
            array('%s', '%d', '%s', '%s', '%s'),
            array('%d', '%s')
        );

        return $updated
            ? true
            : new WP_Error('retry_failed', 'Не вдалося повторити завдання.');
    }

    public static function delete($task_id) {
        global $wpdb;

        $deleted = $wpdb->delete(
            self::table(),
            array('id' => absint($task_id)),
            array('%d')
        );

        return $deleted
            ? true
            : new WP_Error('delete_failed', 'Не вдалося видалити завдання.');
    }

    public static function handle_retry() {
        if (!current_user_can('uapm_manage_settings')) {
            wp_die('Недостатньо прав.');
        }

        check_admin_referer('uapm_retry_queue_item');

        self::retry(absint($_POST['task_id'] ?? 0));

        wp_safe_redirect(admin_url('admin.php?page=uapm-queue'));
        exit;
    }

    public static function handle_delete() {
        if (!current_user_can('uapm_manage_settings')) {
            wp_die('Недостатньо прав.');
        }

        check_admin_referer('uapm_delete_queue_item');

        self::delete(absint($_POST['task_id'] ?? 0));

        wp_safe_redirect(admin_url('admin.php?page=uapm-queue'));
        exit;
    }

    public static function render_page() {
        if (!current_user_can('uapm_manage_settings')) {
            wp_die('Недостатньо прав.');
        }

        $tasks = self::get_all(array('limit' => 200));

        echo '<div class="wrap uapm-wrap">';
        echo '<h1>Черга завдань</h1>';

        echo '<div class="uapm-stats">';
        echo '<div class="uapm-card"><strong>' . esc_html(self::count('pending')) . '</strong><span>Очікують</span></div>';
        echo '<div class="uapm-card"><strong>' . esc_html(self::count('processing')) . '</strong><span>В обробці</span></div>';
        echo '<div class="uapm-card"><strong>' . esc_html(self::count('failed')) . '</strong><span>Помилки</span></div>';
        echo '<div class="uapm-card"><strong>' . esc_html(self::count('completed')) . '</strong><span>Виконані</span></div>';
        echo '</div>';

        echo '<div class="uapm-card">';
        echo '<table class="widefat striped">';
        echo '<thead><tr><th>ID</th><th>Тип</th><th>Статус</th><th>Спроби</th><th>Наступний запуск</th><th>Помилка</th><th>Дії</th></tr></thead><tbody>';

        if (!$tasks) {
            echo '<tr><td colspan="7">Завдань поки немає.</td></tr>';
        }

        foreach ($tasks as $task) {
            echo '<tr>';
            echo '<td>' . esc_html($task->id) . '</td>';
            echo '<td>' . esc_html($task->task_type) . '</td>';
            echo '<td>' . esc_html($task->status) . '</td>';
            echo '<td>' . esc_html($task->attempts . ' / ' . $task->max_attempts) . '</td>';
            echo '<td>' . esc_html($task->available_at) . '</td>';
            echo '<td>' . esc_html($task->last_error) . '</td>';
            echo '<td>';

            if ($task->status === 'failed') {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
                echo '<input type="hidden" name="action" value="uapm_retry_queue_item">';
                echo '<input type="hidden" name="task_id" value="' . esc_attr($task->id) . '">';
                wp_nonce_field('uapm_retry_queue_item');
                echo '<button class="button">Повторити</button>';
                echo '</form> ';
            }

            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline" onsubmit="return confirm(\'Видалити завдання?\')">';
            echo '<input type="hidden" name="action" value="uapm_delete_queue_item">';
            echo '<input type="hidden" name="task_id" value="' . esc_attr($task->id) . '">';
            wp_nonce_field('uapm_delete_queue_item');
            echo '<button class="button button-link-delete">Видалити</button>';
            echo '</form>';

            echo '</td></tr>';
        }

        echo '</tbody></table>';
        echo '</div></div>';
    }

    public static function deactivate() {
        $timestamp = wp_next_scheduled('uapm_process_queue');

        if ($timestamp) {
            wp_unschedule_event($timestamp, 'uapm_process_queue');
        }
    }
}