<?php
if (!defined('ABSPATH')) {
    exit;
}

class UAPM_GitHub {

    const API_URL = 'https://api.github.com';

    public static function init() {
        add_action('admin_post_uapm_github_save', array(__CLASS__, 'handle_save'));
        add_action('admin_post_uapm_github_check', array(__CLASS__, 'handle_check'));
    }

    public static function get_settings() {
        $settings = get_option('uapm_github_settings', array());

        return wp_parse_args($settings, array(
            'enabled' => 0,
            'username' => 'portallcomua',
            'token' => '',
            'timeout' => 20
        ));
    }

    public static function save_settings($settings) {
        $current = self::get_settings();

        $updated = array(
            'enabled' => !empty($settings['enabled']) ? 1 : 0,
            'username' => isset($settings['username'])
                ? sanitize_text_field($settings['username'])
                : $current['username'],
            'token' => isset($settings['token']) && $settings['token'] !== ''
                ? sanitize_text_field($settings['token'])
                : $current['token'],
            'timeout' => 20
        );

        update_option('uapm_github_settings', $updated, false);

        return true;
    }

    public static function request($endpoint) {
        $settings = self::get_settings();

        if (empty($settings['enabled'])) {
            return new WP_Error(
                'uapm_github_disabled',
                'Інтеграцію GitHub вимкнено.'
            );
        }

        $endpoint = ltrim((string) $endpoint, '/');

        if (strpos($endpoint, '..') !== false) {
            return new WP_Error(
                'uapm_github_endpoint',
                'Некоректний GitHub API endpoint.'
            );
        }

        $url = self::API_URL . '/' . $endpoint;

        $headers = array(
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent' => 'UA-Project-Manager'
        );

        if (!empty($settings['token'])) {
            $headers['Authorization'] = 'Bearer ' . $settings['token'];
        }

        $response = wp_remote_get($url, array(
            'headers' => $headers,
            'timeout' => absint($settings['timeout']),
            'redirection' => 3
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($status < 200 || $status >= 300) {
            $message = isset($data['message'])
                ? sanitize_text_field($data['message'])
                : 'GitHub API повернув помилку.';

            return new WP_Error(
                'uapm_github_http_' . $status,
                $message,
                array('status' => $status)
            );
        }

        if (!is_array($data)) {
            return new WP_Error(
                'uapm_github_invalid_response',
                'GitHub повернув некоректну відповідь.'
            );
        }

        return $data;
    }

    public static function get_repository($repository) {
        $repository = self::normalize_repository($repository);

        if (is_wp_error($repository)) {
            return $repository;
        }

        return self::request('repos/' . $repository);
    }

    public static function get_releases($repository, $limit = 20) {
        $repository = self::normalize_repository($repository);

        if (is_wp_error($repository)) {
            return $repository;
        }

        $limit = max(1, min(100, absint($limit)));

        return self::request(
            'repos/' . $repository . '/releases?per_page=' . $limit
        );
    }

    public static function get_latest_release($repository) {
        $repository = self::normalize_repository($repository);

        if (is_wp_error($repository)) {
            return $repository;
        }

        return self::request(
            'repos/' . $repository . '/releases/latest'
        );
    }

    public static function get_tags($repository, $limit = 30) {
        $repository = self::normalize_repository($repository);

        if (is_wp_error($repository)) {
            return $repository;
        }

        $limit = max(1, min(100, absint($limit)));

        return self::request(
            'repos/' . $repository . '/tags?per_page=' . $limit
        );
    }

    public static function normalize_repository($repository) {
        $repository = trim((string) $repository);
        $repository = preg_replace('#^https?://github\.com/#i', '', $repository);
        $repository = trim($repository, "/ \t\n\r\0\x0B");

        if (!preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repository)) {
            return new WP_Error(
                'uapm_invalid_repository',
                'Вкажіть GitHub-репозиторій у форматі username/repository.'
            );
        }

        return $repository;
    }

    public static function get_release_assets($release) {
        if (empty($release['assets']) || !is_array($release['assets'])) {
            return array();
        }

        $assets = array();

        foreach ($release['assets'] as $asset) {
            if (empty($asset['browser_download_url'])) {
                continue;
            }

            $url = esc_url_raw($asset['browser_download_url']);

            if (strpos($url, 'https://github.com/') !== 0) {
                continue;
            }

            $assets[] = array(
                'id' => isset($asset['id']) ? absint($asset['id']) : 0,
                'name' => isset($asset['name'])
                    ? sanitize_file_name($asset['name'])
                    : '',
                'size' => isset($asset['size']) ? absint($asset['size']) : 0,
                'download_count' => isset($asset['download_count'])
                    ? absint($asset['download_count'])
                    : 0,
                'download_url' => $url,
                'content_type' => isset($asset['content_type'])
                    ? sanitize_text_field($asset['content_type'])
                    : ''
            );
        }

        return $assets;
    }

    public static function sync_project($project_id) {
        if (!class_exists('UAPM_Projects')) {
            return new WP_Error(
                'uapm_projects_missing',
                'Модуль проєктів недоступний.'
            );
        }

        $project = UAPM_Projects::get(absint($project_id));

        if (!$project) {
            return new WP_Error(
                'uapm_project_missing',
                'Проєкт не знайдено.'
            );
        }

        if (empty($project->github_repo)) {
            return new WP_Error(
                'uapm_repository_missing',
                'Для проєкту не вказано GitHub-репозиторій.'
            );
        }

        $releases = self::get_releases($project->github_repo, 50);

        if (is_wp_error($releases)) {
            return $releases;
        }

        global $wpdb;

        $table = $wpdb->prefix . 'uapm_releases';
        $synced = 0;

        foreach ($releases as $release) {
            if (empty($release['tag_name'])) {
                continue;
            }

            $tag = sanitize_text_field($release['tag_name']);

            $existing = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$table} WHERE project_id = %d AND tag_name = %s LIMIT 1",
                    absint($project_id),
                    $tag
                )
            );

