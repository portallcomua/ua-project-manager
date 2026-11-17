<?php
if (!defined('ABSPATH')) {
    exit;
}

class UAPM_Milestones {

    const TABLE = 'uapm_milestones';

    public static function init() {
        self::install_schema();

        add_action('admin_menu', array(__CLASS__, 'register_menu'), 109);
        add_action('admin_post_uapm_save_milestone', array(__CLASS__, 'handle_save'));
        add_action('admin_post_uapm_delete_milestone', array(__CLASS__, 'handle_delete'));
        add_action('admin_post_uapm_update_milestone_status', array(__CLASS__, 'handle_status'));
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
            milestone_version varchar(50) NOT NULL DEFAULT '',
            target_date date NULL,
            completed_at datetime NULL,
            status varchar(30) NOT NULL DEFAULT 'planned',
            progress tinyint(3) unsigned NOT NULL DEFAULT 0,
            sort_order int(11) NOT NULL DEFAULT 0,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY project_id (project_id),
            KEY status (status),
            KEY target_date (target_date),
            KEY sort_order (sort_order)
        ) {$charset};";

        dbDelta($sql);
    }

    public static function create($data) {
        global $wpdb;

        if (!is_array($data)) {
            return new WP_Error('uapm_milestone_invalid', 'Некоректні дані етапу.');
        }

        $title = isset($data['title'])
            ? sanitize_text_field($data['title'])
            : '';

        $project_id = isset($data['project_id'])
            ? absint($data['project_id'])
            : 0;

        if ($title === '' || !$project_id) {
            return new WP_Error('uapm_milestone_required', 'Вкажіть проєкт і назву етапу.');
        }

        if (class_exists('UAPM_Projects') && !UAPM_Projects::get($project_id)) {
            return new WP_Error('uapm_milestone_project', 'Проєкт не знайдено.');
        }

        $target_date = null;

        if (!empty($data['target_date'])) {
            $target_date = self::validate_date($data['target_date']);

            if (!$target_date) {
                return new WP_Error('uapm_milestone_date', 'Некоректна дата етапу.');
            }
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
                'milestone_version' => isset($data['milestone_version'])
                    ? sanitize_text_field($data['milestone_version'])
                    : '',
                'target_date' => $target_date,
                'completed_at' => null,
                'status' => 'planned',
                'progress' => 0,
                'sort_order' => isset($data['sort_order'])
                    ? intval($data['sort_order'])
                    : 0,
                'created_by' => get_current_user_id(),
                'created_at' => $now,
                'updated_at' => $now,
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s')
        );

        if (!$result) {
            return new WP_Error('uapm_milestone_save', 'Не вдалося створити етап.');
        }

        $id = (int) $wpdb->insert_id;

        do_action('uapm_milestone_created', $id, $data);

        return $id;
    }

    public static function update($milestone_id, $data) {
        global $wpdb;

        $milestone = self::get($milestone_id);

        if (!$milestone) {
            return new WP_Error('uapm_milestone_missing', 'Етап не знайдено.');
        }

        $updates = array(
            'updated_at' => current_time('mysql'),
        );

        if (isset($data['title'])) {
            $title = sanitize_text_field($data['title']);

            if ($title === '') {
                return new WP_Error('uapm_milestone_title', 'Назва етапу не може бути порожньою.');
            }

            $updates['title'] = $title;
        }

        if (isset($data['description'])) {
            $updates['description'] = sanitize_textarea_field($data['description']);
        }

        if (isset($data['milestone_version'])) {
            $updates['milestone_version'] = sanitize_text_field($data['milestone_version']);
        }

        if (array_key_exists('target_date', $data)) {
            $date = $data['target_date']
                ? self::validate_date($data['target_date'])
                : null;

            if ($data['target_date'] && !$date) {
                return new WP_Error('uapm_milestone_date', 'Некоректна дата етапу.');
            }

            $updates['target_date'] = $date;
        }

        if (isset($data['sort_order'])) {
            $updates['sort_order'] = intval($data['sort_order']);
        }

        $result = $wpdb->update(
            $wpdb->prefix . self::TABLE,
            $updates,
            array('id' => absint($milestone_id))
        );

        return $result !== false;
    }

    public static function update_status($milestone_id, $status, $progress = null) {
        global $wpdb;

        $milestone = self::get($milestone_id);

        if (!$milestone) {
            return new WP_Error('uapm_milestone_missing', 'Етап не знайдено.');
        }

        $allowed = array('planned', 'in_progress', 'completed', 'blocked');

        if (!in_array($status, $allowed, true)) {
            return new WP_Error('uapm_milestone_status', 'Некоректний статус етапу.');
        }

        if ($progress === null) {
            $progress = $status === 'completed'
                ? 100
                : (int) $milestone->progress;
        }

        $progress = max(0, min(100, absint($progress)));

        if ($status === 'completed') {
            $progress = 100;
        }

        $completed_at = $status === 'completed'
            ? current_time('mysql')
            : null;

        $result = $wpdb->update(
            $wpdb->prefix . self::TABLE,
            array(
                'status' => $status,
                'progress' => $progress,
                'completed_at' => $completed_at,
                'updated_at' => current_time('mysql'),
            ),
            array('id' => absint($milestone_id)),
            array('%s', '%d', '%s', '%s'),
            array('%d')
        );

        if ($result !== false) {
            do_action(
                'uapm_milestone_status_changed',
                absint($milestone_id),
                $milestone->status,
                $status
            );
        }

        return $result !== false;
    }

    public static function get($milestone_id) {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}" . self::TABLE . " WHERE id = %d",
                absint($milestone_id)
            )
        );
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
        $params = array();

        if (!empty($args['project_id'])) {
            $where[] = 'project_id = %d';
            $params[] = absint($args['project_id']);
        }

        if (!empty($args['status'])) {
            $where[] = 'status = %s';
            $params[] = sanitize_key($args['status']);
        }

        $params[] = max(1, min(500, absint($args['limit'])));
        $params[] = absint($args['offset']);

        $sql = "SELECT * FROM {$wpdb->prefix}" . self::TABLE . "
                WHERE " . implode(' AND ', $where) . "
                ORDER BY sort_order ASC, target_date ASC, id ASC
                LIMIT %d OFFSET %d";

        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }

    public static function get_progress($project_id) {
        $milestones = self::get_all(array(
            'project_id' => absint($project_id),
            'limit' => 500,
        ));

        if (!$milestones) {
            return array(
                'total' => 0,
                'completed' => 0,
                'progress' => 0,
            );
        }

        $total = count($milestones);
        $completed = 0;
        $sum = 0;

        foreach ($milestones as $milestone) {
            if ($milestone->status === 'completed') {
                $completed++;
            }

            $sum += (int) $milestone->progress;
        }

        return array(
            'total' => $total,
            'completed' => $completed,
            'progress' => (int) round($sum / $total),
        );
    }

    public static function delete($milestone_id) {
        global $wpdb;

        $milestone = self::get($milestone_id);

        if (!$milestone) {
            return false;
        }

        $result = $wpdb->delete(
            $wpdb->prefix . self::TABLE,
            array('id' => absint($milestone_id)),
            array('%d')
        );

        if ($result) {
            do_action('uapm_milestone_deleted', absint($milestone_id));
        }

        return (bool) $result;
    }

    private static function validate_date($value) {
        $value = sanitize_text_field((string) $value);

        $date = DateTime::createFromFormat('Y-m-d', $value);

        if (!$date || $date->format('Y-m-d') !== $value) {
            return false;
        }

        return $value;
    }

    public static function register_menu() {
        add_submenu_page(
            'uapm',
            'Етапи проєктів',
            'Етапи',
            'uapm_manage_projects',
            'uapm-milestones',
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

        $milestones = self::get_all(array(
            'project_id' => $project_id,
            'limit' => 200,
        ));

        echo '<div class="wrap">';
        echo '<h1>Етапи проєктів</h1>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="uapm_save_milestone">';
        wp_nonce_field('uapm_save_milestone');

        echo '<p><label>Проєкт ID <input type="number" name="project_id" value="' . esc_attr($project_id) . '" min="1" required></label></p>';
        echo '<p><input type="text" name="title" class="regular-text" placeholder="Назва етапу" required></p>';
        echo '<p><input type="text" name="milestone_version" class="regular-text" placeholder="Версія, наприклад 1.0.0"></p>';
        echo '<p><textarea name="description" class="large-text" rows="3" placeholder="Опис етапу"></textarea></p>';
        echo '<p><label>Цільова дата <input type="date" name="target_date"></label></p>';
        submit_button('Додати етап');
        echo '</form>';

        echo '<hr><h2>Список етапів</h2>';

        if (!$milestones) {
            echo '<p>Етапів поки немає.</p>';
        } else {
            echo '<table class="widefat striped">';
            echo '<thead><tr><th>Етап</th><th>Версія</th><th>Дата</th><th>Статус</th><th>Прогрес</th><th>Дія</th></tr></thead><tbody>';

            foreach ($milestones as $milestone) {
                echo '<tr>';
                echo '<td><strong>' . esc_html($milestone->title) . '</strong><br>' . esc_html($milestone->description) . '</td>';
                echo '<td>' . esc_html($milestone->milestone_version) . '</td>';
                echo '<td>' . esc_html($milestone->target_date ? $milestone->target_date : '—') . '</td>';
                echo '<td>' . esc_html($milestone->status) . '</td>';
                echo '<td>';
                echo '<div style="background:#dcdcde;height:8px;border-radius:4px;overflow:hidden;min-width:80px">';
                echo '<div style="height:8px;background:#2271b1;width:' . esc_attr((int) $milestone->progress) . '%"></div>';
                echo '</div>';
                echo esc_html((int) $milestone->progress) . '%';
                echo '</td>';
                echo '<td>';

                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block">';
                echo '<input type="hidden" name="action" value="uapm_update_milestone_status">';
                echo '<input type="hidden" name="milestone_id" value="' . esc_attr($milestone->id) . '">';
                wp_nonce_field('uapm_update_milestone_status_' . $milestone->id);
                echo '<select name="status">';
                foreach (array(
                    'planned' => 'Заплановано',
                    'in_progress' => 'У роботі',
                    'completed' => 'Завершено',
                    'blocked' => 'Заблоковано',
                ) as $key => $label) {
                    echo '<option value="' . esc_attr($key) . '" ' . selected($milestone->status, $key, false) . '>' . esc_html($label) . '</option>';
                }
                echo '</select> ';
                submit_button('Оновити', 'secondary small', 'submit', false);
                echo '</form> ';

                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block" onsubmit="return confirm(\'Видалити етап?\')">';
                echo '<input type="hidden" name="action" value="uapm_delete_milestone">';
                echo '<input type="hidden" name="milestone_id" value="' . esc_attr($milestone->id) . '">';
                wp_nonce_field('uapm_delete_milestone_' . $milestone->id);
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

        check_admin_referer('uapm_save_milestone');

        $result = self::create(array(
            'project_id' => isset($_POST['project_id']) ? absint($_POST['project_id']) : 0,
            'title' => isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '',
            'milestone_version' => isset($_POST['milestone_version']) ? sanitize_text_field(wp_unslash($_POST['milestone_version'])) : '',
            'description' => isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : '',
            'target_date' => isset($_POST['target_date']) ? sanitize_text_field(wp_unslash($_POST['target_date'])) : '',
        ));

        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()));
        }

        wp_safe_redirect(admin_url('admin.php?page=uapm-milestones&saved=1'));
        exit;
    }

    public static function handle_status() {
        $milestone_id = isset($_POST['milestone_id'])
            ? absint($_POST['milestone_id'])
            : 0;

        check_admin_referer('uapm_update_milestone_status_' . $milestone_id);

        if (!current_user_can('uapm_manage_projects')) {
            wp_die('Недостатньо прав.', '', array('response' => 403));
        }

        $status = isset($_POST['status'])
            ? sanitize_key($_POST['status'])
            : 'planned';

        $result = self::update_status($milestone_id, $status);

        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()));
        }

        wp_safe_redirect(admin_url('admin.php?page=uapm-milestones&updated=1'));
        exit;
    }

    public static function handle_delete() {
        $milestone_id = isset($_POST['milestone_id'])
            ? absint($_POST['milestone_id'])
            : 0;

        check_admin_referer('uapm_delete_milestone_' . $milestone_id);

        if (!current_user_can('uapm_manage_projects')) {
            wp_die('Недостатньо прав.', '', array('response' => 403));
        }

        self::delete($milestone_id);

        wp_safe_redirect(admin_url('admin.php?page=uapm-milestones&deleted=1'));
        exit;
    }
}