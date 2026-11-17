<?php
/**
 * UA Project Manager — Security.
 *
 * @package UA_Project_Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

class UAPM_Security {

    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_rest_permissions'));
    }

    public static function verify_admin() {
        if (!current_user_can('manage_options')) {
            wp_die(
                esc_html__('Недостатньо прав доступу.', 'ua-project-manager'),
                '',
                array('response' => 403)
            );
        }
    }

    public static function verify_capability($capability) {
        $capability = sanitize_key($capability);

        if (!$capability || !current_user_can($capability)) {
            return new WP_Error(
                'uapm_forbidden',
                __('Недостатньо прав доступу.', 'ua-project-manager'),
                array('status' => 403)
            );
        }

        return true;
    }

    public static function verify_nonce($nonce, $action = 'uapm_admin_action') {
        return wp_verify_nonce(
            sanitize_text_field(wp_unslash($nonce)),
            $action
        );
    }

    public static function create_token($length = 48) {
        $length = max(16, min(128, absint($length)));
        $bytes = random_bytes((int) ceil($length / 2));

        return substr(bin2hex($bytes), 0, $length);
    }

    public static function hash_token($token) {
        return hash_hmac(
            'sha256',
            (string) $token,
            wp_salt('auth')
        );
    }

    public static function verify_token($token, $stored_hash) {
        if (!is_string($token) || !is_string($stored_hash)) {
            return false;
        }

        return hash_equals(
            $stored_hash,
            self::hash_token($token)
        );
    }

    public static function generate_signature($data, $secret) {
        if (!is_scalar($data) || !is_string($secret) || $secret === '') {
            return '';
        }

        return hash_hmac('sha256', (string) $data, $secret);
    }

    public static function verify_signature($data, $signature, $secret) {
        if (!is_string($signature) || $signature === '') {
            return false;
        }

        $expected = self::generate_signature($data, $secret);

        return $expected !== '' && hash_equals($expected, $signature);
    }

    public static function rate_limit($key, $limit = 10, $period = 60) {
        $key = sanitize_key($key);

        if (!$key) {
            return false;
        }

        $user_id = get_current_user_id();
        $ip = self::get_ip_address();

        $identifier = hash(
            'sha256',
            $key . '|' . $user_id . '|' . $ip
        );

        $transient_key = 'uapm_rl_' . substr($identifier, 0, 40);
        $data = get_transient($transient_key);

        $limit = max(1, absint($limit));
        $period = max(1, absint($period));

        if (!is_array($data)) {
            set_transient(
                $transient_key,
                array(
                    'count' => 1,
                    'started' => time(),
                ),
                $period
            );

            return true;
        }

        if (time() - absint($data['started']) >= $period) {
            set_transient(
                $transient_key,
                array(
                    'count' => 1,
                    'started' => time(),
                ),
                $period
            );

            return true;
        }

        if (absint($data['count']) >= $limit) {
            return false;
        }

        $data['count']++;

        set_transient($transient_key, $data, $period);

        return true;
    }

    public static function get_ip_address() {
        $ip = isset($_SERVER['REMOTE_ADDR'])
            ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
            : '';

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

    public static function sanitize_url($url) {
        $url = esc_url_raw($url);

        if (!$url) {
            return '';
        }

        $scheme = wp_parse_url($url, PHP_URL_SCHEME);

        if (!in_array(strtolower((string) $scheme), array('http', 'https'), true)) {
            return '';
        }

        return $url;
    }

    public static function safe_redirect($url, $fallback = '') {
        $url = self::sanitize_url($url);

        if (!$url) {
            $url = $fallback ? self::sanitize_url($fallback) : admin_url();
        }

        wp_safe_redirect($url);
        exit;
    }

    public static function register_rest_permissions() {
        do_action('uapm_register_rest_permissions');
    }

    public static function rest_admin_permission() {
        return current_user_can('manage_options');
    }

    public static function rest_manager_permission() {
        return is_user_logged_in() &&
            class_exists('UAPM_Roles') &&
            UAPM_Roles::can_manage_own_clients();
    }

    public static function sanitize_payload($payload) {
        if (!is_array($payload)) {
            return array();
        }

        $clean = array();

        foreach ($payload as $key => $value) {
            $key = sanitize_key($key);

            if (is_array($value)) {
                $clean[$key] = self::sanitize_payload($value);
            } elseif (is_email($value)) {
                $clean[$key] = sanitize_email($value);
            } elseif (is_scalar($value)) {
                $clean[$key] = sanitize_text_field((string) $value);
            }
        }

        return $clean;
    }
}

UAPM_Security::init();
