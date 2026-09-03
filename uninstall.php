<?php
/**
 * Uninstall routine.
 *
 * Runs when the plugin is deleted from the Plugins screen. WordPress loads this
 * file directly, so nothing from the plugin's own bootstrap is available here.
 *
 * @package WooTemuSync
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Cancel any queued or recurring sync work before its handlers disappear.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'temu-connector' );
}

wp_clear_scheduled_hook( 'wtsync_poll_publish_status' );
wp_clear_scheduled_hook( 'wtsync_first_status_check' );

/*
 * The stored connection, which holds the app credentials and the seller's access
 * token. Deleting the plugin is an explicit act, and leaving a decryptable Temu
 * token in the options table after it would be worse than making the merchant
 * reconnect.
 */
delete_option( 'woo_temu_sync_connection' );
delete_option( 'woo_temu_sync_version' );
delete_option( 'woo_temu_sync_reconcile_offset' );

// Anything this plugin cached rather than stored.
delete_transient( 'wtsync_auth_state' );
delete_transient( 'wtsync_notices' );
delete_transient( 'wtsync_update_manifest' );

/*
 * The _wtsync_* product meta recording each listing's Temu goodsId is deliberately
 * left in place. Deleting it would orphan the products that already exist on Temu,
 * and reinstalling would then publish duplicates against fresh externalGoodsId
 * values that Temu has never seen.
 */
