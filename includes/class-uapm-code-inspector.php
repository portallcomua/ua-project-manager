<?php
if (!defined('ABSPATH')) {
    exit;
}

class UAPM_Code_Inspector {

    public static function init() {
        add_action('admin_post_uapm_inspect_project', array(__CLASS__, 'handle_inspection'));
    }

    public static function inspect($project_id) {
        if (!class_exists('UAPM_Projects')) {
            return new WP_Error('uapm_projects_missing', 'Модуль проєктів недоступний.');
        }

        $project = UAPM_Projects::get(absint($project_id));

        if (!$project) {
            return new WP_Error('uapm_project_missing', 'Проєкт не знайдено.');
        }

        $slug = sanitize_title($project->slug);
        $plugin_dir = WP_PLUGIN_DIR . '/' . $slug;

        if (!is_dir($plugin_dir)) {
            return array(
                'project_id' => absint($project_id),
                'project_name' => $project->name,
                'status' => 'not_installed',
                'message' => 'Папку плагіна не знайдено на цьому WordPress-сайті.',
                'files' => array(),
                'summary' => array(
                    'total' => 0,
                    'php' => 0,
                    'other' => 0,
                    'errors' => 0
                ),
                'checked_at' => current_time('mysql')
            );
        }

        $files = self::scan_directory($plugin_dir);
        $main_file = self::find_main_plugin_file($plugin_dir);

        $summary = array(
            'total' => count($files),
            'php' => 0,
            'other' => 0,
            'errors' => 0
        );

        foreach ($files as $file) {
            if ($file['type'] === 'php') {
                $summary['php']++;
            } else {
                $summary['other']++;
            }

            if (!empty($file['error'])) {
                $summary['errors']++;
            }
        }

        $plugin_header = array();

        if ($main_file) {
            $plugin_header = get_file_data(
                $main_file,
                array(
                    'name' => 'Plugin Name',
                    'version' => 'Version',
                    'description' => 'Description',
                    'author' => 'Author',
                    'text_domain' => 'Text Domain'
                )
            );
        }

        $result = array(
            'project_id' => absint($project_id),
            'project_name' => $project->name,
            'project_slug' => $slug,
            'status' => $summary['errors'] > 0 ? 'warning' : 'completed',
            'plugin_directory' => $plugin_dir,
            'main_file' => $main_file ? basename($main_file) : '',
            'plugin_header' => $plugin_header,
            'project_version' => isset($project->version) ? $project->version : '',
            'installed_version' => isset($plugin_header['version']) ? $plugin_header['version'] : '',
            'version_match' => (
                !empty($project->version) &&
                !empty($plugin_header['version']) &&
                $project->version === $plugin_header['version']
            ),
            'files' => $files,
            'summary' => $summary,
            'checked_at' => current_time('mysql')
        );

        do_action('uapm_code_inspection_completed', $result);

        return $result;
    }

