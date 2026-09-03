<?php
/**
 * Confirms self-fulfilled shipments back to Temu.
 *
 * @package WooTemuSync
 */

namespace WooTemuSync\Fulfilment;

use WooTemuSync\Api\ApiException;
use WooTemuSync\Api\Credentials;
use WooTemuSync\Logger;
use WooTemuSync\Order\OrderImporter;

defined( 'ABSPATH' ) || exit;

/**
 * Tells Temu that a seller has shipped an order themselves.
 *
 * This implements **self-fulfilment** only: the seller ships from their own
 * warehouse with their own carrier account and reports the tracking number.
 * Temu's other path, Buy-Shipping, is deliberately out of scope. It is an
 * asynchronous label purchase that has to be polled for a result, and the label
 * download uses a different request signing scheme with its own headers and a
 * ten minute URL expiry. A seller running a local warehouse already has carrier
 * accounts and labels, so self-fulfilment is what this plugin is for.
 *
 * Temu enforces rules that WooCommerce does not, and breaking them produces a
 * rejected shipment rather than a partial one:
 *
 * - A tracking number may be used for one successful fulfilment only.
 * - Every order item under a parent order must be fulfilled together. Partial
 *   fulfilment, and partial fulfilment across parent orders, are both refused.
 * - Each item's full quantity must go at once.
 *
 * So the payload is always built from every line of the order at the quantity
 * Temu recorded, not from whatever the shop edited the line to afterwards.
 */
class ShipmentConfirmer {

	/**
	 * The self-fulfilment confirm method.
	 */
	const METHOD_CONFIRM = 'bg.logistics.shipment.v2.confirm';

	/**
	 * Meta keys written on the WooCommerce order.
	 */
	const META_TRACKING   = '_wtsync_tracking_number';
	const META_CARRIER    = '_wtsync_carrier_id';
	const META_SHIPPED_AT = '_wtsync_shipped_at';
	const META_LAST_ERROR = '_wtsync_fulfilment_error';

	/**
	 * Credential store.
	 *
	 * @var Credentials
	 */
	private $credentials;

	/**
	 * Carrier lookup.
	 *
	 * @var CarrierRepository
	 */
	private $carriers;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Credentials       $credentials Credential store.
	 * @param CarrierRepository $carriers    Carrier lookup.
	 * @param Logger            $logger      Logger.
	 */
	public function __construct( Credentials $credentials, CarrierRepository $carriers, Logger $logger ) {
		$this->credentials = $credentials;
		$this->carriers    = $carriers;
		$this->logger      = $logger;
	}

	/**
	 * Whether an order can still be confirmed.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	public function is_fulfillable( $order ) {
		if ( '' === (string) $order->get_meta( OrderImporter::META_PARENT_SN ) ) {
			return false;
		}

		if ( '' !== (string) $order->get_meta( self::META_SHIPPED_AT ) ) {
			return false;
		}

		return ! empty( $this->order_send_info( $order ) );
	}

	/**
	 * Whether an order has already been reported as shipped.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	public function is_shipped( $order ) {
		return '' !== (string) $order->get_meta( self::META_SHIPPED_AT );
	}

	/**
	 * Build the per-item payload for an order.
	 *
	 * Every line Temu knows about is included at the quantity Temu recorded,
	 * because the platform refuses a partial shipment.
	 *
	 * @param \WC_Order $order Order.
	 * @return array
	 */
	public function order_send_info( $order ) {
		$parent_sn = (string) $order->get_meta( OrderImporter::META_PARENT_SN );
		$items     = array();

		foreach ( $order->get_items() as $item ) {
			$order_sn = (string) $item->get_meta( OrderImporter::META_ORDER_SN );

			// A line the seller added by hand is not part of the Temu order and
			// must not be sent, or the whole confirmation is rejected.
			if ( '' === $order_sn ) {
				continue;
			}

			$quantity = (int) $item->get_meta( OrderImporter::META_QUANTITY );

			$items[] = array(
				'orderSn'       => $order_sn,
				'parentOrderSn' => $parent_sn,
				'goodsId'       => (int) $item->get_meta( OrderImporter::META_GOODS_ID ),
				'skuId'         => (int) $item->get_meta( OrderImporter::META_SKU_ID ),
				'quantity'      => $quantity > 0 ? $quantity : max( 1, (int) $item->get_quantity() ),
			);
		}

		return $items;
	}

