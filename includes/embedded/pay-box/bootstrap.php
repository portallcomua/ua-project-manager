<?php
namespace UAPM\PayBox;

if (!defined('ABSPATH')) {
    exit;
}

define(__NAMESPACE__ . '\\UPB_VERSION', '1.3.11');
define(__NAMESPACE__ . '\\UPB_GITHUB', 'portallcomua/UA-Pay-Box');
define(__NAMESPACE__ . '\\UPB_FILE', \UAPM_FILE);
define(__NAMESPACE__ . '\\UPB_DIR', __DIR__ . '/');
define(__NAMESPACE__ . '\\UPB_URL', \UAPM_URL . 'includes/embedded/pay-box/');

$files = array(
    'class-upb-util.php',
    'class-upb-settings.php',
    'class-upb-installer.php',
    'class-upb-services.php',
    'class-upb-premium.php',
    'class-upb-snippet.php',
    'class-upb-frontend.php',
    'class-upb-rest.php',
    'class-upb-freemius.php',
    'functions.php',
    'compat.php',
    'class-upb-admin.php',
);

foreach ($files as $file) {
    require_once __DIR__ . '/' . $file;
}

function sync_project_to_paybox($project_id, $project_data = array()) {
    if (!class_exists('UAPM_Projects')) {
        return 0;
    }

    $project = \UAPM_Projects::get(absint($project_id));
    if (!$project) {
        return 0;
    }

    $slug = sanitize_title(!empty($project->project_key) ? $project->project_key : $project->slug);
    if ($slug === '') {
        return 0;
    }

    $map = get_option('uapm_paybox_project_map', array());
    $map = is_array($map) ? $map : array();

    // Match by display name first so duplicate project records (for example, WooCommerce twice)
    // share one Pay Box service instead of creating duplicate products.
    $service = UPB_Services::by_name($project->name);

    $service_id = isset($map[$project_id]) ? absint($map[$project_id]) : 0;
    if (!$service && $service_id) {
        $service = UPB_Services::get($service_id);
        if ($service && (($service['service_type'] ?? 'license') !== 'license')) { $service = null; $service_id = 0; }
    }
    if (!$service) {
        $service = UPB_Services::by_slug($slug);
    }
    if (!$service && !empty($project->slug) && $project->slug !== $slug) {
        $service = UPB_Services::by_slug($project->slug);
    }

    if ($service && !empty($service['id'])) {
        $service_id = (int) $service['id'];
        // Keep the Pay Box record aligned with the main project while preserving its Freemius credentials and price.
        $service_data = wp_parse_args($service, UPB_Services::defaults());
        $service_data['name'] = sanitize_text_field($project->name);
        $service_data['description'] = sanitize_textarea_field((string) $project->description);
        if ($project->status === 'archived') { $service_data['enabled'] = 0; }
        UPB_Services::save($service_id, $service_data);
    } else {
        // Create one service for a genuinely new project name; repeated names reuse the same record.
        $service_id = UPB_Services::register($slug, $project->name, array(
            'description' => (string) $project->description,
            'enabled' => 0,
        ));
    }

    if (!$service_id) {
        return 0;
    }

    if ($service_id) {
        $map[(int) $project_id] = (int) $service_id;
        update_option('uapm_paybox_project_map', $map, false);
        do_action('uapm_paybox_service_synced', (int) $project_id, (int) $service_id);
    }

    return (int) $service_id;
}

function sync_existing_projects_to_paybox() {
    if (!current_user_can('manage_options') || get_option('uapm_paybox_projects_synced_v4')) {
        return;
    }
    if (!class_exists('UAPM_Projects')) {
        return;
    }

    $offset = 0;
    do {
        $projects = \UAPM_Projects::get_all(array('limit' => 200, 'offset' => $offset));
        foreach ((array) $projects as $project) {
            if (!empty($project->id)) {
                sync_project_to_paybox((int) $project->id, (array) $project);
            }
        }
        $count = count((array) $projects);
        $offset += $count;
    } while ($count === 200);

    update_option('uapm_paybox_projects_synced_v4', 1, false);
}

add_action('uapm_project_created', __NAMESPACE__ . '\\sync_project_to_paybox', 10, 2);
add_action('uapm_project_updated', __NAMESPACE__ . '\\sync_project_to_paybox', 10, 2);
add_action('uapm_project_archived', function ($project_id) {
    sync_project_to_paybox($project_id);
}, 10, 1);
add_action('admin_init', __NAMESPACE__ . '\\sync_existing_projects_to_paybox', 30);

add_action('plugins_loaded', function () {
    UPB_Installer::maybe_upgrade();
    UPB_Frontend::init();
    UPB_Rest::init();
    UPB_Freemius::init();

    if (is_admin()) {
        UPB_Admin::init();
    }
}, 21);