            $assets = self::get_release_assets($release);

            $data = array(
                'project_id' => absint($project_id),
                'tag_name' => $tag,
                'name' => isset($release['name'])
                    ? sanitize_text_field($release['name'])
                    : $tag,
                'body' => isset($release['body'])
                    ? wp_kses_post($release['body'])
                    : '',
                'html_url' => isset($release['html_url'])
                    ? esc_url_raw($release['html_url'])
                    : '',
                'zipball_url' => isset($release['zipball_url'])
                    ? esc_url_raw($release['zipball_url'])
                    : '',
                'published_at' => isset($release['published_at'])
                    ? sanitize_text_field($release['published_at'])
                    : null,
                'is_prerelease' => !empty($release['prerelease']) ? 1 : 0,
                'is_draft' => !empty($release['draft']) ? 1 : 0,
                'assets_json' => wp_json_encode($assets),
                'github_release_id' => isset($release['id'])
                    ? absint($release['id'])
                    : 0,
                'updated_at' => current_time('mysql')
            );

            if ($existing) {
                $wpdb->update(
                    $table,
                    $data,
                    array('id' => absint($existing))
                );
            } else {
                $data['created_at'] = current_time('mysql');
                $wpdb->insert($table, $data);
            }

            $synced++;
        }

        update_post_meta(0, '_uapm_unused', '');

        do_action('uapm_github_releases_synced', $project_id, $synced);

        return array(
            'project_id' => absint($project_id),
            'synced' => $synced,
            'checked_at' => current_time('mysql')
        );
    }

    public static function handle_save() {
        if (!current_user_can('uapm_manage_settings')) {
            wp_die('Недостатньо прав.');
        }

        check_admin_referer('uapm_github_save');

        self::save_settings(array(
            'enabled' => isset($_POST['enabled']),
            'username' => isset($_POST['username'])
                ? wp_unslash($_POST['username'])
                : '',
            'token' => isset($_POST['token'])
                ? wp_unslash($_POST['token'])
                : ''
        ));

        wp_safe_redirect(add_query_arg(
            array(
                'page' => 'uapm-github',
                'saved' => 1
            ),
            admin_url('admin.php')
        ));

        exit;
    }

    public static function handle_check() {
        if (!current_user_can('uapm_manage_settings')) {
            wp_die('Недостатньо прав.');
        }

        check_admin_referer('uapm_github_check');

        $settings = self::get_settings();

        $result = self::request('user');

        set_transient(
            'uapm_github_connection_' . get_current_user_id(),
            is_wp_error($result)
                ? $result->get_error_message()
                : 'Підключення успішне. GitHub-користувач: ' . (
                    isset($result['login']) ? $result['login'] : 'невідомий'
                ),
            MINUTE_IN_SECONDS * 5
        );

        wp_safe_redirect(add_query_arg(
            array(
                'page' => 'uapm-github',
                'checked' => 1
            ),
            admin_url('admin.php')
        ));

        exit;
    }

    public static function render_page() {
        if (!current_user_can('uapm_manage_settings')) {
            wp_die('Недостатньо прав.');
        }

        $settings = self::get_settings();
        $message = get_transient(
            'uapm_github_connection_' . get_current_user_id()
        );

        ?>
        <div class="wrap">
            <h1>GitHub Integration</h1>

            <?php if (isset($_GET['saved'])) : ?>
                <div class="notice notice-success">
                    <p>Налаштування збережено.</p>
                </div>
            <?php endif; ?>

            <?php if ($message) : ?>
                <div class="notice notice-info">
                    <p><?php echo esc_html($message); ?></p>
                </div>
                <?php
                delete_transient(
                    'uapm_github_connection_' . get_current_user_id()
                );
                ?>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="uapm_github_save">
                <?php wp_nonce_field('uapm_github_save'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">Увімкнути GitHub</th>
                        <td>
                            <label>
                                <input
                                    type="checkbox"
                                    name="enabled"
                                    value="1"
                                    <?php checked($settings['enabled'], 1); ?>
                                >
                                Активувати інтеграцію
                            </label>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">GitHub username</th>
                        <td>
                            <input
                                type="text"
                                name="username"
                                class="regular-text"
                                value="<?php echo esc_attr($settings['username']); ?>"
                            >
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Personal Access Token</th>
                        <td>
                            <input
                                type="password"
                                name="token"
                                class="regular-text"
                                value=""
                                autocomplete="new-password"
                                placeholder="<?php echo esc_attr(
                                    $settings['token']
                                        ? 'Токен збережено. Залиште порожнім, щоб не змінювати.'
                                        : 'Необов’язково для публічних репозиторіїв.'
                                ); ?>"
                            >
                            <p class="description">
                                Для приватних репозиторіїв потрібен токен із мінімальними правами читання.
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Зберегти налаштування'); ?>
            </form>

            <hr>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="uapm_github_check">
                <?php wp_nonce_field('uapm_github_check'); ?>
                <?php submit_button('Перевірити підключення', 'secondary'); ?>
            </form>
        </div>
        <?php
    }
}

UAPM_GitHub::init();