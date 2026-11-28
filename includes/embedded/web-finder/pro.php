<?php
namespace UAPM\WebFinder;

if (!defined('ABSPATH')) exit;

/**
 * Web Finder is an integrated core module of UA Project Manager.
 * Standalone trial locks and the redundant UA Web Finder Pro submenu are disabled.
 */
function cmsf_trial_started_at() {
    $started = (int) get_option('cmsf_trial_started_at', 0);
    if (!$started) {
        $started = time();
        add_option('cmsf_trial_started_at', $started, '', false);
    }
    return $started;
}
function cmsf_trial_days_left() {
    return 0;
}
function cmsf_pro_is_active() {
    return true;
}
function cmsf_trial_is_active() {
    return true;
}
function cmsf_feature_access_allowed() {
    return true;
}
function cmsf_pro_options() {
    return [
        'checkout_url' => (string) get_option('cmsf_freemius_checkout_url', ''),
        'secret_key' => (string) get_option('cmsf_freemius_secret_key', ''),
        'plan_id' => (string) get_option('cmsf_freemius_plan_id', ''),
    ];
}
function cmsf_trial_blocks_action($action) {
    return false;
}
