<?php
/**
 * UA Project Manager
 * Compatibility fix for legacy installations table.
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
            $wpdb->prepare(
                'SHOW TABLES LIKE %s',
                $wpdb->esc_like($table)
            )
        );

        if ($exists !== $table) {
            return;
        }

        $column = $wpdb->get_row(
            "SHOW COLUMNS FROM `{$table}` LIKE 'site_domain'"
        );

        if (!$column) {
            return;
        }

        $needs_update = (
            $column->Null !== 'NO' ||
            $column->Default === null ||
            (string) $column->Default !== ''
        );

        if (!$needs_update) {
            return;
        }

        $wpdb->query(
            "ALTER TABLE `{$table}`
             MODIFY `site_domain` varchar(191)
             NOT NULL DEFAULT ''"
        );
    }
}

UAPM_Installation_Compat::init();
