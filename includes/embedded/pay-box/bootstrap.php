<?php
namespace UAPM\PayBox;

if (!defined('ABSPATH')) {
    exit;
}

define(__NAMESPACE__ . '\\UPB_VERSION', '1.3.10');
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
    'class-upb-admin.php',
);

foreach ($files as $file) {
    require_once __DIR__ . '/' . $file;
}

add_action('plugins_loaded', function () {
    UPB_Installer::maybe_upgrade();
    UPB_Frontend::init();
    UPB_Rest::init();
    UPB_Freemius::init();

    if (is_admin()) {
        UPB_Admin::init();
    }
}, 21);
