<?php
if (!defined('ABSPATH')) {
    exit;
}

class UAPM_Security {

    public static function init() {
        add_filter('wp_headers', array(__CLASS__, 'security_headers'));
    }

    public static function nonce_field($action, $name = '_uapm_nonce') {
        wp_nonce_field($action, $name);
    }

    public static function verify_admin_request($action, $nonce_name = '_uapm_nonce') {
        if (!current_user_can('manage_options') &&
            !current_user_can('uapm_manage_projects')) {
            wp_die(
                esc_html__('Недостатньо прав для виконання цієї дії.', 'ua-project-manager'),
                '',
                array('response' => 403)
            );
        }

        $nonce = isset($_POST[$nonce_name])
            ? sanitize_text_field(wp_unslash($_POST[$nonce_name]))
            : '';

        if (!$nonce || !wp_verify_nonce($nonce, $action)) {
            wp_die(
                esc_html__('Перевірка безпеки не пройдена. Оновіть сторінку та повторіть дію.', 'ua-project-manager'),
                '',
                array('response' => 403)
            );
        }

        return true;
    }

    public static function verify_rest_permission($request) {
        if (!is_user_logged_in()) {
            return new WP_Error(
                'uapm_auth_required',
                'Потрібна авторизація.',
                array('status' => 401)
            );
        }

        if (!current_user_can('uapm_manage_projects')) {
            return new WP_Error(
                'uapm_forbidden',
                'Недостатньо прав.',
                array('status' => 403)
            );
        }

        return true;
    }

    public static function generate_token($length = 48) {
        $length = max(16, min(128, absint($length)));

        try {
            return bin2hex(random_bytes((int) ceil($length / 2)));
        } catch (Exception $exception) {
            return wp_generate_password($length, false, false);
        }
    }

    public static function hash_token($token) {
        return hash('sha256', (string) $token);
    }

    public static function verify_token($token, $stored_hash) {
        if (!$token || !$stored_hash) {
            return false;
        }

        return hash_equals(
            (string) $stored_hash,
            self::hash_token($token)
        );
    }

    public static function create_signature($payload, $secret) {
        if (is_array($payload) || is_object($payload)) {
            $payload = wp_json_encode($payload);
        }

        return hash_hmac(
            'sha256',
            (string) $payload,
            (string) $secret
        );
    }

    public static function verify_signature($payload, $signature, $secret) {
        if (!$signature || !$secret) {
            return false;
        }

        $expected = self::create_signature($payload, $secret);

        return hash_equals($expected, (string) $signature);
    }

    public static function check_rate_limit($key, $limit = 10, $period = 60) {
        $key = sanitize_key($key);

        if (!$key) {
            return false;
        }

        $limit = max(1, absint($limit));
        $period = max(1, absint($period));

        $transient_key = 'uapm_rl_' . md5($key);
        $data = get_transient($transient_key);

        if (!is_array($data)) {
            $data = array(
                'count' => 0,
                'started' => time(),
            );
        }

        if ((time() - absint($data['started'])) >= $period) {
            $data = array(
                'count' => 0,
                'started' => time(),
            );
        }

        if (absint($data['count']) >= $limit) {
            return false;
        }

        $data['count']++;

        set_transient($transient_key, $data, $period);

        return true;
    }

    public static function normalize_domain($value) {
        $value = trim((string) $value);

        if (!$value) {
            return '';
        }

        if (strpos($value, '://') === false) {
            $value = 'https://' . $value;
        }

        $host = wp_parse_url($value, PHP_URL_HOST);

        if (!$host) {
            return '';
        }

        $host = strtolower($host);
        $host = preg_replace('/^www\./', '', $host);
        $host = rtrim($host, '.');

        if (strlen($host) > 253) {
            return '';
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return '';
        }

        if (!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $host)) {
            return '';
        }

        return $host;
    }

    public static function sanitize_target($target, $type) {
        $target = trim((string) $target);

        if ($type === 'email') {
            return sanitize_email($target);
        }

        if ($type === 'domain') {
            return self::normalize_domain($target);
        }

        return sanitize_text_field($target);
    }

    public static function safe_redirect($url, $fallback = '') {
        $url = wp_validate_redirect($url, $fallback);

        if (!$url) {
            $url = admin_url();
        }

        wp_safe_redirect($url);
        exit;
    }

    public static function security_headers($headers) {
        if (is_admin()) {
            return $headers;
        }

        $headers['X-Content-Type-Options'] = 'nosniff';
        $headers['X-Frame-Options'] = 'SAMEORIGIN';
        $headers['Referrer-Policy'] = 'strict-origin-when-cross-origin';

        return $headers;
    }

    public static function get_client_ip_hash() {
        $ip = isset($_SERVER['REMOTE_ADDR'])
            ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
            : '';

        if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return '';
        }

        return hash_hmac('sha256', $ip, wp_salt('auth'));
    }

    public static function validate_external_url($url) {
        $url = esc_url_raw(trim((string) $url));

        if (!$url) {
            return false;
        }

        $parts = wp_parse_url($url);

        if (empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }

        if (!in_array(strtolower($parts['scheme']), array('http', 'https'), true)) {
            return false;
        }

        $host = strtolower($parts['host']);

        if ($host === 'localhost' || substr($host, -10) === '.localhost') {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (!filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            )) {
                return false;
            }
        }

        return $url;
    }
}