<?php
if (!defined('ABSPATH')) {
    exit;
}

class UAPM_REST_API {

    const NAMESPACE = 'uapm/v1';

    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    public static function register_routes() {

        register_rest_route(self::NAMESPACE, '/status', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'status'),
            'permission_callback' => '__return_true'
        ));

        register_rest_route(self::NAMESPACE, '/projects', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'projects'),
            'permission_callback' => '__return_true'
        ));

        register_rest_route(self::NAMESPACE, '/projects/(?P<slug>[a-zA-Z0-9_-]+)', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'project'),
            'permission_callback' => '__return_true',
            'args' => array(
                'slug' => array(
                    'required' => true,
                    'sanitize_callback' => 'sanitize_title'
                )
            )
        ));

        register_rest_route(self::NAMESPACE, '/activation', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'activation_status'),
            'permission_callback' => '__return_true',
            'args' => array(
                'target' => array(
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'type' => array(
                    'required' => true,
                    'sanitize_callback' => 'sanitize_key'
                )
            )
        ));
    }

    public static function status() {
        return rest_ensure_response(array(
            'success' => true,
            'name' => 'UA Project Manager',
            'version' => defined('UAPM_VERSION') ? UAPM_VERSION : '',
            'api_version' => '1.0',
            'namespace' => self::NAMESPACE,
            'timestamp' => current_time('mysql')
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
            'order' => 'ASC'
        ));

        $result = array();

        foreach ((array) $items as $item) {
            $result[] = self::public_project_data($item);
        }

        return rest_ensure_response(array(
            'success' => true,
            'count' => count($result),
            'projects' => $result
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
            'project' => self::public_project_data($project)
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
                : ''
        );
    }

    public static function activation_status($request) {
        if (!class_exists('UAPM_Payments')) {
            return new WP_Error(
                'uapm_payments_unavailable',
                'Модуль платежів недоступний.',
                array('status' => 503)
            );
        }

        $target = trim((string) $request->get_param('target'));
        $type = sanitize_key($request->get_param('type'));

        if (!in_array($type, array('domain', 'email'), true)) {
            return new WP_Error(
                'uapm_invalid_activation_type',
                'Тип активації має бути domain або email.',
                array('status' => 400)
            );
        }

        if ($type === 'email' && !is_email($target)) {
            return new WP_Error(
                'uapm_invalid_email',
                'Вкажіть коректну електронну адресу.',
                array('status' => 400)
            );
        }

        if ($type === 'domain') {
            $host = wp_parse_url(
                strpos($target, '://') === false ? 'https://' . $target : $target,
                PHP_URL_HOST
            );

            if (!$host || !preg_match('/^[a-zA-Z0-9.-]+$/', $host)) {
                return new WP_Error(
                    'uapm_invalid_domain',
                    'Вкажіть коректний домен.',
                    array('status' => 400)
                );
            }

            $target = strtolower($host);
        } else {
            $target = strtolower($target);
        }

        $rate_key = 'uapm_activation_' . md5(
            $target . '|' . $type . '|' . self::get_client_ip()
        );

        $attempts = absint(get_transient($rate_key));

        if ($attempts >= 30) {
            return new WP_Error(
                'uapm_rate_limit',
                'Забагато запитів. Спробуйте пізніше.',
                array('status' => 429)
            );
        }

        set_transient($rate_key, $attempts + 1, HOUR_IN_SECONDS);

        $status = UAPM_Payments::get_activation_status($target, $type);

        if (is_wp_error($status)) {
            return $status;
        }

        return rest_ensure_response(array(
            'success' => true,
            'target_type' => $type,
            'activation' => $status
        ));
    }

    private static function get_client_ip() {
        $ip = isset($_SERVER['REMOTE_ADDR'])
            ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
            : '';

        return preg_match('/^[0-9a-fA-F:.]+$/', $ip) ? $ip : 'unknown';
    }
}

UAPM_REST_API::init();