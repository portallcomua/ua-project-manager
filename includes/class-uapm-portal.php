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
        if (!class_exists('UAPM_Clients')) {
            wp_die('Модуль клієнтів не завантажено.');
        }

        $user = wp_get_current_user();
        $manager_id = get_current_user_id();

        $clients = UAPM_Clients::get_manager_clients(
            $manager_id,
            array(
                'limit' => 200,
                'offset' => 0,
            )
        );

        $totals = class_exists('UAPM_Payments')
            ? UAPM_Payments::get_manager_totals($manager_id)
            : array();

        $notice = isset($_GET['uapm_client_notice'])
            ? sanitize_key(wp_unslash($_GET['uapm_client_notice']))
            : '';

        $message = isset($_GET['uapm_client_message'])
            ? sanitize_text_field(wp_unslash($_GET['uapm_client_message']))
            : '';

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
                    min-width: 650px;
                }
                .uapm-table th,
                .uapm-table td {
                    padding: 12px 10px;
                    text-align: left;
                    border-bottom: 1px solid #e1e7ef;
                    vertical-align: top;
                }
                .uapm-table th {
                    background: #f7f9fc;
                }
                .uapm-form-grid {
                    display: grid;
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                    gap: 14px;
                }
                .uapm-field {
                    display: flex;
                    flex-direction: column;
                    gap: 6px;
                }
                .uapm-field-full {
                    grid-column: 1 / -1;
                }
                .uapm-field label {
                    font-weight: 600;
                    font-size: 14px;
                }
                .uapm-field input,
                .uapm-field textarea {
                    width: 100%;
                    box-sizing: border-box;
                    padding: 11px;
                    border: 1px solid #ccd5e0;
                    border-radius: 7px;
                    font-size: 15px;
                }
                .uapm-button {
                    display: inline-block;
                    border: 0;
                    border-radius: 7px;
                    padding: 11px 17px;
                    background: #173b68;
                    color: #fff;
                    font-size: 14px;
                    cursor: pointer;
                    text-decoration: none;
                }
                .uapm-button:hover {
                    background: #24558e;
                    color: #fff;
                }
                .uapm-button-danger {
                    background: #a52b36;
                }
                .uapm-button-danger:hover {
                    background: #84212b;
                }
                .uapm-actions {
                    display: flex;
                    gap: 7px;
                    flex-wrap: wrap;
                }
                .uapm-notice {
                    margin-top: 18px;
                    padding: 13px 16px;
                    border-radius: 8px;
                    background: #e8f5ec;
                    color: #185b2d;
                }
                .uapm-notice-error {
                    background: #fdecec;
                    color: #8b2020;
                }
                .uapm-footer {
                    margin-top: 25px;
                    color: #778392;
                    font-size: 12px;
                    text-align: center;
                }
                .uapm-edit-form {
                    min-width: 260px;
                }
                .uapm-edit-form input {
                    width: 100%;
                    box-sizing: border-box;
                    margin-bottom: 6px;
                    padding: 7px;
                    border: 1px solid #ccd5e0;
                    border-radius: 5px;
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
                    .uapm-form-grid {
                        grid-template-columns: 1fr;
                    }
                    .uapm-field-full {
                        grid-column: auto;
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

            <?php if ($notice) : ?>
                <div class="uapm-notice <?php echo 'error' === $notice ? 'uapm-notice-error' : ''; ?>">
                    <?php
                    if ($message) {
                        echo esc_html($message);
                    } elseif ('saved' === $notice) {
                        echo 'Дані клієнта збережено.';
                    } elseif ('archived' === $notice) {
                        echo 'Клієнта переміщено до архіву.';
                    } else {
                        echo 'Не вдалося виконати операцію.';
                    }
                    ?>
                </div>
            <?php endif; ?>

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
                <h2>Додати клієнта</h2>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="uapm_manager_save_client">
                    <input type="hidden" name="client_id" value="0">
                    <?php wp_nonce_field('uapm_manager_save_client'); ?>

                    <div class="uapm-form-grid">
                        <div class="uapm-field">
                            <label for="uapm-client-name">Ім’я клієнта *</label>
                            <input id="uapm-client-name" type="text" name="name" required>
                        </div>

                        <div class="uapm-field">
                            <label for="uapm-client-email">Email *</label>
                            <input id="uapm-client-email" type="email" name="email" required>
                        </div>

                        <div class="uapm-field">
                            <label for="uapm-client-company">Компанія</label>
                            <input id="uapm-client-company" type="text" name="company">
                        </div>

                        <div class="uapm-field">
                            <label for="uapm-client-phone">Телефон</label>
                            <input id="uapm-client-phone" type="text" name="phone">
                        </div>

                        <div class="uapm-field">
                            <label for="uapm-client-country">Країна</label>
                            <input id="uapm-client-country" type="text" name="country">
                        </div>

                        <div class="uapm-field uapm-field-full">
                            <label for="uapm-client-notes">Нотатки</label>
                            <textarea id="uapm-client-notes" name="notes" rows="3"></textarea>
                        </div>
                    </div>

                    <p>
                        <button type="submit" class="uapm-button">Додати клієнта</button>
                    </p>
                </form>
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
                                <th>Керування</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($clients)) : ?>
                            <tr>
                                <td colspan="7">Поки що клієнтів не додано.</td>
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
                                    <td>
                                        <?php if ('archived' !== $client->status) : ?>
                                            <details>
                                                <summary>Редагувати</summary>

                                                <form class="uapm-edit-form" method="post"
                                                      action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                                    <input type="hidden" name="action" value="uapm_manager_save_client">
                                                    <input type="hidden" name="client_id"
                                                           value="<?php echo esc_attr($client->id); ?>">
                                                    <?php wp_nonce_field('uapm_manager_save_client'); ?>

                                                    <input type="text" name="name"
                                                           value="<?php echo esc_attr($client->name); ?>"
                                                           placeholder="Ім’я" required>

                                                    <input type="email" name="email"
                                                           value="<?php echo esc_attr($client->email); ?>"
                                                           placeholder="Email" required>

                                                    <input type="text" name="company"
                                                           value="<?php echo esc_attr($client->company); ?>"
                                                           placeholder="Компанія">

                                                    <input type="text" name="phone"
                                                           value="<?php echo esc_attr($client->phone); ?>"
                                                           placeholder="Телефон">

                                                    <input type="text" name="country"
                                                           value="<?php echo esc_attr($client->country); ?>"
                                                           placeholder="Країна">

                                                    <button type="submit" class="uapm-button">
                                                        Зберегти
                                                    </button>
                                                </form>
                                            </details>

                                            <form method="post"
                                                  action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                                                  onsubmit="return confirm('Архівувати цього клієнта?');">
                                                <input type="hidden" name="action" value="uapm_manager_archive_client">
                                                <input type="hidden" name="client_id"
                                                       value="<?php echo esc_attr($client->id); ?>">
                                                <?php wp_nonce_field('uapm_manager_archive_client_' . $client->id); ?>

                                                <button type="submit" class="uapm-button uapm-button-danger">
                                                    Архівувати
                                                </button>
                                            </form>
                                        <?php else : ?>
                                            <span class="uapm-muted">В архіві</span>
                                        <?php endif; ?>
                                    </td>
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
