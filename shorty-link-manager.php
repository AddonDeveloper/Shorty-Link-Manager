<?php
/**
 * Plugin Name: Shorty Link Manager
 * Description: Find, manage, and shorten external links in WordPress with bulk scanning, safe batch processing, and Shurli.at support.
 * Version: 0.1
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: AddonDeveloper
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: shorty-link-manager
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WPSL_VERSION', '0.1');
define('WPSL_PLUGIN_FILE', __FILE__);
define('WPSL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPSL_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once WPSL_PLUGIN_DIR . 'includes/providers/interface-shortener-provider.php';
require_once WPSL_PLUGIN_DIR . 'includes/providers/class-shurli-provider.php';
require_once WPSL_PLUGIN_DIR . 'includes/class-link-repository.php';
require_once WPSL_PLUGIN_DIR . 'includes/class-link-scanner.php';
require_once WPSL_PLUGIN_DIR . 'includes/class-rate-limiter.php';
require_once WPSL_PLUGIN_DIR . 'includes/class-link-processor.php';
require_once WPSL_PLUGIN_DIR . 'includes/admin/class-settings-page.php';
require_once WPSL_PLUGIN_DIR . 'includes/admin/class-links-page.php';
require_once WPSL_PLUGIN_DIR . 'includes/class-plugin.php';

function wpsl_boot_plugin() {
    $plugin = new WPSL_Plugin();
    $plugin->init();
}
add_action('plugins_loaded', 'wpsl_boot_plugin');

function wpsl_load_textdomain() {
    load_plugin_textdomain('shorty-link-manager', false, dirname(plugin_basename(__FILE__)) . '/languages');
}
add_action('init', 'wpsl_load_textdomain');
