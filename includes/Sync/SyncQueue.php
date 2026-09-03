<?php
/**
 * Schedules stock and price pushes in response to shop activity.
 *
 * @package WooTemuSync
 */

namespace WooTemuSync\Sync;

use WooTemuSync\Logger;
use WooTemuSync\Product\Publisher;
use WooTemuSync\Product\Repository;
use WooTemuSync\Product\Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Turns WooCommerce stock and price changes into queued Temu updates.
 *
 * Syncing inline is not an option. A busy shop fires a stock change on every
 * checkout, and a bulk price edit fires one per product; pushing each of those
 * synchronously would put a Temu round trip inside a customer's checkout and
 * walk straight into the 20 requests per second limit.
 *
 * So changes are coalesced: a product is marked dirty, one job is queued for it
 * a short while later, and that job sends whatever the levels happen to be by
 * the time it runs. Ten sales in a minute cost one API call, not ten.
 */
class SyncQueue {

	/**
	 * Hook that syncs one product.
	 */
	const HOOK_SYNC = 'wtsync_sync_product';

	/**
	 * Hook for the periodic reconciliation sweep.
	 */
	const HOOK_RECONCILE = 'wtsync_reconcile_products';

	/**
	 * How long changes are gathered before the push runs.
	 *
	 * Long enough to swallow a burst of checkouts, short enough that a shop
	 * running low is not still advertising stock it no longer has.
	 */
	const DEBOUNCE = 120;

	/**
	 * Seconds between reconciliation sweeps.
	 */
	const RECONCILE_INTERVAL = HOUR_IN_SECONDS;

	/**
	 * Products reconciled per sweep.
	 */
	const RECONCILE_BATCH = 50;

	/**
	 * Stock sync.
	 *
	 * @var StockSync
	 */
	private $stock;

	/**
	 * Price sync.
	 *
	 * @var PriceSync
	 */
	private $price;

	/**
	 * Product repository.
	 *
	 * @var Repository
	 */
	private $repository;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Product ids already queued during this request.
	 *
	 * A bulk edit can touch the same product several times in one page load;
	 * this keeps that to a single scheduling call.
	 *
	 * @var array<int,bool>
	 */
	private $queued = array();

