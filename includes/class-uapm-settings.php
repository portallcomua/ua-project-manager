<?php
/**
 * UA Project Manager — Settings.
 *
 * @package UA_Project_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class UAPM_Settings {

	/**
	 * Settings option name.
	 */
	const OPTION_NAME = 'uapm_settings';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_uapm_save_settings', array( __CLASS__, 'save' ) );
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'currency'               => 'UAH',
			'language'               => 'auto',
			'minimum_payout'         => 100,
			'default_commission'     => 0,
			'github_enabled'         => 0,
			'github_token'           => '',
			'github_username'        => 'portallcomua',
			'privat_envelope_url'    => '',
			'notifications_enabled'  => 1,
			'privacy_mode'           => 1,
			'installation_reporting' => 1,
			'mailing_enabled'        => 0,
			'items_per_page'         => 25,
		);
	}

	/**
	 * Get all settings.
	 *
	 * @return array
	 */
	public static function get_all() {
		$saved = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		return wp_parse_args( $saved, self::defaults() );
	}

	/**
	 * Get one setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$settings = self::get_all();

		if ( array_key_exists( $key, $settings ) ) {
			return $settings[ $key ];
		}

		return $default;
	}

	/**
	 * Save settings.
	 *
	 * @return void
	 */
	public static function save() {
		if ( ! current_user_can( 'uapm_manage_settings' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to manage these settings.', 'ua-project-manager' ),
				esc_html__( 'Access denied', 'ua-project-manager' ),
				array( 'response' => 403 )
			);
		}

		check_admin_referer( 'uapm_save_settings', 'uapm_settings_nonce' );

		$settings = self::get_all();

		$currency = isset( $_POST['currency'] )
			? sanitize_text_field( wp_unslash( $_POST['currency'] ) )
			: 'UAH';

		$allowed_currencies = array( 'UAH', 'USD', 'EUR', 'GBP' );

		$settings['currency'] = in_array( $currency, $allowed_currencies, true )
			? $currency
			: 'UAH';

		$language = isset( $_POST['language'] )
			? sanitize_text_field( wp_unslash( $_POST['language'] ) )
			: 'auto';

		$allowed_languages = array( 'auto', 'uk', 'en' );

		$settings['language'] = in_array( $language, $allowed_languages, true )
			? $language
			: 'auto';

		$settings['minimum_payout'] = isset( $_POST['minimum_payout'] )
			? max( 0, (float) wp_unslash( $_POST['minimum_payout'] ) )
			: 100;

		$settings['default_commission'] = isset( $_POST['default_commission'] )
			? min( 100, max( 0, (float) wp_unslash( $_POST['default_commission'] ) ) )
			: 0;

		$settings['items_per_page'] = isset( $_POST['items_per_page'] )
			? min( 200, max( 5, absint( wp_unslash( $_POST['items_per_page'] ) ) ) )
			: 25;

		$settings['github_enabled'] = isset( $_POST['github_enabled'] ) ? 1 : 0;
		$settings['notifications_enabled'] = isset( $_POST['notifications_enabled'] ) ? 1 : 0;
		$settings['privacy_mode'] = isset( $_POST['privacy_mode'] ) ? 1 : 0;
		$settings['installation_reporting'] = isset( $_POST['installation_reporting'] ) ? 1 : 0;
		$settings['mailing_enabled'] = isset( $_POST['mailing_enabled'] ) ? 1 : 0;

		$settings['github_username'] = isset( $_POST['github_username'] )
			? sanitize_text_field( wp_unslash( $_POST['github_username'] ) )
			: 'portallcomua';

		$settings['privat_envelope_url'] = isset( $_POST['privat_envelope_url'] )
			? esc_url_raw( wp_unslash( $_POST['privat_envelope_url'] ) )
			: '';

		/*
		 * A blank token field must never erase an existing token.
		 */
		if ( isset( $_POST['github_token'] ) ) {
			$new_token = trim( (string) wp_unslash( $_POST['github_token'] ) );

			if ( '' !== $new_token ) {
				$settings['github_token'] = sanitize_text_field( $new_token );
			}
		}

		update_option( self::OPTION_NAME, $settings, false );

		$redirect = add_query_arg(
			array(
				'page'       => 'uapm-settings',
				'uapm_saved' => '1',
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Render settings page.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'uapm_manage_settings' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to view these settings.', 'ua-project-manager' ),
				esc_html__( 'Access denied', 'ua-project-manager' ),
				array( 'response' => 403 )
			);
		}

		$settings = self::get_all();
		?>
		<div class="wrap uapm-wrap">
			<div class="uapm-header">
				<div class="uapm-header-content">
					<div class="uapm-header-icon">
						<span class="dashicons dashicons-admin-settings"></span>
					</div>
					<div>
						<h1><?php esc_html_e( 'Settings', 'ua-project-manager' ); ?></h1>
						<p><?php esc_html_e( 'Configure the main parameters of UA Project Manager.', 'ua-project-manager' ); ?></p>
					</div>
				</div>
			</div>

			<?php if ( isset( $_GET['uapm_saved'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['uapm_saved'] ) ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Settings saved successfully.', 'ua-project-manager' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="uapm-settings-form">
				<input type="hidden" name="action" value="uapm_save_settings">
				<?php wp_nonce_field( 'uapm_save_settings', 'uapm_settings_nonce' ); ?>

				<div class="uapm-grid uapm-grid-2">

					<div class="uapm-card">
						<div class="uapm-card-header">
							<h2>
								<span class="dashicons dashicons-admin-generic"></span>
								<?php esc_html_e( 'General settings', 'ua-project-manager' ); ?>
							</h2>
						</div>

						<div class="uapm-card-body">
							<div class="uapm-form-row">
								<label for="uapm-currency"><?php esc_html_e( 'Main currency', 'ua-project-manager' ); ?></label>
								<select id="uapm-currency" name="currency" class="regular-text">
									<option value="UAH" <?php selected( $settings['currency'], 'UAH' ); ?>>
										<?php esc_html_e( 'Ukrainian Hryvnia (UAH)', 'ua-project-manager' ); ?>
									</option>
									<option value="USD" <?php selected( $settings['currency'], 'USD' ); ?>>
										<?php esc_html_e( 'US Dollar (USD)', 'ua-project-manager' ); ?>
									</option>
									<option value="EUR" <?php selected( $settings['currency'], 'EUR' ); ?>>
										<?php esc_html_e( 'Euro (EUR)', 'ua-project-manager' ); ?>
									</option>
									<option value="GBP" <?php selected( $settings['currency'], 'GBP' ); ?>>
										<?php esc_html_e( 'Pound Sterling (GBP)', 'ua-project-manager' ); ?>
									</option>
								</select>
							</div>

							<div class="uapm-form-row">
								<label for="uapm-language"><?php esc_html_e( 'Interface language', 'ua-project-manager' ); ?></label>
								<select id="uapm-language" name="language" class="regular-text">
									<option value="auto" <?php selected( $settings['language'], 'auto' ); ?>>
										<?php esc_html_e( 'Automatically follow WordPress language', 'ua-project-manager' ); ?>
									</option>
									<option value="uk" <?php selected( $settings['language'], 'uk' ); ?>>
										<?php esc_html_e( 'Ukrainian', 'ua-project-manager' ); ?>
									</option>
									<option value="en" <?php selected( $settings['language'], 'en' ); ?>>
										<?php esc_html_e( 'English', 'ua-project-manager' ); ?>
									</option>
								</select>
								<p class="description">
									<?php esc_html_e( 'The automatic mode uses the language selected in WordPress.', 'ua-project-manager' ); ?>
								</p>
							</div>

							<div class="uapm-form-row">
								<label for="uapm-items-per-page"><?php esc_html_e( 'Items per page', 'ua-project-manager' ); ?></label>
								<input
									type="number"
									id="uapm-items-per-page"
									name="items_per_page"
									class="small-text"
									min="5"
									max="200"
									value="<?php echo esc_attr( $settings['items_per_page'] ); ?>"
								>
								<p class="description">
									<?php esc_html_e( 'Number of records displayed in tables.', 'ua-project-manager' ); ?>
								</p>
							</div>
						</div>
					</div>

					<div class="uapm-card">
						<div class="uapm-card-header">
							<h2>
								<span class="dashicons dashicons-money-alt"></span>
								<?php esc_html_e( 'Payments and commissions', 'ua-project-manager' ); ?>
							</h2>
						</div>

						<div class="uapm-card-body">
							<div class="uapm-form-row">
								<label for="uapm-minimum-payout"><?php esc_html_e( 'Minimum payout amount', 'ua-project-manager' ); ?></label>
								<input
									type="number"
									step="0.01"
									min="0"
									id="uapm-minimum-payout"
									name="minimum_payout"
									class="regular-text"
									value="<?php echo esc_attr( $settings['minimum_payout'] ); ?>"
								>
								<p class="description">
									<?php esc_html_e( 'The minimum accumulated amount required to request a payout.', 'ua-project-manager' ); ?>
								</p>
							</div>

							<div class="uapm-form-row">
								<label for="uapm-default-commission"><?php esc_html_e( 'Default manager commission (%)', 'ua-project-manager' ); ?></label>
								<input
									type="number"
									step="0.01"
									min="0"
									max="100"
									id="uapm-default-commission"
									name="default_commission"
									class="regular-text"
									value="<?php echo esc_attr( $settings['default_commission'] ); ?>"
								>
								<p class="description">
									<?php esc_html_e( 'This rate is used as the default when a commission is not specified for a manager or client.', 'ua-project-manager' ); ?>
								</p>
							</div>

							<div class="uapm-form-row">
								<label for="uapm-privat-envelope"><?php esc_html_e( 'PrivatBank Envelope URL', 'ua-project-manager' ); ?></label>
								<input
									type="url"
									id="uapm-privat-envelope"
									name="privat_envelope_url"
									class="large-text"
									value="<?php echo esc_attr( $settings['privat_envelope_url'] ); ?>"
									placeholder="https://"
								>
								<p class="description">
									<?php esc_html_e( 'The link is displayed to users as the payment destination. Payments must still be confirmed by an administrator.', 'ua-project-manager' ); ?>
								</p>
							</div>
						</div>
					</div>

					<div class="uapm-card">
						<div class="uapm-card-header">
							<h2>
								<span class="dashicons dashicons-admin-site-alt3"></span>
								<?php esc_html_e( 'GitHub integration', 'ua-project-manager' ); ?>
							</h2>
						</div>

						<div class="uapm-card-body">
							<?php self::checkbox_row( 'github_enabled', __( 'Enable GitHub integration', 'ua-project-manager' ), $settings ); ?>

							<div class="uapm-form-row">
								<label for="uapm-github-username"><?php esc_html_e( 'GitHub username or organization', 'ua-project-manager' ); ?></label>
								<input
									type="text"
									id="uapm-github-username"
									name="github_username"
									class="regular-text"
									value="<?php echo esc_attr( $settings['github_username'] ); ?>"
									autocomplete="off"
								>
							</div>

							<div class="uapm-form-row">
								<label for="uapm-github-token"><?php esc_html_e( 'GitHub personal access token', 'ua-project-manager' ); ?></label>
								<input
									type="password"
									id="uapm-github-token"
									name="github_token"
									class="large-text"
									value=""
									placeholder="<?php echo esc_attr( ! empty( $settings['github_token'] ) ? __( 'Token is saved — enter a new one to replace it', 'ua-project-manager' ) : __( 'Enter GitHub token', 'ua-project-manager' ) ); ?>"
									autocomplete="new-password"
								>
								<p class="description">
									<?php esc_html_e( 'Leave this field blank to keep the currently saved token unchanged.', 'ua-project-manager' ); ?>
								</p>
								<?php if ( ! empty( $settings['github_token'] ) ) : ?>
									<p class="uapm-status uapm-status-success">
										<span class="dashicons dashicons-yes-alt"></span>
										<?php esc_html_e( 'GitHub token is configured.', 'ua-project-manager' ); ?>
									</p>
								<?php else : ?>
									<p class="description">
										<?php esc_html_e( 'No GitHub token has been saved yet.', 'ua-project-manager' ); ?>
									</p>
								<?php endif; ?>
							</div>
						</div>
					</div>

					<div class="uapm-card">
						<div class="uapm-card-header">
							<h2>
								<span class="dashicons dashicons-shield-alt"></span>
								<?php esc_html_e( 'Security and notifications', 'ua-project-manager' ); ?>
							</h2>
						</div>

						<div class="uapm-card-body">
							<?php
							self::checkbox_row(
								'notifications_enabled',
								__( 'Enable system notifications', 'ua-project-manager' ),
								$settings
							);

							self::checkbox_row(
								'privacy_mode',
								__( 'Enable privacy mode', 'ua-project-manager' ),
								$settings
							);

							self::checkbox_row(
								'installation_reporting',
								__( 'Allow plugin installation reporting', 'ua-project-manager' ),
								$settings
							);

							self::checkbox_row(
								'mailing_enabled',
								__( 'Allow email campaigns', 'ua-project-manager' ),
								$settings
							);
							?>

							<p class="description">
								<?php esc_html_e( 'Privacy mode limits the display of sensitive information to users who have the required permissions.', 'ua-project-manager' ); ?>
							</p>
						</div>
					</div>

				</div>

				<div class="uapm-form-actions">
					<button type="submit" class="button button-primary button-large">
						<span class="dashicons dashicons-saved"></span>
						<?php esc_html_e( 'Save settings', 'ua-project-manager' ); ?>
					</button>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Render checkbox setting row.
	 *
	 * @param string $key      Setting key.
	 * @param string $label    Label.
	 * @param array  $settings Settings array.
	 * @return void
	 */
	private static function checkbox_row( $key, $label, $settings ) {
		$checked = ! empty( $settings[ $key ] );
		?>
		<div class="uapm-setting-toggle">
			<label for="uapm-setting-<?php echo esc_attr( $key ); ?>">
				<span class="uapm-setting-toggle-label">
					<strong><?php echo esc_html( $label ); ?></strong>
					<small><?php esc_html_e( 'Enabled', 'ua-project-manager' ); ?></small>
				</span>
				<input
					type="checkbox"
					id="uapm-setting-<?php echo esc_attr( $key ); ?>"
					name="<?php echo esc_attr( $key ); ?>"
					value="1"
					<?php checked( $checked ); ?>
				>
				<span class="uapm-toggle-control" aria-hidden="true"></span>
			</label>
		</div>
		<?php
	}
}

UAPM_Settings::init();
