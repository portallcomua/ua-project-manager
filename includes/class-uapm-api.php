<?php
if (!defined('ABSPATH')) {
    exit;
}

class UAPM_API {

    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    public static function register_routes() {
        register_rest_route('uapm/v1', '/projects/(?P<id>\d+)/stats', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'project_stats'),
            'permission_callback' => array(__CLASS__, 'check_permission'),
        ));

        register_rest_route('uapm/v1', '/analytics/overview', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'overview'),
            'permission_callback' => array(__CLASS__, 'check_permission'),
        ));

        register_rest_route('uapm/v1', '/buttons', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'buttons'),
            'permission_callback' => array(__CLASS__, 'check_permission'),
        ));

        register_rest_route('uapm/v1', '/mailings', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'mailings'),
            'permission_callback' => array(__CLASS__, 'check_permission'),
        ));
    }

    public static function check_permission() {
        return current_user_can('uapm_manage_projects');
    }

    public static function project_stats($request) {
        $project_id = absint($request['id']);

        if (!class_exists('UAPM_Projects') || !UAPM_Projects::get($project_id)) {
            return new WP_Error(
                'uapm_project_not_found',
                'Проєкт не знайдено.',
                array('status' => 404)
            );
        }

        if (!class_exists('UAPM_Analytics')) {
            return new WP_Error(
                'uapm_analytics_unavailable',
                'Модуль аналітики недоступний.',
                array('status' => 503)
            );
        }

        return rest_ensure_response(
            UAPM_Analytics::get_project_statistics($project_id)
        );
    }

    public static function overview() {
        if (!class_exists('UAPM_Analytics')) {
            return new WP_Error(
                'uapm_analytics_unavailable',
                'Модуль аналітики недоступний.',
                array('status' => 503)
            );
        }

        return rest_ensure_response(UAPM_Analytics::get_overview());
    }

    public static function buttons() {
        if (!class_exists('UAPM_Buttons')) {
            return new WP_Error(
                'uapm_buttons_unavailable',
                'Модуль кнопок недоступний.',
                array('status' => 503)
            );
        }

        return rest_ensure_response(array(
            'items' => UAPM_Buttons::get_all(),
        ));
    }

    public static function mailings() {
        if (!class_exists('UAPM_Mailings')) {
            return new WP_Error(
                'uapm_mailings_unavailable',
                'Модуль розсилок недоступний.',
                array('status' => 503)
            );
        }

        return rest_ensure_response(array(
            'items' => UAPM_Mailings::get_all(),
        ));
    }
}