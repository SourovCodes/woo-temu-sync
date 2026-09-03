<?php
/**
 * Tests for pushing stock levels to Temu.
 *
 * @package WooTemuSync
 */

namespace WooTemuSync\Tests;

use WC_Product_Simple;
use WooTemuSync\Api\Credentials;
use WooTemuSync\Api\Region;
use WooTemuSync\Logger;
use WooTemuSync\Product\Mapper;
use WooTemuSync\Product\Publisher;
use WooTemuSync\Sync\SkuMap;
use WooTemuSync\Sync\StockSync;
use WP_UnitTestCase;

/**
 * Covers level calculation, the full-overwrite contract and change detection.
 */
class StockSyncTest extends WP_UnitTestCase {

	/**
	 * Stock sync under test.
	 *
	 * @var StockSync
	 */
	private $sync;

	/**
	 * Decoded request bodies, in order.
	 *
	 * @var array
	 */
	private $sent = array();

	/**
	 * Connect a store and build the sync.
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

		$logger  = new Logger();
		$sku_map = new SkuMap( $credentials, new Mapper(), $logger );

		$this->sync = new StockSync( $credentials, $sku_map, $logger );
	}

	/**
	 * Clear filters and stored state between tests.
	 *
	 * @return void
	 */
	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'wtsync_unmanaged_stock_quantity' );
		delete_option( Credentials::OPTION );

		parent::tear_down();
	}

	/**
	 * Answer API calls with a scripted response, capturing what was sent.
	 *
	 * @param callable $responder Given the decoded body, returns the result member.
	 * @return void
	 */
	private function fake_api( callable $responder ) {
		add_filter(
			'pre_http_request',
			function ( $pre, $args ) use ( $responder ) {
				$body         = json_decode( $args['body'], true );
				$this->sent[] = $body;

				return array(
					'headers'  => array(),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode( call_user_func( $responder, $body ) ),
				);
			},
			10,
			2
		);
	}

	/**
	 * A successful Temu envelope.
	 *
	 * @param array $result Result member.
	 * @return array
	 */
	private function ok( array $result = array() ) {
		return array(
			'success'   => true,
			'errorCode' => 1000000,
			'result'    => $result,
		);
	}

	/**
	 * A published simple product with a pre-resolved SKU map.
	 *
	 * @param array $props Product properties.
	 * @return \WC_Product
	 */
	private function published_product( array $props = array() ) {
		$product = new WC_Product_Simple();
		$product->set_name( 'Synced product' );
		$product->set_regular_price( '10.00' );

		foreach ( $props as $setter => $value ) {
			$product->{"set_{$setter}"}( $value );
		}

		$product->update_meta_data( Publisher::META_GOODS_ID, 700100 );
		$product->save();

		$fresh = wc_get_product( $product->get_id() );
		$fresh->update_meta_data( SkuMap::META_MAP, array( $fresh->get_id() => 51481625557677 ) );
		$fresh->update_meta_data( SkuMap::META_MAP_AT, time() );
		$fresh->save();

		return wc_get_product( $product->get_id() );
	}

	/**
	 * Managed stock is reported as-is.
	 *
	 * @return void
	 */
	public function test_levels_from_managed_stock() {
		$product = $this->published_product(
			array(
				'manage_stock'   => true,
				'stock_quantity' => 17,
			)
		);

		$this->assertSame( array( $product->get_id() => 17 ), $this->sync->levels( $product ) );
	}

	/**
	 * Unmanaged stock still reports a number, because Temu reads a missing one
	 * as zero and lists the product as sold out.
	 *
	 * @return void
	 */
	public function test_levels_from_unmanaged_stock() {
		$in_stock = $this->published_product();
		$this->assertSame( array( $in_stock->get_id() => 100 ), $this->sync->levels( $in_stock ) );

		$sold_out = $this->published_product( array( 'stock_status' => 'outofstock' ) );
		$this->assertSame( array( $sold_out->get_id() => 0 ), $this->sync->levels( $sold_out ) );
	}

	/**
	 * Negative stock is floored at zero rather than sent as a negative.
	 *
	 * WooCommerce allows a backordered product to go below zero; Temu would
	 * reject it (150013002 wants 0 to 1,000,000).
	 *
	 * @return void
	 */
	public function test_negative_stock_is_floored() {
		$product = $this->published_product(
			array(
				'manage_stock'   => true,
				'stock_quantity' => -5,
			)
		);

		$this->assertSame( array( $product->get_id() => 0 ), $this->sync->levels( $product ) );
	}

	/**
	 * Stock above Temu's ceiling is clamped rather than rejected.
	 *
	 * @return void
	 */
	public function test_stock_is_clamped_to_the_platform_maximum() {
		$product = $this->published_product(
			array(
				'manage_stock'   => true,
				'stock_quantity' => 5000000,
			)
		);

		$this->assertSame(
			array( $product->get_id() => StockSync::MAX_QUANTITY ),
			$this->sync->levels( $product )
		);
	}

	/**
	 * The push uses the full-overwrite mode, never the incremental one.
	 *
	 * This is the correctness property of the whole class. The client retries on
	 * rate limits, and a retried delta applies twice; a retried overwrite does
	 * not. Temu also rejects a request carrying both modes at once.
	 *
	 * @return void
	 */
	public function test_push_uses_full_overwrite_not_a_delta() {
		$this->fake_api(
			function () {
				return $this->ok();
			}
		);

		$product = $this->published_product(
			array(
				'manage_stock'   => true,
				'stock_quantity' => 12,
			)
		);

		$this->assertTrue( $this->sync->sync( $product ) );

		$body = $this->sent[0];

		$this->assertSame( 'bg.local.goods.stock.edit', $body['type'] );
		$this->assertSame( 700100, $body['goodsId'] );
		$this->assertArrayHasKey( 'skuStockTargetList', $body );
		$this->assertArrayNotHasKey( 'skuStockChangeList', $body, 'Sending both modes is a platform error (150013003).' );
		$this->assertSame( 51481625557677, $body['skuStockTargetList'][0]['skuId'] );
		$this->assertSame( 12, $body['skuStockTargetList'][0]['stockTarget'] );
		$this->assertNotEmpty( $body['requestUniqueKey'] );
	}

	/**
	 * Nothing is sent when the levels have not moved.
	 *
	 * @return void
	 */
	public function test_unchanged_levels_are_not_resent() {
		$this->fake_api(
			function () {
				return $this->ok();
			}
		);

		$product = $this->published_product(
			array(
				'manage_stock'   => true,
				'stock_quantity' => 8,
			)
		);

		$this->assertTrue( $this->sync->sync( $product ) );
		$this->assertCount( 1, $this->sent );

		$this->assertFalse( $this->sync->sync( wc_get_product( $product->get_id() ) ) );
		$this->assertCount( 1, $this->sent, 'A settled product must cost no API calls.' );
	}

	/**
	 * A real change is sent even though the previous one was remembered.
	 *
	 * @return void
	 */
	public function test_changed_levels_are_sent() {
		$this->fake_api(
			function () {
				return $this->ok();
			}
		);

		$product = $this->published_product(
			array(
				'manage_stock'   => true,
				'stock_quantity' => 8,
			)
		);
		$this->sync->sync( $product );

		$updated = wc_get_product( $product->get_id() );
		$updated->set_stock_quantity( 3 );
		$updated->save();

		$this->assertTrue( $this->sync->sync( wc_get_product( $product->get_id() ) ) );
		$this->assertCount( 2, $this->sent );
		$this->assertSame( 3, $this->sent[1]['skuStockTargetList'][0]['stockTarget'] );
	}

	/**
	 * The idempotency key follows the levels, not the clock.
	 *
	 * @return void
	 */
	public function test_idempotency_key_tracks_the_levels() {
		$this->fake_api(
			function () {
				return $this->ok();
			}
		);

		$product = $this->published_product(
			array(
				'manage_stock'   => true,
				'stock_quantity' => 8,
			)
		);
		$this->sync->sync( $product );

		$updated = wc_get_product( $product->get_id() );
		$updated->set_stock_quantity( 9 );
		$updated->save();
		$this->sync->sync( wc_get_product( $product->get_id() ) );

		$this->assertNotSame( $this->sent[0]['requestUniqueKey'], $this->sent[1]['requestUniqueKey'] );
	}

	/**
	 * A product that was never published is left alone.
	 *
	 * @return void
	 */
	public function test_unpublished_products_are_skipped() {
		$this->fake_api(
			function () {
				return $this->ok();
			}
		);

		$product = new WC_Product_Simple();
		$product->set_name( 'Never published' );
		$product->set_regular_price( '5.00' );
		$product->save();

		$this->assertFalse( $this->sync->sync( wc_get_product( $product->get_id() ) ) );
		$this->assertCount( 0, $this->sent );
	}

	/**
	 * A rejected SKU drops the cached map so it is resolved again next time.
	 *
	 * A mismatch means the listing changed shape on Temu's side, and continuing
	 * to push against a stale map would write to the wrong SKU.
	 *
	 * @return void
	 */
	public function test_a_mismatched_sku_invalidates_the_map() {
		$this->fake_api(
			function () {
				return array(
					'success'   => false,
					'errorCode' => 150010189,
					'errorMsg'  => 'The sku and goods not match',
				);
			}
		);

		$product = $this->published_product(
			array(
				'manage_stock'   => true,
				'stock_quantity' => 4,
			)
		);

		$this->assertFalse( $this->sync->sync( $product ) );
		$this->assertSame( '', wc_get_product( $product->get_id() )->get_meta( SkuMap::META_MAP ) );
	}

	/**
	 * A failed push does not record the levels as accepted.
	 *
	 * Otherwise the change-detection short circuit would suppress the retry and
	 * the shop would silently stop syncing.
	 *
	 * @return void
	 */
	public function test_a_failed_push_is_retried_next_time() {
		$this->fake_api(
			function () {
				return array(
					'success'   => false,
					'errorCode' => 150010003,
					'errorMsg'  => 'Invalid Request Parameters',
				);
			}
		);

		$product = $this->published_product(
			array(
				'manage_stock'   => true,
				'stock_quantity' => 4,
			)
		);

		$this->assertFalse( $this->sync->sync( $product ) );
		$this->assertSame( array(), $this->sync->last_sent( wc_get_product( $product->get_id() ) ) );
	}
}
