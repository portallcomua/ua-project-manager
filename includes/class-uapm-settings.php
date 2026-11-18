<?php
/**
 * UA Project Manager — Settings.
 *
 * @package UA_Project_Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('UAPM_Settings')) {

    final class UAPM_Settings {

        const OPTION_NAME = 'uapm_settings';

        public static function init() {
            add_action('admin_init', array(__CLASS__, 'register_settings'));
        }

        public static function defaults() {
            return array(
                'language' => 'auto',
                'email_notifications' => 1,
                'admin_email' => get_option('admin_email'),
                'github_username' => '',
                'github_token' => '',
                'github_api_enabled' => 1,
                'automatic_updates' => 1,
                'debug_mode' => 0,
                'activity_log' => 1,
                'retention_days' => 30,
                'projects_per_page' => 20,
                'currency' => 'USD',
                'date_format' => get_option('date_format'),
                'time_format' => get_option('time_format'),
            );
        }

        public static function get_all() {
            $saved = get_option(self::OPTION_NAME, array());

            if (!is_array($saved)) {
                $saved = array();
            }

            return wp_parse_args($saved, self::defaults());
        }

        public static function get($key, $default = null) {
            $settings = self::get_all();

            if (array_key_exists($key, $settings)) {
                return $settings[$key];
            }

            return $default;
        }

        public static function register_settings() {
            register_setting(
                'uapm_settings_group',
                self::OPTION_NAME,
                array(
                    'type' => 'array',
                    'sanitize_callback' => array(__CLASS__, 'sanitize'),
                    'default' => self::defaults(),
                )
            );
        }

        public static function sanitize($input) {
            $defaults = self::defaults();

            if (!is_array($input)) {
                return $defaults;
            }

            $output = array();

            $output['language'] = isset($input['language'])
                ? sanitize_key($input['language'])
                : $defaults['language'];

            if (!in_array($output['language'], array('auto', 'uk', 'en'), true)) {
                $output['language'] = 'auto';
            }

            $output['email_notifications'] = !empty($input['email_notifications']) ? 1 : 0;

            $output['admin_email'] = isset($input['admin_email'])
                ? sanitize_email($input['admin_email'])
                : $defaults['admin_email'];

            if (!is_email($output['admin_email'])) {
                $output['admin_email'] = $defaults['admin_email'];
            }

            $output['github_username'] = isset($input['github_username'])
                ? sanitize_text_field($input['github_username'])
                : '';

            $output['github_token'] = isset($input['github_token'])
                ? sanitize_text_field($input['github_token'])
                : '';

            $output['github_api_enabled'] = !empty($input['github_api_enabled']) ? 1 : 0;
            $output['automatic_updates'] = !empty($input['automatic_updates']) ? 1 : 0;
            $output['debug_mode'] = !empty($input['debug_mode']) ? 1 : 0;
            $output['activity_log'] = !empty($input['activity_log']) ? 1 : 0;

            $output['retention_days'] = isset($input['retention_days'])
                ? absint($input['retention_days'])
                : $defaults['retention_days'];

            $output['retention_days'] = min(
                max($output['retention_days'], 1),
                3650
            );

            $output['projects_per_page'] = isset($input['projects_per_page'])
                ? absint($input['projects_per_page'])
                : $defaults['projects_per_page'];

            $output['projects_per_page'] = min(
                max($output['projects_per_page'], 1),
                100
            );

            $output['currency'] = isset($input['currency'])
                ? sanitize_text_field($input['currency'])
                : $defaults['currency'];

            $output['date_format'] = isset($input['date_format'])
                ? sanitize_text_field($input['date_format'])
                : $defaults['date_format'];

            $output['time_format'] = isset($input['time_format'])
                ? sanitize_text_field($input['time_format'])
                : $defaults['time_format'];

            return $output;
        }

        public static function render() {
            if (!current_user_can('uapm_manage_settings')) {
                wp_die(esc_html__('Access denied.', 'ua-project-manager'));
            }

            $settings = self::get_all();
            ?>
            <div class="uapm-wrap">

                <div class="uapm-header">
                    <div class="uapm-header-content">
                        <h1><?php esc_html_e('Settings', 'ua-project-manager'); ?></h1>
                        <p><?php esc_html_e('Configure UA Project Manager.', 'ua-project-manager'); ?></p>
                    </div>
                </div>

                <form method="post" action="options.php" class="uapm-form">
                    <?php settings_fields('uapm_settings_group'); ?>

                    <div class="uapm-settings-section">
                        <h2><?php esc_html_e('General settings', 'ua-project-manager'); ?></h2>

                        <div class="uapm-settings-row">
                            <div class="uapm-settings-label">
                                <?php esc_html_e('Language', 'ua-project-manager'); ?>
                                <div class="uapm-settings-description">
                                    <?php esc_html_e('Choose plugin language.', 'ua-project-manager'); ?>
                                </div>
                            </div>

                            <div class="uapm-settings-field">
                                <select name="<?php echo esc_attr(self::OPTION_NAME); ?>[language]">
                                    <option value="auto" <?php selected($settings['language'], 'auto'); ?>>
                                        <?php esc_html_e('Automatic', 'ua-project-manager'); ?>
                                    </option>

                                    <option value="uk" <?php selected($settings['language'], 'uk'); ?>>
                                        <?php esc_html_e('Ukrainian', 'ua-project-manager'); ?>
                                    </option>

                                    <option value="en" <?php selected($settings['language'], 'en'); ?>>
                                        <?php esc_html_e('English', 'ua-project-manager'); ?>
                                    </option>
                                </select>
                            </div>
                        </div>

                        <div class="uapm-settings-row">
                            <div class="uapm-settings-label">
                                <?php esc_html_e('Admin email', 'ua-project-manager'); ?>
                                <div class="uapm-settings-description">
                                    <?php esc_html_e('Email address for system notifications.', 'ua-project-manager'); ?>
                                </div>
                            </div>

                            <div class="uapm-settings-field">
                                <input
                                    type="email"
                                    name="<?php echo esc_attr(self::OPTION_NAME); ?>[admin_email]"
                                    value="<?php echo esc_attr($settings['admin_email']); ?>"
                                >
                            </div>
                        </div>

                        <div class="uapm-settings-row">
                            <div class="uapm-settings-label">
                                <?php esc_html_e('Currency', 'ua-project-manager'); ?>
                            </div>

                            <div class="uapm-settings-field">
                                <select name="<?php echo esc_attr(self::OPTION_NAME); ?>[currency]">
                                    <option value="USD" <?php selected($settings['currency'], 'USD'); ?>>USD</option>
                                    <option value="EUR" <?php selected($settings['currency'], 'EUR'); ?>>EUR</option>
                                    <option value="UAH" <?php selected($settings['currency'], 'UAH'); ?>>UAH</option>
                                    <option value="GBP" <?php selected($settings['currency'], 'GBP'); ?>>GBP</option>
                                    <option value="PLN" <?php selected($settings['currency'], 'PLN'); ?>>PLN</option>
                                </select>
                            </div>
                        </div>

                        <div class="uapm-settings-row">
                            <div class="uapm-settings-label">
                                <?php esc_html_e('Projects per page', 'ua-project-manager'); ?>
                            </div>

                            <div class="uapm-settings-field">
                                <input
                                    type="number"
                                    min="1"
                                    max="100"
                                    name="<?php echo esc_attr(self::OPTION_NAME); ?>[projects_per_page]"
                                    value="<?php echo esc_attr($settings['projects_per_page']); ?>"
                                >
                            </div>
                        </div>
                    </div>

                    <div class="uapm-settings-section">
                        <h2><?php esc_html_e('Notifications', 'ua-project-manager'); ?></h2>

                        <div class="uapm-settings-row">
                            <div class="uapm-settings-label">
                                <?php esc_html_e('Email notifications', 'ua-project-manager'); ?>
                            </div>

                            <div class="uapm-settings-field">
                                <label class="uapm-toggle">
                                    <input
                                        type="checkbox"
                                        name="<?php echo esc_attr(self::OPTION_NAME); ?>[email_notifications]"
                                        value="1"
                                        <?php checked($settings['email_notifications'], 1); ?>
                                    >

                                    <span class="uapm-toggle-switch"></span>

                                    <span class="uapm-toggle-label">
                                        <?php esc_html_e('Enable email notifications', 'ua-project-manager'); ?>
                                    </span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="uapm-settings-section">
                        <h2><?php esc_html_e('GitHub integration', 'ua-project-manager'); ?></h2>

                        <div class="uapm-settings-row">
                            <div class="uapm-settings-label">
                                <?php esc_html_e('GitHub username', 'ua-project-manager'); ?>
                            </div>

                            <div class="uapm-settings-field">
                                <input
                                    type="text"
                                    name="<?php echo esc_attr(self::OPTION_NAME); ?>[github_username]"
                                    value="<?php echo esc_attr($settings['github_username']); ?>"
                                >
                            </div>
                        </div>

                        <div class="uapm-settings-row">
                            <div class="uapm-settings-label">
                                <?php esc_html_e('GitHub access token', 'ua-project-manager'); ?>

                                <div class="uapm-settings-description">
                                    <?php esc_html_e('Used for authenticated GitHub API requests.', 'ua-project-manager'); ?>
                                </div>
                            </div>

                            <div class="uapm-settings-field">
                                <div class="uapm-password-wrapper">
                                    <input
                                        type="password"
                                        name="<?php echo esc_attr(self::OPTION_NAME); ?>[github_token]"
                                        value="<?php echo esc_attr($settings['github_token']); ?>"
                                        autocomplete="off"
                                    >

                                    <button
                                        type="button"
                                        class="button uapm-password-toggle"
                                    >
                                        <?php esc_html_e('Show', 'ua-project-manager'); ?>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="uapm-settings-row">
                            <div class="uapm-settings-label">
                                <?php esc_html_e('GitHub API', 'ua-project-manager'); ?>
                            </div>

                            <div class="uapm-settings-field">
                                <label class="uapm-toggle">
                                    <input
                                        type="checkbox"
                                        name="<?php echo esc_attr(self::OPTION_NAME); ?>[github_api_enabled]"
                                        value="1"
                                        <?php checked($settings['github_api_enabled'], 1); ?>
                                    >

                                    <span class="uapm-toggle-switch"></span>

                                    <span class="uapm-toggle-label">
                                        <?php esc_html_e('Enable GitHub API', 'ua-project-manager'); ?>
                                    </span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="uapm-settings-section">
                        <h2><?php esc_html_e('System settings', 'ua-project-manager'); ?></h2>

                        <div class="uapm-settings-row">
                            <div class="uapm-settings-label">
                                <?php esc_html_e('Automatic updates', 'ua-project-manager'); ?>
                            </div>

                            <div class="uapm-settings-field">
                                <label class="uapm-toggle">
                                    <input
                                        type="checkbox"
                                        name="<?php echo esc_attr(self::OPTION_NAME); ?>[automatic_updates]"
                                        value="1"
                                        <?php checked($settings['automatic_updates'], 1); ?>
                                    >

                                    <span class="uapm-toggle-switch"></span>

                                    <span class="uapm-toggle-label">
                                        <?php esc_html_e('Enable automatic updates', 'ua-project-manager'); ?>
                                    </span>
                                </label>
                            </div>
                        </div>

                        <div class="uapm-settings-row">
                            <div class="uapm-settings-label">
                                <?php esc_html_e('Activity log', 'ua-project-manager'); ?>
                            </div>

                            <div class="uapm-settings-field">
                                <label class="uapm-toggle">
                                    <input
                                        type="checkbox"
                                        name="<?php echo esc_attr(self::OPTION_NAME); ?>[activity_log]"
                                        value="1"
                                        <?php checked($settings['activity_log'], 1); ?>
                                    >

                                    <span class="uapm-toggle-switch"></span>

                                    <span class="uapm-toggle-label">
                                        <?php esc_html_e('Enable activity logging', 'ua-project-manager'); ?>
                                    </span>
                                </label>
                            </div>
                        </div>

                        <div class="uapm-settings-row">
                            <div class="uapm-settings-label">
                                <?php esc_html_e('Log retention', 'ua-project-manager'); ?>

                                <div class="uapm-settings-description">
                                    <?php esc_html_e('Number of days to keep logs.', 'ua-project-manager'); ?>
                                </div>
                            </div>

                            <div class="uapm-settings-field">
                                <input
                                    type="number"
                                    min="1"
                                    max="3650"
                                    name="<?php echo esc_attr(self::OPTION_NAME); ?>[retention_days]"
                                    value="<?php echo esc_attr($settings['retention_days']); ?>"
                                >
                            </div>
                        </div>

                        <div class="uapm-settings-row">
                            <div class="uapm-settings-label">
                                <?php esc_html_e('Debug mode', 'ua-project-manager'); ?>
                            </div>

                            <div class="uapm-settings-field">
                                <label class="uapm-toggle">
                                    <input
                                        type="checkbox"
                                        name="<?php echo esc_attr(self::OPTION_NAME); ?>[debug_mode]"
                                        value="1"
                                        <?php checked($settings['debug_mode'], 1); ?>
                                    >

                                    <span class="uapm-toggle-switch"></span>

                                    <span class="uapm-toggle-label">
                                        <?php esc_html_e('Enable debug mode', 'ua-project-manager'); ?>
                                    </span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="uapm-settings-section">
                        <h2><?php esc_html_e('Date and time', 'ua-project-manager'); ?></h2>

                        <div class="uapm-settings-row">
                            <div class="uapm-settings-label">
                                <?php esc_html_e('Date format', 'ua-project-manager'); ?>
                            </div>

                            <div class="uapm-settings-field">
                                <input
                                    type="text"
                                    name="<?php echo esc_attr(self::OPTION_NAME); ?>[date_format]"
                                    value="<?php echo esc_attr($settings['date_format']); ?>"
                                >
                            </div>
                        </div>

                        <div class="uapm-settings-row">
                            <div class="uapm-settings-label">
                                <?php esc_html_e('Time format', 'ua-project-manager'); ?>
                            </div>

                            <div class="uapm-settings-field">
                                <input
                                    type="text"
                                    name="<?php echo esc_attr(self::OPTION_NAME); ?>[time_format]"
                                    value="<?php echo esc_attr($settings['time_format']); ?>"
                                >
                            </div>
                        </div>
                    </div>

                    <?php submit_button(__('Save settings', 'ua-project-manager')); ?>
                </form>

            </div>
            <?php
        }
    }

    UAPM_Settings::init();
}
