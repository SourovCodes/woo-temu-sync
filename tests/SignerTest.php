<?php
/**
 * Tests for the Temu request signer.
 *
 * @package WooTemuSync
 */

namespace WooTemuSync\Tests;

use InvalidArgumentException;
use WooTemuSync\Api\Signer;
use WP_UnitTestCase;

/**
 * Covers the MD5 signing algorithm Temu validates every request against.
 */
class SignerTest extends WP_UnitTestCase {

	/**
	 * The app secret from Temu's published worked example.
	 *
	 * Synthetic: it is the value printed in the public documentation, not a
	 * credential belonging to any store.
	 *
	 * @var string
	 */
	private const DOC_SECRET = 'c7e0a1a63542be4de3cb5488f9fba8149e8fc290';

	/**
	 * Remove any filters a test added.
	 *
	 * @return void
	 */
	public function tear_down() {
		remove_all_filters( 'wtsync_json_flags' );
		parent::tear_down();
	}

	/**
	 * The parameters from Temu's worked example.
	 *
	 * @return array Parameter set.
	 */
	private function documented_params() {
		return array(
			'type'            => 'bg.logistics.shipment.confirm',
			'app_key'         => 'f9d5cc9313893a20d5aa85c654e8f503',
			'access_token'    => '2nifvmpyymvypwmcms5ct4uqqudrwgpmzbcnmkt1jzjkuaf3x56iixym',
			'data_type'       => 'JSON',
			'timestamp'       => 1711009072,
			'sendType'        => 0,
			'sendRequestList' => '[{"orderSendInfoList":[{"quantity":1,"orderSn":"211-21905473070712792","parentOrderSn":"PO-211-21905452099192792","goodsId":601099548666279,"skuId":17592352673534}],"carrierId":"699272611","trackingNumber":"270324232756"}]',
		);
	}

	/**
	 * The published example must reproduce the published signature.
	 *
	 * If this ever fails, Temu changed the algorithm and every other call in the
	 * plugin is broken too. It is the canary for the whole API layer.
	 *
	 * @return void
	 */
	public function test_reproduces_the_documented_signature() {
		$this->assertSame(
			'4CCF219942D4180C6DDA3CE36C1B838F',
			Signer::sign( $this->documented_params(), self::DOC_SECRET )
		);
	}

	/**
	 * The secret wraps the base string at both ends.
	 *
	 * @return void
	 */
	public function test_base_string_is_wrapped_in_the_app_secret() {
		$base = Signer::base_string( $this->documented_params(), self::DOC_SECRET );

		$this->assertStringStartsWith( self::DOC_SECRET, $base );
		$this->assertStringEndsWith( self::DOC_SECRET, $base );
	}

	/**
	 * Only the sorted order matters, not the order parameters were built in.
	 *
	 * @return void
	 */
	public function test_signature_is_independent_of_input_order() {
		$params = $this->documented_params();

		$this->assertSame(
			Signer::sign( $params, self::DOC_SECRET ),
			Signer::sign( array_reverse( $params, true ), self::DOC_SECRET )
		);
	}

	/**
	 * An injected signature must never feed back into its own base string.
	 *
	 * @return void
	 */
	public function test_sign_parameter_is_excluded_from_the_base_string() {
		$params           = $this->documented_params();
		$tampered         = $params;
		$tampered['sign'] = 'DEADBEEF';

		$this->assertSame(
			Signer::sign( $params, self::DOC_SECRET ),
			Signer::sign( $tampered, self::DOC_SECRET )
		);
	}

	/**
	 * Null parameters are dropped rather than signed as empty strings.
	 *
	 * @return void
	 */
	public function test_null_parameters_are_dropped() {
		$params               = $this->documented_params();
		$with_null            = $params;
		$with_null['version'] = null;

		$this->assertSame(
			Signer::sign( $params, self::DOC_SECRET ),
			Signer::sign( $with_null, self::DOC_SECRET )
		);
	}

	/**
	 * Keys sort byte-wise, not with PHP's numeric-aware default.
	 *
	 * @return void
	 */
	public function test_keys_sort_byte_wise() {
		$params = array(
			'10' => 'a',
			'9'  => 'b',
			'1'  => 'c',
		);

		$this->assertSame( 'x1c10a9bx', Signer::base_string( $params, 'x' ) );
	}

	/**
	 * Scalars are coerced the way the platform expects to read them.
	 *
	 * @return void
	 */
	public function test_scalar_encoding() {
		$this->assertSame( '0', Signer::encode_value( 0 ) );
		$this->assertSame( 'true', Signer::encode_value( true ) );
		$this->assertSame( 'false', Signer::encode_value( false ) );
		$this->assertSame( 'plain', Signer::encode_value( 'plain' ) );
	}

	/**
	 * Floats are refused rather than signed unreliably.
	 *
	 * 1.10 and 1.1 hash differently, and PHP's float-to-string conversion is
	 * precision and locale bound, so money has to arrive as a string.
	 *
	 * @return void
	 */
	public function test_floats_are_rejected() {
		$this->expectException( InvalidArgumentException::class );

		Signer::encode_value( 19.99 );
	}

	/**
	 * Nested encoding is stable, which is what keeps body and signature in step.
	 *
	 * @return void
	 */
	public function test_nested_encoding_is_stable() {
		$structure = array(
			array(
				'skuId'    => 17592352673534,
				'imageUrl' => 'https://img.temu.com/a/b/c.jpg',
				'title'    => 'Kaffeetasse groß – 500ml',
			),
		);

		$this->assertSame( Signer::encode_value( $structure ), Signer::encode_value( $structure ) );
	}

	/**
	 * Slashes and unicode stay unescaped by default.
	 *
	 * @return void
	 */
	public function test_nested_encoding_leaves_urls_and_unicode_readable() {
		$encoded = Signer::encode_value(
			array(
				'imageUrl' => 'https://img.temu.com/a/b/c.jpg',
				'title'    => 'Kaffeetasse groß',
			)
		);

		$this->assertStringContainsString( 'https://img.temu.com/a/b/c.jpg', $encoded );
		$this->assertStringContainsString( 'groß', $encoded );
	}

	/**
	 * The JSON flags are filterable, which is the escape hatch if Temu turns out
	 * to canonicalise differently from its own example.
	 *
	 * @return void
	 */
	public function test_json_flags_are_filterable() {
		add_filter( 'wtsync_json_flags', '__return_zero' );

		$this->assertStringContainsString(
			'https:\/\/img.temu.com',
			Signer::encode_value( array( 'imageUrl' => 'https://img.temu.com/a.jpg' ) )
		);
	}
}
