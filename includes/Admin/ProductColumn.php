<?php
/**
 * Temu status column on the products list table.
 *
 * @package WooTemuSync
 */

namespace WooTemuSync\Admin;

use WooTemuSync\Product\PublishStatus;
use WooTemuSync\Product\Publisher;
use WooTemuSync\Product\StatusPoller;
use WooTemuSync\Sync\PriceSync;
use WooTemuSync\Sync\StockSync;

defined( 'ABSPATH' ) || exit;

/**
 * Shows each product's Temu state where merchants already look.
 *
 * The poller is only worth running if its result is visible: the whole point is
 * that a merchant can publish two hundred products and see at a glance which
 * ones Temu never accepted.
 */
class ProductColumn {

	/**
	 * Register hooks.
	 */
	public function register() {
		add_filter( 'manage_edit-product_columns', array( $this, 'add_column' ), 20 );
		add_action( 'manage_product_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
	}

	/**
	 * Insert the column after the product name.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_column( $columns ) {
		$inserted = array();

		foreach ( $columns as $key => $label ) {
			$inserted[ $key ] = $label;

			if ( 'name' === $key ) {
				$inserted['wtsync_temu'] = __( 'Temu', 'woo-temu-sync' );
			}
		}

		// Fall back to appending if the name column was not where we expected.
		if ( ! isset( $inserted['wtsync_temu'] ) ) {
			$inserted['wtsync_temu'] = __( 'Temu', 'woo-temu-sync' );
		}

		return $inserted;
	}

	/**
	 * Render one cell.
	 *
	 * @param string $column     Column key.
	 * @param int    $product_id Product id.
	 */
	public function render_column( $column, $product_id ) {
		if ( 'wtsync_temu' !== $column ) {
			return;
		}

		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return;
		}

		$goods_id = (int) $product->get_meta( Publisher::META_GOODS_ID );

		if ( $goods_id <= 0 ) {
			$error = $product->get_meta( Publisher::META_LAST_ERROR );

			if ( $error ) {
				printf(
					'<span style="color:#b32d2e;" title="%s">%s</span>',
					esc_attr( $error ),
					esc_html__( 'Failed', 'woo-temu-sync' )
				);
				return;
			}

			echo '<span style="color:#787c82;">' . esc_html__( 'Not listed', 'woo-temu-sync' ) . '</span>';
			return;
		}

		$status = new PublishStatus(
			$goods_id,
			'' === $product->get_meta( PublishStatus::META_STATUS ) ? null : $product->get_meta( PublishStatus::META_STATUS ),
			'' === $product->get_meta( PublishStatus::META_SUB_STATUS ) ? null : $product->get_meta( PublishStatus::META_SUB_STATUS )
		);

		printf(
			'<strong>%s</strong><br /><span style="color:#787c82;font-size:11px;">%s</span>',
			esc_html( $status->get_label() ),
			esc_html( sprintf( /* translators: %d: Temu product id. */ __( 'Temu ID %d', 'woo-temu-sync' ), $goods_id ) )
		);

		$checked  = (int) $product->get_meta( PublishStatus::META_CHECKED_AT );
		$attempts = (int) $product->get_meta( PublishStatus::META_ATTEMPTS );
		/** This filter is documented in includes/Product/StatusPoller.php */
		$max = (int) apply_filters( 'wtsync_status_max_attempts', StatusPoller::DEFAULT_MAX_ATTEMPTS );

		if ( $checked > 0 ) {
			printf(
				'<br /><span style="color:#787c82;font-size:11px;">%s</span>',
				esc_html(
					sprintf(
						/* translators: %s: human readable time difference. */
						__( 'checked %s ago', 'woo-temu-sync' ),
						human_time_diff( $checked, time() )
					)
				)
			);
		}

		// Say so explicitly rather than letting the merchant wonder why the
		// status stopped moving.
		if ( $attempts >= $max ) {
			printf(
				'<br /><span style="color:#996800;font-size:11px;">%s</span>',
				esc_html__( 'stopped checking', 'woo-temu-sync' )
			);
		}

		$this->render_sync_state( $product );
	}

	/**
	 * Show when stock last synced, and why a price did not.
	 *
	 * The blocked reason is the useful half. Most sellers are not whitelisted for
	 * Temu's price API, and without this the prices would simply never move with
	 * nothing on screen to explain it.
	 *
	 * @param \WC_Product $product Product.
	 * @return void
	 */
	protected function render_sync_state( $product ) {
		$stock_at = (int) $product->get_meta( StockSync::META_SYNCED_AT );

		if ( $stock_at > 0 ) {
			printf(
				'<br /><span style="color:#787c82;font-size:11px;">%s</span>',
				esc_html(
					sprintf(
						/* translators: %s: human readable time difference. */
						__( 'stock synced %s ago', 'woo-temu-sync' ),
						human_time_diff( $stock_at, time() )
					)
				)
			);
		}

		$blocked = (string) $product->get_meta( PriceSync::META_BLOCKED );

		if ( '' !== $blocked ) {
			printf(
				'<br /><span style="color:#996800;font-size:11px;" title="%s">%s</span>',
				esc_attr( $blocked ),
				esc_html__( 'price not synced', 'woo-temu-sync' )
			);
		}
	}
}