	/**
	 * Constructor.
	 *
	 * @param StockSync  $stock      Stock sync.
	 * @param PriceSync  $price      Price sync.
	 * @param Repository $repository Product repository.
	 * @param Logger     $logger     Logger.
	 */
	public function __construct( StockSync $stock, PriceSync $price, Repository $repository, Logger $logger ) {
		$this->stock      = $stock;
		$this->price      = $price;
		$this->repository = $repository;
		$this->logger     = $logger;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		// Stock: these fire for sales, refunds, restocks and manual edits alike.
		add_action( 'woocommerce_product_set_stock', array( $this, 'queue_from_product' ) );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'queue_from_product' ) );
		add_action( 'woocommerce_updated_product_stock', array( $this, 'queue_from_id' ) );

		// Price and everything else editorial.
		add_action( 'woocommerce_update_product', array( $this, 'queue_from_id' ) );
		add_action( 'woocommerce_update_product_variation', array( $this, 'queue_from_id' ) );

		add_action( self::HOOK_SYNC, array( $this, 'run_sync' ) );
		add_action( self::HOOK_RECONCILE, array( $this, 'run_reconcile' ) );
		add_action( 'init', array( $this, 'ensure_reconcile_scheduled' ) );
	}

	/**
	 * Queue from a product object.
	 *
	 * @param \WC_Product $product Product or variation.
	 * @return void
	 */
	public function queue_from_product( $product ) {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return;
		}

		$this->queue_from_id( $product->get_id() );
	}

	/**
	 * Queue from a product or variation id.
	 *
	 * A variation is queued as its parent, because Temu addresses stock and
	 * price per listing and one call covers every SKU under it.
	 *
	 * @param int $product_id Product or variation id.
	 * @return void
	 */
	public function queue_from_id( $product_id ) {
		$product = wc_get_product( absint( $product_id ) );

		if ( ! $product || ! $product->exists() ) {
			return;
		}

		if ( $product->get_parent_id() ) {
			$product = wc_get_product( $product->get_parent_id() );

			if ( ! $product || ! $product->exists() ) {
				return;
			}
		}

		$this->queue( $product );
	}

	/**
	 * Queue a published product for a push.
	 *
	 * @param \WC_Product $product Product.
	 * @return void
	 */
	public function queue( $product ) {
		$product_id = $product->get_id();

		// Never published to Temu, so there is nothing to update.
		if ( (int) $product->get_meta( Publisher::META_GOODS_ID ) <= 0 ) {
			return;
		}

		if ( isset( $this->queued[ $product_id ] ) ) {
			return;
		}

		$this->queued[ $product_id ] = true;

		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		$args = array( 'product_id' => $product_id );

		// A job already waiting will pick up this change when it runs.
		if ( false !== as_next_scheduled_action( self::HOOK_SYNC, $args, Scheduler::GROUP ) ) {
			return;
		}

		/**
		 * Filter how long changes are gathered before the Temu push runs.
		 *
		 * @since 0.1.0
		 *
		 * @param int         $seconds Debounce window.
		 * @param \WC_Product $product Product being queued.
		 */
		$delay = (int) apply_filters( 'wtsync_sync_debounce', self::DEBOUNCE, $product );

		as_schedule_single_action( time() + max( 0, $delay ), self::HOOK_SYNC, $args, Scheduler::GROUP );
	}

	/**
	 * Push stock and prices for one product.
	 *
	 * @param int $product_id Product id.
	 * @return void
	 */
	public function run_sync( $product_id = 0 ) {
		$product = wc_get_product( absint( $product_id ) );

		if ( ! $product || ! $product->exists() ) {
			return;
		}

		$this->stock->sync( $product );

		// Re-read the product: the stock push writes meta, and the price sync
		// would otherwise save a stale object over the top of it.
		$product = wc_get_product( $product->get_id() );

		if ( $product && $product->exists() ) {
			$this->price->sync( $product );
		}
	}

	/**
	 * Make sure the reconciliation sweep is scheduled.
	 *
	 * @return void
	 */
	public function ensure_reconcile_scheduled() {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return;
		}

		if ( false !== as_next_scheduled_action( self::HOOK_RECONCILE, array(), Scheduler::GROUP ) ) {
			return;
		}

		as_schedule_recurring_action(
			time() + self::RECONCILE_INTERVAL,
			self::RECONCILE_INTERVAL,
			self::HOOK_RECONCILE,
			array(),
			Scheduler::GROUP
		);
	}

	/**
	 * Walk the published catalogue and repair anything that drifted.
	 *
	 * Hooks miss things: a direct database write, an import plugin, a failed
	 * push, a job lost when a site was migrated. The sweep is cheap because both
	 * syncs compare against what Temu last accepted and send nothing when the
	 * values match, so a settled catalogue costs no API calls at all.
	 *
	 * @return void
	 */
	public function run_reconcile() {
		$products = $this->repository->published( self::RECONCILE_BATCH, $this->reconcile_offset() );

		if ( empty( $products ) ) {
			$this->set_reconcile_offset( 0 );

			return;
		}

		$synced = 0;

		foreach ( $products as $product ) {
			if ( $this->stock->sync( $product ) ) {
				++$synced;
			}

			$fresh = wc_get_product( $product->get_id() );

			if ( $fresh && $fresh->exists() ) {
				$this->price->sync( $fresh );
			}
		}

		$this->set_reconcile_offset( $this->reconcile_offset() + count( $products ) );

		if ( $synced > 0 ) {
			$this->logger->info(
				sprintf( 'Reconciliation swept %d products and corrected %d', count( $products ), $synced )
			);
		}
	}

	/**
	 * Where the next reconciliation sweep resumes.
	 *
	 * The catalogue is walked a batch at a time across sweeps rather than all at
	 * once, so a shop with ten thousand listings does not try to reconcile them
	 * in a single job.
	 *
	 * @return int
	 */
	protected function reconcile_offset() {
		return (int) get_option( 'woo_temu_sync_reconcile_offset', 0 );
	}

	/**
	 * Record where the next sweep resumes.
	 *
	 * @param int $offset Offset.
	 * @return void
	 */
	protected function set_reconcile_offset( $offset ) {
		update_option( 'woo_temu_sync_reconcile_offset', max( 0, (int) $offset ), false );
	}
}
