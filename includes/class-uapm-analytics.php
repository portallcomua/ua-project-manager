<?php
/**
 * UA Project Manager — Analytics.
 *
 * @package UA_Project_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class UAPM_Analytics {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_uapm_export_analytics', array( __CLASS__, 'export_csv' ) );
	}

	/**
	 * Get prefixed table name.
	 *
	 * @param string $name Table suffix.
	 * @return string
	 */
	private static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'uapm_' . sanitize_key( $name );
	}

	/**
	 * Check analytics permissions.
	 *
	 * @return bool
	 */
	private static function can_view() {
		return current_user_can( 'uapm_manage_projects' ) ||
			current_user_can( 'uapm_manage_payments' ) ||
			current_user_can( 'manage_options' );
	}

	/**
	 * Get date range defaults.
	 *
	 * @param string $date_from Start date.
	 * @param string $date_to   End date.
	 * @return array
	 */
	private static function normalize_dates( $date_from = '', $date_to = '' ) {
		$today = current_time( 'Y-m-d' );

		if ( ! self::valid_date( $date_from ) ) {
			$date_from = substr( $today, 0, 7 ) . '-01';
		}

		if ( ! self::valid_date( $date_to ) ) {
			$date_to = $today;
		}

		if ( $date_from > $date_to ) {
			$swap      = $date_from;
			$date_from = $date_to;
			$date_to   = $swap;
		}

		return array(
			$ date_from = $date_from . ' 00:00:00',
			$date_to . ' 23:59:59',
		);
	}

	/**
	 * Validate a YYYY-MM-DD date.
	 *
	 * @param string $date Date.
	 * @return bool
	 */
	private static function valid_date( $date ) {
		if ( ! is_string( $date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return false;
		}

		$parts = explode( '-', $date );

		return checkdate(
			(int) $parts[1],
			(int) $parts[2],
			(int) $parts[0]
		);
	}

	/**
	 * Get overview statistics.
	 *
	 * @param string $date_from Start date.
	 * @param string $date_to   End date.
	 * @return array
	 */
	public static function get_overview( $date_from = '', $date_to = '' ) {
		global $wpdb;

		$dates = self::normalize_dates( $date_from, $date_to );

		$payments      = self::table( 'payments' );
		$installations = self::table( 'installations' );
		$projects      = self::table( 'projects' );
		$customers     = self::table( 'customers' );

		$result = array(
			'projects'      => self::table_exists( $projects ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$projects}" ) : 0,
			'installations' => self::table_exists( $installations ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$installations} WHERE status = 'active'" ) : 0,
			'customers'     => self::table_exists( $customers ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$customers}" ) : 0,
			'payments'      => 0,
			'revenue'       => 0,
			'pending'       => 0,
			'partial'       => 0,
		);

		if ( ! self::table_exists( $payments ) ) {
			return $result;
		}

		$result['payments'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$payments} WHERE created_at BETWEEN %s AND %s",
				$dates[0],
				$dates[1]
			)
		);

		$result['revenue'] = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(received_amount), 0) FROM {$payments} WHERE created_at BETWEEN %s AND %s",
				$dates[0],
				$dates[1]
			)
		);

		$result['pending'] = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$payments} WHERE payment_status = 'pending'"
		);

		$result['partial'] = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$payments} WHERE payment_status = 'partial'"
		);

		return $result;
	}

	/**
	 * Get project statistics.
	 *
	 * @param int $project_id Project ID.
	 * @return array
	 */
	public static function get_project_statistics( $project_id ) {
		global $wpdb;

		$project_id = absint( $project_id );

		if ( ! $project_id ) {
			return array();
		}

		$payments      = self::table( 'payments' );
		$installations = self::table( 'installations' );
		$downloads     = self::table( 'downloads' );

		$stats = array(
			'installations'        => 0,
			'active_installations' => 0,
			'payments'             => 0,
			'revenue'              => 0,
			'downloads'            => 0,
		);

		if ( self::table_exists( $installations ) ) {
			$stats['installations'] = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$installations} WHERE project_id = %d",
					$project_id
				)
			);

			$stats['active_installations'] = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$installations} WHERE project_id = %d AND status = 'active'",
					$project_id
				)
			);
		}

		if ( self::table_exists( $payments ) ) {
			$stats['payments'] = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$payments} WHERE project_id = %d",
					$project_id
				)
			);

			$stats['revenue'] = (float) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COALESCE(SUM(received_amount), 0) FROM {$payments} WHERE project_id = %d",
					$project_id
				)
			);
		}

		if ( self::table_exists( $downloads ) ) {
			$stats['downloads'] = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$downloads} WHERE project_id = %d",
					$project_id
				)
			);
		}

		return $stats;
	}

	/**
	 * Get revenue grouped by currency.
	 *
	 * @param string $date_from Start date.
	 * @param string $date_to   End date.
	 * @return array
	 */
	public static function get_revenue_by_currency( $date_from = '', $date_to = '' ) {
		global $wpdb;

		$payments = self::table( 'payments' );

		if ( ! self::table_exists( $payments ) ) {
			return array();
		}

		$dates = self::normalize_dates( $date_from, $date_to );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT currency, SUM(received_amount) AS revenue
				FROM {$payments}
				WHERE created_at BETWEEN %s AND %s
				GROUP BY currency
				ORDER BY currency ASC",
				$dates[0],
				$dates[1]
			)
		);
	}

	/**
	 * Get monthly revenue.
	 *
	 * @param int $months Number of months.
	 * @return array
	 */
	public static function get_monthly_revenue( $months = 12 ) {
		global $wpdb;

		$payments = self::table( 'payments' );
		$months   = min( 60, max( 1, absint( $months ) ) );

		if ( ! self::table_exists( $payments ) ) {
			return array();
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE_FORMAT(created_at, '%%Y-%%m') AS month,
					currency,
					SUM(received_amount) AS revenue,
					COUNT(*) AS payment_count
				FROM {$payments}
				WHERE created_at >= DATE_SUB(CURRENT_DATE(), INTERVAL %d MONTH)
				GROUP BY month, currency
				ORDER BY month ASC",
				$months
			)
		);
	}

	/**
	 * Get top projects by received revenue.
	 *
	 * @param int $limit Number of projects.
	 * @return array
	 */
	public static function get_top_projects( $limit = 10 ) {
		global $wpdb;

		$projects = self::table( 'projects' );
		$payments = self::table( 'payments' );
		$limit    = min( 100, max( 1, absint( $limit ) ) );

		if ( ! self::table_exists( $projects ) ) {
			return array();
		}

		if ( ! self::table_exists( $payments ) ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, name, slug, 0 AS payment_count, 0 AS revenue
					FROM {$projects}
					ORDER BY name ASC
					LIMIT %d",
					$limit
				)
			);
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.id, p.name, p.slug,
					COUNT(pay.id) AS payment_count,
					COALESCE(SUM(pay.received_amount), 0) AS revenue
				FROM {$projects} p
				LEFT JOIN {$payments} pay ON pay.project_id = p.id
				GROUP BY p.id, p.name, p.slug
				ORDER BY revenue DESC
				LIMIT %d",
				$limit
			)
		);
	}

	/**
	 * Get recent activity.
	 *
	 * @param int $limit Number of records.
	 * @return array
	 */
	public static function get_recent_activity( $limit = 20 ) {
		global $wpdb;

		$table = self::table( 'activity_log' );
		$limit = min( 100, max( 1, absint( $limit ) ) );

		if ( ! self::table_exists( $table ) ) {
			return array();
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d",
				$limit
			)
		);
	}

	/**
	 * Check whether a database table exists.
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	private static function table_exists( $table ) {
		global $wpdb;

		return $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$wpdb->esc_like( $table )
			)
		) === $table;
	}

	/**
	 * Write an activity record.
	 *
	 * @param string $event       Event key.
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @param string $description Description.
	 * @param array  $meta        Additional metadata.
	 * @return void
	 */
	public static function log_activity( $event, $object_type = '', $object_id = 0, $description = '', $meta = array() ) {
		global $wpdb;

		$table = self::table( 'activity_log' );

		if ( ! self::table_exists( $table ) ) {
			return;
		}

		$wpdb->insert(
			$table,
			array(
				'user_id'     => get_current_user_id(),
				'event'       => sanitize_key( $event ),
				'object_type' => sanitize_key( $object_type ),
				'object_id'   => absint( $object_id ),
				'description' => sanitize_textarea_field( $description ),
				'meta'        => wp_json_encode( is_array( $meta ) ? $meta : array() ),
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Export analytics as CSV.
	 *
	 * @return void
	 */
	public static function export_csv() {
		if ( ! self::can_view() ) {
			wp_die(
				esc_html__( 'You do not have permission to export analytics.', 'ua-project-manager' ),
				esc_html__( 'Access denied', 'ua-project-manager' ),
				array( 'response' => 403 )
			);
		}

		check_admin_referer( 'uapm_export_analytics' );

		$date_from = isset( $_GET['date_from'] )
			? sanitize_text_field( wp_unslash( $_GET['date_from'] ) )
			: '';

		$date_to = isset( $_GET['date_to'] )
			? sanitize_text_field( wp_unslash( $_GET['date_to'] ) )
			: '';

		$data = self::get_overview( $date_from, $date_to );

		nocache_headers();

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=uapm-analytics.csv' );

		$output = fopen( 'php://output', 'w' );

		if ( false === $output ) {
			wp_die( esc_html__( 'Could not create the CSV export.', 'ua-project-manager' ) );
		}

		fprintf( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

		fputcsv( $output, array( __( 'Metric', 'ua-project-manager' ), __( 'Value', 'ua-project-manager' ) ) );
		fputcsv( $output, array( __( 'Projects', 'ua-project-manager' ), $data['projects'] ) );
		fputcsv( $output, array( __( 'Active installations', 'ua-project-manager' ), $data['installations'] ) );
		fputcsv( $output, array( __( 'Customers', 'ua-project-manager' ), $data['customers'] ) );
		fputcsv( $output, array( __( 'Payments in period', 'ua-project-manager' ), $data['payments'] ) );
		fputcsv( $output, array( __( 'Received revenue', 'ua-project-manager' ), $data['revenue'] ) );
		fputcsv( $output, array( __( 'Pending payments', 'ua-project-manager' ), $data['pending'] ) );
		fputcsv( $output, array( __( 'Partial payments', 'ua-project-manager' ), $data['partial'] ) );

		fclose( $output );
		exit;
	}

	/**
	 * Render analytics page.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! self::can_view() ) {
			wp_die(
				esc_html__( 'You do not have permission to view analytics.', 'ua-project-manager' ),
				esc_html__( 'Access denied', 'ua-project-manager' ),
				array( 'response' => 403 )
			);
		}

		$data       = self::get_overview();
		$currencies = self::get_revenue_by_currency();
		$top        = self::get_top_projects();

		$export_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=uapm_export_analytics' ),
			'uapm_export_analytics'
		);
		?>
		<div class="wrap uapm-wrap">
			<div class="uapm-header">
				<div class="uapm-header-content">
					<div class="uapm-header-icon">
						<span class="dashicons dashicons-chart-area"></span>
					</div>
					<div>
						<h1><?php esc_html_e( 'Analytics', 'ua-project-manager' ); ?></h1>
						<p><?php esc_html_e( 'Overview of projects, installations, customers and received payments.', 'ua-project-manager' ); ?></p>
					</div>
				</div>
				<div class="uapm-header-actions">
					<a class="button button-primary" href="<?php echo esc_url( $export_url ); ?>">
						<span class="dashicons dashicons-download"></span>
						<?php esc_html_e( 'Export CSV', 'ua-project-manager' ); ?>
					</a>
				</div>
			</div>

			<div class="uapm-grid uapm-grid-3">
				<?php
				self::render_stat( __( 'Projects', 'ua-project-manager' ), $data['projects'], 'dashicons-portfolio' );
				self::render_stat( __( 'Active installations', 'ua-project-manager' ), $data['installations'], 'dashicons-admin-plugins' );
				self::render_stat( __( 'Customers', 'ua-project-manager' ), $data['customers'], 'dashicons-groups' );
				self::render_stat( __( 'Payments', 'ua-project-manager' ), $data['payments'], 'dashicons-money-alt' );
				self::render_stat( __( 'Received revenue', 'ua-project-manager' ), number_format_i18n( $data['revenue'], 2 ), 'dashicons-chart-line' );
				self::render_stat( __( 'Pending payments', 'ua-project-manager' ), $data['pending'], 'dashicons-clock' );
				self::render_stat( __( 'Partial payments', 'ua-project-manager' ), $data['partial'], 'dashicons-editor-break' );
				?>
			</div>

			<div class="uapm-grid uapm-grid-2">
				<div class="uapm-card">
					<div class="uapm-card-header">
						<h2><?php esc_html_e( 'Revenue by currency', 'ua-project-manager' ); ?></h2>
					</div>
					<div class="uapm-card-body">
						<?php if ( ! empty( $currencies ) ) : ?>
							<table class="widefat striped uapm-table">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Currency', 'ua-project-manager' ); ?></th>
										<th><?php esc_html_e( 'Received', 'ua-project-manager' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $currencies as $currency ) : ?>
										<tr>
											<td><?php echo esc_html( $currency->currency ); ?></td>
											<td><?php echo esc_html( number_format_i18n( (float) $currency->revenue, 2 ) ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						<?php else : ?>
							<p><?php esc_html_e( 'There are no payments for this period yet.', 'ua-project-manager' ); ?></p>
						<?php endif; ?>
					</div>
				</div>

				<div class="uapm-card">
					<div class="uapm-card-header">
						<h2><?php esc_html_e( 'Top projects by received payments', 'ua-project-manager' ); ?></h2>
					</div>
					<div class="uapm-card-body">
						<?php if ( ! empty( $top ) ) : ?>
							<table class="widefat striped uapm-table">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Project', 'ua-project-manager' ); ?></th>
										<th><?php esc_html_e( 'Payments', 'ua-project-manager' ); ?></th>
										<th><?php esc_html_e( 'Received', 'ua-project-manager' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $top as $project ) : ?>
										<tr>
											<td><?php echo esc_html( $project->name ); ?></td>
											<td><?php echo esc_html( $project->payment_count ); ?></td>
											<td><?php echo esc_html( number_format_i18n( (float) $project->revenue, 2 ) ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						<?php else : ?>
							<p><?php esc_html_e( 'No project data is available yet.', 'ua-project-manager' ); ?></p>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a statistic card.
	 *
	 * @param string $label Label.
	 * @param mixed  $value Value.
	 * @param string $icon  Dashicon class.
	 * @return void
	 */
	private static function render_stat( $label, $value, $icon = 'dashicons-chart-bar' ) {
		?>
		<div class="uapm-stat-card">
			<div class="uapm-stat-icon">
				<span class="dashicons <?php echo esc_attr( $icon ); ?>"></span>
			</div>
			<div class="uapm-stat-content">
				<span><?php echo esc_html( $label ); ?></span>
				<strong><?php echo esc_html( $value ); ?></strong>
			</div>
		</div>
		<?php
	}
}
