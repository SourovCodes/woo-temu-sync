<?php
/**
 * Polls Temu for new and changed orders.
 *
 * @package WooTemuSync
 */

namespace WooTemuSync\Order;

use WooTemuSync\Api\ApiException;
use WooTemuSync\Api\Credentials;
use WooTemuSync\Logger;
use WooTemuSync\Product\Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Walks bg.order.list.v2.get and hands each order to the importer.
 *
 * Temu recommends polling on last update time rather than creation time, so an
 * order that changes after it was first seen comes back round. The cursor is
 * only advanced after a clean pass: a failure partway through must re-cover the
 * same window rather than skip it, because an order missed here is an order the
 * seller never ships.
 */
class OrderPoller {

	/**
	 * The order listing method.
	 */
	const METHOD_LIST = 'bg.order.list.v2.get';

	/**
	 * Hook that runs a poll.
	 */
	const HOOK_POLL = 'wtsync_poll_orders';

	/**
	 * Seconds between polls.
	 */
	const POLL_INTERVAL = 600;

	/**
	 * Option holding the end of the last clean poll window.
	 */
	const CURSOR_OPTION = 'woo_temu_sync_order_cursor';

	/**
	 * Orders per page.
	 */
	const PAGE_SIZE = 50;

	/**
	 * Hard cap on pages walked in one run, so a backlog cannot run away with
	 * the request.
	 */
	const MAX_PAGES = 40;

	/**
	 * How far the window reaches back beyond the cursor.
	 *
	 * Temu's update time and our clock are not the same clock, and an order
	 * updated during the previous request would otherwise fall between the two
	 * windows. Re-covering a few minutes is free because the import is
	 * idempotent.
	 */
	const OVERLAP = 300;

	/**
	 * How far back a first-ever poll looks.
	 */
	const FIRST_RUN_LOOKBACK = 7 * DAY_IN_SECONDS;

	/**
	 * Credential store.
	 *
	 * @var Credentials
	 */
	private $credentials;

	/**
	 * Order importer.
	 *
	 * @var OrderImporter
	 */
	private $importer;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Credentials   $credentials Credential store.
	 * @param OrderImporter $importer    Order importer.
	 * @param Logger        $logger      Logger.
	 */
	public function __construct( Credentials $credentials, OrderImporter $importer, Logger $logger ) {
		$this->credentials = $credentials;
		$this->importer    = $importer;
		$this->logger      = $logger;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( self::HOOK_POLL, array( $this, 'run' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
	}

	/**
	 * Make sure the recurring poll exists.
	 *
	 * @return void
	 */
	public function ensure_scheduled() {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return;
		}

		if ( false !== as_next_scheduled_action( self::HOOK_POLL, array(), Scheduler::GROUP ) ) {
			return;
		}

		as_schedule_recurring_action(
			time() + self::POLL_INTERVAL,
			self::POLL_INTERVAL,
			self::HOOK_POLL,
			array(),
			Scheduler::GROUP
		);
	}

	/**
	 * Where the next poll window starts.
	 *
	 * @return int Unix timestamp.
	 */
	public function cursor() {
		$cursor = (int) get_option( self::CURSOR_OPTION, 0 );

		if ( $cursor > 0 ) {
			return $cursor;
		}

		/**
		 * Filter how far back the very first order poll reaches.
		 *
		 * @since 0.1.0
		 *
		 * @param int $seconds Look-back window in seconds.
		 */
		$lookback = (int) apply_filters( 'wtsync_order_first_run_lookback', self::FIRST_RUN_LOOKBACK );

		return time() - max( 0, $lookback );
	}

	/**
	 * Run one poll.
	 *
	 * @return int Number of orders imported or updated.
	 */
	public function run() {
		if ( ! $this->credentials->is_connected() ) {
			return 0;
		}

		$start = max( 0, $this->cursor() - self::OVERLAP );
		$end   = time();

		$page      = 1;
		$processed = 0;

		while ( $page <= self::MAX_PAGES ) {
			try {
				$result = $this->credentials->client()->request(
					self::METHOD_LIST,
					array(
						'pageNumber'    => $page,
						'pageSize'      => self::PAGE_SIZE,
						'updateAtStart' => $start,
						'updateAtEnd'   => $end,
					)
				);
			} catch ( ApiException $e ) {
				$this->logger->error(
					sprintf( 'Order poll failed on page %d: %s', $page, $e->getMessage() ),
					array(
						'code'       => $e->get_error_code(),
						'request_id' => $e->get_request_id(),
					)
				);

				// Leave the cursor where it was so this window is covered again.
				return $processed;
			}

			$items = isset( $result['pageItems'] ) && is_array( $result['pageItems'] ) ? $result['pageItems'] : array();

			if ( empty( $items ) ) {
				break;
			}

			foreach ( $items as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				if ( $this->importer->import( $item ) ) {
					++$processed;
				}
			}

			if ( count( $items ) < self::PAGE_SIZE ) {
				break;
			}

			++$page;
		}

		if ( $page > self::MAX_PAGES ) {
			/*
			 * More pages were waiting than one run will walk. Advancing the cursor
			 * now would skip them, so leave it: the next run re-covers the same
			 * window and works through the backlog a page-set at a time.
			 */
			$this->logger->info( 'Order poll stopped at the page cap; the cursor was left in place so the backlog is picked up next run.' );

			return $processed;
		}

		update_option( self::CURSOR_OPTION, $end, false );

		if ( $processed > 0 ) {
			$this->logger->info( sprintf( 'Order poll imported or updated %d order(s)', $processed ) );
		}

		return $processed;
	}
}
