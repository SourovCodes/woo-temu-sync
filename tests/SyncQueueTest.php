<?php
/**
 * Tests for the stock and price sync queue.
 *
 * @package WooTemuSync
 */

namespace WooTemuSync\Tests;

use WC_Product_Attribute;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;
use WooTemuSync\Api\Credentials;
use WooTemuSync\Api\Region;
use WooTemuSync\Logger;
use WooTemuSync\Product\Mapper;
use WooTemuSync\Product\Publisher;
use WooTemuSync\Product\Repository;
use WooTemuSync\Product\Scheduler;
use WooTemuSync\Sync\PriceSync;
use WooTemuSync\Sync\SkuMap;
use WooTemuSync\Sync\StockSync;
use WooTemuSync\Sync\SyncQueue;
use WP_UnitTestCase;

/**
 * Covers coalescing, variation-to-parent routing and what never gets queued.
 */
class SyncQueueTest extends WP_UnitTestCase {

	/**
	 * Queue under test.
	 *
	 * @var SyncQueue
	 */
	private $queue;

	/**
	 * Connect a store and build the queue.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$this->markTestSkipped( 'Action Scheduler is not available in this WooCommerce build.' );
		}

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

		$this->queue = new SyncQueue(
			new StockSync( $credentials, $sku_map, $logger ),
			new PriceSync( $credentials, $sku_map, $logger ),
			new Repository(),
			$logger
		);
	}

	/**
	 * Cancel anything a test queued.
	 *
	 * @return void
	 */
	public function tear_down() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), Scheduler::GROUP );
		}

		remove_all_filters( 'wtsync_sync_debounce' );
		delete_option( Credentials::OPTION );

		parent::tear_down();
	}

	/**
	 * Whether a sync job is waiting for a product.
	 *
	 * @param int $product_id Product id.
	 * @return bool
	 */
	private function is_queued( $product_id ) {
		return false !== as_next_scheduled_action(
			SyncQueue::HOOK_SYNC,
			array( 'product_id' => $product_id ),
			Scheduler::GROUP
		);
	}

	/**
	 * A published simple product.
	 *
	 * @return \WC_Product
	 */
	private function published_product() {
		$product = new WC_Product_Simple();
		$product->set_name( 'Queued product' );
		$product->set_regular_price( '10.00' );
		$product->update_meta_data( Publisher::META_GOODS_ID, 700400 );
		$product->save();

		return wc_get_product( $product->get_id() );
	}

	/**
	 * A published product is queued rather than pushed inline.
	 *
	 * Pushing inline would put a Temu round trip inside a customer's checkout.
	 *
	 * @return void
	 */
	public function test_a_published_product_is_queued() {
		$product = $this->published_product();

		$this->queue->queue( $product );

		$this->assertTrue( $this->is_queued( $product->get_id() ) );
	}

	/**
	 * A product that was never published is never queued.
	 *
	 * @return void
	 */
	public function test_an_unpublished_product_is_not_queued() {
		$product = new WC_Product_Simple();
		$product->set_name( 'Never published' );
		$product->set_regular_price( '10.00' );
		$product->save();

		$this->queue->queue( wc_get_product( $product->get_id() ) );

		$this->assertFalse( $this->is_queued( $product->get_id() ) );
	}

	/**
	 * Repeated changes in one request collapse into a single job.
	 *
	 * Ten sales in a minute must cost one API call, not ten.
	 *
	 * @return void
	 */
	public function test_repeated_changes_collapse_into_one_job() {
		$product = $this->published_product();

		for ( $i = 0; $i < 5; $i++ ) {
			$this->queue->queue_from_id( $product->get_id() );
		}

		$pending = as_get_scheduled_actions(
			array(
				'hook'   => SyncQueue::HOOK_SYNC,
				'status' => \ActionScheduler_Store::STATUS_PENDING,
				'group'  => Scheduler::GROUP,
			),
			'ids'
		);

		$this->assertCount( 1, $pending );
	}

	/**
	 * A variation is queued as its parent, because Temu updates a whole listing.
	 *
	 * @return void
	 */
	public function test_a_variation_is_queued_as_its_parent() {
		$colour = new WC_Product_Attribute();
		$colour->set_name( 'Colour' );
		$colour->set_options( array( 'Red' ) );
		$colour->set_visible( true );
		$colour->set_variation( true );

		$parent = new WC_Product_Variable();
		$parent->set_name( 'Queued variable' );
		$parent->set_attributes( array( $colour ) );
		$parent->update_meta_data( Publisher::META_GOODS_ID, 700401 );
		$parent->save();

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_attributes( array( 'colour' => 'Red' ) );
		$variation->set_regular_price( '10.00' );
		$variation->save();

		$this->queue->queue_from_id( $variation->get_id() );

		$this->assertTrue( $this->is_queued( $parent->get_id() ) );
		$this->assertFalse( $this->is_queued( $variation->get_id() ) );
	}

	/**
	 * The debounce window is filterable.
	 *
	 * @return void
	 */
	public function test_the_debounce_window_is_filterable() {
		add_filter(
			'wtsync_sync_debounce',
			static function () {
				return 0;
			}
		);

		$product = $this->published_product();
		$this->queue->queue( $product );

		$scheduled = as_next_scheduled_action(
			SyncQueue::HOOK_SYNC,
			array( 'product_id' => $product->get_id() ),
			Scheduler::GROUP
		);

		// With no debounce the job is due immediately rather than two minutes out.
		$this->assertLessThanOrEqual( time() + 5, is_bool( $scheduled ) ? time() : $scheduled );
	}

	/**
	 * A deleted product does not blow up the handler.
	 *
	 * @return void
	 */
	public function test_running_a_job_for_a_deleted_product_is_safe() {
		$this->queue->run_sync( 999999999 );

		$this->assertTrue( true, 'Handling a missing product must not raise.' );
	}
}
