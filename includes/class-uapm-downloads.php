<?php
if (!defined('ABSPATH')) {
    exit;
}

class UAPM_Downloads {

    public static function init() {
        add_action('init', array(__CLASS__, 'register_endpoint'));
        add_action('template_redirect', array(__CLASS__, 'handle_download'));
        add_action('admin_post_uapm_download_stats', array(__CLASS__, 'render_stats'));
        add_action('admin_post_nopriv_uapm_download_stats', array(__CLASS__, 'render_stats'));
        add_action('uapm_record_download', array(__CLASS__, 'record_download'), 10, 4);
    }

    public static function install_schema() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = $wpdb->prefix . 'uapm_downloads';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            project_id bigint(20) unsigned NOT NULL DEFAULT 0,
            release_id bigint(20) unsigned NOT NULL DEFAULT 0,
            asset_name varchar(255) NOT NULL DEFAULT '',
            download_url text NOT NULL,
            visitor_hash varchar(64) NOT NULL DEFAULT '',
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            event_type varchar(30) NOT NULL DEFAULT 'click',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY project_id (project_id),
            KEY release_id (release_id),
            KEY event_type (event_type),
            KEY created_at (created_at)
        ) {$charset};";

        dbDelta($sql);
    }

    public static function register_endpoint() {
        add_rewrite_rule(
            '^uapm-download/([0-9]+)/?$',
            'index.php?uapm_download_id=$matches[1]',
            'top'
        );

        add_rewrite_tag('%uapm_download_id%', '([0-9]+)');
    }

    public static function handle_download() {
        $download_id = absint(get_query_var('uapm_download_id'));

        if (!$download_id) {
            return;
        }

        $download = self::get_download_source($download_id);

        if (!$download) {
            wp_die(
                'Посилання на завантаження не знайдено.',
                'Завантаження недоступне',
                array('response' => 404)
            );
        }

        do_action(
            'uapm_record_download',
            absint($download['project_id']),
            absint($download['release_id']),
            $download['asset_name'],
            $download['download_url']
        );

        wp_safe_redirect($download['download_url'], 302);
        exit;
    }

    public static function get_download_source($asset_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'uapm_releases';

        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SHOW TABLES LIKE %s",
                $wpdb->esc_like($table)
            )
        );

        if ($exists !== $table) {
            return false;
        }

        $release = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d LIMIT 1",
                absint($asset_id)
            ),
            ARRAY_A
        );

        if (!$release || empty($release['assets_json'])) {
            return false;
        }

        $assets = json_decode($release['assets_json'], true);

        if (!is_array($assets)) {
            return false;
        }

        foreach ($assets as $asset) {
            if (
                isset($asset['id']) &&
                absint($asset['id']) === absint($asset_id) &&
                !empty($asset['download_url'])
            ) {
                $url = esc_url_raw($asset['download_url']);

                if (strpos($url, 'https://github.com/') !== 0) {
                    return false;
                }

                return array(
                    'project_id' => absint($release['project_id']),
                    'release_id' => absint($release['id']),
                    'asset_name' => isset($asset['name'])
                        ? sanitize_file_name($asset['name'])
                        : '',
                    'download_url' => $url
                );
            }
        }

        return false;
    }

    public static function record_download(
        $project_id,
        $release_id,
        $asset_name,
        $download_url
    ) {
        global $wpdb;

        $table = $wpdb->prefix . 'uapm_downloads';

        $url = esc_url_raw($download_url);

        if (strpos($url, 'https://github.com/') !== 0) {
            return false;
        }

        $ip = isset($_SERVER['REMOTE_ADDR'])
            ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
            : '';

        $visitor_hash = hash_hmac(
            'sha256',
            $ip . '|' . (isset($_SERVER['HTTP_USER_AGENT'])
                ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']))
                : ''),
            wp_salt('auth')
        );

        $result = $wpdb->insert(
            $table,
            array(
                'project_id' => absint($project_id),
                'release_id' => absint($release_id),
                'asset_name' => sanitize_file_name($asset_name),
                'download_url' => $url,
                'visitor_hash' => $visitor_hash,
                'user_id' => get_current_user_id(),
                'event_type' => 'click',
                'created_at' => current_time('mysql')
            ),
            array('%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s')
        );

        return $result !== false;
    }

    public static function create_download_url($release_id) {
        return home_url(
            '/uapm-download/' . absint($release_id) . '/'
        );
    }

    public static function get_stats($args = array()) {
        global $wpdb;

        $table = $wpdb->prefix . 'uapm_downloads';

        $args = wp_parse_args($args, array(
            'project_id' => 0,
            'limit' => 100,
            'offset' => 0
        ));

        $where = 'WHERE 1=1';
        $params = array();

        if (!empty($args['project_id'])) {
            $where .= ' AND project_id = %d';
            $params[] = absint($args['project_id']);
        }

        $limit = max(1, min(500, absint($args['limit'])));
        $offset = absint($args['offset']);

        $sql = "SELECT * FROM {$table} {$where}
                ORDER BY created_at DESC
                LIMIT %d OFFSET %d";

        $params[] = $limit;
        $params[] = $offset;

        return $wpdb->get_results(
            $wpdb->prepare($sql, $params)
        );
    }

    public static function get_total($project_id = 0) {
        global $wpdb;

        $table = $wpdb->prefix . 'uapm_downloads';

        if ($project_id) {
            return absint($wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE project_id = %d",
                    absint($project_id)
                )
            ));
        }

        return absint(
            $wpdb->get_var("SELECT COUNT(*) FROM {$table}")
        );
    }

    public static function get_project_totals() {
        global $wpdb;

        $table = $wpdb->prefix . 'uapm_downloads';

        return $wpdb->get_results(
            "SELECT project_id, COUNT(*) AS downloads
             FROM {$table}
             GROUP BY project_id
             ORDER BY downloads DESC"
        );
    }

    public static function render_stats() {
        if (!current_user_can('uapm_manage_projects')) {
            wp_die('Недостатньо прав.');
        }

        check_admin_referer('uapm_download_stats');

        $project_id = isset($_GET['project_id'])
            ? absint($_GET['project_id'])
            : 0;

        $stats = self::get_stats(array(
            'project_id' => $project_id,
            'limit' => 500
        ));

        ?>
        <div class="wrap">
            <h1>Статистика завантажень</h1>

            <p>
                Усього переходів:
                <strong>
                    <?php echo esc_html(self::get_total($project_id)); ?>
                </strong>
            </p>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Проєкт ID</th>
                        <th>Реліз ID</th>
                        <th>Файл</th>
                        <th>Тип події</th>
                        <th>Дата</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stats as $item) : ?>
                        <tr>
                            <td><?php echo esc_html($item->project_id); ?></td>
                            <td><?php echo esc_html($item->release_id); ?></td>
                            <td><?php echo esc_html($item->asset_name); ?></td>
                            <td><?php echo esc_html($item->event_type); ?></td>
                            <td><?php echo esc_html($item->created_at); ?></td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (empty($stats)) : ?>
                        <tr>
                            <td colspan="5">Записів поки немає.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}

UAPM_Downloads::install_schema();
UAPM_Downloads::init();