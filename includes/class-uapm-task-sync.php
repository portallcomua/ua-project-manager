<?php
/**
 * Task synchronization API for the UA Project Manager Android client.
 *
 * Uses WordPress Application Password authentication over HTTPS.
 * Offline clients keep UUIDs and revisions; stale writes are rejected with HTTP 409.
 */
if (!defined('ABSPATH')) {
    exit;
}

final class UAPM_Task_Sync {
    const REST_NAMESPACE = 'uapm/v1';

    public static function init() {
        add_action('init', array(__CLASS__, 'upgrade_schema'), 25);
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    public static function upgrade_schema() {
        global $wpdb;

        $table = $wpdb->prefix . 'uapm_tasks';
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        if ($found !== $table) {
            return;
        }

        /*
         * Do not use dbDelta() for this extension. The base task table is
         * created by UAPM_Tasks; dbDelta() here could attempt to add columns
         * and indexes that already exist on sites upgraded from earlier builds.
         * Inspect the live schema and add only missing elements.
         */
        $required_columns = array(
            'task_uuid' => "char(36) NOT NULL DEFAULT ''",
            'revision' => 'bigint(20) unsigned NOT NULL DEFAULT 1',
            'executor' => "varchar(100) NOT NULL DEFAULT ''",
            'notes' => 'longtext NULL',
            'device_id' => "varchar(64) NOT NULL DEFAULT ''",
            'deleted_at' => 'datetime NULL',
        );

        $existing_columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
        $existing_columns = is_array($existing_columns) ? $existing_columns : array();
        $complete = true;

        foreach ($required_columns as $column => $definition) {
            if (in_array($column, $existing_columns, true)) {
                continue;
            }

            $wpdb->query("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
            $existing_columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
            $existing_columns = is_array($existing_columns) ? $existing_columns : array();

            if (!in_array($column, $existing_columns, true)) {
                $complete = false;
            }
        }

        $required_indexes = array(
            'task_uuid' => '(task_uuid)',
            'updated_at' => '(updated_at)',
            'deleted_at' => '(deleted_at)',
        );

        $index_rows = $wpdb->get_results("SHOW INDEX FROM {$table}", ARRAY_A);
        $existing_indexes = array();
        foreach ((array) $index_rows as $index_row) {
            if (!empty($index_row['Key_name'])) {
                $existing_indexes[] = $index_row['Key_name'];
            }
        }

        foreach ($required_indexes as $index_name => $columns_sql) {
            if (in_array($index_name, $existing_indexes, true)) {
                continue;
            }

            $wpdb->query("ALTER TABLE {$table} ADD KEY {$index_name} {$columns_sql}");
            $index_rows = $wpdb->get_results("SHOW INDEX FROM {$table}", ARRAY_A);
            $existing_indexes = array();
            foreach ((array) $index_rows as $index_row) {
                if (!empty($index_row['Key_name'])) {
                    $existing_indexes[] = $index_row['Key_name'];
                }
            }

            if (!in_array($index_name, $existing_indexes, true)) {
                $complete = false;
            }
        }

        if ($complete) {
            update_option('uapm_task_sync_db_version', '1.1', false);
        }

        if (in_array('task_uuid', $existing_columns, true)) {
            $rows = $wpdb->get_col("SELECT id FROM {$table} WHERE task_uuid = '' OR task_uuid IS NULL LIMIT 500");
            foreach ((array) $rows as $id) {
                $wpdb->update(
                    $table,
                    array('task_uuid' => wp_generate_uuid4()),
                    array('id' => (int) $id),
                    array('%s'),
                    array('%d')
                );
            }
        }
    }

    public static function register_routes() {
        register_rest_route(self::REST_NAMESPACE, '/tasks', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array(__CLASS__, 'list_tasks'),
                'permission_callback' => array(__CLASS__, 'permissions'),
            ),
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array(__CLASS__, 'save_task'),
                'permission_callback' => array(__CLASS__, 'permissions'),
            ),
        ));

