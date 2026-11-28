<?php
/**
 * UA Project Manager — Admin Sections.
 *
 * @package UA_Project_Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('UAPM_Admin_Sections')) {

    final class UAPM_Admin_Sections {

        public static function init() {
            add_action('admin_menu', array(__CLASS__, 'register_sections'), 30);
        }

        public static function register_sections() {
            $parent = 'uapm';

            self::add_page(
                __('Clients', 'ua-project-manager'),
                __('Clients', 'ua-project-manager'),
                'uapm_manage_clients',
                'uapm-clients',
                array(__CLASS__, 'render_clients')
            );

            self::add_page(
                __('Integrations', 'ua-project-manager'),
                __('Integrations', 'ua-project-manager'),
                'uapm_manage_integrations',
                'uapm-integrations',
                array(__CLASS__, 'render_integrations')
            );

            self::add_page(
                __('Downloads', 'ua-project-manager'),
                __('Downloads', 'ua-project-manager'),
                'uapm_manage_downloads',
                'uapm-downloads',
                array(__CLASS__, 'render_downloads')
            );

            self::add_page(
                __('Installations', 'ua-project-manager'),
                __('Installations', 'ua-project-manager'),
                'uapm_manage_installations',
                'uapm-installations',
                array(__CLASS__, 'render_installations')
            );

            self::add_page(
                __('GitHub', 'ua-project-manager'),
                __('GitHub', 'ua-project-manager'),
                'uapm_manage_repository',
                'uapm-github',
                array(__CLASS__, 'render_github')
            );

            self::add_page(
                __('Code Generator', 'ua-project-manager'),
                __('Code Generator', 'ua-project-manager'),
                'uapm_manage_code_generator',
                'uapm-code-generator',
                array(__CLASS__, 'render_code_generator')
            );

            self::add_page(
                __('Code Inspector', 'ua-project-manager'),
                __('Code Inspector', 'ua-project-manager'),
                'uapm_manage_code_inspector',
                'uapm-code-inspector',
                array(__CLASS__, 'render_code_inspector')
            );

            self::add_page(
                __('Analytics', 'ua-project-manager'),
                __('Analytics', 'ua-project-manager'),
                'uapm_manage_analytics',
                'uapm-analytics',
                array(__CLASS__, 'render_analytics')
            );

            self::add_page(
                __('Settings', 'ua-project-manager'),
                __('Settings', 'ua-project-manager'),
                'uapm_manage_settings',
                'uapm-settings',
                array(__CLASS__, 'render_settings')
            );
        }

        private static function add_page($page_title, $menu_title, $capability, $slug, $callback) {
            add_submenu_page(
                'uapm',
                $page_title,
                $menu_title,
                $capability,
                $slug,
                $callback
            );
        }

        private static function header($title, $description = '') {
            ?>
            <div class="wrap uapm-wrap">
                <?php if (class_exists('UAPM_Admin')) { UAPM_Admin::render_breadcrumb('Налаштування та модулі', $title); } ?>
                <div class="uapm-header">
                    <div class="uapm-header-content">
                        <h1><?php echo esc_html($title); ?></h1>

                        <?php if ($description) : ?>
                            <p><?php echo esc_html($description); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php
        }

        private static function footer() {
            ?>
                <div class="uapm-admin-footer">
                    <span>
                        <?php
                        printf(
                            esc_html__('UA Project Manager %s', 'ua-project-manager'),
                            defined('UAPM_VERSION') ? esc_html(UAPM_VERSION) : ''
                        );
                        ?>
                    </span>

                    <span>
                        <a href="https://github.com/portallcomua/ua-project-manager"
                           target="_blank"
                           rel="noopener noreferrer">
                            GitHub
                        </a>
                    </span>
                </div>
            </div>
            <?php
        }

        private static function card($title, $description, $icon = '📦') {
            ?>
            <div class="uapm-card">
                <div class="uapm-flex">
                    <div class="uapm-list-icon">
                        <?php echo esc_html($icon); ?>
                    </div>

                    <div>
                        <h3 class="uapm-card-title">
                            <?php echo esc_html($title); ?>
                        </h3>

                        <p class="uapm-text-muted">
                            <?php echo esc_html($description); ?>
                        </p>
                    </div>
                </div>
            </div>
            <?php
        }

        private static function placeholder($title, $description, $icon = '📦') {
            ?>
            <div class="uapm-panel">
                <div class="uapm-panel-body">
                    <div class="uapm-empty-state">
                        <span class="uapm-empty-state-icon">
                            <?php echo esc_html($icon); ?>
                        </span>

                        <h3><?php echo esc_html($title); ?></h3>

                        <p><?php echo esc_html($description); ?></p>
                    </div>
                </div>
            </div>
            <?php
        }

        public static function render_clients() {
            if (!current_user_can('uapm_manage_clients')) {
                wp_die(esc_html__('Access denied.', 'ua-project-manager'));
            }

            self::header(
                __('Clients', 'ua-project-manager'),
                __('Manage project clients.', 'ua-project-manager')
            );

            if (class_exists('UAPM_Clients')) {
                if (method_exists('UAPM_Clients', 'render')) {
                    UAPM_Clients::render();
                } elseif (method_exists('UAPM_Clients', 'admin_page')) {
                    UAPM_Clients::admin_page();
                } else {
                    self::placeholder(
                        __('Clients module', 'ua-project-manager'),
                        __('The clients module is loaded, but its renderer is not available.', 'ua-project-manager'),
                        '👥'
                    );
                }
            } else {
                self::placeholder(
                    __('Clients module', 'ua-project-manager'),
                    __('The clients module is not available.', 'ua-project-manager'),
                    '👥'
                );
            }

            self::footer();
        }

        public static function render_integrations() {
            if (!current_user_can('uapm_manage_integrations')) {
                wp_die(esc_html__('Access denied.', 'ua-project-manager'));
            }

            self::header(
                __('Integrations', 'ua-project-manager'),
                __('Connect external services.', 'ua-project-manager')
            );

            if (class_exists('UAPM_Integrations')) {
                if (method_exists('UAPM_Integrations', 'render')) {
                    UAPM_Integrations::render();
                } elseif (method_exists('UAPM_Integrations', 'admin_page')) {
                    UAPM_Integrations::admin_page();
                } else {
                    self::placeholder(
                        __('Integrations', 'ua-project-manager'),
                        __('The integrations module is loaded.', 'ua-project-manager'),
                        '🔌'
                    );
                }
            } else {
                self::placeholder(
                    __('Integrations', 'ua-project-manager'),
                    __('The integrations module is not available.', 'ua-project-manager'),
                    '🔌'
                );
            }

            self::footer();
        }

        public static function render_downloads() {
            if (!current_user_can('uapm_manage_downloads')) {
                wp_die(esc_html__('Access denied.', 'ua-project-manager'));
            }

            self::header(
                __('Downloads', 'ua-project-manager'),
                __('Manage plugin and project downloads.', 'ua-project-manager')
            );

            if (class_exists('UAPM_Downloads')) {
                if (method_exists('UAPM_Downloads', 'render')) {
                    UAPM_Downloads::render();
                } elseif (method_exists('UAPM_Downloads', 'admin_page')) {
                    UAPM_Downloads::admin_page();
                } else {
                    self::placeholder(
                        __('Downloads', 'ua-project-manager'),
                        __('The downloads module is loaded.', 'ua-project-manager'),
                        '⬇️'
                    );
                }
            } else {
                self::placeholder(
                    __('Downloads', 'ua-project-manager'),
                    __('The downloads module is not available.', 'ua-project-manager'),
                    '⬇️'
                );
            }

            self::footer();
        }

        public static function render_installations() {
            if (!current_user_can('uapm_manage_installations')) {
                wp_die(esc_html__('Access denied.', 'ua-project-manager'));
            }

            self::header(
                __('Installations', 'ua-project-manager'),
                __('Manage connected installations.', 'ua-project-manager')
            );

            if (class_exists('UAPM_Installations')) {
                if (method_exists('UAPM_Installations', 'render')) {
                    UAPM_Installations::render();
                } elseif (method_exists('UAPM_Installations', 'admin_page')) {
                    UAPM_Installations::admin_page();
                } else {
                    self::placeholder(
                        __('Installations', 'ua-project-manager'),
                        __('The installations module is loaded.', 'ua-project-manager'),
                        '🖥️'
                    );
                }
            } else {
                self::placeholder(
                    __('Installations', 'ua-project-manager'),
                    __('The installations module is not available.', 'ua-project-manager'),
                    '🖥️'
                );
            }

            self::footer();
        }

        public static function render_github() {
            if (!current_user_can('uapm_manage_repository')) {
                wp_die(esc_html__('Access denied.', 'ua-project-manager'));
            }

            self::header(
                __('GitHub', 'ua-project-manager'),
                __('Manage GitHub repositories and connections.', 'ua-project-manager')
            );

            if (class_exists('UAPM_Repository')) {
                if (method_exists('UAPM_Repository', 'render')) {
                    UAPM_Repository::render();
                } elseif (method_exists('UAPM_Repository', 'admin_page')) {
                    UAPM_Repository::admin_page();
                } else {
                    self::placeholder(
                        __('GitHub repository', 'ua-project-manager'),
                        __('The repository module is loaded.', 'ua-project-manager'),
                        '🐙'
                    );
                }
            } else {
                self::placeholder(
                    __('GitHub repository', 'ua-project-manager'),
                    __('The repository module is not available.', 'ua-project-manager'),
                    '🐙'
                );
            }

            self::footer();
        }

        public static function render_code_generator() {
            if (!current_user_can('uapm_manage_code_generator')) {
                wp_die(esc_html__('Access denied.', 'ua-project-manager'));
            }

            self::header(
                __('Code Generator', 'ua-project-manager'),
                __('Generate code and project files.', 'ua-project-manager')
            );

            if (class_exists('UAPM_Code_Generator')) {
                if (method_exists('UAPM_Code_Generator', 'render')) {
                    UAPM_Code_Generator::render();
                } elseif (method_exists('UAPM_Code_Generator', 'admin_page')) {
                    UAPM_Code_Generator::admin_page();
                } else {
                    self::placeholder(
                        __('Code Generator', 'ua-project-manager'),
                        __('The code generator module is loaded.', 'ua-project-manager'),
                        '⚙️'
                    );
                }
            } else {
                self::placeholder(
                    __('Code Generator', 'ua-project-manager'),
                    __('The code generator module is not available.', 'ua-project-manager'),
                    '⚙️'
                );
            }

            self::footer();
        }

        public static function render_code_inspector() {
            if (!current_user_can('uapm_manage_code_inspector')) {
                wp_die(esc_html__('Access denied.', 'ua-project-manager'));
            }

            self::header(
                __('Code Inspector', 'ua-project-manager'),
                __('Inspect and analyze code.', 'ua-project-manager')
            );

            if (class_exists('UAPM_Code_Inspector')) {
                if (method_exists('UAPM_Code_Inspector', 'render')) {
                    UAPM_Code_Inspector::render();
                } elseif (method_exists('UAPM_Code_Inspector', 'admin_page')) {
                    UAPM_Code_Inspector::admin_page();
                } else {
                    self::placeholder(
                        __('Code Inspector', 'ua-project-manager'),
                        __('The code inspector module is loaded.', 'ua-project-manager'),
                        '🔍'
                    );
                }
            } else {
                self::placeholder(
                    __('Code Inspector', 'ua-project-manager'),
                    __('The code inspector module is not available.', 'ua-project-manager'),
                    '🔍'
                );
            }

            self::footer();
        }

        public static function render_analytics() {
            if (!current_user_can('uapm_manage_analytics')) {
                wp_die(esc_html__('Access denied.', 'ua-project-manager'));
            }

            self::header(
                __('Analytics', 'ua-project-manager'),
                __('Statistics and project analytics.', 'ua-project-manager')
            );

            if (class_exists('UAPM_Analytics')) {
                if (method_exists('UAPM_Analytics', 'render')) {
                    UAPM_Analytics::render();
                } elseif (method_exists('UAPM_Analytics', 'admin_page')) {
                    UAPM_Analytics::admin_page();
                } else {
                    self::placeholder(
                        __('Analytics', 'ua-project-manager'),
                        __('The analytics module is loaded.', 'ua-project-manager'),
                        '📊'
                    );
                }
            } else {
                self::placeholder(
                    __('Analytics', 'ua-project-manager'),
                    __('The analytics module is not available.', 'ua-project-manager'),
                    '📊'
                );
            }

            self::footer();
        }

        public static function render_settings() {
            if (!current_user_can('uapm_manage_settings')) {
                wp_die(esc_html__('Access denied.', 'ua-project-manager'));
            }

            self::header(
                __('Settings', 'ua-project-manager'),
                __('Configure UA Project Manager.', 'ua-project-manager')
            );

            if (class_exists('UAPM_Settings')) {
                if (method_exists('UAPM_Settings', 'render')) {
                    UAPM_Settings::render();
                } elseif (method_exists('UAPM_Settings', 'admin_page')) {
                    UAPM_Settings::admin_page();
                } else {
                    self::placeholder(
                        __('Settings', 'ua-project-manager'),
                        __('The settings module is loaded.', 'ua-project-manager'),
                        '⚙️'
                    );
                }
            } else {
                self::placeholder(
                    __('Settings', 'ua-project-manager'),
                    __('The settings module is not available.', 'ua-project-manager'),
                    '⚙️'
                );
            }

            self::footer();
        }
    }

    UAPM_Admin_Sections::init();
}
