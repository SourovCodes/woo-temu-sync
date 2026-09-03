<?php
/**
 * Mapping between WooCommerce variations and Temu SKU identifiers.
 *
 * @package WooTemuSync
 */

namespace WooTemuSync\Sync;

use WooTemuSync\Api\ApiException;
use WooTemuSync\Api\Credentials;
use WooTemuSync\Logger;
use WooTemuSync\Product\Mapper;
use WooTemuSync\Product\Publisher;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the Temu skuId behind each WooCommerce variation.
 *
 * Stock and price updates address SKUs by Temu's own numeric skuId, which is
 * minted when the listing is created and is not knowable from anything the shop
 * holds. So the mapping has to be fetched once and cached against the product.
 *
 * Getting this wrong is worse than not syncing: a mismatched map would push one
 * variation's stock onto another. Where the mapping cannot be established with
 * certainty this class returns nothing and says why, rather than guessing.
 */
class SkuMap {

	/**
	 * Method that returns a listing addressed by our own identifiers.
	 */
	const METHOD_RETRIEVE = 'temu.local.goods.list.retrieve';

	/**
	 * Meta key holding the variation id to Temu skuId map.
	 */
	const META_MAP = '_wtsync_sku_map';

	/**
	 * Meta key holding when the map was last resolved.
	 */
	const META_MAP_AT = '_wtsync_sku_map_at';

	/**
	 * How long a resolved map is trusted before it is refetched.
	 */
	const TTL = DAY_IN_SECONDS;

	/**
	 * Credential store.
	 *
	 * @var Credentials
	 */
	private $credentials;

	/**
	 * Product mapper, for the external identifiers.
	 *
	 * @var Mapper
	 */
	private $mapper;

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
	 * @param Mapper      $mapper      Product mapper.
	 * @param Logger      $logger      Logger.
	 */
	public function __construct( Credentials $credentials, Mapper $mapper, Logger $logger ) {
		$this->credentials = $credentials;
		$this->mapper      = $mapper;
		$this->logger      = $logger;
	}

	/**
	 * The variation id to Temu skuId map for a product.
	 *
	 * @param \WC_Product $product Published product.
	 * @param bool        $force   Refetch even when the cached map is still fresh.
	 * @return array<int,int> Empty when the mapping could not be established.
	 */
	public function get( $product, $force = false ) {
		$cached = $product->get_meta( self::META_MAP );
		$at     = (int) $product->get_meta( self::META_MAP_AT );

		if ( ! $force && is_array( $cached ) && ! empty( $cached ) && ( time() - $at ) < self::TTL ) {
			return array_map( 'intval', $cached );
		}

		return $this->refresh( $product );
	}

	/**
	 * Fetch the map from Temu and cache it.
	 *
	 * @param \WC_Product $product Published product.
	 * @return array<int,int>
	 */
	public function refresh( $product ) {
		$goods_id = (int) $product->get_meta( Publisher::META_GOODS_ID );

		if ( $goods_id <= 0 ) {
			return array();
		}

		try {
			$result = $this->credentials->client()->request(
				self::METHOD_RETRIEVE,
				array( 'outGoodsSnList' => array( $this->mapper->external_goods_id( $product ) ) )
			);
		} catch ( ApiException $e ) {
			$this->logger->error(
				sprintf( 'Could not read the SKU map for product %d: %s', $product->get_id(), $e->getMessage() ),
				array(
					'code'       => $e->get_error_code(),
					'request_id' => $e->get_request_id(),
				)
			);

			return array();
		}

		$map = $this->build_map( $product, $result );

		if ( empty( $map ) ) {
			return array();
		}

		$product->update_meta_data( self::META_MAP, $map );
		$product->update_meta_data( self::META_MAP_AT, time() );
		$product->save();

		return $map;
	}