	/**
	 * Report an order as shipped.
	 *
	 * @param \WC_Order $order           Order.
	 * @param string    $tracking_number Carrier tracking number.
	 * @param int       $carrier_id      Temu logisticsServiceProviderId.
	 * @return true
	 *
	 * @throws FulfilmentException When the shipment cannot be confirmed.
	 */
	public function confirm( $order, $tracking_number, $carrier_id ) {
		$tracking_number = $this->clean_tracking_number( $tracking_number );
		$carrier_id      = (int) $carrier_id;

		if ( '' === $tracking_number ) {
			throw new FulfilmentException( esc_html__( 'Enter the tracking number before confirming the shipment.', 'woo-temu-sync' ) );
		}

		if ( $carrier_id <= 0 ) {
			throw new FulfilmentException( esc_html__( 'Choose the carrier that Temu should record for this shipment.', 'woo-temu-sync' ) );
		}

		if ( $this->is_shipped( $order ) ) {
			throw new FulfilmentException( esc_html__( 'This order has already been reported to Temu as shipped.', 'woo-temu-sync' ) );
		}

		$items = $this->order_send_info( $order );

		if ( empty( $items ) ) {
			throw new FulfilmentException( esc_html__( 'This order has no Temu line items to confirm.', 'woo-temu-sync' ) );
		}

		$region_id = (int) $order->get_meta( OrderImporter::META_REGION_ID );

		if ( ! $this->carriers->is_valid( $carrier_id, $region_id ) ) {
			throw new FulfilmentException( esc_html__( 'Temu does not accept that carrier for this order\'s market.', 'woo-temu-sync' ) );
		}

		$request = array(
			'trackingNumber'    => $tracking_number,
			'carrierId'         => $carrier_id,
			'orderSendInfoList' => $items,
		);

		$warehouse = $this->warehouse_id( $order );

		if ( '' !== $warehouse ) {
			$request['selfShippingWarehouseId'] = $warehouse;
		}

		try {
			$this->credentials->client()->request(
				self::METHOD_CONFIRM,
				array(
					'sendType'        => $this->send_type(),
					'sendRequestList' => array( $request ),
				)
			);
		} catch ( ApiException $e ) {
			// Temu already has this shipment. Recording it locally is the correct
			// outcome, not an error to show the seller.
			if ( 120012004 === $e->get_error_code() ) {
				$this->record( $order, $tracking_number, $carrier_id );

				return true;
			}

			$message = $this->explain( $e );

			$order->update_meta_data( self::META_LAST_ERROR, $message );
			$order->save();

			$this->logger->error(
				sprintf( 'Shipment confirmation failed for order %d: %s', $order->get_id(), $message ),
				array(
					'code'       => $e->get_error_code(),
					'request_id' => $e->get_request_id(),
				)
			);

			throw new FulfilmentException( esc_html( $message ), (int) $e->get_error_code() );
		}

		$this->record( $order, $tracking_number, $carrier_id );

		return true;
	}

	/**
	 * Record a successful shipment on the order.
	 *
	 * @param \WC_Order $order           Order.
	 * @param string    $tracking_number Tracking number.
	 * @param int       $carrier_id      Carrier id.
	 * @return void
	 */
	protected function record( $order, $tracking_number, $carrier_id ) {
		$order->update_meta_data( self::META_TRACKING, $tracking_number );
		$order->update_meta_data( self::META_CARRIER, $carrier_id );
		$order->update_meta_data( self::META_SHIPPED_AT, time() );
		$order->delete_meta_data( self::META_LAST_ERROR );

		$order->add_order_note(
			sprintf(
				/* translators: %s: tracking number. */
				__( 'Shipment confirmed to Temu with tracking number %s.', 'woo-temu-sync' ),
				$tracking_number
			)
		);

		$order->save();

		$this->logger->info(
			sprintf( 'Shipment confirmed for order %d with tracking %s', $order->get_id(), $tracking_number )
		);

		/**
		 * Fires after Temu accepts a shipment confirmation.
		 *
		 * @since 0.1.0
		 *
		 * @param \WC_Order $order           The order.
		 * @param string    $tracking_number Tracking number sent.
		 * @param int       $carrier_id      Carrier id sent.
		 */
		do_action( 'wtsync_shipment_confirmed', $order, $tracking_number, $carrier_id );
	}

