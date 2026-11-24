<?php
namespace UAPM\PayBox;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST: перевірка PRO для зовнішніх клієнтів (Android, Chrome, інші сайти).
 * GET /wp-json/upb/v1/check?service=<id|slug>&username=<login>&api_key=<ключ>
 */
class UPB_Rest {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function api_key() {
		$k = get_option( 'upb_api_key' );
		if ( ! $k ) {
			$k = wp_generate_password( 32, false, false );
			update_option( 'upb_api_key', $k, false );
		}
		return $k;
	}

	public static function routes() {
		register_rest_route( 'upb/v1', '/service', array( 'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => array( __CLASS__, 'service' ) ) );
		register_rest_route( 'upb/v1', '/status', array( 'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => array( __CLASS__, 'status' ) ) );
		register_rest_route(
			'upb/v1',
			'/check',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'check' ),
			)
		);
	}

	private static function svc_by_param( WP_REST_Request $req ) {
		$slug = sanitize_text_field( (string) $req->get_param( 'service' ) );
		$sid  = upb_service_id( $slug );
		return $sid ? UPB_Services::get( $sid ) : null;
	}

	/** GET service — публічні дані проекту для вікна оплати. */
	public static function service( WP_REST_Request $req ) {
		$svc = self::svc_by_param( $req );
		if ( ! $svc || empty( $svc['enabled'] ) ) {
			return new WP_REST_Response( array( 'message' => 'Проект не знайдено на сервері Pay Box.' ), 404 );
		}
		$pricing = UPB_Freemius::service_pricing( $svc );
		$checkout = UPB_Freemius::checkout_url( $svc );
		$buyer_email = sanitize_email( (string) $req->get_param( 'email' ) );
		if ( $checkout && is_email( $buyer_email ) ) {
			$checkout = add_query_arg( array( 'user_email' => $buyer_email, 'readonly_user' => 'true' ), $checkout );
		}
		$locale = sanitize_text_field( (string) $req->get_param( 'locale' ) );
		$is_uk = 0 === strpos( strtolower( $locale ), 'uk' );
		// Freemius price has priority. Keep the editable Pay Box price as a
		// fallback for existing services if Freemius pricing is temporarily unavailable.
		$price_display = $is_uk && ! empty( $pricing['uah_price'] )
			? number_format( (float) $pricing['uah_price'], 0, '.', ' ' ) . ' грн'
			: ( ! empty( $pricing['usd_price'] )
				? chr( 36 ) . number_format( (float) $pricing['usd_price'], 2, '.', '' )
				: (string) $svc['price'] );
		return new WP_REST_Response(
			array(
				'id' => (int) $svc['id'],
				'slug' => $svc['slug'],
				'name' => $svc['name'],
				'price' => $price_display,
				'price_usd' => (float) $pricing['usd_price'],
				'price_uah' => (int) $pricing['uah_price'],
				'currency' => $pricing['currency'],
				'exchange_rate' => (float) $pricing['exchange_rate'],
				'price_updated' => (int) $pricing['updated_at'],
				'url' => $checkout,
				'checkout_url' => $checkout,
				'days' => (int) $svc['days'],
				'lifetime' => ! empty( $svc['freemius_product_id'] ) && ! empty( $svc['freemius_plan_id'] ),
			),
			200
		);
	}

	/** GET status — стан PRO домену. */
	public static function status( WP_REST_Request $req ) {
		$svc = self::svc_by_param( $req );
		$email = sanitize_email( (string) $req->get_param( 'email' ) );
		if ( ! $svc || ! is_email( $email ) ) {
			return new WP_REST_Response( array( 'state' => 'free', 'premium' => false, 'message' => 'Valid purchaser email is required.' ), 200 );
		}
		$license = UPB_Freemius::get_email_license( (int) $svc['id'], $email );
		$expires = $license ? (int) $license->expires : 0;
		$days_left = $expires ? (int) ceil( ( $expires - time() ) / DAY_IN_SECONDS ) : 0;
		return new WP_REST_Response( array(
			'state' => $license ? ( $expires ? 'active' : 'unlimited' ) : 'free',
			'premium' => (bool) $license,
			'expires' => $expires,
			'days_left' => $days_left,
			'checkout_url' => UPB_Freemius::checkout_url( $svc ),
		), 200 );
	}

	public static function check( WP_REST_Request $req ) {
		$key = (string) $req->get_param( 'api_key' );
		if ( ! $key || ! hash_equals( self::api_key(), $key ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid api_key' ), 403 );
		}
		$sid = upb_service_id( sanitize_text_field( (string) $req->get_param( 'service' ) ) );
		if ( ! $sid ) {
			return new WP_REST_Response( array( 'error' => 'service not found' ), 404 );
		}
		$user = get_user_by( 'login', sanitize_user( (string) $req->get_param( 'username' ), true ) );
		if ( ! $user ) {
			return new WP_REST_Response( array( 'error' => 'user not found' ), 404 );
		}
		$st = UPB_Premium::state( $user->ID, $sid );
		return new WP_REST_Response(
			array(
				'premium'    => in_array( $st['state'], array( 'active', 'expiring', 'unlimited' ), true ),
				'state'      => $st['state'],
				'expires_at' => $st['expires'] ? gmdate( 'c', $st['expires'] ) : null,
				'days_left'  => $st['days_left'],
			),
			200
		);
	}
}