	/**
	 * Match the SKUs Temu returned against this store's variations.
	 *
	 * @param \WC_Product $product Product.
	 * @param array       $result  Decoded API result.
	 * @return array<int,int>
	 */
	protected function build_map( $product, array $result ) {
		$sku_infos = $this->extract_sku_infos( $product, $result );

		if ( empty( $sku_infos ) ) {
			return array();
		}

		$variations = $this->sellable_variations( $product );

		if ( empty( $variations ) ) {
			return array();
		}

		// The reliable case: Temu echoes the externalSkuId we sent, so each SKU
		// identifies its variation outright.
		$by_external = array();
		foreach ( $sku_infos as $info ) {
			$external = isset( $info['skuSn'] ) ? (string) $info['skuSn'] : '';

			if ( '' !== $external && ! empty( $info['skuId'] ) ) {
				$by_external[ $external ] = (int) $info['skuId'];
			}
		}

		$map = array();
		foreach ( $variations as $variation ) {
			$external = $this->mapper->external_sku_id( $variation );

			if ( isset( $by_external[ $external ] ) ) {
				$map[ $variation->get_id() ] = $by_external[ $external ];
			}
		}

		if ( count( $map ) === count( $variations ) ) {
			return $map;
		}

		/*
		 * Fallback, and only for a single-SKU product. Temu's skuSn is documented
		 * as a SKU serial number without saying whether it echoes the identifier we
		 * supplied, so a listing with one variation and one returned SKU can be
		 * paired without ambiguity. Anything else is left unmapped: pushing stock
		 * to the wrong variation is a worse failure than not pushing it at all.
		 */
		if ( 1 === count( $variations ) && 1 === count( $sku_infos ) && ! empty( $sku_infos[0]['skuId'] ) ) {
			$only = reset( $variations );

			return array( $only->get_id() => (int) $sku_infos[0]['skuId'] );
		}

		$this->logger->error(
			sprintf(
				'Could not match all Temu SKUs for product %d: %d variation(s), %d Temu SKU(s), %d matched by identifier. Stock and price sync are paused for this product.',
				$product->get_id(),
				count( $variations ),
				count( $sku_infos ),
				count( $map )
			)
		);

		return array();
	}

	/**
	 * Pull this product's SKU list out of a listing response.
	 *
	 * @param \WC_Product $product Product.
	 * @param array       $result  Decoded API result.
	 * @return array
	 */
	protected function extract_sku_infos( $product, array $result ) {
		if ( empty( $result['goodsList'] ) || ! is_array( $result['goodsList'] ) ) {
			return array();
		}

		$external = $this->mapper->external_goods_id( $product );

		foreach ( $result['goodsList'] as $goods ) {
			if ( ! is_array( $goods ) ) {
				continue;
			}

			// Only trust an entry that names the product we asked about.
			if ( isset( $goods['outGoodsSn'] ) && (string) $goods['outGoodsSn'] !== $external ) {
				continue;
			}

			if ( ! empty( $goods['skuInfoList'] ) && is_array( $goods['skuInfoList'] ) ) {
				return array_values( array_filter( $goods['skuInfoList'], 'is_array' ) );
			}
		}

		return array();
	}

	/**
	 * The variations this store would publish, in the same terms the mapper uses.
	 *
	 * @param \WC_Product $product Product.
	 * @return \WC_Product[] Keyed by variation id.
	 */
	protected function sellable_variations( $product ) {
		if ( ! $product->is_type( 'variable' ) ) {
			return array( $product->get_id() => $product );
		}

		$variations = array();

		foreach ( $product->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );

			if ( ! $variation || ! $variation->exists() ) {
				continue;
			}

			// Match the mapper: a variation with no price was never published.
			if ( '' === $variation->get_price() || null === $variation->get_price() ) {
				continue;
			}

			$variations[ $variation->get_id() ] = $variation;
		}

		return $variations;
	}

	/**
	 * Forget a cached map, so the next sync refetches it.
	 *
	 * @param \WC_Product $product Product.
	 * @return void
	 */
	public function forget( $product ) {
		$product->delete_meta_data( self::META_MAP );
		$product->delete_meta_data( self::META_MAP_AT );
		$product->save();
	}
}
