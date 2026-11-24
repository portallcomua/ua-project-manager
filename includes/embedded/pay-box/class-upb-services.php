<?php
namespace UAPM\PayBox;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Зберігання "Послуг" (товарів) в опції upb_services.
 */
class UPB_Services {

	public static function all() {
		$all = get_option( 'upb_services', array() );
		return is_array( $all ) ? $all : array();
	}

	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ (int) $id ] ) ? $all[ (int) $id ] : null;
	}

	public static function defaults() {
		return array(
			'slug'            => '',
			'name'            => '',
			'price'           => '',
			'description'     => '',
			'freemius_product_id' => '',
			'freemius_plan_id' => '',
			'freemius_checkout_url' => '',
			'freemius_secret_key' => '',
			'freemius_api_token' => '',
			'days'            => 30,
			'enabled'         => 1,
		);
	}

	/** Санітизація даних з форми. */
	public static function sanitize( $in ) {
		$in   = wp_unslash( $in );
		$days = isset( $in['days'] ) ? absint( $in['days'] ) : 0;
		if ( $days < 1 ) {
			$days = 30;
		}
		if ( $days > 9999 ) {
			$days = 9999;
		}
		return array(
			'slug'            => sanitize_title( isset( $in['slug'] ) ? $in['slug'] : '' ),
			'name'            => sanitize_text_field( isset( $in['name'] ) ? $in['name'] : '' ),
			'price'           => sanitize_text_field( isset( $in['price'] ) ? $in['price'] : '' ),
			'description'     => sanitize_textarea_field( isset( $in['description'] ) ? $in['description'] : '' ),
			'freemius_product_id' => sanitize_text_field( isset( $in['freemius_product_id'] ) ? $in['freemius_product_id'] : '' ),
			'freemius_plan_id' => sanitize_text_field( isset( $in['freemius_plan_id'] ) ? $in['freemius_plan_id'] : '' ),
			'freemius_checkout_url' => esc_url_raw( trim( isset( $in['freemius_checkout_url'] ) ? $in['freemius_checkout_url'] : '' ), array( 'http', 'https' ) ),
			'freemius_secret_key' => sanitize_text_field( isset( $in['freemius_secret_key'] ) ? $in['freemius_secret_key'] : '' ),
			'freemius_api_token' => sanitize_text_field( isset( $in['freemius_api_token'] ) ? $in['freemius_api_token'] : '' ),
			'days'            => $days,
			'enabled'         => ! empty( $in['enabled'] ) ? 1 : 0,
		);
	}

	/** Пошук проекту за slug плагіна. */
	public static function by_slug( $slug ) {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return null;
		}
		foreach ( self::all() as $svc ) {
			if ( isset( $svc['slug'] ) && $svc['slug'] === $slug ) {
				return $svc;
			}
		}
		return null;
	}

	/** Find a service by its exact normalized display name, preferring the configured Freemius record. */
	public static function by_name( $name ) {
		$normalize = function ( $value ) {
			$value = trim( preg_replace( '/\\s+/u', ' ', (string) $value ) );
			return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
		};
		$needle = $normalize( $name );
		if ( '' === $needle ) { return null; }
		$best = null;
		$best_score = -1;
		foreach ( self::all() as $service ) {
			if ( $normalize( $service['name'] ?? '' ) !== $needle ) { continue; }
			$score = 0;
			if ( ! empty( $service['freemius_product_id'] ) && ! empty( $service['freemius_plan_id'] ) && ! empty( $service['freemius_secret_key'] ) ) { $score += 2; }
			if ( ! empty( $service['freemius_api_token'] ) ) { $score++; }
			if ( null === $best || $score > $best_score || ( $score === $best_score && (int) ( $service['id'] ?? 0 ) < (int) ( $best['id'] ?? 0 ) ) ) {
				$best = $service;
				$best_score = $score;
			}
		}
		return $best;
	}

	/** Автореєстрація проекту іншим плагіном (ідемпотентно). Повертає ID. */
	public static function register( $slug, $name, array $extra = array() ) {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return 0;
		}
		$exists = self::by_slug( $slug );
		if ( $exists ) {
			return (int) $exists['id'];
		}
		$data = wp_parse_args( $extra, self::defaults() );
		$data['slug'] = $slug;
		$data['name'] = sanitize_text_field( $name );
		return self::save( 0, $data );
	}

	/** Створення / оновлення. Повертає ID. */
	public static function save( $id, array $data ) {
		$all = self::all();
		$id  = (int) $id;
		if ( $id < 1 ) {
			$id = max( 1, (int) get_option( 'upb_next_id', 1 ) );
			while ( isset( $all[ $id ] ) ) {
				$id++;
			}
			update_option( 'upb_next_id', $id + 1, false );
		}
		$data['id'] = $id;
		$all[ $id ] = $data;
		update_option( 'upb_services', $all, false );
		return $id;
	}

	public static function delete( $id ) {
		$all = self::all();
		unset( $all[ (int) $id ] );
		update_option( 'upb_services', $all, false );
	}
}
