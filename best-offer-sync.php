<?php
/**
 * Plugin Name: Best Offer WP Sync
 * Plugin URI: https://enviweb.gr
 * Description: WP-CLI command to sync WooCommerce products from Best Offer XML feed. Updates supplier prices and stock levels.
 * Version: 1.1.0
 * Author: EnviWeb
 * Author URI: https://enviweb.gr
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: best-offer-sync
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 8.5
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Currently plugin version.
 */
define( 'ENVIWEB_BESTOFFER_VERSION', '1.1.0' );
define( 'ENVIWEB_BESTOFFER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ENVIWEB_BESTOFFER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Check if WooCommerce is active
 */
function enviweb_bestoffer_check_woocommerce() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'enviweb_bestoffer_woocommerce_missing_notice' );
		return false;
	}
	return true;
}

/**
 * Admin notice if WooCommerce is not active
 */
function enviweb_bestoffer_woocommerce_missing_notice() {
	?>
	<div class="notice notice-error">
		<p><?php esc_html_e( 'Best Offer WP Sync requires WooCommerce to be installed and active.', 'best-offer-sync' ); ?></p>
	</div>
	<?php
}

/**
 * Plugin activation hook
 */
function enviweb_bestoffer_activate() {
	require_once ENVIWEB_BESTOFFER_PLUGIN_DIR . 'includes/class-bestoffer-database.php';
	EnviWeb_BestOffer_Database::create_tables();
	EnviWeb_BestOffer_Database::upgrade_tables();
}
register_activation_hook( __FILE__, 'enviweb_bestoffer_activate' );

/**
 * Initialize the plugin
 */
function enviweb_bestoffer_init() {
	// Check WooCommerce dependency
	if ( ! enviweb_bestoffer_check_woocommerce() ) {
		return;
	}

	// Load required files
	require_once ENVIWEB_BESTOFFER_PLUGIN_DIR . 'includes/class-bestoffer-database.php';
	require_once ENVIWEB_BESTOFFER_PLUGIN_DIR . 'includes/class-bestoffer-logger.php';
	require_once ENVIWEB_BESTOFFER_PLUGIN_DIR . 'includes/class-bestoffer-admin.php';
	require_once ENVIWEB_BESTOFFER_PLUGIN_DIR . 'includes/class-bestoffer-metabox.php';

	// Check and upgrade database if needed
	EnviWeb_BestOffer_Database::upgrade_tables();

	// Initialize admin interface
	if ( is_admin() ) {
		new EnviWeb_BestOffer_Admin();
		new EnviWeb_BestOffer_Metabox();
	}

	// Load WP-CLI command if in CLI context
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		require_once ENVIWEB_BESTOFFER_PLUGIN_DIR . 'includes/class-bestoffer-cli-command.php';
		WP_CLI::add_command( 'bestoffer', 'EnviWeb_BestOffer_CLI_Command' );
	}
}
add_action( 'plugins_loaded', 'enviweb_bestoffer_init' );

