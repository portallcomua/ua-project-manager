<?php
/**
 * Backward-compatible public API for plugins that previously used standalone UA Pay Box.
 * The implementation lives in the namespaced embedded module.
 */
if (!defined('ABSPATH')) {
    exit;
}

// Preserve the public constants exposed by the former standalone plugin.
if (!defined('UPB_VERSION')) define('UPB_VERSION', '1.3.10');
if (!defined('UPB_GITHUB')) define('UPB_GITHUB', 'portallcomua/UA-Pay-Box');
if (!defined('UPB_FILE')) define('UPB_FILE', UAPM_FILE);
if (!defined('UPB_DIR')) define('UPB_DIR', UAPM_PATH . 'includes/embedded/pay-box/');
if (!defined('UPB_URL')) define('UPB_URL', UAPM_URL . 'includes/embedded/pay-box/');

if (!function_exists('wcc_check_user_premium')) {
    function wcc_check_user_premium($user_id = 0, $service_id = 0) {
        return \UAPM\PayBox\wcc_check_user_premium($user_id, $service_id);
    }
}
if (!function_exists('upb_check_user_premium')) {
    function upb_check_user_premium($user_id = 0, $service_id = 0) {
        return \UAPM\PayBox\upb_check_user_premium($user_id, $service_id);
    }
}
if (!function_exists('wcc_get_user_premium_expiry')) {
    function wcc_get_user_premium_expiry($user_id = 0, $service_id = 0) {
        return \UAPM\PayBox\wcc_get_user_premium_expiry($user_id, $service_id);
    }
}
if (!function_exists('upb_enqueue_assets')) {
    function upb_enqueue_assets() {
        return \UAPM\PayBox\upb_enqueue_assets();
    }
}
if (!function_exists('upb_service_id')) {
    function upb_service_id($slug_or_id) {
        return \UAPM\PayBox\upb_service_id($slug_or_id);
    }
}
if (!function_exists('upb_register_service')) {
    function upb_register_service($slug, $name, array $extra = array()) {
        return \UAPM\PayBox\upb_register_service($slug, $name, $extra);
    }
}
if (!function_exists('upb_get_service')) {
    function upb_get_service($slug_or_id) {
        return \UAPM\PayBox\upb_get_service($slug_or_id);
    }
}
if (!function_exists('upb_buy_button')) {
    function upb_buy_button($service_id, $label = 'Купити PRO', $class = '') {
        return \UAPM\PayBox\upb_buy_button($service_id, $label, $class);
    }
}
