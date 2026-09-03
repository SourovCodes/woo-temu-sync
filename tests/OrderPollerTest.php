<?php
/**
 * Tests for polling Temu orders.
 *
 * @package WooTemuSync
 */

namespace WooTemuSync\Tests;

use WooTemuSync\Api\Credentials;
use WooTemuSync\Api\Region;
use WooTemuSync\Logger;
use WooTemuSync\Order\AddressMapper;
use WooTemuSync\Order\OrderImporter;
use WooTemuSync\Order\OrderPoller;
use WP_UnitTestCase;

/**
 * Covers the polling window, paging and what happens to the cursor on failure.
 */
class OrderPollerTest extends WP_UnitTestCase {

	/**
	 * Poller under test.
	 *
	 * @var OrderPoller
	 */
	private $poller;

	/**
	 * The connection the poller reads.
	 *
	 * @var Credentials
	 */
	private $credentials;

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

		$credentials       = new Credentials();
		$this->credentials = $credentials;
		$credentials->update(
			array(
				'app_key'      => 'appkey123',
				'app_secret'   => 'secret456',
				'access_token' => 'token789',
				'region'       => Region::EU,
			)
		);

		update_option( 'woocommerce_currency', 'EUR' );

		$logger = new Logger();

		$this->poller = new OrderPoller(
			$credentials,
			new OrderImporter( $credentials, new AddressMapper(), $logger ),
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
		remove_all_filters( 'wtsync_order_first_run_lookback' );
		delete_option( OrderPoller::CURSOR_OPTION );
		delete_option( Credentials::OPTION );

		parent::tear_down();
	}

	/**
	 * Build one Temu order payload.
	 *
	 * @param string $parent_sn Parent order number.
	 * @return array
	 */
	private function order( $parent_sn ) {
		return array(
			'parentOrderMap' => array(
				'parentOrderSn'     => $parent_sn,
				'parentOrderStatus' => 2,
				'regionId'          => 76,
				'updateTime'        => time(),
			),
			'orderList'      => array(
				array(
					'orderSn'   => $parent_sn . '-1',
					'quantity'  => 1,
					'goodsName' => 'Test product',
				),
			),
		);
	}

