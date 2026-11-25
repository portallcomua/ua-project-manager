<?php
/**
 * UA Project Manager
 * Administration interface.
 *
 * @package UA_Project_Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

final class UAPM_Admin {

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'register_menu'));
        add_action('admin_menu', array(__CLASS__, 'hide_legacy_module_menus'), 999);
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_styles'));
    }

    /** Hide duplicate top-level menus from the old standalone modules while keeping their data untouched. */
    public static function hide_legacy_module_menus() {
        if (class_exists('\\UAPM\\PayBox\\UPB_Admin')) {
            remove_menu_page('upb-premiums');
        }
        if (function_exists('\\UAPM\\WebFinder\\cmsf_page_search')) {
            remove_menu_page('cms-checker');
        }
    }

    public static function register_menu() {
        add_menu_page(
            __('UA Project Manager', 'ua-project-manager'),
            __('UA Project Manager', 'ua-project-manager'),
            'uapm_manage_projects',
            'uapm',
            array(__CLASS__, 'render_dashboard'),
            'dashicons-screenoptions',
            3
        );

        add_submenu_page(
            'uapm',
            __('Головна', 'ua-project-manager'),
            __('Головна', 'ua-project-manager'),
            'uapm_manage_projects',
            'uapm',
            array(__CLASS__, 'render_dashboard')
        );

        add_submenu_page(
            'uapm',
            __('Проєкти', 'ua-project-manager'),
            __('Проєкти', 'ua-project-manager'),
            'uapm_manage_projects',
            'uapm-projects',
            array(__CLASS__, 'render_projects')
        );

        add_submenu_page(
            'uapm',
            __('Оплати', 'ua-project-manager'),
            __('Оплати', 'ua-project-manager'),
            'uapm_manage_payments',
            'uapm-payments',
            array(__CLASS__, 'render_payments')
        );

        add_submenu_page(
            'uapm',
            __('Import / Export', 'ua-project-manager'),
            __('Імпорт даних', 'ua-project-manager'),
            'uapm_manage_settings',
            'uapm-import',
            array('UAPM_Import', 'render_page')
        );
    }

    public static function enqueue_styles($hook) {
        if (strpos((string) $hook, 'uapm') === false) {
            return;
        }

        wp_enqueue_media();
        wp_enqueue_script('jquery');
        wp_register_style('uapm-admin-inline', false, array(), UAPM_VERSION);
        wp_enqueue_style('uapm-admin-inline');

        $css = '
            .uapm-wrap{max-width:1250px;margin:20px 20px 20px 0}
            .uapm-header{background:#173b68;color:#fff;padding:22px 25px;border-radius:10px;margin-bottom:20px}
            .uapm-brand{font-size:13px;font-weight:700;margin-bottom:10px;color:#fff}.uapm-brand span{opacity:.75;font-weight:400;margin-left:5px}
            .uapm-quick-nav{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 13px}.uapm-quick-nav a{display:inline-block;padding:4px 9px;border:1px solid rgba(255,255,255,.35);border-radius:4px;color:#fff;text-decoration:none;font-size:11px;line-height:1.5}.uapm-quick-nav a:hover{background:rgba(255,255,255,.15);color:#fff}
            .uapm-header h1{color:#fff;margin:0 0 6px;font-size:25px}
            .uapm-header p{margin:0;color:#dce9f7}
            .uapm-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:15px;margin:20px 0}
            .uapm-card{background:#fff;border:1px solid #dcdcde;border-radius:9px;padding:18px;box-sizing:border-box}
            .uapm-card h2{margin:0 0 12px;font-size:17px}
            .uapm-number{font-size:30px;font-weight:700;color:#173b68}
            .uapm-muted{color:#646970}
            .uapm-table-wrap{overflow-x:auto;background:#fff;border:1px solid #dcdcde;border-radius:8px;margin-top:15px}
            .uapm-table{width:100%;border-collapse:collapse;min-width:750px}
            .uapm-table th,.uapm-table td{padding:12px;text-align:left;border-bottom:1px solid #eee;vertical-align:top}
            .uapm-table th{background:#f6f7f7;font-weight:600}
            .uapm-form{background:#fff;border:1px solid #dcdcde;border-radius:9px;padding:20px;margin:20px 0}
            .uapm-form h2{margin-top:0}
            .uapm-field{margin-bottom:15px}
            .uapm-field label{display:block;font-weight:600;margin-bottom:6px}
            .uapm-field input,.uapm-field textarea,.uapm-field select{width:100%;max-width:650px}
            .uapm-field textarea{min-height:90px}
            .uapm-actions{display:flex;gap:7px;flex-wrap:wrap}
            .uapm-status{display:inline-block;padding:4px 9px;border-radius:20px;background:#edf2f7;font-size:12px}
            .uapm-status-active,.uapm-status-paid{background:#e2f5e9;color:#176b35}
            .uapm-status-pending,.uapm-status-partial{background:#fff3cd;color:#805b00}
            .uapm-status-revoked,.uapm-status-archived{background:#fbeaea;color:#a12626}
            .uapm-inline-form{display:inline-block;margin:2px}
            .uapm-inline-form input[type=number]{width:100px}
            .uapm-notice{padding:12px 15px;margin:15px 0;border-left:4px solid #2271b1;background:#fff}
            .uapm-notice.error{border-color:#d63638}
            .uapm-notice.success{border-color:#00a32a}
            .uapm-section-title{margin-top:28px}.uapm-project-links{display:inline-flex;gap:8px;flex-wrap:wrap;margin-top:4px;font-size:12px}
            @media(max-width:782px){
                .uapm-wrap{margin-right:10px}
                .uapm-header{padding:17px}
                .uapm-form{padding:14px}
                .uapm-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
                .uapm-number{font-size:24px}
                .uapm-actions .button{margin-bottom:5px}
            }
            @media(max-width:420px){
                .uapm-grid{grid-template-columns:1fr}
            }
        ';

        wp_add_inline_style('uapm-admin-inline', $css);
        $media_js = "jQuery(function($){var frame;$(document).on('click','.uapm-select-logo',function(e){e.preventDefault();if(frame){frame.open();return;}frame=wp.media({title:'Виберіть логотип проєкту',button:{text:'Використати логотип'},multiple:false,library:{type:'image'}});frame.on('select',function(){var a=frame.state().get('selection').first().toJSON();$('#uapm-logo-url').val(a.url);$('#uapm-logo-preview').attr('src',a.url).show();$('.uapm-remove-logo').show();});frame.open();});$(document).on('click','.uapm-remove-logo',function(e){e.preventDefault();$('#uapm-logo-url').val('');$('#uapm-logo-preview').attr('src','').hide();$(this).hide();});});";
        wp_add_inline_script('jquery', $media_js);
    }

    private static function can_manage_projects() {
        return current_user_can('uapm_manage_projects') || current_user_can('manage_options');
    }

    private static function can_manage_payments() {
        return current_user_can('uapm_manage_payments') || current_user_can('manage_options');
    }

    private static function header($title, $description = '') {
        echo '<div class="wrap uapm-wrap">';
        echo '<div class="uapm-header">';
        echo '<div class="uapm-brand">UA Project Manager <span>v' . esc_html(UAPM_VERSION) . '</span></div>';
        echo '<nav class="uapm-quick-nav" aria-label="Швидке меню">';
        $nav_items = array('uapm' => 'Головна', 'uapm-projects' => 'Проєкти', 'uapm-payments' => 'Платежі', 'uapm-import' => 'Імпорт даних');
        foreach ($nav_items as $page_slug => $nav_label) {
            if (($page_slug === 'uapm-payments' && !self::can_manage_payments()) || ($page_slug === 'uapm-import' && !current_user_can('uapm_manage_settings') && !current_user_can('manage_options'))) { continue; }
            echo '<a href="' . esc_url(admin_url('admin.php?page=' . $page_slug)) . '">' . esc_html($nav_label) . '</a>';
        }
        echo '</nav>';
        echo '<h1>' . esc_html($title) . '</h1>';

        if ($description) {
            echo '<p>' . esc_html($description) . '</p>';
        }

        echo '</div>';

        self::display_notice();
    }

    private static function footer() {
        echo '</div>';
    }

    private static function display_notice() {
        if (empty($_GET['uapm_notice'])) {
            return;
        }

        $type = sanitize_key(wp_unslash($_GET['uapm_notice']));
        $message = isset($_GET['uapm_message'])
            ? sanitize_text_field(rawurldecode(wp_unslash($_GET['uapm_message'])))
            : '';

        if (!in_array($type, array('success', 'error', 'warning'), true) || !$message) {
            return;
        }

        echo '<div class="uapm-notice ' . esc_attr($type) . '">';
        echo esc_html($message);
        echo '</div>';
    }

    public static function render_dashboard() {
        if (!self::can_manage_projects()) {
            wp_die(esc_html__('You do not have permission to access this page.', 'ua-project-manager'));
        }

        self::header(
            __('UA Project Manager', 'ua-project-manager'),
            __('Central management of projects, releases, installations and payments.', 'ua-project-manager')
        );

        $projects_total = UAPM_Projects::count();
        $projects_active = UAPM_Projects::count(array('status' => 'active'));
        $projects_development = UAPM_Projects::count(array('status' => 'development'));

        $payments = array();

        if (self::can_manage_payments()) {
            $payments = UAPM_Payments::get_all(array('limit' => 200));
        }

        $pending = 0;
        $paid = 0;
        $partial = 0;

        foreach ($payments as $payment) {
            if ($payment->payment_status === 'pending') {
                $pending++;
            } elseif ($payment->payment_status === 'paid') {
                $paid++;
            } elseif ($payment->payment_status === 'partial') {
                $partial++;
            }
        }

        echo '<div class="uapm-grid">';

        self::stat_card(
            __('All projects', 'ua-project-manager'),
            $projects_total
        );

        self::stat_card(
            __('Active projects', 'ua-project-manager'),
            $projects_active
        );

        self::stat_card(
            __('У розробці', 'ua-project-manager'),
            $projects_development
        );

        if (self::can_manage_payments()) {
            self::stat_card(__('Pending payments', 'ua-project-manager'), $pending);
            self::stat_card(__('Partial payments', 'ua-project-manager'), $partial);
            self::stat_card(__('Confirmed payments', 'ua-project-manager'), $paid);
        }

        echo '</div>';

        echo '<div class="uapm-grid">';

        echo '<div class="uapm-card">';
        echo '<h2>' . esc_html__('Проєкти', 'ua-project-manager') . '</h2>';
        echo '<p class="uapm-muted">' . esc_html__('Create and manage your products and applications.', 'ua-project-manager') . '</p>';
        echo '<a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=uapm-projects')) . '">' . esc_html__('Open projects', 'ua-project-manager') . '</a>';
        echo '</div>';

        if (self::can_manage_payments()) {
            echo '<div class="uapm-card">';
            echo '<h2>' . esc_html__('Платежі', 'ua-project-manager') . '</h2>';
            echo '<p class="uapm-muted">' . esc_html__('Record received amounts and manually confirm or revoke PRO access.', 'ua-project-manager') . '</p>';
            echo '<a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=uapm-payments')) . '">' . esc_html__('Open payments', 'ua-project-manager') . '</a>';
            echo '</div>';
        }

        echo '</div>';

        echo '<h2 class="uapm-section-title">' . esc_html__('Latest projects', 'ua-project-manager') . '</h2>';

        $latest = UAPM_Projects::get_all(array('limit' => 5));

        self::render_projects_table($latest, false);

        self::footer();
    }

    private static function stat_card($label, $number) {
        echo '<div class="uapm-card">';
        echo '<div class="uapm-muted">' . esc_html($label) . '</div>';
        echo '<div class="uapm-number">' . esc_html(number_format_i18n((int) $number)) . '</div>';
        echo '</div>';
    }

    public static function render_projects() {
        if (!self::can_manage_projects()) {
            wp_die(esc_html__('You do not have permission to access this page.', 'ua-project-manager'));
        }

        $edit_id = isset($_GET['edit_project']) ? absint($_GET['edit_project']) : 0;
        $project = $edit_id ? UAPM_Projects::get($edit_id) : null;
        self::header($edit_id ? 'Редагувати проєкт' : 'Додати проєкт', 'Керування проєктами та їхніми посиланнями.');

        if ($edit_id && !$project) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Проєкт не знайдено.', 'ua-project-manager') . '</p></div>';
        }

        self::render_project_form($project);

        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';

        $projects = UAPM_Projects::get_all(array(
            'search' => $search,
            'limit' => 200,
            'orderby' => 'created_at',
            'order' => 'DESC',
        ));

        echo '<h2 class="uapm-section-title">' . esc_html__('Список проєктів', 'ua-project-manager') . '</h2>';

        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" style="margin:15px 0">';
        echo '<input type="hidden" name="page" value="uapm-projects">';
        echo '<input type="search" name="s" value="' . esc_attr($search) . '" placeholder="' . esc_attr__('Пошук проєктів...', 'ua-project-manager') . '">';
        echo ' <button class="button">' . esc_html__('Знайти', 'ua-project-manager') . '</button>';
        echo '</form>';

        self::render_projects_table($projects, true);

        self::footer();
    }

    private static function render_project_form($project = null) {
        $is_edit = is_object($project);
        $value = static function ($key, $default = '') use ($project) {
            return $project && isset($project->{$key}) ? $project->{$key} : $default;
        };

        echo '<div class="uapm-form">';
        echo '<h2>' . esc_html($is_edit ? __('Редагувати проєкт', 'ua-project-manager') : __('Додати проєкт', 'ua-project-manager')) . '</h2>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="uapm_save_project">';

        if ($is_edit) {
            echo '<input type="hidden" name="project_id" value="' . esc_attr((int) $project->id) . '">';
        }

        wp_nonce_field('uapm_save_project');

        self::field('project[name]', __('Назва проєкту', 'ua-project-manager'), $value('name'), 'text', true);
        self::field('project[slug]', __('Системна назва (slug)', 'ua-project-manager'), $value('slug'));
        self::field('project[version]', __('Версія', 'ua-project-manager'), $value('version', '1.0.0'));
        self::field('project[author]', __('Автор', 'ua-project-manager'), $value('author'));

        echo '<div class="uapm-field">';
        echo '<label for="uapm-project-type">' . esc_html__('Тип проєкту', 'ua-project-manager') . '</label>';
        $known_types = array('wordpress', 'android', 'windows', 'web', 'other');
        $stored_type = (string) $value('project_type', 'wordpress');
        $custom_type = !in_array($stored_type, $known_types, true);
        echo '<select id="uapm-project-type" name="project[project_type]">';

        $types = array(
            'wordpress' => 'WordPress',
            'android' => 'Android / Android-додаток',
            'windows' => 'Windows / програма',
            'web' => 'Вебсайт / вебсервіс',
            'other' => __('Інший тип (вказати власний)', 'ua-project-manager'),
        );

        foreach ($types as $key => $label) {
            echo '<option value="' . esc_attr($key) . '" ' . selected($custom_type ? 'custom' : $stored_type, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '<option value="custom" ' . selected($custom_type, true, false) . '>Власний тип проєкту…</option>';
        echo '</select></div>';
        echo '<div class="uapm-field" id="uapm-custom-type-wrap"' . ($custom_type ? '' : ' style="display:none"') . '>';
        echo '<label for="uapm-custom-project-type">Вкажіть свій тип проєкту</label>';
        echo '<input type="text" id="uapm-custom-project-type" name="project[custom_project_type]" value="' . esc_attr($custom_type ? ucwords(str_replace(array('-', '_'), ' ', $stored_type)) : '') . '" placeholder="Наприклад: Chrome Extension, Node.js програма">';
        echo '</div>';
        echo '<script>document.addEventListener("DOMContentLoaded",function(){var s=document.getElementById("uapm-project-type"),w=document.getElementById("uapm-custom-type-wrap");if(s&&w){s.addEventListener("change",function(){w.style.display=s.value==="custom"?"block":"none";});}});</script>';

        echo '<div class="uapm-field">';
        echo '<label for="uapm-project-status">' . esc_html__('Статус', 'ua-project-manager') . '</label>';
        echo '<select id="uapm-project-status" name="project[status]">';

        $statuses = array(
            'active' => __('Активний', 'ua-project-manager'),
            'development' => __('У розробці', 'ua-project-manager'),
            'paused' => __('Призупинений', 'ua-project-manager'),
            'archived' => __('Архівний', 'ua-project-manager'),
        );

        foreach ($statuses as $key => $label) {
            echo '<option value="' . esc_attr($key) . '" ' . selected($value('status', 'active'), $key, false) . '>' . esc_html($label) . '</option>';
        }

        echo '</select></div>';

        self::field('project[website_url]', __('Посилання на сайт', 'ua-project-manager'), $value('website_url'), 'url');
        self::field('project[documentation_url]', __('Посилання на документацію', 'ua-project-manager'), $value('documentation_url'), 'url');
        self::field('project[github_repository]', __('Репозиторій GitHub (власник/репозиторій або повне посилання)', 'ua-project-manager'), $value('github_repository'));
        self::field('project[github_branch]', __('Гілка GitHub', 'ua-project-manager'), $value('github_branch', 'main'));
        echo '<div class="uapm-field"><label for="uapm-logo-url">Логотип проєкту</label>';
        echo '<input type="url" id="uapm-logo-url" name="project[logo_url]" value="' . esc_attr($value('logo_url')) . '" placeholder="URL логотипа або виберіть файл з медіатеки" style="max-width:520px"> ';
        echo '<button type="button" class="button uapm-select-logo">Завантажити / вибрати з медіатеки</button> ';
        echo '<button type="button" class="button uapm-remove-logo"' . ($value('logo_url') ? '' : ' style="display:none"') . '>Прибрати</button>';
        echo '<div style="margin-top:10px"><img id="uapm-logo-preview" src="' . esc_url($value('logo_url')) . '" alt="Попередній перегляд логотипа" style="max-width:120px;max-height:120px;object-fit:contain;' . ($value('logo_url') ? '' : 'display:none;') . '"></div></div>';

        echo '<div class="uapm-field">';
        echo '<label for="uapm-project-description">' . esc_html__('Опис', 'ua-project-manager') . '</label>';
        echo '<textarea id="uapm-project-description" name="project[description]">' . esc_textarea($value('description')) . '</textarea>';
        echo '</div>';

        // Додаткові параметри відображення та рекламні налаштування проєкту.
        $display = class_exists('UAPM_Advertising') ? UAPM_Advertising::get_project_extras($value('id', 0)) : array();
        echo '<hr><h3>Відображення проєкту та реклама</h3>';
        echo '<div class="uapm-field"><label for="uapm-all-projects-url">Посилання «Всі проєкти»</label><input type="url" id="uapm-all-projects-url" name="project[all_projects_url]" value="' . esc_attr($display['all_projects_url'] ?? '') . '" placeholder="https://uaserver.pp.ua/..."> <label><input type="checkbox" name="project[all_projects_visible]" value="1" ' . checked(!empty($display['all_projects_visible']), true, false) . '> Відображати посилання у плагіні / додатку</label></div>';
        echo '<div class="uapm-field"><label for="uapm-project-banner-html">HTML banner для цього проєкту</label><textarea id="uapm-project-banner-html" name="project[project_banner_html]" rows="5" class="large-text code">' . esc_textarea($display['project_banner_html'] ?? '') . '</textarea><label><input type="checkbox" name="project[project_banner_visible]" value="1" ' . checked(!empty($display['project_banner_visible']), true, false) . '> Відображати банер у плагіні / додатку</label></div>';
        echo '<div class="uapm-field"><label><input type="checkbox" name="project[ads_available]" value="1" ' . checked(!empty($display['ads_available']), true, false) . '> Доступний для розміщення реклами</label><p class="description">Якщо галочка вимкнена, проєкт не з’являється у виборі рекламного блоку.</p></div>';
        if ($is_edit && class_exists('UAPM_Advertising')) {
            echo '<div class="uapm-field"><label>Код підключення рекламного блоку</label><textarea readonly rows="5" class="large-text code" onclick="this.select()">' . esc_textarea(UAPM_Advertising::project_embed_code($value('id', 0))) . '</textarea></div>';
        }
        if ($is_edit && class_exists('UAPM_Shared_Sync')) {
            $finder = UAPM_Shared_Sync::project_finder_summary($value('id', 0));
            echo '<hr><h3>Пов’язані дані Web Finder</h3>';
            echo '<p>Списки пошуку: <strong>' . count($finder['search_lists']) . '</strong> · Сайтів: <strong>' . (int)$finder['sites'] . '</strong></p>';
            echo '<p>Списки контактів: <strong>' . count($finder['contact_lists']) . '</strong> · Email: <strong>' . (int)$finder['emails'] . '</strong> · Кампанії: <strong>' . (int)$finder['campaigns'] . '</strong></p>';
            if ($finder['search_lists']) echo '<p><strong>Списки сайтів:</strong> ' . esc_html(implode(', ', $finder['search_lists'])) . '</p>';
            if ($finder['contact_lists']) echo '<p><strong>Списки контактів:</strong> ' . esc_html(implode(', ', $finder['contact_lists'])) . '</p>';
            if (!$finder['search_lists'] && !$finder['contact_lists']) echo '<p class="description">Ще немає пов’язаних списків. У Finder можна створити новий список або прив’язати наявний до цього проєкту.</p>';
        }

        echo '<p class="submit">';
        echo '<button type="submit" class="button button-primary">' . esc_html($is_edit ? __('Зберегти зміни', 'ua-project-manager') : __('Створити проєкт', 'ua-project-manager')) . '</button> ';

        if ($is_edit) {
            echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=uapm-projects')) . '">' . esc_html__('Скасувати', 'ua-project-manager') . '</a>';
        }

        echo '</p>';
        echo '</form>';
        echo '</div>';
    }

    private static function field($name, $label, $value = '', $type = 'text', $required = false) {
        $id = 'uapm-' . sanitize_html_class(str_replace(array('[', ']'), '-', $name));

        echo '<div class="uapm-field">';
        echo '<label for="' . esc_attr($id) . '">' . esc_html($label) . '</label>';
        echo '<input type="' . esc_attr($type) . '" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '" ' . ($required ? 'required' : '') . '>';
        echo '</div>';
    }

    private static function render_projects_table($projects, $show_actions = true) {
        if (empty($projects)) {
            echo '<div class="uapm-card"><p>' . esc_html__('Проєктів не знайдено.', 'ua-project-manager') . '</p></div>';
            return;
        }

        echo '<div class="uapm-table-wrap">';
        echo '<table class="uapm-table">';
        echo '<thead><tr>';
        echo '<th>ID</th>';
        echo '<th>' . esc_html__('Назва', 'ua-project-manager') . '</th>';
        echo '<th>' . esc_html__('Тип', 'ua-project-manager') . '</th>';
        echo '<th>' . esc_html__('Версія', 'ua-project-manager') . '</th>';
        echo '<th>' . esc_html__('Статус', 'ua-project-manager') . '</th>';
        echo '<th>Рекламодавець / покази / кліки / період</th>';

        if ($show_actions) {
            echo '<th>' . esc_html__('Дії', 'ua-project-manager') . '</th>';
        }

        echo '</tr></thead><tbody>';

        foreach ($projects as $project) {
            echo '<tr>';
            echo '<td>' . esc_html((int) $project->id) . '</td>';
            echo '<td><strong>' . esc_html($project->name) . '</strong><br><span class="uapm-muted">' . esc_html($project->slug) . '</span>';
            $project_links = array();
            if (!empty($project->website_url)) {
                $project_links[] = '<a href="' . esc_url($project->website_url) . '" target="_blank" rel="noopener noreferrer">Сайт</a>';
            }
            if (!empty($project->documentation_url)) {
                $project_links[] = '<a href="' . esc_url($project->documentation_url) . '" target="_blank" rel="noopener noreferrer">Документація</a>';
            }
            if (!empty($project->github_repository)) {
                $github_url = trim($project->github_repository);
                if (!preg_match('#^https?://#i', $github_url)) {
                    if (stripos($github_url, 'github.com/') === 0) {
                        $github_url = 'https://' . $github_url;
                    } else {
                        $github_url = 'https://github.com/' . ltrim($github_url, '/');
                    }
                }
                $project_links[] = '<a href="' . esc_url($github_url) . '" target="_blank" rel="noopener noreferrer">GitHub</a>';
            }
            if ($project_links) {
                echo '<br><span class="uapm-project-links">' . implode(' · ', $project_links) . '</span>';
            }
            echo '</td>';
            echo '<td>' . esc_html(ucwords(str_replace(array('-', '_'), ' ', $project->project_type))) . '</td>';
            echo '<td>' . esc_html($project->version) . '</td>';
            echo '<td>' . self::status_badge($project->status) . '</td>';
            echo '<td>';
            if (class_exists('UAPM_Advertising')) {
                $ad = UAPM_Advertising::project_ad_summary($project->id);
                if ($ad) {
                    echo '<strong>' . esc_html($ad['advertiser']) . '</strong><br>Покази: ' . (int)$ad['impressions'] . ' · Кліки: ' . (int)$ad['clicks'] . '<br><small>' . esc_html($ad['start'] . ' — ' . $ad['end']) . '</small>';
                } else { echo '—'; }
            } else { echo '—'; }
            echo '</td>';

            if ($show_actions) {
                echo '<td><div class="uapm-actions">';

                $edit_url = add_query_arg(
                    array(
                        'page' => 'uapm-projects',
                        'edit_project' => (int) $project->id,
                    ),
                    admin_url('admin.php')
                );

                echo '<a class="button button-small" href="' . esc_url($edit_url) . '">' . esc_html__('Редагувати', 'ua-project-manager') . '</a>';

                if ($project->status !== 'archived') {
                    $archive_url = wp_nonce_url(
                        add_query_arg(
                            array(
                                'action' => 'uapm_delete_project',
                                'project_id' => (int) $project->id,
                            ),
                            admin_url('admin-post.php')
                        ),
                        'uapm_delete_project'
                    );

                    echo '<a class="button button-small" href="' . esc_url($archive_url) . '" onclick="return confirm(' . esc_attr(wp_json_encode(__('Archive this project?', 'ua-project-manager'))) . ')">' . esc_html__('В архів', 'ua-project-manager') . '</a>';
                }

                echo '</div></td>';
            }

            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }

    private static function status_badge($status) {
        $status = sanitize_key($status);
        $class = 'uapm-status-' . $status;

        $labels = array(
            'active' => 'Активний',
            'development' => 'У розробці',
            'paused' => 'Призупинений',
            'archived' => 'Архівний',
            'pending' => 'Очікує',
            'partial' => 'Частково оплачено',
            'paid' => 'Оплачено',
            'revoked' => 'Скасовано',
            'provisional' => 'Попередній',
            'confirmed' => 'Підтверджено',
        );
        $label = isset($labels[$status]) ? $labels[$status] : ucfirst($status);
        return '<span class="uapm-status ' . esc_attr($class) . '">' . esc_html($label) . '</span>';
    }

    public static function render_payments() {
        if (!self::can_manage_payments()) {
            wp_die(esc_html__('You do not have permission to access this page.', 'ua-project-manager'));
        }

        self::header(
            __('Платежі', 'ua-project-manager'),
            __('Record actual receipts. PRO access is confirmed only after manual verification.', 'ua-project-manager')
        );

        $status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';

        $allowed_statuses = array('pending', 'partial', 'paid', 'revoked');

        if (!in_array($status, $allowed_statuses, true)) {
            $status = '';
        }

        $payments = UAPM_Payments::get_all(array(
            'status' => $status,
            'limit' => 200,
        ));

        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" style="margin:15px 0">';
        echo '<input type="hidden" name="page" value="uapm-payments">';
        echo '<select name="status">';
        echo '<option value="">' . esc_html__('All statuses', 'ua-project-manager') . '</option>';

        foreach ($allowed_statuses as $item) {
            echo '<option value="' . esc_attr($item) . '" ' . selected($status, $item, false) . '>' . esc_html(ucfirst($item)) . '</option>';
        }

        echo '</select> ';
        echo '<button class="button">' . esc_html__('Filter', 'ua-project-manager') . '</button>';
        echo '</form>';

        if (empty($payments)) {
            echo '<div class="uapm-card"><p>' . esc_html__('No payment requests found.', 'ua-project-manager') . '</p></div>';
            self::footer();
            return;
        }

        echo '<div class="uapm-table-wrap">';
        echo '<table class="uapm-table">';
        echo '<thead><tr>';
        echo '<th>ID</th>';
        echo '<th>' . esc_html__('Project', 'ua-project-manager') . '</th>';
        echo '<th>' . esc_html__('Customer', 'ua-project-manager') . '</th>';
        echo '<th>' . esc_html__('Amount', 'ua-project-manager') . '</th>';
        echo '<th>' . esc_html__('Received', 'ua-project-manager') . '</th>';
        echo '<th>' . esc_html__('Статус', 'ua-project-manager') . '</th>';
        echo '<th>' . esc_html__('Activation', 'ua-project-manager') . '</th>';
        echo '<th>' . esc_html__('Дії', 'ua-project-manager') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($payments as $payment) {
            $project = UAPM_Projects::get((int) $payment->project_id);
            $project_name = $project ? $project->name : __('Unknown project', 'ua-project-manager');

            $customer = isset($payment->customer_email) ? $payment->customer_email : '';
            $target = isset($payment->activation_target) ? $payment->activation_target : '';
            $activation_type = isset($payment->activation_type) ? $payment->activation_type : '';

            echo '<tr>';
            echo '<td>#' . esc_html((int) $payment->id) . '</td>';
            echo '<td>' . esc_html($project_name) . '</td>';
            echo '<td>' . esc_html($customer) . '<br><span class="uapm-muted">' . esc_html($target) . '</span></td>';
            echo '<td>' . esc_html(number_format_i18n((float) $payment->amount, 2)) . ' ' . esc_html($payment->currency) . '</td>';
            echo '<td>' . esc_html(number_format_i18n((float) $payment->received_amount, 2)) . ' ' . esc_html($payment->currency) . '</td>';
            echo '<td>' . self::status_badge($payment->payment_status) . '</td>';
            echo '<td>' . esc_html($activation_type) . '<br>' . self::status_badge($payment->activation_status) . '</td>';
            echo '<td>';

            if (in_array($payment->payment_status, array('pending', 'partial'), true)) {
                self::render_receipt_form($payment);

                self::render_payment_action(
                    'uapm_confirm_payment',
                    'uapm_confirm_payment',
                    $payment->id,
                    __('Confirm PRO', 'ua-project-manager')
                );
            }

            if ($payment->payment_status !== 'revoked') {
                self::render_revoke_form($payment);
            }

            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';

        echo '<div class="uapm-card" style="margin-top:18px">';
        echo '<strong>' . esc_html__('Important:', 'ua-project-manager') . '</strong> ';
        echo esc_html__('A redirect to Privat Envelope or another payment page does not prove that money was received. Record the actual amount first, then confirm PRO manually after verification.', 'ua-project-manager');
        echo '</div>';

        self::footer();
    }

    private static function render_receipt_form($payment) {
        echo '<form class="uapm-inline-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="uapm_record_payment">';
        echo '<input type="hidden" name="payment_id" value="' . esc_attr((int) $payment->id) . '">';
        wp_nonce_field('uapm_record_payment');
        echo '<input type="number" name="amount" min="0.01" step="0.01" required placeholder="' . esc_attr__('Received', 'ua-project-manager') . '">';
        echo '<button class="button button-small">' . esc_html__('Record receipt', 'ua-project-manager') . '</button>';
        echo '</form>';
    }

    private static function render_payment_action($action, $nonce, $payment_id, $label) {
        echo '<form class="uapm-inline-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '">';
        echo '<input type="hidden" name="payment_id" value="' . esc_attr((int) $payment_id) . '">';
        wp_nonce_field($nonce);
        echo '<button class="button button-primary button-small">' . esc_html($label) . '</button>';
        echo '</form>';
    }

    private static function render_revoke_form($payment) {
        echo '<form class="uapm-inline-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(' . esc_attr(wp_json_encode(__('Revoke PRO access for this payment?', 'ua-project-manager'))) . ')">';
        echo '<input type="hidden" name="action" value="uapm_revoke_payment">';
        echo '<input type="hidden" name="payment_id" value="' . esc_attr((int) $payment->id) . '">';
        wp_nonce_field('uapm_revoke_payment');
        echo '<button class="button button-small">' . esc_html__('Revoke PRO', 'ua-project-manager') . '</button>';
        echo '</form>';
    }
}