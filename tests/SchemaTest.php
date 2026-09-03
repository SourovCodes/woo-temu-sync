<?php
/**
 * Tests for the Temu product schema helpers.
 *
 * @package WooTemuSync
 */

namespace WooTemuSync\Tests;

use WooTemuSync\Product\Schema;
use WP_UnitTestCase;

/**
 * Covers the field limits and value formatting Temu enforces.
 */
class SchemaTest extends WP_UnitTestCase {

	/**
	 * Money is formatted as a fixed-precision string.
	 *
	 * @return void
	 */
	public function test_money_formatting() {
		$this->assertSame( '19.90', Schema::money( 19.9 ) );
		$this->assertSame( '20.00', Schema::money( 20 ) );
		$this->assertSame( '1234.50', Schema::money( 1234.5 ), 'No thousands separator.' );
		$this->assertSame( '1000', Schema::money( 1000, 0 ), 'Zero-decimal currencies exist.' );
	}

	/**
	 * Measurements drop pointless decimals but never significant digits.
	 *
	 * Trimming trailing zeros from a whole number would turn 350 g into 35 g.
	 *
	 * @return void
	 */
	public function test_measurement_formatting() {
		$this->assertSame( '22', Schema::measurement( 22.0 ) );
		$this->assertSame( '33.5', Schema::measurement( 33.5 ) );
		$this->assertSame( '350', Schema::measurement( 350, 0 ) );
		$this->assertSame( '100', Schema::measurement( 100, 0 ) );
	}

	/**
	 * Temu reads zero as "not supplied" and substitutes a default, so a value
	 * that rounds away must be reported as absent rather than as zero.
	 *
	 * @return void
	 */
	public function test_measurement_rejects_values_that_are_not_real() {
		$this->assertSame( '', Schema::measurement( 0 ) );
		$this->assertSame( '', Schema::measurement( 0.001, 2 ) );
		$this->assertSame( '', Schema::measurement( -5 ) );
	}

	/**
	 * Barcode types are inferred from the code, since WooCommerce stores them all
	 * in one field.
	 *
	 * @return void
	 */
	public function test_barcode_type_detection() {
		$this->assertSame( 'EAN', Schema::detect_barcode_type( '4006381333931' ) );
		$this->assertSame( 'UPC', Schema::detect_barcode_type( '012345678905' ) );
		$this->assertSame( 'ISBN', Schema::detect_barcode_type( '9783161484100' ), 'The 978 prefix is bookland.' );
		$this->assertSame( 'GTIN-14', Schema::detect_barcode_type( '10012345678902' ) );
		$this->assertSame( 'EAN', Schema::detect_barcode_type( '40063813' ) );
		$this->assertSame( '', Schema::detect_barcode_type( 'ABC' ) );
	}

	/**
	 * Names are flattened to plain text within their limit.
	 *
	 * @return void
	 */
	public function test_plain_text() {
		$this->assertSame( 'Coffee mug', trim( Schema::plain_text( 'Coffee mug 🎉', 100 ) ) );
		$this->assertSame( 'Bold name', Schema::plain_text( '<b>Bold</b> name', 100 ) );
		$this->assertSame( 'Kaffeetasse groß', Schema::plain_text( 'Kaffeetasse groß', 100 ) );
		$this->assertSame( 10, mb_strlen( Schema::plain_text( str_repeat( 'a', 50 ), 10 ) ) );
	}

	/**
	 * Only URLs Temu's crawler can actually reach are sent.
	 *
	 * A staging site would otherwise produce products stuck in Draft with no
	 * error recorded anywhere.
	 *
	 * @return void
	 */
	public function test_image_url_reachability() {
		$this->assertTrue( Schema::is_public_image_url( 'https://shop.example.com/a.jpg' ) );

		$this->assertFalse( Schema::is_public_image_url( 'http://localhost/a.jpg' ) );
		$this->assertFalse( Schema::is_public_image_url( 'http://shop.local/a.jpg' ) );
		$this->assertFalse( Schema::is_public_image_url( 'http://shop.test/a.jpg' ) );
		$this->assertFalse( Schema::is_public_image_url( 'http://192.168.1.10/a.jpg' ) );
		$this->assertFalse( Schema::is_public_image_url( 'http://staging/a.jpg' ) );
		$this->assertFalse( Schema::is_public_image_url( 'ftp://example.com/a.jpg' ) );
		$this->assertFalse( Schema::is_public_image_url( '' ) );
	}
}
