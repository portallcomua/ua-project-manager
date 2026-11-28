<?php
namespace UAPM\PayBox;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Генератор коду інтеграції для кожного проекту (no-key email flow + Freemius Checkout).
 */
class UPB_Snippet {

	/** PHP для плагіна на БУДЬ-ЯКОМУ сайті: PRO визначається автоматично за email через REST Pay Box. */
	public static function php( array $svc ) {
		return self::freemius_php( $svc );
	}

	/** WordPress client snippet: verify lifetime PRO by site admin email without manual license keys. */
	public static function freemius_php( array $svc ) {
		$slug = ! empty( $svc['slug'] ) ? $svc['slug'] : 'project-' . (int) $svc['id'];
		$fn   = 'upb_' . preg_replace( '/[^a-z0-9_]/', '_', strtolower( $slug ) );
		$hub  = untrailingslashit( home_url() );
		$checkout = str_replace( "'", "\\'", isset( $svc['freemius_checkout_url'] ) ? $svc['freemius_checkout_url'] : '' );
		$tpl = <<<'TPL'
/* UA Pay Box + Freemius (без ключів, за email): вставте у головний файл WordPress-плагіна.
 * Посилання оплати підставляє admin_email сайту з readonly_user=true, а PRO активується автоматично після оплати.
 */
if ( ! function_exists( '%FN%_is_pro' ) ) {
	function %FN%_checkout_url() {
		$base = '%CHECKOUT%';
		if ( '' === $base ) { return ''; }
		$email = sanitize_email( get_option( 'admin_email' ) );
		return $email ? add_query_arg( array( 'user_email' => $email, 'readonly_user' => 'true' ), $base ) : $base;
	}
	function %FN%_is_pro( $force = false ) {
		$email = sanitize_email( get_option( 'admin_email' ) );
		if ( ! $email || ! is_email( $email ) ) { return false; }
		$cache = get_transient( '%FN%_license_status' );
		if ( ! $force && is_array( $cache ) && isset( $cache['checked'], $cache['valid'] ) && time() - (int) $cache['checked'] < 6 * HOUR_IN_SECONDS ) {
			return (bool) $cache['valid'];
		}
		$r = wp_remote_post( '%HUB%/wp-json/upb/v1/license/status', array(
			'timeout' => 10,
			'body' => array( 'service' => '%SLUG%', 'email' => $email ),
		) );
		$valid = false;
		if ( ! is_wp_error( $r ) && 200 === (int) wp_remote_retrieve_response_code( $r ) ) {
			$data = json_decode( wp_remote_retrieve_body( $r ), true );
			$env = is_array( $data ) && isset( $data['environment'] ) ? strtolower( trim( (string) $data['environment'] ) ) : '';
			$live = '' === $env || '0' === $env || in_array( $env, array( 'production', 'live' ), true );
			$valid = is_array( $data ) && ! empty( $data['valid'] ) && $live;
		}
		set_transient( '%FN%_license_status', array( 'valid' => $valid, 'checked' => time() ), 6 * HOUR_IN_SECONDS );
		return $valid;
	}
}
TPL;
		return str_replace( array( '%FN%', '%SLUG%', '%HUB%', '%CHECKOUT%' ), array( $fn, $slug, $hub, $checkout ), $tpl );
	}

	/** Android Kotlin: verify lifetime PRO by purchaser email on a background thread. */
	public static function android_kotlin( array $svc ) {
		$slug = ! empty( $svc['slug'] ) ? $svc['slug'] : 'project-' . (int) $svc['id'];
		$hub  = untrailingslashit( home_url() );
		$checkout = wp_json_encode( isset( $svc['freemius_checkout_url'] ) ? $svc['freemius_checkout_url'] : '' );
		$slug_json = wp_json_encode( $slug );
		$endpoint_json = wp_json_encode( $hub . '/wp-json/upb/v1/license/status' );
		$tpl = <<<'KOTLIN'
/* AndroidManifest.xml: add <uses-permission android:name="android.permission.INTERNET" /> */
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL
import java.net.URLEncoder

object PayBoxLicense {
    const val CHECKOUT_URL = %CHECKOUT_JSON%
    private const val SERVICE_SLUG = %SLUG_JSON%
    private const val STATUS_URL = %ENDPOINT_JSON%

    fun checkoutUrl(email: String): String {
        val sep = if (CHECKOUT_URL.contains("?")) "&" else "?"
        return CHECKOUT_URL + sep + "user_email=" + URLEncoder.encode(email, "UTF-8") + "&readonly_user=true"
    }

    fun isPro(email: String): Boolean {
        val query = "service=" + URLEncoder.encode(SERVICE_SLUG, "UTF-8") + "&email=" + URLEncoder.encode(email, "UTF-8")
        val connection = (URL(STATUS_URL).openConnection() as HttpURLConnection)
        return try {
            connection.requestMethod = "POST"
            connection.doOutput = true
            connection.setRequestProperty("Content-Type", "application/x-www-form-urlencoded; charset=UTF-8")
            connection.connectTimeout = 10000
            connection.readTimeout = 10000
            connection.outputStream.use { it.write(query.toByteArray(Charsets.UTF_8)) }
            val json = JSONObject(connection.inputStream.bufferedReader().use { it.readText() })
            val env = json.optString("environment", "production").lowercase()
            json.optBoolean("valid", false) && (env == "production" || env == "live" || env == "0" || env.isEmpty())
        } catch (e: Exception) {
            false
        } finally {
            connection.disconnect()
        }
    }
}
KOTLIN;
		return str_replace(
			array( '%CHECKOUT_JSON%', '%SLUG_JSON%', '%ENDPOINT_JSON%' ),
			array( $checkout, $slug_json, $endpoint_json ),
			$tpl
		);
	}

	/** Chrome extension JavaScript snippet: check PRO status by purchaser email. */
	public static function chrome_js( array $svc ) {
		$slug = ! empty( $svc['slug'] ) ? $svc['slug'] : 'project-' . (int) $svc['id'];
		$hub  = untrailingslashit( home_url() );
		$tpl = <<<'JS'
const PAYBOX_LICENSE_URL = %ENDPOINT_JSON%;
const PAYBOX_SERVICE = %SLUG_JSON%;
const PAYBOX_CHECKOUT_URL = %CHECKOUT_JSON%;

async function checkPayBoxLicense(email) {
  const body = new URLSearchParams({ service: PAYBOX_SERVICE, email });
  try {
    const response = await fetch(PAYBOX_LICENSE_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body,
    });
    if (!response.ok) return false;
    const data = await response.json();
    const env = String(data.environment || 'production').toLowerCase();
    return data.valid === true && (env === 'production' || env === 'live' || env === '0' || env === '');
  } catch (error) {
    return false;
  }
}
JS;
		return str_replace(
			array( '%ENDPOINT_JSON%', '%SLUG_JSON%', '%CHECKOUT_JSON%' ),
			array(
				wp_json_encode( $hub . '/wp-json/upb/v1/license/status' ),
				wp_json_encode( $slug ),
				wp_json_encode( isset( $svc['freemius_checkout_url'] ) ? $svc['freemius_checkout_url'] : '' ),
			),
			$tpl
		);
	}

	public static function html( array $svc ) {
		$id = (int) $svc['id'];
		return "<!-- Кнопка в HTML/грі (потрібні скрипти Pay Box на сторінці) -->\n<button data-upb-buy=\"{$id}\">Купити PRO</button>\n<script>/* або з JS: */ UPB.open({$id});</script>";
	}
}
