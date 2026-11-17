<?php
/**
 * UA Project Manager - Frontend Management Portal.
 *
 * @package UA_Project_Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

final class UAPM_Portal {

    const VERSION = '1.1.0';
    const QUERY_VAR = 'uapm_portal';

    public static function init() {
        add_action('init', array(__CLASS__, 'register_rewrite'));
        add_filter('query_vars', array(__CLASS__, 'register_query_var'));
        add_action('template_redirect', array(__CLASS__, 'render_portal'));
        add_action('admin_bar_menu', array(__CLASS__, 'admin_bar_link'), 90);
    }

    public static function register_rewrite() {
        add_rewrite_rule(
            '^gpm/?$',
            'index.php?' . self::QUERY_VAR . '=1',
            'top'
        );
    }

    public static function register_query_var($vars) {
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    private static function is_manager() {
        return current_user_can('uapm_view_own_clients') &&
            !current_user_can('uapm_manage_projects') &&
            !current_user_can('manage_options');
    }

    public static function admin_bar_link($bar) {
        if (
            !is_user_logged_in() ||
            (
                !current_user_can('uapm_manage_projects') &&
                !current_user_can('uapm_view_own_clients') &&
                !current_user_can('manage_options')
            )
        ) {
            return;
        }

        $bar->add_node(array(
            'id' => 'uapm-portal',
            'title' => 'UA Project Manager',
            'href' => home_url('/gpm/'),
        ));
    }

    public static function render_portal() {
        if (!get_query_var(self::QUERY_VAR)) {
            return;
        }

        if (!is_user_logged_in()) {
            auth_redirect();
        }

        $is_admin = current_user_can('uapm_manage_projects') ||
            current_user_can('manage_options');

        $is_manager = current_user_can('uapm_view_own_clients');

        if (!$is_admin && !$is_manager) {
            wp_die(
                esc_html__('У вас немає доступу до менеджера проєктів.', 'ua-project-manager'),
                esc_html__('Доступ заборонено', 'ua-project-manager'),
                array('response' => 403)
            );
        }

        if (!$is_admin && $is_manager) {
            self::render_manager_page();
        } else {
            self::render_admin_page();
        }

        exit;
    }

    private static function render_manager_page() {
        global $wpdb;

        $user = wp_get_current_user();
        $table = $wpdb->prefix . 'uapm_clients';
        $manager_id = get_current_user_id();

        $clients = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, name, email, company, phone, status, created_at
                 FROM {$table}
                 WHERE user_id = %d
                 ORDER BY created_at DESC
                 LIMIT 200",
                $manager_id
            )
        );

        $totals = class_exists('UAPM_Payments')
            ? UAPM_Payments::get_manager_totals($manager_id)
            : array();

        status_header(200);
        nocache_headers();
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>Кабінет менеджера — UA Project Manager</title>
            <?php wp_head(); ?>
            <style>
                body.uapm-portal-body {
                    margin: 0;
                    background: #f2f5f9;
                    color: #263445;
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                }
                .uapm-portal {
                    max-width: 1100px;
                    margin: 0 auto;
                    padding: 24px;
                }
                .uapm-header {
                    background: #173b68;
                    color: #fff;
                    border-radius: 14px;
                    padding: 24px;
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    flex-wrap: wrap;
                    gap: 15px;
                }
                .uapm-header h1 {
                    color: #fff;
                    margin: 0 0 7px;
                    font-size: 25px;
                }
                .uapm-header p {
                    color: #dce8f6;
                    margin: 0;
                }
                .uapm-header a {
                    color: #fff;
                }
                .uapm-card {
                    background: #fff;
                    border: 1px solid #e1e7ef;
                    border-radius: 12px;
                    padding: 20px;
                    margin-top: 20px;
                    min-width: 0;
                }
                .uapm-card h2 {
                    margin-top: 0;
                    font-size: 20px;
                }
                .uapm-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
                    gap: 15px;
                    margin-top: 20px;
                }
                .uapm-number {
                    color: #173b68;
                    font-size: 28px;
                    font-weight: 700;
                }
                .uapm-muted {
                    color: #6b7785;
                    font-size: 13px;
                }
                .uapm-table-wrap {
                    overflow-x: auto;
                }
                .uapm-table {
                    width: 100%;
                    border-collapse: collapse;
                    min-width: 600px;
                }
                .uapm-table th,
                .uapm-table td {
                    padding: 12px 10px;
                    text-align: left;
                    border-bottom: 1px solid #e1e7ef;
                }
                .uapm-table th {
                    background: #f7f9fc;
                }
                .uapm-footer {
                    margin-top: 25px;
                    color: #778392;
                    font-size: 12px;
                    text-align: center;
                }
                @media(max-width: 600px) {
                    .uapm-portal {
                        padding: 12px;
                    }
                    .uapm-header {
                        padding: 18px;
                    }
                    .uapm-header h1 {
                        font-size: 21px;
                    }
                }
            </style>
        </head>
        <body <?php body_class('uapm-portal-body'); ?>>
        <?php wp_body_open(); ?>

        <main class="uapm-portal">
            <header class="uapm-header">
                <div>
                    <h1>Кабінет менеджера</h1>
                    <p>UA Project Manager</p>
                </div>
                <div>
                    <?php echo esc_html($user->display_name); ?>
                    <br>
                    <a href="<?php echo esc_url(wp_logout_url(home_url('/gpm/'))); ?>">
                        Вийти
                    </a>
                </div>
            </header>

            <section class="uapm-grid">
                <div class="uapm-card">
                    <div class="uapm-muted">Мої клієнти</div>
                    <div class="uapm-number">
                        <?php echo esc_html(number_format_i18n(count($clients))); ?>
                    </div>
                </div>

                <?php foreach ($totals as $total) : ?>
                    <div class="uapm-card">
                        <div class="uapm-muted">
                            Нарахована комісія (<?php echo esc_html($total['currency']); ?>)
                        </div>
                        <div class="uapm-number">
                            <?php
                            echo esc_html(
                                number_format_i18n((float) $total['commission_total'], 2)
                            );
                            ?>
                        </div>
                        <div class="uapm-muted">
                            Отримано оплат:
                            <?php
                            echo esc_html(
                                number_format_i18n((float) $total['received_total'], 2)
                            );
                            ?>
                            <?php echo esc_html($total['currency']); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </section>

            <section class="uapm-card">
                <h2>Мої клієнти</h2>

                <div class="uapm-table-wrap">
                    <table class="uapm-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Клієнт</th>
                                <th>Email</th>
                                <th>Компанія</th>
                                <th>Телефон</th>
                                <th>Статус</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($clients)) : ?>
                            <tr>
                                <td colspan="6">Поки що клієнтів не призначено.</td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ($clients as $client) : ?>
                                <tr>
                                    <td><?php echo esc_html($client->id); ?></td>
                                    <td><?php echo esc_html($client->name); ?></td>
                                    <td><?php echo esc_html($client->email); ?></td>
                                    <td><?php echo esc_html($client->company); ?></td>
                                    <td><?php echo esc_html($client->phone); ?></td>
                                    <td><?php echo esc_html($client->status); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <footer class="uapm-footer">
                UA Project Manager <?php echo esc_html(UAPM_VERSION); ?>
            </footer>
        </main>

        <?php wp_footer(); ?>
        </body>
        </html>
        <?php
    }

    private static function render_admin_page() {
        $user = wp_get_current_user();

        $projects_total = class_exists('UAPM_Projects')
            ? UAPM_Projects::count()
            : 0;

        $projects_active = class_exists('UAPM_Projects')
            ? UAPM_Projects::count(array('status' => 'active'))
            : 0;

        $projects_development = class_exists('UAPM_Projects')
            ? UAPM_Projects::count(array('status' => 'development'))
            : 0;

        $projects = class_exists('UAPM_Projects')
            ? UAPM_Projects::get_all(array(
                'limit' => 100,
                'orderby' => 'created_at',
                'order' => 'DESC',
            ))
            : array();

        status_header(200);
        nocache_headers();
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>UA Project Manager</title>
            <?php wp_head(); ?>
            <style>
                :root {
                    --uapm-blue: #173b68;
                    --uapm-bg: #f2f5f9;
                    --uapm-border: #e1e7ef;
                    --uapm-text: #263445;
                }
                body.uapm-portal-body {
                    margin: 0;
                    background: var(--uapm-bg);
                    color: var(--uapm-text);
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                }
                .uapm-portal {
                    max-width: 1400px;
                    margin: 0 auto;
                    padding: 24px;
                }
                .uapm-portal-header {
                    background: var(--uapm-blue);
                    color: #fff;
                    border-radius: 14px;
                    padding: 24px;
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    flex-wrap: wrap;
                    gap: 15px;
                }
                .uapm-portal-header h1 {
                    color: #fff;
                    margin: 0 0 7px;
                    font-size: 26px;
                }
                .uapm-portal-header p {
                    color: #dce8f6;
                    margin: 0;
                }
                .uapm-portal-user {
                    font-size: 14px;
                    text-align: right;
                }
                .uapm-portal-user a {
                    color: #fff;
                }
                .uapm-portal-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
                    gap: 16px;
                    margin: 22px 0;
                }
                .uapm-portal-card {
                    background: #fff;
                    border: 1px solid var(--uapm-border);
                    border-radius: 12px;
                    padding: 20px;
                    min-width: 0;
                }
                .uapm-portal-card h2 {
                    font-size: 17px;
                    margin: 0 0 12px;
                }
                .uapm-portal-number {
                    color: var(--uapm-blue);
                    font-size: 32px;
                    font-weight: 700;
                }
                .uapm-portal-muted {
                    color: #6b7785;
                    font-size: 13px;
                }
                .uapm-portal-section {
                    margin-top: 28px;
                }
                .uapm-portal-section h2 {
                    font-size: 21px;
                }
                .uapm-portal-nav {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
                    gap: 12px;
                }
                .uapm-portal-link {
                    display: block;
                    background: #fff;
                    border: 1px solid var(--uapm-border);
                    border-radius: 10px;
                    padding: 17px;
                    color: var(--uapm-blue);
                    text-decoration: none;
                    font-weight: 600;
                }
                .uapm-portal-link:hover {
                    border-color: var(--uapm-blue);
                    background: #f8fbff;
                }
                .uapm-portal-table-wrap {
                    overflow-x: auto;
                }
                .uapm-portal-table {
                    width: 100%;
                    border-collapse: collapse;
                    min-width: 600px;
                }
                .uapm-portal-table th,
                .uapm-portal-table td {
                    text-align: left;
                    padding: 13px 10px;
                    border-bottom: 1px solid var(--uapm-border);
                }
                .uapm-portal-table th {
                    background: #f7f9fc;
                }
                .uapm-portal-badge {
                    display: inline-block;
                    background: #edf2f7;
                    padding: 5px 9px;
                    border-radius: 20px;
                    font-size: 12px;
                }
                .uapm-portal-footer {
                    margin-top: 30px;
                    color: #778392;
                    font-size: 12px;
                    text-align: center;
                }
                @media(max-width:600px) {
                    .uapm-portal {
                        padding: 12px;
                    }
                    .uapm-portal-header {
                        padding: 18px;
                    }
                    .uapm-portal-header h1 {
                        font-size: 22px;
                    }
                    .uapm-portal-user {
                        text-align: left;
                    }
                }
            </style>
        </head>
        <body <?php body_class('uapm-portal-body'); ?>>
        <?php wp_body_open(); ?>

        <div class="uapm-portal">
            <header class="uapm-portal-header">
                <div>
                    <h1>UA Project Manager</h1>
                    <p>Центр управління твоїми проєктами</p>
                </div>
                <div class="uapm-portal-user">
                    <div><?php echo esc_html($user->display_name); ?></div>
                    <a href="<?php echo esc_url(wp_logout_url(home_url('/gpm/'))); ?>">
                        Вийти
                    </a>
                </div>
            </header>

            <section class="uapm-portal-grid">
                <div class="uapm-portal-card">
                    <div class="uapm-portal-muted">Усі проєкти</div>
                    <div class="uapm-portal-number">
                        <?php echo esc_html(number_format_i18n($projects_total)); ?>
                    </div>
                </div>
                <div class="uapm-portal-card">
                    <div class="uapm-portal-muted">Активні проєкти</div>
                    <div class="uapm-portal-number">
                        <?php echo esc_html(number_format_i18n($projects_active)); ?>
                    </div>
                </div>
                <div class="uapm-portal-card">
                    <div class="uapm-portal-muted">У розробці</div>
                    <div class="uapm-portal-number">
                        <?php echo esc_html(number_format_i18n($projects_development)); ?>
                    </div>
                </div>
            </section>

            <section class="uapm-portal-section">
                <h2>Розділи адміністратора</h2>
                <nav class="uapm-portal-nav">
                    <?php
                    $links = array(
                        array('Проєкти', 'admin.php?page=uapm-projects'),
                        array('Платежі', 'admin.php?page=uapm-payments'),
                        array('Інтеграції', 'admin.php?page=uapm-integrations'),
                        array('Завантаження', 'admin.php?page=uapm-downloads'),
                        array('Встановлення', 'admin.php?page=uapm-installations'),
                        array('GitHub', 'admin.php?page=uapm-github'),
                        array('Генератор коду', 'admin.php?page=uapm-code-generator'),
                        array('Перевірка коду', 'admin.php?page=uapm-code-inspector'),
                        array('Аналітика', 'admin.php?page=uapm-analytics'),
                        array('Налаштування', 'admin.php?page=uapm-settings'),
                    );

                    foreach ($links as $link) :
                        ?>
                        <a class="uapm-portal-link"
                           href="<?php echo esc_url(admin_url($link[1])); ?>">
                            <?php echo esc_html($link[0]); ?>
                        </a>
                    <?php endforeach; ?>
                </nav>
            </section>

            <section class="uapm-portal-section">
                <h2>Останні проєкти</h2>
                <div class="uapm-portal-card uapm-portal-table-wrap">
                    <?php if (empty($projects)) : ?>
                        <p>Поки що проєктів немає.</p>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=uapm-projects')); ?>">
                            Створити перший проєкт
                        </a>
                    <?php else : ?>
                        <table class="uapm-portal-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Назва</th>
                                    <th>Тип</th>
                                    <th>Версія</th>
                                    <th>Статус</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($projects as $project) : ?>
                                <tr>
                                    <td><?php echo esc_html(absint($project->id)); ?></td>
                                    <td>
                                        <strong><?php echo esc_html($project->name); ?></strong>
                                        <br>
                                        <span class="uapm-portal-muted">
                                            <?php echo esc_html($project->slug); ?>
                                        </span>
                                    </td>
                                    <td><?php echo esc_html($project->project_type); ?></td>
                                    <td><?php echo esc_html($project->version); ?></td>
                                    <td>
                                        <span class="uapm-portal-badge">
                                            <?php echo esc_html($project->status); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </section>

            <footer class="uapm-portal-footer">
                UA Project Manager <?php echo esc_html(UAPM_VERSION); ?>
            </footer>
        </div>

        <?php wp_footer(); ?>
        </body>
        </html>
        <?php
    }
}

UAPM_Portal::init();
