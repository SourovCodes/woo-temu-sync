<?php
/**
 * Publishes WooCommerce products to Temu.
 *
 * @package WooTemuSync
 */

namespace WooTemuSync\Product;

use WooTemuSync\Api\ApiException;
use WooTemuSync\Api\Credentials;
use WooTemuSync\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Sends a mapped product to temu.local.goods.v3.add and records the result.
 *
 * Creation is asynchronous on Temu's side: a successful call returns a goodsId
 * but the listing is not live. Temu enriches the data, assigns a category and
 * submits for review, which takes minutes. So this class records what it sent
 * and when, and leaves status polling to a later pass.
 */
class Publisher {

	/**
	 * Meta key holding Temu's product id.
	 */
	const META_GOODS_ID = '_wtsync_temu_goods_id';

	/**
	 * Meta key holding the last publish timestamp.
	 */
	const META_PUBLISHED_AT = '_wtsync_published_at';

	/**
	 * Meta key holding the last publish error.
	 */
	const META_LAST_ERROR = '_wtsync_last_error';

	/**
	 * Credential store.
	 *
	 * @var Credentials
	 */
	protected $credentials;

	/**
	 * Product mapper.
	 *
	 * @var Mapper
	 */
	protected $mapper;

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
	 * @param Mapper      $mapper      Product mapper.
	 * @param Logger      $logger      Logger.
	 */
	public function __construct( Credentials $credentials, Mapper $mapper, Logger $logger ) {
		$this->credentials = $credentials;
		$this->mapper      = $mapper;
		$this->logger      = $logger;
	}

	/**
	 * Publish or update one product on Temu.
	 *
	 * @param \WC_Product $product Product to publish.
	 * @return int Temu's goodsId.
	 *
	 * @throws ApiException When Temu rejects the call, or the store cannot publish.
	 */
	public function publish( $product ) {
		$this->assert_ready();

		$payload = $this->mapper->to_payload( $product );

		$this->logger->info(
			sprintf( 'Publishing product %d to Temu', $product->get_id() ),
			array( 'externalGoodsId' => $payload['goodsBasic']['externalGoodsId'] )
		);

		try {
			$result = $this->credentials->client()->request( Schema::METHOD_ADD, $payload );
		} catch ( ApiException $e ) {
			$product->update_meta_data( self::META_LAST_ERROR, $e->getMessage() );
			$product->save();

			throw $e;
		}

		$goods_id = isset( $result['goodsId'] ) ? (int) $result['goodsId'] : 0;

		$product->update_meta_data( self::META_GOODS_ID, $goods_id );
		$product->update_meta_data( self::META_PUBLISHED_AT, time() );
		$product->delete_meta_data( self::META_LAST_ERROR );
		$product->save();

		$this->logger->info(
			sprintf( 'Product %d created on Temu as goodsId %d', $product->get_id(), $goods_id )
		);

		/**
		 * Fires after a product is accepted by Temu.
		 *
		 * The listing is not live yet: Temu still has to enrich and review it.
		 *
		 * @since 0.1.0
		 *
		 * @param int         $goods_id Temu product id.
		 * @param \WC_Product $product  Source product.
		 * @param array       $payload  Payload that was sent.
		 */
		do_action( 'wtsync_product_published', $goods_id, $product, $payload );

		return $goods_id;
	}

	/**
	 * Temu's product id for a WooCommerce product, if it has one.
	 *
	 * @param \WC_Product $product Product.
	 * @return int Zero when never published.
	 */
	public function get_goods_id( $product ) {
		return (int) $product->get_meta( self::META_GOODS_ID );
	}

	/**
	 * Whether the connection can publish products at all.
	 *
	 * @throws ApiException When the store is not ready to publish.
	 */
	protected function assert_ready() {
		if ( ! $this->credentials->is_connected() ) {
			throw new ApiException(
				esc_html__( 'Connect a Temu store before publishing products.', 'woo-temu-sync' ),
				3000030
			);
		}

		if ( $this->credentials->is_expired() ) {
			throw new ApiException(
				esc_html__( 'The Temu access token has expired. Reconnect the store.', 'woo-temu-sync' ),
				3000034
			);
		}

		$scopes = $this->credentials->get( 'api_scopes', array() );

		// An empty scope list means we simply never recorded one, which is not
		// the same as the scope being absent. Only complain when we know.
		if ( ! empty( $scopes ) && ! $this->credentials->has_scope( Schema::METHOD_ADD ) ) {
			throw new ApiException(
				sprintf(
					/* translators: %s: API method name. */
					esc_html__( 'This Temu app is not authorised to call %s. Ask the seller to re-authorise the app and grant the product publishing permission, or request the scope on the Temu partner console.', 'woo-temu-sync' ),
					esc_html( Schema::METHOD_ADD )
				),
				3000032
			);
		}
	}
}
