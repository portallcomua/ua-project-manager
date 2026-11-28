<?php
/**
 * UA Project Manager - Code Generator.
 *
 * @package UA_Project_Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

final class UAPM_Code_Generator {

    const VERSION = '1.1.0';

    public static function init() {
        add_action('admin_post_uapm_generate_code', array(__CLASS__, 'handle_generate'));
    }

    public static function get_templates() {
        return array(
            'project_id' => __('Project identification', 'ua-project-manager'),
            'plugin_header' => __('WordPress plugin header', 'ua-project-manager'),
            'connector' => __('UPM API connector', 'ua-project-manager'),
            'installation_reporter' => __('Installation reporter', 'ua-project-manager'),
            'github_config' => __('GitHub updater configuration', 'ua-project-manager'),
            'complete_connector' => __('Complete UPM connector', 'ua-project-manager'),
            'freemius_paybox' => 'Безключова перевірка PRO (Pay Box + Freemius)',
            'remote_banner' => 'Зовнішній банер і «Всі проєкти» (REST)',
        );
    }

    private static function php_string($value) {
        return var_export((string) $value, true);
    }

    private static function project_data($project) {
        $slug = sanitize_title($project->slug);
        $slug = $slug ? $slug : 'project-' . absint($project->id);

        $identifier = strtoupper(
            preg_replace('/[^A-Z0-9]+/', '_', strtoupper($slug))
        );

        return array(
            'id' => absint($project->id),
            'name' => sanitize_text_field($project->name),
            'slug' => $slug,
            'identifier' => trim($identifier, '_'),
            'version' => sanitize_text_field($project->version),
            'type' => sanitize_key($project->project_type),
            'github_repository' => isset($project->github_repository)
                ? sanitize_text_field($project->github_repository)
                : '',
            'github_branch' => isset($project->github_branch)
                ? sanitize_text_field($project->github_branch)
                : 'main',
            'website_url' => isset($project->website_url)
                ? esc_url_raw($project->website_url)
                : '',
        );
    }

    public static function generate($project_id, $template) {
        $project = UAPM_Projects::get(absint($project_id));

        if (!$project) {
            return new WP_Error(
                'uapm_project_not_found',
                __('Project not found.', 'ua-project-manager')
            );
        }

        $templates = self::get_templates();

        if (!isset($templates[$template])) {
            return new WP_Error(
                'uapm_invalid_template',
                __('Unknown code template.', 'ua-project-manager')
            );
        }

        $data = self::project_data($project);

        switch ($template) {
            case 'project_id':
                return self::generate_project_id($data);

            case 'plugin_header':
                return self::generate_plugin_header($data);

            case 'connector':
                return self::generate_connector($data);

            case 'installation_reporter':
                return self::generate_installation_reporter($data);

            case 'github_config':
                return self::generate_github_config($data);

            case 'complete_connector':
                return self::generate_complete_connector($data);

            case 'freemius_paybox':
                if (class_exists('\\UAPM\\PayBox\\UPB_Snippet')) {
                    $map = get_option('uapm_paybox_project_map', array());
                    $sid = isset($map[$data['id']]) ? absint($map[$data['id']]) : 0;
                    $svc = $sid && class_exists('\\UAPM\\PayBox\\UPB_Services') ? \UAPM\PayBox\UPB_Services::get($sid) : null;
                    if (!$svc && function_exists('\\UAPM\\PayBox\\upb_get_service')) {
                        $svc = \UAPM\PayBox\upb_get_service($data['slug']);
                    }
                    if (is_array($svc)) {
                        return "<?php\n" . \UAPM\PayBox\UPB_Snippet::freemius_php($svc);
                    }
                }
                return "<?php\n// Послугу Pay Box для проєкту «" . $data['slug'] . "» ще не налаштовано.";

            case 'remote_banner':
                if (class_exists('UAPM_Advertising')) {
                    return "<?php\n" . UAPM_Advertising::project_embed_code($data['id']);
                }
                return "<?php\n// Модуль реклами недоступний.";
        }

        return new WP_Error(
            'uapm_generation_failed',
            __('Code generation failed.', 'ua-project-manager')
        );
    }

    private static function generate_project_id($data) {
        return "<?php\n" .
            "/** Generated project identification. */\n\n" .
            "if (!defined('ABSPATH')) {\n    exit;\n}\n\n" .
            "define('" . $data['identifier'] . "_PROJECT_ID', " . $data['id'] . ");\n" .
            "define('" . $data['identifier'] . "_PROJECT_SLUG', " . self::php_string($data['slug']) . ");\n" .
            "define('" . $data['identifier'] . "_PROJECT_VERSION', " . self::php_string($data['version']) . ");\n";
    }

    private static function generate_plugin_header($data) {
        $name = str_replace(array("\r", "\n"), '', $data['name']);
        $author = str_replace(array("\r", "\n"), '', get_bloginfo('name'));

        return "<?php\n" .
            "/**\n" .
            " * Plugin Name: " . $name . "\n" .
            " * Description: WordPress plugin managed through UA Project Manager.\n" .
            " * Version: " . $data['version'] . "\n" .
            " * Author: " . $author . "\n" .
            " * Text Domain: " . $data['slug'] . "\n" .
            " * Requires at least: 6.0\n" .
            " * Requires PHP: 7.4\n" .
            " */\n\n" .
            "if (!defined('ABSPATH')) {\n    exit;\n}\n";
    }

    /**
     * Shared source for the generated connector and reporter.
     *
     * The integration token is deliberately a placeholder.
     * A real token must be entered by the plugin developer.
     */
    private static function generate_complete_connector($data) {
        $prefix = strtolower($data['identifier']);
        $upper = $data['identifier'];

        $source = <<<'GENERATED'
<?php
/**
 * UA Project Manager connector.
 *
 * Project ID: {{PROJECT_ID}}
 * Project: {{PROJECT_NAME}}
 *
 * IMPORTANT:
 * Replace the integration token placeholder below with a token
 * created in UA Project Manager -> Integrations.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('{{PREFIX}}_UPM_PROJECT_ID')) {
    define('{{PREFIX}}_UPM_PROJECT_ID', {{PROJECT_ID}});
}

if (!defined('{{PREFIX}}_UPM_API_URL')) {
    define('{{PREFIX}}_UPM_API_URL', {{API_URL}});
}

/*
 * Insert the integration token generated in the UPM admin panel.
 * Do not publish this token in a public repository.
 */
if (!defined('{{PREFIX}}_UPM_INTEGRATION_TOKEN')) {
    define('{{PREFIX}}_UPM_INTEGRATION_TOKEN', 'PASTE_UPM_INTEGRATION_TOKEN_HERE');
}

if (!defined('{{PREFIX}}_UPM_PLUGIN_SLUG')) {
    define('{{PREFIX}}_UPM_PLUGIN_SLUG', {{PLUGIN_SLUG}});
}

if (!defined('{{PREFIX}}_UPM_PLUGIN_VERSION')) {
    define('{{PREFIX}}_UPM_PLUGIN_VERSION', {{PLUGIN_VERSION}});
}

function {{PREFIX}}_upm_option_name() {
    return '{{PREFIX}}_upm_installation';
}

function {{PREFIX}}_upm_consent_option() {
    return '{{PREFIX}}_upm_reporting_consent';
}

/**
 * Add an explicit opt-in checkbox to WordPress Settings -> General.
 * The default value is disabled.
 */
function {{PREFIX}}_upm_register_consent_setting() {
    register_setting(
        'general',
        {{PREFIX}}_upm_consent_option(),
        array(
            'type' => 'integer',
            'sanitize_callback' => function ($value) {
                return empty($value) ? 0 : 1;
            },
            'default' => 0,
        )
    );

    add_settings_field(
        '{{PREFIX}}_upm_consent',
        'UA Project Manager',
        '{{PREFIX}}_upm_render_consent_field',
        'general'
    );
}
add_action('admin_init', '{{PREFIX}}_upm_register_consent_setting');

function {{PREFIX}}_upm_render_consent_field() {
    $enabled = (int) get_option({{PREFIX}}_upm_consent_option(), 0);
    ?>
    <label>
        <input
            type="checkbox"
            name="<?php echo esc_attr({{PREFIX}}_upm_consent_option()); ?>"
            value="1"
            <?php checked(1, $enabled); ?>
        />
        Дозволити передачу даних про встановлення цього плагіна до UA Project Manager.
    </label>
    <p class="description">
        Передаються адреса сайту, назва сайту, версія плагіна,
        версії WordPress і PHP. Без увімкнення цієї опції дані не передаються.
    </p>
    <?php
}

/**
 * Send an authenticated request to the UPM server.
 */
function {{PREFIX}}_upm_request($endpoint, $payload = array(), $token = '') {
    $endpoint = ltrim(sanitize_text_field($endpoint), '/');

    $url = trailingslashit({{PREFIX}}_UPM_API_URL) . $endpoint;

    $headers = array(
        'Content-Type' => 'application/json',
    );

    if ($token !== '') {
        $headers['X-UAPM-Token'] = $token;
    }

    return wp_remote_post($url, array(
        'timeout' => 20,
        'redirection' => 2,
        'headers' => $headers,
        'body' => wp_json_encode($payload),
    ));
}

/**
 * Register this installation after explicit administrator consent.
 */
function {{PREFIX}}_upm_register_installation() {
    if (!(int) get_option({{PREFIX}}_upm_consent_option(), 0)) {
        return;
    }

    $integration_token = {{PREFIX}}_UPM_INTEGRATION_TOKEN;

    if (
        !$integration_token ||
        $integration_token === 'PASTE_UPM_INTEGRATION_TOKEN_HERE'
    ) {
        return;
    }

    $stored = get_option({{PREFIX}}_upm_option_name(), array());

    if (
        !empty($stored['installation_id']) &&
        !empty($stored['installation_token'])
    ) {
        return;
    }

    $response = {{PREFIX}}_upm_request(
        'installation/register',
        array(
            'project_id' => {{PREFIX}}_UPM_PROJECT_ID,
            'consent' => 1,
            'site_url' => home_url('/'),
            'site_name' => get_bloginfo('name'),
            'plugin_slug' => {{PREFIX}}_UPM_PLUGIN_SLUG,
            'plugin_version' => {{PREFIX}}_UPM_PLUGIN_VERSION,
            'wordpress_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'platform' => 'wordpress',
        ),
        $integration_token
    );

    if (is_wp_error($response)) {
        return;
    }

    $status = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if (
        $status < 200 ||
        $status >= 300 ||
        empty($body['success']) ||
        empty($body['installation_id']) ||
        empty($body['installation_token'])
    ) {
        return;
    }

    update_option(
        {{PREFIX}}_upm_option_name(),
        array(
            'installation_id' => absint($body['installation_id']),
            'installation_token' => sanitize_text_field($body['installation_token']),
            'registered_at' => time(),
        ),
        false
    );

    if (!wp_next_scheduled('{{PREFIX}}_upm_heartbeat_event')) {
        wp_schedule_event(
            time() + HOUR_IN_SECONDS,
            'twicedaily',
            '{{PREFIX}}_upm_heartbeat_event'
        );
    }
}

/**
 * Heartbeat uses the individual installation token.
 * The integration token is not sent with heartbeat requests.
 */
function {{PREFIX}}_upm_send_heartbeat() {
    if (!(int) get_option({{PREFIX}}_upm_consent_option(), 0)) {
        return;
    }

    $stored = get_option({{PREFIX}}_upm_option_name(), array());

    if (
        empty($stored['installation_id']) ||
        empty($stored['installation_token'])
    ) {
        return;
    }

    {{PREFIX}}_upm_request(
        'installation/heartbeat',
        array(
            'installation_id' => absint($stored['installation_id']),
            'installation_token' => $stored['installation_token'],
            'plugin_version' => {{PREFIX}}_UPM_PLUGIN_VERSION,
            'wordpress_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
        )
    );
}

/**
 * Stop reporting and deactivate the registered installation.
 */
function {{PREFIX}}_upm_revoke_reporting($old_value, $new_value) {
    if (!empty($new_value)) {
        return;
    }

    $stored = get_option({{PREFIX}}_upm_option_name(), array());

    if (
        !empty($stored['installation_id']) &&
        !empty($stored['installation_token'])
    ) {
        {{PREFIX}}_upm_request(
            'installation/deactivate',
            array(
                'installation_id' => absint($stored['installation_id']),
                'installation_token' => $stored['installation_token'],
            )
        );
    }

    wp_clear_scheduled_hook('{{PREFIX}}_upm_heartbeat_event');
    delete_option({{PREFIX}}_upm_option_name());
}
add_action(
    'update_option_' . {{PREFIX}}_upm_consent_option(),
    '{{PREFIX}}_upm_revoke_reporting',
    10,
    2
);

/**
 * Schedule registration only after consent has been enabled.
 */
function {{PREFIX}}_upm_maybe_schedule_registration() {
    if (!(int) get_option({{PREFIX}}_upm_consent_option(), 0)) {
        return;
    }

    $stored = get_option({{PREFIX}}_upm_option_name(), array());

    if (
        empty($stored['installation_id']) &&
        !wp_next_scheduled('{{PREFIX}}_upm_register_event')
    ) {
        wp_schedule_single_event(
            time() + 30,
            '{{PREFIX}}_upm_register_event'
        );
    }
}
add_action('init', '{{PREFIX}}_upm_maybe_schedule_registration');

add_action(
    '{{PREFIX}}_upm_register_event',
    '{{PREFIX}}_upm_register_installation'
);

add_action(
    '{{PREFIX}}_upm_heartbeat_event',
    '{{PREFIX}}_upm_send_heartbeat'
);

/**
 * Clean up scheduled events when the plugin is deactivated.
 */
function {{PREFIX}}_upm_plugin_deactivated() {
    wp_clear_scheduled_hook('{{PREFIX}}_upm_register_event');
    wp_clear_scheduled_hook('{{PREFIX}}_upm_heartbeat_event');
}
register_deactivation_hook(__FILE__, '{{PREFIX}}_upm_plugin_deactivated');

GENERATED;

        $replacements = array(
            '{{PROJECT_ID}}' => (string) $data['id'],
            '{{PROJECT_NAME}}' => $data['name'],
            '{{PREFIX}}' => $prefix,
            '{{API_URL}}' => self::php_string(home_url('/wp-json/uapm/v1/')),
            '{{PLUGIN_SLUG}}' => self::php_string($data['slug']),
            '{{PLUGIN_VERSION}}' => self::php_string($data['version']),
        );

        return strtr($source, $replacements);
    }

    private static function generate_connector($data) {
        return self::generate_complete_connector($data);
    }

    private static function generate_installation_reporter($data) {
        return self::generate_complete_connector($data);
    }

    private static function generate_github_config($data) {
        return "<?php\n" .
            "/** GitHub updater configuration. */\n\n" .
            "if (!defined('ABSPATH')) {\n    exit;\n}\n\n" .
            "define('" . $data['identifier'] . "_GITHUB_REPOSITORY', " .
                self::php_string($data['github_repository']) . ");\n" .
            "define('" . $data['identifier'] . "_GITHUB_BRANCH', " .
                self::php_string($data['github_branch']) . ");\n" .
            "define('" . $data['identifier'] . "_CURRENT_VERSION', " .
                self::php_string($data['version']) . ");\n";
    }

    public static function handle_generate() {
        if (
            !current_user_can('uapm_manage_projects') &&
            !current_user_can('manage_options')
        ) {
            wp_die(esc_html__('Access denied.', 'ua-project-manager'));
        }

        check_admin_referer('uapm_generate_code');

        $project_id = isset($_POST['project_id'])
            ? absint($_POST['project_id'])
            : 0;

        $template = isset($_POST['template'])
            ? sanitize_key(wp_unslash($_POST['template']))
            : '';

        $code = self::generate($project_id, $template);

        if (is_wp_error($code)) {
            wp_die(esc_html($code->get_error_message()));
        }

        $project = UAPM_Projects::get($project_id);

        $filename = sanitize_file_name(
            ($project ? $project->slug : 'project') . '-' . $template . '.php'
        );

        nocache_headers();

        header('Content-Type: application/octet-stream; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');

        echo $code;
        exit;
    }

    public static function render_form() {
        if (
            !current_user_can('uapm_manage_projects') &&
            !current_user_can('manage_options')
        ) {
            return;
        }

        $projects = UAPM_Projects::get_all(array(
            'limit' => 200,
            'orderby' => 'name',
            'order' => 'ASC',
        ));

        echo '<div class="uapm-form">';
        echo '<h2>' . esc_html__('Code Generator', 'ua-project-manager') . '</h2>';

        if (empty($projects)) {
            echo '<p>' . esc_html__('Create a project before generating code.', 'ua-project-manager') . '</p>';
            echo '</div>';
            return;
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="uapm_generate_code">';

        wp_nonce_field('uapm_generate_code');

        echo '<div class="uapm-field">';
        echo '<label for="uapm-generator-project">' . esc_html__('Project', 'ua-project-manager') . '</label>';
        echo '<select id="uapm-generator-project" name="project_id" required>';

        foreach ($projects as $project) {
            echo '<option value="' . esc_attr((int) $project->id) . '">' .
                esc_html($project->name . ' (' . $project->slug . ')') .
                '</option>';
        }

        echo '</select>';
        echo '</div>';

        echo '<div class="uapm-field">';
        echo '<label for="uapm-generator-template">' . esc_html__('Code template', 'ua-project-manager') . '</label>';
        echo '<select id="uapm-generator-template" name="template" required>';

        foreach (self::get_templates() as $key => $label) {
            echo '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>';
        }

        echo '</select>';
        echo '</div>';

        echo '<button type="submit" class="button button-primary">' .
            esc_html__('Generate and download PHP file', 'ua-project-manager') .
            '</button>';

        echo '</form>';
        echo '</div>';
    }
}
