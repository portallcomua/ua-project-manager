<?php
namespace UAPM\PayBox;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UPB_Util {

	/** Правильна форма слова "день" українською. */
	public static function days_word( $n ) {
		$n    = abs( (int) $n );
		$m10  = $n % 10;
		$m100 = $n % 100;
		if ( $m10 === 1 && $m100 !== 11 ) {
			return $n . ' день';
		}
		if ( $m10 >= 2 && $m10 <= 4 && ( $m100 < 12 || $m100 > 14 ) ) {
			return $n . ' дні';
		}
		return $n . ' днів';
	}

	/** Домен поточного сайту (без www). */
	public static function site_domain() {
		$h = wp_parse_url( home_url(), PHP_URL_HOST );
		return strtolower( preg_replace( '/^www\./', '', (string) $h ) );
	}

	/** Нормалізація домену, отриманого ззовні. Порожній рядок — якщо недійсний. */
	public static function clean_domain( $d ) {
		$d = strtolower( trim( (string) $d ) );
		$d = preg_replace( '#^[a-z]+://#', '', $d );
		$d = preg_replace( '#[/?\#].*$#', '', $d );
		$d = preg_replace( '#:\d+$#', '', $d );
		$d = preg_replace( '/^www\./', '', $d );
		if ( function_exists( 'idn_to_ascii' ) && preg_match( '/[^\x00-\x7F]/', $d ) ) {
			$a = idn_to_ascii( $d, 0, defined( 'INTL_IDNA_VARIANT_UTS46' ) ? INTL_IDNA_VARIANT_UTS46 : 0 );
			$d = $a ? $a : $d;
		}
		if ( 'localhost' === $d ) {
			return $d;
		}
		return preg_match( '/^[a-z0-9]([a-z0-9\-\.]{0,186}[a-z0-9])?\.[a-z0-9\-]{2,}$/', $d ) ? $d : '';
	}

	public static function fmt_ts( $ts ) {
		return wp_date( 'd.m.Y', (int) $ts );
	}

	public static function fmt_gmt( $mysql_gmt ) {
		return wp_date( 'd.m.Y H:i', strtotime( $mysql_gmt . ' UTC' ) );
	}

	public static function replace_tokens( $text, array $map ) {
		$search  = array();
		$replace = array();
		foreach ( $map as $k => $v ) {
			$search[]  = '{' . $k . '}';
			$replace[] = $v;
		}
		return str_replace( $search, $replace, (string) $text );
	}
}
