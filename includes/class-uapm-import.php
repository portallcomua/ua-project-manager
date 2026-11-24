<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * CSV importer with delimiter detection, column mapping, preview and safe upsert.
 */
class UAPM_Import {

    private static function types() {
        return array(
            'projects' => array(
                'label' => 'Проєкти',
                'table' => 'uapm_projects',
                'unique' => 'project_key',
                'required' => array('name', 'project_key'),
                'fields' => array(
                    'project_key' => 'Ключ проєкту',
                    'name' => 'Назва',
                    'description' => 'Опис',
                    'project_type' => 'Тип проєкту',
                    'status' => 'Статус',
                    'version' => 'Версія',
                    'author' => 'Автор',
                    'website_url' => 'Сайт',
                    'documentation_url' => 'Документація',
                    'github_repository' => 'GitHub репозиторій',
                    'github_branch' => 'GitHub гілка',
                    'logo_url' => 'URL логотипа',
                ),
            ),
            'customers' => array(
                'label' => 'Клієнти',
                'table' => 'uapm_customers',
                'unique' => 'email',
                'required' => array('email'),
                'fields' => array(
                    'email' => 'Email',
                    'name' => 'Ім’я',
                    'phone' => 'Телефон',
                    'company' => 'Компанія',
                    'country' => 'Країна',
                    'customer_type' => 'Тип клієнта',
                    'status' => 'Статус',
                    'mailing_consent' => 'Згода на розсилку',
                    'notes' => 'Нотатки',
                ),
            ),
        );
    }

    public static function init() {
        add_action('admin_post_uapm_import_preview', array(__CLASS__, 'handle_preview'));
        add_action('admin_post_uapm_import_commit', array(__CLASS__, 'handle_commit'));
    }

    private static function authorize($nonce_action, $nonce_name) {
        if (!current_user_can('uapm_manage_settings') && !current_user_can('manage_options')) {
            wp_die('Недостатньо прав для імпорту.');
        }
        check_admin_referer($nonce_action, $nonce_name);
    }

    private static function page_url($args = array()) {
        return add_query_arg(array_merge(array('page' => 'uapm-import'), $args), admin_url('admin.php'));
    }

    private static function redirect_error($message) {
        wp_safe_redirect(self::page_url(array('uapm_import_error' => rawurlencode($message))));
        exit;
    }

    private static function detect_delimiter($line) {
        $candidates = array(';', ',', "\t", '|');
        $best = ';';
        $best_count = 0;
        foreach ($candidates as $delimiter) {
            $count = count(str_getcsv($line, $delimiter));
            if ($count > $best_count) {
                $best_count = $count;
                $best = $delimiter;
            }
        }
        return $best;
    }

