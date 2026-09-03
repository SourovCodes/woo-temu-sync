<?php
/**
 * Plugin Name:          Woo Temu Sync
 * Plugin URI:           https://github.com/SourovCodes/woo-temu-sync
 * Update URI:           https://github.com/SourovCodes/woo-temu-sync
 * Description:          Lists WooCommerce products on Temu and syncs stock, prices and orders through the Temu Open API. No middleman service and no fees on your sales.
 * Version:              0.1.0
 * Requires at least:    7.0
 * Requires PHP:         8.2
 * Requires Plugins:     woocommerce
 * Author:               Sourov Biswas
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          woo-temu-sync
 * Domain Path:          /languages
 * WC requires at least: 11.0
 * WC tested up to:      11.0
 *
 * @package WooTemuSync
 */

namespace WooTemuSync;

defined( 'ABSPATH' ) || exit;

define( 'WTSYNC_VERSION', '0.1.0' );
define( 'WTSYNC_PLUGIN_FILE', __FILE__ );
define( 'WTSYNC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WTSYNC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WTSYNC_MIN_WC_VERSION', '11.0' );

/**
 * Load the Composer autoloader.
 *
 * A distributed build always ships vendor/, but a git checkout may not have run
 * `composer install` yet. Fail loudly in the admin rather than fatally.
 *
 * @return bool True when the autoloader was loaded.
 */
function load_autoloader() {
	$autoloader = WTSYNC_PLUGIN_DIR . 'vendor/autoload.php';

	if ( ! is_readable( $autoloader ) ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\\render_missing_autoloader_notice' );
		return false;
	}

	require_once $autoloader;
	return true;
}

/**
 * Show an admin notice explaining that the Composer dependencies are missing.
 *
 * @return void
 */
function render_missing_autoloader_notice() {
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__(
			'Woo Temu Sync is missing its dependencies. Run "composer install" in the plugin directory.',
			'woo-temu-sync'
		)
	);
}

/**
 * Show an admin notice explaining that WooCommerce is required.
 *
 * @return void
 */
function render_missing_woocommerce_notice() {
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: %s: minimum supported WooCommerce version. */
				__( 'Woo Temu Sync requires WooCommerce %s or newer to be installed and active.', 'woo-temu-sync' ),
				WTSYNC_MIN_WC_VERSION
			)
		)
	);
}

/**
 * Show an admin notice explaining that High-Performance Order Storage is required.
 *
 * @return void
 */
function render_hpos_required_notice() {
	printf(
		'<div class="notice notice-error"><p>%1$s</p><p><a href="%2$s">%3$s</a></p></div>',
		esc_html__(
			'Woo Temu Sync requires High-Performance Order Storage. Synchronisation with Temu stays disabled until it is enabled.',
			'woo-temu-sync'
		),
		esc_url( admin_url( 'admin.php?page=wc-settings&tab=advanced&section=features' ) ),
		esc_html__( 'Enable High-Performance Order Storage', 'woo-temu-sync' )
	);
}

/**
 * Determine whether a supported version of WooCommerce is active.
 *
 * The "Requires Plugins" header covers WordPress 6.5 and newer, but it does not
 * enforce a minimum WooCommerce version, so check that here too.
 *
 * @return bool True when WooCommerce is active and new enough.
 */
function is_woocommerce_supported() {
	if ( ! class_exists( 'WooCommerce' ) || ! defined( 'WC_VERSION' ) ) {
		return false;
	}

	return version_compare( WC_VERSION, WTSYNC_MIN_WC_VERSION, '>=' );
}

/**
 * Determine whether High-Performance Order Storage is the active order store.
 *
 * Temu orders are imported as WooCommerce orders, so every order this plugin
 * creates has to land in the store WooCommerce actually reads. Gating on HPOS
 * here rather than when order import ships keeps the requirement out of an
 * upgrade path, where it would break sites that were already running.
 *
 * @return bool True when HPOS is enabled.
 */
function is_hpos_enabled() {
	if ( ! class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) ) {
		return false;
	}

	return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
}

/**
 * Declare compatibility with WooCommerce feature flags.
 *
 * High-Performance Order Storage must be declared before WooCommerce initialises,
 * otherwise the plugin is listed as incompatible and HPOS stays disabled.
 *
 * @return void
 */
function declare_woocommerce_compatibility() {
	if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		return;
	}

	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WTSYNC_PLUGIN_FILE, true );
	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', WTSYNC_PLUGIN_FILE, true );
}

/**
 * Boot the plugin once all other plugins are loaded.
 *
 * @return void
 */
function bootstrap() {
	if ( ! is_woocommerce_supported() ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\\render_missing_woocommerce_notice' );
		return;
	}

	if ( ! is_hpos_enabled() ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\\render_hpos_required_notice' );
		return;
	}

	Plugin::instance()->init();
}

if ( load_autoloader() ) {
	/*
	 * Registered ahead of the requirement gates, not inside bootstrap(). An update is
	 * often the thing that fixes a plugin sitting inert behind one of those gates, so
	 * a site whose WooCommerce is too old must still be offered the version that
	 * supports it.
	 */
	( new Updates\Updater() )->register();

	add_action( 'before_woocommerce_init', __NAMESPACE__ . '\\declare_woocommerce_compatibility' );
	add_action( 'plugins_loaded', __NAMESPACE__ . '\\bootstrap' );

	register_activation_hook( __FILE__, array( Activator::class, 'activate' ) );
	register_deactivation_hook( __FILE__, array( Deactivator::class, 'deactivate' ) );
}
