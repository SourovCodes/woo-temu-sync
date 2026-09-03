<?php
/**
 * Background scheduling for status polling.
 *
 * @package WooTemuSync
 */

namespace WooTemuSync\Product;

use WooTemuSync\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Drives the status poller in the background.
 *
 * Action Scheduler only: it ships with WooCommerce, which the plugin already
 * requires, and it survives a site with no visitors in a way WP-Cron does not.
 * A polling job that only runs when somebody happens to load a page is worse
 * than useless, because the merchant cannot tell a settled listing from an
 * unvisited site.
 */
class Scheduler {

	/**
	 * Hook run for the recurring sweep.
	 */
	const HOOK_SWEEP = 'wtsync_poll_publish_status';

	/**
	 * Hook run for the first check after a publish.
	 */
	const HOOK_FIRST_CHECK = 'wtsync_first_status_check';

	/**
	 * Action Scheduler group, so the plugin's jobs can be found and cancelled together.
	 */
	const GROUP = 'woo-temu-sync';

	/**
	 * Seconds between sweeps.
	 */
	const SWEEP_INTERVAL = 900;

	/**
	 * Delay before the first check on a freshly published product.
	 *
	 * Temu's own guide says to wait about ten minutes before querying status,
	 * so asking sooner just burns rate limit on a product that is still being
	 * enriched. Twelve gives that advice some headroom.
	 */
	const FIRST_CHECK_DELAY = 720;

	/**
	 * Status poller.
	 *
	 * @var StatusPoller
	 */
	private $poller;

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
	 * Constructor.
	 *
	 * @param StatusPoller $poller     Status poller.
	 * @param Repository   $repository Product repository.
	 * @param Logger       $logger     Logger.
	 */
	public function __construct( StatusPoller $poller, Repository $repository, Logger $logger ) {
		$this->poller     = $poller;
		$this->repository = $repository;
		$this->logger     = $logger;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( self::HOOK_SWEEP, array( $this, 'run_sweep' ) );
		add_action( self::HOOK_FIRST_CHECK, array( $this, 'run_first_check' ) );
		add_action( 'wtsync_product_published', array( $this, 'schedule_first_check' ), 10, 2 );
		add_action( 'init', array( $this, 'ensure_sweep_scheduled' ) );
	}

	/**
	 * Make sure the recurring sweep exists.
	 *
	 * Scheduled on init rather than at activation so a site that lost its queue,
	 * or was cloned without it, puts the job back on the next request.
	 *
	 * @return void
	 */
	public function ensure_sweep_scheduled() {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return;
		}

		if ( false !== as_next_scheduled_action( self::HOOK_SWEEP, array(), self::GROUP ) ) {
			return;
		}

		as_schedule_recurring_action(
			time() + self::SWEEP_INTERVAL,
			self::SWEEP_INTERVAL,
			self::HOOK_SWEEP,
			array(),
			self::GROUP
		);
	}

	/**
	 * Queue the first status check for a newly published product.
	 *
	 * @param int         $goods_id Temu product id.
	 * @param \WC_Product $product  Product.
	 * @return void
	 */
	public function schedule_first_check( $goods_id, $product ) {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		as_schedule_single_action(
			time() + self::FIRST_CHECK_DELAY,
			self::HOOK_FIRST_CHECK,
			array( 'product_id' => $product->get_id() ),
			self::GROUP
		);
	}

	/**
	 * Check one product.
	 *
	 * @param int $product_id Product id.
	 * @return void
	 */
	public function run_first_check( $product_id = 0 ) {
		$product_id = absint( $product_id );

		if ( 0 === $product_id ) {
			return;
		}

		$product = wc_get_product( $product_id );

		if ( ! $product || ! $product->exists() ) {
			return;
		}

		$this->poller->poll( array( $product ) );
	}

	/**
	 * Check every product still awaiting a settled status.
	 *
	 * @return void
	 */
	public function run_sweep() {
		$products = array_values(
			array_filter(
				$this->repository->pending_status(),
				array( $this->poller, 'should_poll' )
			)
		);

		if ( empty( $products ) ) {
			return;
		}

		$changed = $this->poller->poll( $products );

		$this->logger->info(
			sprintf( 'Status sweep checked %d products, %d changed', count( $products ), $changed )
		);
	}

	/**
	 * Remove scheduled work.
	 *
	 * @return void
	 */
	public static function unschedule() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), self::GROUP );
		}
	}
}
