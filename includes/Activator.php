<?php
/**
 * Activation routine.
 *
 * @package WooTemuSync
 */

namespace WooTemuSync;

defined( 'ABSPATH' ) || exit;

/**
 * Runs once when the plugin is activated.
 */
final class Activator {

	/**
	 * Prepare the site for the plugin.
	 *
	 * Keep this idempotent: WordPress runs it on every activation, including
	 * reactivation after an update.
	 *
	 * @return void
	 */
	public static function activate() {
		// Autoloaded: read on every request to decide whether an upgrade is due.
		add_option( Plugin::VERSION_KEY, WTSYNC_VERSION, '', true );

		/*
		 * The authorisation callback is a plain query var on the front page rather
		 * than a rewrite rule, but permalink setups that cache the rule set still
		 * need a nudge before the front end will route the request.
		 */
		flush_rewrite_rules();

		/**
		 * Fires after the plugin has finished its activation routine.
		 *
		 * @since 0.1.0
		 */
		do_action( 'woo_temu_sync_activated' );
	}
}
