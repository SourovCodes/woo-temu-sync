<?php
/**
 * Tests for the Temu API client.
 *
 * @package WooTemuSync
 */

namespace WooTemuSync\Tests;

use WooTemuSync\Api\ApiException;
use WooTemuSync\Api\Client;
use WooTemuSync\Api\Region;
use WooTemuSync\Api\Signer;
use WP_Error;
use WP_UnitTestCase;

/**
 * Covers request shape, signing and error handling without touching the network.
 */
class ClientTest extends WP_UnitTestCase {

	/**
	 * Bodies the client sent, decoded.
	 *
	 * @var array
	 */
	private $sent = array();

	/**
	 * URLs the client posted to.
	 *
	 * @var array
	 */
	private $urls = array();

	/**
	 * How many requests were attempted.
	 *
	 * @var int
	 */
	private $attempts = 0;

	/**
	 * Reset between tests.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->sent     = array();
		$this->urls     = array();
		$this->attempts = 0;
	}

	/**
	 * Remove the HTTP short-circuit between tests.
	 *
	 * @return void
	 */
	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	/**
	 * A client whose retry backoff does not actually sleep.
	 *
	 * @param string $token Access token.
	 * @return Client
	 */
	private function client( $token = 'token789' ) {
		return new class( 'appkey123', 'secret456', $token, Region::EU ) extends Client {
			/**
			 * Skip the real sleep so retry tests stay fast.
			 *
			 * @param int $attempt Attempt number.
			 * @return void
			 */
			protected function backoff( $attempt ) {
				unset( $attempt );
			}
		};
	}

