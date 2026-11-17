<?php
if (!defined('ABSPATH')) {
    exit;
}

class UAPM_Dashboard_Widgets {

    public static function init() {
        add_action('wp_dashboard_setup', array(__CLASS__, 'register_widgets'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_styles'));
    }

    public static function register_widgets() {
        if (!current_user_can('uapm_manage_projects')) {
            return;
        }

        wp_add_dashboard_widget(
            'uapm_overview_widget',
            'UA Project Manager — Огляд',
            array(__CLASS__, 'render_overview')
        );

        wp_add_dashboard_widget(
            'uapm_alerts_widget',
            'UA Project Manager — Сповіщення',
            array(__CLASS__, 'render_alerts')
        );
    }

    public static function enqueue_styles($hook) {
        if ($hook !== 'index.php') {
            return;
        }

        wp_register_style('uapm-dashboard-widgets', false, array(), UAPM_VERSION);
        wp_enqueue_style('uapm-dashboard-widgets');

        wp_add_inline_style(
            'uapm-dashboard-widgets',
            '
            #uapm_overview_widget .inside,
            #uapm_alerts_widget .inside {
                padding: 14px;
            }
            .uapm-widget-grid {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 10px;
            }
            .uapm-widget-stat {
                background: #f6f7f7;
                border: 1px solid #dcdcde;
                border-radius: 8px;
                padding: 12px;
                min-width: 0;
            }
            .uapm-widget-stat strong {
                display: block;
                font-size: 22px;
                line-height: 1.3;
                overflow-wrap: anywhere;
            }
            .uapm-widget-stat span {
                display: block;
                color: #646970;
                font-size: 12px;
                margin-top: 4px;
            }
            .uapm-widget-alert {
                padding: 10px 12px;
                margin: 0 0 8px;
                border-left: 4px solid #dba617;
                background: #fcf9e8;
            }
            .uapm-widget-alert.error {
                border-color: #d63638;
                background: #fcf0f1;
            }
            @media(max-width: 600px) {
                .uapm-widget-grid {
                    grid-template-columns: 1fr 1fr;
                }
            }
            '
        );
    }

    private static function count_table($table_suffix) {
        global $wpdb;

        $table = $wpdb->prefix . sanitize_key($table_suffix);

        $exists = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $table)
        );

        if ($exists !== $table) {
            return 0;
        }

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
    }

    public static function render_overview() {
        if (!current_user_can('uapm_manage_projects')) {
            return;
        }

        $projects = self::count_table('uapm_projects');
        $customers = self::count_table('uapm_customers');
        $payments = self::count_table('uapm_payments');
        $installations = self::count_table('uapm_installations');

        echo '<div class="uapm-widget-grid">';

        $stats = array(
            'Проєкти' => $projects,
            'Клієнти' => $customers,
            'Платежі' => $payments,
            'Інсталяції' => $installations,
        );

        foreach ($stats as $label => $value) {
            echo '<div class="uapm-widget-stat">';
            echo '<strong>' . esc_html(number_format_i18n($value)) . '</strong>';
            echo '<span>' . esc_html($label) . '</span>';
            echo '</div>';
        }

        echo '</div>';

        echo '<p style="margin-top:14px">';
        echo '<a href="' . esc_url(admin_url('admin.php?page=uapm')) . '">Відкрити UA Project Manager</a>';
        echo '</p>';
    }

    public static function render_alerts() {
        if (!current_user_can('uapm_manage_settings')) {
            echo '<p>Немає доступу до системних сповіщень.</p>';
            return;
        }

        $alerts = array();

        if (class_exists('UAPM_Queue')) {
            global $wpdb;

            $queue_table = $wpdb->prefix . 'uapm_queue';
            $exists = $wpdb->get_var(
                $wpdb->prepare('SHOW TABLES LIKE %s', $queue_table)
            );

            if ($exists === $queue_table) {
                $failed = (int) $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$queue_table} WHERE status = 'failed'"
                );

                if ($failed > 0) {
                    $alerts[] = array(
                        'type' => 'error',
                        'text' => 'Завдань із помилками в черзі: ' . $failed,
                        'url' => admin_url('admin.php?page=uapm-queue'),
                    );
                }
            }
        }

        if (class_exists('UAPM_Logs')) {
            $errors = UAPM_Logs::get_error_count(24);

            if ($errors > 0) {
                $alerts[] = array(
                    'type' => 'error',
                    'text' => 'Системних помилок за 24 години: ' . $errors,
                    'url' => admin_url('admin.php?page=uapm-logs'),
                );
            }
        }

        if (class_exists('UAPM_Diagnostics')) {
            $summary = UAPM_Diagnostics::get_summary();

            if (!empty($summary['error'])) {
                $alerts[] = array(
                    'type' => 'error',
                    'text' => 'Помилок у діагностиці: ' . absint($summary['error']),
                    'url' => admin_url('admin.php?page=uapm-diagnostics'),
                );
            }

            if (!empty($summary['warning'])) {
                $alerts[] = array(
                    'type' => 'warning',
                    'text' => 'Попереджень у діагностиці: ' . absint($summary['warning']),
                    'url' => admin_url('admin.php?page=uapm-diagnostics'),
                );
            }
        }

        if (!$alerts) {
            echo '<p>Критичних сповіщень немає.</p>';
            return;
        }

        foreach ($alerts as $alert) {
            $type = $alert['type'] === 'error' ? 'error' : '';

            echo '<div class="uapm-widget-alert ' . esc_attr($type) . '">';
            echo '<a href="' . esc_url($alert['url']) . '">';
            echo esc_html($alert['text']);
            echo '</a>';
            echo '</div>';
        }
    }
}