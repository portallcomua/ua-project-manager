<?php
namespace UAPM\PayBox;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Генератор коду інтеграції для кожного проекту.
 */
class UPB_Snippet {

	/** PHP для плагіна на БУДЬ-ЯКОМУ сайті: PRO визначається за доменом через REST вашого сервера Pay Box. */
	public static function php( array $svc ) {
		// The primary integration snippet is Freemius-only.
		return self::freemius_php( $svc );
	}

	/** WordPress client snippet: validate a Freemius license without embedding server secrets. */
	public static function freemius_php( array $svc ) {
		$slug = ! empty( $svc['slug'] ) ? $svc['slug'] : 'project-' . (int) $svc['id'];
		$fn   = 'upb_' . preg_replace( '/[^a-z0-9_]/', '_', strtolower( $slug ) );
		$hub  = untrailingslashit( home_url() );
		$checkout = str_replace( "'", "\\'", isset( $svc['freemius_checkout_url'] ) ? $svc['freemius_checkout_url'] : '' );
		$tpl = <<<'TPL'
/* UA Pay Box + Freemius: вставте у головний файл WordPress-плагіна.
 * Ліцензійний ключ користувач отримує від Freemius. Product Secret Key тут НЕ потрібен.
 * Збережіть введений ключ: update_option( '%FN%_license_key', $license_key );
 */
if ( ! function_exists( '%FN%_is_pro' ) ) {
	function %FN%_checkout_url() { return '%CHECKOUT%'; }
	function %FN%_set_license_key( $license_key ) {
		update_option( '%FN%_license_key', sanitize_text_field( $license_key ), false );
		delete_transient( '%FN%_license_status' );
	}
	function %FN%_is_pro( $force = false ) {
		$key = trim( (string) get_option( '%FN%_license_key', '' ) );
		if ( '' === $key ) { return false; }
		$cache = get_transient( '%FN%_license_status' );
		if ( ! $force && is_array( $cache ) && isset( $cache['checked'], $cache['valid'] ) && time() - (int) $cache['checked'] < 6 * HOUR_IN_SECONDS ) {
			return (bool) $cache['valid'];
		}
		$r = wp_remote_post( '%HUB%/wp-json/upb/v1/license/status', array(
			'timeout' => 10,
			'body' => array( 'service' => '%SLUG%', 'license_key' => $key ),
		) );
		$valid = false;
		if ( ! is_wp_error( $r ) && 200 === (int) wp_remote_retrieve_response_code( $r ) ) {
			$data = json_decode( wp_remote_retrieve_body( $r ), true );
			$valid = is_array( $data ) && ! empty( $data['valid'] );
		}
		set_transient( '%FN%_license_status', array( 'valid' => $valid, 'checked' => time() ), 6 * HOUR_IN_SECONDS );
		return $valid;
	}
}
TPL;
		return str_replace( array( '%FN%', '%SLUG%', '%HUB%', '%CHECKOUT%' ), array( $fn, $slug, $hub, $checkout ), $tpl );
	}

	/** Android Kotlin: call from a background thread (network access is not allowed on the UI thread). */
	public static function android_kotlin( array $svc ) {
		$slug = ! empty( $svc['slug'] ) ? $svc['slug'] : 'project-' . (int) $svc['id'];
		$hub  = untrailingslashit( home_url() );
		$checkout = wp_json_encode( isset( $svc['freemius_checkout_url'] ) ? $svc['freemius_checkout_url'] : '' );
		return '/* AndroidManifest.xml: add <uses-permission android:name="android.permission.INTERNET" /> */' . "\n" .
			"import org.json.JSONObject\nimport java.net.HttpURLConnection\nimport java.net.URL\nimport java.net.URLEncoder\n\n" .
			"object PayBoxLicense {\n    const val CHECKOUT_URL = " . $checkout . "\n    fun isPro(licenseKey: String): Boolean {\n        val query = \"service=" . $slug . "&license_key=\" + URLEncoder.encode(licenseKey, \"UTF-8\")\n        val connection = (URL(\"" . $hub . "/wp-json/upb/v1/license/status\").openConnection() as HttpURLConnection)\n        return try {\n            connection.requestMethod = \"POST\"\n            connection.doOutput = true\n            connection.setRequestProperty(\"Content-Type\", \"application/x-www-form-urlencoded; charset=UTF-8\")\n            connection.connectTimeout = 10000\n            connection.readTimeout = 10000\n            connection.outputStream.use { it.write(query.toByteArray(Charsets.UTF_8)) }\n            connection.inputStream.bufferedReader().use { JSONObject(it.readText()).optBoolean(\"valid\", false) }\n        } catch (e: Exception) { false } finally { connection.disconnect() }\n    }\n}\n";
	}

	/** Chrome extension JavaScript snippet. Store the key in chrome.storage, never in manifest/source. */
	public static function chrome_js( array $svc ) {
		$slug = ! empty( $svc['slug'] ) ? $svc['slug'] : 'project-' . (int) $svc['id'];
		$hub  = untrailingslashit( home_url() );
		return "const PAYBOX_LICENSE_URL = " . wp_json_encode( $hub . '/wp-json/upb/v1/license/status' ) . ";\n" .
			"const PAYBOX_SERVICE = " . wp_json_encode( $slug ) . ";\n" .
			"const PAYBOX_CHECKOUT_URL = " . wp_json_encode( isset( $svc['freemius_checkout_url'] ) ? $svc['freemius_checkout_url'] : '' ) . ";\n\n" .
			"async function checkPayBoxLicense(licenseKey) {\n" .
			"  const body = new URLSearchParams({ service: PAYBOX_SERVICE, license_key: licenseKey });\n" .
			"  try { const response = await fetch(PAYBOX_LICENSE_URL, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body }); if (!response.ok) return false; const data = await response.json(); return data.valid === true; }\n" .
			"  catch (error) { return false; }\n}\n";
	}

	public static function html( array $svc ) {
		$id = (int) $svc['id'];
		return "<!-- Кнопка в HTML/грі (потрібні скрипти Pay Box на сторінці) -->\n<button data-upb-buy=\"{$id}\">Купити PRO</button>\n<script>/* або з JS: */ UPB.open({$id});</script>";
	}
}
