<?php
namespace UAPM\PayBox;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Логіка PRO-статусів користувачів (user_meta + журнал активацій).
 *
 * Мета-ключ: _upb_premium_{service_id} => array(
 *   status   => active | blocked | expired
 *   expires  => unix timestamp (0 = безстроково)
 *   activated=> unix timestamp
 *   log_id   => id запису в журналі
 *   notified => 0|1 (чи надіслано лист про закінчення)
 * )
 */
class UPB_Premium {

	/** Чи має користувач чинний PRO. */
	public static function is_active( $user_id, $service_id ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return false;
		}
		return UPB_Freemius::is_email_active( (int) $service_id, $user->user_email );
	}

	/**
	 * Стан PRO визначається тільки записом Freemius, а не старими user_meta.
	 */
	public static function state( $user_id, $service_id ) {
		$out = array( 'state' => 'free', 'expires' => 0, 'days_left' => 0 );
		$user = get_userdata( (int) $user_id );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return $out;
		}
		$license = UPB_Freemius::get_email_license( (int) $service_id, $user->user_email );
		if ( ! $license || 'active' !== $license->status ) {
			return $out;
		}
		$exp = (int) $license->expires;
		if ( $exp && $exp <= time() ) {
			return $out;
		}
		if ( 0 === $exp ) {
			$out['state'] = 'unlimited';
			return $out;
		}
		$out['expires'] = $exp;
		$out['days_left'] = (int) ceil( ( $exp - time() ) / DAY_IN_SECONDS );
		$out['state'] = $out['days_left'] <= (int) UPB_Settings::get( 'warn_days' ) ? 'expiring' : 'active';
		return $out;
	}

}
