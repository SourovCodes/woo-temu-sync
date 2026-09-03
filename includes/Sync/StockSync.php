<?php
/**
 * Pushes WooCommerce stock levels to Temu.
 *
 * @package WooTemuSync
 */

namespace WooTemuSync\Sync;

use WooTemuSync\Api\ApiException;
use WooTemuSync\Api\Credentials;
use WooTemuSync\Logger;
use WooTemuSync\Product\Publisher;

defined( 'ABSPATH' ) || exit;

/**
 * Sends the shop's current stock levels for a published product.
 *
 * Temu offers two modes. This uses the **full** one (skuStockTargetList), which
 * overwrites the level, rather than the incremental one (skuStockChangeList),
 * which adds a delta. That is not a preference, it is a correctness
 * requirement: the client retries on rate limits and transient failures, and a
 * retried delta applies twice. Temu's own guide warns that duplicate requests
 * lead to multiple operations on the value. A full overwrite is idempotent, and
 * WooCommerce is the source of truth anyway, so there is nothing a delta buys.
 */
class StockSync {

	/**
	 * The stock update method.
	 */
	const METHOD_STOCK = 'bg.local.goods.stock.edit';

	/**
	 * Meta key holding the levels last accepted by Temu.
	 */
	const META_LAST_STOCK = '_wtsync_last_stock';

	/**
	 * Meta key holding when stock was last pushed.
	 */
	const META_SYNCED_AT = '_wtsync_stock_synced_at';

	/**
	 * Temu rejects quantities outside this range (error 150013002).
	 */
	const MAX_QUANTITY = 1000000;

	/**
	 * Credential store.
	 *
	 * @var Credentials
	 */
	private $credentials;

	/**
	 * SKU map resolver.
	 *
	 * @var SkuMap
	 */
	private $sku_map;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Credentials $credentials Credential store.
	 * @param SkuMap      $sku_map     SKU map resolver.
	 * @param Logger      $logger      Logger.
	 */
	public function __construct( Credentials $credentials, SkuMap $sku_map, Logger $logger ) {
		$this->credentials = $credentials;
		$this->sku_map     = $sku_map;
		$this->logger      = $logger;
	}

	/**
	 * The stock level this shop would report for each variation.
	 *
	 * Mirrors Mapper::quantity(): a product that does not manage stock still
	 * needs a number, because Temu reads a missing one as zero and lists the
	 * item as sold out.
	 *
	 * @param \WC_Product $product Product.
	 * @return array<int,int> Variation id to quantity.
	 */
	public function levels( $product ) {
		$variations = $product->is_type( 'variable' )
			? $product->get_children()
			: array( $product->get_id() );

		$levels = array();

		foreach ( $variations as $variation_id ) {
			$variation = wc_get_product( $variation_id );

			if ( ! $variation || ! $variation->exists() ) {
				continue;
			}

			$levels[ $variation->get_id() ] = $this->quantity( $variation );
		}

		return $levels;
	}

	/**
	 * Sellable quantity for one variation.
	 *
	 * @param \WC_Product $variation Variation or simple product.
	 * @return int
	 */
	protected function quantity( $variation ) {
		if ( $variation->managing_stock() ) {
			$quantity = max( 0, (int) $variation->get_stock_quantity() );
		} else {
			/** This filter is documented in includes/Product/Mapper.php */
			$default  = (int) apply_filters( 'wtsync_unmanaged_stock_quantity', 100, $variation );
			$quantity = $variation->is_in_stock() ? max( 0, $default ) : 0;
		}

		// A product on backorder is still purchasable in the shop, but Temu has no
		// equivalent, and promising stock the shop cannot ship is how a seller
		// collects late-shipment penalties. Report what can actually be sent.
		return min( $quantity, self::MAX_QUANTITY );
	}

	/**
	 * Push the current levels for a product.
	 *
	 * @param \WC_Product $product Published product.
	 * @return bool True when an update was sent and accepted.
	 */
	public function sync( $product ) {
		$goods_id = (int) $product->get_meta( Publisher::META_GOODS_ID );

		if ( $goods_id <= 0 || ! $this->credentials->is_connected() ) {
			return false;
		}

		$map = $this->sku_map->get( $product );

		if ( empty( $map ) ) {
			return false;
		}

		$levels  = $this->levels( $product );
		$targets = array();
		$sent    = array();

		foreach ( $map as $variation_id => $sku_id ) {
			if ( ! isset( $levels[ $variation_id ] ) ) {
				continue;
			}

			$targets[] = array(
				'skuId'       => (int) $sku_id,
				'stockTarget' => (int) $levels[ $variation_id ],
			);

			$sent[ $variation_id ] = (int) $levels[ $variation_id ];
		}

		if ( empty( $targets ) ) {
			return false;
		}

		// Nothing moved since the last accepted push, so spend no rate limit.
		if ( $sent === $this->last_sent( $product ) ) {
			return false;
		}

		try {
			$this->credentials->client()->request(
				self::METHOD_STOCK,
				array(
					'goodsId'            => $goods_id,
					'skuStockTargetList' => $targets,
					// Harmless for a full overwrite, and the platform asks for
					// idempotency control on repeated stock requests.
					'requestUniqueKey'   => $this->idempotency_key( $goods_id, $sent ),
				)
			);
		} catch ( ApiException $e ) {
			$this->logger->error(
				sprintf( 'Stock push failed for product %d: %s', $product->get_id(), $e->getMessage() ),
				array(
					'code'       => $e->get_error_code(),
					'request_id' => $e->get_request_id(),
				)
			);

			// A rejected SKU usually means the listing changed shape on Temu's
			// side, so drop the cached map and resolve it again next time.
			if ( in_array( $e->get_error_code(), array( 150010189, 150010188 ), true ) ) {
				$this->sku_map->forget( $product );
			}

			return false;
		}

		$product->update_meta_data( self::META_LAST_STOCK, $sent );
		$product->update_meta_data( self::META_SYNCED_AT, time() );
		$product->save();

		$this->logger->info(
			sprintf( 'Stock pushed for product %d (Temu %d): %d SKU(s)', $product->get_id(), $goods_id, count( $targets ) )
		);

		/**
		 * Fires after stock levels are accepted by Temu.
		 *
		 * @since 0.1.0
		 *
		 * @param array       $sent    Variation id to quantity that was sent.
		 * @param \WC_Product $product Product.
		 */
		do_action( 'wtsync_stock_synced', $sent, $product );

		return true;
	}

	/**
	 * The levels Temu last accepted.
	 *
	 * @param \WC_Product $product Product.
	 * @return array<int,int>
	 */
	public function last_sent( $product ) {
		$stored = $product->get_meta( self::META_LAST_STOCK );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$levels = array();
		foreach ( $stored as $variation_id => $quantity ) {
			$levels[ (int) $variation_id ] = (int) $quantity;
		}

		return $levels;
	}

	/**
	 * A key that is stable for a given set of levels but changes when they do.
	 *
	 * @param int   $goods_id Temu product id.
	 * @param array $levels   Variation id to quantity.
	 * @return string
	 */
	protected function idempotency_key( $goods_id, array $levels ) {
		ksort( $levels );

		return substr( md5( $goods_id . '|' . wp_json_encode( $levels ) ), 0, 32 );
	}
}
