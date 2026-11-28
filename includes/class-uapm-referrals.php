<?php
/**
 * UA Project Manager - Referral & Partner System.
 *
 * Shortlinks (/r{user_id} and /r{user_id}/{slug}), signed 30-day attribution cookies,
 * Click Counter download tracking, live Freemius payment commissions,
 * frontend partner cabinet (/partner/ + [uapm_referral_portal]), and payout ledger.
 */

if (!defined('ABSPATH')) {
    exit;
}

class UAPM_Referrals {

    const DB_VERSION = '1.0.0';
    const COOKIE_NAME = 'uapm_ref';

    public static function init() {
        add_action('init', array(__CLASS__, 'register_rewrites'), 10);
        add_filter('query_vars', array(__CLASS__, 'register_query_vars'));
        add_action('template_redirect', array(__CLASS__, 'handle_frontend_routes'), 5);
        add_shortcode('uapm_referral_portal', array(__CLASS__, 'render_portal_shortcode'));

        add_action('admin_menu', array(__CLASS__, 'register_menu'), 65);
        add_action('admin_post_uapm_ref_save_settings', array(__CLASS__, 'handle_save_settings'));
        add_action('admin_post_uapm_ref_save_rates', array(__CLASS__, 'handle_save_rates'));
        add_action('admin_post_uapm_ref_mark_paid', array(__CLASS__, 'handle_mark_paid'));
        add_action('admin_post_uapm_ref_save_partner_details', array(__CLASS__, 'handle_partner_save_details'));
        add_action('admin_post_uapm_ref_join_partner', array(__CLASS__, 'handle_join_partner'));

        add_action('uacc_recorded_email', array(__CLASS__, 'handle_click_counter_lead'), 10, 4);

        if (get_option('uapm_referrals_db_version') !== self::DB_VERSION) {
            if (defined('UAPM_DB_VERSION') && get_option('uapm_modules_schema_ver') !== UAPM_DB_VERSION) {
                if (defined('UAPM_DB_VERSION') && get_option('uapm_modules_schema_ver') !== UAPM_DB_VERSION) {
                    if (defined('UAPM_DB_VERSION') && get_option('uapm_modules_schema_ver') !== UAPM_DB_VERSION) {
                        self::install_schema();
                    }
                }
            }
        }
    }

    private static function table($name) {
        global $wpdb;
        $allowed = array(
            'partners' => $wpdb->prefix . 'uapm_referrals_partners',
            'rates'    => $wpdb->prefix . 'uapm_referral_rates',
            'events'   => $wpdb->prefix . 'uapm_referral_events',
            'payouts'  => $wpdb->prefix . 'uapm_referral_payouts',
        );
        return isset($allowed[$name]) ? $allowed[$name] : '';
    }

    public static function install_schema() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        $t_partners = self::table('partners');
        $t_rates    = self::table('rates');
        $t_events   = self::table('events');
        $t_payouts  = self::table('payouts');

        $sql = array(
            "CREATE TABLE {$t_partners} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint(20) unsigned NOT NULL,
                status varchar(20) NOT NULL DEFAULT 'active',
                payout_method varchar(40) NOT NULL DEFAULT 'card',
                payout_details text NULL,
                notes text NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY user_id (user_id),
                KEY status (status)
            ) {$charset};",

