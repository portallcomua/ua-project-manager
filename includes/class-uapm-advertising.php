<?php
/**
 * UA Project Manager — Advertising module.
 *
 * @package UA_Project_Manager
 */
if (!defined('ABSPATH')) {
    exit;
}

final class UAPM_Advertising {
    const DB_VERSION = '1.0.0';
    const CRON_HOOK = 'uapm_advertising_daily';

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'), 40);
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
        add_action('admin_post_uapm_ad_save_advertiser', array(__CLASS__, 'save_advertiser'));
        add_action('admin_post_uapm_ad_save_block', array(__CLASS__, 'save_block'));
        add_action('admin_post_uapm_ad_save_payment', array(__CLASS__, 'save_payment'));
        add_action('init', array(__CLASS__, 'track_click'));
        add_action('init', array(__CLASS__, 'register_shortcodes'));
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
        add_action(self::CRON_HOOK, array(__CLASS__, 'daily_tasks'));
        add_action('init', array(__CLASS__, 'schedule'));
        self::install();
    }

    private static function table($name) {
        global $wpdb;
        return $wpdb->prefix . 'uapm_ad_' . $name;
    }

    private static function sanitize_banner_html($html) {
        $html = (string)$html;
        return current_user_can('unfiltered_html') ? $html : wp_kses_post($html);
    }

    public static function install() {
        global $wpdb;
        if (get_option('uapm_ad_db_version') === self::DB_VERSION) {
            return;
        }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE " . self::table('advertisers') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            website varchar(500) NOT NULL DEFAULT '',
            email varchar(191) NOT NULL DEFAULT '',
            phone varchar(100) NOT NULL DEFAULT '',
            viber varchar(191) NOT NULL DEFAULT '',
            telegram varchar(191) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY email (email)
        ) $c;");
        dbDelta("CREATE TABLE " . self::table('blocks') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            advertiser_id bigint(20) unsigned NOT NULL,
            project_ids longtext NULL,
            banner_html longtext NULL,
            banner_image varchar(500) NOT NULL DEFAULT '',
            target_url varchar(1000) NOT NULL DEFAULT '',
            monthly_price decimal(18,2) NOT NULL DEFAULT 0.00,
            currency varchar(3) NOT NULL DEFAULT 'UAH',
            start_date date NOT NULL,
            end_date date NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            service_id bigint(20) unsigned NOT NULL DEFAULT 0,
            reminder_7_sent tinyint(1) NOT NULL DEFAULT 0,
            reminder_end_sent tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY advertiser_id (advertiser_id),
            KEY status_dates (status,start_date,end_date)
        ) $c;");
        dbDelta("CREATE TABLE " . self::table('payments') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            block_id bigint(20) unsigned NOT NULL,
            amount decimal(18,2) NOT NULL DEFAULT 0.00,
            currency varchar(3) NOT NULL DEFAULT 'UAH',
            payment_type varchar(30) NOT NULL DEFAULT 'cash',
            payment_date date NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'paid',
            reference varchar(191) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY block_id (block_id),
            KEY payment_date (payment_date)
        ) $c;");
        dbDelta("CREATE TABLE " . self::table('events') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            block_id bigint(20) unsigned NOT NULL,
            event_type varchar(12) NOT NULL,
            visitor_hash char(64) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY block_type_date (block_id,event_type,created_at)
        ) $c;");
        dbDelta("CREATE TABLE " . self::table('renewals') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            block_id bigint(20) unsigned NOT NULL,
            advertiser_id bigint(20) unsigned NOT NULL,
            service_id bigint(20) unsigned NOT NULL,
            token char(64) NOT NULL,
            checkout_url text NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            freemius_license_id varchar(191) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            completed_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY token (token),
            KEY service_status (service_id,status),
            KEY advertiser_id (advertiser_id)
        ) $c;");
        update_option('uapm_ad_db_version', self::DB_VERSION, false);
    }

    public static function schedule() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
        }
    }

    public static function menu() {
        add_menu_page('Реклама', 'Реклама', 'manage_options', 'uapm-advertising', array(__CLASS__, 'render_page'), 'dashicons-megaphone', 7);
        add_submenu_page('uapm-advertising', 'Рекламодавці та блоки', 'Рекламодавці', 'manage_options', 'uapm-advertising', array(__CLASS__, 'render_page'));
        add_submenu_page('uapm-advertising', 'Звіти реклами', 'Звіти', 'manage_options', 'uapm-ad-reports', array(__CLASS__, 'render_reports'));
    }

    public static function assets($hook) {
        if (strpos((string) $hook, 'uapm-advertising') === false && strpos((string) $hook, 'uapm-ad-reports') === false && strpos((string) $hook, 'uapm-projects') === false) {
            return;
        }
        wp_enqueue_media();
        wp_add_inline_script('jquery', "jQuery(function($){var frame;$(document).on('click','.uapm-ad-media',function(e){e.preventDefault();if(frame){frame.open();return;}frame=wp.media({title:'Вибрати банер',button:{text:'Використати банер'},multiple:false,library:{type:'image'}});frame.on('select',function(){var a=frame.state().get('selection').first().toJSON();$('#uapm-ad-image').val(a.url);$('#uapm-ad-image-preview').attr('src',a.url).show();});frame.open();});});");
    }

    private static function guard() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостатньо прав.');
        }
    }

    public static function save_advertiser() {
        self::guard();
        check_admin_referer('uapm_ad_save_advertiser');
        global $wpdb;
        $id = absint($_POST['advertiser_id'] ?? 0);
        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        if (!$name || ($email && !is_email($email))) {
            wp_die('Вкажіть назву рекламодавця та коректний email.');
        }
        $data = array(
            'name' => $name,
            'website' => esc_url_raw(wp_unslash($_POST['website'] ?? '')),
            'email' => $email,
            'phone' => sanitize_text_field(wp_unslash($_POST['phone'] ?? '')),
            'viber' => sanitize_text_field(wp_unslash($_POST['viber'] ?? '')),
            'telegram' => sanitize_text_field(wp_unslash($_POST['telegram'] ?? '')),
            'updated_at' => current_time('mysql'),
        );
        if ($id) {
            $wpdb->update(self::table('advertisers'), $data, array('id' => $id));
        } else {
            $data['created_at'] = current_time('mysql');
            $wpdb->insert(self::table('advertisers'), $data);
            $id = (int) $wpdb->insert_id;
        }
        wp_safe_redirect(add_query_arg(array('page'=>'uapm-advertising','advertiser_id'=>$id,'saved'=>'1'), admin_url('admin.php')));
        exit;
    }

    public static function save_block() {
        self::guard();
        check_admin_referer('uapm_ad_save_block');
        global $wpdb;
        $advertiser_id = absint($_POST['advertiser_id'] ?? 0);
        $advertiser = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table('advertisers') . " WHERE id=%d", $advertiser_id));
        if (!$advertiser) {
            wp_die('Рекламодавця не знайдено.');
        }
        $projects = isset($_POST['project_ids']) ? array_values(array_unique(array_filter(array_map('absint', (array) wp_unslash($_POST['project_ids']))))) : array();
        if (!$projects) {
            wp_die('Оберіть хоча б один проєкт.');
        }
        $available = self::available_project_ids();
        $projects = array_values(array_intersect($projects, $available));
        if (!$projects) {
            wp_die('Вибрані проєкти вже мають розміщення реклами або недоступні.');
        }
        $start = sanitize_text_field(wp_unslash($_POST['start_date'] ?? ''));
        $end = sanitize_text_field(wp_unslash($_POST['end_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) || !checkdate((int)substr($start,5,2),(int)substr($start,8,2),(int)substr($start,0,4)) || !checkdate((int)substr($end,5,2),(int)substr($end,8,2),(int)substr($end,0,4)) || $end < $start) {
            wp_die('Перевірте дати початку та закінчення розміщення.');
        }
        $currency = strtoupper(sanitize_key(wp_unslash($_POST['currency'] ?? 'UAH')));
        if (!in_array($currency, array('UAH','USD'), true)) $currency = 'UAH';
        $service_id = absint($_POST['service_id'] ?? 0);
        $services = class_exists('\\UAPM\\PayBox\\UPB_Services') ? \UAPM\PayBox\UPB_Services::all() : array();
        if (!$service_id || empty($services[$service_id]) || (($services[$service_id]['service_type'] ?? 'license') !== 'advertising')) {
            wp_die('Створи або вибери в Pay Box окрему активну послугу з типом «Розміщення реклами». Вона потрібна для оплати та автоматичного продовження.');
        }
        $banner_html = self::sanitize_banner_html(wp_unslash($_POST['banner_html'] ?? ''));
        $banner_image = esc_url_raw(wp_unslash($_POST['banner_image'] ?? ''));
        if ('' === trim($banner_html) && '' === $banner_image) wp_die('Додай HTML-код або завантаж зображення банера.');
        $amount = max(0, (float) str_replace(',', '.', sanitize_text_field(wp_unslash($_POST['payment_amount'] ?? '0'))));
        $payment_currency = strtoupper(sanitize_key(wp_unslash($_POST['payment_currency'] ?? $currency)));
        if (!in_array($payment_currency, array('UAH','USD'), true)) $payment_currency = $currency;
        $type = sanitize_key(wp_unslash($_POST['payment_type'] ?? 'cash'));
        if (!in_array($type, array('freemius','privat_envelope','cash'), true)) $type = 'cash';
        if ($type === 'freemius' && $amount <= 0) $amount = max(0, (float) str_replace(',', '.', sanitize_text_field(wp_unslash($_POST['monthly_price'] ?? '0'))));
        $data = array(
            'advertiser_id' => $advertiser_id,
            'project_ids' => wp_json_encode($projects),
            'banner_html' => $banner_html,
            'banner_image' => $banner_image,
            'target_url' => esc_url_raw(wp_unslash($_POST['target_url'] ?? '')),
            'monthly_price' => max(0, (float) str_replace(',', '.', sanitize_text_field(wp_unslash($_POST['monthly_price'] ?? '0')))),
            'currency' => $currency,
            'start_date' => $start,
            'end_date' => $end,
            'status' => ($amount > 0 && $type !== 'freemius') ? 'active' : 'pending_payment',
            'service_id' => $service_id,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        );
        $wpdb->insert(self::table('blocks'), $data);
        $block_id = (int) $wpdb->insert_id;
        if (!$block_id) {
            wp_die('Не вдалося зберегти рекламний блок: ' . esc_html($wpdb->last_error));
        }

        if ($amount > 0) {
            $payment_date = sanitize_text_field(wp_unslash($_POST['payment_date'] ?? current_time('Y-m-d')));
            $payment_status = $type === 'freemius' ? 'pending' : 'paid';
            $wpdb->insert(self::table('payments'), array(
                'block_id'=>$block_id,'amount'=>$amount,'currency'=>$payment_currency,'payment_type'=>$type,
                'payment_date'=>$payment_date,'status'=>$payment_status,'created_at'=>current_time('mysql')
            ));
        }
        if ($type === 'freemius' && is_email($advertiser->email)) {
            $checkout = class_exists('\\UAPM\\PayBox\\UPB_Freemius') ? \UAPM\PayBox\UPB_Freemius::checkout_url($services[$service_id]) : '';
            if ($checkout) {
                $checkout = add_query_arg('user_email', rawurlencode($advertiser->email), $checkout);
                wp_mail($advertiser->email, 'Оплата розміщення реклами', 'Для оплати розміщення реклами перейдіть за посиланням: ' . $checkout);
            }
        }
        wp_safe_redirect(add_query_arg(array('page'=>'uapm-advertising','advertiser_id'=>$advertiser_id,'block_saved'=>$block_id), admin_url('admin.php')));
        exit;
    }

    public static function save_payment() {
        self::guard();
        check_admin_referer('uapm_ad_save_payment');
        global $wpdb;
        $block_id = absint($_POST['block_id'] ?? 0);
        $block = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table('blocks') . " WHERE id=%d", $block_id));
        if (!$block) wp_die('Рекламний блок не знайдено.');
        $amount = max(0, (float) str_replace(',', '.', sanitize_text_field(wp_unslash($_POST['amount'] ?? '0'))));
        if ($amount <= 0) wp_die('Сума оплати має бути більшою за нуль.');
        $currency = strtoupper(sanitize_key(wp_unslash($_POST['currency'] ?? $block->currency)));
        if (!in_array($currency, array('UAH','USD'), true)) $currency = $block->currency;
        $type = sanitize_key(wp_unslash($_POST['payment_type'] ?? 'cash'));
        if (!in_array($type, array('freemius','privat_envelope','cash'), true)) $type = 'cash';
        $date = sanitize_text_field(wp_unslash($_POST['payment_date'] ?? current_time('Y-m-d')));
        $status = $type === 'freemius' ? 'pending' : 'paid';
        $wpdb->insert(self::table('payments'), array('block_id'=>$block_id,'amount'=>$amount,'currency'=>$currency,'payment_type'=>$type,'payment_date'=>$date,'status'=>$status,'created_at'=>current_time('mysql')));
        if ($type !== 'freemius') $wpdb->update(self::table('blocks'), array('status'=>'active'), array('id'=>$block_id));
        if ($type === 'freemius' && !empty($block->service_id)) {
            $services = class_exists('\\UAPM\\PayBox\\UPB_Services') ? \UAPM\PayBox\UPB_Services::all() : array();
            $advertiser_email = $wpdb->get_var($wpdb->prepare("SELECT email FROM " . self::table('advertisers') . " WHERE id=%d", (int)$block->advertiser_id));
            if (is_email($advertiser_email) && !empty($services[(int)$block->service_id])) {
                $checkout = \UAPM\PayBox\UPB_Freemius::checkout_url($services[(int)$block->service_id]);
                if ($checkout) wp_mail($advertiser_email, 'Оплата розміщення реклами', 'Для оплати розміщення реклами перейдіть за посиланням: ' . add_query_arg('user_email', rawurlencode($advertiser_email), $checkout));
            }
        }
        wp_safe_redirect(add_query_arg(array('page'=>'uapm-advertising','advertiser_id'=>(int)$block->advertiser_id,'payment_saved'=>'1'), admin_url('admin.php')));
        exit;
    }

    public static function get_project_extras($project_id) {
        $all = get_option('uapm_project_display_options', array());
        $v = isset($all[(int)$project_id]) && is_array($all[(int)$project_id]) ? $all[(int)$project_id] : array();
        return wp_parse_args($v, array('all_projects_url'=>'','all_projects_visible'=>0,'project_banner_html'=>'','project_banner_visible'=>0,'ads_available'=>0));
    }

    public static function save_project_extras($project_id, $data) {
        $project_id = absint($project_id);
        if (!$project_id || !is_array($data)) return;
        $all = get_option('uapm_project_display_options', array());
        if (!is_array($all)) $all = array();
        $all[$project_id] = array(
            'all_projects_url' => esc_url_raw($data['all_projects_url'] ?? ''),
            'all_projects_visible' => !empty($data['all_projects_visible']) ? 1 : 0,
            'project_banner_html' => self::sanitize_banner_html($data['project_banner_html'] ?? ''),
            'project_banner_visible' => !empty($data['project_banner_visible']) ? 1 : 0,
            'ads_available' => !empty($data['ads_available']) ? 1 : 0,
        );
        update_option('uapm_project_display_options', $all, false);
    }

    public static function project_options($exclude_booked = true) {
        if (!class_exists('UAPM_Projects')) return array();
        $projects = UAPM_Projects::get_all(array('status'=>'active','limit'=>200,'orderby'=>'name','order'=>'ASC'));
        $booked = $exclude_booked ? self::booked_project_ids() : array();
        $out = array();
        foreach ($projects as $p) {
            $extra = self::get_project_extras($p->id);
            if (!$extra['ads_available'] || in_array((int)$p->id, $booked, true)) continue;
            $out[(int)$p->id] = $p->name . ' (' . $p->project_type . ')';
        }
        return $out;
    }

    private static function available_project_ids() {
        return array_map('intval', array_keys(self::project_options(true)));
    }

    private static function booked_project_ids() {
        global $wpdb;
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT project_ids FROM " . self::table('blocks') . " WHERE status IN ('active','pending_payment') AND end_date >= %s",
            current_time('Y-m-d')
        ));
        $ids = array();
        foreach ((array)$rows as $json) {
            $values = json_decode($json, true);
            foreach ((array)$values as $id) $ids[] = absint($id);
        }
        return array_values(array_unique($ids));
    }

    private static function services_for_advertising() {
        if (!class_exists('\\UAPM\\PayBox\\UPB_Services')) return array();
        $out = array();
        foreach (\UAPM\PayBox\UPB_Services::all() as $id=>$service) {
            if (($service['service_type'] ?? 'license') === 'advertising' && !empty($service['enabled'])) $out[(int)$id] = $service['name'];
        }
        return $out;
    }

    public static function render_page() {
        self::guard();
        global $wpdb;
        $edit_id = absint($_GET['advertiser_id'] ?? 0);
        $advertiser = $edit_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table('advertisers') . " WHERE id=%d", $edit_id)) : null;
        $advertisers = $wpdb->get_results("SELECT * FROM " . self::table('advertisers') . " ORDER BY created_at DESC");
        echo '<div class="wrap"><h1>Реклама — рекламодавці та розміщення</h1>';
        if (!empty($_GET['saved'])) echo '<div class="notice notice-success"><p>Рекламодавця збережено.</p></div>';
        if (!empty($_GET['block_saved'])) echo '<div class="notice notice-success"><p>Рекламний блок додано.</p></div>';
        if (!empty($_GET['payment_saved'])) echo '<div class="notice notice-success"><p>Платіж записано.</p></div>';
        echo '<div class="uapm-card" style="background:#fff;padding:18px;margin:15px 0;border:1px solid #ccd0d4">';
        echo '<h2>' . ($advertiser ? 'Редагувати рекламодавця' : 'Створити рекламодавця') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="uapm_ad_save_advertiser"><input type="hidden" name="advertiser_id" value="' . (int)($advertiser->id ?? 0) . '">';
        wp_nonce_field('uapm_ad_save_advertiser');
        echo '<p><label>Назва рекламодавця<br><input class="regular-text" required name="name" value="' . esc_attr($advertiser->name ?? '') . '"></label>　<label>Дата створення<br><input type="text" readonly value="' . esc_attr($advertiser->created_at ?? current_time('mysql')) . '"></label></p>';
        echo '<p><label>Сайт<br><input type="url" class="regular-text" name="website" value="' . esc_attr($advertiser->website ?? '') . '"></label></p>';
        echo '<p><label>Email<br><input type="email" class="regular-text" name="email" value="' . esc_attr($advertiser->email ?? '') . '"></label> ';
        echo '<label>Телефон<br><input class="regular-text" name="phone" value="' . esc_attr($advertiser->phone ?? '') . '"></label></p>';
        echo '<p><label>Viber<br><input class="regular-text" name="viber" value="' . esc_attr($advertiser->viber ?? '') . '"></label> ';
        echo '<label>Telegram (@username або посилання)<br><input class="regular-text" name="telegram" value="' . esc_attr($advertiser->telegram ?? '') . '"></label></p>';
        submit_button($advertiser ? 'Зберегти рекламодавця' : 'Створити рекламодавця');
        echo '</form></div>';

        echo '<h2>Рекламодавці</h2><table class="widefat striped"><thead><tr><th>Назва</th><th>Контакти</th><th>Створено</th><th></th></tr></thead><tbody>';
        foreach ($advertisers as $a) {
            $contacts = array();
            if ($a->website) $contacts[] = '<a href="' . esc_url($a->website) . '" target="_blank" rel="noopener">Сайт</a>';
            if ($a->email) $contacts[] = '<a href="' . esc_url('https://mail.google.com/mail/?view=cm&fs=1&to=' . rawurlencode($a->email)) . '" target="_blank" rel="noopener">' . esc_html($a->email) . '</a>';
            if ($a->phone) $contacts[] = '<a href="tel:' . esc_attr(preg_replace('/[^0-9+]/','',$a->phone)) . '">' . esc_html($a->phone) . '</a>';
            if ($a->viber) $contacts[] = '<a href="' . esc_url('viber://chat?number=' . rawurlencode($a->viber)) . '">Viber</a>';
            if ($a->telegram) { $tg = preg_match('~^https?://~i',$a->telegram) ? $a->telegram : 'https://t.me/' . ltrim($a->telegram,'@'); $contacts[] = '<a href="' . esc_url($tg) . '" target="_blank" rel="noopener">Telegram</a>'; }
            echo '<tr><td><strong>' . esc_html($a->name) . '</strong></td><td>' . implode(' · ', $contacts) . '</td><td>' . esc_html($a->created_at) . '</td><td><a class="button button-small" href="' . esc_url(add_query_arg(array('page'=>'uapm-advertising','advertiser_id'=>$a->id),admin_url('admin.php'))) . '">Відкрити / редагувати</a></td></tr>';
        }
        if (!$advertisers) echo '<tr><td colspan="4">Рекламодавців ще немає.</td></tr>';
        echo '</tbody></table>';

        if ($advertiser) {
            self::render_block_form($advertiser);
            self::render_blocks($advertiser->id);
        }
        echo '</div>';
    }

    private static function render_block_form($advertiser) {
        $projects = self::project_options(true);
        $services = self::services_for_advertising();
        echo '<div style="background:#fff;padding:18px;margin:20px 0;border:1px solid #ccd0d4"><h2>Додати рекламний блок — ' . esc_html($advertiser->name) . '</h2>';
        echo '<p class="description">Проєкти з активним розміщенням автоматично прибираються зі списку доступних.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="uapm_ad_save_block"><input type="hidden" name="advertiser_id" value="' . (int)$advertiser->id . '">';
        wp_nonce_field('uapm_ad_save_block');
        echo '<p><label>Проєкти (можна вибрати декілька)<br><select name="project_ids[]" multiple size="6" required style="min-width:360px">';
        foreach ($projects as $id=>$label) echo '<option value="' . (int)$id . '">' . esc_html($label) . '</option>';
        echo '</select></label></p>';
        echo '<p><label>HTML-код банера<br><textarea name="banner_html" rows="5" class="large-text code" placeholder="Вставте HTML банера"></textarea></label></p>';
        echo '<p><label>Завантажити банер<br><input type="url" id="uapm-ad-image" name="banner_image" class="regular-text" placeholder="URL з медіатеки"> <button class="button uapm-ad-media">Вибрати банер</button></label><br><img id="uapm-ad-image-preview" style="max-width:240px;max-height:120px;display:none" alt=""></p>';
        echo '<p><label>Посилання банера<br><input type="url" name="target_url" class="large-text" placeholder="https://..."></label></p>';
        echo '<p><label>Ціна за місяць<br><input type="number" min="0" step="0.01" name="monthly_price" required> <select name="currency"><option value="UAH">грн</option><option value="USD">USD</option></select></label></p>';
        echo '<p><label>Початок розміщення <input type="date" name="start_date" required value="' . esc_attr(current_time('Y-m-d')) . '"></label>　<label>Закінчення <input type="date" name="end_date" required></label></p>';
        echo '<p><label>Послуга Pay Box для реклами<br><select name="service_id" required><option value="0">— не прив’язувати —</option>';
        foreach ($services as $id=>$name) echo '<option value="' . (int)$id . '">' . esc_html($name) . '</option>';
        echo '</select></label> <span class="description">Створи в Pay Box окрему послугу з типом «Розміщення реклами».</span></p>';
        echo '<h3>Початкова оплата (можна залишити порожньою)</h3>';
        echo '<p><label>Сума <input type="number" min="0" step="0.01" name="payment_amount"></label> <label>Валюта <select name="payment_currency"><option value="UAH">грн</option><option value="USD">USD</option></select></label> <label>Тип <select name="payment_type"><option value="freemius">Freemius</option><option value="privat_envelope">Приват Конверт</option><option value="cash">Готівка</option></select></label> <label>Дата <input type="date" name="payment_date" value="' . esc_attr(current_time('Y-m-d')) . '"></label></p>';
        submit_button('Додати рекламний блок');
        echo '</form></div>';
    }

    private static function render_blocks($advertiser_id) {
        global $wpdb;
        $blocks = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::table('blocks') . " WHERE advertiser_id=%d ORDER BY id DESC", $advertiser_id));
        echo '<h2>Рекламні блоки та оплати</h2>';
        echo '<table class="widefat striped"><thead><tr><th>Проєкти</th><th>Період</th><th>Ціна / місяць</th><th>Покази</th><th>Кліки</th><th>Оплати</th><th>Статус</th></tr></thead><tbody>';
        foreach ($blocks as $b) {
            $ids = json_decode($b->project_ids, true); $names = array();
            foreach ((array)$ids as $id) { $p = UAPM_Projects::get($id); if ($p) $names[] = $p->name; }
            $stats = self::counts($b->id);
            $payments = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::table('payments') . " WHERE block_id=%d ORDER BY payment_date DESC", $b->id));
            $paid = array();
            foreach ($payments as $p) $paid[] = number_format_i18n((float)$p->amount,2) . ' ' . esc_html($p->currency) . ' · ' . esc_html($p->payment_type) . ' · ' . esc_html($p->payment_date) . ' (' . esc_html($p->status) . ')';
            echo '<tr><td>' . esc_html(implode(', ', $names)) . '</td><td>' . esc_html($b->start_date . ' — ' . $b->end_date) . '</td><td>' . esc_html(number_format_i18n((float)$b->monthly_price,2) . ' ' . $b->currency) . '</td><td>' . (int)$stats['impressions'] . '</td><td>' . (int)$stats['clicks'] . '</td><td>' . ($paid ? implode('<br>', $paid) : '—') . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:6px"><input type="hidden" name="action" value="uapm_ad_save_payment"><input type="hidden" name="block_id" value="' . (int)$b->id . '">';
            wp_nonce_field('uapm_ad_save_payment');
            echo '<input type="number" min="0" step="0.01" name="amount" placeholder="Сума" required style="width:90px"><select name="currency"><option value="UAH">грн</option><option value="USD">USD</option></select><select name="payment_type"><option value="privat_envelope">Приват Конверт</option><option value="cash">Готівка</option><option value="freemius">Freemius</option></select><input type="date" name="payment_date" value="' . esc_attr(current_time('Y-m-d')) . '"><button class="button button-small">Додати оплату</button></form></td><td>' . esc_html($b->status) . '</td></tr>';
        }
        if (!$blocks) echo '<tr><td colspan="7">Рекламних блоків ще немає.</td></tr>';
        echo '</tbody></table>';
    }

    public static function render_reports() {
        self::guard();
        global $wpdb;
        $from = sanitize_text_field($_GET['from'] ?? date('Y-m-01', strtotime('-1 month')));
        $to = sanitize_text_field($_GET['to'] ?? date('Y-m-t', strtotime('-1 month')));
        echo '<div class="wrap"><h1>Звіти реклами</h1><form method="get"><input type="hidden" name="page" value="uapm-ad-reports"><label>З <input type="date" name="from" value="' . esc_attr($from) . '"></label> <label>По <input type="date" name="to" value="' . esc_attr($to) . '"></label> <button class="button">Показати</button></form>';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT a.name advertiser,b.id block_id,b.project_ids,b.start_date,b.end_date, SUM(e.event_type='impression') impressions,SUM(e.event_type='click') clicks FROM " . self::table('blocks') . " b INNER JOIN " . self::table('advertisers') . " a ON a.id=b.advertiser_id LEFT JOIN " . self::table('events') . " e ON e.block_id=b.id AND DATE(e.created_at) BETWEEN %s AND %s GROUP BY b.id ORDER BY a.name,b.id DESC", $from, $to));
        echo '<table class="widefat striped"><thead><tr><th>Рекламодавець</th><th>Блок / проєкти</th><th>Покази</th><th>Кліки</th><th>Період</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $names=array(); foreach ((array)json_decode($r->project_ids,true) as $id) {$p=UAPM_Projects::get($id);if($p)$names[]=$p->name;}
            echo '<tr><td>'.esc_html($r->advertiser).'</td><td>#'.(int)$r->block_id.' — '.esc_html(implode(', ',$names)).'</td><td>'.(int)$r->impressions.'</td><td>'.(int)$r->clicks.'</td><td>'.esc_html($r->start_date.' — '.$r->end_date).'</td></tr>';
        }
        if (!$rows) echo '<tr><td colspan="5">За цей період подій немає.</td></tr>';
        echo '</tbody></table></div>';
    }

    private static function counts($block_id, $from = '', $to = '') {
        global $wpdb;
        $where = 'block_id=%d'; $args = array((int)$block_id);
        if ($from && $to) { $where .= ' AND DATE(created_at) BETWEEN %s AND %s'; $args[]=$from; $args[]=$to; }
        $sql = $wpdb->prepare("SELECT SUM(event_type='impression') impressions,SUM(event_type='click') clicks FROM " . self::table('events') . " WHERE $where", $args);
        $r = $wpdb->get_row($sql, ARRAY_A);
        return array('impressions'=>(int)($r['impressions'] ?? 0),'clicks'=>(int)($r['clicks'] ?? 0));
    }

    private static function record_event($block_id, $type) {
        global $wpdb;
        $ip = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '');
        $visitor = hash_hmac('sha256', $ip . '|' . wp_salt('auth'), wp_salt('secure_auth'));
        $wpdb->insert(self::table('events'), array('block_id'=>(int)$block_id,'event_type'=>$type,'visitor_hash'=>$visitor,'created_at'=>current_time('mysql')));
    }

    private static function active_block_for_project($project_id) {
        global $wpdb;
        $today = current_time('Y-m-d');
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::table('blocks') . " WHERE status='active' AND start_date<=%s AND end_date>=%s ORDER BY id DESC", $today, $today));
        foreach ($rows as $b) {
            if (in_array((int)$project_id, array_map('intval',(array)json_decode($b->project_ids,true)), true)) return $b;
        }
        return null;
    }

    private static function ad_html($block) {
        $click = add_query_arg(array('uapm_ad_click'=>(int)$block->id), home_url('/'));
        if (!empty($block->banner_html)) {
            $html = $block->banner_html;
            if (!empty($block->target_url)) $html = '<a href="' . esc_url($click) . '" target="_blank" rel="sponsored noopener">' . $html . '</a>';
            return $html;
        }
        if (!empty($block->banner_image)) {
            return '<a href="' . esc_url($click) . '" target="_blank" rel="sponsored noopener"><img src="' . esc_url($block->banner_image) . '" alt="Реклама" style="max-width:100%;height:auto"></a>';
        }
        return '';
    }

    public static function register_shortcodes() {
        add_shortcode('uapm_project_display', function ($atts) {
            $atts = shortcode_atts(array('project'=>''), $atts);
            $project = UAPM_Projects::get_by_slug($atts['project']);
            if (!$project) return '';
            $extra = self::get_project_extras($project->id);
            $html = '';
            if (!empty($extra['all_projects_visible']) && !empty($extra['all_projects_url'])) $html .= '<p class="uapm-all-projects"><a href="' . esc_url($extra['all_projects_url']) . '">Всі проєкти</a></p>';
            if (!empty($extra['project_banner_visible']) && !empty($extra['project_banner_html'])) $html .= '<div class="uapm-project-banner">' . $extra['project_banner_html'] . '</div>';
            return $html;
        });
        add_shortcode('uapm_ad', function ($atts) {
            $atts = shortcode_atts(array('project'=>''), $atts);
            $project = UAPM_Projects::get_by_slug($atts['project']);
            if (!$project) return '';
            $block = self::active_block_for_project($project->id);
            if (!$block) return '';
            self::record_event($block->id, 'impression');
            return '<div class="uapm-ad-slot" data-ad-block="' . (int)$block->id . '">' . self::ad_html($block) . '</div>';
        });
    }

    public static function track_click() {
        if (empty($_GET['uapm_ad_click'])) return;
        global $wpdb;
        $id = absint($_GET['uapm_ad_click']);
        $block = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table('blocks') . " WHERE id=%d", $id));
        if (!$block || $block->status !== 'active' || empty($block->target_url)) wp_die('Рекламне посилання недоступне.', '', array('response'=>404));
        self::record_event($id, 'click');
        wp_redirect(esc_url_raw($block->target_url), 302, 'UA Project Manager Advertising');
        exit;
    }

    public static function register_routes() {
        register_rest_route('uapm/v1', '/project-config/(?P<project>[a-z0-9-]+)', array(
            'methods'=>'GET','permission_callback'=>'__return_true',
            'callback'=>function($request) {
                $project=UAPM_Projects::get_by_slug($request['project']);
                if (!$project) return new WP_REST_Response(array('visible'=>false),404);
                $extra=self::get_project_extras($project->id);
                return array(
                    'all_projects_url'=>!empty($extra['all_projects_visible']) ? $extra['all_projects_url'] : '',
                    'project_banner_html'=>!empty($extra['project_banner_visible']) ? $extra['project_banner_html'] : '',
                    'advertising_available'=>(bool)$extra['ads_available']
                );
            }
        ));
        register_rest_route('uapm/v1', '/ad/(?P<project>[a-z0-9-]+)', array(
            'methods'=>'GET','permission_callback'=>'__return_true',
            'callback'=>function($request) {
                $project=UAPM_Projects::get_by_slug($request['project']);
                if (!$project) return new WP_REST_Response(array('html'=>''),404);
                $block=self::active_block_for_project($project->id);
                if (!$block) return array('html'=>'','active'=>false);
                self::record_event($block->id,'impression');
                return array('html'=>self::ad_html($block),'active'=>true,'block_id'=>(int)$block->id);
            }
        ));
    }

    public static function project_embed_code($project_id) {
        $project=UAPM_Projects::get($project_id);
        if (!$project) return '';
        $url=rest_url('uapm/v1/ad/' . rawurlencode($project->slug));
        $config_url=rest_url('uapm/v1/project-config/' . rawurlencode($project->slug));
        $html = "WordPress plugin / project display:\n[uapm_project_display project=\"" . $project->slug . "\"]\n[uapm_ad project=\"" . $project->slug . "\"]\n\nREST configuration for Android / app:\nGET " . $config_url . "\nREST advertising banner endpoint:\nGET " . $url . "\n\nThe configuration endpoint returns the visible project link and HTML banner. The advertising endpoint returns JSON with active, html and block_id.";
        return $html;
    }

    public static function project_ad_summary($project_id) {
        global $wpdb;
        $blocks = $wpdb->get_results("SELECT * FROM " . self::table('blocks') . " WHERE status='active' ORDER BY end_date DESC");
        foreach ($blocks as $block) {
            if (!in_array((int)$project_id, array_map('intval', (array)json_decode($block->project_ids, true)), true)) continue;
            $advertiser = $wpdb->get_var($wpdb->prepare("SELECT name FROM " . self::table('advertisers') . " WHERE id=%d", (int)$block->advertiser_id));
            $counts = self::counts($block->id);
            return array('advertiser'=>(string)$advertiser,'impressions'=>$counts['impressions'],'clicks'=>$counts['clicks'],'start'=>$block->start_date,'end'=>$block->end_date);
        }
        return array();
    }

    public static function handle_freemius_license($service_id, $email, $license_id) {
        global $wpdb;
        $service_id=absint($service_id); $email=sanitize_email($email);
        if (!$service_id || !is_email($email)) return false;
        $services=class_exists('\\UAPM\\PayBox\\UPB_Services') ? \UAPM\PayBox\UPB_Services::all() : array();
        if (empty($services[$service_id]) || ($services[$service_id]['service_type'] ?? 'license') !== 'advertising') return false;
        $order=$wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table('renewals') . " WHERE service_id=%d AND status='pending' AND advertiser_id IN (SELECT id FROM " . self::table('advertisers') . " WHERE LOWER(email)=LOWER(%s)) ORDER BY id DESC LIMIT 1", $service_id, $email));
        if (!$order) {
            // Initial placement payment: confirm the newest pending Freemius payment for this advertiser and service.
            $payment = $wpdb->get_row($wpdb->prepare("SELECT p.* FROM " . self::table('payments') . " p INNER JOIN " . self::table('blocks') . " b ON b.id=p.block_id INNER JOIN " . self::table('advertisers') . " a ON a.id=b.advertiser_id WHERE b.service_id=%d AND LOWER(a.email)=LOWER(%s) AND p.payment_type='freemius' AND p.status='pending' ORDER BY p.id DESC LIMIT 1", $service_id, $email));
            if ($payment) {
                $wpdb->update(self::table('payments'), array('status'=>'paid','reference'=>sanitize_text_field($license_id),'payment_date'=>current_time('Y-m-d')), array('id'=>(int)$payment->id));
                $wpdb->update(self::table('blocks'), array('status'=>'active'), array('id'=>(int)$payment->block_id));
                return true;
            }
            return false;
        }
        $old=$wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table('blocks') . " WHERE id=%d", (int)$order->block_id));
        if (!$old) return false;
        $start = $old->end_date >= current_time('Y-m-d') ? date('Y-m-d', strtotime($old->end_date . ' +1 day')) : current_time('Y-m-d');
        $end = date('Y-m-d', strtotime($start . ' +1 month -1 day'));
        $wpdb->insert(self::table('blocks'), array(
            'advertiser_id'=>(int)$old->advertiser_id,'project_ids'=>$old->project_ids,'banner_html'=>$old->banner_html,'banner_image'=>$old->banner_image,'target_url'=>$old->target_url,
            'monthly_price'=>$old->monthly_price,'currency'=>$old->currency,'start_date'=>$start,'end_date'=>$end,'status'=>'active','service_id'=>$service_id,
            'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')
        ));
        $new_id=(int)$wpdb->insert_id;
        if (!$new_id) return false;
        $wpdb->insert(self::table('payments'), array('block_id'=>$new_id,'amount'=>$old->monthly_price,'currency'=>$old->currency,'payment_type'=>'freemius','payment_date'=>current_time('Y-m-d'),'status'=>'paid','reference'=>sanitize_text_field($license_id),'created_at'=>current_time('mysql')));
        $wpdb->update(self::table('renewals'), array('status'=>'paid','freemius_license_id'=>sanitize_text_field($license_id),'completed_at'=>current_time('mysql')), array('id'=>(int)$order->id));
        return true;
    }

    public static function daily_tasks() {
        global $wpdb;
        $today=current_time('Y-m-d');
        $soon=date('Y-m-d',strtotime('+7 days',current_time('timestamp')));
        $blocks=$wpdb->get_results($wpdb->prepare("SELECT b.*,a.name advertiser,a.email FROM " . self::table('blocks') . " b INNER JOIN " . self::table('advertisers') . " a ON a.id=b.advertiser_id WHERE b.status='active' AND b.end_date <= %s AND (b.reminder_7_sent=0 OR b.reminder_end_sent=0)", $soon));
        foreach ($blocks as $b) {
            if (!is_email($b->email)) continue;
            if ($b->end_date > $today && $b->end_date <= $soon && !$b->reminder_7_sent) {
                $sent = wp_mail($b->email,'Нагадування: завершується розміщення реклами','Добрий день, '.$b->advertiser.'! Розміщення реклами завершується '.$b->end_date.'. Можна продовжити розміщення — у день завершення надішлемо посилання на оплату через Freemius.');
                if ($sent) $wpdb->update(self::table('blocks'),array('reminder_7_sent'=>1),array('id'=>(int)$b->id));
            }
            if ($b->end_date <= $today && !$b->reminder_end_sent) {
                $url=self::create_renewal_url($b);
                $body='Добрий день, '.$b->advertiser.'! Сьогодні завершується розміщення реклами. Для продовження на наступний місяць перейдіть за посиланням: '.$url;
                $sent = wp_mail($b->email,'Розміщення реклами завершується — продовжити',$body);
                if ($sent) $wpdb->update(self::table('blocks'),array('reminder_end_sent'=>1),array('id'=>(int)$b->id));
            }
        }
        self::send_monthly_reports();
    }

    private static function create_renewal_url($block) {
        global $wpdb;
        $service_id=(int)$block->service_id;
        $services=class_exists('\\UAPM\\PayBox\\UPB_Services') ? \UAPM\PayBox\UPB_Services::all() : array();
        if (!$service_id || empty($services[$service_id])) return home_url('/');
        $service=$services[$service_id];
        $checkout=class_exists('\\UAPM\\PayBox\\UPB_Freemius') ? \UAPM\PayBox\UPB_Freemius::checkout_url($service) : '';
        if (!$checkout) return home_url('/');
        $token=hash('sha256',wp_generate_password(40,false,false).microtime(true));
        $wpdb->insert(self::table('renewals'),array('block_id'=>(int)$block->id,'advertiser_id'=>(int)$block->advertiser_id,'service_id'=>$service_id,'token'=>$token,'checkout_url'=>$checkout,'status'=>'pending','created_at'=>current_time('mysql')));
        return add_query_arg('user_email',rawurlencode((string)$wpdb->get_var($wpdb->prepare("SELECT email FROM " . self::table('advertisers') . " WHERE id=%d",$block->advertiser_id))),$checkout);
    }

    private static function send_monthly_reports() {
        global $wpdb;
        $report_month = wp_date('Y-m', strtotime('first day of last month', current_time('timestamp')));
        if (get_option('uapm_ad_report_sent_month') === $report_month) return;
        $from = $report_month . '-01';
        $to = wp_date('Y-m-t', strtotime('last day of last month', current_time('timestamp')));
        $advertisers=$wpdb->get_results("SELECT * FROM " . self::table('advertisers') . " WHERE email<>''");
        foreach ($advertisers as $a) {
            if (!is_email($a->email)) continue;
            $rows=$wpdb->get_results($wpdb->prepare("SELECT b.id,b.project_ids,b.start_date,b.end_date,SUM(e.event_type='impression') impressions,SUM(e.event_type='click') clicks FROM " . self::table('blocks') . " b LEFT JOIN " . self::table('events') . " e ON e.block_id=b.id AND DATE(e.created_at) BETWEEN %s AND %s WHERE b.advertiser_id=%d GROUP BY b.id", $from,$to,$a->id));
            $body="Звіт про рекламне розміщення за період $from — $to\n\n";
            foreach ($rows as $r) {
                $names=array();foreach((array)json_decode($r->project_ids,true) as $id){$p=UAPM_Projects::get($id);if($p)$names[]=$p->name;}
                $body.='Проєкти: '.implode(', ',$names)."\nПокази: ".(int)$r->impressions."\nКліки: ".(int)$r->clicks."\nПеріод: ".$r->start_date.' — '.$r->end_date."\n\n";
            }
            wp_mail($a->email,'Щомісячний звіт рекламного розміщення',$body);
        }
        update_option('uapm_ad_report_sent_month',$report_month,false);
    }
}
