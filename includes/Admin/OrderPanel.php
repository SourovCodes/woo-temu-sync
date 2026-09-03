<?php
/**
 * Temu fulfilment panel on the order screen.
 *
 * @package WooTemuSync
 */

namespace WooTemuSync\Admin;

use WooTemuSync\Fulfilment\CarrierRepository;
use WooTemuSync\Fulfilment\FulfilmentException;
use WooTemuSync\Fulfilment\ShipmentConfirmer;
use WooTemuSync\Order\OrderImporter;

defined( 'ABSPATH' ) || exit;

/**
 * Lets a seller report a shipment to Temu from the order they are looking at.
 *
 * Fulfilment belongs on the order screen rather than behind a bulk tool: the
 * tracking number arrives when the parcel is packed, and Temu will not accept a
 * partial shipment, so this is a per-order act by design.
 */
class OrderPanel {

	/**
	 * Nonce action.
	 */
	const NONCE = 'wtsync_fulfil';

	/**
	 * Carrier lookup.
	 *
	 * @var CarrierRepository
	 */
	private $carriers;

	/**
	 * Shipment confirmer.
	 *
	 * @var ShipmentConfirmer
	 */
	private $confirmer;

	/**
	 * Constructor.
	 *
	 * @param CarrierRepository $carriers  Carrier lookup.
	 * @param ShipmentConfirmer $confirmer Shipment confirmer.
	 */
	public function __construct( CarrierRepository $carriers, ShipmentConfirmer $confirmer ) {
		$this->carriers  = $carriers;
		$this->confirmer = $confirmer;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'save' ), 20 );
	}

	/**
	 * Add the panel to the order screen.
	 *
	 * @return void
	 */
	public function add_meta_box() {
		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';

		add_meta_box(
			'wtsync-fulfilment',
			__( 'Temu fulfilment', 'woo-temu-sync' ),
			array( $this, 'render' ),
			$screen,
			'side',
			'default'
		);
	}

	/**
	 * Render the panel.
	 *
	 * @param mixed $post_or_order The post or order the screen is showing.
	 * @return void
	 */
	public function render( $post_or_order ) {
		$order = $post_or_order instanceof \WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );

		if ( ! $order ) {
			return;
		}

		$parent_sn = (string) $order->get_meta( OrderImporter::META_PARENT_SN );

		if ( '' === $parent_sn ) {
			echo '<p>' . esc_html__( 'This order did not come from Temu.', 'woo-temu-sync' ) . '</p>';
			return;
		}

		printf(
			'<p><strong>%s</strong><br /><code>%s</code></p>',
			esc_html__( 'Temu order', 'woo-temu-sync' ),
			esc_html( $parent_sn )
		);

		if ( $this->confirmer->is_shipped( $order ) ) {
			$this->render_shipped( $order );
			return;
		}

		$error = (string) $order->get_meta( ShipmentConfirmer::META_LAST_ERROR );

		if ( '' !== $error ) {
			printf(
				'<div class="notice notice-error inline"><p>%s</p></div>',
				esc_html( $error )
			);
		}

		$this->render_form( $order );
	}

	/**
	 * Render the state of an order already reported to Temu.
	 *
	 * @param \WC_Order $order Order.
	 * @return void
	 */
	protected function render_shipped( $order ) {
		$carriers   = $this->carriers->for_region( (int) $order->get_meta( OrderImporter::META_REGION_ID ) );
		$carrier_id = (int) $order->get_meta( ShipmentConfirmer::META_CARRIER );

		printf(
			'<p><span style="color:#008a20;font-weight:600;">%s</span></p>',
			esc_html__( 'Reported to Temu as shipped', 'woo-temu-sync' )
		);

		printf(
			'<p><strong>%s</strong><br /><code>%s</code></p>',
			esc_html__( 'Tracking number', 'woo-temu-sync' ),
			esc_html( (string) $order->get_meta( ShipmentConfirmer::META_TRACKING ) )
		);

		if ( isset( $carriers[ $carrier_id ] ) ) {
			printf(
				'<p><strong>%s</strong><br />%s</p>',
				esc_html__( 'Carrier', 'woo-temu-sync' ),
				esc_html( $carriers[ $carrier_id ] )
			);
		}

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Temu accepts one tracking number per shipment, so this cannot be sent again from here.', 'woo-temu-sync' )
		);
	}

	/**
	 * Render the confirmation form.
	 *
	 * @param \WC_Order $order Order.
	 * @return void
	 */
	protected function render_form( $order ) {
		$carriers = $this->carriers->for_region( (int) $order->get_meta( OrderImporter::META_REGION_ID ) );

		wp_nonce_field( self::NONCE, 'wtsync_fulfil_nonce' );

		echo '<p><label for="wtsync-carrier"><strong>' . esc_html__( 'Carrier', 'woo-temu-sync' ) . '</strong></label>';

		if ( empty( $carriers ) ) {
			echo '<br /><em>' . esc_html__( 'Temu\'s carrier list could not be loaded. Enter the carrier id by hand if you know it.', 'woo-temu-sync' ) . '</em>';
			echo '<input type="number" class="widefat" id="wtsync-carrier" name="wtsync_carrier_id" value="" min="1" /></p>';
		} else {
			echo '<select class="widefat" id="wtsync-carrier" name="wtsync_carrier_id">';
			echo '<option value="">' . esc_html__( 'Select a carrier', 'woo-temu-sync' ) . '</option>';

			foreach ( $carriers as $id => $name ) {
				printf( '<option value="%d">%s</option>', (int) $id, esc_html( $name ) );
			}

			echo '</select></p>';
		}

		printf(
			'<p><label for="wtsync-tracking"><strong>%s</strong></label>'
				. '<input type="text" class="widefat" id="wtsync-tracking" name="wtsync_tracking_number" value="" autocomplete="off" /></p>',
			esc_html__( 'Tracking number', 'woo-temu-sync' )
		);

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Temu requires the whole order to ship at once and accepts a tracking number only once. Saving the order sends this to Temu.', 'woo-temu-sync' )
		);
	}

	/**
	 * Confirm the shipment when the order is saved.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public function save( $order_id ) {
		if ( ! isset( $_POST['wtsync_fulfil_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['wtsync_fulfil_nonce'] ) ), self::NONCE ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_shop_order', $order_id ) ) {
			return;
		}

		$tracking = isset( $_POST['wtsync_tracking_number'] )
			? sanitize_text_field( wp_unslash( $_POST['wtsync_tracking_number'] ) )
			: '';

		// An empty field is the normal case: the seller saved the order for some
		// other reason and is not shipping it yet.
		if ( '' === trim( $tracking ) ) {
			return;
		}

		$carrier_id = isset( $_POST['wtsync_carrier_id'] ) ? absint( wp_unslash( $_POST['wtsync_carrier_id'] ) ) : 0;

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		try {
			$this->confirmer->confirm( $order, $tracking, $carrier_id );
		} catch ( FulfilmentException $e ) {
			// The message is already on the order, and the panel renders it on the
			// next page load. Nothing further to do here.
			$order->update_meta_data( ShipmentConfirmer::META_LAST_ERROR, $e->getMessage() );
			$order->save();
		}
	}
}
