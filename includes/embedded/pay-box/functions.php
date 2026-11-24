<?php
namespace UAPM\PayBox;

/**
 * Публічний API для інших плагінів і тем.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( __NAMESPACE__ . '\\wcc_check_user_premium' ) ) {
	/**
	 * Чи має користувач чинний PRO по послузі.
	 *
	 * @param int $user_id    ID користувача (0 = поточний).
	 * @param int $service_id ID послуги.
	 * @return bool
	 */
	function wcc_check_user_premium( $user_id = 0, $service_id = 0 ) {
		$user_id    = $user_id ? (int) $user_id : get_current_user_id();
		$service_id = (int) $service_id;
		if ( ! $user_id || ! $service_id ) {
			return false;
		}
		return (bool) apply_filters( 'upb_user_is_premium', UPB_Premium::is_active( $user_id, $service_id ), $user_id, $service_id );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\upb_check_user_premium' ) ) {
	/** Аліас. */
	function upb_check_user_premium( $user_id = 0, $service_id = 0 ) {
		return wcc_check_user_premium( $user_id, $service_id );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\wcc_get_user_premium_expiry' ) ) {
	/**
	 * Термін дії PRO: unix timestamp, 0 = безстроково, false = PRO немає.
	 */
	function wcc_get_user_premium_expiry( $user_id = 0, $service_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! wcc_check_user_premium( $user_id, $service_id ) ) {
			return false;
		}
		$state = UPB_Premium::state( $user_id, $service_id );
		return 'unlimited' === $state['state'] ? 0 : (int) $state['expires'];
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\upb_enqueue_assets' ) ) {
	/**
	 * Підключити JS/CSS модального вікна на поточній сторінці
	 * (для кнопок з атрибутом data-upb-buy="ID" всередині інших плагінів/ігор).
	 */
	function upb_enqueue_assets() {
		UPB_Frontend::need();
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\upb_service_id' ) ) {
	/**
	 * ID проекту за slug плагіна (або числовим ID). 0, якщо проекту немає.
	 */
	function upb_service_id( $slug_or_id ) {
		if ( is_numeric( $slug_or_id ) ) {
			$svc = UPB_Services::get( (int) $slug_or_id );
			return $svc ? (int) $svc['id'] : 0;
		}
		$svc = UPB_Services::by_slug( $slug_or_id );
		return $svc ? (int) $svc['id'] : 0;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\upb_register_service' ) ) {
	/**
	 * Створює проект у Pay Box, якщо його ще немає (викликається іншим плагіном).
	 * Дані Freemius для оплати адмін налаштовує в UA Pay Box → Послуги.
	 */
	function upb_register_service( $slug, $name, array $extra = array() ) {
		return UPB_Services::register( $slug, $name, $extra );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\upb_get_service' ) ) {
	function upb_get_service( $slug_or_id ) {
		$id = upb_service_id( $slug_or_id );
		return $id ? UPB_Services::get( $id ) : null;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\upb_buy_button' ) ) {
	/**
	 * HTML кнопки «Купити PRO». Працює і на сайті, і в wp-admin.
	 * Відкриває попап Freemius Checkout; PRO вмикається після підтвердженого webhook.
	 */
	function upb_buy_button( $service_id, $label = 'Купити PRO', $class = '' ) {
		$service_id = upb_service_id( $service_id );
		if ( ! $service_id ) {
			return '';
		}
		UPB_Frontend::need();
		$uid = get_current_user_id();
		if ( $uid ) {
			$st = UPB_Premium::state( $uid, $service_id );
			if ( 'active' === $st['state'] || 'unlimited' === $st['state'] ) {
				return '<span class="upb-inline-note">✅ PRO активний</span>';
			}
		}
		return '<button type="button" class="upb-btn upb-btn--inline ' . esc_attr( $class ) . '" data-upb-buy="' . (int) $service_id . '">' . esc_html( $label ) . '</button>';
	}
}