        register_rest_route(self::REST_NAMESPACE, '/tasks/(?P<uuid>[a-fA-F0-9-]{36})', array(
            array(
                'methods' => 'PUT',
                'callback' => array(__CLASS__, 'save_task'),
                'permission_callback' => array(__CLASS__, 'permissions'),
            ),
            array(
                'methods' => 'DELETE',
                'callback' => array(__CLASS__, 'delete_task'),
                'permission_callback' => array(__CLASS__, 'permissions'),
            ),
        ));
    }

    public static function permissions() {
        if (!is_user_logged_in()) {
            return new WP_Error('uapm_task_auth', 'Увійдіть у свій обліковий запис WordPress.', array('status' => 401));
        }
        if (!current_user_can('uapm_manage_projects') && !current_user_can('manage_options')) {
            return new WP_Error('uapm_task_forbidden', 'Немає дозволу на синхронізацію задач.', array('status' => 403));
        }
        return true;
    }

    public static function list_tasks($request) {
        global $wpdb;
        $table = $wpdb->prefix . 'uapm_tasks';
        $since = sanitize_text_field((string) $request->get_param('since'));
        $where = array('1=1');
        $params = array();

        if ($since !== '') {
            $date = strtotime($since);
            if ($date) {
                $where[] = 'updated_at >= %s';
                $params[] = gmdate('Y-m-d H:i:s', $date);
            }
        }

        if (!current_user_can('manage_options') && !current_user_can('uapm_manage_projects')) {
            $user_id = get_current_user_id();
            $where[] = '(created_by = %d OR assigned_user_id = %d)';
            $params[] = $user_id;
            $params[] = $user_id;
        }

        $sql = "SELECT * FROM {$table} WHERE " . implode(' AND ', $where) . " ORDER BY updated_at ASC LIMIT 500";
        $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
        return rest_ensure_response(array(
            'success' => true,
            'server_time' => current_time('mysql', true),
            'tasks' => array_map(array(__CLASS__, 'format_task'), (array) $rows),
        ));
    }

    private static function format_task($row) {
        return array(
            'uuid' => (string) $row['task_uuid'],
            'id' => (int) $row['id'],
            'project_id' => (int) $row['project_id'],
            'title' => (string) $row['title'],
            'description' => (string) $row['description'],
            'notes' => (string) $row['notes'],
            'executor' => (string) $row['executor'],
            'status' => (string) $row['status'],
            'priority' => (string) $row['priority'],
            'assigned_user_id' => (int) $row['assigned_user_id'],
            'created_by' => (int) $row['created_by'],
            'due_date' => $row['due_date'],
            'completed_at' => $row['completed_at'],
            'updated_at' => $row['updated_at'],
            'revision' => (int) $row['revision'],
            'device_id' => (string) $row['device_id'],
            'deleted_at' => $row['deleted_at'],
        );
    }

    public static function save_task($request) {
        global $wpdb;
        $table = $wpdb->prefix . 'uapm_tasks';
        $data = $request->get_json_params();
        if (!is_array($data)) {
            $data = $request->get_params();
        }

        $uuid = sanitize_text_field((string) ($request->get_param('uuid') ?: ($data['uuid'] ?? '')));
        if (!$uuid || !wp_is_uuid($uuid)) {
            return new WP_Error('uapm_task_uuid', 'Некоректний UUID задачі.', array('status' => 400));
        }

        $title = sanitize_text_field((string) ($data['title'] ?? ''));
        if ($title === '') {
            return new WP_Error('uapm_task_title', 'Вкажіть назву задачі.', array('status' => 400));
        }

        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE task_uuid = %s LIMIT 1", $uuid), ARRAY_A);
        $client_revision = absint($data['revision'] ?? 0);

        if ($existing && $client_revision !== (int) $existing['revision']) {
            return new WP_REST_Response(array(
                'success' => false,
                'conflict' => true,
                'message' => 'Задачу змінено на сервері. Спочатку отримайте актуальну версію.',
                'server_task' => self::format_task($existing),
            ), 409);
        }

        $status = sanitize_key((string) ($data['status'] ?? 'pending'));
        if (!in_array($status, array('pending', 'in_progress', 'completed', 'cancelled'), true)) {
            $status = 'pending';
        }
        $priority = sanitize_key((string) ($data['priority'] ?? 'normal'));
        if (!in_array($priority, array('low', 'normal', 'high', 'urgent'), true)) {
            $priority = 'normal';
        }
        $due_date = sanitize_text_field((string) ($data['due_date'] ?? ''));
        if ($due_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $due_date)) {
            return new WP_Error('uapm_task_due_date', 'Некоректна дата виконання.', array('status' => 400));
        }
        if ($due_date !== '' && strlen($due_date) === 10) {
            $due_date .= ' 23:59:59';
        }
        $project_id = absint($data['project_id'] ?? 0);
        if ($project_id && class_exists('UAPM_Projects') && !UAPM_Projects::get($project_id)) {
            return new WP_Error('uapm_task_project', 'Проєкт не знайдено.', array('status' => 400));
        }

        $now = current_time('mysql', true);
        $values = array(
            'project_id' => $project_id,
            'title' => $title,
            'description' => wp_kses_post((string) ($data['description'] ?? '')),
            'notes' => sanitize_textarea_field((string) ($data['notes'] ?? '')),
            'executor' => sanitize_text_field((string) ($data['executor'] ?? '')),
            'status' => $status,
            'priority' => $priority,
            'assigned_user_id' => absint($data['assigned_user_id'] ?? get_current_user_id()),
            'due_date' => $due_date !== '' ? $due_date : null,
            'completed_at' => $status === 'completed' ? ($existing['completed_at'] ?? $now) : null,
            'updated_at' => $now,
            'revision' => $existing ? ((int) $existing['revision'] + 1) : 1,
            'device_id' => sanitize_text_field(substr((string) ($data['device_id'] ?? ''), 0, 64)),
            'deleted_at' => null,
        );

        if ($existing) {
            $wpdb->update($table, $values, array('id' => (int) $existing['id']));
            $id = (int) $existing['id'];
        } else {
            $values['task_uuid'] = $uuid;
            $values['created_by'] = get_current_user_id();
            $values['created_at'] = $now;
            $wpdb->insert($table, $values);
            $id = (int) $wpdb->insert_id;
        }

        if (!$id) {
            return new WP_Error('uapm_task_save', 'Не вдалося зберегти задачу.', array('status' => 500));
        }

        $saved = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), ARRAY_A);
        return rest_ensure_response(array('success' => true, 'task' => self::format_task($saved)));
    }

    public static function delete_task($request) {
        global $wpdb;
        $table = $wpdb->prefix . 'uapm_tasks';
        $uuid = sanitize_text_field((string) $request->get_param('uuid'));
        $revision = absint($request->get_param('revision'));
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE task_uuid = %s LIMIT 1", $uuid), ARRAY_A);
        if (!$row) {
            return new WP_Error('uapm_task_missing', 'Задачу не знайдено.', array('status' => 404));
        }
        if ($revision !== (int) $row['revision']) {
            return new WP_REST_Response(array('success' => false, 'conflict' => true, 'server_task' => self::format_task($row)), 409);
        }
        $now = current_time('mysql', true);
        $wpdb->update($table, array('deleted_at' => $now, 'updated_at' => $now, 'revision' => ((int) $row['revision'] + 1)), array('id' => (int) $row['id']));
        return rest_ensure_response(array('success' => true, 'uuid' => $uuid, 'deleted_at' => $now, 'revision' => ((int) $row['revision'] + 1)));
    }
}

UAPM_Task_Sync::init();
