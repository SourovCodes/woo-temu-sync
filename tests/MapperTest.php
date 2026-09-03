<?php
/**
 * Tests for the WooCommerce to Temu product mapper.
 *
 * @package WooTemuSync
 */

namespace WooTemuSync\Tests;

use WC_Product_Attribute;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;
use WooTemuSync\Product\Mapper;
use WooTemuSync\Product\MappingException;
use WooTemuSync\Product\Schema;
use WP_UnitTestCase;

/**
 * Covers the payload built for temu.local.goods.v3.add.
 */
class MapperTest extends WP_UnitTestCase {

	/**
	 * Mapper under test.
	 *
	 * @var Mapper
	 */
	private $mapper;

	/**
	 * Set the store up as a euro shop working in kilograms and centimetres.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->mapper = new Mapper();

		update_option( 'woocommerce_currency', 'EUR' );
		update_option( 'woocommerce_weight_unit', 'kg' );
		update_option( 'woocommerce_dimension_unit', 'cm' );

		/*
		 * The development site is served from a .local host, and the mapper
		 * correctly refuses to hand those URLs to Temu's image crawler. Point the
		 * uploads directory at a publicly shaped host so these tests exercise the
		 * real reachability check rather than filtering it away; SchemaTest covers
		 * the rejection side.
		 */
		add_filter( 'upload_dir', array( $this, 'public_upload_dir' ) );
	}

	/**
	 * Serve uploads from a host that looks reachable from outside.
	 *
	 * @param array $dirs Upload directory parts.
	 * @return array
	 */
	public function public_upload_dir( $dirs ) {
		$dirs['baseurl'] = 'https://shop.example.com/wp-content/uploads';
		$dirs['url']     = $dirs['baseurl'] . ( isset( $dirs['subdir'] ) ? $dirs['subdir'] : '' );

		return $dirs;
	}

	/**
	 * Drop the upload directory filter between tests.
	 *
	 * @return void
	 */
	public function tear_down() {
		remove_filter( 'upload_dir', array( $this, 'public_upload_dir' ) );
		remove_all_filters( 'wtsync_external_goods_id' );

		parent::tear_down();
	}

	/**
	 * Create an attachment that resolves to a public URL.
	 *
	 * @param string $file File name.
	 * @return int Attachment id.
	 */
	private function attachment( $file = 'main.jpg' ) {
		return self::factory()->attachment->create_object(
			$file,
			0,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_type'      => 'attachment',
			)
		);
	}

	/**
	 * A saleable simple product with an image.
	 *
	 * @param array $props Extra properties to set.
	 * @return WC_Product_Simple
	 */
	private function simple_product( array $props = array() ) {
		$product = new WC_Product_Simple();
		$product->set_name( 'Enamel Coffee Mug 500ml' );
		$product->set_regular_price( '19.00' );
		$product->set_image_id( $this->attachment() );

		foreach ( $props as $setter => $value ) {
			$product->{"set_{$setter}"}( $value );
		}

		$product->save();

		return wc_get_product( $product->get_id() );
	}

	/**
	 * The identifiers Temu keys updates off must be stable and prefixed.
	 *
	 * @return void
	 */
	public function test_external_identifiers_are_stable_and_prefixed() {
		$product = $this->simple_product();
		$payload = $this->mapper->to_payload( $product );

		$this->assertSame( 'wc-' . $product->get_id(), $payload['goodsBasic']['externalGoodsId'] );
		$this->assertSame( 'wc-sku-' . $product->get_id(), $payload['skuList'][0]['externalSkuId'] );
	}

	/**
	 * The basic block carries name, type and description.
	 *
	 * @return void
	 */
	public function test_goods_basic() {
		$product = $this->simple_product(
			array( 'description' => '<p>Manufacturer: Muster GmbH, Berlin, Germany.</p>' )
		);

		$basic = $this->mapper->to_payload( $product )['goodsBasic'];

		$this->assertSame( 'Enamel Coffee Mug 500ml', $basic['goodsName'] );
		$this->assertSame( Schema::TYPE_NORMAL, $basic['productType'] );
		$this->assertStringContainsString( 'Muster GmbH', $basic['goodsDesc'] );
	}

	/**
	 * The description is sent whole because V3 mines it for compliance data.
	 *
	 * Temu extracts manufacturer and compliance attributes from the product
	 * prose, so trimming the description would quietly cost GPSR information.
	 *
	 * @return void
	 */
	public function test_long_description_is_preferred_over_the_short_one() {
		$product = $this->simple_product(
			array(
				'description'       => 'Full description with manufacturer details.',
				'short_description' => 'Short blurb.',
			)
		);

		$this->assertSame(
			'Full description with manufacturer details.',
			$this->mapper->to_payload( $product )['goodsBasic']['goodsDesc']
		);
	}

	/**
	 * The featured image leads the carousel and the gallery follows.
	 *
	 * @return void
	 */
	public function test_carousel_images() {
		$featured = $this->attachment( 'featured.jpg' );
		$gallery  = array( $this->attachment( 'g1.jpg' ), $this->attachment( 'g2.jpg' ) );

		$product = $this->simple_product();
		$product->set_image_id( $featured );
		$product->set_gallery_image_ids( $gallery );
		$product->save();

		$images = $this->mapper->to_payload( wc_get_product( $product->get_id() ) )['goodsBasic']['goodsCarouselImage'];

		$this->assertCount( 3, $images );
		$this->assertSame( wp_get_attachment_url( $featured ), $images[0] );
	}

	/**
	 * The sale price becomes basePrice and the regular price becomes listPrice.
	 *
	 * @return void
	 */
	public function test_sale_price_mapping() {
		$product = $this->simple_product( array( 'sale_price' => '14.50' ) );
		$price   = $this->mapper->to_payload( $product )['skuList'][0]['price'];

		$this->assertSame( '14.50', $price['basePrice']['amount'] );
		$this->assertSame( '19.00', $price['listPrice']['amount'] );
		$this->assertSame( 'EUR', $price['basePrice']['currency'] );
		$this->assertIsString( $price['basePrice']['amount'], 'Money must never be sent as a JSON number.' );
	}

	/**
	 * Nothing discounted means no struck-through reference price.
	 *
	 * @return void
	 */
	public function test_list_price_is_omitted_without_a_sale() {
		$product = $this->simple_product();

		$this->assertArrayNotHasKey(
			'listPrice',
			$this->mapper->to_payload( $product )['skuList'][0]['price']
		);
	}

	/**
	 * Managed stock is reported as-is.
	 *
	 * @return void
	 */
	public function test_managed_stock_quantity() {
		$product = $this->simple_product(
			array(
				'manage_stock'   => true,
				'stock_quantity' => 42,
			)
		);

		$this->assertSame( 42, $this->mapper->to_payload( $product )['skuList'][0]['quantity'] );
	}

	/**
	 * Unmanaged stock still has to be a number, or Temu lists it as sold out.
	 *
	 * @return void
	 */
	public function test_unmanaged_stock_quantity() {
		$in_stock = $this->simple_product();
		$this->assertSame( 100, $this->mapper->to_payload( $in_stock )['skuList'][0]['quantity'] );

		$sold_out = $this->simple_product( array( 'stock_status' => 'outofstock' ) );
		$this->assertSame( 0, $this->mapper->to_payload( $sold_out )['skuList'][0]['quantity'] );
	}

	/**
	 * Weight and dimensions are converted into Temu's grams and centimetres.
	 *
	 * @return void
	 */
	public function test_package_info_is_converted_from_store_units() {
		$product = $this->simple_product(
			array(
				'weight' => '0.35',
				'length' => '12',
				'width'  => '9',
				'height' => '10',
			)
		);

		$package = $this->mapper->to_payload( $product )['skuList'][0]['packageInfo'];

		$this->assertSame( '350', $package['weight'], '0.35 kg is 350 g, not 35.' );
		$this->assertSame( '12', $package['length'] );
		$this->assertSame( '9', $package['width'] );
		$this->assertSame( '10', $package['height'] );
	}

	/**
	 * An imperial store is converted rather than shipped out by a factor of 450.
	 *
	 * @return void
	 */
	public function test_package_info_from_an_imperial_store() {
		update_option( 'woocommerce_weight_unit', 'lbs' );
		update_option( 'woocommerce_dimension_unit', 'in' );

		$product = $this->simple_product(
			array(
				'weight' => '1',
				'length' => '10',
				'width'  => '5',
				'height' => '2',
			)
		);

		$package = $this->mapper->to_payload( $product )['skuList'][0]['packageInfo'];

		$this->assertSame( '454', $package['weight'] );
		$this->assertSame( '25.4', $package['length'] );
	}

	/**
	 * Half a package block is worse than none.
	 *
	 * Temu substitutes its own defaults for anything missing, so a real weight
	 * beside invented dimensions silently misprices shipping.
	 *
	 * @return void
	 */
	public function test_incomplete_package_info_is_omitted() {
		$product = $this->simple_product( array( 'weight' => '0.2' ) );

		$this->assertArrayNotHasKey(
			'packageInfo',
			$this->mapper->to_payload( $product )['skuList'][0]
		);
	}

	/**
	 * Product attributes are forwarded; variation attributes are not.
	 *
	 * @return void
	 */
	public function test_product_attributes() {
		$material = new WC_Product_Attribute();
		$material->set_name( 'Material' );
		$material->set_options( array( 'Enamel', 'Steel' ) );
		$material->set_visible( true );

		$colour = new WC_Product_Attribute();
		$colour->set_name( 'Colour' );
		$colour->set_options( array( 'Red' ) );
		$colour->set_variation( true );

		$product = $this->simple_product();
		$product->set_attributes( array( $material, $colour ) );
		$product->save();

		$payload = $this->mapper->to_payload( wc_get_product( $product->get_id() ) );
		$names   = wp_list_pluck( $payload['attributes'], 'name' );

		$this->assertContains( 'Material', $names );
		$this->assertNotContains( 'Colour', $names, 'Variation axes belong on the SKU, not the product.' );
		$this->assertSame( array( 'Enamel', 'Steel' ), $payload['attributes'][0]['value'] );
	}

	/**
	 * A variable product becomes one SKU per saleable variation.
	 *
	 * @return void
	 */
	public function test_variable_product_sku_list() {
		$colour = new WC_Product_Attribute();
		$colour->set_name( 'Colour' );
		$colour->set_options( array( 'Red', 'Blue' ) );
		$colour->set_visible( true );
		$colour->set_variation( true );

		$parent = new WC_Product_Variable();
		$parent->set_name( 'Enamel Mug' );
		$parent->set_image_id( $this->attachment( 'parent.jpg' ) );
		$parent->set_attributes( array( $colour ) );
		$parent->save();

		$red_image = $this->attachment( 'red.jpg' );

		$red = new WC_Product_Variation();
		$red->set_parent_id( $parent->get_id() );
		$red->set_attributes( array( 'colour' => 'Red' ) );
		$red->set_regular_price( '15.00' );
		$red->set_image_id( $red_image );
		$red->save();

		// A variation with no price cannot be sold and must be skipped.
		$blue = new WC_Product_Variation();
		$blue->set_parent_id( $parent->get_id() );
		$blue->set_attributes( array( 'colour' => 'Blue' ) );
		$blue->save();

		$payload = $this->mapper->to_payload( wc_get_product( $parent->get_id() ) );

		$this->assertCount( 1, $payload['skuList'], 'A variation with no price is not saleable.' );

		$sku = $payload['skuList'][0];
		$this->assertSame( 'wc-sku-' . $red->get_id(), $sku['externalSkuId'] );
		$this->assertSame( wp_get_attachment_url( $red_image ), $sku['images'][0] );
		$this->assertNotEmpty( $sku['variations'] );
		$this->assertSame( 'Red', $sku['variations'][0]['value'] );
		$this->assertIsString( $sku['variations'][0]['value'], 'variations[].value is a string, unlike attributes[].value.' );
	}

	/**
	 * A variation with no image of its own inherits the parent gallery.
	 *
	 * SKU images are required, so losing the variation entirely would be worse.
	 *
	 * @return void
	 */
	public function test_variation_falls_back_to_the_parent_image() {
		$parent_image = $this->attachment( 'parent.jpg' );

		$parent = new WC_Product_Variable();
		$parent->set_name( 'Inherit images' );
		$parent->set_image_id( $parent_image );
		$parent->save();

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_regular_price( '15.00' );
		$variation->save();

		$payload = $this->mapper->to_payload( wc_get_product( $parent->get_id() ) );

		$this->assertSame( wp_get_attachment_url( $parent_image ), $payload['skuList'][0]['images'][0] );
	}

	/**
	 * A product with no reachable image is refused locally.
	 *
	 * Temu downloads images from the URLs we send, and a failure there leaves the
	 * listing stuck in Draft with no error the merchant can see.
	 *
	 * @return void
	 */
	public function test_product_without_images_is_refused() {
		$product = new WC_Product_Simple();
		$product->set_name( 'No image' );
		$product->set_regular_price( '5.00' );
		$product->save();

		$this->expectException( MappingException::class );

		$this->mapper->to_payload( wc_get_product( $product->get_id() ) );
	}

	/**
	 * A nameless product is refused rather than sent for Temu to reject.
	 *
	 * @return void
	 */
	public function test_nameless_product_is_refused() {
		// The description matters: wp_insert_post() refuses to save a post whose
		// title, content and excerpt are all empty, so without it the blanked name
		// would never reach the database and the test would prove nothing.
		$product = $this->simple_product( array( 'description' => 'A product that lost its name.' ) );
		$product->set_name( '   ' );
		$product->save();

		$this->assertSame( '', trim( wc_get_product( $product->get_id() )->get_name() ) );

		$this->expectException( MappingException::class );

		$this->mapper->to_payload( wc_get_product( $product->get_id() ) );
	}

	/**
	 * The external identifiers are filterable for multi-store setups.
	 *
	 * @return void
	 */
	public function test_external_goods_id_is_filterable() {
		add_filter(
			'wtsync_external_goods_id',
			static function () {
				return 'shop-a-123';
			}
		);

		$payload = $this->mapper->to_payload( $this->simple_product() );

		$this->assertSame( 'shop-a-123', $payload['goodsBasic']['externalGoodsId'] );

		remove_all_filters( 'wtsync_external_goods_id' );
	}
}
