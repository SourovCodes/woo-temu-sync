<?php
/**
 * Finding products by their Temu sync state.
 *
 * @package WooTemuSync
 */

namespace WooTemuSync\Product;

defined( 'ABSPATH' ) || exit;

/**
 * Queries for products the connector needs to act on.
 *
 * Products are still ordinary posts under HPOS - that feature moves orders, not
 * the catalogue - so a plain WP_Query on the meta is the cheapest way in.
 */
class Repository {

	/**
	 * Products that have been published to Temu but not yet settled.
	 *
	 * @param int $limit Maximum products to return.
	 * @return \WC_Product[]
	 */
	public function pending_status( $limit = 200 ) {
		/**
		 * Filter how many times a product is polled before polling gives up.
		 *
		 * @since 0.1.0
		 *
		 * @param int $attempts Maximum attempts.
		 */
		$max_attempts = (int) apply_filters( 'wtsync_status_max_attempts', StatusPoller::DEFAULT_MAX_ATTEMPTS );

		$query = new \WP_Query(
			array(
				'post_type'              => array( 'product' ),
				'post_status'            => 'any',
				'posts_per_page'         => (int) $limit,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Indexed meta lookup, run on a schedule rather than a page load.
				'meta_query'             => array(
					'relation' => 'AND',
					array(
						'key'     => Publisher::META_GOODS_ID,
						'value'   => 0,
						'compare' => '>',
						'type'    => 'NUMERIC',
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => PublishStatus::META_ATTEMPTS,
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => PublishStatus::META_ATTEMPTS,
							'value'   => $max_attempts,
							'compare' => '<',
							'type'    => 'NUMERIC',
						),
					),
				),
			)
		);

		return $this->to_products( $query->posts );
	}

	/**
	 * Hydrate post ids into product objects, skipping any that have gone away.
	 *
	 * @param array $ids Post ids.
	 * @return \WC_Product[]
	 */
	protected function to_products( array $ids ) {
		$products = array();

		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );

			if ( $product && $product->exists() ) {
				$products[] = $product;
			}
		}

		return $products;
	}
}
