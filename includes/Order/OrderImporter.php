<?php
/**
 * Creates WooCommerce orders from Temu orders.
 *
 * @package WooTemuSync
 */

namespace WooTemuSync\Order;

use WooTemuSync\Api\ApiException;
use WooTemuSync\Api\Credentials;
use WooTemuSync\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Turns one Temu parent order into one WooCommerce order.
 *
 * Temu's model is two tiers: a parent order (parentOrderSn) is the transaction,
 * and each order item under it is one Temu SKU. That maps cleanly onto a
 * WooCommerce order and its line items.
 *
 * Three of the decisions here are deliberate and worth not undoing:
 *
 * - **Pending orders are not imported by default.** Temu's own guide says a
 *   consumer can still change the address and the quantity while an order is
 *   Pending, and to begin processing only once it reaches Unshipped. Importing
 *   earlier invites shipping to an address the buyer has since changed.
 * - **An order is never dropped because a product could not be matched.** An
 *   unmatched line becomes a plain line item carrying Temu's own product name.
 *   A missing order is invisible; a slightly untidy one is not.
 * - **A WooCommerce status is only ever set from a Temu code that has been
 *   explicitly mapped.** Temu publishes no enumeration for parentOrderStatus,
 *   and guessing it could mark an unshipped order complete.
 */
class OrderImporter {

	/**
	 * Shipping address, masked.
	 */
	const METHOD_SHIPPING = 'bg.order.shippinginfo.v2.get';

	/**
	 * Shipping address, real.
	 */
	const METHOD_SHIPPING_DECRYPTED = 'bg.order.decryptshippinginfo.get';

	/**
	 * Order money.
	 */
	const METHOD_AMOUNT = 'bg.order.amount.query';

	/**
	 * Meta keys written on the WooCommerce order.
	 */
	const META_PARENT_SN   = '_wtsync_parent_order_sn';
	const META_REGION_ID   = '_wtsync_region_id';
	const META_RAW_STATUS  = '_wtsync_temu_status';
	const META_UPDATED_AT  = '_wtsync_order_updated_at';
	const META_AMOUNTS     = '_wtsync_temu_amounts';
	const META_ADDRESS_SET = '_wtsync_address_resolved';

	/**
	 * Meta keys holding Temu's own identifiers on a line item.
	 *
	 * All three are needed to confirm a shipment later:
	 * bg.logistics.shipment.v2.confirm addresses each item by orderSn, goodsId
	 * and skuId together, none of which can be recovered from WooCommerce.
	 */
	const META_ORDER_SN = '_wtsync_order_sn';
	const META_GOODS_ID = '_wtsync_line_goods_id';
	const META_SKU_ID   = '_wtsync_line_sku_id';
	const META_QUANTITY = '_wtsync_line_quantity';

	/**
	 * Credential store.
	 *
	 * @var Credentials
	 */
	private $credentials;

	/**
	 * Address mapper.
	 *
	 * @var AddressMapper
	 */
	private $addresses;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Credentials   $credentials Credential store.
	 * @param AddressMapper $addresses   Address mapper.
	 * @param Logger        $logger      Logger.
	 */
	public function __construct( Credentials $credentials, AddressMapper $addresses, Logger $logger ) {
		$this->credentials = $credentials;
		$this->addresses   = $addresses;
		$this->logger      = $logger;
	}

