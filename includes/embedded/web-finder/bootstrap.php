<?php
namespace UAPM\WebFinder;

if (!defined('ABSPATH')) {
    exit;
}

// Keep the legacy public constants available to older add-ons.
if (!defined('CMSF_VERSION')) define('CMSF_VERSION', '2.6.2');
if (!defined('CMSF_DIR')) define('CMSF_DIR', __DIR__ . '/');
if (!defined('CMSF_URL')) define('CMSF_URL', \UAPM_URL . 'includes/embedded/web-finder/');

define(__NAMESPACE__ . '\\CMSF_VERSION', '2.6.2');
define(__NAMESPACE__ . '\\CMSF_DIR', __DIR__ . '/');
define(__NAMESPACE__ . '\\CMSF_URL', \UAPM_URL . 'includes/embedded/web-finder/');

/**
 * Єдина перевірка доступу для вбудованого Web Finder.
 * Вона відповідає capability головного меню UA Project Manager.
 */
if (!function_exists(__NAMESPACE__ . '\\\\cmsf_can_manage')) {
    function cmsf_can_manage() {
        return current_user_can('uapm_manage_projects') || current_user_can('manage_options');
    }
}

$files = array(
    'db.php',
    'detector.php',
    'discover.php',
    'smtp.php',
    'mailer.php',
    'ajax.php',
    'admin.php',
    'pro.php',
    'i18n.php',
    'contacts.php',
);

foreach ($files as $file) {
    require_once __DIR__ . '/' . $file;
}

function cmsf_contacts_tables_exist() {
    global $wpdb;

    foreach (array('lists', 'contacts', 'blacklist', 'campaigns', 'recipients') as $name) {
        $table = $wpdb->prefix . 'cmsfc_' . $name;
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        if ($found !== $table) {
            return false;
        }
    }

    return true;
}

function cmsf_contacts_maybe_install() {
    if (!function_exists(__NAMESPACE__ . '\\cmsf_contacts_install')) {
        return;
    }

    $installed_version = get_option('cmsf_contacts_db_version', '');
    if ($installed_version !== '1.2' || !cmsf_contacts_tables_exist()) {
        cmsf_contacts_install();
        if (cmsf_contacts_tables_exist()) {
            update_option('cmsf_contacts_db_version', '1.2', false);
        }
    }
}

add_action('plugins_loaded', function () {
    cmsf_trial_started_at();

    // dbDelta updates the existing cmsf_* tables in place; it does not replace their rows.
    if (get_option('cmsf_db_version') !== CMSF_VERSION) {
        cmsf_install();
    }

    cmsf_contacts_maybe_install();
}, 21);
