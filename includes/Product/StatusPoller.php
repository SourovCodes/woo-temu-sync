<?php
/**
 * Polls Temu for product publication status.
 *
 * @package WooTemuSync
 */

namespace WooTemuSync\Product;

use WooTemuSync\Api\ApiException;
use WooTemuSync\Api\Credentials;
use WooTemuSync\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Asks Temu what happened to the products we published.
 *
 * Creation is asynchronous: temu.local.goods.v3.add returns a goodsId
 * immediately, then Temu spends minutes recognising the category, enriching
 * attributes and generating SKUs before the listing can go to review. Without
 * this poller a merchant publishes a catalogue and has no way to learn which
 * items silently stuck in Draft, which is the single most common failure mode
 * of the V3 flow.
 *
 * bg.local.goods.publish.status.get accepts a batch of ids, so a whole
 * catalogue costs a handful of calls rather than one per product.
 */
class StatusPoller {

	/**
	 * The batch status method.
	 */
	const METHOD_STATUS = 'bg.local.goods.publish.status.get';

	/**
	 * Products per API call.
	 *
	 * Temu does not document a cap on goodsIdList. 100 is conservative enough
	 * to stay well inside any reasonable limit while keeping a 1,000 product
	 * catalogue down to ten calls.
	 */
	const DEFAULT_BATCH_SIZE = 100;

	/**
	 * How many times a single product is polled before we stop.
	 *
	 * Without a documented terminal status there is no reliable "it is done"
	 * signal, so give-up is by attempt count rather than by state. At the
	 * default 15 minute cadence this covers about three hours.
	 */
	const DEFAULT_MAX_ATTEMPTS = 12;

	/**
	 * Credential store.
	 *
	 * @var Credentials
	 */
	protected $credentials;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	protected $logger;

	/**
	 * Constructor.
	 *
	 * @param Credentials $credentials Credential store.
	 * @param Logger      $logger      Logger.
	 */
	public function __construct( Credentials $credentials, Logger $logger ) {
		$this->credentials = $credentials;
		$this->logger      = $logger;
	}

	/**
	 * Split a list of ids into API-sized batches.
	 *
	 * @param array $goods_ids Temu product ids.
	 * @return array[] Batches of ids.
	 */
	public function batches( array $goods_ids ) {
		$goods_ids = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $goods_ids )
				)
			)
		);

		if ( empty( $goods_ids ) ) {
			return array();
		}

		/**
		 * Filter how many products are queried per API call.
		 *
		 * @since 0.1.0
		 *
		 * @param int $size Batch size.
		 */
		$size = (int) apply_filters( 'wtsync_status_batch_size', self::DEFAULT_BATCH_SIZE );
		$size = max( 1, $size );

		return array_chunk( $goods_ids, $size );
	}

	/**
	 * Read the status of a set of products.
	 *
	 * A failure on one batch does not abandon the rest: a rate limit or a
	 * transient error partway through a catalogue sweep should not throw away
	 * the readings already collected.
	 *
	 * @param array $goods_ids Temu product ids.
	 * @return PublishStatus[] Keyed by goodsId.
	 */
	public function fetch( array $goods_ids ) {
		$statuses = array();

		foreach ( $this->batches( $goods_ids ) as $batch ) {
			try {
				$result = $this->client()->request(
					self::METHOD_STATUS,
					array( 'goodsIdList' => $batch )
				);
			} catch ( ApiException $e ) {
				$this->logger->error(
					sprintf( 'Status poll failed for %d products: %s', count( $batch ), $e->getMessage() ),
					array(
						'code'       => $e->get_error_code(),
						'request_id' => $e->get_request_id(),
					)
				);

				continue;
			}

			foreach ( $this->parse( $result ) as $status ) {
				$statuses[ $status->get_goods_id() ] = $status;
			}
		}

		return $statuses;
	}

	/**
	 * Turn a response body into status readings.
	 *
	 * @param array $result Decoded API result.
	 * @return PublishStatus[]
	 */
	public function parse( array $result ) {
		if ( empty( $result['goodsPublishStatusList'] ) || ! is_array( $result['goodsPublishStatusList'] ) ) {
			return array();
		}

		$statuses = array();

		foreach ( $result['goodsPublishStatusList'] as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$status = PublishStatus::from_response( $entry );

			if ( $status ) {
				$statuses[] = $status;
			}
		}

		return $statuses;
	}

	/**
	 * Poll a set of WooCommerce products and record what comes back.
	 *
	 * @param \WC_Product[] $products Products to check.
	 * @return int Number of products whose status changed.
	 */
	public function poll( array $products ) {
		$by_goods_id = array();

		foreach ( $products as $product ) {
			$goods_id = (int) $product->get_meta( Publisher::META_GOODS_ID );

			if ( $goods_id > 0 ) {
				$by_goods_id[ $goods_id ] = $product;
			}
		}

		if ( empty( $by_goods_id ) ) {
			return 0;
		}

		$statuses = $this->fetch( array_keys( $by_goods_id ) );
		$changed  = 0;

		foreach ( $by_goods_id as $goods_id => $product ) {
			$attempts = (int) $product->get_meta( PublishStatus::META_ATTEMPTS );
			$product->update_meta_data( PublishStatus::META_ATTEMPTS, $attempts + 1 );
			$product->update_meta_data( PublishStatus::META_CHECKED_AT, time() );

			// Temu omitting a product from the response is not an error: the
			// record may simply not exist yet. Count the attempt and move on.
			if ( ! isset( $statuses[ $goods_id ] ) ) {
				$product->save();
				continue;
			}

			$status   = $statuses[ $goods_id ];
			$previous = new PublishStatus(
				$goods_id,
				'' === $product->get_meta( PublishStatus::META_STATUS ) ? null : $product->get_meta( PublishStatus::META_STATUS ),
				'' === $product->get_meta( PublishStatus::META_SUB_STATUS ) ? null : $product->get_meta( PublishStatus::META_SUB_STATUS )
			);

			if ( $status->differs_from( $previous ) ) {
				++$changed;

				$product->update_meta_data( PublishStatus::META_STATUS, $status->get_status() );
				$product->update_meta_data( PublishStatus::META_SUB_STATUS, $status->get_sub_status() );

				$this->logger->info(
					sprintf(
						'Product %d (Temu %d) status is now %s',
						$product->get_id(),
						$goods_id,
						$status->get_label()
					)
				);

				/**
				 * Fires when Temu reports a new status for a product.
				 *
				 * @since 0.1.0
				 *
				 * @param PublishStatus $status   New reading.
				 * @param \WC_Product   $product  Product.
				 * @param PublishStatus $previous Previous reading.
				 */
				do_action( 'wtsync_publish_status_changed', $status, $product, $previous );
			}

			$product->save();
		}

		return $changed;
	}

	/**
	 * Whether a product should still be polled.
	 *
	 * @param \WC_Product $product Product.
	 * @return bool
	 */
	public function should_poll( $product ) {
		if ( (int) $product->get_meta( Publisher::META_GOODS_ID ) <= 0 ) {
			return false;
		}

		/**
		 * Filter how many times a product is polled before giving up.
		 *
		 * @since 0.1.0
		 *
		 * @param int $attempts Maximum attempts.
		 */
		$max = (int) apply_filters( 'wtsync_status_max_attempts', self::DEFAULT_MAX_ATTEMPTS );

		return (int) $product->get_meta( PublishStatus::META_ATTEMPTS ) < $max;
	}

	/**
	 * API client.
	 *
	 * @return Client
	 */
	protected function client() {
		return $this->credentials->client();
	}
}
