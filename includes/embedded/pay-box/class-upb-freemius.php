<?php
namespace UAPM\PayBox;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Freemius bridge without Freemius SDK.
 * Freemius sends signed webhooks; clients validate license keys against this Pay Box.
 */
class UPB_Freemius {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'license_cors' ), 10, 4 );
	}

	/** Allow browser extensions to call the public license-status endpoint. */
	public static function license_cors( $served, $result, $request, $server ) {
		if ( '/upb/v1/license/status' === $request->get_route() ) {
			header( 'Access-Control-Allow-Origin: *' );
			header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
			header( 'Access-Control-Allow-Headers: Content-Type' );
		}
		return $served;
	}

	public static function routes() {
		register_rest_route( 'upb/v1', '/freemius/webhook/(?P<service_id>\\d+)', array(
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => array( __CLASS__, 'webhook' ),
		) );
		register_rest_route( 'upb/v1', '/license/status', array(
			'methods'             => array( 'GET', 'POST' ),
			'permission_callback' => '__return_true',
			'callback'            => array( __CLASS__, 'license_status' ),
		) );
	}

	private static function license_table() {
		global $wpdb;
		return $wpdb->prefix . 'upb_licenses';
	}

	private static function value( $array, $path, $default = '' ) {
		$parts = explode( '.', $path );
		$node  = $array;
		foreach ( $parts as $part ) {
			if ( ! is_array( $node ) || ! array_key_exists( $part, $node ) ) {
				return $default;
			}
			$node = $node[ $part ];
		}
		return $node;
	}

	private static function timestamp( $value ) {
		if ( is_numeric( $value ) ) {
			return (int) $value;
		}
		if ( is_string( $value ) && '' !== trim( $value ) ) {
			$time = strtotime( $value );
			return $time ? $time : 0;
		}
		return 0;
	}

	/** Signed webhook endpoint. Product Secret Key is never sent to client apps. */
	public static function webhook( WP_REST_Request $request ) {
		$service_id = absint( $request['service_id'] );
		$service    = UPB_Services::get( $service_id );
		if ( ! $service || empty( $service['freemius_secret_key'] ) ) {
			return new WP_REST_Response( array( 'message' => 'Freemius integration is not configured.' ), 404 );
		}

		$raw       = $request->get_body();
		$signature = trim( (string) $request->get_header( 'x-signature' ) );
		$signature = preg_replace( '/^sha256=/i', '', $signature );
		$expected  = hash_hmac( 'sha256', $raw, $service['freemius_secret_key'] );
		if ( '' === $signature || ! hash_equals( $expected, $signature ) ) {
			return new WP_REST_Response( array( 'message' => 'Invalid webhook signature.' ), 401 );
		}

		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return new WP_REST_Response( array( 'message' => 'Invalid JSON payload.' ), 400 );
		}

		/*
		 * Freemius Event API: data may be an ID, an object, or an array.
		 * Full related entities (License, User, Payment) are supplied in
		 * the top-level "objects" map, keyed by type or entity ID.
		 */
		$event_data = isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : array();
		$objects    = isset( $data['objects'] ) && is_array( $data['objects'] ) ? $data['objects'] : array();

		$license = array();
		if ( isset( $event_data['license'] ) && is_array( $event_data['license'] ) ) {
			$license = $event_data['license'];
		} elseif ( isset( $data['license'] ) && is_array( $data['license'] ) ) {
			$license = $data['license'];
		} elseif ( isset( $objects['license'] ) && is_array( $objects['license'] ) ) {
			$license = $objects['license'];
		} elseif ( isset( $event_data['secret_key'], $event_data['id'] ) ) {
			$license = $event_data;
		} else {
			foreach ( $objects as $object ) {
				if ( is_array( $object ) && ! empty( $object['id'] ) && ! empty( $object['secret_key'] ) ) {
					$license = $object;
					break;
				}
			}
		}

		$payment = array();
		if ( isset( $event_data['payment'] ) && is_array( $event_data['payment'] ) ) {
			$payment = $event_data['payment'];
		} elseif ( isset( $objects['payment'] ) && is_array( $objects['payment'] ) ) {
			$payment = $objects['payment'];
		} else {
			foreach ( $objects as $object ) {
				if ( is_array( $object ) && isset( $object['license_id'] ) && isset( $object['plan_id'] ) ) {
					$payment = $object;
					break;
				}
			}
		}

		$user = array();
		if ( isset( $event_data['user'] ) && is_array( $event_data['user'] ) ) {
			$user = $event_data['user'];
		} elseif ( isset( $objects['user'] ) && is_array( $objects['user'] ) ) {
			$user = $objects['user'];
		} else {
			foreach ( $objects as $object ) {
				if ( is_array( $object ) && ! empty( $object['email'] ) ) {
					$user = $object;
					break;
				}
			}
		}

		$product_id = (string) self::value( $data, 'plugin_id', self::value( $license, 'plugin_id', '' ) );
		$plan_id    = (string) self::value(
			$license,
			'plan_id',
			self::value( $event_data, 'plan_id', self::value( $payment, 'plan_id', self::value( $data, 'plan_id', '' ) ) )
		);

		if ( '' !== $product_id && ! empty( $service['freemius_product_id'] ) && $product_id !== (string) $service['freemius_product_id'] ) {
			return new WP_REST_Response( array( 'message' => 'Product ID does not match this Pay Box service.' ), 403 );
		}
		if ( '' !== $plan_id && ! empty( $service['freemius_plan_id'] ) && $plan_id !== (string) $service['freemius_plan_id'] ) {
			return new WP_REST_Response( array( 'message' => 'Plan ID does not match this Pay Box service.' ), 403 );
		}

		$event = strtolower( (string) self::value( $data, 'type', self::value( $data, 'event.type', self::value( $data, 'action', $request->get_header( 'x-event' ) ) ) ) );

		$active_events = array( 'license.created', 'license.activated', 'license.updated', 'license.extended', 'license.shortened', 'license.plan.changed', 'payment.dispute.won' );
		$revoked_events = array( 'license.cancelled', 'license.canceled', 'license.deactivated', 'license.expired', 'license.deleted', 'payment.refund', 'payment.dispute.closed', 'payment.dispute.lost' );
		if ( ! in_array( $event, $active_events, true ) && ! in_array( $event, $revoked_events, true ) ) {
			return new WP_REST_Response( array( 'received' => true, 'ignored' => true, 'event' => $event ), 200 );
		}

		$license_id = (string) self::value(
			$license,
			'id',
			self::value( $event_data, 'license_id', self::value( $payment, 'license_id', self::value( $data, 'license_id', '' ) ) )
		);
		// For license.* events Freemius may put only the related entity ID in data.
		if ( '' === $license_id && isset( $data['data'] ) && is_scalar( $data['data'] ) && 0 < (int) $data['data'] && 0 === strpos( $event, 'license.' ) ) {
			$license_id = (string) absint( $data['data'] );
		}
		if ( '' === $license_id ) {
			return new WP_REST_Response( array( 'message' => 'License ID is missing from the Freemius event.' ), 400 );
		}

		$license_state = strtolower( (string) self::value( $license, 'status', '' ) );
		$revoked = in_array( $event, $revoked_events, true )
			|| ! empty( $license['is_cancelled'] )
			|| ! empty( $license['is_deleted'] )
			|| ( array_key_exists( 'is_active', $license ) && false === (bool) $license['is_active'] )
			|| in_array( $license_state, array( 'cancelled', 'canceled', 'expired', 'inactive', 'deleted', 'revoked' ), true );

		$expires_value = self::value( $license, 'expiration', self::value( $license, 'expiration_date', self::value( $license, 'expires_at', null ) ) );
		$expires = null === $expires_value ? 0 : self::timestamp( $expires_value );
		if ( $expires && $expires <= time() ) {
			$revoked = true;
		}

		$key = (string) self::value( $license, 'secret_key', self::value( $license, 'license_key', self::value( $license, 'key', '' ) ) );
		$email = sanitize_email(
			(string) self::value(
				$user,
				'email',
				self::value( $data, 'user.email', self::value( $event_data, 'email', self::value( $license, 'email', '' ) ) )
			)
		);
		$status = $revoked ? 'revoked' : 'active';

		global $wpdb;
		$table    = self::license_table();
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id, email, expires FROM {$table} WHERE service_id=%d AND license_id=%s", $service_id, $license_id ) );
		if ( '' === $email && $existing && ! empty( $existing->email ) ) {
			$email = sanitize_email( $existing->email );
		}
		if ( null === $expires_value && $existing ) {
			$expires = (int) $existing->expires;
		}
		if ( '' === $email ) {
			return new WP_REST_Response( array( 'message' => 'Purchaser email is missing from the Freemius license event.' ), 400 );
		}

		$record = array(
			'service_id' => $service_id,
			'license_id' => sanitize_text_field( $license_id ),
			'email'      => $email,
			'status'     => $status,
			'expires'    => $expires,
			'updated_at' => current_time( 'mysql', true ),
		);

		if ( '' !== $key ) {
			$record['license_hash'] = hash_hmac( 'sha256', $key, wp_salt( 'auth' ) );
		} elseif ( ! $existing ) {
			$record['license_hash'] = '';
		}

		if ( $existing ) {
			$wpdb->update( $table, $record, array( 'id' => (int) $existing->id ) );
		} else {
			$record['created_at'] = current_time( 'mysql', true );
			$wpdb->insert( $table, $record );
		}

		// Advertising placements are renewed only from a verified, active Freemius webhook.
		if ( ! $revoked && class_exists( '\\UAPM_Advertising' ) ) {
			\UAPM_Advertising::handle_freemius_license( $service_id, $email, $license_id );
		}

		return new WP_REST_Response(
			array(
				'received'       => true,
				'license_status' => $status,
				'license_id'     => sanitize_text_field( $license_id ),
				'lifetime'       => 0 === $expires,
			),
			200
		);
	}

	/** Return the newest currently valid license for a purchaser email and service. */
	public static function get_email_license( $service_id, $email ) {
		global $wpdb;
		$email = sanitize_email( (string) $email );
		if ( ! $service_id || ! is_email( $email ) ) {
			return null;
		}
		$table = self::license_table();
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT status, expires FROM {$table} WHERE service_id=%d AND LOWER(email)=LOWER(%s) AND status='active' AND (expires=0 OR expires>%d) ORDER BY updated_at DESC, id DESC LIMIT 1",
			(int) $service_id, $email, time()
		) );
	}

	public static function is_email_active( $service_id, $email ) {
		return (bool) self::get_email_license( $service_id, $email );
	}

	/**
	 * Public PRO status check.
	 * New clients authenticate by the purchaser email supplied to Freemius
	 * Checkout; legacy clients may continue to validate a license key.
	 */
	public static function license_status( WP_REST_Request $request ) {
		$service_param = sanitize_text_field( (string) $request->get_param( 'service' ) );
		$service_id    = upb_service_id( $service_param );
		if ( ! $service_id ) {
			return new WP_REST_Response( array( 'valid' => false, 'message' => 'Service is required.' ), 400 );
		}

		$email = sanitize_email( (string) $request->get_param( 'email' ) );
		global $wpdb;
		$table = self::license_table();

		if ( $email && is_email( $email ) ) {
			$row = self::get_email_license( $service_id, $email );
			$valid = (bool) $row;
			return new WP_REST_Response( array(
				'valid'   => (bool) $valid,
				'status'  => $row ? $row->status : 'not_found',
				'expires' => $row && (int) $row->expires ? (int) $row->expires : 0,
				'method'  => 'email',
			), 200 );
		}

		$key = trim( (string) $request->get_param( 'license_key' ) );
		if ( '' === $key ) {
			return new WP_REST_Response( array( 'valid' => false, 'message' => 'A valid purchaser email is required.' ), 400 );
		}
		$hash = hash_hmac( 'sha256', $key, wp_salt( 'auth' ) );
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT status, expires FROM {$table} WHERE service_id=%d AND license_hash=%s ORDER BY id DESC LIMIT 1",
			$service_id,
			$hash
		) );
		$valid = $row && 'active' === $row->status && ( ! (int) $row->expires || (int) $row->expires > time() );
		return new WP_REST_Response( array(
			'valid'   => (bool) $valid,
			'status'  => $row ? $row->status : 'not_found',
			'expires' => $row && (int) $row->expires ? (int) $row->expires : 0,
			'method'  => 'license_key',
		), 200 );
	}

	/** Build the standard hosted checkout URL from the configured product and plan. */
	public static function checkout_url( $service ) {
		if ( empty( $service['freemius_product_id'] ) || empty( $service['freemius_plan_id'] ) || empty( $service['freemius_secret_key'] ) ) {
			return '';
		}
		if ( ! empty( $service['freemius_checkout_url'] ) ) {
			return esc_url_raw( $service['freemius_checkout_url'] );
		}
		if ( ! empty( $service['freemius_product_id'] ) && ! empty( $service['freemius_plan_id'] ) ) {
			return 'https://checkout.freemius.com/plugin/' . rawurlencode( $service['freemius_product_id'] ) . '/plan/' . rawurlencode( $service['freemius_plan_id'] ) . '/';
		}
		return '';
	}

	/** Return safe cached pricing. The API Bearer Token never leaves this server. */
	public static function service_pricing( $service ) {
		$empty = array( 'currency' => '', 'usd_price' => 0, 'uah_price' => 0, 'exchange_rate' => 0, 'updated_at' => 0 );
		$cache = ! empty( $service['freemius_price_cache'] ) && is_array( $service['freemius_price_cache'] )
			? wp_parse_args( $service['freemius_price_cache'], $empty ) : $empty;
		if ( empty( $service['freemius_product_id'] ) || empty( $service['freemius_plan_id'] ) || empty( $service['freemius_api_token'] ) ) {
			return $cache;
		}
		if ( ! empty( $cache['updated_at'] ) && ( time() - (int) $cache['updated_at'] ) < DAY_IN_SECONDS ) {
			return $cache;
		}

		$url = sprintf(
			'https://api.freemius.com/v1/products/%s/plans/%s/pricing.json?count=50',
			rawurlencode( $service['freemius_product_id'] ),
			rawurlencode( $service['freemius_plan_id'] )
		);
		$response = wp_remote_get( $url, array(
			'timeout' => 15,
			'headers' => array(
				'Accept'        => 'application/json',
				'Authorization' => 'Bearer ' . trim( $service['freemius_api_token'] ),
			),
		) );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return $cache;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$rows = isset( $data['pricing'] ) && is_array( $data['pricing'] ) ? $data['pricing'] : array();
		if ( isset( $data['lifetime_price'] ) ) {
			$rows = array( $data );
		} elseif ( isset( $data['pricing']['lifetime_price'] ) ) {
			$rows = array( $data['pricing'] );
		}

		$selected = null;
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['lifetime_price'] ) ) {
				continue;
			}
			$currency = strtoupper( sanitize_text_field( isset( $row['currency'] ) ? $row['currency'] : '' ) );
			if ( 'USD' === $currency ) {
				$selected = $row;
				break;
			}
			if ( null === $selected ) {
				$selected = $row;
			}
		}
		if ( ! is_array( $selected ) ) {
			return $cache;
		}

		$amount   = (float) $selected['lifetime_price'];
		$currency = strtoupper( sanitize_text_field( isset( $selected['currency'] ) ? $selected['currency'] : '' ) );
		if ( $amount <= 0 || 'USD' !== $currency ) {
			return $cache;
		}

		$rate = 0;
		$nbu = wp_remote_get(
			'https://bank.gov.ua/NBUStatService/v1/statdirectory/exchange?valcode=USD&json',
			array( 'timeout' => 12, 'headers' => array( 'Accept' => 'application/json' ) )
		);
		if ( ! is_wp_error( $nbu ) && 200 === (int) wp_remote_retrieve_response_code( $nbu ) ) {
			$rates = json_decode( wp_remote_retrieve_body( $nbu ), true );
			if ( is_array( $rates ) && ! empty( $rates[0]['rate'] ) ) {
				$rate = (float) $rates[0]['rate'];
			}
		}
		if ( $rate <= 0 && ! empty( $cache['exchange_rate'] ) ) {
			$rate = (float) $cache['exchange_rate'];
		}
		$uah = $rate > 0 ? (int) ( ceil( ( $amount * $rate ) / 10 ) * 10 ) : (int) $cache['uah_price'];

		$new_cache = array(
			'currency'      => 'USD',
			'usd_price'     => $amount,
			'uah_price'     => $uah,
			'exchange_rate' => $rate,
			'updated_at'    => time(),
		);
		$all = UPB_Services::all();
		$id = isset( $service['id'] ) ? (int) $service['id'] : 0;
		if ( $id && isset( $all[ $id ] ) ) {
			$all[ $id ]['freemius_price_cache'] = $new_cache;
			$all[ $id ]['price'] = '$' . number_format( $amount, 2, '.', '' ) . ( $uah ? ' / ' . number_format( $uah, 0, '.', ' ' ) . ' грн' : '' );
			update_option( 'upb_services', $all, false );
		}
		return $new_cache;
	}
}
