<?php
/**
 * Tests for importing Temu orders into WooCommerce.
 *
 * @package WooTemuSync
 */

namespace WooTemuSync\Tests;

use WC_Product_Simple;
use WooTemuSync\Api\Credentials;
use WooTemuSync\Api\Region;
use WooTemuSync\Logger;
use WooTemuSync\Order\AddressMapper;
use WooTemuSync\Order\OrderImporter;
use WP_UnitTestCase;

/**
 * Covers idempotency, line items, addresses, money and status handling.
 */
class OrderImporterTest extends WP_UnitTestCase {

	/**
	 * Importer under test.
	 *
	 * @var OrderImporter
	 */
	private $importer;

	/**
	 * Connect a store.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$credentials = new Credentials();
		$credentials->update(
			array(
				'app_key'      => 'appkey123',
				'app_secret'   => 'secret456',
				'access_token' => 'token789',
				'region'       => Region::EU,
			)
		);

		update_option( 'woocommerce_currency', 'EUR' );

		$this->importer = new OrderImporter( $credentials, new AddressMapper(), new Logger() );
	}

	/**
	 * Clear state between tests.
	 *
	 * @return void
	 */
	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'wtsync_order_status_map' );
		remove_all_filters( 'wtsync_new_order_status' );
		remove_all_filters( 'wtsync_decrypt_addresses' );
		remove_all_filters( 'wtsync_should_import_order' );
		delete_option( Credentials::OPTION );

		parent::tear_down();
	}

	/**
	 * Answer the shipping and amount calls with canned data.
	 *
	 * @param array $shipping Shipping result.
	 * @param array $amounts  Amount result parentOrderMap.
	 * @return void
	 */
	private function fake_api( array $shipping = array(), array $amounts = array() ) {
		add_filter(
			'pre_http_request',
			static function ( $pre, $args ) use ( $shipping, $amounts ) {
				$body = json_decode( $args['body'], true );

				if ( OrderImporter::METHOD_AMOUNT === $body['type'] ) {
					$result = empty( $amounts ) ? array() : array( 'parentOrderMap' => $amounts );
				} else {
					$result = $shipping;
				}

				return array(
					'headers'  => array(),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode(
						array(
							'success'   => true,
							'errorCode' => 1000000,
							'result'    => $result,
						)
					),
				);
			},
			10,
			2
		);
	}

	/**
	 * A German shipping address as Temu returns it.
	 *
	 * Synthetic data shaped like the real thing, not anybody's address.
	 *
	 * @return array
	 */
	private function shipping_payload() {
		return array(
			'receiptName'  => 'Anna Schmidt',
			'addressExtra' => array(
				'firstName' => 'Anna',
				'lastName'  => 'Schmidt',
			),
			'addressLine1' => 'Musterstrasse 12',
			'addressLine2' => 'Hinterhaus',
			'addressLine3' => 'Wohnung 4',
			'regionName1'  => 'Germany',
			'regionName2'  => 'Berlin',
			'regionName3'  => 'Berlin',
			'postCode'     => '10115',
			'mobile'       => '030123456',
			'mail'         => 'buyer@example.com',
		);
	}

	/**
	 * A Temu parent order payload.
	 *
	 * @param array $overrides Parent order overrides.
	 * @param array $lines     Order item payloads.
	 * @return array
	 */
	private function order_payload( array $overrides = array(), array $lines = array() ) {
		$parent = array_merge(
			array(
				'parentOrderSn'     => 'PO-76-9900000000001',
				'parentOrderStatus' => 2,
				'regionId'          => 76,
				'parentOrderTime'   => time() - 3600,
				'updateTime'        => time(),
			),
			$overrides
		);

		if ( empty( $lines ) ) {
			$lines = array(
				array(
					'orderSn'   => '76-9900000000001',
					'goodsId'   => 700500,
					'skuId'     => 9001,
					'quantity'  => 2,
					'goodsName' => 'Enamel Coffee Mug',
					'spec'      => 'Colour: Red',
				),
			);
		}

		return array(
			'parentOrderMap' => $parent,
			'orderList'      => $lines,
		);
	}

	/**
	 * A published product whose external SKU id Temu would echo back.
	 *
	 * @return \WC_Product
	 */
	private function published_product() {
		$product = new WC_Product_Simple();
		$product->set_name( 'Enamel Coffee Mug' );
		$product->set_regular_price( '12.00' );
		$product->save();

		return wc_get_product( $product->get_id() );
	}

	/**
	 * An order is created and keyed on the Temu parent order number.
	 *
	 * @return void
	 */
	public function test_an_order_is_created_and_keyed_on_the_parent_order() {
		$this->fake_api( $this->shipping_payload() );

		$order = $this->importer->import( $this->order_payload() );

		$this->assertNotNull( $order );
		$this->assertSame( 'PO-76-9900000000001', $order->get_meta( OrderImporter::META_PARENT_SN ) );
		$this->assertSame( 'EUR', $order->get_currency() );
		$this->assertCount( 1, $order->get_items() );
	}

	/**
	 * Importing the same order twice does not create a second one.
	 *
	 * Temu is polled on update time, so the same order comes back repeatedly.
	 *
	 * @return void
	 */
	public function test_import_is_idempotent() {
		$this->fake_api( $this->shipping_payload() );

		$payload = $this->order_payload();
		$first   = $this->importer->import( $payload );
		$second  = $this->importer->import( $payload );

		$this->assertSame( $first->get_id(), $second->get_id() );
		$this->assertCount(
			1,
			$order_ids = wc_get_orders(
				array(
					'limit'  => -1,
					'status' => 'any',
				)
			)
		);
	}

	/**
	 * A line item is matched to the WooCommerce product by our own SKU code.
	 *
	 * @return void
	 */
	public function test_line_items_match_products_by_external_sku_code() {
		$product = $this->published_product();
		$this->fake_api( $this->shipping_payload() );

		$order = $this->importer->import(
			$this->order_payload(
				array(),
				array(
					array(
						'orderSn'     => '76-9900000000001',
						'quantity'    => 3,
						'goodsName'   => 'Enamel Coffee Mug',
						'productList' => array(
							array( 'extCode' => 'wc-sku-' . $product->get_id() ),
						),
					),
				)
			)
		);

		$items = array_values( $order->get_items() );
		$this->assertSame( $product->get_id(), $items[0]->get_product_id() );
		$this->assertSame( 3, $items[0]->get_quantity() );
	}

	/**
	 * An unmatched product still produces a line item.
	 *
	 * A missing order is invisible to the seller; an untidy one is not. Never
	 * drop an order because a product could not be resolved.
	 *
	 * @return void
	 */
	public function test_an_unmatched_product_still_becomes_a_line_item() {
		$this->fake_api( $this->shipping_payload() );

		$order = $this->importer->import( $this->order_payload() );
		$items = array_values( $order->get_items() );

		$this->assertCount( 1, $items );
		$this->assertSame( 'Enamel Coffee Mug', $items[0]->get_name() );
		$this->assertSame( 2, $items[0]->get_quantity() );
		$this->assertSame( 0, $items[0]->get_product_id() );
	}

	/**
	 * The Temu order item id is kept on the line, for fulfilment later.
	 *
	 * @return void
	 */
	public function test_the_temu_order_item_id_is_kept_on_the_line() {
		$this->fake_api( $this->shipping_payload() );

		$order = $this->importer->import( $this->order_payload() );
		$items = array_values( $order->get_items() );

		$this->assertSame( '76-9900000000001', $items[0]->get_meta( OrderImporter::META_ORDER_SN ) );
	}

	/**
	 * The address is mapped, including the country Temu only names in prose.
	 *
	 * @return void
	 */
	public function test_the_shipping_address_is_mapped() {
		$this->fake_api( $this->shipping_payload() );

		$order = $this->importer->import( $this->order_payload() );

		$this->assertSame( 'Anna', $order->get_shipping_first_name() );
		$this->assertSame( 'Schmidt', $order->get_shipping_last_name() );
		$this->assertSame( 'Musterstrasse 12', $order->get_shipping_address_1() );
		$this->assertSame( 'Hinterhaus, Wohnung 4', $order->get_shipping_address_2(), 'A third line must not be dropped.' );
		$this->assertSame( 'Berlin', $order->get_shipping_city() );
		$this->assertSame( '10115', $order->get_shipping_postcode() );
		$this->assertSame( 'DE', $order->get_shipping_country(), 'regionId 76 is Germany.' );
		$this->assertSame( 'buyer@example.com', $order->get_billing_email() );
	}

	/**
	 * Billing is filled from shipping, because Temu supplies only one address.
	 *
	 * @return void
	 */
	public function test_billing_mirrors_shipping() {
		$this->fake_api( $this->shipping_payload() );

		$order = $this->importer->import( $this->order_payload() );

		$this->assertSame( 'Musterstrasse 12', $order->get_billing_address_1() );
		$this->assertSame( 'DE', $order->get_billing_country() );
	}

	/**
	 * Personal data can be kept out of WooCommerce entirely.
	 *
	 * @return void
	 */
	public function test_address_decryption_can_be_turned_off() {
		$called = array();

		add_filter( 'wtsync_decrypt_addresses', '__return_false' );
		add_filter(
			'pre_http_request',
			static function ( $pre, $args ) use ( &$called ) {
				$body     = json_decode( $args['body'], true );
				$called[] = $body['type'];

				return array(
					'headers'  => array(),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode(
						array(
							'success'   => true,
							'errorCode' => 1000000,
							'result'    => array(),
						)
					),
				);
			},
			10,
			2
		);

		$this->importer->import( $this->order_payload() );

		$this->assertContains( OrderImporter::METHOD_SHIPPING, $called );
		$this->assertNotContains( OrderImporter::METHOD_SHIPPING_DECRYPTED, $called );
	}

	/**
	 * The order total comes from what the customer paid.
	 *
	 * @return void
	 */
	public function test_the_total_is_taken_from_customer_paid() {
		$this->fake_api(
			$this->shipping_payload(),
			array(
				'customerPaid' => array(
					'amount'   => 24.99,
					'currency' => 'EUR',
				),
				'taxTotal'     => array(
					'amount'   => 3.99,
					'currency' => 'EUR',
				),
			)
		);

		$order = $this->importer->import( $this->order_payload() );

		$this->assertSame( '24.99', $order->get_total() );

		$amounts = $order->get_meta( OrderImporter::META_AMOUNTS );
		$this->assertIsArray( $amounts, 'The whole amount payload is kept: WooCommerce has no field for the seller revenue split.' );
		$this->assertArrayHasKey( 'taxTotal', $amounts );
	}

	/**
	 * A new order lands in a status the seller can act on.
	 *
	 * @return void
	 */
	public function test_a_new_order_is_actionable() {
		$this->fake_api( $this->shipping_payload() );

		$order = $this->importer->import( $this->order_payload() );

		$this->assertSame( 'processing', $order->get_status() );
	}

	/**
	 * An undocumented Temu status is recorded but never guessed at.
	 *
	 * Temu publishes no enumeration for parentOrderStatus, and guessing could
	 * mark an unshipped order complete.
	 *
	 * @return void
	 */
	public function test_an_unmapped_status_is_recorded_not_guessed() {
		$this->fake_api( $this->shipping_payload() );

		$order = $this->importer->import( $this->order_payload( array( 'parentOrderStatus' => 7 ) ) );

		$this->assertSame( 7, (int) $order->get_meta( OrderImporter::META_RAW_STATUS ) );
		$this->assertSame( 'processing', $order->get_status() );
	}

	/**
	 * Once the enumeration is known, one filter maps it.
	 *
	 * @return void
	 */
	public function test_the_status_map_is_filterable() {
		add_filter(
			'wtsync_order_status_map',
			static function () {
				return array( 3 => 'completed' );
			}
		);

		$this->fake_api( $this->shipping_payload() );

		$order = $this->importer->import( $this->order_payload( array( 'parentOrderStatus' => 3 ) ) );

		$this->assertSame( 'completed', $order->get_status() );
	}

	/**
	 * Cancellation is detected from the documented boolean, not the enum.
	 *
	 * @return void
	 */
	public function test_a_cancelled_order_is_detected_without_the_enum() {
		$this->fake_api( $this->shipping_payload() );

		$order = $this->importer->import(
			$this->order_payload(
				array(),
				array(
					array(
						'orderSn'                  => '76-9900000000001',
						'quantity'                 => 1,
						'goodsName'                => 'Enamel Coffee Mug',
						'isCancelledDuringPending' => true,
					),
				)
			)
		);

		$this->assertSame( 'cancelled', $order->get_status() );
	}

	/**
	 * A partially cancelled order is not treated as cancelled.
	 *
	 * @return void
	 */
	public function test_a_partly_cancelled_order_is_not_cancelled() {
		$this->fake_api( $this->shipping_payload() );

		$order = $this->importer->import(
			$this->order_payload(
				array(),
				array(
					array(
						'orderSn'                  => 'a',
						'quantity'                 => 1,
						'goodsName'                => 'One',
						'isCancelledDuringPending' => true,
					),
					array(
						'orderSn'   => 'b',
						'quantity'  => 1,
						'goodsName' => 'Two',
					),
				)
			)
		);

		$this->assertNotSame( 'cancelled', $order->get_status() );
	}

	/**
	 * An order can be skipped entirely by a filter.
	 *
	 * @return void
	 */
	public function test_an_order_can_be_skipped() {
		add_filter( 'wtsync_should_import_order', '__return_false' );
		$this->fake_api( $this->shipping_payload() );

		$this->assertNull( $this->importer->import( $this->order_payload() ) );
	}

	/**
	 * A payload with no parent order number is ignored rather than fatal.
	 *
	 * @return void
	 */
	public function test_a_payload_without_a_parent_order_number_is_ignored() {
		$this->assertNull( $this->importer->import( array() ) );
		$this->assertNull( $this->importer->import( array( 'parentOrderMap' => array() ) ) );
	}
}