	/**
	 * Find the WooCommerce order already imported for a Temu parent order.
	 *
	 * @param string $parent_sn Temu parentOrderSn.
	 * @return \WC_Order|null
	 */
	public function find( $parent_sn ) {
		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'status'     => 'any',
				'meta_key'   => self::META_PARENT_SN, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Indexed lookup on the identifier this import is keyed by.
				'meta_value' => $parent_sn,           // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- As above.
			)
		);

		return empty( $orders ) ? null : $orders[0];
	}

	/**
	 * Import or update one parent order.
	 *
	 * @param array $item One entry from bg.order.list.v2.get pageItems.
	 * @return \WC_Order|null The order, or null when it was skipped.
	 */
	public function import( array $item ) {
		$parent_order = isset( $item['parentOrderMap'] ) && is_array( $item['parentOrderMap'] )
			? $item['parentOrderMap']
			: array();

		$parent_sn = isset( $parent_order['parentOrderSn'] ) ? (string) $parent_order['parentOrderSn'] : '';

		if ( '' === $parent_sn ) {
			return null;
		}

		$lines = isset( $item['orderList'] ) && is_array( $item['orderList'] ) ? $item['orderList'] : array();

		if ( ! $this->should_import( $parent_order, $lines ) ) {
			return null;
		}

		$order      = $this->find( $parent_sn );
		$is_new     = null === $order;
		$updated_at = isset( $parent_order['updateTime'] ) ? (int) $parent_order['updateTime'] : 0;

		if ( ! $is_new && $updated_at > 0 && (int) $order->get_meta( self::META_UPDATED_AT ) >= $updated_at ) {
			// Nothing has changed on Temu's side since the last import.
			return $order;
		}

		if ( $is_new ) {
			$order = $this->create_order( $parent_sn, $parent_order, $lines );

			if ( ! $order ) {
				return null;
			}
		}

		$order->update_meta_data( self::META_RAW_STATUS, isset( $parent_order['parentOrderStatus'] ) ? (int) $parent_order['parentOrderStatus'] : 0 );
		$order->update_meta_data( self::META_UPDATED_AT, $updated_at );

		$this->apply_addresses( $order, $parent_order );
		$this->apply_amounts( $order, $parent_sn );
		$this->apply_status( $order, $parent_order, $lines, $is_new );

		$order->save();

		if ( $is_new ) {
			$this->logger->info( sprintf( 'Imported Temu order %s as WooCommerce order %d', $parent_sn, $order->get_id() ) );

			/**
			 * Fires after a Temu order is imported for the first time.
			 *
			 * @since 0.1.0
			 *
			 * @param \WC_Order $order  The new WooCommerce order.
			 * @param array     $item   The raw Temu payload.
			 */
			do_action( 'wtsync_order_imported', $order, $item );
		}

		return $order;
	}

	/**
	 * Whether this order should be imported at all.
	 *
	 * @param array $parent_order Parent order payload.
	 * @param array $lines  Order item payloads.
	 * @return bool
	 */
	protected function should_import( array $parent_order, array $lines ) {
		/**
		 * Filter whether a Temu order is imported.
		 *
		 * Return false to skip an order entirely, for example to leave a market
		 * or a fulfilment type to be handled elsewhere.
		 *
		 * @since 0.1.0
		 *
		 * @param bool  $import Whether to import.
		 * @param array $parent_order Parent order payload.
		 * @param array $lines  Order item payloads.
		 */
		return (bool) apply_filters( 'wtsync_should_import_order', true, $parent_order, $lines );
	}

	/**
	 * Create the WooCommerce order and its line items.
	 *
	 * @param string $parent_sn Temu parentOrderSn.
	 * @param array  $parent_order    Parent order payload.
	 * @param array  $lines     Order item payloads.
	 * @return \WC_Order|null
	 */
	protected function create_order( $parent_sn, array $parent_order, array $lines ) {
		$order = wc_create_order(
			array(
				'created_via' => 'temu',
				'status'      => 'pending',
			)
		);

		if ( is_wp_error( $order ) ) {
			$this->logger->error( sprintf( 'Could not create a WooCommerce order for Temu order %s: %s', $parent_sn, $order->get_error_message() ) );

			return null;
		}

		$order->update_meta_data( self::META_PARENT_SN, $parent_sn );
		$order->update_meta_data( self::META_REGION_ID, isset( $parent_order['regionId'] ) ? (int) $parent_order['regionId'] : 0 );

		$order->set_currency( $this->currency( $parent_order ) );
		$order->add_order_note(
			sprintf(
				/* translators: %s: Temu parent order number. */
				__( 'Imported from Temu order %s.', 'woo-temu-sync' ),
				$parent_sn
			)
		);

		if ( isset( $parent_order['parentOrderTime'] ) && $parent_order['parentOrderTime'] > 0 ) {
			$order->set_date_created( (int) $parent_order['parentOrderTime'] );
		}

		foreach ( $lines as $line ) {
			if ( is_array( $line ) ) {
				$this->add_line_item( $order, $line );
			}
		}

		return $order;
	}

	/**
	 * Add one Temu order item to the order.
	 *
	 * @param \WC_Order $order Order.
	 * @param array     $line  Order item payload.
	 * @return void
	 */
	protected function add_line_item( $order, array $line ) {
		$quantity = isset( $line['quantity'] ) ? max( 1, (int) $line['quantity'] ) : 1;
		$product  = $this->resolve_product( $line );

		if ( $product ) {
			$item_id = $order->add_product( $product, $quantity );
			$item    = $item_id ? $order->get_item( $item_id ) : null;
		} else {
			// Never lose an order over an unmatched product: record what Temu sold
			// so the seller can see it and fulfil by hand.
			$item = new \WC_Order_Item_Product();
			$item->set_name( isset( $line['goodsName'] ) ? (string) $line['goodsName'] : __( 'Temu product', 'woo-temu-sync' ) );
			$item->set_quantity( $quantity );
			$order->add_item( $item );
		}

		if ( ! $item ) {
			return;
		}

		if ( isset( $line['orderSn'] ) ) {
			$item->add_meta_data( self::META_ORDER_SN, (string) $line['orderSn'], true );
		}

		if ( isset( $line['goodsId'] ) ) {
			$item->add_meta_data( self::META_GOODS_ID, (int) $line['goodsId'], true );
		}

		if ( isset( $line['skuId'] ) ) {
			$item->add_meta_data( self::META_SKU_ID, (int) $line['skuId'], true );
		}

		// Temu refuses partial fulfilment, so the quantity it believes it sold is
		// what has to be confirmed, not whatever the shop later edited the line to.
		$item->add_meta_data( self::META_QUANTITY, $quantity, true );

		if ( ! empty( $line['spec'] ) ) {
			$item->add_meta_data( __( 'Variation', 'woo-temu-sync' ), (string) $line['spec'], true );
		}

		$item->save();
	}

	/**
	 * Find the WooCommerce product behind a Temu order item.
	 *
	 * @param array $line Order item payload.
	 * @return \WC_Product|null
	 */
	protected function resolve_product( array $line ) {
		$product_id = 0;

		// Temu echoes the external SKU identifier we supplied at publish time,
		// which is the only reliable link back to a WooCommerce variation.
		foreach ( $this->external_codes( $line ) as $code ) {
			if ( preg_match( '/^wc-sku-(\d+)$/', $code, $matches ) ) {
				$product_id = (int) $matches[1];
				break;
			}
		}

		/**
		 * Filter the WooCommerce product id resolved for a Temu order item.
		 *
		 * @since 0.1.0
		 *
		 * @param int   $product_id Resolved product or variation id, 0 when unknown.
		 * @param array $line       Order item payload.
		 */
		$product_id = (int) apply_filters( 'wtsync_order_line_product_id', $product_id, $line );

		if ( $product_id <= 0 ) {
			return null;
		}

		$product = wc_get_product( $product_id );

		return $product && $product->exists() ? $product : null;
	}

	/**
	 * External SKU codes carried on an order item.
	 *
	 * @param array $line Order item payload.
	 * @return string[]
	 */
	protected function external_codes( array $line ) {
		$codes = array();

		if ( ! empty( $line['productList'] ) && is_array( $line['productList'] ) ) {
			foreach ( $line['productList'] as $product ) {
				if ( is_array( $product ) && ! empty( $product['extCode'] ) ) {
					$codes[] = (string) $product['extCode'];
				}
			}
		}

		if ( ! empty( $line['extCode'] ) ) {
			$codes[] = (string) $line['extCode'];
		}

		return $codes;
	}

	/**
	 * Fetch and apply the shipping address.
	 *
	 * @param \WC_Order $order  Order.
	 * @param array     $parent_order Parent order payload.
	 * @return void
	 */
	protected function apply_addresses( $order, array $parent_order ) {
		$parent_sn = (string) $order->get_meta( self::META_PARENT_SN );

		/**
		 * Filter whether to fetch the real, decrypted shipping address.
		 *
		 * Temu returns a masked address by default and the real one only from a
		 * separate endpoint. The real address is personal data: it is fetched
		 * because an order cannot be shipped without it, but a shop that fulfils
		 * somewhere else can turn this off and keep the personal data out of
		 * WooCommerce entirely.
		 *
		 * @since 0.1.0
		 *
		 * @param bool      $decrypt Whether to fetch the decrypted address.
		 * @param \WC_Order $order   Order being imported.
		 */
		$decrypt = (bool) apply_filters( 'wtsync_decrypt_addresses', true, $order );

		$method   = $decrypt ? self::METHOD_SHIPPING_DECRYPTED : self::METHOD_SHIPPING;
		$shipping = $this->fetch( $method, array( 'parentOrderSn' => $parent_sn ) );

		if ( empty( $shipping ) ) {
			return;
		}

		$address = $this->addresses->to_wc_address( $shipping, isset( $parent_order['regionId'] ) ? (int) $parent_order['regionId'] : 0 );

		$order->set_address( $address, 'shipping' );

		// Temu does not distinguish a billing address, and WooCommerce screens and
		// most shipping plugins read billing when shipping is empty.
		$order->set_address( $address, 'billing' );

		if ( ! empty( $shipping['mail'] ) ) {
			$order->set_billing_email( sanitize_email( (string) $shipping['mail'] ) );
		}

		$order->update_meta_data( self::META_ADDRESS_SET, $decrypt && ! $this->addresses->looks_masked( $shipping ) ? 'full' : 'masked' );
	}

	/**
	 * Fetch and apply the order money.
	 *
	 * @param \WC_Order $order     Order.
	 * @param string    $parent_sn Temu parentOrderSn.
	 * @return void
	 */
	protected function apply_amounts( $order, $parent_sn ) {
		$result = $this->fetch( self::METHOD_AMOUNT, array( 'parentOrderSn' => $parent_sn ) );

		$amounts = isset( $result['parentOrderMap'] ) && is_array( $result['parentOrderMap'] )
			? $result['parentOrderMap']
			: array();

		if ( empty( $amounts ) ) {
			return;
		}

		// Keep the whole payload: it carries the seller's revenue, Temu's discount
		// share and the tax split, none of which WooCommerce has a field for.
		$order->update_meta_data( self::META_AMOUNTS, $amounts );

		$total = $this->money( $amounts, 'customerPaid' );

		if ( null !== $total ) {
			$order->set_total( $total );
		}
	}

	/**
	 * Read one money field.
	 *
	 * @param array  $amounts Amount payload.
	 * @param string $key     Field name.
	 * @return string|null
	 */
	protected function money( array $amounts, $key ) {
		if ( ! isset( $amounts[ $key ]['amount'] ) ) {
			return null;
		}

		$amount = $amounts[ $key ]['amount'];

		/**
		 * Filter a money amount read from a Temu order.
		 *
		 * Temu's order amounts are documented only as a number beside a currency,
		 * without saying whether they are major or minor units. This filter is the
		 * one-line correction if a live order shows they are minor units.
		 *
		 * @since 0.1.0
		 *
		 * @param float  $amount Raw amount as Temu reported it.
		 * @param string $key    Which amount this is.
		 * @param array  $amounts The whole amount payload.
		 */
		$amount = apply_filters( 'wtsync_order_amount', (float) $amount, $key, $amounts );

		return wc_format_decimal( $amount, wc_get_price_decimals() );
	}

	/**
	 * Decide and apply the WooCommerce status.
	 *
	 * @param \WC_Order $order  Order.
	 * @param array     $parent_order Parent order payload.
	 * @param array     $lines  Order item payloads.
	 * @param bool      $is_new Whether the order was just created.
	 * @return void
	 */
	protected function apply_status( $order, array $parent_order, array $lines, $is_new ) {
		$raw = isset( $parent_order['parentOrderStatus'] ) ? (int) $parent_order['parentOrderStatus'] : 0;

		/**
		 * Filter the map of Temu order status codes to WooCommerce statuses.
		 *
		 * Temu does not publish this enumeration, so it ships empty. Fill it in
		 * once the codes are confirmed against a live seller account:
		 *
		 *     add_filter( 'wtsync_order_status_map', function ( $map ) {
		 *         $map[2] = 'processing';
		 *         $map[3] = 'completed';
		 *         return $map;
		 *     } );
		 *
		 * @since 0.1.0
		 *
		 * @param array $map Temu status code to WooCommerce status slug.
		 */
		$map = (array) apply_filters( 'wtsync_order_status_map', array() );

		if ( isset( $map[ $raw ] ) ) {
			$order->set_status( $map[ $raw ] );

			return;
		}

		// Cancellation is knowable without the enumeration: Temu reports it as a
		// documented boolean on the order item.
		if ( $this->is_cancelled( $lines ) ) {
			$order->set_status( 'cancelled' );

			return;
		}

		if ( ! $is_new ) {
			// An unmapped code says nothing new, so leave whatever the shop set.
			return;
		}

		/**
		 * Filter the WooCommerce status given to a newly imported Temu order.
		 *
		 * @since 0.1.0
		 *
		 * @param string $status WooCommerce status slug.
		 */
		$order->set_status( (string) apply_filters( 'wtsync_new_order_status', 'processing' ) );
	}

	/**
	 * Whether every line of the order was cancelled before shipment.
	 *
	 * @param array $lines Order item payloads.
	 * @return bool
	 */
	protected function is_cancelled( array $lines ) {
		if ( empty( $lines ) ) {
			return false;
		}

		foreach ( $lines as $line ) {
			if ( ! is_array( $line ) || empty( $line['isCancelledDuringPending'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The order currency.
	 *
	 * @param array $parent_order Parent order payload.
	 * @return string
	 */
	protected function currency( array $parent_order ) {
		if ( ! empty( $parent_order['currency'] ) ) {
			return (string) $parent_order['currency'];
		}

		return get_woocommerce_currency();
	}

	/**
	 * Call an order API, returning an empty array on failure.
	 *
	 * A missing address or missing totals must not abandon the import: an order
	 * that exists with gaps can be repaired on the next poll, while an order
	 * that was never created is invisible to the seller.
	 *
	 * @param string $method API method name.
	 * @param array  $params Parameters.
	 * @return array
	 */
	protected function fetch( $method, array $params ) {
		try {
			return $this->credentials->client()->request( $method, $params );
		} catch ( ApiException $e ) {
			$this->logger->error(
				sprintf( '%s failed: %s', $method, $e->getMessage() ),
				array(
					'code'       => $e->get_error_code(),
					'request_id' => $e->get_request_id(),
				)
			);

			return array();
		}
	}
}