	/**
	 * Answer the list call from a script keyed on page number.
	 *
	 * @param array $pages    Page number to pageItems.
	 * @param bool  $fail_all Return a failure envelope for the list call.
	 * @return void
	 */
	private function fake_pages( array $pages, $fail_all = false ) {
		add_filter(
			'pre_http_request',
			function ( $pre, $args ) use ( $pages, $fail_all ) {
				$body         = json_decode( $args['body'], true );
				$this->sent[] = $body;

				if ( OrderPoller::METHOD_LIST !== $body['type'] ) {
					// Shipping and amount calls: answer with nothing useful.
					$envelope = array(
						'success'   => true,
						'errorCode' => 1000000,
						'result'    => array(),
					);
				} elseif ( $fail_all ) {
					$envelope = array(
						'success'   => false,
						'errorCode' => 3000001,
						'errorMsg'  => 'SIGN_UNVALID',
					);
				} else {
					$page     = (int) $body['pageNumber'];
					$items    = isset( $pages[ $page ] ) ? $pages[ $page ] : array();
					$envelope = array(
						'success'   => true,
						'errorCode' => 1000000,
						'result'    => array( 'pageItems' => $items ),
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
	 * The bodies of the list calls only.
	 *
	 * @return array
	 */
	private function list_calls() {
		return array_values(
			array_filter(
				$this->sent,
				static function ( $body ) {
					return OrderPoller::METHOD_LIST === $body['type'];
				}
			)
		);
	}

	/**
	 * A first run reaches back rather than starting from now.
	 *
	 * Starting at the current moment would miss every order placed before the
	 * plugin was installed.
	 *
	 * @return void
	 */
	public function test_the_first_run_looks_back() {
		$this->assertGreaterThan( 0, time() - $this->poller->cursor() );
		$this->assertLessThanOrEqual( OrderPoller::FIRST_RUN_LOOKBACK + 5, time() - $this->poller->cursor() );
	}

	/**
	 * The look-back is filterable, for a shop with a long history.
	 *
	 * @return void
	 */
	public function test_the_first_run_lookback_is_filterable() {
		add_filter(
			'wtsync_order_first_run_lookback',
			static function () {
				return 60;
			}
		);

		$this->assertLessThanOrEqual( 65, time() - $this->poller->cursor() );
	}

	/**
	 * Orders are imported and the cursor moves forward after a clean pass.
	 *
	 * @return void
	 */
	public function test_a_clean_pass_imports_and_advances_the_cursor() {
		$this->fake_pages( array( 1 => array( $this->order( 'PO-76-1' ), $this->order( 'PO-76-2' ) ) ) );

		$this->assertSame( 2, $this->poller->run() );
		$this->assertGreaterThan( 0, (int) get_option( OrderPoller::CURSOR_OPTION ) );
		$this->assertCount(
			2,
			wc_get_orders(
				array(
					'limit'  => -1,
					'status' => 'any',
				)
			)
		);
	}

	/**
	 * The window overlaps the previous one, so nothing falls between polls.
	 *
	 * Temu's clock and ours are not the same clock, and the import is idempotent,
	 * so re-covering a few minutes is free.
	 *
	 * @return void
	 */
	public function test_the_window_overlaps_the_previous_cursor() {
		$cursor = time() - 1000;
		update_option( OrderPoller::CURSOR_OPTION, $cursor, false );

		$this->fake_pages( array( 1 => array() ) );
		$this->poller->run();

		$this->assertSame( $cursor - OrderPoller::OVERLAP, $this->list_calls()[0]['updateAtStart'] );
	}

	/**
	 * Every page is walked, because a partial pass loses orders.
	 *
	 * @return void
	 */
	public function test_all_pages_are_walked() {
		$full = array();
		for ( $i = 0; $i < OrderPoller::PAGE_SIZE; $i++ ) {
			$full[] = $this->order( 'PO-76-page1-' . $i );
		}

		$this->fake_pages(
			array(
				1 => $full,
				2 => array( $this->order( 'PO-76-page2-1' ) ),
			)
		);

		$this->assertSame( OrderPoller::PAGE_SIZE + 1, $this->poller->run() );

		$pages = wp_list_pluck( $this->list_calls(), 'pageNumber' );
		$this->assertSame( array( 1, 2 ), $pages );
	}

	/**
	 * A short page ends the walk without a wasted extra call.
	 *
	 * @return void
	 */
	public function test_a_short_page_ends_the_walk() {
		$this->fake_pages( array( 1 => array( $this->order( 'PO-76-1' ) ) ) );

		$this->poller->run();

		$this->assertCount( 1, $this->list_calls() );
	}

	/**
	 * A failed poll leaves the cursor alone so the window is covered again.
	 *
	 * Advancing past a window that was never read is how orders go missing, and
	 * a missing order is one the seller never ships.
	 *
	 * @return void
	 */
	public function test_a_failed_poll_does_not_advance_the_cursor() {
		$cursor = time() - 1000;
		update_option( OrderPoller::CURSOR_OPTION, $cursor, false );

		$this->fake_pages( array(), true );

		$this->assertSame( 0, $this->poller->run() );
		$this->assertSame( $cursor, (int) get_option( OrderPoller::CURSOR_OPTION ) );
	}

	/**
	 * A poll with nothing new still advances the cursor.
	 *
	 * @return void
	 */
	public function test_an_empty_poll_advances_the_cursor() {
		update_option( OrderPoller::CURSOR_OPTION, time() - 1000, false );

		$this->fake_pages( array( 1 => array() ) );

		$this->assertSame( 0, $this->poller->run() );
		$this->assertGreaterThan( time() - 10, (int) get_option( OrderPoller::CURSOR_OPTION ) );
	}

	/**
	 * A disconnected store polls nothing.
	 *
	 * @return void
	 */
	public function test_a_disconnected_store_does_not_poll() {
		// Disconnect through the credential store rather than deleting the option
		// behind it: the store caches what it read, which is what a live request
		// would also be holding.
		$this->credentials->disconnect();

		$this->fake_pages( array( 1 => array( $this->order( 'PO-76-1' ) ) ) );

		$this->assertSame( 0, $this->poller->run() );
		$this->assertCount( 0, $this->sent );
	}
}