    private static function scan_directory($directory) {
        $results = array();

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $directory,
                    FilesystemIterator::SKIP_DOTS
                )
            );

            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                $path = $file->getPathname();
                $relative_path = ltrim(
                    str_replace($directory, '', $path),
                    DIRECTORY_SEPARATOR
                );

                $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                $is_php = $extension === 'php';

                $item = array(
                    'name' => basename($path),
                    'path' => str_replace(DIRECTORY_SEPARATOR, '/', $relative_path),
                    'type' => $is_php ? 'php' : 'other',
                    'size' => $file->getSize(),
                    'modified' => gmdate('Y-m-d H:i:s', $file->getMTime()),
                    'hash' => hash_file('sha256', $path),
                    'error' => false,
                    'error_message' => ''
                );

                if ($is_php) {
                    $contents = file_get_contents($path);

                    if ($contents === false) {
                        $item['error'] = true;
                        $item['error_message'] = 'Не вдалося прочитати PHP-файл.';
                    } elseif (strpos($contents, '<?php') === false) {
                        $item['error'] = true;
                        $item['error_message'] = 'Не знайдено відкривальний PHP-тег.';
                    }
                }

                $results[] = $item;
            }
        } catch (Exception $exception) {
            return array(
                array(
                    'name' => '',
                    'path' => '',
                    'type' => 'error',
                    'size' => 0,
                    'modified' => '',
                    'hash' => '',
                    'error' => true,
                    'error_message' => $exception->getMessage()
                )
            );
        }

        usort($results, function($a, $b) {
            return strcmp($a['path'], $b['path']);
        });

        return $results;
    }

    private static function find_main_plugin_file($directory) {
        $files = glob(trailingslashit($directory) . '*.php');

        if (!$files) {
            return false;
        }

        foreach ($files as $file) {
            $headers = get_file_data(
                $file,
                array('name' => 'Plugin Name')
            );

            if (!empty($headers['name'])) {
                return $file;
            }
        }

        return false;
    }

    public static function handle_inspection() {
        if (!current_user_can('uapm_inspect_code')) {
            wp_die('Недостатньо прав для перевірки коду.');
        }

        check_admin_referer('uapm_inspect_project');

        $project_id = isset($_POST['project_id'])
            ? absint($_POST['project_id'])
            : 0;

        $result = self::inspect($project_id);

        if (is_wp_error($result)) {
            $redirect = add_query_arg(
                array(
                    'page' => 'uapm-code-inspector',
                    'uapm_error' => rawurlencode($result->get_error_message())
                ),
                admin_url('admin.php')
            );
        } else {
            set_transient(
                'uapm_inspection_' . get_current_user_id(),
                $result,
                HOUR_IN_SECONDS
            );

            $redirect = add_query_arg(
                array(
                    'page' => 'uapm-code-inspector',
                    'inspection' => 'completed'
                ),
                admin_url('admin.php')
            );
        }

        wp_safe_redirect($redirect);
        exit;
    }

    public static function get_last_result() {
        return get_transient(
            'uapm_inspection_' . get_current_user_id()
        );
    }

    public static function render_form($project_id = 0) {
        if (!current_user_can('uapm_inspect_code')) {
            return;
        }

        ?>
        <div class="wrap">
            <h1>UPM Code Inspector</h1>

            <p>Перевірка файлів встановленого плагіна на цьому WordPress-сайті.</p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="uapm_inspect_project">

                <?php wp_nonce_field('uapm_inspect_project'); ?>

                <label for="uapm_project_id">ID проєкту:</label>

                <input
                    type="number"
                    min="1"
                    name="project_id"
                    id="uapm_project_id"
                    value="<?php echo esc_attr(absint($project_id)); ?>"
                    required
                >

                <?php submit_button('Перевірити файли'); ?>
            </form>

            <?php self::render_last_result(); ?>
        </div>
        <?php
    }

    private static function render_last_result() {
        $result = self::get_last_result();

        if (!$result) {
            return;
        }

        ?>
        <hr>

        <h2>Результат перевірки</h2>

        <p>
            <strong>Проєкт:</strong>
            <?php echo esc_html($result['project_name']); ?>
        </p>

        <p>
            <strong>Статус:</strong>
            <?php echo esc_html($result['status']); ?>
        </p>

        <p>
            <strong>Дата:</strong>
            <?php echo esc_html($result['checked_at']); ?>
        </p>

        <p>
            <strong>Усього файлів:</strong>
            <?php echo esc_html($result['summary']['total']); ?>
        </p>

        <p>
            <strong>PHP-файлів:</strong>
            <?php echo esc_html($result['summary']['php']); ?>
        </p>

        <p>
            <strong>Інших файлів:</strong>
            <?php echo esc_html($result['summary']['other']); ?>
        </p>

        <p>
            <strong>Помилок:</strong>
            <?php echo esc_html($result['summary']['errors']); ?>
        </p>

        <?php if (!empty($result['plugin_header'])) : ?>
            <h3>Інформація про встановлений плагін</h3>

            <p>
                Назва:
                <?php echo esc_html($result['plugin_header']['name']); ?>
            </p>

            <p>
                Версія:
                <?php echo esc_html($result['installed_version']); ?>
            </p>

            <p>
                Версія в UPM:
                <?php echo esc_html($result['project_version']); ?>
            </p>
        <?php endif; ?>

        <h3>Список файлів</h3>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th>Файл</th>
                    <th>Тип</th>
                    <th>Розмір</th>
                    <th>SHA-256</th>
                    <th>Статус</th>
                </tr>
            </thead>

            <tbody>
                <?php foreach ($result['files'] as $file) : ?>
                    <tr>
                        <td><?php echo esc_html($file['path']); ?></td>
                        <td><?php echo esc_html($file['type']); ?></td>
                        <td><?php echo esc_html(size_format($file['size'])); ?></td>
                        <td>
                            <code><?php echo esc_html(substr($file['hash'], 0, 16)); ?></code>
                        </td>
                        <td>
                            <?php
                            echo !empty($file['error'])
                                ? esc_html($file['error_message'])
                                : 'OK';
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}

UAPM_Code_Inspector::init();