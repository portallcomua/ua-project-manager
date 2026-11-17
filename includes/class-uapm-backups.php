<?php
if (!defined('ABSPATH')) {
    exit;
}

class UAPM_Backups {

    public static function init() {
        add_action('admin_post_uapm_create_backup', array(__CLASS__, 'handle_create'));
        add_action('admin_post_uapm_restore_backup', array(__CLASS__, 'handle_restore'));
        add_action('admin_post_uapm_delete_backup', array(__CLASS__, 'handle_delete'));
    }

    public static function get_backup_directory() {
        $uploads = wp_upload_dir();
        $directory = trailingslashit($uploads['basedir']) . 'uapm-backups';

        if (!file_exists($directory)) {
            wp_mkdir_p($directory);
        }

        if (is_dir($directory)) {
            $index = trailingslashit($directory) . 'index.php';

            if (!file_exists($index)) {
                file_put_contents($index, "<?php\n// Silence is golden.\n");
            }

            $htaccess = trailingslashit($directory) . '.htaccess';

            if (!file_exists($htaccess)) {
                file_put_contents(
                    $htaccess,
                    "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n"
                );
            }
        }

        return $directory;
    }

    public static function create_backup($project_id) {
        $project_id = absint($project_id);

        if (!$project_id || !class_exists('UAPM_Projects')) {
            return new WP_Error('invalid_project', 'Проєкт не знайдено.');
        }

        $project = UAPM_Projects::get($project_id);

        if (!$project) {
            return new WP_Error('project_not_found', 'Проєкт не знайдено.');
        }

        $slug = sanitize_file_name($project->slug);

        if (!$slug) {
            return new WP_Error('invalid_slug', 'Некоректний ідентифікатор проєкту.');
        }

        $source = trailingslashit(WP_PLUGIN_DIR) . $slug;

        if (!is_dir($source)) {
            return new WP_Error('directory_not_found', 'Папку плагіна не знайдено.');
        }

        if (!class_exists('ZipArchive')) {
            return new WP_Error('zip_unavailable', 'На сервері недоступний ZipArchive.');
        }

        $directory = self::get_backup_directory();

        if (!is_dir($directory) || !wp_is_writable($directory)) {
            return new WP_Error('backup_directory_error', 'Немає доступу до папки резервних копій.');
        }

        $filename = sprintf(
            'uapm-project-%d-%s-%s.zip',
            $project_id,
            gmdate('Ymd-His'),
            wp_generate_password(6, false, false)
        );

        $filepath = trailingslashit($directory) . $filename;

        $zip = new ZipArchive();

        if ($zip->open($filepath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return new WP_Error('zip_create_failed', 'Не вдалося створити архів.');
        }

        $source_real = realpath($source);

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $source_real,
                    FilesystemIterator::SKIP_DOTS
                ),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $file) {
                $path = $file->getPathname();
                $real = realpath($path);

                if (!$real || strpos($real, $source_real) !== 0) {
                    continue;
                }

                $relative = substr($real, strlen($source_real) + 1);
                $relative = str_replace('\\', '/', $relative);

                if ($file->isDir()) {
                    $zip->addEmptyDir($relative);
                } elseif ($file->isFile() && $file->isReadable()) {
                    $zip->addFile($real, $relative);
                }
            }
        } catch (Throwable $error) {
            $zip->close();
            @unlink($filepath);

            return new WP_Error('backup_failed', 'Помилка читання файлів проєкту.');
        }

        $zip->close();

        if (!file_exists($filepath) || filesize($filepath) === 0) {
            @unlink($filepath);
            return new WP_Error('empty_backup', 'Архів резервної копії порожній.');
        }

        $record = array(
            'project_id' => $project_id,
            'project_name' => sanitize_text_field($project->name),
            'filename' => $filename,
            'created_at' => current_time('mysql'),
            'size' => filesize($filepath),
            'checksum' => hash_file('sha256', $filepath),
        );

        $backups = get_option('uapm_backup_records', array());

        if (!is_array($backups)) {
            $backups = array();
        }

        $backups[$filename] = $record;

        update_option('uapm_backup_records', $backups, false);

        if (class_exists('UAPM_Activity_Log')) {
            UAPM_Activity_Log::add(
                'backup_created',
                'Створено резервну копію проєкту: ' . $project->name,
                $project_id
            );
        }

        return $record;
    }

    public static function get_all($project_id = 0) {
        $backups = get_option('uapm_backup_records', array());

        if (!is_array($backups)) {
            return array();
        }

        if ($project_id) {
            $backups = array_filter($backups, function ($backup) use ($project_id) {
                return absint($backup['project_id'] ?? 0) === absint($project_id);
            });
        }

        uasort($backups, function ($a, $b) {
            return strcmp(
                $b['created_at'] ?? '',
                $a['created_at'] ?? ''
            );
        });

        return array_values($backups);
    }

    public static function restore_backup($filename) {
        $filename = sanitize_file_name($filename);
        $backups = get_option('uapm_backup_records', array());

        if (empty($backups[$filename])) {
            return new WP_Error('backup_not_found', 'Резервну копію не знайдено.');
        }

        $record = $backups[$filename];
        $project_id = absint($record['project_id']);

        $project = UAPM_Projects::get($project_id);

        if (!$project) {
            return new WP_Error('project_not_found', 'Проєкт не знайдено.');
        }

        $directory = self::get_backup_directory();
        $archive = trailingslashit($directory) . $filename;

        if (!file_exists($archive) || !is_readable($archive)) {
            return new WP_Error('archive_missing', 'Файл резервної копії відсутній.');
        }

        $checksum = hash_file('sha256', $archive);

        if (!hash_equals($record['checksum'], $checksum)) {
            return new WP_Error('checksum_failed', 'Контрольна сума архіву не збігається.');
        }

        if (!class_exists('ZipArchive')) {
            return new WP_Error('zip_unavailable', 'На сервері недоступний ZipArchive.');
        }

        $slug = sanitize_file_name($project->slug);
        $target = trailingslashit(WP_PLUGIN_DIR) . $slug;

        if (!is_dir($target)) {
            return new WP_Error('target_missing', 'Папку встановленого плагіна не знайдено.');
        }

        $temporary = trailingslashit(get_temp_dir()) . 'uapm-restore-' . wp_generate_password(12, false, false);

        if (!wp_mkdir_p($temporary)) {
            return new WP_Error('temp_directory_error', 'Не вдалося створити тимчасову папку.');
        }

        $zip = new ZipArchive();

        if ($zip->open($archive) !== true) {
            self::remove_directory($temporary);
            return new WP_Error('archive_open_failed', 'Не вдалося відкрити архів.');
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);

            if (!$entry || strpos($entry, '/') === 0 || strpos($entry, '\\') === 0) {
                $zip->close();
                self::remove_directory($temporary);
                return new WP_Error('unsafe_archive', 'Архів містить небезпечний шлях.');
            }

            $normalized = str_replace('\\', '/', $entry);
            $parts = explode('/', $normalized);

            if (in_array('..', $parts, true)) {
                $zip->close();
                self::remove_directory($temporary);
                return new WP_Error('unsafe_archive', 'Архів містить небезпечний шлях.');
            }
        }

        $extracted = $zip->extractTo($temporary);
        $zip->close();

        if (!$extracted) {
            self::remove_directory($temporary);
            return new WP_Error('extract_failed', 'Не вдалося розпакувати архів.');
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $temporary,
                FilesystemIterator::SKIP_DOTS
            ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $source_path = $item->getPathname();
            $relative = substr($source_path, strlen($temporary) + 1);
            $destination = trailingslashit($target) . $relative;

            if ($item->isDir()) {
                if (!file_exists($destination)) {
                    wp_mkdir_p($destination);
                }
            } else {
                $parent = dirname($destination);

                if (!is_dir($parent)) {
                    wp_mkdir_p($parent);
                }

                if (!@copy($source_path, $destination)) {
                    self::remove_directory($temporary);

                    return new WP_Error(
                        'restore_copy_failed',
                        'Не вдалося відновити файл: ' . sanitize_text_field($relative)
                    );
                }
            }
        }

        self::remove_directory($temporary);

        if (class_exists('UAPM_Activity_Log')) {
            UAPM_Activity_Log::add(
                'backup_restored',
                'Відновлено резервну копію проєкту: ' . $project->name,
                $project_id
            );
        }

        return true;
    }

    public static function delete_backup($filename) {
        $filename = sanitize_file_name($filename);
        $backups = get_option('uapm_backup_records', array());

        if (empty($backups[$filename])) {
            return new WP_Error('backup_not_found', 'Резервну копію не знайдено.');
        }

        $filepath = trailingslashit(self::get_backup_directory()) . $filename;

        if (file_exists($filepath) && !@unlink($filepath)) {
            return new WP_Error('delete_failed', 'Не вдалося видалити архів.');
        }

        unset($backups[$filename]);
        update_option('uapm_backup_records', $backups, false);

        return true;
    }

    private static function remove_directory($directory) {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $directory,
                FilesystemIterator::SKIP_DOTS
            ),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($directory);
    }

    public static function handle_create() {
        if (!current_user_can('uapm_inspect_code')) {
            wp_die('Недостатньо прав.');
        }

        check_admin_referer('uapm_create_backup');

        $result = self::create_backup(absint($_POST['project_id'] ?? 0));

        $url = admin_url('admin.php?page=uapm-backups');

        if (is_wp_error($result)) {
            $url = add_query_arg(
                'uapm_error',
                rawurlencode($result->get_error_message()),
                $url
            );
        } else {
            $url = add_query_arg('uapm_success', 'backup_created', $url);
        }

        wp_safe_redirect($url);
        exit;
    }

    public static function handle_restore() {
        if (!current_user_can('uapm_inspect_code')) {
            wp_die('Недостатньо прав.');
        }

        check_admin_referer('uapm_restore_backup');

        $result = self::restore_backup(
            sanitize_file_name(wp_unslash($_POST['filename'] ?? ''))
        );

        $url = admin_url('admin.php?page=uapm-backups');

        if (is_wp_error($result)) {
            $url = add_query_arg(
                'uapm_error',
                rawurlencode($result->get_error_message()),
                $url
            );
        } else {
            $url = add_query_arg('uapm_success', 'backup_restored', $url);
        }

        wp_safe_redirect($url);
        exit;
    }

    public static function handle_delete() {
        if (!current_user_can('uapm_inspect_code')) {
            wp_die('Недостатньо прав.');
        }

        check_admin_referer('uapm_delete_backup');

        $result = self::delete_backup(
            sanitize_file_name(wp_unslash($_POST['filename'] ?? ''))
        );

        $url = admin_url('admin.php?page=uapm-backups');

        if (is_wp_error($result)) {
            $url = add_query_arg(
                'uapm_error',
                rawurlencode($result->get_error_message()),
                $url
            );
        } else {
            $url = add_query_arg('uapm_success', 'backup_deleted', $url);
        }

        wp_safe_redirect($url);
        exit;
    }

    public static function render_page() {
        if (!current_user_can('uapm_inspect_code')) {
            wp_die('Недостатньо прав.');
        }

        $projects = UAPM_Projects::get_all(array(
            'status' => 'active',
            'limit' => 200,
        ));

        $backups = self::get_all();

        echo '<div class="wrap uapm-wrap">';
        echo '<h1>Резервні копії проєктів</h1>';

        if (!empty($_GET['uapm_error'])) {
            echo '<div class="notice notice-error"><p>' .
                esc_html(wp_unslash($_GET['uapm_error'])) .
                '</p></div>';
        }

        if (!empty($_GET['uapm_success'])) {
            echo '<div class="notice notice-success"><p>Операцію виконано.</p></div>';
        }

        echo '<div class="uapm-card">';
        echo '<h2>Створити резервну копію</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="uapm_create_backup">';
        wp_nonce_field('uapm_create_backup');

        echo '<select name="project_id" required>';
        echo '<option value="">Оберіть проєкт</option>';

        foreach ($projects as $project) {
            echo '<option value="' . esc_attr($project->id) . '">' .
                esc_html($project->name) .
                '</option>';
        }

        echo '</select> ';
        submit_button('Створити копію', 'primary', 'submit', false);
        echo '</form>';
        echo '</div>';

        echo '<div class="uapm-card">';
        echo '<h2>Збережені копії</h2>';
        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>Проєкт</th><th>Файл</th><th>Дата</th><th>Розмір</th><th>Дії</th>';
        echo '</tr></thead><tbody>';

        if (empty($backups)) {
            echo '<tr><td colspan="5">Резервних копій поки немає.</td></tr>';
        }

        foreach ($backups as $backup) {
            echo '<tr>';
            echo '<td>' . esc_html($backup['project_name']) . '</td>';
            echo '<td>' . esc_html($backup['filename']) . '</td>';
            echo '<td>' . esc_html($backup['created_at']) . '</td>';
            echo '<td>' . esc_html(size_format($backup['size'])) . '</td>';
            echo '<td>';

            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block">';
            echo '<input type="hidden" name="action" value="uapm_restore_backup">';
            echo '<input type="hidden" name="filename" value="' . esc_attr($backup['filename']) . '">';
            wp_nonce_field('uapm_restore_backup');
            echo '<button class="button" onclick="return confirm(\'Відновити файли проєкту з цієї копії?\')">Відновити</button>';
            echo '</form> ';

            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block">';
            echo '<input type="hidden" name="action" value="uapm_delete_backup">';
            echo '<input type="hidden" name="filename" value="' . esc_attr($backup['filename']) . '">';
            wp_nonce_field('uapm_delete_backup');
            echo '<button class="button button-link-delete" onclick="return confirm(\'Видалити резервну копію?\')">Видалити</button>';
            echo '</form>';

            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
        echo '</div>';
    }
}