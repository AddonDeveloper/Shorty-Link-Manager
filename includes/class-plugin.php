<?php

if (!defined('ABSPATH')) {
    exit;
}

class WPSL_Plugin {
    private $settings_page;
    private $links_page;
    private $link_processor;

    public function __construct() {
        $repository = new WPSL_Link_Repository();
        $scanner = new WPSL_Link_Scanner($repository);
        $rate_limiter = new WPSL_Rate_Limiter();

        $this->settings_page = new WPSL_Settings_Page();
        $this->link_processor = new WPSL_Link_Processor($repository, $scanner, $rate_limiter);
        $this->links_page = new WPSL_Links_Page($repository, $scanner, $this->link_processor);
    }

    public function init() {
        $this->settings_page->init();
        $this->links_page->init();

        add_action('save_post', array($this->link_processor, 'maybe_process_post'), 20, 3);
        add_filter('plugin_action_links_' . plugin_basename(WPSL_PLUGIN_FILE), array($this, 'add_plugin_links'));
    }


    public function add_plugin_links($links) {
        $settings_link = '<a href="' . esc_url(admin_url('admin.php?page=shorty-link-manager')) . '">' . esc_html__('Settings', 'shorty-link-manager') . '</a>';
        $links_link = '<a href="' . esc_url(admin_url('admin.php?page=shorty-link-manager-links')) . '">' . esc_html__('Links', 'shorty-link-manager') . '</a>';
        array_unshift($links, $settings_link, $links_link);
        return $links;
    }
}

function wpsl_get_active_provider() {
    $options = get_option('wpsl_settings', array());
    $provider_id = isset($options['provider']) ? $options['provider'] : 'shurli';
    $api_key = isset($options['api_key']) ? $options['api_key'] : '';

    switch ($provider_id) {
        case 'shurli':
            return new WPSL_Shurli_Provider($api_key);
        default:
            return null;
    }
}
