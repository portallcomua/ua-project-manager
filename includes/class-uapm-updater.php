<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * GitHub Releases updater for UA Project Manager.
 *
 * Supports the native WordPress Update URI API (WordPress 5.8+)
 * and keeps the legacy transient filter as a compatibility fallback.
 */
class UAPM_Updater {

    private static $cache = array();
    private const REPOSITORY = 'portallcomua/ua-project-manager';
    private const CACHE_TTL = 900;
    private const CACHE_KEY = 'uapm_github_release_113';

    public static function init() {
        // Native WordPress Update URI API for github.com.
        add_filter(
            'update_plugins_github.com',
            array(__CLASS__, 'github_update'),
            10,
            4
        );

        // Compatibility fallback for older WordPress installations.
        add_filter(
            'pre_set_site_transient_update_plugins',
            array(__CLASS__, 'check_updates')
        );

        add_filter('plugins_api', array(__CLASS__, 'plugin_information'), 20, 3);
        add_action('upgrader_process_complete', array(__CLASS__, 'clear_cache'), 10, 2);
    }

    private static function get_repository_from_plugin_data($plugin_data) {
        $update_uri = isset($plugin_data['UpdateURI'])
            ? trim((string) $plugin_data['UpdateURI'])
            : '';

        if ($update_uri === '') {
            return '';
        }

        $host = strtolower((string) wp_parse_url($update_uri, PHP_URL_HOST));
        $path = trim((string) wp_parse_url($update_uri, PHP_URL_PATH), '/');

        if ($host !== 'github.com') {
            return '';
        }

        $path = preg_replace('#\.git$#i', '', $path);

        if (strcasecmp($path, self::REPOSITORY) !== 0) {
            return '';
        }

        return self::REPOSITORY;
    }

    private static function get_remote_release($repository = self::REPOSITORY, $force = false) {
        $repository = trim(sanitize_text_field($repository), '/');

        if (!preg_match('/^[a-zA-Z0-9_.-]+\/[a-zA-Z0-9_.-]+$/', $repository)) {
            return false;
        }

        if (!$force && array_key_exists($repository, self::$cache)) {
            return self::$cache[$repository];
        }

        $transient_key = self::CACHE_KEY . '_' . md5($repository);

        if (!$force) {
            $cached = get_transient($transient_key);

            if (is_array($cached) && !empty($cached['tag_name'])) {
                self::$cache[$repository] = $cached;
                return $cached;
            }
        }

        $settings = class_exists('UAPM_Settings')
            ? UAPM_Settings::get_all()
            : array();

        $headers = array(
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => 'UA-Project-Manager/' . (defined('UAPM_VERSION') ? UAPM_VERSION : '1.1.3'),
            'X-GitHub-Api-Version' => '2022-11-28',
        );

        if (!empty($settings['github_token'])) {
            $headers['Authorization'] = 'Bearer ' . sanitize_text_field($settings['github_token']);
        }

        $url = 'https://api.github.com/repos/' . rawurlencode(explode('/', $repository)[0])
            . '/' . rawurlencode(explode('/', $repository)[1]) . '/releases/latest';

        $response = wp_safe_remote_get($url, array(
            'timeout' => 20,
            'redirection' => 3,
            'headers' => $headers,
        ));

        if (is_wp_error($response)) {
            self::$cache[$repository] = false;
            return false;
        }

        $status = (int) wp_remote_retrieve_response_code($response);

        if ($status !== 200) {
            self::$cache[$repository] = false;
            return false;
        }

        $release = json_decode(wp_remote_retrieve_body($response), true);

        if (
            !is_array($release) ||
            empty($release['tag_name']) ||
            !empty($release['draft']) ||
            !empty($release['prerelease'])
        ) {
            self::$cache[$repository] = false;
            return false;
        }

        self::$cache[$repository] = $release;
        set_transient($transient_key, $release, self::CACHE_TTL);

        return $release;
    }

    private static function get_release_version($release) {
        if (empty($release['tag_name'])) {
            return '';
        }

        $version = ltrim(sanitize_text_field($release['tag_name']), 'vV');

        return preg_match('/^\d+(?:\.\d+){1,3}(?:[-+][a-zA-Z0-9.-]+)?$/', $version)
            ? $version
            : '';
    }

    /**
     * Prefer the stable ZIP asset whose filename never changes.
     * Fall back to the versioned release ZIP, then GitHub's source archive.
     */
    private static function get_download_url($release) {
        if (!empty($release['assets']) && is_array($release['assets'])) {
            $preferred = array(
                'ua-project-manager.zip',
            );

            $version = self::get_release_version($release);

            if ($version !== '') {
                $preferred[] = 'ua-project-manager-' . $version . '.zip';
            }

            foreach ($preferred as $wanted_name) {
                foreach ($release['assets'] as $asset) {
                    if (
                        !empty($asset['name']) &&
                        $asset['name'] === $wanted_name &&
                        !empty($asset['browser_download_url'])
                    ) {
                        return esc_url_raw($asset['browser_download_url']);
                    }
                }
            }
        }

        if (!empty($release['zipball_url'])) {
            return esc_url_raw($release['zipball_url']);
        }

        return '';
    }

