<?php
/**
 * Deactivation routine.
 *
 * @package WooTemuSync
 */

namespace WooTemuSync;

use WooTemuSync\Product\Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Runs once when the plugin is deactivated.
 */
final class Deactivator {

	/**
	 * Stand the plugin down without destroying anything.
	 *
	 * Credentials and the product mapping are deliberately left in place.
	 * Deactivating to debug a theme conflict should not force the merchant to
	 * re-authorise with Temu, and dropping the Temu product IDs would orphan
	 * every listing that already exists on the marketplace.
	 *
	 * @return void
	 */
	public static function deactivate() {
		Scheduler::unschedule();

		flush_rewrite_rules();

		/**
		 * Fires after the plugin has finished its deactivation routine.
		 *
		 * @since 0.1.0
		 */
		do_action( 'woo_temu_sync_deactivated' );
	}
}
