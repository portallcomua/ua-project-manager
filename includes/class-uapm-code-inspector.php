<?php
/**
 * UA Project Manager - Code Inspector.
 *
 * @package UA_Project_Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

final class UAPM_Code_Inspector {

    const VERSION = '1.1.0';
    const MAX_FILES = 3000;
    const MAX_PHP_FILE_SIZE = 2097152;

    public static function init() {
        add_action('admin_post_uapm_inspect_project', array(__CLASS__, 'handle_inspection'));
    }

    private static function can_inspect() {
        return current_user_can('uapm_inspect_code') ||
            current_user_can('uapm_manage_projects') ||
            current_user_can('manage_options');
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
        $plugin_dir = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . $slug;

        $base_dir = realpath(WP_PLUGIN_DIR);
        $real_dir = realpath($plugin_dir);

        if (
            !$base_dir ||
            !$real_dir ||
            !is_dir($real_dir) ||
            strpos(
                wp_normalize_path($real_dir) . '/',
                trailingslashit(wp_normalize_path($base_dir))
            ) !== 0
        ) {
            return array(
                'project_id' => absint($project_id),
                'project_name' => sanitize_text_field($project->name),
                'project_slug' => $slug,
                'status' => 'not_installed',
                'message' => 'Папку плагіна не знайдено в каталозі встановлених плагінів.',
                'files' => array(),
                'summary' => array(
                    'total' => 0,
                    'php' => 0,
                    'other' => 0,
                    'errors' => 0,
                    'warnings' => 0,
                    'syntax_errors' => 0
                ),
                'checked_at' => current_time('mysql')
            );
        }

        $files = self::scan_directory($real_dir);
        $main_file = self::find_main_plugin_file($real_dir);

        $summary = array(
            'total' => count($files),
            'php' => 0,
            'other' => 0,
            'errors' => 0,
            'warnings' => 0,
            'syntax_errors' => 0
        );

        foreach ($files as $file) {
            if ($file['type'] === 'php') {
                $summary['php']++;
            } elseif ($file['type'] === 'other') {
                $summary['other']++;
            }

            if (!empty($file['error'])) {
                $summary['errors']++;
            }

            if (!empty($file['warning'])) {
                $summary['warnings']++;
            }

            if (!empty($file['syntax_error'])) {
                $summary['syntax_errors']++;
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

        $project_version = isset($project->version)
            ? sanitize_text_field($project->version)
            : '';

        $installed_version = isset($plugin_header['version'])
            ? sanitize_text_field($plugin_header['version'])
            : '';

        $version_match = (
            $project_version !== '' &&
            $installed_version !== '' &&
            version_compare($project_version, $installed_version, '==')
        );

        $status = 'completed';

        if ($summary['errors'] > 0 || $summary['syntax_errors'] > 0) {
            $status = 'error';
        } elseif ($summary['warnings'] > 0 || !$main_file || !$version_match) {
            $status = 'warning';
        }

        $result = array(
            'project_id' => absint($project_id),
            'project_name' => sanitize_text_field($project->name),
            'project_slug' => $slug,
            'status' => $status,
            'plugin_directory' => $real_dir,
            'main_file' => $main_file
                ? str_replace(
                    wp_normalize_path($real_dir) . '/',
                    '',
                    wp_normalize_path($main_file)
                )
                : '',
            'plugin_header' => $plugin_header,
            'project_version' => $project_version,
            'installed_version' => $installed_version,
            'version_match' => (bool) $version_match,
            'files' => $files,
            'summary' => $summary,
            'checked_at' => current_time('mysql')
        );

        do_action('uapm_code_inspection_completed', $result);

        return $result;
    }

    private static function scan_directory($directory) {
        $results = array();
        $directory = realpath($directory);

        if (!$directory || !is_readable($directory)) {
            return array(
                self::error_item('', 'Немає доступу до каталогу плагіна.')
            );
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $directory,
                    FilesystemIterator::SKIP_DOTS
                ),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if (count($results) >= self::MAX_FILES) {
                    $results[] = self::error_item(
                        '',
                        'Досягнуто обмеження кількості файлів для перевірки.'
                    );
                    break;
                }

                if (!$file->isFile() || $file->isLink()) {
                    continue;
                }

                $path = $file->getPathname();
                $real_path = realpath($path);

                if (
                    !$real_path ||
                    strpos(
                        wp_normalize_path($real_path),
                        trailingslashit(wp_normalize_path($directory))
                    ) !== 0
                ) {
                    continue;
                }

                $relative_path = ltrim(
                    str_replace(
                        wp_normalize_path($directory),
                        '',
                        wp_normalize_path($real_path)
                    ),
                    '/'
                );

                $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                $is_php = $extension === 'php';
                $size = $file->getSize();

                $item = array(
                    'name' => basename($path),
                    'path' => $relative_path,
                    'type' => $is_php ? 'php' : 'other',
                    'size' => $size,
                    'modified' => gmdate('Y-m-d H:i:s', $file->getMTime()),
                    'hash' => '',
                    'error' => false,
                    'error_message' => '',
                    'warning' => false,
                    'warning_message' => '',
                    'syntax_error' => false,
                    'syntax_error_message' => ''
                );

                if (!is_readable($real_path)) {
                    $item['error'] = true;
                    $item['error_message'] = 'Файл недоступний для читання.';
                    $results[] = $item;
                    continue;
                }

                $hash = hash_file('sha256', $real_path);

                if ($hash !== false) {
                    $item['hash'] = $hash;
                } else {
                    $item['warning'] = true;
                    $item['warning_message'] = 'Не вдалося обчислити SHA-256.';
                }

                if ($is_php) {
                    if ($size > self::MAX_PHP_FILE_SIZE) {
                        $item['warning'] = true;
                        $item['warning_message'] = 'PHP-файл завеликий для синтаксичної перевірки.';
                    } else {
                        $contents = file_get_contents($real_path);

                        if ($contents === false) {
                            $item['error'] = true;
                            $item['error_message'] = 'Не вдалося прочитати PHP-файл.';
                        } elseif (strpos($contents, '<?php') === false) {
                            $item['warning'] = true;
                            $item['warning_message'] = 'Не знайдено відкривальний PHP-тег.';
                        } else {
                            $syntax = self::check_php_syntax($contents);

                            if (is_wp_error($syntax)) {
                                $item['syntax_error'] = true;
                                $item['syntax_error_message'] = $syntax->get_error_message();
                            }
                        }
                    }
                }

                $results[] = $item;
            }
        } catch (Throwable $exception) {
            $results[] = self::error_item(
                '',
                'Помилка сканування: ' . $exception->getMessage()
            );
        }

        usort($results, function($a, $b) {
            return strcmp($a['path'], $b['path']);
        });

        return $results;
    }

    /**
     * Parse PHP source without executing it.
     */
    private static function check_php_syntax($source) {
        if (!defined('TOKEN_PARSE')) {
            return new WP_Error(
                'uapm_token_parse_unavailable',
                'Ця версія PHP не підтримує повну перевірку синтаксису.'
            );
        }

        try {
            token_get_all($source, TOKEN_PARSE);
        } catch (ParseError $exception) {
            return new WP_Error(
                'uapm_php_syntax_error',
                $exception->getMessage()
            );
        } catch (Throwable $exception) {
            return new WP_Error(
                'uapm_php_parse_error',
                $exception->getMessage()
            );
        }

        return true;
    }

    private static function error_item($path, $message) {
        return array(
            'name' => $path ? basename($path) : '',
            'path' => $path,
            'type' => 'error',
            'size' => 0,
            'modified' => '',
            'hash' => '',
            'error' => true,
            'error_message' => sanitize_text_field($message),
            'warning' => false,
            'warning_message' => '',
            'syntax_error' => false,
            'syntax_error_message' => ''
        );
    }

    private static function find_main_plugin_file($directory) {
        $files = glob(trailingslashit($directory) . '*.php');

        if (!$files) {
            return false;
        }

        foreach ($files as $file) {
            if (!is_file($file) || !is_readable($file)) {
                continue;
            }

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
        if (!self::can_inspect()) {
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
                    'uapm_error' => $result->get_error_message()
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
        if (!self::can_inspect()) {
            return;
        }

        $projects = class_exists('UAPM_Projects')
            ? UAPM_Projects::get_all(array(
                'limit' => 200,
                'orderby' => 'name',
                'order' => 'ASC'
            ))
            : array();

        echo '<div class="wrap">';
        echo '<h1>UPM Code Inspector</h1>';
        echo '<p>Перевірка файлів плагіна, встановленого на цьому WordPress-сайті.</p>';

        if (isset($_GET['uapm_error'])) {
            echo '<div class="notice notice-error"><p>' .
                esc_html(sanitize_text_field(wp_unslash($_GET['uapm_error']))) .
                '</p></div>';
        }

        if (empty($projects)) {
            echo '<p>Спочатку створіть проєкт у UA Project Manager.</p>';
            echo '</div>';
            return;
        }

        echo '<form method="post" action="' .
            esc_url(admin_url('admin-post.php')) . '">';

        echo '<input type="hidden" name="action" value="uapm_inspect_project">';

        wp_nonce_field('uapm_inspect_project');

        echo '<p><label for="uapm_project_id">Проєкт:</label></p>';
        echo '<select name="project_id" id="uapm_project_id" required>';

        foreach ($projects as $project) {
            echo '<option value="' . esc_attr(absint($project->id)) . '"' .
                selected(absint($project_id), absint($project->id), false) . '>' .
                esc_html($project->name . ' (' . $project->slug . ')') .
                '</option>';
        }

        echo '</select> ';

        submit_button('Перевірити файли', 'primary', 'submit', false);

        echo '</form>';

        self::render_last_result();

        echo '</div>';
    }

    private static function render_last_result() {
        $result = self::get_last_result();

        if (!$result || !is_array($result)) {
            return;
        }

        echo '<hr><h2>Результат перевірки</h2>';

        echo '<p><strong>Проєкт:</strong> ' .
            esc_html($result['project_name']) . '</p>';

        echo '<p><strong>Статус:</strong> ' .
            esc_html($result['status']) . '</p>';

        echo '<p><strong>Дата:</strong> ' .
            esc_html($result['checked_at']) . '</p>';

        echo '<p><strong>Усього файлів:</strong> ' .
            esc_html($result['summary']['total']) . '</p>';

        echo '<p><strong>PHP-файлів:</strong> ' .
            esc_html($result['summary']['php']) . '</p>';

        echo '<p><strong>Інших файлів:</strong> ' .
            esc_html($result['summary']['other']) . '</p>';

        echo '<p><strong>Помилок читання:</strong> ' .
            esc_html($result['summary']['errors']) . '</p>';

        echo '<p><strong>Синтаксичних помилок PHP:</strong> ' .
            esc_html($result['summary']['syntax_errors']) . '</p>';

        echo '<p><strong>Попереджень:</strong> ' .
            esc_html($result['summary']['warnings']) . '</p>';

        if (!empty($result['plugin_header'])) {
            echo '<h3>Інформація про встановлений плагін</h3>';

            echo '<p>Назва: ' .
                esc_html($result['plugin_header']['name']) . '</p>';

            echo '<p>Встановлена версія: ' .
                esc_html($result['installed_version']) . '</p>';

            echo '<p>Версія в UPM: ' .
                esc_html($result['project_version']) . '</p>';

            echo '<p>Версії збігаються: ' .
                (!empty($result['version_match']) ? 'Так' : 'Ні') .
                '</p>';
        }

        if (!empty($result['message'])) {
            echo '<p>' . esc_html($result['message']) . '</p>';
        }

        if (empty($result['files'])) {
            return;
        }

        echo '<h3>Файли</h3>';
        echo '<div style="overflow-x:auto">';
        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>Файл</th>';
        echo '<th>Тип</th>';
        echo '<th>Розмір</th>';
        echo '<th>SHA-256</th>';
        echo '<th>Результат</th>';
        echo '</tr></thead><tbody>';

        foreach ($result['files'] as $file) {
            echo '<tr>';

            echo '<td>' . esc_html($file['path']) . '</td>';
            echo '<td>' . esc_html($file['type']) . '</td>';
            echo '<td>' . esc_html(size_format($file['size'])) . '</td>';

            echo '<td><code>' .
                esc_html($file['hash'] ? substr($file['hash'], 0, 16) : '—') .
                '</code></td>';

            $messages = array();

            if (!empty($file['error_message'])) {
                $messages[] = $file['error_message'];
            }

            if (!empty($file['syntax_error_message'])) {
                $messages[] = 'PHP: ' . $file['syntax_error_message'];
            }

            if (!empty($file['warning_message'])) {
                $messages[] = $file['warning_message'];
            }

            echo '<td>' . esc_html(
                $messages ? implode(' | ', $messages) : 'OK'
            ) . '</td>';

            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }
}

UAPM_Code_Inspector::init();
