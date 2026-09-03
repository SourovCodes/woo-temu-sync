<?php
/**
 * Tests for confirming self-fulfilled shipments to Temu.
 *
 * @package WooTemuSync
 */

namespace WooTemuSync\Tests;

use WC_Order_Item_Product;
use WooTemuSync\Api\Credentials;
use WooTemuSync\Api\Region;
use WooTemuSync\Fulfilment\CarrierRepository;
use WooTemuSync\Fulfilment\FulfilmentException;
use WooTemuSync\Fulfilment\ShipmentConfirmer;
use WooTemuSync\Logger;
use WooTemuSync\Order\OrderImporter;
use WP_UnitTestCase;

/**
 * Covers payload shape, Temu's fulfilment rules and error translation.
 */
class ShipmentConfirmerTest extends WP_UnitTestCase {

	/**
	 * Confirmer under test.
	 *
	 * @var ShipmentConfirmer
	 */
	private $confirmer;

	/**
	 * Decoded request bodies.
	 *
	 * @var array
	 */
	private $sent = array();

	/**
	 * Connect a store.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->sent = array();

		$credentials = new Credentials();
		$credentials->update(
			array(
				'app_key'      => 'appkey123',
				'app_secret'   => 'secret456',
				'access_token' => 'token789',
				'region'       => Region::EU,
			)
		);

		$logger = new Logger();

		$this->confirmer = new ShipmentConfirmer(
			$credentials,
			new CarrierRepository( $credentials, $logger ),
			$logger
		);
	}

	/**
	 * Clear state between tests.
	 *
	 * @return void
	 */
	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'wtsync_self_shipping_warehouse_id' );
		remove_all_filters( 'wtsync_shipment_send_type' );
		delete_transient( CarrierRepository::CACHE_PREFIX . '76' );
		delete_option( Credentials::OPTION );

		parent::tear_down();
	}

	/**
	 * Answer the confirm call, and serve a carrier list.
	 *
	 * @param array $failure Optional failure envelope for the confirm call.
	 * @return void
	 */
	private function fake_api( array $failure = array() ) {
		add_filter(
			'pre_http_request',
			function ( $pre, $args ) use ( $failure ) {
				$body         = json_decode( $args['body'], true );
				$this->sent[] = $body;

				if ( CarrierRepository::METHOD_COMPANIES === $body['type'] ) {
					$envelope = array(
						'success'   => true,
						'errorCode' => 1000000,
						'result'    => array(
							array(
								'logisticsServiceProviderId'   => 5501,
								'logisticsServiceProviderName' => 'DHL Paket',
							),
							array(
								'logisticsServiceProviderId'   => 5502,
								'logisticsServiceProviderName' => 'DPD',
							),
						),
					);
				} elseif ( ! empty( $failure ) ) {
					$envelope = $failure;
				} else {
					$envelope = array(
						'success'   => true,
						'errorCode' => 1000000,
						'result'    => array(),
					);
				}

				return array(
					'headers'  => array(),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode( $envelope ),
				);
			},
			10,
			2
		);
	}

	/**
	 * An imported Temu order with two line items.
	 *
	 * @return \WC_Order
	 */
	private function temu_order() {
		$order = wc_create_order();
		$order->update_meta_data( OrderImporter::META_PARENT_SN, 'PO-76-9900000000001' );
		$order->update_meta_data( OrderImporter::META_REGION_ID, 76 );

		foreach ( array( array( '76-1', 700500, 9001, 2 ), array( '76-2', 700501, 9002, 1 ) ) as $line ) {
			list( $order_sn, $goods_id, $sku_id, $quantity ) = $line;

			$item = new WC_Order_Item_Product();
			$item->set_name( 'Temu line ' . $order_sn );
			$item->set_quantity( $quantity );
			$item->add_meta_data( OrderImporter::META_ORDER_SN, $order_sn, true );
			$item->add_meta_data( OrderImporter::META_GOODS_ID, $goods_id, true );
			$item->add_meta_data( OrderImporter::META_SKU_ID, $sku_id, true );
			$item->add_meta_data( OrderImporter::META_QUANTITY, $quantity, true );
			$order->add_item( $item );
		}

		$order->save();

		return wc_get_order( $order->get_id() );
	}

	/**
	 * The confirm call the test made, if any.
	 *
	 * @return array|null
	 */
	private function confirm_call() {
		foreach ( $this->sent as $body ) {
			if ( ShipmentConfirmer::METHOD_CONFIRM === $body['type'] ) {
				return $body;
			}
		}

		return null;
	}

	/**
	 * Tracking numbers are stripped of the separators Temu refuses.
	 *
	 * Error 120014020 rejects a tracking number carrying dashes or hashes, which
	 * couriers and shop staff both add freely.
	 *
	 * @return void
	 */
	public function test_tracking_numbers_are_cleaned() {
		$this->assertSame( 'JD014600006281485399', $this->confirmer->clean_tracking_number( 'JD-0146 0000-6281485399' ) );
		$this->assertSame( 'ABC123', $this->confirmer->clean_tracking_number( '  #ABC-123%  ' ) );
		$this->assertSame( '', $this->confirmer->clean_tracking_number( '---' ) );
	}

	/**
	 * Every Temu line goes in one confirmation, at the quantity Temu recorded.
	 *
	 * Temu refuses partial fulfilment, so sending a subset gets the whole
	 * shipment rejected rather than partly accepted.
	 *
	 * @return void
	 */
	public function test_the_whole_order_is_confirmed_at_once() {
		$this->fake_api();

		$order = $this->temu_order();
		$this->assertTrue( $this->confirmer->confirm( $order, 'JD0146', 5501 ) );

		$call    = $this->confirm_call();
		$request = $call['sendRequestList'][0];

		$this->assertSame( 'JD0146', $request['trackingNumber'] );
		$this->assertSame( 5501, $request['carrierId'] );
		$this->assertCount( 2, $request['orderSendInfoList'] );

		$first = $request['orderSendInfoList'][0];
		$this->assertSame( '76-1', $first['orderSn'] );
		$this->assertSame( 'PO-76-9900000000001', $first['parentOrderSn'] );
		$this->assertSame( 700500, $first['goodsId'] );
		$this->assertSame( 9001, $first['skuId'] );
		$this->assertSame( 2, $first['quantity'] );
	}

	/**
	 * A line the seller added by hand is not sent to Temu.
	 *
	 * Temu would reject the whole confirmation over an order item it does not
	 * recognise.
	 *
	 * @return void
	 */
	public function test_manually_added_lines_are_excluded() {
		$this->fake_api();

		$order = $this->temu_order();

		$extra = new WC_Order_Item_Product();
		$extra->set_name( 'A gift the seller added' );
		$extra->set_quantity( 1 );
		$order->add_item( $extra );
		$order->save();

		$this->confirmer->confirm( wc_get_order( $order->get_id() ), 'JD0146', 5501 );

		$this->assertCount( 2, $this->confirm_call()['sendRequestList'][0]['orderSendInfoList'] );
	}

	/**
	 * A successful confirmation is recorded and cannot be repeated.
	 *
	 * Temu accepts a tracking number for one fulfilment only.
	 *
	 * @return void
	 */
	public function test_a_confirmed_shipment_is_recorded_and_not_repeatable() {
		$this->fake_api();

		$order = $this->temu_order();
		$this->confirmer->confirm( $order, 'JD0146', 5501 );

		$fresh = wc_get_order( $order->get_id() );
		$this->assertTrue( $this->confirmer->is_shipped( $fresh ) );
		$this->assertSame( 'JD0146', $fresh->get_meta( ShipmentConfirmer::META_TRACKING ) );
		$this->assertSame( 5501, (int) $fresh->get_meta( ShipmentConfirmer::META_CARRIER ) );
		$this->assertFalse( $this->confirmer->is_fulfillable( $fresh ) );

		$this->expectException( FulfilmentException::class );
		$this->confirmer->confirm( $fresh, 'JD0147', 5501 );
	}

	/**
	 * An order Temu already knows is shipped is recorded, not reported as failed.
	 *
	 * A retry after a lost response must settle, not leave the seller thinking
	 * the shipment never registered.
	 *
	 * @return void
	 */
	public function test_an_already_shipped_order_settles_rather_than_erroring() {
		$this->fake_api(
			array(
				'success'   => false,
				'errorCode' => 120012004,
				'errorMsg'  => 'The order has been shipped.',
			)
		);

		$order = $this->temu_order();

		$this->assertTrue( $this->confirmer->confirm( $order, 'JD0146', 5501 ) );
		$this->assertTrue( $this->confirmer->is_shipped( wc_get_order( $order->get_id() ) ) );
	}

	/**
	 * A blank tracking number never reaches the API.
	 *
	 * @return void
	 */
	public function test_a_blank_tracking_number_is_refused_locally() {
		$this->fake_api();

		$this->expectException( FulfilmentException::class );
		$this->confirmer->confirm( $this->temu_order(), '   ', 5501 );
	}

	/**
	 * A missing carrier never reaches the API.
	 *
	 * @return void
	 */
	public function test_a_missing_carrier_is_refused_locally() {
		$this->fake_api();

		$this->expectException( FulfilmentException::class );
		$this->confirmer->confirm( $this->temu_order(), 'JD0146', 0 );
	}

	/**
	 * A carrier Temu does not list for the market is refused before sending.
	 *
	 * @return void
	 */
	public function test_an_unknown_carrier_is_refused() {
		$this->fake_api();

		$this->expectException( FulfilmentException::class );
		$this->confirmer->confirm( $this->temu_order(), 'JD0146', 9999 );
	}

	/**
	 * An order that did not come from Temu is not fulfillable.
	 *
	 * @return void
	 */
	public function test_a_non_temu_order_is_not_fulfillable() {
		$order = wc_create_order();
		$order->save();

		$this->assertFalse( $this->confirmer->is_fulfillable( wc_get_order( $order->get_id() ) ) );
	}

	/**
	 * A rejection is translated into something the seller can act on.
	 *
	 * @return void
	 */
	public function test_a_pending_address_change_is_explained() {
		$this->fake_api(
			array(
				'success'   => false,
				'errorCode' => 120012023,
				'errorMsg'  => 'Address change pending.',
			)
		);

		$order = $this->temu_order();

		try {
			$this->confirmer->confirm( $order, 'JD0146', 5501 );
			$this->fail( 'Expected a FulfilmentException.' );
		} catch ( FulfilmentException $e ) {
			$this->assertStringContainsString( 'Seller Center', $e->getMessage() );
		}

		$fresh = wc_get_order( $order->get_id() );
		$this->assertFalse( $this->confirmer->is_shipped( $fresh ), 'A rejected shipment must stay unshipped.' );
		$this->assertStringContainsString( 'address', strtolower( (string) $fresh->get_meta( ShipmentConfirmer::META_LAST_ERROR ) ) );
	}

	/**
	 * A store restricted to buy-shipping is told plainly what happened.
	 *
	 * @return void
	 */
	public function test_a_buy_shipping_restriction_is_explained() {
		$this->fake_api(
			array(
				'success'   => false,
				'errorCode' => 120012035,
				'errorMsg'  => 'Restricted from using the Confirm shipment function.',
			)
		);

		try {
			$this->confirmer->confirm( $this->temu_order(), 'JD0146', 5501 );
			$this->fail( 'Expected a FulfilmentException.' );
		} catch ( FulfilmentException $e ) {
			$this->assertStringContainsString( 'buy-shipping', strtolower( $e->getMessage() ) );
		}
	}

	/**
	 * A warehouse id is sent only when the store configures one.
	 *
	 * @return void
	 */
	public function test_the_warehouse_id_is_optional() {
		$this->fake_api();
		$this->confirmer->confirm( $this->temu_order(), 'JD0146', 5501 );
		$this->assertArrayNotHasKey( 'selfShippingWarehouseId', $this->confirm_call()['sendRequestList'][0] );

		remove_all_filters( 'pre_http_request' );
		$this->sent = array();

		add_filter(
			'wtsync_self_shipping_warehouse_id',
			static function () {
				return 'WH-BERLIN-1';
			}
		);

		$this->fake_api();
		$this->confirmer->confirm( $this->temu_order(), 'JD9999', 5501 );

		$this->assertSame( 'WH-BERLIN-1', $this->confirm_call()['sendRequestList'][0]['selfShippingWarehouseId'] );
	}

	/**
	 * Carriers are cached rather than fetched for every order screen.
	 *
	 * @return void
	 */
	public function test_the_carrier_list_is_cached() {
		$this->fake_api();

		$credentials = new Credentials();
		$carriers    = new CarrierRepository( $credentials, new Logger() );

		$this->assertSame(
			array(
				5501 => 'DHL Paket',
				5502 => 'DPD',
			),
			$carriers->for_region( 76 )
		);

		$before = count( $this->sent );
		$carriers->for_region( 76 );

		$this->assertCount( $before, $this->sent, 'A cached carrier list must not cost another API call.' );
	}
}
