<?php
/**
 * UA Project Manager — Downloads.
 *
 * @package UA_Project_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class UAPM_Downloads {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_endpoint' ) );
		add_filter( 'query_vars', array( __CLASS__, 'register_query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_download' ) );
	}

	public static function install_schema() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = $wpdb->prefix . 'uapm_downloads';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			project_id bigint(20) unsigned NOT NULL DEFAULT 0,
			release_id bigint(20) unsigned NOT NULL DEFAULT 0,
			asset_id bigint(20) unsigned NOT NULL DEFAULT 0,
			asset_name varchar(255) NOT NULL DEFAULT '',
			download_url text NOT NULL,
			visitor_hash varchar(64) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			event_type varchar(30) NOT NULL DEFAULT 'click',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY project_id (project_id),
			KEY release_id (release_id),
			KEY asset_id (asset_id),
			KEY event_type (event_type),
			KEY created_at (created_at)
		) {$charset};";

		dbDelta( $sql );
	}

	public static function register_endpoint() {
		add_rewrite_rule(
			'^uapm-download/([0-9]+)/([0-9]+)/?$',
			'index.php?uapm_download_release=$matches[1]&uapm_download_asset=$matches[2]',
			'top'
		);

		add_rewrite_tag( '%uapm_download_release%', '([0-9]+)' );
		add_rewrite_tag( '%uapm_download_asset%', '([0-9]+)' );
	}

	public static function register_query_vars( $vars ) {
		$vars[] = 'uapm_download_release';
		$vars[] = 'uapm_download_asset';

		return $vars;
	}

	private static function table_exists( $table ) {
		global $wpdb;

		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$wpdb->esc_like( $table )
			)
		);

		return $found === $table;
	}

	private static function get_release( $release_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'uapm_releases';

		if ( ! self::table_exists( $table ) ) {
			return false;
		}

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d LIMIT 1",
				absint( $release_id )
			),
			ARRAY_A
		);
	}

	/**
	 * Only allow HTTPS GitHub release asset URLs.
	 *
	 * @param string $url URL to validate.
	 * @return string|false
	 */
	private static function validate_download_url( $url ) {
		$url = esc_url_raw( $url, array( 'https' ) );

		if ( ! $url ) {
			return false;
		}

		$parts = wp_parse_url( $url );

		if (
			! is_array( $parts ) ||
			empty( $parts['scheme'] ) ||
			empty( $parts['host'] ) ||
			'https' !== strtolower( $parts['scheme'] ) ||
			'github.com' !== strtolower( $parts['host'] )
		) {
			return false;
		}

		$path = isset( $parts['path'] ) ? $parts['path'] : '';

		if ( ! preg_match( '#^/[^/]+/[^/]+/releases/download/[^/]+/.+$#', $path ) ) {
			return false;
		}

		return $url;
	}

	public static function get_download_source( $release_id, $asset_id ) {
		$release = self::get_release( $release_id );

		if ( ! $release || empty( $release['assets_json'] ) ) {
			return false;
		}

		$assets = json_decode( $release['assets_json'], true );

		if ( ! is_array( $assets ) ) {
			return false;
		}

		foreach ( $assets as $asset ) {
			if (
				! is_array( $asset ) ||
				! isset( $asset['id'] ) ||
				absint( $asset['id'] ) !== absint( $asset_id )
			) {
				continue;
			}

			if ( empty( $asset['download_url'] ) ) {
				return false;
			}

			$url = self::validate_download_url( $asset['download_url'] );

			if ( ! $url ) {
				return false;
			}

			return array(
				'project_id'   => absint( $release['project_id'] ),
				'release_id'   => absint( $release['id'] ),
				'asset_id'     => absint( $asset['id'] ),
				'asset_name'   => isset( $asset['name'] ) ? sanitize_file_name( $asset['name'] ) : '',
				'download_url' => $url,
			);
		}

		return false;
	}

	public static function handle_download() {
		$release_id = absint( get_query_var( 'uapm_download_release' ) );
		$asset_id   = absint( get_query_var( 'uapm_download_asset' ) );

		if ( ! $release_id || ! $asset_id ) {
			return;
		}

		$download = self::get_download_source( $release_id, $asset_id );

		if ( ! $download ) {
			wp_die(
				esc_html__( 'The download link was not found or is unavailable.', 'ua-project-manager' ),
				esc_html__( 'Download unavailable', 'ua-project-manager' ),
				array( 'response' => 404 )
			);
		}

		self::record_download(
			$download['project_id'],
			$download['release_id'],
			$download['asset_id'],
			$download['asset_name'],
			$download['download_url']
		);

		wp_redirect( $download['download_url'], 302 );
		exit;
	}

	public static function create_download_url( $release_id, $asset_id = 0 ) {
		$release_id = absint( $release_id );
		$asset_id   = absint( $asset_id );

		if ( ! $release_id ) {
			return '';
		}

		if ( ! $asset_id ) {
			$release = self::get_release( $release_id );

			if ( ! $release || empty( $release['assets_json'] ) ) {
				return '';
			}

			$assets = json_decode( $release['assets_json'], true );

			if ( ! is_array( $assets ) ) {
				return '';
			}

			foreach ( $assets as $asset ) {
				if (
					is_array( $asset ) &&
					! empty( $asset['id'] ) &&
					! empty( $asset['download_url'] ) &&
					self::validate_download_url( $asset['download_url'] )
				) {
					$asset_id = absint( $asset['id'] );
					break;
				}
			}
		}

		if ( ! $asset_id ) {
			return '';
		}

		return home_url(
			'/uapm-download/' . $release_id . '/' . $asset_id . '/'
		);
	}

	public static function record_download( $project_id, $release_id, $asset_id, $asset_name, $download_url ) {
		global $wpdb;

		$url = self::validate_download_url( $download_url );

		if ( ! $url ) {
			return false;
		}

		$table = $wpdb->prefix . 'uapm_downloads';

		if ( ! self::table_exists( $table ) ) {
			return false;
		}

		$ip = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';

		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
			: '';

		$visitor_hash = hash_hmac(
			'sha256',
			$ip . '|' . $user_agent,
			wp_salt( 'auth' )
		);

		$result = $wpdb->insert(
			$table,
			array(
				'project_id'   => absint( $project_id ),
				'release_id'   => absint( $release_id ),
				'asset_id'     => absint( $asset_id ),
				'asset_name'   => sanitize_file_name( $asset_name ),
				'download_url' => $url,
				'visitor_hash' => $visitor_hash,
				'user_id'      => get_current_user_id(),
				'event_type'   => 'click',
				'created_at'   => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		return false !== $result;
	}

	public static function get_stats( $args = array() ) {
		global $wpdb;

		$table = $wpdb->prefix . 'uapm_downloads';

		if ( ! self::table_exists( $table ) ) {
			return array();
		}

		$args = wp_parse_args(
			$args,
			array(
				'project_id' => 0,
				'release_id' => 0,
				'limit'      => 100,
				'offset'     => 0,
			)
		);

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['project_id'] ) ) {
			$where[]  = 'project_id = %d';
			$params[] = absint( $args['project_id'] );
		}

		if ( ! empty( $args['release_id'] ) ) {
			$where[]  = 'release_id = %d';
			$params[] = absint( $args['release_id'] );
		}

		$limit    = max( 1, min( 500, absint( $args['limit'] ) ) );
		$offset   = max( 0, absint( $args['offset'] ) );
		$params[] = $limit;
		$params[] = $offset;

		$sql = "SELECT * FROM {$table}
			WHERE " . implode( ' AND ', $where ) . "
			ORDER BY created_at DESC
			LIMIT %d OFFSET %d";

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
	}

	public static function get_total( $project_id = 0 ) {
		global $wpdb;

		$table = $wpdb->prefix . 'uapm_downloads';

		if ( ! self::table_exists( $table ) ) {
			return 0;
		}

		if ( $project_id ) {
			return absint(
				$wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$table} WHERE project_id = %d",
						absint( $project_id )
					)
				)
			);
		}

		return absint( $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) );
	}

	public static function get_project_totals() {
		global $wpdb;

		$table = $wpdb->prefix . 'uapm_downloads';

		if ( ! self::table_exists( $table ) ) {
			return array();
		}

		return $wpdb->get_results(
			"SELECT project_id, COUNT(*) AS downloads
			FROM {$table}
			WHERE event_type = 'click'
			GROUP BY project_id
			ORDER BY downloads DESC"
		);
	}

	public static function render_stats() {
		if ( ! current_user_can( 'uapm_manage_projects' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to view download statistics.', 'ua-project-manager' ),
				esc_html__( 'Access denied', 'ua-project-manager' ),
				array( 'response' => 403 )
			);
		}

		$project_id = isset( $_GET['project_id'] )
			? absint( $_GET['project_id'] )
			: 0;

		$stats = self::get_stats(
			array(
				'project_id' => $project_id,
				'limit'      => 500,
			)
		);
		?>
		<div class="wrap uapm-wrap">
			<div class="uapm-header">
				<div class="uapm-header-content">
					<div class="uapm-header-icon">
						<span class="dashicons dashicons-download"></span>
					</div>
					<div>
						<h1><?php esc_html_e( 'Download statistics', 'ua-project-manager' ); ?></h1>
						<p><?php esc_html_e( 'Track clicks on project release assets.', 'ua-project-manager' ); ?></p>
					</div>
				</div>
			</div>

			<div class="uapm-card">
				<div class="uapm-card-body">
					<p>
						<?php esc_html_e( 'Total download link clicks:', 'ua-project-manager' ); ?>
						<strong><?php echo esc_html( self::get_total( $project_id ) ); ?></strong>
					</p>

					<p class="description">
						<?php esc_html_e( 'This statistic counts visits to download links. It cannot confirm that a file was actually saved on a visitor’s device.', 'ua-project-manager' ); ?>
					</p>

					<table class="widefat striped uapm-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Project ID', 'ua-project-manager' ); ?></th>
								<th><?php esc_html_e( 'Release ID', 'ua-project-manager' ); ?></th>
								<th><?php esc_html_e( 'File', 'ua-project-manager' ); ?></th>
								<th><?php esc_html_e( 'Event', 'ua-project-manager' ); ?></th>
								<th><?php esc_html_e( 'Date', 'ua-project-manager' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( $stats ) : ?>
								<?php foreach ( $stats as $item ) : ?>
									<tr>
										<td><?php echo esc_html( $item->project_id ); ?></td>
										<td><?php echo esc_html( $item->release_id ); ?></td>
										<td><?php echo esc_html( $item->asset_name ); ?></td>
										<td><?php echo esc_html( $item->event_type ); ?></td>
										<td><?php echo esc_html( $item->created_at ); ?></td>
									</tr>
								<?php endforeach; ?>
							<?php else : ?>
								<tr>
									<td colspan="5"><?php esc_html_e( 'No download records yet.', 'ua-project-manager' ); ?></td>
								</tr>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
		<?php
	}
}

UAPM_Downloads::install_schema();
UAPM_Downloads::init();
