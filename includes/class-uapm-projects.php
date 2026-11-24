
<?php
/**
 * UA Project Manager
 * Projects and project plans management.
 *
 * @package UA_Project_Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

final class UAPM_Projects {

    /**
     * Initialize hooks.
     */
    public static function init() {
        add_action('admin_post_uapm_save_project', array(__CLASS__, 'handle_save_project'));
        add_action('admin_post_uapm_delete_project', array(__CLASS__, 'handle_delete_project'));
        add_action('admin_post_uapm_save_plan', array(__CLASS__, 'handle_save_plan'));
        add_action('admin_post_uapm_delete_plan', array(__CLASS__, 'handle_delete_plan'));
    }

    /**
     * Get projects table name.
     *
     * @return string
     */
    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'uapm_projects';
    }

    /**
     * Get plans table name.
     *
     * @return string
     */
    private static function plans_table() {
        global $wpdb;
        return $wpdb->prefix . 'uapm_project_plans';
    }

    /**
     * Check project management permission.
     *
     * @return bool
     */
    public static function can_manage() {
        return current_user_can('uapm_manage_projects') ||
            current_user_can('manage_options');
    }

    /**
     * Create a project.
     *
     * @param array $data Project data.
     * @return int|WP_Error
     */
    public static function create($data) {
        global $wpdb;

        if (!self::can_manage()) {
            return new WP_Error('uapm_forbidden', __('You are not allowed to create projects.', 'ua-project-manager'));
        }

        $prepared = self::sanitize_project_data($data);

        if (is_wp_error($prepared)) {
            return $prepared;
        }

        $now = current_time('mysql');

        $result = $wpdb->insert(
            self::table(),
            array(
                'name' => $prepared['name'],
                'slug' => self::unique_slug($prepared['slug']),
                'project_key' => $prepared['project_key'] ? $prepared['project_key'] : self::unique_slug($prepared['slug']),
                'description' => $prepared['description'],
                'project_type' => $prepared['project_type'],
                'status' => $prepared['status'],
                'version' => $prepared['version'],
                'author' => $prepared['author'],
                'website_url' => $prepared['website_url'],
                'documentation_url' => $prepared['documentation_url'],
                'github_repository' => $prepared['github_repository'],
                'github_branch' => $prepared['github_branch'],
                'logo_url' => $prepared['logo_url'],
                'created_at' => $now,
                'updated_at' => $now,
            ),
            array(
                '%s', '%s', '%s', '%s', '%s', '%s',
                '%s', '%s', '%s', '%s', '%s', '%s',
                '%s', '%s',
            )
        );

        if (!$result) {
            return new WP_Error('uapm_database_error', __('Could not create the project.', 'ua-project-manager'));
        }

        $project_id = (int) $wpdb->insert_id;

        self::log(
            $project_id,
            'project_created',
            sprintf('Project "%s" created.', $prepared['name'])
        );

        do_action('uapm_project_created', $project_id, $prepared);

        return $project_id;
    }

    /**
     * Update a project.
     *
     * @param int   $project_id Project ID.
     * @param array $data       Project data.
     * @return bool|WP_Error
     */
    public static function update($project_id, $data) {
        global $wpdb;

        if (!self::can_manage()) {
            return new WP_Error('uapm_forbidden', __('You are not allowed to edit projects.', 'ua-project-manager'));
        }

        $project_id = absint($project_id);

        if (!$project_id || !self::get($project_id)) {
            return new WP_Error('uapm_not_found', __('Project not found.', 'ua-project-manager'));
        }

        $prepared = self::sanitize_project_data($data);

        if (is_wp_error($prepared)) {
            return $prepared;
        }

        $existing = self::get($project_id);
        $slug = $prepared['slug'];

        if ($slug !== $existing->slug) {
            $slug = self::unique_slug($slug, $project_id);
        }

        $result = $wpdb->update(
            self::table(),
            array(
                'name' => $prepared['name'],
                'slug' => $slug,
                'project_key' => $prepared['project_key'] ? $prepared['project_key'] : $slug,
                'description' => $prepared['description'],
                'project_type' => $prepared['project_type'],
                'status' => $prepared['status'],
                'version' => $prepared['version'],
                'author' => $prepared['author'],
                'website_url' => $prepared['website_url'],
                'documentation_url' => $prepared['documentation_url'],
                'github_repository' => $prepared['github_repository'],
                'github_branch' => $prepared['github_branch'],
                'logo_url' => $prepared['logo_url'],
                'updated_at' => current_time('mysql'),
            ),
            array('id' => $project_id),
            array(
                '%s', '%s', '%s', '%s', '%s', '%s',
                '%s', '%s', '%s', '%s', '%s', '%s',
                '%s',
            ),
            array('%d')
        );

        if (false === $result) {
            return new WP_Error('uapm_database_error', __('Could not update the project.', 'ua-project-manager'));
        }

        self::log($project_id, 'project_updated', 'Project updated.');

        do_action('uapm_project_updated', $project_id, $prepared);

        return true;
    }

    /**
     * Archive a project instead of physically deleting its records.
     *
     * @param int $project_id Project ID.
     * @return bool|WP_Error
     */
    public static function delete($project_id) {
        global $wpdb;

        if (!self::can_manage()) {
            return new WP_Error('uapm_forbidden', __('You are not allowed to archive projects.', 'ua-project-manager'));
        }

        $project_id = absint($project_id);

        if (!$project_id || !self::get($project_id)) {
            return new WP_Error('uapm_not_found', __('Project not found.', 'ua-project-manager'));
        }

        $result = $wpdb->update(
            self::table(),
            array(
                'status' => 'archived',
                'updated_at' => current_time('mysql'),
            ),
            array('id' => $project_id),
            array('%s', '%s'),
            array('%d')
        );

        if (false === $result) {
            return new WP_Error('uapm_database_error', __('Could not archive the project.', 'ua-project-manager'));
        }

        self::log($project_id, 'project_archived', 'Project archived.');

        do_action('uapm_project_archived', $project_id);

        return true;
    }

    /**
     * Get a project by ID.
     *
     * @param int $project_id Project ID.
     * @return object|null
     */
    public static function get($project_id) {
        global $wpdb;

        $project_id = absint($project_id);

        if (!$project_id) {
            return null;
        }

        return $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . self::table() . ' WHERE id = %d',
                $project_id
            )
        );
    }

    /**
     * Get a project by slug.
     *
     * @param string $slug Project slug.
     * @return object|null
     */
    public static function get_by_slug($slug) {
        global $wpdb;

        $slug = sanitize_title($slug);

        if (!$slug) {
            return null;
        }

        return $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . self::table() . ' WHERE slug = %s',
                $slug
            )
        );
    }

    /**
     * Get projects list.
     *
     * @param array $args Query arguments.
     * @return array
     */
    public static function get_all($args = array()) {
        global $wpdb;

        $defaults = array(
            'status' => '',
            'project_type' => '',
            'search' => '',
            'limit' => 50,
            'offset' => 0,
            'orderby' => 'created_at',
            'order' => 'DESC',
        );

        $args = wp_parse_args($args, $defaults);

        $allowed_orderby = array(
            'id',
            'name',
            'slug',
            'status',
            'project_type',
            'version',
            'created_at',
            'updated_at',
        );

        $orderby = in_array($args['orderby'], $allowed_orderby, true)
            ? $args['orderby']
            : 'created_at';

        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';

        $limit = min(200, max(1, absint($args['limit'])));
        $offset = max(0, absint($args['offset']));

        $where = array('1=1');
        $values = array();

        if ($args['status'] !== '') {
            $where[] = 'status = %s';
            $values[] = sanitize_key($args['status']);
        }

        if ($args['project_type'] !== '') {
            $where[] = 'project_type = %s';
            $values[] = sanitize_key($args['project_type']);
        }

        if ($args['search'] !== '') {
            $search = '%' . $wpdb->esc_like(sanitize_text_field($args['search'])) . '%';

            $where[] = '(name LIKE %s OR slug LIKE %s OR description LIKE %s)';
            $values[] = $search;
            $values[] = $search;
            $values[] = $search;
        }

        $sql = 'SELECT * FROM ' . self::table() .
            ' WHERE ' . implode(' AND ', $where) .
            " ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";

        $values[] = $limit;
        $values[] = $offset;

        return $wpdb->get_results($wpdb->prepare($sql, $values));
    }

    /**
     * Count projects.
     *
     * @param array $args Filters.
     * @return int
     */
    public static function count($args = array()) {
        global $wpdb;

        $where = array('1=1');
        $values = array();

        if (!empty($args['status'])) {
            $where[] = 'status = %s';
            $values[] = sanitize_key($args['status']);
        }

        if (!empty($args['project_type'])) {
            $where[] = 'project_type = %s';
            $values[] = sanitize_key($args['project_type']);
        }

        $sql = 'SELECT COUNT(*) FROM ' . self::table() .
            ' WHERE ' . implode(' AND ', $where);

        if ($values) {
            $sql = $wpdb->prepare($sql, $values);
        }

        return (int) $wpdb->get_var($sql);
    }

    /**
     * Create or update a FREE/PRO plan.
     *
     * @param array $data Plan data.
     * @return int|WP_Error
     */
    public static function save_plan($data) {
        global $wpdb;

        if (!self::can_manage()) {
            return new WP_Error('uapm_forbidden', __('You are not allowed to manage plans.', 'ua-project-manager'));
        }

        $project_id = isset($data['project_id']) ? absint($data['project_id']) : 0;
        $plan_id = isset($data['id']) ? absint($data['id']) : 0;

        if (!$project_id || !self::get($project_id)) {
            return new WP_Error('uapm_invalid_project', __('Select a valid project.', 'ua-project-manager'));
        }

        $name = isset($data['name']) ? sanitize_text_field($data['name']) : '';
        $plan_type = isset($data['plan_type']) ? sanitize_key($data['plan_type']) : 'free';

        if (!$name) {
            return new WP_Error('uapm_invalid_plan', __('Plan name is required.', 'ua-project-manager'));
        }

        if (!in_array($plan_type, array('free', 'pro'), true)) {
            return new WP_Error('uapm_invalid_plan_type', __('Plan type must be FREE or PRO.', 'ua-project-manager'));
        }

        $price = isset($data['price']) ? (float) $data['price'] : 0;

        if ($price < 0) {
            return new WP_Error('uapm_invalid_price', __('Price cannot be negative.', 'ua-project-manager'));
        }

        $features = isset($data['features'])
            ? $data['features']
            : array();

        $limits = isset($data['limits_data'])
            ? $data['limits_data']
            : array();

        $record = array(
            'project_id' => $project_id,
            'plan_type' => $plan_type,
            'name' => $name,
            'price' => number_format($price, 2, '.', ''),
            'currency' => isset($data['currency']) ? strtoupper(sanitize_text_field($data['currency'])) : 'UAH',
            'description' => isset($data['description']) ? sanitize_textarea_field($data['description']) : '',
            'features' => wp_json_encode(self::sanitize_array($features)),
            'limits_data' => wp_json_encode(self::sanitize_array($limits)),
            'is_active' => isset($data['is_active']) ? (int) (bool) $data['is_active'] : 1,
        );

        if ($plan_id) {
            $existing = self::get_plan($plan_id);

            if (!$existing || (int) $existing->project_id !== $project_id) {
                return new WP_Error('uapm_plan_not_found', __('Plan not found.', 'ua-project-manager'));
            }

            $result = $wpdb->update(
                self::plans_table(),
                $record,
                array('id' => $plan_id),
                array('%d', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%d'),
                array('%d')
            );

            if (false === $result) {
                return new WP_Error('uapm_database_error', __('Could not update the plan.', 'ua-project-manager'));
            }

            self::log($project_id, 'plan_updated', 'Plan updated: ' . $name);

            do_action('uapm_plan_updated', $plan_id, $record);

            return $plan_id;
        }

        $record['created_at'] = current_time('mysql');

        $result = $wpdb->insert(
            self::plans_table(),
            $record,
            array('%d', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%d', '%s')
        );

        if (!$result) {
            return new WP_Error('uapm_database_error', __('Could not create the plan.', 'ua-project-manager'));
        }

        $new_id = (int) $wpdb->insert_id;

        self::log($project_id, 'plan_created', 'Plan created: ' . $name);

        do_action('uapm_plan_created', $new_id, $record);

        return $new_id;
    }

    /**
     * Get plan by ID.
     *
     * @param int $plan_id Plan ID.
     * @return object|null
     */
    public static function get_plan($plan_id) {
        global $wpdb;

        $plan_id = absint($plan_id);

        if (!$plan_id) {
            return null;
        }

        return $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . self::plans_table() . ' WHERE id = %d',
                $plan_id
            )
        );
    }

    /**
     * Get plans for a project.
     *
     * @param int  $project_id Project ID.
     * @param bool $active_only Return active plans only.
     * @return array
     */
    public static function get_plans($project_id, $active_only = false) {
        global $wpdb;

        $project_id = absint($project_id);

        if (!$project_id) {
            return array();
        }

        $sql = 'SELECT * FROM ' . self::plans_table() . ' WHERE project_id = %d';

        if ($active_only) {
            $sql .= ' AND is_active = 1';
        }

        $sql .= ' ORDER BY id ASC';

        return $wpdb->get_results(
            $wpdb->prepare($sql, $project_id)
        );
    }

    /**
     * Delete a plan.
     *
     * Plans referenced by payments cannot be deleted.
     *
     * @param int $plan_id Plan ID.
     * @return bool|WP_Error
     */
    public static function delete_plan($plan_id) {
        global $wpdb;

        if (!self::can_manage()) {
            return new WP_Error('uapm_forbidden', __('You are not allowed to delete plans.', 'ua-project-manager'));
        }

        $plan = self::get_plan($plan_id);

        if (!$plan) {
            return new WP_Error('uapm_plan_not_found', __('Plan not found.', 'ua-project-manager'));
        }

        $payments_table = $wpdb->prefix . 'uapm_payments';

        $payment_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$payments_table} WHERE plan_id = %d",
                absint($plan_id)
            )
        );

        if ($payment_count > 0) {
            $result = $wpdb->update(
                self::plans_table(),
                array('is_active' => 0),
                array('id' => absint($plan_id)),
                array('%d'),
                array('%d')
            );

            if (false === $result) {
                return new WP_Error('uapm_database_error', __('Could not deactivate the plan.', 'ua-project-manager'));
            }

            self::log((int) $plan->project_id, 'plan_deactivated', 'Plan deactivated because payment records exist.');

            return true;
        }

        $result = $wpdb->delete(
            self::plans_table(),
            array('id' => absint($plan_id)),
            array('%d')
        );

        if (!$result) {
            return new WP_Error('uapm_database_error', __('Could not delete the plan.', 'ua-project-manager'));
        }

        self::log((int) $plan->project_id, 'plan_deleted', 'Plan deleted.');

        return true;
    }

    /**
     * Sanitize project fields.
     *
     * @param array $data Raw input.
     * @return array|WP_Error
     */
    private static function sanitize_project_data($data) {
        if (!is_array($data)) {
            return new WP_Error('uapm_invalid_data', __('Invalid project data.', 'ua-project-manager'));
        }

        $name = isset($data['name']) ? sanitize_text_field($data['name']) : '';

        if (!$name) {
            return new WP_Error('uapm_missing_name', __('Project name is required.', 'ua-project-manager'));
        }

        $types = array(
            'wordpress',
            'android',
            'windows',
            'web',
            'other',
        );

        $statuses = array(
            'active',
            'development',
            'paused',
            'archived',
        );

        $project_type = isset($data['project_type'])
            ? sanitize_key($data['project_type'])
            : 'wordpress';

        $status = isset($data['status'])
            ? sanitize_key($data['status'])
            : 'active';

        if (!in_array($project_type, $types, true)) {
            $project_type = 'other';
        }

        if (!in_array($status, $statuses, true)) {
            $status = 'active';
        }

        return array(
            'name' => $name,
            'slug' => isset($data['slug']) && $data['slug']
                ? sanitize_title($data['slug'])
                : sanitize_title($name),
            'project_key' => isset($data['project_key']) && $data['project_key']
                ? sanitize_title($data['project_key'])
                : (isset($data['slug']) && $data['slug'] ? sanitize_title($data['slug']) : sanitize_title($name)),
            'description' => isset($data['description'])
                ? sanitize_textarea_field($data['description'])
                : '',
            'project_type' => $project_type,
            'status' => $status,
            'version' => isset($data['version'])
                ? sanitize_text_field($data['version'])
                : '1.0.0',
            'author' => isset($data['author'])
                ? sanitize_text_field($data['author'])
                : '',
            'website_url' => isset($data['website_url'])
                ? esc_url_raw($data['website_url'])
                : '',
            'documentation_url' => isset($data['documentation_url'])
                ? esc_url_raw($data['documentation_url'])
                : '',
            'github_repository' => isset($data['github_repository'])
                ? sanitize_text_field($data['github_repository'])
                : '',
            'github_branch' => isset($data['github_branch'])
                ? sanitize_text_field($data['github_branch'])
                : 'main',
            'logo_url' => isset($data['logo_url'])
                ? esc_url_raw($data['logo_url'])
                : '',
        );
    }

    /**
     * Generate a unique project slug.
     *
     * @param string $slug      Desired slug.
     * @param int    $exclude_id Project ID to exclude.
     * @return string
     */
    private static function unique_slug($slug, $exclude_id = 0) {
        global $wpdb;

        $base = sanitize_title($slug);

        if (!$base) {
            $base = 'project';
        }

        $candidate = $base;
        $counter = 2;

        while (true) {
            $sql = 'SELECT id FROM ' . self::table() . ' WHERE slug = %s';
            $values = array($candidate);

            if ($exclude_id) {
                $sql .= ' AND id != %d';
                $values[] = absint($exclude_id);
            }

            $exists = $wpdb->get_var(
                $wpdb->prepare($sql, $values)
            );

            if (!$exists) {
                return $candidate;
            }

            $candidate = $base . '-' . $counter;
            $counter++;
        }
    }

    /**
     * Sanitize nested plan data.
     *
     * @param mixed $value Input value.
     * @return array
     */
    private static function sanitize_array($value) {
        if (!is_array($value)) {
            return array();
        }

        $clean = array();

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $clean[sanitize_key($key)] = self::sanitize_array($item);
            } elseif (is_scalar($item)) {
                $clean[sanitize_key($key)] = sanitize_text_field((string) $item);
            }
        }

        return $clean;
    }

    /**
     * Write an activity log entry.
     *
     * @param int    $project_id Project ID.
     * @param string $event      Event type.
     * @param string $message    Description.
     */
    private static function log($project_id, $event, $message) {
        global $wpdb;

        $table = $wpdb->prefix . 'uapm_activity_log';

        $wpdb->insert(
            $table,
            array(
                'user_id' => get_current_user_id(),
                'project_id' => absint($project_id),
                'event_type' => sanitize_key($event),
                'description' => sanitize_text_field($message),
                'context' => null,
                'created_at' => current_time('mysql'),
            ),
            array('%d', '%d', '%s', '%s', '%s', '%s')
        );
    }

    /**
     * Verify admin request nonce.
     *
     * @param string $action Nonce action.
     */
    private static function verify_request($action) {
        if (!isset($_REQUEST['_wpnonce'])) {
            wp_die(esc_html__('Security verification failed.', 'ua-project-manager'));
        }

        check_admin_referer($action);
    }

    /**
     * Handle project create/update form.
     */
    public static function handle_save_project() {
        if (!self::can_manage()) {
            wp_die(esc_html__('Access denied.', 'ua-project-manager'));
        }

        self::verify_request('uapm_save_project');

        $data = isset($_POST['project']) && is_array($_POST['project'])
            ? wp_unslash($_POST['project'])
            : array();

        $project_id = isset($_POST['project_id'])
            ? absint($_POST['project_id'])
            : 0;

        $result = $project_id
            ? self::update($project_id, $data)
            : self::create($data);

        if (is_wp_error($result)) {
            self::redirect_with_notice('error', $result->get_error_message());
        }

        self::redirect_with_notice('success', __('Project saved successfully.', 'ua-project-manager'));
    }

    /**
     * Handle project archive request.
     */
    public static function handle_delete_project() {
        if (!self::can_manage()) {
            wp_die(esc_html__('Access denied.', 'ua-project-manager'));
        }

        self::verify_request('uapm_delete_project');

        $project_id = isset($_GET['project_id'])
            ? absint($_GET['project_id'])
            : 0;

        $result = self::delete($project_id);

        if (is_wp_error($result)) {
            self::redirect_with_notice('error', $result->get_error_message());
        }

        self::redirect_with_notice('success', __('Project archived successfully.', 'ua-project-manager'));
    }

    /**
     * Handle plan create/update form.
     */
    public static function handle_save_plan() {
        if (!self::can_manage()) {
            wp_die(esc_html__('Access denied.', 'ua-project-manager'));
        }

        self::verify_request('uapm_save_plan');

        $data = isset($_POST['plan']) && is_array($_POST['plan'])
            ? wp_unslash($_POST['plan'])
            : array();

        if (isset($_POST['plan_id'])) {
            $data['id'] = absint($_POST['plan_id']);
        }

        $result = self::save_plan($data);

        if (is_wp_error($result)) {
            self::redirect_with_notice('error', $result->get_error_message());
        }

        self::redirect_with_notice('success', __('Plan saved successfully.', 'ua-project-manager'));
    }

    /**
     * Handle plan deletion request.
     */
    public static function handle_delete_plan() {
        if (!self::can_manage()) {
            wp_die(esc_html__('Access denied.', 'ua-project-manager'));
        }

        self::verify_request('uapm_delete_plan');

        $plan_id = isset($_GET['plan_id'])
            ? absint($_GET['plan_id'])
            : 0;

        $result = self::delete_plan($plan_id);

        if (is_wp_error($result)) {
            self::redirect_with_notice('error', $result->get_error_message());
        }

        self::redirect_with_notice('success', __('Plan deleted or deactivated successfully.', 'ua-project-manager'));
    }

    /**
     * Redirect to project admin page with a notice.
     *
     * @param string $type    Notice type.
     * @param string $message Notice message.
     */
    private static function redirect_with_notice($type, $message) {
        $url = add_query_arg(
            array(
                'page' => 'uapm',
                'uapm_notice' => sanitize_key($type),
                'uapm_message' => rawurlencode($message),
            ),
            admin_url('admin.php')
        );

        wp_safe_redirect($url);
        exit;
    }
}