    private static function normalize_header($value) {
        $value = trim((string) $value);
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);
        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        $value = preg_replace('/[\s\-_]+/u', '', $value);
        return $value;
    }

    private static function aliases() {
        return array(
            'project_key' => array('projectkey', 'ключпроєкту', 'ключпроекту', 'ключ', 'папкаплагіна', 'slug', 'папка'),
            'name' => array('name', 'назва', 'назвапроєкту', 'проєкт', 'проект'),
            'description' => array('description', 'опис', 'короткийопис'),
            'project_type' => array('projecttype', 'тип', 'типпроєкту', 'типпроекту'),
            'status' => array('status', 'статус'),
            'version' => array('version', 'версія', 'поточнаверсія'),
            'author' => array('author', 'автор'),
            'website_url' => array('websiteurl', 'сайт', 'urlсайту', 'вебсайт'),
            'documentation_url' => array('documentationurl', 'документація', 'документация'),
            'github_repository' => array('githubrepository', 'github', 'репозиторій', 'репозиторий', 'посиланняgithub'),
            'github_branch' => array('githubbranch', 'гілка', 'гілкаgithub'),
            'logo_url' => array('logourl', 'логотип', 'urlлоготипа'),
            'email' => array('email', 'електроннапошта', 'поштa', 'емейл'),
            'phone' => array('phone', 'телефон'),
            'company' => array('company', 'компанія', 'компания'),
            'country' => array('country', 'країна', 'страна'),
            'customer_type' => array('customertype', 'типклієнта'),
            'mailing_consent' => array('mailingconsent', 'розсилка', 'згоданарозсилку'),
            'notes' => array('notes', 'нотатки', 'примітки'),
        );
    }

    private static function suggested_mapping($headers, $type) {
        $config = self::types()[$type];
        $aliases = self::aliases();
        $mapping = array();
        foreach ($config['fields'] as $field => $label) {
            $mapping[$field] = '';
            $known = isset($aliases[$field]) ? $aliases[$field] : array($field);
            foreach ($headers as $index => $header) {
                $normalized = self::normalize_header($header);
                if (in_array($normalized, $known, true)) {
                    $mapping[$field] = (string) $index;
                    break;
                }
            }
        }
        return $mapping;
    }

    public static function handle_preview() {
        self::authorize('uapm_import_preview', 'uapm_import_nonce');

        if (empty($_FILES['uapm_import_file']['tmp_name']) || !is_uploaded_file($_FILES['uapm_import_file']['tmp_name'])) {
            self::redirect_error('Не вибрано CSV-файл.');
        }

        $file = $_FILES['uapm_import_file'];
        if (!empty($file['error']) || (int) $file['size'] > 5 * MB_IN_BYTES) {
            self::redirect_error('Файл завеликий або не вдалося його завантажити. Максимум 5 МБ.');
        }
        if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'csv') {
            self::redirect_error('Потрібен файл із розширенням .csv.');
        }

        $type = sanitize_key(wp_unslash($_POST['import_type'] ?? ''));
        $types = self::types();
        if (!isset($types[$type])) {
            self::redirect_error('Невідомий тип імпорту.');
        }

        $requested = sanitize_text_field(wp_unslash($_POST['delimiter'] ?? 'auto'));
        $handle = fopen($file['tmp_name'], 'r');
        if (!$handle) {
            self::redirect_error('Не вдалося прочитати файл.');
        }

        $first_line = fgets($handle);
        if ($first_line === false) {
            fclose($handle);
            self::redirect_error('CSV-файл порожній.');
        }
        $delimiter = $requested === 'auto'
            ? self::detect_delimiter($first_line)
            : ($requested === 'tab' ? "\t" : $requested);
        if (!in_array($delimiter, array(';', ',', "\t", '|'), true)) {
            fclose($handle);
            self::redirect_error('Непідтримуваний роздільник.');
        }

        rewind($handle);
        $headers = fgetcsv($handle, 0, $delimiter);
        if (!$headers || count($headers) > 60) {
            fclose($handle);
            self::redirect_error('Не вдалося прочитати заголовки або колонок більше 60.');
        }
        $headers = array_map(function($header) {
            return trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $header));
        }, $headers);

        $rows = array();
        $line = 1;
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $line++;
            if ($line > 10001) {
                fclose($handle);
                self::redirect_error('Перевищено ліміт 10 000 рядків.');
            }
            if (count($row) < count($headers)) {
                $row = array_pad($row, count($headers), '');
            }
            if (count($row) > count($headers)) {
                $row = array_slice($row, 0, count($headers));
            }
            if (count(array_filter($row, function($v) { return trim((string) $v) !== ''; })) === 0) {
                continue;
            }
            $rows[] = $row;
        }
        fclose($handle);

        if (!$rows) {
            self::redirect_error('У файлі немає рядків із даними.');
        }

        $token = wp_generate_password(32, false, false);
        set_transient('uapm_import_' . $token, array(
            'user_id' => get_current_user_id(),
            'type' => $type,
            'headers' => $headers,
            'rows' => $rows,
            'delimiter' => $delimiter,
        ), 30 * MINUTE_IN_SECONDS);

        wp_safe_redirect(self::page_url(array('stage' => 'map', 'token' => $token)));
        exit;
    }

    public static function handle_commit() {
        self::authorize('uapm_import_commit', 'uapm_import_commit_nonce');

        $token = sanitize_text_field(wp_unslash($_POST['token'] ?? ''));
        $payload = get_transient('uapm_import_' . $token);
        if (!$payload || (int) $payload['user_id'] !== get_current_user_id()) {
            self::redirect_error('Попередній перегляд застарів. Завантажте файл ще раз.');
        }

        $type = $payload['type'];
        $config = self::types()[$type];
        $headers = $payload['headers'];
        $mapping = array();
        foreach ($config['fields'] as $field => $label) {
            $selected = isset($_POST['map'][$field]) ? (string) absint($_POST['map'][$field]) : '';
            if (!isset($_POST['map'][$field]) || $_POST['map'][$field] === '') {
                $selected = '';
            }
            if ($selected !== '' && !array_key_exists((int) $selected, $headers)) {
                $selected = '';
            }
            $mapping[$field] = $selected;
        }

        foreach ($config['required'] as $required) {
            if ($mapping[$required] === '') {
                self::redirect_error('Не зіставлено обов’язкове поле: ' . $config['fields'][$required]);
            }
        }

        if (!empty($_POST['save_mapping'])) {
            $preset = array();
            foreach ($mapping as $field => $index) {
                if ($index !== '') {
                    $preset[$field] = $headers[(int) $index];
                }
            }
            update_option('uapm_import_mapping_' . $type, $preset, false);
        }

        global $wpdb;
        $table = $wpdb->prefix . $config['table'];
        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        $errors = array();

        foreach ($payload['rows'] as $row_index => $row) {
            $record = array();
            foreach ($mapping as $field => $index) {
                if ($index === '') {
                    continue;
                }
                $value = trim((string) ($row[(int) $index] ?? ''));
                if ($value === '') {
                    continue; // Empty CSV cells never erase existing values.
                }
                if ($field === 'email') {
                    $value = sanitize_email($value);
                } elseif (in_array($field, array('website_url', 'documentation_url', 'logo_url'), true)) {
                    $value = esc_url_raw($value);
                } elseif ($field === 'mailing_consent') {
                    $value = in_array(strtolower($value), array('1', 'yes', 'true', 'так', 'згоден', 'да'), true) ? 1 : 0;
                } elseif (in_array($field, array('description', 'notes'), true)) {
                    $value = sanitize_textarea_field($value);
                } else {
                    $value = sanitize_text_field($value);
                }
                $record[$field] = $value;
            }

            foreach ($config['required'] as $required) {
                if (empty($record[$required])) {
                    $skipped++;
                    continue 2;
                }
            }

            if ($type === 'projects') {
                $record['project_key'] = sanitize_title($record['project_key']);
                if (!$record['project_key']) {
                    $skipped++;
                    continue;
                }
                if (empty($record['slug'])) {
                    $record['slug'] = $record['project_key'];
                }
                $record['slug'] = sanitize_title($record['slug']);
                if (empty($record['project_type'])) $record['project_type'] = 'other';
                if (empty($record['status'])) $record['status'] = 'active';
                if (empty($record['github_branch'])) $record['github_branch'] = 'main';
            } else {
                if (!is_email($record['email'])) {
                    $skipped++;
                    continue;
                }
                if (isset($record['mailing_consent'])) {
                    $record['mailing_consent'] = (int) $record['mailing_consent'];
                }
            }

            $existing_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE {$config['unique']} = %s LIMIT 1",
                $record[$config['unique']]
            ));

            $now = current_time('mysql');
            if ($existing_id) {
                $record['updated_at'] = $now;
                $result = $wpdb->update($table, $record, array('id' => absint($existing_id)));
                if ($result === false) {
                    $skipped++;
                    $errors[] = 'Рядок ' . ($row_index + 2) . ': ' . $wpdb->last_error;
                } else {
                    $updated++;
                }
            } else {
                if ($type === 'projects') {
                    $record += array(
                        'description' => '',
                        'project_type' => 'other',
                        'status' => 'active',
                        'version' => '1.0.0',
                        'author' => '',
                        'website_url' => '',
                        'documentation_url' => '',
                        'github_repository' => '',
                        'github_branch' => 'main',
                        'logo_url' => '',
                    );
                } else {
                    $record += array(
                        'name' => '',
                        'phone' => '',
                        'company' => '',
                        'country' => '',
                        'customer_type' => 'individual',
                        'status' => 'active',
                        'mailing_consent' => 0,
                        'notes' => '',
                        'user_id' => 0,
                    );
                }
                $record['created_at'] = $now;
                $record['updated_at'] = $now;
                $result = $wpdb->insert($table, $record);
                if (!$result) {
                    $skipped++;
                    $errors[] = 'Рядок ' . ($row_index + 2) . ': ' . $wpdb->last_error;
                } else {
                    $inserted++;
                }
            }
        }

        delete_transient('uapm_import_' . $token);
        update_option('uapm_last_import', array(
            'type' => $type,
            'inserted' => $inserted,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => array_slice($errors, 0, 10),
            'date' => current_time('mysql'),
        ), false);

        wp_safe_redirect(self::page_url(array(
            'imported' => 1,
            'inserted' => $inserted,
            'updated' => $updated,
            'skipped' => $skipped,
        )));
        exit;
    }

    public static function render_page() {
        if (!current_user_can('uapm_manage_settings') && !current_user_can('manage_options')) {
            wp_die('Недостатньо прав.');
        }

        $types = self::types();
        $last = get_option('uapm_last_import', array());
        echo '<div class="wrap uapm-wrap"><h1>Імпорт даних</h1>';

        if (!empty($_GET['uapm_import_error'])) {
            echo '<div class="notice notice-error"><p>' . esc_html(rawurldecode(wp_unslash($_GET['uapm_import_error']))) . '</p></div>';
        }
        if (isset($_GET['imported'])) {
            echo '<div class="notice notice-success"><p>Імпорт завершено. Додано: ' . absint($_GET['inserted'] ?? 0) . '; оновлено: ' . absint($_GET['updated'] ?? 0) . '; пропущено: ' . absint($_GET['skipped'] ?? 0) . '.</p></div>';
        }

        $token = sanitize_text_field(wp_unslash($_GET['token'] ?? ''));
        $payload = $token ? get_transient('uapm_import_' . $token) : false;
        if (!empty($_GET['stage']) && $_GET['stage'] === 'map' && $payload && (int) $payload['user_id'] === get_current_user_id()) {
            self::render_mapping($token, $payload);
        } else {
            echo '<div class="uapm-card"><h2>Завантаження CSV</h2>';
            echo '<p>Можна використовувати українські або англійські заголовки. Після завантаження перевірте зіставлення колонок перед записом.</p>';
            echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="uapm_import_preview">';
            wp_nonce_field('uapm_import_preview', 'uapm_import_nonce');
            echo '<p><label><strong>Що імпортуємо</strong></label><br><select name="import_type">';
            foreach ($types as $key => $config) {
                echo '<option value="' . esc_attr($key) . '">' . esc_html($config['label']) . '</option>';
            }
            echo '</select></p>';
            echo '<p><label><strong>CSV-файл</strong></label><br><input type="file" name="uapm_import_file" accept=".csv,text/csv" required></p>';
            echo '<p><label><strong>Роздільник колонок</strong></label><br><select name="delimiter"><option value="auto">Визначити автоматично</option><option value=";">Крапка з комою (;)</option><option value=",">Кома (,)</option><option value="tab">Табуляція (Tab)</option><option value="|">Вертикальна риска (|)</option></select></p>';
            submit_button('Завантажити та переглянути');
            echo '</form></div>';
        }

        if (!empty($last['date'])) {
            echo '<div class="uapm-card"><h2>Останній імпорт</h2><p>' . esc_html($last['date']) . ' — ' . esc_html($types[$last['type']]['label'] ?? $last['type']) . '</p>';
            echo '<p>Додано: ' . absint($last['inserted']) . ' | Оновлено: ' . absint($last['updated']) . ' | Пропущено: ' . absint($last['skipped']) . '</p>';
            if (!empty($last['errors'])) {
                echo '<details><summary>Деталі помилок</summary><ul>';
                foreach ($last['errors'] as $error) echo '<li>' . esc_html($error) . '</li>';
                echo '</ul></details>';
            }
            echo '</div>';
        }
        echo '</div>';
    }

    private static function render_mapping($token, $payload) {
        $type = $payload['type'];
        $config = self::types()[$type];
        $headers = $payload['headers'];
        $mapping = self::suggested_mapping($headers, $type);
        $saved = get_option('uapm_import_mapping_' . $type, array());

        foreach ($config['fields'] as $field => $label) {
            if (!empty($saved[$field])) {
                foreach ($headers as $index => $header) {
                    if ($header === $saved[$field]) {
                        $mapping[$field] = (string) $index;
                        break;
                    }
                }
            }
        }

        echo '<div class="uapm-card"><h2>Перевірка та зіставлення колонок</h2>';
        echo '<p>Роздільник: <code>' . esc_html($payload['delimiter'] === "\t" ? 'Tab' : $payload['delimiter']) . '</code>. Рядків даних: ' . count($payload['rows']) . '. Зіставте кожне поле з відповідною колонкою CSV.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="uapm_import_commit"><input type="hidden" name="token" value="' . esc_attr($token) . '">';
        wp_nonce_field('uapm_import_commit', 'uapm_import_commit_nonce');
        echo '<table class="widefat striped"><thead><tr><th>Поле системи</th><th>Колонка у CSV</th></tr></thead><tbody>';
        foreach ($config['fields'] as $field => $label) {
            echo '<tr><td><strong>' . esc_html($label) . '</strong>' . (in_array($field, $config['required'], true) ? ' <span style="color:#b32d2e">*</span>' : '') . '</td><td><select name="map[' . esc_attr($field) . ']"><option value="">— Не імпортувати —</option>';
            foreach ($headers as $index => $header) {
                echo '<option value="' . (int) $index . '"' . selected($mapping[$field], (string) $index, false) . '>' . esc_html($header) . '</option>';
            }
            echo '</select></td></tr>';
        }
        echo '</tbody></table>';
        echo '<h3>Попередній перегляд CSV</h3><div style="overflow-x:auto"><table class="widefat striped"><thead><tr>';
        foreach ($headers as $header) echo '<th>' . esc_html($header) . '</th>';
        echo '</tr></thead><tbody>';
        foreach (array_slice($payload['rows'], 0, 5) as $row) {
            echo '<tr>';
            foreach ($headers as $index => $header) echo '<td>' . esc_html($row[$index] ?? '') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        echo '<p><label><input type="checkbox" name="save_mapping" value="1" checked> Запам’ятати це зіставлення для наступного імпорту</label></p>';
        echo '<p class="description">Обов’язкові поля позначені зірочкою. При повторному імпорті запис із таким самим ключем проєкту або email буде оновлено. Порожні клітинки не стирають збережені дані.</p>';
        submit_button('Підтвердити імпорт');
        echo '</form><p><a href="' . esc_url(self::page_url()) . '">Скасувати та повернутися</a></p></div>';
    }
}
