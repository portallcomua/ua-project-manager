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
                'columns' => array(
                    'name', 'slug', 'description', 'project_type',
                    'status', 'version', 'github_repo'
                ),
                'required' => array('name', 'slug'),
                'unique' => 'slug',
            ),
            'customers' => array(
                'table' => 'uapm_customers',
                'columns' => array(
                    'name', 'email', 'phone', 'company', 'country',
                    'customer_type', 'status', 'mailing_consent', 'notes'
                ),
                'required' => array('email'),
                'unique' => 'email',
            ),
        );
    }

    public static function handle_import() {
        self::authorize();

        if (
            empty($_FILES['uapm_import_file']['tmp_name']) ||
            !is_uploaded_file($_FILES['uapm_import_file']['tmp_name'])
        ) {
            self::redirect('missing_file');
        }

        $type = isset($_POST['import_type'])
            ? sanitize_key(wp_unslash($_POST['import_type']))
            : '';

        $types = self::allowed_types();

        if (!isset($types[$type])) {
            self::redirect('invalid_type');
        }

        $file = $_FILES['uapm_import_file'];

        if (!empty($file['error']) || (int) $file['size'] > 5 * MB_IN_BYTES) {
            self::redirect('invalid_file');
        }

        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if ($extension !== 'csv') {
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

        $header = array_map(
            function ($value) {
                return sanitize_key(trim((string) $value));
            },
            $header
        );

        $config = $types[$type];

        if (array_diff($config['required'], $header)) {
            fclose($handle);
            self::redirect('missing_columns');
        }

        $allowed_columns = $config['columns'];
        $mapped_columns = array_values(array_intersect($header, $allowed_columns));

        if (!$mapped_columns) {
            fclose($handle);
            self::redirect('no_columns');
        }

        global $wpdb;

        $table = $wpdb->prefix . $config['table'];
        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        $line = 1;

        while (($data = fgetcsv($handle, 0, ';')) !== false) {
            $line++;

            if ($line > 10001) {
                break;
            }

            if (count($data) !== count($header)) {
                $skipped++;
                continue;
            }

            $record = array();

            foreach ($header as $index => $column) {
                if (!in_array($column, $allowed_columns, true)) {
                    continue;
                }

                $value = isset($data[$index])
                    ? trim((string) $data[$index])
                    : '';

                if ($column === 'mailing_consent') {
                    $record[$column] = in_array(
                        strtolower($value),
                        array('1', 'yes', 'true', 'так', 'згоден'),
                        true
                    ) ? 1 : 0;
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

                if (empty($record['status'])) {
                    $record['status'] = 'active';
                }

                if (empty($record['project_type'])) {
                    $record['project_type'] = 'other';
                }
            }

            if ($type === 'customers') {
                $record['email'] = sanitize_email($record['email']);

                if (!is_email($record['email'])) {
                    $skipped++;
                    continue;
                }

                if (empty($record['status'])) {
                    $record['status'] = 'active';
                }

                if (!isset($record['mailing_consent'])) {
                    $record['mailing_consent'] = 0;
                }
            }

            $unique_column = $config['unique'];
            $unique_value = $record[$unique_column];

            $existing_id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$table} WHERE {$unique_column} = %s LIMIT 1",
                    $unique_value
                )
            );

            if ($existing_id) {
                $result = $wpdb->update(
                    $table,
                    $record,
                    array('id' => absint($existing_id))
                );

                if ($result !== false) {
                    $updated++;
                } else {
                    $skipped++;
                }
            } else {
                $result = $wpdb->insert($table, $record);

                if ($result !== false) {
                    $inserted++;
                } else {
                    $skipped++;
                }
            }
        }

        fclose($handle);

        update_option(
            'uapm_last_import',
            array(
                'type' => $type,
                'inserted' => $inserted,
                'updated' => $updated,
                'skipped' => $skipped,
                'date' => current_time('mysql'),
            ),
            false
        );

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page' => 'uapm-import',
                    'imported' => 1,
                    'inserted' => $inserted,
                    'updated' => $updated,
                    'skipped' => $skipped,
                ),
                admin_url('admin.php')
            )
        );
        exit;
    }

    private static function redirect($error) {
        wp_safe_redirect(
            add_query_arg(
                array(
                    'page' => 'uapm-import',
                    'import_error' => sanitize_key($error),
                ),
                admin_url('admin.php')
            )
        );
        exit;
    }

    public static function render_page() {
        if (!current_user_can('uapm_manage_settings')) {
            wp_die('Недостатньо прав.');
        }

        $last = get_option('uapm_last_import', array());

        echo '<div class="wrap uapm-wrap">';
        echo '<h1>Імпорт даних</h1>';
        echo '<div class="uapm-card">';
        echo '<p>Завантажте CSV-файл із роздільником крапка з комою. Максимальний розмір — 5 МБ, до 10 000 рядків.</p>';

        if (isset($_GET['imported'])) {
            echo '<div class="notice notice-success"><p>';
            echo 'Імпорт завершено. Додано: ' . esc_html(absint($_GET['inserted'] ?? 0));
            echo ', оновлено: ' . esc_html(absint($_GET['updated'] ?? 0));
            echo ', пропущено: ' . esc_html(absint($_GET['skipped'] ?? 0));
            echo '</p></div>';
        }

        if (isset($_GET['import_error'])) {
            echo '<div class="notice notice-error"><p>';
            echo 'Помилка імпорту: ' . esc_html(sanitize_key(wp_unslash($_GET['import_error'])));
            echo '</p></div>';
        }

        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="uapm_import_csv">';
        wp_nonce_field('uapm_import_csv', 'uapm_import_nonce');

        echo '<p><label for="uapm-import-type"><strong>Тип даних</strong></label></p>';
        echo '<select id="uapm-import-type" name="import_type" required>';
        echo '<option value="projects">Проєкти</option>';
        echo '<option value="customers">Клієнти</option>';
        echo '</select>';

        echo '<p><label for="uapm-import-file"><strong>CSV-файл</strong></label></p>';
        echo '<input type="file" id="uapm-import-file" name="uapm_import_file" accept=".csv,text/csv" required>';

        echo '<p class="description">Під час імпорту наявні записи з таким самим slug або email будуть оновлені.</p>';

        submit_button('Імпортувати CSV');
        echo '</form>';
        echo '</div>';

        if (!empty($last['date'])) {
            echo '<div class="uapm-card">';
            echo '<h2>Останній імпорт</h2>';
            echo '<p>Дата: ' . esc_html($last['date']) . '</p>';
            echo '<p>Тип: ' . esc_html($last['type']) . '</p>';
            echo '<p>Додано: ' . esc_html($last['inserted']) . '</p>';
            echo '<p>Оновлено: ' . esc_html($last['updated']) . '</p>';
            echo '<p>Пропущено: ' . esc_html($last['skipped']) . '</p>';
            echo '</div>';
        }

        echo '</div>';
    }
}