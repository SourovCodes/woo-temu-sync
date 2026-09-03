<?php
/**
 * Tests for pushing base prices to Temu.
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
use WooTemuSync\Sync\PriceSync;
use WooTemuSync\Sync\SkuMap;
use WP_UnitTestCase;

/**
 * Covers the whitelist gate, the price order workflow and payload shape.
 */
class PriceSyncTest extends WP_UnitTestCase {

	/**
	 * Credential store.
	 *
	 * @var Credentials
	 */
	private $credentials;

	/**
	 * Decoded request bodies, in order.
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

		$this->sent        = array();
		$this->credentials = new Credentials();
		$this->credentials->update(
			array(
				'app_key'      => 'appkey123',
				'app_secret'   => 'secret456',
				'access_token' => 'token789',
				'region'       => Region::EU,
			)
		);

		update_option( 'woocommerce_currency', 'EUR' );
	}

	/**
	 * Clear filters and stored state between tests.
	 *
	 * @return void
	 */
	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'wtsync_base_price' );
		delete_option( Credentials::OPTION );

		parent::tear_down();
	}

	/**
	 * Build the sync against the current credentials.
	 *
	 * @return PriceSync
	 */
	private function sync() {
		$logger = new Logger();

		return new PriceSync(
			$this->credentials,
			new SkuMap( $this->credentials, new Mapper(), $logger ),
			$logger
		);
	}

	/**
	 * Answer API calls by method name.
	 *
	 * @param array $by_type Map of API method to result member, or a full envelope.
	 * @return void
	 */
	private function fake_api( array $by_type ) {
		add_filter(
			'pre_http_request',
			function ( $pre, $args ) use ( $by_type ) {
				$body         = json_decode( $args['body'], true );
				$this->sent[] = $body;

				$type     = $body['type'];
				$response = isset( $by_type[ $type ] ) ? $by_type[ $type ] : array( 'result' => array() );

				if ( ! isset( $response['success'] ) ) {
					$response = array(
						'success'   => true,
						'errorCode' => 1000000,
						'result'    => isset( $response['result'] ) ? $response['result'] : $response,
					);
				}

				return array(
					'headers'  => array(),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode( $response ),
				);
			},
			10,
			2
		);
	}

	/**
	 * A published product with a pre-resolved SKU map.
	 *
	 * @param string $price Regular price.
	 * @return \WC_Product
	 */
	private function published_product( $price = '24.50' ) {
		$product = new WC_Product_Simple();
		$product->set_name( 'Priced product' );
		$product->set_regular_price( $price );
		$product->update_meta_data( Publisher::META_GOODS_ID, 700200 );
		$product->save();

		$fresh = wc_get_product( $product->get_id() );
		$fresh->update_meta_data( SkuMap::META_MAP, array( $fresh->get_id() => 51481625557677 ) );
		$fresh->update_meta_data( SkuMap::META_MAP_AT, time() );
		$fresh->save();

		return wc_get_product( $product->get_id() );
	}

	/**
	 * The names of the API methods that were called.
	 *
	 * @return array
	 */
	private function called_methods() {
		return wp_list_pluck( $this->sent, 'type' );
	}

	/**
	 * A connection that was never told its scopes attempts the call anyway.
	 *
	 * An empty scope list means unknown, not denied; the API is the authority.
	 *
	 * @return void
	 */
	public function test_unknown_scopes_do_not_block() {
		$this->assertTrue( $this->sync()->is_available() );
	}

	/**
	 * A connection whose scopes exclude the price API is blocked before any call.
	 *
	 * Temu grants the price API per store through their business team, so most
	 * sellers will land here and deserve to be told why rather than watching
	 * prices silently not move.
	 *
	 * @return void
	 */
	public function test_missing_price_scope_blocks_with_an_explanation() {
		$this->credentials->update(
			array(
				'api_scopes' => array( 'bg.local.goods.stock.edit', 'bg.order.list.get' ),
			)
		);

		$this->fake_api( array() );

		$sync    = $this->sync();
		$product = $this->published_product();

		$this->assertFalse( $sync->is_available() );
		$this->assertFalse( $sync->sync( $product ) );
		$this->assertCount( 0, $this->sent, 'A known-missing scope must not spend an API call.' );

		$reason = wc_get_product( $product->get_id() )->get_meta( PriceSync::META_BLOCKED );
		$this->assertStringContainsString( 'whitelist', strtolower( $reason ) );
	}

	/**
	 * With the scope granted, the change is sent in Temu's nested shape.
	 *
	 * @return void
	 */
	public function test_price_change_payload() {
		$this->credentials->update(
			array( 'api_scopes' => array( PriceSync::METHOD_CHANGE_PRICE ) )
		);

		$this->fake_api(
			array(
				PriceSync::METHOD_ORDER_QUERY  => array( 'priceAuditList' => array() ),
				PriceSync::METHOD_CHANGE_PRICE => array(),
			)
		);

		$this->assertTrue( $this->sync()->sync( $this->published_product( '24.50' ) ) );

		$change = $this->sent[ count( $this->sent ) - 1 ];

		$this->assertSame( PriceSync::METHOD_CHANGE_PRICE, $change['type'] );
		$this->assertSame( 700200, $change['goodsId'] );

		$item = $change['changeSkuPriceDTOList'][0]['skuChangePriceBaseDTOList'][0];
		$this->assertSame( 51481625557677, $item['skuId'] );
		$this->assertSame( '24.50', $item['newSupplierPrice']['amount'] );
		$this->assertSame( 'EUR', $item['newSupplierPrice']['currency'] );
		$this->assertIsString( $item['newSupplierPrice']['amount'], 'Money must never be sent as a JSON number.' );
		$this->assertNotEmpty( $change['changeSkuPriceDTOList'][0]['reason'] );
	}

	/**
	 * A price still under review blocks the change.
	 *
	 * Temu only accepts a modification while the workflow sits in a settled
	 * state; sending one during review is refused at their end.
	 *
	 * @return void
	 */
	public function test_a_price_under_review_blocks_the_change() {
		$this->credentials->update(
			array( 'api_scopes' => array( PriceSync::METHOD_CHANGE_PRICE ) )
		);

		$this->fake_api(
			array(
				PriceSync::METHOD_ORDER_QUERY => array(
					'priceAuditList' => array(
						array(
							'goodsId' => 700200,
							'status'  => PriceSync::STATUS_UNDER_REVIEW,
						),
					),
				),
			)
		);

		$product = $this->published_product();

		$this->assertFalse( $this->sync()->sync( $product ) );
		$this->assertNotContains( PriceSync::METHOD_CHANGE_PRICE, $this->called_methods() );
		$this->assertNotSame( '', wc_get_product( $product->get_id() )->get_meta( PriceSync::META_BLOCKED ) );
	}

	/**
	 * A price waiting on the merchant also blocks the change.
	 *
	 * @return void
	 */
	public function test_a_price_awaiting_the_merchant_blocks_the_change() {
		$this->credentials->update(
			array( 'api_scopes' => array( PriceSync::METHOD_CHANGE_PRICE ) )
		);

		$this->fake_api(
			array(
				PriceSync::METHOD_ORDER_QUERY => array(
					'priceAuditList' => array(
						array( 'status' => PriceSync::STATUS_AWAITING_MERCHANT ),
					),
				),
			)
		);

		$this->assertFalse( $this->sync()->sync( $this->published_product() ) );
		$this->assertNotContains( PriceSync::METHOD_CHANGE_PRICE, $this->called_methods() );
	}

	/**
	 * A settled workflow lets the change through.
	 *
	 * @return void
	 */
	public function test_a_settled_workflow_allows_the_change() {
		$this->credentials->update(
			array( 'api_scopes' => array( PriceSync::METHOD_CHANGE_PRICE ) )
		);

		$this->fake_api(
			array(
				PriceSync::METHOD_ORDER_QUERY  => array(
					'priceAuditList' => array(
						array( 'status' => PriceSync::STATUS_PASSED ),
					),
				),
				PriceSync::METHOD_CHANGE_PRICE => array(),
			)
		);

		$this->assertTrue( $this->sync()->sync( $this->published_product() ) );
		$this->assertContains( PriceSync::METHOD_CHANGE_PRICE, $this->called_methods() );
	}

	/**
	 * Unchanged prices cost no API calls at all.
	 *
	 * @return void
	 */
	public function test_unchanged_prices_are_not_resent() {
		$this->credentials->update(
			array( 'api_scopes' => array( PriceSync::METHOD_CHANGE_PRICE ) )
		);

		$this->fake_api(
			array(
				PriceSync::METHOD_ORDER_QUERY  => array( 'priceAuditList' => array() ),
				PriceSync::METHOD_CHANGE_PRICE => array(),
			)
		);

		$product = $this->published_product();
		$this->assertTrue( $this->sync()->sync( $product ) );

		$before = count( $this->sent );
		$this->assertFalse( $this->sync()->sync( wc_get_product( $product->get_id() ) ) );
		$this->assertCount( $before, $this->sent, 'A settled price must not even query the workflow.' );
	}

	/**
	 * The base price is filterable, because Temu\'s base price is what the seller
	 * receives rather than what the buyer pays.
	 *
	 * @return void
	 */
	public function test_base_price_is_filterable() {
		$this->credentials->update(
			array( 'api_scopes' => array( PriceSync::METHOD_CHANGE_PRICE ) )
		);

		add_filter(
			'wtsync_base_price',
			static function ( $price ) {
				// Absorb a 10% marketplace commission.
				return (string) ( (float) $price * 0.9 );
			}
		);

		$product = $this->published_product( '20.00' );

		$this->assertSame( array( $product->get_id() => '18.00' ), $this->sync()->prices( $product ) );
	}

	/**
	 * A rejection is translated into something the merchant can act on.
	 *
	 * @return void
	 */
	public function test_an_unconfirmed_earlier_price_is_explained() {
		$this->credentials->update(
			array( 'api_scopes' => array( PriceSync::METHOD_CHANGE_PRICE ) )
		);

		$this->fake_api(
			array(
				PriceSync::METHOD_ORDER_QUERY  => array( 'priceAuditList' => array() ),
				PriceSync::METHOD_CHANGE_PRICE => array(
					'success'   => false,
					'errorCode' => 150011103,
					'errorMsg'  => 'The price imported from your ERP system has not been confirmed.',
				),
			)
		);

		$product = $this->published_product();

		$this->assertFalse( $this->sync()->sync( $product ) );

		$reason = wc_get_product( $product->get_id() )->get_meta( PriceSync::META_BLOCKED );
		$this->assertStringContainsString( 'Seller Center', $reason );
	}

	/**
	 * A promotion cool-down is explained rather than reported as a generic error.
	 *
	 * @return void
	 */
	public function test_a_promotion_cooldown_is_explained() {
		$this->credentials->update(
			array( 'api_scopes' => array( PriceSync::METHOD_CHANGE_PRICE ) )
		);

		$this->fake_api(
			array(
				PriceSync::METHOD_ORDER_QUERY  => array( 'priceAuditList' => array() ),
				PriceSync::METHOD_CHANGE_PRICE => array(
					'success'   => false,
					'errorCode' => 150010233,
					'errorMsg'  => 'Activity cool-down applies.',
				),
			)
		);

		$product = $this->published_product();
		$this->assertFalse( $this->sync()->sync( $product ) );

		$reason = wc_get_product( $product->get_id() )->get_meta( PriceSync::META_BLOCKED );
		$this->assertStringContainsString( 'promotion', strtolower( $reason ) );
	}

	/**
	 * A failed push does not record the price as accepted, so it retries.
	 *
	 * @return void
	 */
	public function test_a_failed_push_is_retried_next_time() {
		$this->credentials->update(
			array( 'api_scopes' => array( PriceSync::METHOD_CHANGE_PRICE ) )
		);

		$this->fake_api(
			array(
				PriceSync::METHOD_ORDER_QUERY  => array( 'priceAuditList' => array() ),
				PriceSync::METHOD_CHANGE_PRICE => array(
					'success'   => false,
					'errorCode' => 150011101,
					'errorMsg'  => 'Price change exceeds the allowed range.',
				),
			)
		);

		$product = $this->published_product();
		$this->assertFalse( $this->sync()->sync( $product ) );
		$this->assertSame( array(), $this->sync()->last_sent( wc_get_product( $product->get_id() ) ) );
	}
}
