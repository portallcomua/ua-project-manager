
<?php
if (!defined('ABSPATH')) {
    exit;
}

class UAPM_Import {

    public static function init() {
        add_action('admin_post_uapm_import_csv', array(__CLASS__, 'handle_import'));
    }

    private static function authorize() {
        if (!current_user_can('uapm_manage_settings')) {
            wp_die('Недостатньо прав для імпорту.');
        }
        check_admin_referer('uapm_import_csv', 'uapm_import_nonce');
    }

    private static function allowed_types() {
        return array(
            'projects' => array(
                'table' => 'uapm_projects',
                'columns' => array('name', 'slug', 'description', 'project_type', 'status', 'version', 'github_repository'),
                'required' => array('name', 'slug'),
                'unique' => 'slug',
            ),
            'customers' => array(
                'table' => 'uapm_customers',
                'columns' => array('name', 'email', 'phone', 'company', 'country', 'customer_type', 'status', 'mailing_consent', 'notes'),
                'required' => array('email'),
                'unique' => 'email',
            ),
        );
    }

    public static function handle_import() {
        self::authorize();

        if (empty($_FILES['uapm_import_file']['tmp_name']) || !is_uploaded_file($_FILES['uapm_import_file']['tmp_name'])) {
            self::redirect('missing_file');
        }

        $type = sanitize_key(wp_unslash($_POST['import_type'] ?? ''));
        $types = self::allowed_types();

        if (!isset($types[$type])) {
            self::redirect('invalid_type');
        }

        $file = $_FILES['uapm_import_file'];

        if (!empty($file['error']) || (int) $file['size'] > 5 * MB_IN_BYTES) {
            self::redirect('invalid_file');
        }

        if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'csv') {
            self::redirect('invalid_format');
        }

        $handle = fopen($file['tmp_name'], 'r');
        if (!$handle) {
            self::redirect('read_error');
        }

        $header = fgetcsv($handle, 0, ';');
        if (!$header || count($header) > 50) {
            fclose($handle);
            self::redirect('invalid_header');
        }

        $header = array_map(function ($value) {
            return sanitize_key(trim((string) $value));
        }, $header);

        $config = $types[$type];

        if (array_diff($config['required'], $header)) {
            fclose($handle);
            self::redirect('missing_columns');
        }

        global $wpdb;

        $table = $wpdb->prefix . $config['table'];
        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        $line = 1;

        while (($data = fgetcsv($handle, 0, ';')) !== false) {
            if (++$line > 10001) {
                break;
            }

            if (count($data) !== count($header)) {
                $skipped++;
                continue;
            }

            $record = array();

            foreach ($header as $index => $column) {
                if (!in_array($column, $config['columns'], true)) {
                    continue;
                }

                $value = trim((string) ($data[$index] ?? ''));

                if ($column === 'mailing_consent') {
                    $record[$column] = in_array(strtolower($value), array('1', 'yes', 'true', 'так', 'згоден'), true) ? 1 : 0;
                } elseif ($column === 'email') {
                    $record[$column] = sanitize_email($value);
                } elseif ($column === 'github_repository') {
                    $record[$column] = sanitize_text_field($value);
                } else {
                    $record[$column] = sanitize_text_field($value);
                }
            }

            $valid = true;
            foreach ($config['required'] as $required) {
                if (empty($record[$required])) {
                    $valid = false;
                }
            }

            if (!$valid) {
                $skipped++;
                continue;
            }

            if ($type === 'projects') {
                $record['slug'] = sanitize_title($record['slug']);
                $record['status'] = $record['status'] ?? 'active';
                $record['project_type'] = $record['project_type'] ?? 'other';
            }

            if ($type === 'customers') {
                if (!is_email($record['email'])) {
                    $skipped++;
                    continue;
                }
                $record['status'] = $record['status'] ?? 'active';
                $record['mailing_consent'] = $record['mailing_consent'] ?? 0;
            }

            $unique = $config['unique'];
            $existing_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE {$unique} = %s LIMIT 1",
                $record[$unique]
            ));

            if ($existing_id) {
                $result = $wpdb->update($table, $record, array('id' => absint($existing_id)));
                $result !== false ? $updated++ : $skipped++;
            } else {
                $result = $wpdb->insert($table, $record);
                $result !== false ? $inserted++ : $skipped++;
            }
        }

        fclose($handle);

        update_option('uapm_last_import', array(
            'type' => $type,
            'inserted' => $inserted,
            'updated' => $updated,
            'skipped' => $skipped,
            'date' => current_time('mysql'),
        ), false);

        wp_safe_redirect(add_query_arg(array(
            'page' => 'uapm-import',
            'imported' => 1,
            'inserted' => $inserted,
            'updated' => $updated,
            'skipped' => $skipped,
        ), admin_url('admin.php')));
        exit;
    }

    private static function redirect($error) {
        wp_safe_redirect(add_query_arg(array(
            'page' => 'uapm-import',
            'import_error' => sanitize_key($error),
        ), admin_url('admin.php')));
        exit;
    }

    public static function render_page() {
        if (!current_user_can('uapm_manage_settings')) {
            wp_die('Недостатньо прав.');
        }

        $last = get_option('uapm_last_import', array());

        echo '<div class="wrap uapm-wrap"><h1>Імпорт даних</h1><div class="uapm-card">';
        echo '<p>CSV із роздільником крапка з комою. Максимум 5 МБ та 10 000 рядків.</p>';

        if (isset($_GET['imported'])) {
            echo '<div class="notice notice-success"><p>Додано: ' . absint($_GET['inserted'] ?? 0) . '; оновлено: ' . absint($_GET['updated'] ?? 0) . '; пропущено: ' . absint($_GET['skipped'] ?? 0) . '.</p></div>';
        }

        if (isset($_GET['import_error'])) {
            echo '<div class="notice notice-error"><p>Помилка імпорту: ' . esc_html(sanitize_key(wp_unslash($_GET['import_error']))) . '</p></div>';
        }

        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="uapm_import_csv">';
        wp_nonce_field('uapm_import_csv', 'uapm_import_nonce');
        echo '<p><label for="uapm-import-type"><strong>Тип даних</strong></label></p>';
        echo '<select id="uapm-import-type" name="import_type" required><option value="projects">Проєкти</option><option value="customers">Клієнти</option></select>';
        echo '<p><label for="uapm-import-file"><strong>CSV-файл</strong></label></p>';
        echo '<input type="file" id="uapm-import-file" name="uapm_import_file" accept=".csv,text/csv" required>';
        submit_button('Імпортувати CSV');
        echo '</form></div>';

        if (!empty($last['date'])) {
            echo '<div class="uapm-card"><h2>Останній імпорт</h2>';
            echo '<p>Дата: ' . esc_html($last['date']) . '</p>';
            echo '<p>Тип: ' . esc_html($last['type']) . '</p>';
            echo '<p>Додано: ' . absint($last['inserted']) . '</p>';
            echo '<p>Оновлено: ' . absint($last['updated']) . '</p>';
            echo '<p>Пропущено: ' . absint($last['skipped']) . '</p></div>';
        }

        echo '</div>';
    }
}