            "CREATE TABLE {$t_rates} (
                project_id bigint(20) unsigned NOT NULL,
                commission_percent decimal(5,2) NOT NULL DEFAULT 0.00,
                is_active tinyint(1) NOT NULL DEFAULT 1,
                promo_text longtext NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (project_id)
            ) {$charset};",

            "CREATE TABLE {$t_events} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                partner_user_id bigint(20) unsigned NOT NULL,
                project_id bigint(20) unsigned NOT NULL DEFAULT 0,
                event_type varchar(20) NOT NULL DEFAULT 'visit',
                visitor_hash char(64) NOT NULL DEFAULT '',
                email varchar(191) NOT NULL DEFAULT '',
                transaction_id varchar(191) NOT NULL DEFAULT '',
                gross_amount decimal(18,2) NOT NULL DEFAULT 0.00,
                currency varchar(10) NOT NULL DEFAULT 'UAH',
                commission_percent decimal(5,2) NOT NULL DEFAULT 0.00,
                commission_uah decimal(18,2) NOT NULL DEFAULT 0.00,
                status varchar(20) NOT NULL DEFAULT 'approved',
                payout_id bigint(20) unsigned NOT NULL DEFAULT 0,
                meta longtext NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY partner_user_id (partner_user_id),
                KEY project_id (project_id),
                KEY event_type (event_type),
                KEY email (email),
                KEY transaction_id (transaction_id),
                KEY status (status),
                KEY payout_id (payout_id)
            ) {$charset};",

            "CREATE TABLE {$t_payouts} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                partner_user_id bigint(20) unsigned NOT NULL,
                amount_uah decimal(18,2) NOT NULL DEFAULT 0.00,
                payout_method varchar(40) NOT NULL DEFAULT 'card',
                payout_details text NULL,
                reference varchar(191) NOT NULL DEFAULT '',
                status varchar(20) NOT NULL DEFAULT 'paid',
                paid_by bigint(20) unsigned NOT NULL DEFAULT 0,
                paid_at datetime NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY partner_user_id (partner_user_id),
                KEY status (status)
            ) {$charset};",
        );

        foreach ($sql as $query) {
            dbDelta($query);
        }

        if (false === get_option('uapm_ref_min_payout_uah', false)) {
            add_option('uapm_ref_min_payout_uah', 1000, '', false);
        }
        if (false === get_option('uapm_ref_payout_day', false)) {
            add_option('uapm_ref_payout_day', 25, '', false);
        }

        update_option('uapm_referrals_db_version', self::DB_VERSION, false);
    }

    public static function register_rewrites() {
        add_rewrite_rule('^r([0-9]+)/([a-zA-Z0-9_-]+)/?$', 'index.php?uapm_ref_uid=$matches[1]&uapm_ref_proj=$matches[2]', 'top');
        add_rewrite_rule('^r([0-9]+)/?$', 'index.php?uapm_ref_uid=$matches[1]', 'top');
        add_rewrite_rule('^partner/?$', 'index.php?uapm_ref_portal=1', 'top');
    }

    public static function register_query_vars($vars) {
        $vars[] = 'uapm_ref_uid';
        $vars[] = 'uapm_ref_proj';
        $vars[] = 'uapm_ref_portal';
        return $vars;
    }

    private static function visitor_hash() {
        $ip = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '');
        $ua = sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? '');
        return hash_hmac('sha256', $ip . '|' . $ua, wp_salt('auth'));
    }

    private static function sign_cookie_payload($payload) {
        $json = wp_json_encode($payload);
        $b64  = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        $sig  = hash_hmac('sha256', $b64, wp_salt('auth'));
        return $b64 . '.' . $sig;
    }

    private static function parse_cookie() {
        if (empty($_COOKIE[self::COOKIE_NAME]) || !is_string($_COOKIE[self::COOKIE_NAME])) {
            return null;
        }
        $parts = explode('.', wp_unslash($_COOKIE[self::COOKIE_NAME]), 2);
        if (count($parts) !== 2) {
            return null;
        }
        list($b64, $sig) = $parts;
        $expected = hash_hmac('sha256', $b64, wp_salt('auth'));
        if (!hash_equals($expected, $sig)) {
            return null;
        }
        $pad = strlen($b64) % 4;
        if ($pad) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        $decoded = json_decode(base64_decode(strtr($b64, '-_', '+/')), true);
        if (!is_array($decoded) || empty($decoded['uid']) || empty($decoded['exp']) || (int) $decoded['exp'] < time()) {
            return null;
        }
        return $decoded;
    }

    private static function set_ref_cookie($uid, $project_id = 0) {
        $exp = time() + (30 * DAY_IN_SECONDS);
        $val = self::sign_cookie_payload(array(
            'uid' => absint($uid),
            'pid' => absint($project_id),
            'exp' => $exp,
        ));
        $_COOKIE[self::COOKIE_NAME] = $val;
        if (!headers_sent()) {
            setcookie(self::COOKIE_NAME, $val, array(
                'expires'  => $exp,
                'path'     => COOKIEPATH ? COOKIEPATH : '/',
                'domain'   => COOKIE_DOMAIN,
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ));
        }
    }

    private static function ensure_partner($user_id) {
        global $wpdb;
        $user_id = absint($user_id);
        if (!$user_id || !get_userdata($user_id)) {
            return false;
        }
        $table = self::table('partners');
        $exists = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE user_id = %d", $user_id));
        if ($exists) {
            return $exists;
        }
        $now = current_time('mysql');
        $wpdb->insert($table, array(
            'user_id'        => $user_id,
            'status'         => 'active',
            'payout_method'  => 'card',
            'payout_details' => '',
            'notes'          => '',
            'created_at'     => $now,
            'updated_at'     => $now,
        ));
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE user_id = %d", $user_id));
    }

    public static function handle_frontend_routes() {
        $portal = get_query_var('uapm_ref_portal');
        if ($portal) {
            self::render_frontend_portal_page();
            exit;
        }

        $uid = absint(get_query_var('uapm_ref_uid'));
        $proj_slug = sanitize_title((string) get_query_var('uapm_ref_proj'));

        if (!$uid && !empty($_GET['ref'])) {
            $raw_ref = sanitize_text_field(wp_unslash($_GET['ref']));
            if (preg_match('/^r?(\d+)$/i', $raw_ref, $m)) {
                $uid = absint($m[1]);
            }
            if (!$proj_slug && !empty($_GET['project'])) {
                $proj_slug = sanitize_title(wp_unslash($_GET['project']));
            }
        }

        if (!$uid) {
            return;
        }

        $user = get_userdata($uid);
        if (!$user) {
            return;
        }

        $partner = self::ensure_partner($uid);
        if ($partner && $partner->status !== 'active') {
            wp_safe_redirect(home_url('/'));
            exit;
        }

        $project = null;
        if ($proj_slug && class_exists('UAPM_Projects')) {
            $project = UAPM_Projects::get_by_slug($proj_slug);
        }
        $project_id = $project ? absint($project->id) : 0;

        self::set_ref_cookie($uid, $project_id);

        // Log deduplicated 24h visit per visitor hash + partner + project.
        global $wpdb;
        $t_events = self::table('events');
        $vhash    = self::visitor_hash();
        $since    = gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS);
        $dup = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$t_events} WHERE partner_user_id=%d AND project_id=%d AND event_type='visit' AND visitor_hash=%s AND created_at >= %s LIMIT 1",
            $uid, $project_id, $vhash, $since
        ));
        if (!$dup) {
            $wpdb->insert($t_events, array(
                'partner_user_id'    => $uid,
                'project_id'         => $project_id,
                'event_type'         => 'visit',
                'visitor_hash'       => $vhash,
                'email'              => '',
                'transaction_id'     => '',
                'gross_amount'       => 0,
                'currency'           => 'UAH',
                'commission_percent' => 0,
                'commission_uah'     => 0,
                'status'             => 'approved',
                'payout_id'          => 0,
                'meta'               => wp_json_encode(array('referer' => esc_url_raw($_SERVER['HTTP_REFERER'] ?? ''))),
                'created_at'         => current_time('mysql'),
            ));
        }

        $target = home_url('/');
        if ($project && !empty($project->website_url)) {
            $target = esc_url_raw($project->website_url);
        } elseif ($project && !empty($project->documentation_url)) {
            $target = esc_url_raw($project->documentation_url);
        }

        wp_safe_redirect($target, 302);
        exit;
    }

    public static function handle_click_counter_lead($button_id, $email, $consent, $url) {
        if (!$consent || !is_email($email)) {
            return;
        }
        $cookie = self::parse_cookie();
        if (!$cookie || empty($cookie['uid'])) {
            return;
        }
        $uid = absint($cookie['uid']);
        $project_id = absint(get_post_meta($button_id, '_uacc_project_id', true));
        if (!$project_id && !empty($cookie['pid'])) {
            $project_id = absint($cookie['pid']);
        }
        global $wpdb;
        $t_events = self::table('events');
        $wpdb->insert($t_events, array(
            'partner_user_id'    => $uid,
            'project_id'         => $project_id,
            'event_type'         => 'download',
            'visitor_hash'       => self::visitor_hash(),
            'email'              => sanitize_email($email),
            'transaction_id'     => 'uacc_' . absint($button_id) . '_' . md5(strtolower($email) . gmdate('Y-m-d')),
            'gross_amount'       => 0,
            'currency'           => 'UAH',
            'commission_percent' => 0,
            'commission_uah'     => 0,
            'status'             => 'approved',
            'payout_id'          => 0,
            'meta'               => wp_json_encode(array('button_id' => absint($button_id), 'url' => esc_url_raw((string) $url))),
            'created_at'         => current_time('mysql'),
        ));
    }

    private static function project_id_for_paybox_service($service_id) {
        $map = get_option('uapm_paybox_project_map', array());
        if (is_array($map)) {
            foreach ($map as $proj_id => $svc_id) {
                if (absint($svc_id) === absint($service_id)) {
                    return absint($proj_id);
                }
            }
        }
        return 0;
    }

    private static function rate_for_project($project_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table('rates') . " WHERE project_id = %d",
            absint($project_id)
        ), ARRAY_A);
        if ($row) {
            return $row;
        }
        return array(
            'project_id'         => absint($project_id),
            'commission_percent' => (float) get_option('uapm_default_commission', 0),
            'is_active'          => 1,
            'promo_text'         => '',
        );
    }

    private static function convert_to_uah($amount, $currency, $service_id = 0) {
        $amount = (float) $amount;
        $currency = strtoupper(trim((string) $currency));
        if ($amount <= 0) {
            return 0.0;
        }
        if ($currency === '' || $currency === 'UAH') {
            return round($amount, 2);
        }
        if ($currency === 'USD') {
            $rate = 0.0;
            if ($service_id && class_exists('\\UAPM\\PayBox\\UPB_Services')) {
                $svc = \UAPM\PayBox\UPB_Services::get($service_id);
                if (!empty($svc['freemius_price_cache']['exchange_rate'])) {
                    $rate = (float) $svc['freemius_price_cache']['exchange_rate'];
                }
            }
            if ($rate <= 0) {
                $cached = get_transient('uapm_nbu_usd_rate');
                if ($cached && (float) $cached > 0) {
                    $rate = (float) $cached;
                } else {
                    $res = wp_remote_get('https://bank.gov.ua/NBUStatService/v1/statdirectory/exchange?valcode=USD&json', array('timeout' => 10));
                    if (!is_wp_error($res) && 200 === (int) wp_remote_retrieve_response_code($res)) {
                        $json = json_decode(wp_remote_retrieve_body($res), true);
                        if (!empty($json[0]['rate'])) {
                            $rate = (float) $json[0]['rate'];
                            set_transient('uapm_nbu_usd_rate', $rate, 12 * HOUR_IN_SECONDS);
                        }
                    }
                }
            }
            if ($rate <= 0) {
                $rate = 41.5;
            }
            return round($amount * $rate, 2);
        }
        return round($amount, 2);
    }

    public static function record_freemius_payment($service_id, $email, $license_id, $paid_amount = 0, $paid_currency = 'USD', $payment = array()) {
        global $wpdb;
        $service_id = absint($service_id);
        $email = sanitize_email($email);
        $license_id = sanitize_text_field((string) $license_id);
        if (!$service_id || !is_email($email) || $license_id === '') {
            return false;
        }

        $t_events = self::table('events');
        $tx_id = 'fs_' . $license_id;
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$t_events} WHERE transaction_id = %s AND event_type = 'sale' LIMIT 1",
            $tx_id
        ));
        if ($exists) {
            return false;
        }

        $project_id = self::project_id_for_paybox_service($service_id);
        $partner_uid = 0;

        $cookie = self::parse_cookie();
        if ($cookie && !empty($cookie['uid'])) {
            $partner_uid = absint($cookie['uid']);
        }
        if (!$partner_uid) {
            $since = gmdate('Y-m-d H:i:s', time() - (30 * DAY_IN_SECONDS));
            $partner_uid = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT partner_user_id FROM {$t_events} WHERE LOWER(email) = LOWER(%s) AND event_type IN ('download','visit') AND created_at >= %s ORDER BY id DESC LIMIT 1",
                $email, $since
            ));
        }

        if (!$partner_uid) {
            return false;
        }

        $partner = self::ensure_partner($partner_uid);
        if (!$partner || $partner->status !== 'active') {
            return false;
        }

        $rate = $project_id ? self::rate_for_project($project_id) : array('commission_percent' => 0, 'is_active' => 1);
        if (empty($rate['is_active']) || (float) $rate['commission_percent'] <= 0) {
            return false;
        }

        $pct = (float) $rate['commission_percent'];
        $gross = (float) $paid_amount;
        $cur = strtoupper(trim((string) $paid_currency));
        if ($gross <= 0 && class_exists('\\UAPM\\PayBox\\UPB_Services')) {
            $svc = \UAPM\PayBox\UPB_Services::get($service_id);
            if (!empty($svc['freemius_price_cache']['usd_price'])) {
                $gross = (float) $svc['freemius_price_cache']['usd_price'];
                $cur = 'USD';
            }
        }
        if ($gross <= 0) {
            return false;
        }

        $gross_uah = self::convert_to_uah($gross, $cur ?: 'USD', $service_id);
        $commission_uah = round($gross_uah * ($pct / 100), 2);

        $wpdb->insert($t_events, array(
            'partner_user_id'    => $partner_uid,
            'project_id'         => $project_id,
            'event_type'         => 'sale',
            'visitor_hash'       => '',
            'email'              => $email,
            'transaction_id'     => $tx_id,
            'gross_amount'       => $gross,
            'currency'           => substr($cur ?: 'USD', 0, 10),
            'commission_percent' => $pct,
            'commission_uah'     => $commission_uah,
            'status'             => 'approved',
            'payout_id'          => 0,
            'meta'               => wp_json_encode(array('service_id' => $service_id, 'license_id' => $license_id, 'gross_uah' => $gross_uah)),
            'created_at'         => current_time('mysql'),
        ));

        return (int) $wpdb->insert_id;
    }

    public static function revoke_transaction($license_id) {
        global $wpdb;
        $license_id = sanitize_text_field((string) $license_id);
        if ($license_id === '') {
            return false;
        }
        $tx_id = 'fs_' . $license_id;
        return $wpdb->update(
            self::table('events'),
            array('status' => 'revoked'),
            array('transaction_id' => $tx_id, 'event_type' => 'sale')
        );
    }

    public static function get_partner_summary($user_id) {
        global $wpdb;
        $user_id = absint($user_id);
        $t_events = self::table('events');
        $t_payouts = self::table('payouts');

        $visits = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$t_events} WHERE partner_user_id = %d AND event_type = 'visit'",
            $user_id
        ));
        $downloads = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$t_events} WHERE partner_user_id = %d AND event_type = 'download'",
            $user_id
        ));
        $sales = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$t_events} WHERE partner_user_id = %d AND event_type = 'sale' AND status = 'approved'",
            $user_id
        ));
        $earned = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(commission_uah), 0) FROM {$t_events} WHERE partner_user_id = %d AND event_type = 'sale' AND status = 'approved'",
            $user_id
        ));
        $unpaid = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(commission_uah), 0) FROM {$t_events} WHERE partner_user_id = %d AND event_type = 'sale' AND status = 'approved' AND payout_id = 0",
            $user_id
        ));
        $paid = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount_uah), 0) FROM {$t_payouts} WHERE partner_user_id = %d AND status = 'paid'",
            $user_id
        ));

        return array(
            'visits'     => $visits,
            'downloads'  => $downloads,
            'sales'      => $sales,
            'earned_uah' => round($earned, 2),
            'unpaid_uah' => round($unpaid, 2),
            'paid_uah'   => round($paid, 2),
        );
    }

    public static function handle_join_partner() {
        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url(home_url('/partner/')));
            exit;
        }
        check_admin_referer('uapm_ref_join');
        self::ensure_partner(get_current_user_id());
        wp_safe_redirect(add_query_arg('joined', '1', home_url('/partner/')));
        exit;
    }

    public static function handle_partner_save_details() {
        if (!is_user_logged_in()) {
            wp_die('Увійдіть у свій акаунт.');
        }
        check_admin_referer('uapm_ref_partner_details');
        global $wpdb;
        $uid = get_current_user_id();
        self::ensure_partner($uid);
        $method = sanitize_key(wp_unslash($_POST['payout_method'] ?? 'card'));
        if (!in_array($method, array('card', 'monobank', 'iban', 'privat24'), true)) {
            $method = 'card';
        }
        $details = sanitize_textarea_field(wp_unslash($_POST['payout_details'] ?? ''));
        $wpdb->update(
            self::table('partners'),
            array(
                'payout_method'  => $method,
                'payout_details' => $details,
                'updated_at'     => current_time('mysql'),
            ),
            array('user_id' => $uid)
        );
        $redirect = wp_get_referer() ?: home_url('/partner/');
        wp_safe_redirect(add_query_arg('details_saved', '1', $redirect));
        exit;
    }

    private static function render_frontend_portal_page() {
        status_header(200);
        nocache_headers();
        ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Партнерський кабінет — <?php bloginfo('name'); ?></title>
<?php wp_head(); ?>
<style>
body.uapm-partner-page{background:#f5f6f8;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#1d2327;margin:0;padding:32px 16px}
.uapm-partner-shell{max-width:1040px;margin:0 auto;background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:28px;box-shadow:0 2px 8px rgba(0,0,0,.04)}
.uapm-partner-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin:18px 0}
.uapm-partner-stat{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px}
.uapm-partner-stat strong{display:block;font-size:22px;margin-top:4px;color:#0f172a}
.uapm-partner-table{width:100%;border-collapse:collapse;margin:14px 0}
.uapm-partner-table th,.uapm-partner-table td{border:1px solid #e2e8f0;padding:10px;text-align:left;vertical-align:top}
.uapm-partner-table th{background:#f8fafc}
.uapm-badge-ok{display:inline-block;background:#dcfce7;color:#166534;padding:3px 8px;border-radius:4px;font-weight:600;font-size:12px}
.uapm-badge-due{display:inline-block;background:#fee2e2;color:#991b1b;padding:3px 8px;border-radius:4px;font-weight:600;font-size:12px}
</style>
</head>
<body class="uapm-partner-page">
<div class="uapm-partner-shell">
<?php echo self::render_portal_shortcode(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
</div>
<?php wp_footer(); ?>
</body>
</html><?php
    }

    public static function render_portal_shortcode() {
        ob_start();
        $min_payout = (float) get_option('uapm_ref_min_payout_uah', 1000);
        $payout_day = (int) get_option('uapm_ref_payout_day', 25);

        if (!is_user_logged_in()) {
            ?>
            <div class="uapm-ref-guest">
                <h1>Партнерська програма</h1>
                <p>Запрошуйте користувачів до наших WordPress-плагінів та сервісів за вашим персональним посиланням і отримуйте відсоток з кожної оплати через Freemius.</p>
                <ul>
                    <li>Термін дії cookie: <strong>30 днів</strong>.</li>
                    <li>Мінімальна сума виплати: <strong><?php echo esc_html(number_format_i18n($min_payout, 0)); ?> грн</strong> (виплата <?php echo (int) $payout_day; ?>-го числа кожного місяця).</li>
                </ul>
                <p>
                    <a class="button button-primary" href="<?php echo esc_url(wp_login_url(home_url('/partner/'))); ?>">Увійти в партнерський кабінет</a>
                    <?php if (get_option('users_can_register')) : ?>
                        <a class="button" href="<?php echo esc_url(wp_registration_url()); ?>">Зареєструватися</a>
                    <?php endif; ?>
                </p>
            </div>
            <?php
            return ob_get_clean();
        }

        $uid = get_current_user_id();
        global $wpdb;
        $partner = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table('partners') . " WHERE user_id = %d", $uid));

        if (!$partner) {
            ?>
            <div class="uapm-ref-join">
                <h1>Приєднатися до партнерської програми</h1>
                <p>Після активації ви отримаєте унікальні реферальні посилання <code><?php echo esc_html(home_url('/r' . $uid)); ?></code> для всіх доступних проєктів.</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="uapm_ref_join_partner">
                    <?php wp_nonce_field('uapm_ref_join'); ?>
                    <button type="submit" class="button button-primary">Стати партнером</button>
                </form>
            </div>
            <?php
            return ob_get_clean();
        }

        $summary = self::get_partner_summary($uid);
        $projects = class_exists('UAPM_Projects') ? UAPM_Projects::get_all(array('status' => 'active', 'limit' => 200, 'orderby' => 'name', 'order' => 'ASC')) : array();
        $today_day = (int) current_time('j');
        $payout_ready = $summary['unpaid_uah'] >= $min_payout || ($summary['unpaid_uah'] > 0 && $today_day >= $payout_day);
        ?>
        <div class="uapm-ref-cabinet">
            <h1>Партнерський кабінет</h1>
            <?php if (!empty($_GET['details_saved'])) : ?>
                <p style="background:#dcfce7;color:#166534;padding:10px;border-radius:6px">Реквізити виплати збережено.</p>
            <?php endif; ?>
            <?php if (!empty($_GET['joined'])) : ?>
                <p style="background:#dcfce7;color:#166534;padding:10px;border-radius:6px">Вітаємо! Ваш партнерський акаунт активовано.</p>
            <?php endif; ?>

            <p>Загальне посилання: <input type="text" readonly value="<?php echo esc_attr(home_url('/r' . $uid)); ?>" onclick="this.select()" style="width:320px;max-width:100%"></p>

            <div class="uapm-partner-stats">
                <div class="uapm-partner-stat"><span>Переходи</span><strong><?php echo (int) $summary['visits']; ?></strong></div>
                <div class="uapm-partner-stat"><span>Завантаження (email)</span><strong><?php echo (int) $summary['downloads']; ?></strong></div>
                <div class="uapm-partner-stat"><span>Продажі PRO</span><strong><?php echo (int) $summary['sales']; ?></strong></div>
                <div class="uapm-partner-stat"><span>До виплати</span><strong><?php echo esc_html(number_format_i18n($summary['unpaid_uah'], 2)); ?> грн</strong></div>
                <div class="uapm-partner-stat"><span>Виплачено всього</span><strong><?php echo esc_html(number_format_i18n($summary['paid_uah'], 2)); ?> грн</strong></div>
            </div>

            <p>
                Статус виплати (поріг <?php echo esc_html(number_format_i18n($min_payout, 0)); ?> грн · день <?php echo (int) $payout_day; ?>):
                <?php if ($payout_ready) : ?>
                    <span class="uapm-badge-due">Готово до виплати</span>
                <?php else : ?>
                    <span class="uapm-badge-ok">Накопичення / все сплачено</span>
                <?php endif; ?>
            </p>

            <h2>Партнерські посилання та промо-матеріали за проєктами</h2>
            <table class="uapm-partner-table">
                <thead><tr><th>Проєкт</th><th>Комісія</th><th>Реферальне посилання</th><th>Промо-текст</th></tr></thead>
                <tbody>
                <?php foreach ((array) $projects as $project) :
                    $rate = self::rate_for_project((int) $project->id);
                    if (empty($rate['is_active']) || (float) $rate['commission_percent'] <= 0) {
                        continue;
                    }
                    $ref_link = home_url('/r' . $uid . '/' . $project->slug);
                    $promo = str_replace('{{REF_LINK}}', $ref_link, (string) $rate['promo_text']);
                ?>
                    <tr>
                        <td><strong><?php echo esc_html($project->name); ?></strong></td>
                        <td><?php echo esc_html(number_format_i18n((float) $rate['commission_percent'], 1)); ?>%</td>
                        <td><input type="text" readonly value="<?php echo esc_attr($ref_link); ?>" onclick="this.select()" style="width:100%;min-width:240px"></td>
                        <td><?php echo $promo !== '' ? nl2br(esc_html($promo)) : '—'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h2>Реквізити для отримання виплат</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:520px">
                <input type="hidden" name="action" value="uapm_ref_save_partner_details">
                <?php wp_nonce_field('uapm_ref_partner_details'); ?>
                <p>
                    <label>Спосіб виплати<br>
                    <select name="payout_method">
                        <option value="card" <?php selected($partner->payout_method, 'card'); ?>>Банківська картка</option>
                        <option value="monobank" <?php selected($partner->payout_method, 'monobank'); ?>>Monobank</option>
                        <option value="privat24" <?php selected($partner->payout_method, 'privat24'); ?>>Приват24</option>
                        <option value="iban" <?php selected($partner->payout_method, 'iban'); ?>>IBAN</option>
                    </select></label>
                </p>
                <p>
                    <label>Номер картки / IBAN / коментар<br>
                    <textarea name="payout_details" rows="3" style="width:100%"><?php echo esc_textarea((string) $partner->payout_details); ?></textarea></label>
                </p>
                <button type="submit" class="button button-primary">Зберегти реквізити</button>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function register_menu() {
        add_submenu_page(
            'uapm',
            'Реферальна система',
            'Реферальна система',
            'uapm_manage_projects',
            'uapm-referrals',
            array(__CLASS__, 'render_admin_page')
        );
    }

    public static function handle_save_settings() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостатньо прав.');
        }
        check_admin_referer('uapm_ref_settings');
        $min = max(1, (float) str_replace(',', '.', sanitize_text_field(wp_unslash($_POST['min_payout_uah'] ?? '1000'))));
        $day = min(28, max(1, absint($_POST['payout_day'] ?? 25)));
        update_option('uapm_ref_min_payout_uah', $min, false);
        update_option('uapm_ref_payout_day', $day, false);
        wp_safe_redirect(add_query_arg(array('page' => 'uapm-referrals', 'saved' => 'settings'), admin_url('admin.php')));
        exit;
    }

    public static function handle_save_rates() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостатньо прав.');
        }
        check_admin_referer('uapm_ref_rates');
        global $wpdb;
        $table = self::table('rates');
        $rates = isset($_POST['rates']) && is_array($_POST['rates']) ? wp_unslash($_POST['rates']) : array();
        $now = current_time('mysql');

        foreach ($rates as $project_id => $row) {
            $pid = absint($project_id);
            if (!$pid || !is_array($row)) {
                continue;
            }
            $pct = min(90, max(0, (float) str_replace(',', '.', sanitize_text_field($row['commission_percent'] ?? '0'))));
            $active = !empty($row['is_active']) ? 1 : 0;
            $promo = sanitize_textarea_field($row['promo_text'] ?? '');
            $wpdb->replace($table, array(
                'project_id'         => $pid,
                'commission_percent' => $pct,
                'is_active'          => $active,
                'promo_text'         => $promo,
                'updated_at'         => $now,
            ));
        }

        wp_safe_redirect(add_query_arg(array('page' => 'uapm-referrals', 'saved' => 'rates'), admin_url('admin.php')));
        exit;
    }

    public static function handle_mark_paid() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостатньо прав.');
        }
        $partner_uid = absint($_POST['partner_user_id'] ?? 0);
        check_admin_referer('uapm_ref_mark_paid_' . $partner_uid);
        global $wpdb;
        $partner = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table('partners') . " WHERE user_id = %d", $partner_uid));
        if (!$partner) {
            wp_die('Партнера не знайдено.');
        }
        $t_events = self::table('events');
        $unpaid = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(commission_uah), 0) FROM {$t_events} WHERE partner_user_id = %d AND event_type = 'sale' AND status = 'approved' AND payout_id = 0",
            $partner_uid
        ));
        if ($unpaid <= 0) {
            wp_safe_redirect(add_query_arg(array('page' => 'uapm-referrals', 'saved' => 'nothing_due'), admin_url('admin.php')));
            exit;
        }
        $now = current_time('mysql');
        $wpdb->insert(self::table('payouts'), array(
            'partner_user_id' => $partner_uid,
            'amount_uah'      => round($unpaid, 2),
            'payout_method'   => $partner->payout_method,
            'payout_details'  => $partner->payout_details,
            'reference'       => sanitize_text_field(wp_unslash($_POST['reference'] ?? '')),
            'status'          => 'paid',
            'paid_by'         => get_current_user_id(),
            'paid_at'         => $now,
            'created_at'      => $now,
        ));
        $payout_id = (int) $wpdb->insert_id;
        if ($payout_id) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$t_events} SET payout_id = %d WHERE partner_user_id = %d AND event_type = 'sale' AND status = 'approved' AND payout_id = 0",
                $payout_id, $partner_uid
            ));
        }

        wp_safe_redirect(add_query_arg(array('page' => 'uapm-referrals', 'saved' => 'paid'), admin_url('admin.php')));
        exit;
    }

    public static function render_admin_page() {
        if (!current_user_can('uapm_manage_projects') && !current_user_can('manage_options')) {
            wp_die('Недостатньо прав.');
        }

        global $wpdb;
        $min_payout = (float) get_option('uapm_ref_min_payout_uah', 1000);
        $payout_day = (int) get_option('uapm_ref_payout_day', 25);
        $today_day = (int) current_time('j');

        $projects = class_exists('UAPM_Projects') ? UAPM_Projects::get_all(array('limit' => 200, 'orderby' => 'name', 'order' => 'ASC')) : array();
        $partners = $wpdb->get_results("SELECT * FROM " . self::table('partners') . " ORDER BY id DESC");
        $recent_events = $wpdb->get_results("SELECT * FROM " . self::table('events') . " ORDER BY id DESC LIMIT 40");

        echo '<div class="wrap uapm-admin">';
        if (class_exists('UAPM_Admin')) {
            UAPM_Admin::render_breadcrumb('Реклама', 'Реферальна система');
        }
        echo '<h1>Реферальна система</h1>';
        echo '<p class="description">Публічний кабінет партнерів: <a href="' . esc_url(home_url('/partner/')) . '" target="_blank" rel="noopener">' . esc_html(home_url('/partner/')) . '</a> · Формат посилання: <code>' . esc_html(home_url('/r{USER_ID}/{project-slug}')) . '</code> (шорткод <code>[uapm_referral_portal]</code>).</p>';

        if (!empty($_GET['saved'])) {
            $msg = sanitize_key(wp_unslash($_GET['saved']));
            $map = array(
                'settings'    => 'Параметри виплат збережено.',
                'rates'       => 'Комісії та промо-матеріали по проєктах збережено.',
                'paid'        => 'Виплату партнеру зафіксовано.',
                'nothing_due' => 'Немає несплаченої комісії для цього партнера.',
            );
            if (isset($map[$msg])) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($map[$msg]) . '</p></div>';
            }
        }

        // Settings form
        echo '<h2>Налаштування виплат</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="background:#fff;padding:14px;border:1px solid #ccd0d4;max-width:760px;margin-bottom:20px">';
        echo '<input type="hidden" name="action" value="uapm_ref_save_settings">';
        wp_nonce_field('uapm_ref_settings');
        echo '<label style="margin-right:16px">Мінімальний поріг виплати (грн): <input type="number" min="1" step="1" name="min_payout_uah" value="' . esc_attr((string) $min_payout) . '" style="width:110px"></label>';
        echo '<label style="margin-right:16px">День виплат (число місяця): <input type="number" min="1" max="28" name="payout_day" value="' . (int) $payout_day . '" style="width:70px"></label>';
        submit_button('Зберегти параметри', 'secondary', 'submit', false);
        echo '</form>';

        // Commission rates per project
        echo '<h2>Відсоток комісії та промо-матеріали по проєктах</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="uapm_ref_save_rates">';
        wp_nonce_field('uapm_ref_rates');
        echo '<table class="widefat striped"><thead><tr><th>Проєкт</th><th>Активний</th><th>Комісія (%)</th><th>Промо-текст (плейсхолдер <code>{{REF_LINK}}</code>)</th></tr></thead><tbody>';
        foreach ((array) $projects as $project) {
            $pid = (int) $project->id;
            $r = self::rate_for_project($pid);
            echo '<tr>';
            echo '<td><strong>' . esc_html($project->name) . '</strong><br><code>' . esc_html($project->slug) . '</code></td>';
            echo '<td><input type="checkbox" name="rates[' . $pid . '][is_active]" value="1" ' . checked(!empty($r['is_active']), true, false) . '></td>';
            echo '<td><input type="number" min="0" max="90" step="0.5" name="rates[' . $pid . '][commission_percent]" value="' . esc_attr((string) $r['commission_percent']) . '" style="width:85px"> %</td>';
            echo '<td><textarea name="rates[' . $pid . '][promo_text]" rows="2" class="large-text">' . esc_textarea((string) $r['promo_text']) . '</textarea></td>';
            echo '</tr>';
        }
        if (!$projects) {
            echo '<tr><td colspan="4">Спочатку створіть проєкти.</td></tr>';
        }
        echo '</tbody></table>';
        submit_button('Зберегти ставки проєктів');
        echo '</form>';

        // Partners & payouts table (Red = due for payout, Green = paid / below threshold)
        echo '<h2>Партнери та виплати</h2>';
        echo '<table class="widefat striped"><thead><tr><th>Партнер</th><th>Реквізити</th><th>Переходи</th><th>Завантаження</th><th>Продажі</th><th>До виплати</th><th>Виплачено</th><th>Статус</th><th>Дія</th></tr></thead><tbody>';
        foreach ((array) $partners as $partner) {
            $uid = (int) $partner->user_id;
            $user = get_userdata($uid);
            $summary = self::get_partner_summary($uid);
            $due = $summary['unpaid_uah'] >= $min_payout || ($summary['unpaid_uah'] > 0 && $today_day >= $payout_day);
            $badge = $due
                ? '<span style="display:inline-block;background:#fee2e2;color:#991b1b;padding:3px 8px;border-radius:4px;font-weight:600">Червоний: до виплати</span>'
                : '<span style="display:inline-block;background:#dcfce7;color:#166534;padding:3px 8px;border-radius:4px;font-weight:600">Зелений: сплачено / очікує порогу</span>';
            echo '<tr>';
            echo '<td><strong>' . esc_html($user ? $user->display_name : ('#' . $uid)) . '</strong><br><small>' . esc_html($user ? $user->user_email : '') . '</small><br><code>' . esc_html(home_url('/r' . $uid)) . '</code></td>';
            echo '<td>' . esc_html($partner->payout_method) . '<br><small>' . esc_html((string) $partner->payout_details) . '</small></td>';
            echo '<td>' . (int) $summary['visits'] . '</td>';
            echo '<td>' . (int) $summary['downloads'] . '</td>';
            echo '<td>' . (int) $summary['sales'] . '</td>';
            echo '<td><strong>' . esc_html(number_format_i18n($summary['unpaid_uah'], 2)) . ' грн</strong></td>';
            echo '<td>' . esc_html(number_format_i18n($summary['paid_uah'], 2)) . ' грн</td>';
            echo '<td>' . $badge . '</td>';
            echo '<td>';
            if ($summary['unpaid_uah'] > 0) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Позначити всю накопичену комісію як виплачену?\');">';
                echo '<input type="hidden" name="action" value="uapm_ref_mark_paid">';
                echo '<input type="hidden" name="partner_user_id" value="' . $uid . '">';
                wp_nonce_field('uapm_ref_mark_paid_' . $uid);
                echo '<input type="text" name="reference" placeholder="Коментар / № транзакції" style="width:150px;margin-bottom:4px"><br>';
                echo '<button type="submit" class="button button-small button-primary">Позначити виплаченим</button>';
                echo '</form>';
            } else {
                echo '—';
            }
            echo '</td>';
            echo '</tr>';
        }
        if (!$partners) {
            echo '<tr><td colspan="9">Партнерів поки немає.</td></tr>';
        }
        echo '</tbody></table>';

        // Recent events
        echo '<h2>Останні події рефералів</h2>';
        echo '<table class="widefat striped"><thead><tr><th>Дата</th><th>Партнер</th><th>Подія</th><th>Проєкт</th><th>Email</th><th>Сума</th><th>Комісія</th><th>Статус</th></tr></thead><tbody>';
        foreach ((array) $recent_events as $ev) {
            $proj = $ev->project_id && class_exists('UAPM_Projects') ? UAPM_Projects::get((int) $ev->project_id) : null;
            echo '<tr>';
            echo '<td>' . esc_html($ev->created_at) . '</td>';
            echo '<td>#' . (int) $ev->partner_user_id . '</td>';
            echo '<td>' . esc_html($ev->event_type) . '</td>';
            echo '<td>' . esc_html($proj ? $proj->name : ($ev->project_id ? '#' . $ev->project_id : '—')) . '</td>';
            echo '<td>' . esc_html($ev->email ?: '—') . '</td>';
            echo '<td>' . ((float) $ev->gross_amount > 0 ? esc_html(number_format_i18n((float) $ev->gross_amount, 2) . ' ' . $ev->currency) : '—') . '</td>';
            echo '<td>' . ((float) $ev->commission_uah > 0 ? esc_html(number_format_i18n((float) $ev->commission_uah, 2) . ' грн') : '—') . '</td>';
            echo '<td>' . esc_html($ev->status) . '</td>';
            echo '</tr>';
        }
        if (!$recent_events) {
            echo '<tr><td colspan="8">Подій поки немає.</td></tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
    }
}
