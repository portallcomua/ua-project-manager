<?php
if (!defined('ABSPATH')) {
    exit;
}

class UAPM_Updater {

    private static $cache = array();
    private const REPOSITORY = 'portallcomua/ua-project-manager';

    public static function init() {
        add_filter('pre_set_site_transient_update_plugins', array(__CLASS__, 'check_updates'));
        add_filter('plugins_api', array(__CLASS__, 'plugin_information'), 20, 3);
        add_action('upgrader_process_complete', array(__CLASS__, 'clear_cache'), 10, 2);
    }

    private static function get_plugin_data($plugin_file) {
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return get_plugin_data($plugin_file, false, false);
    }

    private static function get_remote_release($repository) {
        $repository = sanitize_text_field($repository);

        if (isset(self::$cache[$repository])) {
            return self::$cache[$repository];
        }

        $settings = class_exists('UAPM_Settings')
            ? UAPM_Settings::get_all()
            : array();

        $repository = trim($repository, '/');

        if (!preg_match('/^[a-zA-Z0-9_.-]+\/[a-zA-Z0-9_.-]+$/', $repository)) {
            return false;
        }

        $url = 'https://api.github.com/repos/' . $repository . '/releases/latest';

        $headers = array(
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => 'UA-Project-Manager',
        );

        if (!empty($settings['github_token'])) {
            $headers['Authorization'] = 'Bearer ' . $settings['github_token'];
        }

        $response = wp_remote_get($url, array(
            'timeout' => 15,
            'headers' => $headers,
        ));

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return false;
        }

        $release = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($release['tag_name'])) {
            return false;
        }

        self::$cache[$repository] = $release;

        return $release;
    }

    public static function check_updates($transient) {
        if (empty($transient->checked) || !is_object($transient)) {
            return $transient;
        }

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins = get_plugins();

        foreach ($plugins as $plugin_file => $plugin_data) {
            if (defined('UAPM_BASENAME') && $plugin_file === UAPM_BASENAME) {
                $repository = self::REPOSITORY;
            } else {
                $repository = $plugin_data['UpdateURI'] ?? '';
                if (!$repository) {
                    continue;
                }
                $repository = preg_replace(
                    '#^https?://github\.com/#i',
                    '',
                    trim($repository)
                );
            }

            $release = self::get_remote_release($repository);

            if (!$release) {
                continue;
            }

            $remote_version = ltrim(
                sanitize_text_field($release['tag_name']),
                'vV'
            );

            $local_version = $plugin_data['Version'] ?? '0.0.0';

            if (!version_compare($remote_version, $local_version, '>')) {
                continue;
            }

            $download_url = '';

            if (!empty($release['assets']) && is_array($release['assets'])) {
                foreach ($release['assets'] as $asset) {
                    if (!empty($asset['browser_download_url'])) {
                        $download_url = esc_url_raw($asset['browser_download_url']);
                        break;
                    }
                }
            }

            if (!$download_url && !empty($release['zipball_url'])) {
                $download_url = esc_url_raw($release['zipball_url']);
            }

            if (!$download_url) {
                continue;
            }

            $transient->response[$plugin_file] = (object) array(
                'slug' => dirname($plugin_file),
                'plugin' => $plugin_file,
                'new_version' => $remote_version,
                'url' => esc_url_raw($release['html_url'] ?? ''),
                'package' => $download_url,
                'tested' => '',
                'requires_php' => '',
            );
        }

        return $transient;
    }

    public static function plugin_information($result, $action, $args) {
        if ($action !== 'plugin_information' || empty($args->slug)) {
            return $result;
        }

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins = get_plugins();

        foreach ($plugins as $plugin_file => $plugin_data) {
            if (defined('UAPM_BASENAME') && $plugin_file === UAPM_BASENAME) {
                $repository = self::REPOSITORY;
            } else {
                if (dirname($plugin_file) !== $args->slug) {
                    continue;
                }
                $repository = $plugin_data['UpdateURI'] ?? '';
                if (!$repository) {
                    return $result;
                }
                $repository = preg_replace(
                    '#^https?://github\.com/#i',
                    '',
                    trim($repository)
                );
            }

            $release = self::get_remote_release($repository);

            if (!$release) {
                return $result;
            }

            return (object) array(
                'name' => $plugin_data['Name'],
                'slug' => $args->slug,
                'version' => ltrim($release['tag_name'], 'vV'),
                'author' => $plugin_data['Author'],
                'homepage' => $release['html_url'] ?? '',
                'sections' => array(
                    'description' => $plugin_data['Description'],
                    'changelog' => wp_kses_post($release['body'] ?? ''),
                ),
                'download_link' => self::get_download_url($release),
            );
        }

        return $result;
    }

    private static function get_download_url($release) {
        if (!empty($release['assets']) && is_array($release['assets'])) {
            foreach ($release['assets'] as $asset) {
                if (!empty($asset['browser_download_url'])) {
                    return esc_url_raw($asset['browser_download_url']);
                }
            }
        }

        return esc_url_raw($release['zipball_url'] ?? '');
    }

    public static function clear_cache($upgrader, $options) {
        if (!empty($options['action']) && $options['action'] === 'update') {
            self::$cache = array();
        }
    }
}