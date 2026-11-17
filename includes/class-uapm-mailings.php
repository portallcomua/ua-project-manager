<?php
if (!defined('ABSPATH')) {
    exit;
}

class UAPM_Mailings {

    public static function init() {
        add_action('admin_post_uapm_send_mailing', array(__CLASS__, 'send_mailing'));
        add_action('admin_post_uapm_save_mailing', array(__CLASS__, 'save_mailing'));
    }

    private static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'uapm_mailings';
    }

    public static function install_schema() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            message LONGTEXT NOT NULL,
            project_id BIGINT UNSIGNED DEFAULT 0,
            status VARCHAR(30) DEFAULT 'draft',
            recipient_count INT UNSIGNED DEFAULT 0,
            sent_count INT UNSIGNED DEFAULT 0,
            failed_count INT UNSIGNED DEFAULT 0,
            created_by BIGINT UNSIGNED DEFAULT 0,
            created_at DATETIME NOT NULL,
            sent_at DATETIME DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY project_id (project_id),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset;";

        dbDelta($sql);
    }

    public static function create($data) {
        global $wpdb;

        $name = sanitize_text_field($data['name'] ?? '');
        $subject = sanitize_text_field($data['subject'] ?? '');
        $message = wp_kses_post($data['message'] ?? '');

        if (!$name || !$subject || !$message) {
            return new WP_Error('uapm_invalid_mailing', 'Заповніть назву, тему та текст розсилки.');
        }

        $result = $wpdb->insert(
            self::table_name(),
            array(
                'name' => $name,
                'subject' => $subject,
                'message' => $message,
                'project_id' => absint($data['project_id'] ?? 0),
                'status' => 'draft',
                'created_by' => get_current_user_id(),
                'created_at' => current_time('mysql'),
            )
        );

        return $result ? (int) $wpdb->insert_id : new WP_Error('uapm_mailing_error', 'Не вдалося створити розсилку.');
    }

    public static function get($id) {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table_name() . " WHERE id = %d",
            absint($id)
        ));
    }

    public static function get_all($limit = 50) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::table_name() . " ORDER BY created_at DESC LIMIT %d",
            min(200, max(1, absint($limit)))
        ));
    }

    public static function get_recipients($project_id = 0) {
        global $wpdb;

        $table = $wpdb->prefix . 'uapm_customers';

        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

        if ($exists !== $table) {
            return array();
        }

        if ($project_id) {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT DISTINCT email, name FROM $table
                 WHERE project_id = %d AND email <> '' AND mailing_consent = 1",
                absint($project_id)
            ));
        }

        return $wpdb->get_results(
            "SELECT DISTINCT email, name FROM $table
             WHERE email <> '' AND mailing_consent = 1"
        );
    }

    public static function save_mailing() {
        if (!current_user_can('uapm_manage_projects')) {
            wp_die('Недостатньо прав.');
        }

        check_admin_referer('uapm_save_mailing');

        $id = self::create(array(
            'name' => wp_unslash($_POST['name'] ?? ''),
            'subject' => wp_unslash($_POST['subject'] ?? ''),
            'message' => wp_unslash($_POST['message'] ?? ''),
            'project_id' => absint($_POST['project_id'] ?? 0),
        ));

        $url = admin_url('admin.php?page=uapm-mailings');

        if (is_wp_error($id)) {
            $url = add_query_arg('uapm_error', rawurlencode($id->get_error_message()), $url);
        } else {
            $url = add_query_arg('uapm_saved', '1', $url);
        }

        wp_safe_redirect($url);
        exit;
    }

    public static function send_mailing() {
        if (!current_user_can('uapm_manage_projects')) {
            wp_die('Недостатньо прав.');
        }

        check_admin_referer('uapm_send_mailing');

        $id = absint($_POST['mailing_id'] ?? 0);
        $mailing = self::get($id);

        if (!$mailing || $mailing->status === 'sent') {
            wp_die('Розсилку не знайдено або її вже відправлено.');
        }

        $recipients = self::get_recipients($mailing->project_id);
        $sent = 0;
        $failed = 0;

        foreach ($recipients as $recipient) {
            $email = sanitize_email($recipient->email);

            if (!is_email($email)) {
                $failed++;
                continue;
            }

            $name = sanitize_text_field($recipient->name);
            $message = str_replace(
                array('{{name}}', '{{email}}'),
                array($name, $email),
                $mailing->message
            );

            $headers = array('Content-Type: text/html; charset=UTF-8');

            if (wp_mail($email, $mailing->subject, wpautop($message), $headers)) {
                $sent++;
            } else {
                $failed++;
            }
        }

        global $wpdb;

        $wpdb->update(
            self::table_name(),
            array(
                'status' => 'sent',
                'recipient_count' => count($recipients),
                'sent_count' => $sent,
                'failed_count' => $failed,
                'sent_at' => current_time('mysql'),
            ),
            array('id' => $id)
        );

        wp_safe_redirect(add_query_arg(
            array(
                'page' => 'uapm-mailings',
                'uapm_sent' => $sent,
                'uapm_failed' => $failed,
            ),
            admin_url('admin.php')
        ));
        exit;
    }

    public static function render_page() {
        if (!current_user_can('uapm_manage_projects')) {
            wp_die('Недостатньо прав.');
        }

        $mailings = self::get_all();

        echo '<div class="wrap uapm-admin">';
        echo '<h1>Розсилки</h1>';

        if (isset($_GET['uapm_sent'])) {
            echo '<div class="notice notice-success"><p>Відправлено: '
                . absint($_GET['uapm_sent'])
                . '; помилок: '
                . absint($_GET['uapm_failed'] ?? 0)
                . '.</p></div>';
        }

        echo '<h2>Створити розсилку</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="uapm_save_mailing">';
        wp_nonce_field('uapm_save_mailing');

        echo '<table class="form-table">';
        echo '<tr><th><label for="uapm-mailing-name">Назва</label></th><td><input required class="regular-text" id="uapm-mailing-name" name="name"></td></tr>';
        echo '<tr><th><label for="uapm-mailing-subject">Тема листа</label></th><td><input required class="regular-text" id="uapm-mailing-subject" name="subject"></td></tr>';
        echo '<tr><th><label for="uapm-mailing-project">Проєкт</label></th><td><input type="number" min="0" id="uapm-mailing-project" name="project_id" value="0"><p class="description">0 — усі проєкти.</p></td></tr>';
        echo '<tr><th><label for="uapm-mailing-message">Текст</label></th><td><textarea required class="large-text" rows="10" id="uapm-mailing-message" name="message"></textarea><p class="description">Мітки: {{name}}, {{email}}.</p></td></tr>';
        echo '</table>';

        submit_button('Зберегти чернетку');
        echo '</form>';

        echo '<h2>Історія розсилок</h2>';
        echo '<table class="widefat striped"><thead><tr><th>Назва</th><th>Тема</th><th>Статус</th><th>Отримувачі</th><th>Надіслано</th><th>Помилки</th><th>Дія</th></tr></thead><tbody>';

        if ($mailings) {
            foreach ($mailings as $mailing) {
                echo '<tr>';
                echo '<td>' . esc_html($mailing->name) . '</td>';
                echo '<td>' . esc_html($mailing->subject) . '</td>';
                echo '<td>' . esc_html($mailing->status) . '</td>';
                echo '<td>' . absint($mailing->recipient_count) . '</td>';
                echo '<td>' . absint($mailing->sent_count) . '</td>';
                echo '<td>' . absint($mailing->failed_count) . '</td>';
                echo '<td>';

                if ($mailing->status !== 'sent') {
                    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                    echo '<input type="hidden" name="action" value="uapm_send_mailing">';
                    echo '<input type="hidden" name="mailing_id" value="' . absint($mailing->id) . '">';
                    wp_nonce_field('uapm_send_mailing');
                    submit_button('Відправити', 'secondary small', 'submit', false);
                    echo '</form>';
                } else {
                    echo 'Завершено';
                }

                echo '</td></tr>';
            }
        } else {
            echo '<tr><td colspan="7">Розсилок поки немає.</td></tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }
}