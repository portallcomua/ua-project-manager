<?php
/**
 * UA Project Manager
 * Secure public and integration REST API.
 *
 * @package UA_Project_Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

final class UAPM_REST_API {

    const NAMESPACE = 'uapm/v1';

    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    public static function register_routes() {

        register_rest_route(self::NAMESPACE, '/status', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'status'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route(self::NAMESPACE, '/projects', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'projects'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route(self::NAMESPACE, '/projects/(?P<slug>[a-zA-Z0-9_-]+)', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'project'),
            'permission_callback' => '__return_true',
            'args' => array(
                'slug' => array(
                    'required' => true,
                    'sanitize_callback' => 'sanitize_title',
                ),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/activation', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'activation_status'),
            'permission_callback' => '__return_true',
            'args' => array(
                'project_id' => array(
                    'required' => true,
                    'sanitize_callback' => 'absint',
                ),
                'target' => array(
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'type' => array(
                    'required' => true,
                    'sanitize_callback' => 'sanitize_key',
                ),
            ),
        ));
    }

    public static function status() {
        return rest_ensure_response(array(
            'success' => true,
            'name' => 'UA Project Manager',
            'version' => defined('UAPM_VERSION') ? UAPM_VERSION : '',
            'api_version' => '1.0',
            'namespace' => self::NAMESPACE,
            'timestamp' => current_time('mysql'),
        ));
    }

    public static function projects($request) {
        if (!class_exists('UAPM_Projects')) {
            return new WP_Error(
                'uapm_projects_unavailable',
                'Модуль проєктів недоступний.',
                array('status' => 503)
            );
        }

        $items = UAPM_Projects::get_all(array(
            'status' => 'active',
            'limit' => 100,
            'offset' => 0,
            'orderby' => 'name',
            'order' => 'ASC',
        ));

        $result = array();

        foreach ((array) $items as $item) {
            $result[] = self::public_project_data($item);
        }

        return rest_ensure_response(array(
            'success' => true,
            'count' => count($result),
            'projects' => $result,
        ));
    }

    public static function project($request) {
        if (!class_exists('UAPM_Projects')) {
            return new WP_Error(
                'uapm_projects_unavailable',
                'Модуль проєктів недоступний.',
                array('status' => 503)
            );
        }

        $slug = sanitize_title($request->get_param('slug'));
        $project = UAPM_Projects::get_by_slug($slug);

        if (!$project || $project->status !== 'active') {
            return new WP_Error(
                'uapm_project_not_found',
                'Активний проєкт не знайдено.',
                array('status' => 404)
            );
        }

        return rest_ensure_response(array(
            'success' => true,
            'project' => self::public_project_data($project),
        ));
    }

    private static function public_project_data($project) {
        return array(
            'id' => absint($project->id),
            'name' => sanitize_text_field($project->name),
            'slug' => sanitize_title($project->slug),
            'description' => isset($project->description)
                ? sanitize_textarea_field($project->description)
                : '',
            'type' => isset($project->project_type)
                ? sanitize_key($project->project_type)
                : '',
            'version' => isset($project->version)
                ? sanitize_text_field($project->version)
                : '',
            'status' => sanitize_key($project->status),
            'author' => isset($project->author)
                ? sanitize_text_field($project->author)
                : '',
            'website' => isset($project->website)
                ? esc_url_raw($project->website)
                : '',
            'documentation_url' => isset($project->documentation_url)
                ? esc_url_raw($project->documentation_url)
                : '',
        );
    }

    /**
     * Check activation state for an authorized integration.
     *
     * Required header:
     * X-UAPM-Token: integration access token
     *
     * Required parameters:
     * project_id, target, type
     */
    public static function activation_status($request) {

        if (!class_exists('UAPM_Integrations')) {
            return new WP_Error(
                'uapm_integrations_unavailable',
                'Модуль інтеграцій недоступний.',
                array('status' => 503)
            );
        }

        if (!class_exists('UAPM_Payments')) {
            return new WP_Error(
                'uapm_payments_unavailable',
                'Модуль платежів недоступний.',
                array('status' => 503)
            );
        }

        /*
         * Перевіряємо токен інтеграції.
         * Без нього перевірка PRO недоступна.
         */
        $integration = UAPM_Integrations::authenticate($request);

        if (is_wp_error($integration)) {
            return $integration;
        }

        $project_id = absint($request->get_param('project_id'));
        $target = trim((string) $request->get_param('target'));
        $type = sanitize_key($request->get_param('type'));

        if (!$project_id) {
            return new WP_Error(
                'uapm_invalid_project_id',
                'Не вказано ID проєкту.',
                array('status' => 400)
            );
        }

        if (!$target) {
            return new WP_Error(
                'uapm_missing_target',
                'Не вказано домен або email.',
                array('status' => 400)
            );
        }

        if (!in_array($type, array('domain', 'email'), true)) {
            return new WP_Error(
                'uapm_invalid_activation_type',
                'Тип активації має бути domain або email.',
                array('status' => 400)
            );
        }

        /*
         * Перевіряємо існування проєкту.
         */
        if (!class_exists('UAPM_Projects')) {
            return new WP_Error(
                'uapm_projects_unavailable',
                'Модуль проєктів недоступний.',
                array('status' => 503)
            );
        }

        $project = UAPM_Projects::get($project_id);

        if (!$project || $project->status !== 'active') {
            return new WP_Error(
                'uapm_project_not_found',
                'Активний проєкт не знайдено.',
                array('status' => 404)
            );
        }

        /*
         * Нормалізація цілі активації.
         */
        if ($type === 'email') {
            $target = sanitize_email($target);

            if (!is_email($target)) {
                return new WP_Error(
                    'uapm_invalid_email',
                    'Вкажіть коректну електронну адресу.',
                    array('status' => 400)
                );
            }

            $target = strtolower($target);
        }

        if ($type === 'domain') {
            $target = self::normalize_domain($target);

            if (!$target) {
                return new WP_Error(
                    'uapm_invalid_domain',
                    'Вкажіть коректний домен.',
                    array('status' => 400)
                );
            }
        }

        /*
         * Важливо: Payments очікує три аргументи:
         * project_id, target, type.
         */
        $result = UAPM_Payments::get_activation_status(
            $project_id,
            $target,
            $type
        );

        if (is_wp_error($result)) {
            return $result;
        }

        /*
         * Не повертаємо назовні суму платежу,
         * отримані кошти, номер платежу або інші
         * фінансові дані клієнта.
         */
        return rest_ensure_response(array(
            'success' => true,
            'project_id' => $project_id,
            'project_slug' => sanitize_title($project->slug),
            'target_type' => $type,
            'activation' => array(
                'active' => !empty($result['active']),
                'confirmed' => !empty($result['confirmed']),
                'status' => isset($result['status'])
                    ? sanitize_key($result['status'])
                    : 'unknown',
            ),
            'checked_at' => current_time('mysql'),
        ));
    }

    private static function normalize_domain($value) {
        $value = trim((string) $value);

        if (!$value) {
            return '';
        }

        if (strpos($value, '://') === false) {
            $value = 'https://' . $value;
        }

        $host = wp_parse_url($value, PHP_URL_HOST);

        if (!$host) {
            return '';
        }

        $host = strtolower(trim($host, '.'));

        if (!preg_match(
            '/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(\.([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/',
            $host
        )) {
            return '';
        }

        return $host;
    }
}

UAPM_REST_API::init();
