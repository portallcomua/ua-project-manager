<?php
/**
 * Shared synchronization between projects, Pay Box and Web Finder.
 *
 * @package UA_Project_Manager
 */
if (!defined('ABSPATH')) {
    exit;
}

final class UAPM_Shared_Sync {
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'), 45);
        add_action('admin_post_uapm_sync_all', array(__CLASS__, 'handle_sync'));
        add_action('uapm_paybox_service_saved', array(__CLASS__, 'service_saved'), 10, 2);
    }

    public static function menu() {
        add_submenu_page('uapm', 'Синхронізація модулів', 'Синхронізація', 'manage_options', 'uapm-sync', array(__CLASS__, 'render'));
    }

    public static function service_saved($service_id, $service) {
        self::sync_all();
    }

    public static function sync_all() {
        if (!class_exists('UAPM_Projects')) return array('projects'=>0,'mapped'=>0);
        $projects = UAPM_Projects::get_all(array('limit'=>200,'orderby'=>'id','order'=>'ASC'));
        $mapped = 0;
        foreach ((array)$projects as $project) {
            if (function_exists('\\UAPM\\PayBox\\sync_project_to_paybox')) {
                $id = \UAPM\PayBox\sync_project_to_paybox((int)$project->id, (array)$project);
                if ($id) $mapped++;
            }
        }
        update_option('uapm_shared_sync_last_run', current_time('mysql'), false);
        update_option('uapm_shared_sync_last_result', array('projects'=>count((array)$projects),'mapped'=>$mapped), false);
        return array('projects'=>count((array)$projects),'mapped'=>$mapped);
    }

    public static function handle_sync() {
        if (!current_user_can('manage_options')) wp_die('Недостатньо прав.');
        check_admin_referer('uapm_sync_all');
        $result = self::sync_all();
        wp_safe_redirect(add_query_arg(array('page'=>'uapm-sync','synced'=>'1','projects'=>$result['projects'],'mapped'=>$result['mapped']), admin_url('admin.php')));
        exit;
    }

    private static function table_count($table) {
        global $wpdb;
        $full = $wpdb->prefix . $table;
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($full)));
        if ($found !== $full) return null;
        return (int)$wpdb->get_var("SELECT COUNT(*) FROM " . $full);
    }

    public static function project_finder_summary($project_id) {
        global $wpdb;
        $project_id = absint($project_id);
        $out = array('search_lists'=>array(),'contact_lists'=>array(),'sites'=>0,'emails'=>0,'campaigns'=>0);
        $search_lists_table = $wpdb->prefix . 'cmsf_lists';
        $search_sites_table = $wpdb->prefix . 'cmsf_sites';
        $contact_lists_table = $wpdb->prefix . 'cmsfc_lists';
        $contacts_table = $wpdb->prefix . 'cmsfc_contacts';
        $campaigns_table = $wpdb->prefix . 'cmsfc_campaigns';
        $exists = function($table) use ($wpdb) {
            return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
        };
        $search_ids = array();
        if ($exists($search_lists_table)) {
            $rows = $wpdb->get_results("SELECT id,name,params FROM " . $search_lists_table, ARRAY_A);
            foreach ((array)$rows as $row) {
                $params = json_decode($row['params'] ?? '{}', true);
                $ids = array_map('absint', (array)($params['project_ids'] ?? array()));
                if (in_array($project_id, $ids, true)) {
                    $out['search_lists'][] = $row['name'];
                    $search_ids[] = (int)$row['id'];
                }
            }
        }
        if ($search_ids && $exists($search_sites_table)) {
            $placeholders = implode(',', array_fill(0, count($search_ids), '%d'));
            $out['sites'] = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . $search_sites_table . " WHERE list_id IN ($placeholders)", $search_ids));
        }
        $contact_ids = array();
        if ($exists($contact_lists_table)) {
            $rows = $wpdb->get_results("SELECT id,name,project_ids FROM " . $contact_lists_table, ARRAY_A);
            foreach ((array)$rows as $row) {
                $ids = array_map('absint', (array)json_decode($row['project_ids'] ?? '[]', true));
                if (in_array($project_id, $ids, true)) {
                    $out['contact_lists'][] = $row['name'];
                    $contact_ids[] = (int)$row['id'];
                }
            }
        }
        if ($contact_ids) {
            $placeholders = implode(',', array_fill(0, count($contact_ids), '%d'));
            if ($exists($contacts_table)) $out['emails'] = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . $contacts_table . " WHERE list_id IN ($placeholders)", $contact_ids));
            if ($exists($campaigns_table)) $out['campaigns'] = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . $campaigns_table . " WHERE list_id IN ($placeholders)", $contact_ids));
        }
        return $out;
    }

    public static function render() {
        if (!current_user_can('manage_options')) wp_die('Недостатньо прав.');
        $last = (string)get_option('uapm_shared_sync_last_run','');
        $result = get_option('uapm_shared_sync_last_result',array());
        $map = get_option('uapm_paybox_project_map',array());
        $services = class_exists('\\UAPM\\PayBox\\UPB_Services') ? \UAPM\PayBox\UPB_Services::all() : array();
        $counts = array(
            'Проєкти головного блоку' => class_exists('UAPM_Projects') ? UAPM_Projects::count() : 0,
            'Послуги Pay Box' => count($services),
            'Зв’язки проєкт → Pay Box' => count((array)$map),
            'Списки пошуку Finder' => self::table_count('cmsf_lists'),
            'Сайти Finder' => self::table_count('cmsf_sites'),
            'Списки збору email' => self::table_count('cmsfc_lists'),
            'Зібрані контакти' => self::table_count('cmsfc_contacts'),
            'Кампанії розсилки' => self::table_count('cmsfc_campaigns'),
        );
        echo '<div class="wrap">';
        if (class_exists('UAPM_Admin')) { UAPM_Admin::render_breadcrumb('Налаштування', 'Синхронізація модулів'); }
        echo '<h1>Синхронізація даних модулів</h1>';
        if (!empty($_GET['synced'])) echo '<div class="notice notice-success"><p>Синхронізацію виконано. Проєктів: '.(int)($_GET['projects']??0).'; зв’язків Pay Box: '.(int)($_GET['mapped']??0).'.</p></div>';
        echo '<p>Головний блок «Проєкти» є основним довідником. Pay Box зберігає окремі платіжні послуги PRO та реклами. Web Finder зберігає свої списки, сайти, контакти, шаблони й кампанії; списки можна пов’язувати з одним або кількома проєктами за їхніми ID. Перейменування проєкту не розриває ці зв’язки.</p>';
        echo '<table class="widefat striped" style="max-width:850px"><tbody>';
        foreach ($counts as $label=>$count) echo '<tr><th>'.esc_html($label).'</th><td>'.($count===null?'Таблицю ще не створено':number_format_i18n($count)).'</td></tr>';
        echo '</tbody></table>';
        echo '<p><strong>Остання синхронізація:</strong> '.esc_html($last ?: 'ще не запускалася').'</p>';
        if ($result) echo '<p><strong>Останній результат:</strong> проєктів '.(int)($result['projects']??0).', прив’язано до Pay Box '.(int)($result['mapped']??0).'.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="uapm_sync_all">';
        wp_nonce_field('uapm_sync_all');
        submit_button('Синхронізувати всі проєкти з Pay Box');
        echo '</form>';
        echo '<p class="description">Синхронізація не видаляє таблиці, записи ліцензій, списки Finder, сайти, email, шаблони чи історію розсилок.</p></div>';
    }
}
