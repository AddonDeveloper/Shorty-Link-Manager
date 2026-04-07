<?php

if (!defined('ABSPATH')) {
    exit;
}

class WPSL_Settings_Page {
    public function init() {
        add_action('admin_menu', array($this, 'add_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_post_wpsl_test_connection', array($this, 'handle_test_connection'));
    }

    public function add_menu() {
        add_menu_page(
            __('Shorty Link Manager', 'shorty-link-manager'),
            __('Shorty Link Manager', 'shorty-link-manager'),
            'manage_options',
            'shorty-link-manager',
            array($this, 'render_page'),
            'dashicons-admin-links'
        );
    }

    public function register_settings() {
        register_setting('wpsl_settings_group', 'wpsl_settings', array($this, 'sanitize_settings'));

        add_settings_section(
            'wpsl_main_section',
            __('Short URL Settings', 'shorty-link-manager'),
            '__return_false',
            'shorty-link-manager'
        );

        add_settings_field('provider', __('Service', 'shorty-link-manager'), array($this, 'render_provider_field'), 'shorty-link-manager', 'wpsl_main_section');
        add_settings_field('api_key', __('API Key', 'shorty-link-manager'), array($this, 'render_api_key_field'), 'shorty-link-manager', 'wpsl_main_section');
        add_settings_field('mode', __('Mode', 'shorty-link-manager'), array($this, 'render_mode_field'), 'shorty-link-manager', 'wpsl_main_section');
    }

    public function sanitize_settings($input) {
        $output = array();
        $output['provider'] = isset($input['provider']) ? sanitize_text_field($input['provider']) : 'shurli';
        $output['api_key'] = isset($input['api_key']) ? sanitize_text_field($input['api_key']) : '';
        $output['mode'] = isset($input['mode']) && in_array($input['mode'], array('automatic', 'manual'), true) ? $input['mode'] : 'manual';
        return $output;
    }

    public function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = get_option('wpsl_settings', array());
        $provider = wpsl_get_active_provider();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading sanitized admin notice query args for display only.
        $wpsl_notice = isset($_GET['wpsl_notice']) ? sanitize_key(wp_unslash($_GET['wpsl_notice'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading sanitized admin notice query args for display only.
        $message = isset($_GET['message']) ? sanitize_text_field(wp_unslash($_GET['message'])) : '';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Shorty Link Manager → Settings', 'shorty-link-manager'); ?></h1>
            <p><?php esc_html_e('Configure your short URL service and choose how outgoing links should be shortened.', 'shorty-link-manager'); ?></p>

            <?php if ($wpsl_notice) : ?>
                <div class="notice notice-<?php echo esc_attr('success' === $wpsl_notice ? 'success' : 'error'); ?> is-dismissible">
                    <p><?php echo esc_html($message); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php
                settings_fields('wpsl_settings_group');
                do_settings_sections('shorty-link-manager');
                submit_button();
                ?>
            </form>

            <hr />
            <h2><?php esc_html_e('Service Info', 'shorty-link-manager'); ?></h2>
            <?php if ($provider) : ?>
                <p>
                    <strong><?php esc_html_e('Active service:', 'shorty-link-manager'); ?></strong>
                    <?php echo esc_html($provider->get_name()); ?>
                </p>
                <p>
                    <a class="button button-secondary" href="<?php echo esc_url($provider->get_registration_url()); ?>" target="_blank" rel="noopener noreferrer">
                        <?php esc_html_e('Open registration / API documentation', 'shorty-link-manager'); ?>
                    </a>
                </p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('wpsl_test_connection'); ?>
                    <input type="hidden" name="action" value="wpsl_test_connection" />
                    <?php submit_button(__('Test API connection', 'shorty-link-manager'), 'secondary', 'submit', false); ?>
                </form>
            <?php else : ?>
                <p><?php esc_html_e('No provider is configured yet.', 'shorty-link-manager'); ?></p>
            <?php endif; ?>

            <hr />
            <h2><?php esc_html_e('How it works', 'shorty-link-manager'); ?></h2>
            <ul style="list-style: disc; margin-left: 20px;">
                <li><?php esc_html_e('Automatic mode shortens external links when a post or page is saved.', 'shorty-link-manager'); ?></li>
                <li><?php esc_html_e('Manual mode leaves links unchanged on save, so you can review and process them later from the Links page.', 'shorty-link-manager'); ?></li>
                <li><?php esc_html_e('Only external links are shortened. Internal links are ignored.', 'shorty-link-manager'); ?></li>
            </ul>
        </div>
        <?php
    }

    public function render_provider_field() {
        $options = get_option('wpsl_settings', array());
        $provider = isset($options['provider']) ? $options['provider'] : 'shurli';
        ?>
        <select name="wpsl_settings[provider]">
            <option value="shurli" <?php selected($provider, 'shurli'); ?>>Shurli.at</option>
        </select>
        <?php
    }

    public function render_api_key_field() {
        $options = get_option('wpsl_settings', array());
        ?>
        <input type="password" class="regular-text" name="wpsl_settings[api_key]" value="<?php echo esc_attr(isset($options['api_key']) ? $options['api_key'] : ''); ?>" autocomplete="off" />
        <p class="description"><?php esc_html_e('Enter the API key of the selected short URL service.', 'shorty-link-manager'); ?></p>
        <?php
    }

    public function render_mode_field() {
        $options = get_option('wpsl_settings', array());
        $mode = isset($options['mode']) ? $options['mode'] : 'manual';
        ?>
        <fieldset>
            <label>
                <input type="radio" name="wpsl_settings[mode]" value="automatic" <?php checked($mode, 'automatic'); ?> />
                <?php esc_html_e('Automatic: shorten all outgoing links on save', 'shorty-link-manager'); ?>
            </label>
            <br>
            <label>
                <input type="radio" name="wpsl_settings[mode]" value="manual" <?php checked($mode, 'manual'); ?> />
                <?php esc_html_e('Manual: do not shorten links automatically on save', 'shorty-link-manager'); ?>
            </label>
        </fieldset>
        <?php
    }

    public function handle_test_connection() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'shorty-link-manager'));
        }

        check_admin_referer('wpsl_test_connection');

        $provider = wpsl_get_active_provider();
        $redirect_url = admin_url('admin.php?page=shorty-link-manager');

        if (!$provider) {
            wp_safe_redirect(add_query_arg(array(
                'wpsl_notice' => 'error',
                'message' => rawurlencode(__('No provider configured.', 'shorty-link-manager')),
            ), $redirect_url));
            exit;
        }

        $result = $provider->test_connection();
        if (is_wp_error($result)) {
            wp_safe_redirect(add_query_arg(array(
                'wpsl_notice' => 'error',
                'message' => rawurlencode($result->get_error_message()),
            ), $redirect_url));
            exit;
        }

        wp_safe_redirect(add_query_arg(array(
            'wpsl_notice' => 'success',
            'message' => rawurlencode(__('API connection successful.', 'shorty-link-manager')),
        ), $redirect_url));
        exit;
    }
}
