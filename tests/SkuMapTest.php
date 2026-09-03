<?php
/**
 * Tests for the WooCommerce to Temu SKU mapping.
 *
 * @package WooTemuSync
 */

namespace WooTemuSync\Tests;

use WC_Product_Simple;
use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Product_Variation;
use WooTemuSync\Api\Credentials;
use WooTemuSync\Api\Region;
use WooTemuSync\Logger;
use WooTemuSync\Product\Mapper;
use WooTemuSync\Product\Publisher;
use WooTemuSync\Sync\SkuMap;
use WP_UnitTestCase;

/**
 * Covers identifier matching, the single-SKU fallback and the refusal to guess.
 */
class SkuMapTest extends WP_UnitTestCase {

	/**
	 * SKU map under test.
	 *
	 * @var SkuMap
	 */
	private $map;

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

		$this->map = new SkuMap( $credentials, new Mapper(), new Logger() );
	}

	/**
	 * Clear state between tests.
	 *
	 * @return void
	 */
	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		delete_option( Credentials::OPTION );

		parent::tear_down();
	}

	/**
	 * Answer the listing call with a scripted goods list.
	 *
	 * @param array $goods_list The goodsList member.
	 * @return void
	 */
	private function fake_listing( array $goods_list ) {
		add_filter(
			'pre_http_request',
			function ( $pre, $args ) use ( $goods_list ) {
				$this->sent[] = json_decode( $args['body'], true );

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
							'result'    => array( 'goodsList' => $goods_list ),
						)
					),
				);
			},
			10,
			2
		);
	}

	/**
	 * A published simple product.
	 *
	 * @return \WC_Product
	 */
	private function simple_published() {
		$product = new WC_Product_Simple();
		$product->set_name( 'Mapped product' );
		$product->set_regular_price( '10.00' );
		$product->update_meta_data( Publisher::META_GOODS_ID, 700300 );
		$product->save();

		return wc_get_product( $product->get_id() );
	}

	/**
	 * A published variable product with two priced variations.
	 *
	 * @return array{0:\WC_Product,1:\WC_Product,2:\WC_Product} Parent, first, second.
	 */
	private function variable_published() {
		$colour = new WC_Product_Attribute();
		$colour->set_name( 'Colour' );
		$colour->set_options( array( 'Red', 'Blue' ) );
		$colour->set_visible( true );
		$colour->set_variation( true );

		$parent = new WC_Product_Variable();
		$parent->set_name( 'Mapped variable' );
		$parent->set_attributes( array( $colour ) );
		$parent->update_meta_data( Publisher::META_GOODS_ID, 700301 );
		$parent->save();

		$red = new WC_Product_Variation();
		$red->set_parent_id( $parent->get_id() );
		$red->set_attributes( array( 'colour' => 'Red' ) );
		$red->set_regular_price( '10.00' );
		$red->save();

		$blue = new WC_Product_Variation();
		$blue->set_parent_id( $parent->get_id() );
		$blue->set_attributes( array( 'colour' => 'Blue' ) );
		$blue->set_regular_price( '12.00' );
		$blue->save();

		return array( wc_get_product( $parent->get_id() ), $red, $blue );
	}

	/**
	 * The listing is requested by our own identifier, not Temu's.
	 *
	 * @return void
	 */
	public function test_listing_is_requested_by_external_identifier() {
		$product = $this->simple_published();
		$this->fake_listing( array() );

		$this->map->refresh( $product );

		$this->assertSame( 'temu.local.goods.list.retrieve', $this->sent[0]['type'] );
		$this->assertSame( array( 'wc-' . $product->get_id() ), $this->sent[0]['outGoodsSnList'] );
	}

	/**
	 * When Temu echoes our external SKU ids, every variation maps exactly.
	 *
	 * @return void
	 */
	public function test_variations_map_by_external_identifier() {
		list( $parent, $red, $blue ) = $this->variable_published();

		$this->fake_listing(
			array(
				array(
					'goodsId'     => 700301,
					'outGoodsSn'  => 'wc-' . $parent->get_id(),
					'skuInfoList' => array(
						array(
							'skuId' => 9001,
							'skuSn' => 'wc-sku-' . $red->get_id(),
						),
						array(
							'skuId' => 9002,
							'skuSn' => 'wc-sku-' . $blue->get_id(),
						),
					),
				),
			)
		);

		$this->assertSame(
			array(
				$red->get_id()  => 9001,
				$blue->get_id() => 9002,
			),
			$this->map->refresh( $parent )
		);
	}

	/**
	 * A single-SKU product is paired without ambiguity even when the identifier
	 * does not come back.
	 *
	 * One variation and one returned SKU can only mean one thing.
	 *
	 * @return void
	 */
	public function test_single_sku_product_falls_back_to_the_only_match() {
		$product = $this->simple_published();

		$this->fake_listing(
			array(
				array(
					'goodsId'     => 700300,
					'outGoodsSn'  => 'wc-' . $product->get_id(),
					'skuInfoList' => array(
						array(
							'skuId' => 9100,
							'skuSn' => 'TEMU-INTERNAL-1',
						),
					),
				),
			)
		);

		$this->assertSame( array( $product->get_id() => 9100 ), $this->map->refresh( $product ) );
	}

	/**
	 * A multi-SKU listing that cannot be matched is left unmapped.
	 *
	 * This is the safety property of the class. Pairing by position or by guess
	 * would push one variation's stock onto another, which is a far worse
	 * failure than not syncing at all.
	 *
	 * @return void
	 */
	public function test_ambiguous_multi_sku_listing_is_refused() {
		list( $parent ) = $this->variable_published();

		$this->fake_listing(
			array(
				array(
					'goodsId'     => 700301,
					'outGoodsSn'  => 'wc-' . $parent->get_id(),
					'skuInfoList' => array(
						array(
							'skuId' => 9201,
							'skuSn' => 'TEMU-INTERNAL-1',
						),
						array(
							'skuId' => 9202,
							'skuSn' => 'TEMU-INTERNAL-2',
						),
					),
				),
			)
		);

		$this->assertSame( array(), $this->map->refresh( $parent ) );
		$this->assertSame( '', wc_get_product( $parent->get_id() )->get_meta( SkuMap::META_MAP ) );
	}

	/**
	 * A partial match is refused too: half a map is not a map.
	 *
	 * @return void
	 */
	public function test_partial_match_is_refused() {
		list( $parent, $red ) = $this->variable_published();

		$this->fake_listing(
			array(
				array(
					'goodsId'     => 700301,
					'outGoodsSn'  => 'wc-' . $parent->get_id(),
					'skuInfoList' => array(
						array(
							'skuId' => 9301,
							'skuSn' => 'wc-sku-' . $red->get_id(),
						),
						array(
							'skuId' => 9302,
							'skuSn' => 'TEMU-INTERNAL-2',
						),
					),
				),
			)
		);

		$this->assertSame( array(), $this->map->refresh( $parent ) );
	}

	/**
	 * A listing for a different product is ignored.
	 *
	 * @return void
	 */
	public function test_a_listing_for_another_product_is_ignored() {
		$product = $this->simple_published();

		$this->fake_listing(
			array(
				array(
					'goodsId'     => 999999,
					'outGoodsSn'  => 'wc-somebody-else',
					'skuInfoList' => array( array( 'skuId' => 9400 ) ),
				),
			)
		);

		$this->assertSame( array(), $this->map->refresh( $product ) );
	}

	/**
	 * A resolved map is cached and reused rather than refetched every push.
	 *
	 * @return void
	 */
	public function test_a_resolved_map_is_cached() {
		$product = $this->simple_published();

		$this->fake_listing(
			array(
				array(
					'goodsId'     => 700300,
					'outGoodsSn'  => 'wc-' . $product->get_id(),
					'skuInfoList' => array( array( 'skuId' => 9500 ) ),
				),
			)
		);

		$this->assertSame( array( $product->get_id() => 9500 ), $this->map->get( $product ) );
		$this->assertCount( 1, $this->sent );

		$this->assertSame(
			array( $product->get_id() => 9500 ),
			$this->map->get( wc_get_product( $product->get_id() ) )
		);
		$this->assertCount( 1, $this->sent, 'A cached map must not cost another API call.' );
	}

	/**
	 * Forgetting a map forces the next lookup to refetch.
	 *
	 * @return void
	 */
	public function test_forget_clears_the_cache() {
		$product = $this->simple_published();

		$this->fake_listing(
			array(
				array(
					'goodsId'     => 700300,
					'outGoodsSn'  => 'wc-' . $product->get_id(),
					'skuInfoList' => array( array( 'skuId' => 9600 ) ),
				),
			)
		);

		$this->map->get( $product );
		$this->map->forget( wc_get_product( $product->get_id() ) );

		$this->map->get( wc_get_product( $product->get_id() ) );

		$this->assertCount( 2, $this->sent );
	}

	/**
	 * An unpublished product needs no lookup at all.
	 *
	 * @return void
	 */
	public function test_unpublished_products_are_not_looked_up() {
		$product = new WC_Product_Simple();
		$product->set_name( 'Never published' );
		$product->save();

		$this->fake_listing( array() );

		$this->assertSame( array(), $this->map->refresh( wc_get_product( $product->get_id() ) ) );
		$this->assertCount( 0, $this->sent );
	}
}