    private static function build_update_response($plugin_file, $plugin_data, $release) {
        $version = self::get_release_version($release);
        $package = self::get_download_url($release);

        if ($version === '' || $package === '') {
            return false;
        }

        return (object) array(
            'id' => 'https://github.com/' . self::REPOSITORY,
            'slug' => dirname($plugin_file),
            'plugin' => $plugin_file,
            'version' => $version,
            'new_version' => $version,
            'url' => !empty($release['html_url'])
                ? esc_url_raw($release['html_url'])
                : 'https://github.com/' . self::REPOSITORY . '/releases/latest',
            'package' => $package,
            'tested' => '',
            'requires' => !empty($plugin_data['RequiresWP']) ? $plugin_data['RequiresWP'] : '6.0',
            'requires_php' => !empty($plugin_data['RequiresPHP']) ? $plugin_data['RequiresPHP'] : '7.4',
            'icons' => array(),
            'banners' => array(),
        );
    }

    /**
     * Native Update URI callback.
     *
     * WordPress calls this filter for plugins declaring a github.com Update URI.
     */
    public static function github_update($update, $plugin_data, $plugin_file, $locales = array()) {
        if (self::get_repository_from_plugin_data($plugin_data) !== self::REPOSITORY) {
            return $update;
        }

        $release = self::get_remote_release(self::REPOSITORY);

        if (!$release) {
            return $update;
        }

        $remote_version = self::get_release_version($release);
        $local_version = !empty($plugin_data['Version'])
            ? sanitize_text_field($plugin_data['Version'])
            : '0.0.0';

        if ($remote_version === '' || !version_compare($remote_version, $local_version, '>')) {
            return false;
        }

        return self::build_update_response($plugin_file, $plugin_data, $release) ?: $update;
    }

    /**
     * Legacy fallback. The native Update URI filter above is the primary path.
     */
    public static function check_updates($transient) {
        if (!is_object($transient) || empty($transient->checked) || !is_array($transient->checked)) {
            return $transient;
        }

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins = get_plugins();

        foreach ($plugins as $plugin_file => $plugin_data) {
            if (defined('UAPM_BASENAME') && $plugin_file !== UAPM_BASENAME) {
                continue;
            }

            if (self::get_repository_from_plugin_data($plugin_data) !== self::REPOSITORY) {
                continue;
            }

            $release = self::get_remote_release(self::REPOSITORY);

            if (!$release) {
                continue;
            }

            $remote_version = self::get_release_version($release);
            $local_version = isset($plugin_data['Version']) ? $plugin_data['Version'] : '0.0.0';

            if ($remote_version === '' || !version_compare($remote_version, $local_version, '>')) {
                continue;
            }

            $update = self::build_update_response($plugin_file, $plugin_data, $release);

            if ($update) {
                if (!isset($transient->response) || !is_array($transient->response)) {
                    $transient->response = array();
                }
                $transient->response[$plugin_file] = $update;
            }
        }

        return $transient;
    }

    public static function plugin_information($result, $action, $args) {
        if ($action !== 'plugin_information' || empty($args->slug)) {
            return $result;
        }

        $requested_slug = sanitize_key($args->slug);
        $valid_slugs = array('ua-project-manager', 'github-com-portallcomua-ua-project-manager');

        if (!in_array($requested_slug, $valid_slugs, true)) {
            return $result;
        }

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins = get_plugins();
        $plugin_file = defined('UAPM_BASENAME') ? UAPM_BASENAME : 'ua-project-manager/ua-project-manager.php';

        if (empty($plugins[$plugin_file])) {
            return $result;
        }

        $plugin_data = $plugins[$plugin_file];

        if (self::get_repository_from_plugin_data($plugin_data) !== self::REPOSITORY) {
            return $result;
        }

        $release = self::get_remote_release(self::REPOSITORY);

        if (!$release) {
            return $result;
        }

        $version = self::get_release_version($release);
        $download_link = self::get_download_url($release);

        if ($version === '' || $download_link === '') {
            return $result;
        }

        return (object) array(
            'name' => !empty($plugin_data['Name']) ? $plugin_data['Name'] : 'UA Project Manager',
            'slug' => 'ua-project-manager',
            'version' => $version,
            'author' => !empty($plugin_data['Author']) ? $plugin_data['Author'] : 'UA Tools',
            'homepage' => !empty($release['html_url']) ? esc_url_raw($release['html_url']) : 'https://github.com/' . self::REPOSITORY,
            'download_link' => $download_link,
            'requires' => !empty($plugin_data['RequiresWP']) ? $plugin_data['RequiresWP'] : '6.0',
            'requires_php' => !empty($plugin_data['RequiresPHP']) ? $plugin_data['RequiresPHP'] : '7.4',
            'sections' => array(
                'description' => !empty($plugin_data['Description']) ? $plugin_data['Description'] : '',
                'changelog' => !empty($release['body']) ? wp_kses_post($release['body']) : '',
            ),
        );
    }

    public static function clear_cache($upgrader, $options) {
        if (
            !empty($options['action']) &&
            $options['action'] === 'update' &&
            (!isset($options['type']) || $options['type'] === 'plugin')
        ) {
            self::$cache = array();
            delete_transient(self::CACHE_KEY . '_' . md5(self::REPOSITORY));
            delete_site_transient('update_plugins');
        }
    }
}
