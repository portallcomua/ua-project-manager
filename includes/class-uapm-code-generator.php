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

    const VERSION = '1.0.0';

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
        }

        return new WP_Error(
            'uapm_generation_failed',
            __('Code generation failed.', 'ua-project-manager')
        );
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

    private static function generate_project_id($data) {
        return "<?php\n" .
            "/**\n" .
            " * Generated project identification.\n" .
            " */\n\n" .
            "if (!defined('ABSPATH')) {\n" .
            "    exit;\n" .
            "}\n\n" .
            "define('" . $data['identifier'] . "_PROJECT_ID', " . $data['id'] . ");\n" .
            "define('" . $data['identifier'] . "_PROJECT_SLUG', '" . esc_js($data['slug']) . "');\n" .
            "define('" . $data['identifier'] . "_PROJECT_VERSION', '" . esc_js($data['version']) . "');\n";
    }

    private static function generate_plugin_header($data) {
        $name = str_replace(array("\r", "\n"), '', $data['name']);

        return "<?php\n" .
            "/**\n" .
            " * Plugin Name: " . $name . "\n" .
            " * Description: WordPress plugin managed through UA Project Manager.\n" .
            " * Version: " . $data['version'] . "\n" .
            " * Author: " . get_bloginfo('name') . "\n" .
            " * Text Domain: " . $data['slug'] . "\n" .
            " * Requires at least: 6.0\n" .
            " * Requires PHP: 7.4\n" .
            " */\n\n" .
            "if (!defined('ABSPATH')) {\n" .
            "    exit;\n" .
            "}\n";
    }

    private static function generate_connector($data) {
        $prefix = $data['identifier'];

        return "<?php\n" .
            "/**\n" .
            " * UA Project Manager API connector.\n" .
            " * Project: " . $data['name'] . "\n" .
            " */\n\n" .
            "if (!defined('ABSPATH')) {\n" .
            "    exit;\n" .
            "}\n\n" .
            "if (!defined('" . $prefix . "_UPM_PROJECT_ID')) {\n" .
            "    define('" . $prefix . "_UPM_PROJECT_ID', " . $data['id'] . ");\n" .
            "}\n\n" .
            "if (!defined('" . $prefix . "_UPM_API_URL')) {\n" .
            "    define('" . $prefix . "_UPM_API_URL', '" . esc_js(home_url('/wp-json/uapm/v1/')) . "');\n" .
            "}\n\n" .
            "function " . strtolower($prefix) . "_upm_request($endpoint, $payload = array()) {\n" .
            "    $endpoint = ltrim(sanitize_text_field($endpoint), '/');\n" .
            "    $url = trailingslashit(" . $prefix . "_UPM_API_URL) . $endpoint;\n\n" .
            "    return wp_remote_post($url, array(\n" .
            "        'timeout' => 15,\n" .
            "        'headers' => array(\n" .
            "            'Content-Type' => 'application/json',\n" .
            "        ),\n" .
            "        'body' => wp_json_encode($payload),\n" .
            "    ));\n" .
            "}\n";
    }

    private static function generate_installation_reporter($data) {
        $prefix = strtolower($data['identifier']);

        return "<?php\n" .
            "/**\n" .
            " * Optional installation reporter for UA Project Manager.\n" .
            " * Project ID: " . $data['id'] . "\n" .
            " */\n\n" .
            "if (!defined('ABSPATH')) {\n" .
            "    exit;\n" .
            "}\n\n" .
            "function " . $prefix . "_upm_report_installation() {\n" .
            "    if (!get_option('" . $prefix . "_upm_reporting_enabled', false)) {\n" .
            "        return;\n" .
            "    }\n\n" .
            "    if (!wp_next_scheduled('" . $prefix . "_upm_installation_event')) {\n" .
            "        wp_schedule_single_event(time() + 30, '" . $prefix . "_upm_installation_event');\n" .
            "    }\n" .
            "}\n" .
            "add_action('init', '" . $prefix . "_upm_report_installation');\n\n" .
            "function " . $prefix . "_upm_send_installation_event() {\n" .
            "    if (!get_option('" . $prefix . "_upm_reporting_enabled', false)) {\n" .
            "        return;\n" .
            "    }\n\n" .
            "    do_action('" . $prefix . "_upm_installation_event_ready', array(\n" .
            "        'project_id' => " . $data['id'] . ",\n" .
            "        'event' => 'installation_check',\n" .
            "        'version' => '" . esc_js($data['version']) . "',\n" .
            "        'site_url' => home_url('/'),\n" .
            "        'wordpress_version' => get_bloginfo('version'),\n" .
            "        'php_version' => PHP_VERSION,\n" .
            "    ));\n" .
            "}\n" .
            "add_action('" . $prefix . "_upm_installation_event', '" . $prefix . "_upm_send_installation_event');\n";
    }

    private static function generate_github_config($data) {
        $repository = $data['github_repository'];
        $branch = $data['github_branch'];

        return "<?php\n" .
            "/**\n" .
            " * GitHub updater configuration.\n" .
            " * Project: " . $data['name'] . "\n" .
            " */\n\n" .
            "if (!defined('ABSPATH')) {\n" .
            "    exit;\n" .
            "}\n\n" .
            "define('" . $data['identifier'] . "_GITHUB_REPOSITORY', '" . esc_js($repository) . "');\n" .
            "define('" . $data['identifier'] . "_GITHUB_BRANCH', '" . esc_js($branch) . "');\n" .
            "define('" . $data['identifier'] . "_CURRENT_VERSION', '" . esc_js($data['version']) . "');\n\n" .
            "// This file only stores updater configuration.\n" .
            "// A separate updater implementation is required to check releases and install updates.\n";
    }

    private static function generate_complete_connector($data) {
        $connector = self::generate_connector($data);
        $reporter = self::generate_installation_reporter($data);

        $reporter = preg_replace('/^<\?php\s*/', '', $reporter);

        return $connector . "\n\n" .
            "/* Installation reporting component. */\n\n" .
            $reporter;
    }

    public static function handle_generate() {
        if (!current_user_can('uapm_manage_projects') && !current_user_can('manage_options')) {
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
        if (!current_user_can('uapm_manage_projects') && !current_user_can('manage_options')) {
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