<?php
if (!defined('ABSPATH')) {
    exit;
}

class UAPM_Attachments {

    const TABLE = 'uapm_attachments';

    public static function init() {
        self::install_schema();

        add_action('admin_menu', array(__CLASS__, 'register_menu'), 106);
        add_action('admin_post_uapm_upload_attachment', array(__CLASS__, 'handle_upload'));
        add_action('admin_post_uapm_delete_attachment', array(__CLASS__, 'handle_delete'));
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
            comment_id bigint(20) unsigned NOT NULL DEFAULT 0,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
            file_name varchar(255) NOT NULL DEFAULT '',
            file_url text NOT NULL,
            mime_type varchar(150) NOT NULL DEFAULT '',
            file_size bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY project_id (project_id),
            KEY task_id (task_id),
            KEY comment_id (comment_id),
            KEY user_id (user_id),
            KEY attachment_id (attachment_id)
        ) {$charset};";

        dbDelta($sql);
    }

    public static function upload($file, $data = array()) {
        global $wpdb;

        if (!is_user_logged_in()) {
            return new WP_Error('uapm_attachment_auth', 'Потрібна авторизація.');
        }

        if (!isset($file['error']) || (int) $file['error'] !== UPLOAD_ERR_OK) {
            return new WP_Error('uapm_attachment_upload', 'Помилка завантаження файлу.');
        }

        $max_size = 10 * MB_IN_BYTES;

        if (empty($file['size']) || (int) $file['size'] > $max_size) {
            return new WP_Error('uapm_attachment_size', 'Максимальний розмір файлу — 10 МБ.');
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $allowed_mimes = array(
            'jpg|jpeg|jpe' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            'txt' => 'text/plain',
            'csv' => 'text/csv',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'zip' => 'application/zip',
        );

        $checked = wp_check_filetype_and_ext(
            $file['tmp_name'],
            sanitize_file_name($file['name']),
            $allowed_mimes
        );

        if (empty($checked['type']) ||
            !in_array($checked['type'], $allowed_mimes, true)) {
            return new WP_Error('uapm_attachment_type', 'Цей тип файлу не дозволений.');
        }

        $project_id = isset($data['project_id'])
            ? absint($data['project_id'])
            : 0;

        $task_id = isset($data['task_id'])
            ? absint($data['task_id'])
            : 0;

        $comment_id = isset($data['comment_id'])
            ? absint($data['comment_id'])
            : 0;

        if ($project_id && class_exists('UAPM_Projects') &&
            !UAPM_Projects::get($project_id)) {
            return new WP_Error('uapm_attachment_project', 'Проєкт не знайдено.');
        }

        $upload = wp_handle_upload(
            $file,
            array(
                'test_form' => false,
                'mimes' => $allowed_mimes,
            )
        );

        if (isset($upload['error'])) {
            return new WP_Error('uapm_attachment_upload', $upload['error']);
        }

        $attachment_id = wp_insert_attachment(
            array(
                'post_mime_type' => $upload['type'],
                'post_title' => sanitize_text_field(
                    pathinfo($upload['file'], PATHINFO_FILENAME)
                ),
                'post_status' => 'inherit',
            ),
            $upload['file']
        );

        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }

        $metadata = wp_generate_attachment_metadata(
            $attachment_id,
            $upload['file']
        );

        if (!is_wp_error($metadata) && !empty($metadata)) {
            wp_update_attachment_metadata($attachment_id, $metadata);
        }

        $result = $wpdb->insert(
            $wpdb->prefix . self::TABLE,
            array(
                'project_id' => $project_id,
                'task_id' => $task_id,
                'comment_id' => $comment_id,
                'user_id' => get_current_user_id(),
                'attachment_id' => absint($attachment_id),
                'file_name' => sanitize_file_name($file['name']),
                'file_url' => esc_url_raw($upload['url']),
                'mime_type' => sanitize_text_field($upload['type']),
                'file_size' => absint($file['size']),
                'created_at' => current_time('mysql'),
            ),
            array('%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s')
        );

        if (!$result) {
            wp_delete_attachment($attachment_id, true);
            return new WP_Error('uapm_attachment_database', 'Не вдалося зберегти запис файлу.');
        }

        $id = (int) $wpdb->insert_id;

        do_action('uapm_attachment_uploaded', $id, $data);

        return $id;
    }

    public static function get($id) {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}" . self::TABLE . " WHERE id = %d",
                absint($id)
            )
        );
    }

    public static function get_all($args = array()) {
        global $wpdb;

        $defaults = array(
            'project_id' => 0,
            'task_id' => 0,
            'comment_id' => 0,
            'limit' => 100,
            'offset' => 0,
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

        if (!empty($args['comment_id'])) {
            $where[] = 'comment_id = %d';
            $params[] = absint($args['comment_id']);
        }

        $params[] = max(1, min(200, absint($args['limit'])));
        $params[] = absint($args['offset']);

        $sql = "SELECT * FROM {$wpdb->prefix}" . self::TABLE . "
                WHERE " . implode(' AND ', $where) . "
                ORDER BY created_at DESC, id DESC
                LIMIT %d OFFSET %d";

        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }

    public static function delete($id) {
        global $wpdb;

        $attachment = self::get($id);

        if (!$attachment) {
            return false;
        }

        if (!current_user_can('uapm_manage_projects') &&
            (int) $attachment->user_id !== get_current_user_id()) {
            return new WP_Error('uapm_attachment_forbidden', 'Немає прав для видалення цього файлу.');
        }

        $deleted = $wpdb->delete(
            $wpdb->prefix . self::TABLE,
            array('id' => absint($id)),
            array('%d')
        );

        if ($deleted) {
            if ($attachment->attachment_id) {
                wp_delete_attachment((int) $attachment->attachment_id, true);
            }

            do_action('uapm_attachment_deleted', absint($id));
        }

        return (bool) $deleted;
    }

    public static function register_menu() {
        add_submenu_page(
            'uapm',
            'Файли проєктів',
            'Файли',
            'uapm_manage_projects',
            'uapm-attachments',
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

        $files = self::get_all(array(
            'project_id' => $project_id,
            'task_id' => $task_id,
            'limit' => 100,
        ));

        echo '<div class="wrap">';
        echo '<h1>Файли проєктів</h1>';

        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="uapm_upload_attachment">';
        echo '<input type="hidden" name="project_id" value="' . esc_attr($project_id) . '">';
        echo '<input type="hidden" name="task_id" value="' . esc_attr($task_id) . '">';
        wp_nonce_field('uapm_upload_attachment');

        echo '<p><input type="file" name="uapm_file" required></p>';
        echo '<p class="description">Дозволені зображення, PDF, документи, таблиці, TXT, CSV та ZIP. Максимум 10 МБ.</p>';
        submit_button('Завантажити файл');
        echo '</form>';

        echo '<hr><h2>Завантажені файли</h2>';

        if (!$files) {
            echo '<p>Файлів поки немає.</p>';
        } else {
            echo '<table class="widefat striped">';
            echo '<thead><tr><th>Файл</th><th>Тип</th><th>Розмір</th><th>Дата</th><th>Дія</th></tr></thead><tbody>';

            foreach ($files as $file) {
                echo '<tr>';
                echo '<td><a href="' . esc_url($file->file_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($file->file_name) . '</a></td>';
                echo '<td>' . esc_html($file->mime_type) . '</td>';
                echo '<td>' . esc_html(size_format((int) $file->file_size)) . '</td>';
                echo '<td>' . esc_html($file->created_at) . '</td>';
                echo '<td>';

                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Видалити файл?\')">';
                echo '<input type="hidden" name="action" value="uapm_delete_attachment">';
                echo '<input type="hidden" name="attachment_id" value="' . esc_attr($file->id) . '">';
                wp_nonce_field('uapm_delete_attachment_' . $file->id);
                submit_button('Видалити', 'delete small', 'submit', false);

                echo '</form>';
                echo '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        echo '</div>';
    }

    public static function handle_upload() {
        if (!is_user_logged_in()) {
            wp_die('Потрібна авторизація.', '', array('response' => 401));
        }

        check_admin_referer('uapm_upload_attachment');

        if (!current_user_can('uapm_manage_projects')) {
            wp_die('Недостатньо прав.', '', array('response' => 403));
        }

        if (empty($_FILES['uapm_file'])) {
            wp_die('Файл не вибрано.');
        }

        $data = array(
            'project_id' => isset($_POST['project_id'])
                ? absint($_POST['project_id'])
                : 0,
            'task_id' => isset($_POST['task_id'])
                ? absint($_POST['task_id'])
                : 0,
        );

        $result = self::upload($_FILES['uapm_file'], $data);

        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()));
        }

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page' => 'uapm-attachments',
                    'project_id' => $data['project_id'],
                    'task_id' => $data['task_id'],
                    'uploaded' => 1,
                ),
                admin_url('admin.php')
            )
        );

        exit;
    }

    public static function handle_delete() {
        $id = isset($_POST['attachment_id'])
            ? absint($_POST['attachment_id'])
            : 0;

        check_admin_referer('uapm_delete_attachment_' . $id);

        $result = self::delete($id);

        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()));
        }

        wp_safe_redirect(admin_url('admin.php?page=uapm-attachments'));
        exit;
    }
}