<?php
/**
 * Tests for Temu publication status polling.
 *
 * @package WooTemuSync
 */

namespace WooTemuSync\Tests;

use WC_Product_Simple;
use WooTemuSync\Api\Credentials;
use WooTemuSync\Api\Region;
use WooTemuSync\Logger;
use WooTemuSync\Product\PublishStatus;
use WooTemuSync\Product\Publisher;
use WooTemuSync\Product\StatusPoller;
use WP_UnitTestCase;

/**
 * Covers batching, parsing and the give-up policy.
 */
class StatusPollerTest extends WP_UnitTestCase {

	/**
	 * Poller under test.
	 *
	 * @var StatusPoller
	 */
	private $poller;

	/**
	 * Bodies the poller sent, decoded.
	 *
	 * @var array
	 */
	private $sent = array();

	/**
	 * Connect a store so the poller has credentials to build a client from.
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

		$this->poller = new StatusPoller( $credentials, new Logger() );
	}

	/**
	 * Remove the HTTP short-circuit between tests.
	 *
	 * @return void
	 */
	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'wtsync_status_batch_size' );
		remove_all_filters( 'wtsync_status_max_attempts' );

		delete_option( Credentials::OPTION );

		parent::tear_down();
	}

	/**
	 * Answer every status call with a scripted response.
	 *
	 * @param callable $responder Given the decoded body, returns the result member or a WP_Error.
	 * @return void
	 */
	private function fake_status( callable $responder ) {
		add_filter(
			'pre_http_request',
			function ( $pre, $args ) use ( $responder ) {
				$body         = json_decode( $args['body'], true );
				$this->sent[] = $body;

				$outcome = call_user_func( $responder, $body );

				if ( is_wp_error( $outcome ) ) {
					return $outcome;
				}

				return array(
					'headers'  => array(),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode( $outcome ),
				);
			},
			10,
			2
		);
	}

	/**
	 * A Temu envelope reporting a status for every id that was asked about.
	 *
	 * @param array $body       Request body.
	 * @param int   $status     Status code to report.
	 * @param int   $sub_status Sub-status code to report.
	 * @return array
	 */
	private function status_for_all( array $body, $status = 2, $sub_status = 5 ) {
		$list = array();

		foreach ( $body['goodsIdList'] as $goods_id ) {
			$list[] = array(
				'goodsId'   => $goods_id,
				'status'    => $status,
				'subStatus' => $sub_status,
			);
		}

		return array(
			'success'   => true,
			'errorCode' => 1000000,
			'result'    => array( 'goodsPublishStatusList' => $list ),
		);
	}

	/**
	 * A product that has already been published to Temu.
	 *
	 * @param int $goods_id Temu product id.
	 * @return \WC_Product
	 */
	private function published_product( $goods_id ) {
		$product = new WC_Product_Simple();
		$product->set_name( 'Published product' );
		$product->set_regular_price( '10.00' );
		$product->update_meta_data( Publisher::META_GOODS_ID, $goods_id );
		$product->save();

		return wc_get_product( $product->get_id() );
	}

	/**
	 * Ids are de-duplicated, cleaned and split into API-sized batches.
	 *
	 * @return void
	 */
	public function test_batching() {
		$this->assertSame( array(), $this->poller->batches( array() ) );
		$this->assertCount( 1, $this->poller->batches( range( 1, 100 ) ) );
		$this->assertCount( 3, $this->poller->batches( range( 1, 250 ) ) );
		$this->assertCount( 50, $this->poller->batches( range( 1, 250 ) )[2] );
		$this->assertCount( 1, $this->poller->batches( array( 7, 7, 7 ) )[0] );
		$this->assertSame( array(), $this->poller->batches( array( 0, null, '' ) ) );
		$this->assertSame( array( array( 42 ) ), $this->poller->batches( array( '42' ) ) );
	}

	/**
	 * The batch endpoint is called with the ids in goodsIdList.
	 *
	 * @return void
	 */
	public function test_fetch_uses_the_batch_endpoint() {
		$this->fake_status(
			function ( $body ) {
				return $this->status_for_all( $body, 1, 3 );
			}
		);

		$statuses = $this->poller->fetch( array( 111, 222 ) );

		$this->assertSame( 'bg.local.goods.publish.status.get', $this->sent[0]['type'] );
		$this->assertSame( array( 111, 222 ), $this->sent[0]['goodsIdList'] );
		$this->assertCount( 2, $statuses );
		$this->assertSame( 1, $statuses[111]->get_status() );
	}

	/**
	 * Malformed responses parse to nothing rather than to junk readings.
	 *
	 * @return void
	 */
	public function test_parsing_is_defensive() {
		$this->assertSame( array(), $this->poller->parse( array() ) );
		$this->assertSame( array(), $this->poller->parse( array( 'goodsPublishStatusList' => 'nope' ) ) );
		$this->assertCount( 0, $this->poller->parse( array( 'goodsPublishStatusList' => array( 'bad', 5 ) ) ) );
		$this->assertNull( PublishStatus::from_response( array( 'status' => 1 ) ) );
	}

	/**
	 * One failed batch must not throw away the batches that worked.
	 *
	 * A rate limit partway through a catalogue sweep is expected, and losing the
	 * readings already collected would make the sweep pointless.
	 *
	 * @return void
	 */
	public function test_a_failed_batch_does_not_lose_the_others() {
		add_filter(
			'wtsync_status_batch_size',
			static function () {
				return 1;
			}
		);

		$call = 0;

		$this->fake_status(
			function ( $body ) use ( &$call ) {
				++$call;

				if ( 1 === $call ) {
					return array(
						'success'   => false,
						'errorCode' => 3000001,
						'errorMsg'  => 'SIGN_UNVALID',
					);
				}

				return $this->status_for_all( $body, 4, 0 );
			}
		);

		$statuses = $this->poller->fetch( array( 900, 901 ) );

		$this->assertCount( 1, $statuses );
		$this->assertArrayHasKey( 901, $statuses );
	}

	/**
	 * A first reading is recorded on the product and counted as a change.
	 *
	 * @return void
	 */
	public function test_poll_records_a_first_reading() {
		$this->fake_status(
			function ( $body ) {
				return $this->status_for_all( $body );
			}
		);

		$product = $this->published_product( 700001 );

		$this->assertSame( 1, $this->poller->poll( array( $product ) ) );

		$stored = wc_get_product( $product->get_id() );
		$this->assertSame( '2', (string) $stored->get_meta( PublishStatus::META_STATUS ) );
		$this->assertSame( '5', (string) $stored->get_meta( PublishStatus::META_SUB_STATUS ) );
		$this->assertSame( 1, (int) $stored->get_meta( PublishStatus::META_ATTEMPTS ) );
		$this->assertGreaterThan( 0, (int) $stored->get_meta( PublishStatus::META_CHECKED_AT ) );
	}

	/**
	 * An unchanged status is not reported as a change, but still counts.
	 *
	 * @return void
	 */
	public function test_unchanged_status_is_not_a_change() {
		$this->fake_status(
			function ( $body ) {
				return $this->status_for_all( $body );
			}
		);

		$product = $this->published_product( 700002 );
		$this->poller->poll( array( $product ) );

		$this->assertSame( 0, $this->poller->poll( array( wc_get_product( $product->get_id() ) ) ) );
		$this->assertSame( 2, (int) wc_get_product( $product->get_id() )->get_meta( PublishStatus::META_ATTEMPTS ) );
	}

	/**
	 * Products that were never published are skipped entirely.
	 *
	 * @return void
	 */
	public function test_unpublished_products_are_skipped() {
		$product = new WC_Product_Simple();
		$product->set_name( 'Never published' );
		$product->save();

		$this->assertSame( 0, $this->poller->poll( array( wc_get_product( $product->get_id() ) ) ) );
	}

	/**
	 * Temu omitting a product is not an error and invents no status.
	 *
	 * The record may simply not exist yet, so the attempt is counted and the
	 * reading is left empty rather than guessed.
	 *
	 * @return void
	 */
	public function test_a_product_temu_does_not_report() {
		$this->fake_status(
			static function () {
				return array(
					'success'   => true,
					'errorCode' => 1000000,
					'result'    => array( 'goodsPublishStatusList' => array() ),
				);
			}
		);

		$product = $this->published_product( 700003 );

		$this->assertSame( 0, $this->poller->poll( array( $product ) ) );

		$stored = wc_get_product( $product->get_id() );
		$this->assertSame( 1, (int) $stored->get_meta( PublishStatus::META_ATTEMPTS ) );
		$this->assertSame( '', (string) $stored->get_meta( PublishStatus::META_STATUS ) );
	}

	/**
	 * Polling gives up by attempt count, because Temu documents no terminal state.
	 *
	 * @return void
	 */
	public function test_give_up_policy() {
		$fresh = $this->published_product( 700004 );
		$this->assertTrue( $this->poller->should_poll( $fresh ) );

		$nearly = $this->published_product( 700005 );
		$nearly->update_meta_data( PublishStatus::META_ATTEMPTS, StatusPoller::DEFAULT_MAX_ATTEMPTS - 1 );
		$nearly->save();
		$this->assertTrue( $this->poller->should_poll( wc_get_product( $nearly->get_id() ) ) );

		$exhausted = $this->published_product( 700006 );
		$exhausted->update_meta_data( PublishStatus::META_ATTEMPTS, StatusPoller::DEFAULT_MAX_ATTEMPTS );
		$exhausted->save();
		$this->assertFalse( $this->poller->should_poll( wc_get_product( $exhausted->get_id() ) ) );

		$unpublished = new WC_Product_Simple();
		$unpublished->save();
		$this->assertFalse( $this->poller->should_poll( wc_get_product( $unpublished->get_id() ) ) );
	}

	/**
	 * Undocumented status codes are shown raw rather than guessed at.
	 *
	 * Temu publishes no enumeration for status/subStatus, and a wrong label in
	 * the merchant's admin is worse than an honest number they can quote to
	 * Temu support.
	 *
	 * @return void
	 */
	public function test_undocumented_codes_are_reported_honestly() {
		$status = new PublishStatus( 1, 2, 7 );

		$this->assertTrue( $status->is_known() );
		$this->assertStringContainsString( '2', $status->get_label() );
		$this->assertStringContainsString( '7', $status->get_label() );

		$unknown = new PublishStatus( 1 );
		$this->assertFalse( $unknown->is_known() );
	}

	/**
	 * Labels become readable once the enumeration is confirmed, without a code change.
	 *
	 * @return void
	 */
	public function test_status_labels_are_filterable() {
		add_filter(
			'wtsync_publish_status_labels',
			static function () {
				return array( 1 => 'Draft' );
			}
		);

		$this->assertSame( 'Draft', ( new PublishStatus( 1, 1, 0 ) )->get_label() );

		remove_all_filters( 'wtsync_publish_status_labels' );
	}

	/**
	 * Change detection covers both halves of the reading.
	 *
	 * @return void
	 */
	public function test_change_detection() {
		$status = new PublishStatus( 1, 2, 7 );

		$this->assertTrue( $status->differs_from( new PublishStatus( 1, 1, 7 ) ) );
		$this->assertTrue( $status->differs_from( new PublishStatus( 1, 2, 8 ) ) );
		$this->assertTrue( $status->differs_from( null ) );
		$this->assertFalse( $status->differs_from( new PublishStatus( 1, 2, 7 ) ) );
	}
}