	/**
	 * Strip the characters Temu refuses in a tracking number.
	 *
	 * Error 120014020 rejects a tracking number carrying separators such as
	 * dashes or hashes, which couriers and shop staff both add freely.
	 *
	 * @param string $tracking_number Raw tracking number.
	 * @return string
	 */
	public function clean_tracking_number( $tracking_number ) {
		$tracking_number = trim( (string) $tracking_number );
		$cleaned         = preg_replace( '/[^A-Za-z0-9]/', '', $tracking_number );

		return null === $cleaned ? '' : $cleaned;
	}

	/**
	 * The warehouse a shipment leaves from, when the store uses them.
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	protected function warehouse_id( $order ) {
		/**
		 * Filter the self-shipping warehouse id sent with a confirmation.
		 *
		 * Stores that registered warehouses in Seller Center must name one, and
		 * Temu rejects the shipment otherwise (120011085, 120012063).
		 *
		 * @since 0.1.0
		 *
		 * @param string    $warehouse_id Warehouse id, empty to omit.
		 * @param \WC_Order $order        Order being confirmed.
		 */
		return (string) apply_filters( 'wtsync_self_shipping_warehouse_id', '', $order );
	}

	/**
	 * The sendType Temu expects for a self-fulfilled shipment.
	 *
	 * Temu does not publish this enumeration. Zero is what their own worked
	 * example uses for a payload carrying a tracking number and a carrier, which
	 * is exactly this case, so that is the default rather than a guess.
	 *
	 * @return int
	 */
	protected function send_type() {
		/**
		 * Filter the sendType sent with a shipment confirmation.
		 *
		 * @since 0.1.0
		 *
		 * @param int $send_type Temu sendType.
		 */
		return (int) apply_filters( 'wtsync_shipment_send_type', 0 );
	}

	/**
	 * Turn a Temu rejection into something the seller can act on.
	 *
	 * @param ApiException $e Failure.
	 * @return string
	 */
	protected function explain( ApiException $e ) {
		switch ( $e->get_error_code() ) {
			case 120014001:
				return __( 'Temu received no tracking number.', 'woo-temu-sync' );

			case 120014020:
				return __( 'Temu could not read the tracking number. Remove any dashes, spaces or other symbols and try again.', 'woo-temu-sync' );

			case 120014005:
			case 120014008:
			case 120014023:
				return __( 'Temu does not recognise that tracking number for the chosen carrier. Check both and try again.', 'woo-temu-sync' );

			case 120014022:
				return __( 'The tracking number and carrier do not match. Check that the carrier is the one that actually carried the parcel.', 'woo-temu-sync' );

			case 120014019:
				return __( 'That is a Temu-generated tracking number, which cannot be used to confirm a self-fulfilled shipment.', 'woo-temu-sync' );

			case 120012023:
				return __( 'The buyer has asked to change the delivery address. Handle that in Seller Center before shipping.', 'woo-temu-sync' );

			case 120012030:
				return __( 'The buyer has asked to cancel this order. Answer the cancellation in Seller Center before shipping.', 'woo-temu-sync' );

			case 120011065:
				return __( 'Temu has no shipping address for this order yet.', 'woo-temu-sync' );

			case 120012035:
			case 120012076:
				return __( 'Temu has restricted this store from confirming its own shipments. Temu\'s buy-shipping labels have to be used instead, which this plugin does not do; use Seller Center.', 'woo-temu-sync' );

			case 120011085:
			case 120012063:
			case 120012064:
				return __( 'Temu needs a valid self-shipping warehouse for this order. Set one with the wtsync_self_shipping_warehouse_id filter, or check the warehouse settings in Seller Center.', 'woo-temu-sync' );

			case 120011086:
				return __( 'Fill in the warehouse management type and brand in Temu Seller Center before confirming shipments.', 'woo-temu-sync' );

			case 120011011:
				return __( 'Temu will not ship from that warehouse to this buyer\'s address.', 'woo-temu-sync' );

			case 120015537:
				return __( 'Brazilian orders can only be shipped with Temu\'s own labels.', 'woo-temu-sync' );

			default:
				// A restriction on one named carrier comes back under several codes
				// with the carrier in the message, so pass Temu's own words through.
				return $e->getMessage();
		}
	}
}
