<?php
namespace UAPM\PayBox;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UPB_Installer {

	const DB_VERSION = '4';

	public static function activate() {
		self::create_table();
		add_option( 'upb_services', array(), '', 'no' );
		add_option( 'upb_next_id', 1, '', 'no' );
		add_option( 'upb_settings', UPB_Settings::defaults(), '', 'no' );
		update_option( 'upb_db_version', self::DB_VERSION );
	}

	public static function deactivate() {
		// Remove the scheduled job from the retired manual-payment system.
		wp_clear_scheduled_hook( 'upb_daily_check' );
	}

	public static function maybe_upgrade() {
		// Unified migration is deliberately non-destructive: preserve all legacy tables, options and user metadata.
		add_option('upb_services', array(), '', 'no');
		add_option('upb_next_id', 1, '', 'no');
		add_option('upb_settings', UPB_Settings::defaults(), '', 'no');
		if ((string) get_option('upb_db_version') === self::DB_VERSION) return;
		self::create_table();
		update_option('upb_db_version', self::DB_VERSION);
	}

	/** Create only the Freemius license table. Legacy payment tables are no longer used. */
	public static function create_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$licenses = $wpdb->prefix . 'upb_licenses';
		$sql = "CREATE TABLE {$licenses} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			service_id BIGINT(20) UNSIGNED NOT NULL,
			license_id VARCHAR(191) NOT NULL,
			license_hash CHAR(64) NOT NULL DEFAULT '',
			email VARCHAR(191) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			expires BIGINT(20) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY service_license (service_id,license_id),
			KEY license_hash (license_hash),
			KEY status (status),
			KEY service_email (service_id,email)
		) {$charset};";
		dbDelta( $sql );
	}
}
