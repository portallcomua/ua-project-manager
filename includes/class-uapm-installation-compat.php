<?php
/**
 * UA Project Manager — legacy installations schema compatibility.
 *
 * @package UA_Project_Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

final class UAPM_Installation_Compat {

    public static function init() {
        add_action('init', array(__CLASS__, 'fix_legacy_schema'), 1);
    }

    public static function fix_legacy_schema() {
        global $wpdb;

        $table = $wpdb->prefix . 'uapm_installations';

        $exists = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))
        );

        if ($exists !== $table) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        UAPM_Installations::install_schema();

        $columns = $wpdb->get_col("SHOW COLUMNS FROM `{$table}`", 0);

        $legacy_map = array(
            'installation_status' => 'status',
            'installation_token' => 'install_token_hash',
            'product_version' => 'plugin_version',
        );

        foreach ($legacy_map as $old => $new) {
            if (in_array($old, $columns, true) && in_array($new, $columns, true)) {
                $wpdb->query(
                    "UPDATE `{$table}` SET `{$new}` = `{$old}` WHERE (`{$new}` IS NULL OR `{$new}` = '') AND `{$old}` IS NOT NULL"
                );
            }
        }
    }
}

UAPM_Installation_Compat::init();