	/**
	 * Short-circuit wp_remote_post() with a canned reply.
	 *
	 * @param callable $responder Given the decoded body and attempt number, returns a response.
	 * @return void
	 */
	private function fake_response( callable $responder ) {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $responder ) {
				++$this->attempts;

				$body         = json_decode( $args['body'], true );
				$this->sent[] = $body;
				$this->urls[] = $url;

				return call_user_func( $responder, $body, $this->attempts );
			},
			10,
			3
		);
	}

	/**
	 * Build a successful Temu envelope.
	 *
	 * @param array $result Result member.
	 * @return array
	 */
	private function success( array $result = array() ) {
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
					'requestId' => 'eu-test-1',
					'result'    => $result,
				)
			),
		);
	}

	/**
	 * Build a failing Temu envelope.
	 *
	 * @param int    $code    Temu error code.
	 * @param string $message Error message.
	 * @return array
	 */
	private function failure( $code, $message ) {
		return array(
			'headers'  => array(),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'body'     => wp_json_encode(
				array(
					'success'   => false,
					'errorCode' => $code,
					'errorMsg'  => $message,
					'requestId' => 'eu-err-1',
				)
			),
		);
	}

	/**
	 * A successful call returns the result member and posts to the right host.
	 *
	 * @return void
	 */
	public function test_successful_call_returns_the_result() {
		$this->fake_response(
			function () {
				return $this->success( array( 'total' => 7 ) );
			}
		);

		$result = $this->client()->request( 'bg.local.goods.list.query', array( 'page' => 1 ) );

		$this->assertSame( array( 'total' => 7 ), $result );
		$this->assertSame( 'https://openapi-b-eu.temu.com/openapi/router', $this->urls[0] );
	}

	/**
	 * The envelope carries every common parameter Temu requires.
	 *
	 * @return void
	 */
	public function test_request_envelope() {
		$this->fake_response(
			function () {
				return $this->success();
			}
		);

		$this->client()->request( 'bg.local.goods.list.query', array( 'page' => 1 ) );
		$body = $this->sent[0];

		$this->assertSame( 'bg.local.goods.list.query', $body['type'] );
		$this->assertSame( 'JSON', $body['data_type'] );
		$this->assertSame( 'appkey123', $body['app_key'] );
		$this->assertSame( 'token789', $body['access_token'] );
		$this->assertSame( 1, $body['page'] );
		$this->assertSame( 10, strlen( $body['timestamp'] ) );
		$this->assertMatchesRegularExpression( '/^[0-9A-F]{32}$/', $body['sign'] );
	}

	/**
	 * The signature in the body verifies against the body that was sent.
	 *
	 * @return void
	 */
	public function test_signature_verifies_against_the_sent_body() {
		$this->fake_response(
			function () {
				return $this->success();
			}
		);

		$this->client()->request( 'bg.local.goods.list.query' );

		$body = $this->sent[0];
		$sign = $body['sign'];
		unset( $body['sign'] );

		$this->assertSame( $sign, Signer::sign( $body, 'secret456' ) );
	}

	/**
	 * The token handshake runs before any access token exists.
	 *
	 * @return void
	 */
	public function test_access_token_is_omitted_when_empty() {
		$this->fake_response(
			function () {
				return $this->success( array( 'accessToken' => 'new-token' ) );
			}
		);

		$this->client( '' )->request( 'bg.open.accesstoken.create', array( 'code' => 'abc' ) );

		$this->assertArrayNotHasKey( 'access_token', $this->sent[0] );
	}

	/**
	 * Whatever was signed for a nested structure is what gets sent.
	 *
	 * This is the property that keeps product publishing working: sign one
	 * serialisation and send another and every call fails with SIGN_UNVALID.
	 *
	 * @return void
	 */
	public function test_nested_json_signed_matches_nested_json_sent() {
		$captured = '';

		add_filter(
			'pre_http_request',
			function ( $pre, $args ) use ( &$captured ) {
				$captured = $args['body'];

				return $this->success();
			},
			10,
			2
		);

		$goods = array(
			array(
				'skuId'    => 17592352673534,
				'imageUrl' => 'https://img.temu.com/a/b/c.jpg',
			),
		);

		$this->client()->request( 'temu.local.goods.v3.add', array( 'goodsList' => $goods ) );

		$this->assertStringContainsString( Signer::encode_value( $goods ), $captured );
	}

	/**
	 * A signature rejection is surfaced with its code, hint and request id.
	 *
	 * @return void
	 */
	public function test_signature_error_is_not_retried_and_carries_a_hint() {
		$this->fake_response(
			function () {
				return $this->failure( 3000001, 'SIGN_UNVALID' );
			}
		);

		try {
			$this->client()->request( 'bg.local.goods.list.query' );
			$this->fail( 'Expected an ApiException.' );
		} catch ( ApiException $e ) {
			$this->assertSame( 3000001, $e->get_error_code() );
			$this->assertFalse( $e->is_retryable() );
			$this->assertNotSame( '', $e->get_hint() );
			$this->assertSame( 'eu-err-1', $e->get_request_id() );
			$this->assertSame( 'bg.local.goods.list.query', $e->get_api_type() );
			$this->assertSame( 1, $this->attempts, 'A deterministic error must be attempted once.' );
		}
	}

	/**
	 * An expired token asks for reconnection rather than retrying.
	 *
	 * @return void
	 */
	public function test_expired_token_requires_reauth() {
		$this->fake_response(
			function () {
				return $this->failure( 3000034, 'token expired' );
			}
		);

		try {
			$this->client()->request( 'bg.order.list.get' );
			$this->fail( 'Expected an ApiException.' );
		} catch ( ApiException $e ) {
			$this->assertTrue( $e->needs_reauth() );
			$this->assertFalse( $e->is_retryable() );
		}
	}

	/**
	 * A missing scope is reported as an app registration problem.
	 *
	 * @return void
	 */
	public function test_missing_scope_is_an_app_config_error() {
		$this->fake_response(
			function () {
				return $this->failure( 3000027, 'no permission' );
			}
		);

		try {
			$this->client()->request( 'temu.local.goods.v3.add' );
			$this->fail( 'Expected an ApiException.' );
		} catch ( ApiException $e ) {
			$this->assertTrue( $e->is_app_config_error() );
		}
	}

	/**
	 * Rate limiting is retried until it succeeds.
	 *
	 * @return void
	 */
	public function test_rate_limiting_is_retried() {
		$this->fake_response(
			function ( $body, $attempt ) {
				return $attempt < 3
					? $this->failure( 4000004, 'RATE_LIMIT_EXCEED_EXCEPTION' )
					: $this->success( array( 'ok' => 1 ) );
			}
		);

		$this->assertSame( array( 'ok' => 1 ), $this->client()->request( 'bg.order.list.get' ) );
		$this->assertSame( 3, $this->attempts, 'Retrying must stop as soon as the call succeeds.' );
	}

	/**
	 * Retries are capped rather than looping for ever.
	 *
	 * @return void
	 */
	public function test_retries_are_capped() {
		$this->fake_response(
			function () {
				return $this->failure( 4000004, 'RATE_LIMIT_EXCEED_EXCEPTION' );
			}
		);

		try {
			$this->client()->request( 'bg.order.list.get' );
			$this->fail( 'Expected an ApiException.' );
		} catch ( ApiException $e ) {
			$this->assertSame( Client::MAX_ATTEMPTS, $this->attempts );
			$this->assertTrue( $e->is_rate_limited() );
		}
	}

	/**
	 * A transport failure is retryable and names its cause.
	 *
	 * @return void
	 */
	public function test_transport_failure_is_retryable() {
		$this->fake_response(
			function () {
				return new WP_Error( 'http_request_failed', 'cURL error 28: timed out' );
			}
		);

		try {
			$this->client()->request( 'bg.order.list.get' );
			$this->fail( 'Expected an ApiException.' );
		} catch ( ApiException $e ) {
			$this->assertTrue( $e->is_retryable() );
			$this->assertStringContainsString( 'timed out', $e->getMessage() );
		}
	}

	/**
	 * A gateway error page is retryable; a malformed 200 is not.
	 *
	 * @return void
	 */
	public function test_unparseable_responses() {
		$this->fake_response(
			function () {
				return array(
					'headers'  => array(),
					'response' => array(
						'code'    => 502,
						'message' => 'Bad Gateway',
					),
					'body'     => '<html>gateway error</html>',
				);
			}
		);

		try {
			$this->client()->request( 'bg.order.list.get' );
			$this->fail( 'Expected an ApiException.' );
		} catch ( ApiException $e ) {
			$this->assertTrue( $e->is_retryable() );
		}

		remove_all_filters( 'pre_http_request' );
		$this->attempts = 0;

		$this->fake_response(
			function () {
				return array(
					'headers'  => array(),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => 'not json',
				);
			}
		);

		try {
			$this->client()->request( 'bg.order.list.get' );
			$this->fail( 'Expected an ApiException.' );
		} catch ( ApiException $e ) {
			$this->assertFalse( $e->is_retryable() );
		}
	}

	/**
	 * Every region resolves to its own gateway.
	 *
	 * @return void
	 */
	public function test_region_hosts() {
		$this->assertSame( 'https://openapi-b-eu.temu.com/openapi/router', Region::host( Region::EU ) );
		$this->assertSame( 'https://openapi-b-us.temu.com/openapi/router', Region::host( Region::US ) );
		$this->assertSame( 'https://openapi-b-global.temu.com/openapi/router', Region::host( Region::GLOBAL ) );
		$this->assertSame( 'https://seller-eu.temu.com', Region::seller_center( Region::EU ) );
		$this->assertSame( Region::EU, Region::from_callback_host( 'openapi-b-eu.temu.com' ) );
		$this->assertNull( Region::from_callback_host( 'example.com' ) );
	}
}
