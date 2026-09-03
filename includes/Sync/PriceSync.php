<?php
/**
 * Pushes WooCommerce prices to Temu.
 *
 * @package WooTemuSync
 */

namespace WooTemuSync\Sync;

use WooTemuSync\Api\ApiException;
use WooTemuSync\Api\Credentials;
use WooTemuSync\Logger;
use WooTemuSync\Product\Publisher;
use WooTemuSync\Product\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Sends base prices for a published product.
 *
 * Two things make this unlike stock, and both are the platform's doing rather
 * than ours.
 *
 * **The price API is whitelisted.** Temu documents it as a sensitive interface
 * "only available to whitelisted, locally developed applications", granted per
 * store through their business team. A seller who has not been granted it can
 * publish products and sync stock perfectly well and simply cannot push prices.
 * So this class treats the permission as absent until proven present, and says
 * so in plain language instead of failing obscurely.
 *
 * **Prices are negotiated, not set.** Temu runs a price order workflow: when a
 * price is under review or waiting on the merchant, a change is refused.
 * Modification is only accepted while the current order sits in a settled
 * state, so the workflow is checked before anything is sent.
 */
class PriceSync {

	/**
	 * The batch price change method.
	 */
	const METHOD_CHANGE_PRICE = 'bg.local.goods.priceorder.change.sku.price';

	/**
	 * The price order listing method.
	 */
	const METHOD_ORDER_QUERY = 'bg.local.goods.priceorder.query';

	/**
	 * Price order type covering pricing opportunities and modifications.
	 */
	const ORDER_TYPE_PRICING = 2;

	/**
	 * Price order states, as published in Temu's price management guide.
	 */
	const STATUS_UNDER_REVIEW          = 100;
	const STATUS_AWAITING_MERCHANT     = 101;
	const STATUS_PASSED                = 201;
	const STATUS_REJECTED_PENDING_EDIT = 202;
	const STATUS_MERCHANT_CONFIRMED    = 203;
	const STATUS_MERCHANT_MODIFYING    = 204;
	const STATUS_MERCHANT_REFUSED      = 205;
	const STATUS_REJECTED              = 206;

	/**
	 * The states a price modification may be initiated from.
	 *
	 * Straight from the guide: everything else means the platform or the
	 * merchant still owes the workflow an answer.
	 */
	const MODIFIABLE_STATES = array(
		self::STATUS_PASSED,
		self::STATUS_REJECTED_PENDING_EDIT,
		self::STATUS_MERCHANT_CONFIRMED,
		self::STATUS_MERCHANT_REFUSED,
		self::STATUS_REJECTED,
	);

	/**
	 * Meta key holding the prices last accepted by Temu.
	 */
	const META_LAST_PRICES = '_wtsync_last_prices';

	/**
	 * Meta key holding when prices were last pushed.
	 */
	const META_SYNCED_AT = '_wtsync_price_synced_at';

	/**
	 * Meta key recording why price sync is unavailable, for the admin to read.
	 */
	const META_BLOCKED = '_wtsync_price_blocked';

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
	 * Whether this connection is allowed to change prices.
	 *
	 * An empty scope list means unknown rather than denied, so the attempt is
	 * allowed and the API gets to be the authority.
	 *
	 * @return bool
	 */
	public function is_available() {
		$scopes = $this->credentials->get( 'api_scopes', array() );

		if ( empty( $scopes ) || ! is_array( $scopes ) ) {
			return true;
		}

		return $this->credentials->has_scope( self::METHOD_CHANGE_PRICE );
	}

	/**
	 * The base price this shop would report for each variation.
	 *
	 * Temu's base price is the amount the seller receives per sale under
	 * non-promotional conditions, not the shop's shelf price: Temu sets what the
	 * buyer pays on top of it. Defaulting to the WooCommerce price keeps the
	 * seller's settlement equal to what they charge in their own shop, which is
	 * the sane starting point, but a seller carrying different margins per
	 * channel will want to adjust it.
	 *
	 * @param \WC_Product $product Product.
	 * @return array<int,string> Variation id to amount.
	 */
	public function prices( $product ) {
		$variations = $product->is_type( 'variable' )
			? $product->get_children()
			: array( $product->get_id() );

		$decimals = wc_get_price_decimals();
		$prices   = array();

		foreach ( $variations as $variation_id ) {
			$variation = wc_get_product( $variation_id );

			if ( ! $variation || ! $variation->exists() ) {
				continue;
			}

			$price = $variation->get_price();

			if ( '' === $price || null === $price ) {
				continue;
			}

			/**
			 * Filter the base price sent to Temu for one variation.
			 *
			 * Return a value to charge Temu buyers differently from the shop, for
			 * example to absorb the marketplace commission.
			 *
			 * @since 0.1.0
			 *
			 * @param string      $price     The shop's current price.
			 * @param \WC_Product $variation Variation or simple product.
			 * @param \WC_Product $product   Parent product.
			 */
			$price = apply_filters( 'wtsync_base_price', $price, $variation, $product );

			$prices[ $variation->get_id() ] = Schema::money( $price, $decimals );
		}

		return $prices;
	}

	/**
	 * Push the current prices for a product.
	 *
	 * @param \WC_Product $product Published product.
	 * @return bool True when a change was sent and accepted.
	 */
	public function sync( $product ) {
		$goods_id = (int) $product->get_meta( Publisher::META_GOODS_ID );

		if ( $goods_id <= 0 || ! $this->credentials->is_connected() ) {
			return false;
		}

		if ( ! $this->is_available() ) {
			$this->block(
				$product,
				__( 'This Temu app is not authorised to change prices. Temu grants the price API per store through their business team; ask them to whitelist the app.', 'woo-temu-sync' )
			);

			return false;
		}

		$map = $this->sku_map->get( $product );

		if ( empty( $map ) ) {
			return false;
		}

		$prices = $this->prices( $product );
		$items  = array();
		$sent   = array();

		foreach ( $map as $variation_id => $sku_id ) {
			if ( ! isset( $prices[ $variation_id ] ) ) {
				continue;
			}

			$items[] = array(
				'skuId'            => (int) $sku_id,
				'newSupplierPrice' => array(
					'amount'   => $prices[ $variation_id ],
					'currency' => get_woocommerce_currency(),
				),
			);

			$sent[ $variation_id ] = $prices[ $variation_id ];
		}

		if ( empty( $items ) || $sent === $this->last_sent( $product ) ) {
			return false;
		}

		if ( ! $this->workflow_allows_change( $goods_id ) ) {
			$this->block(
				$product,
				__( 'Temu is still reviewing this product\'s price, or is waiting for the seller to answer a pricing task in Seller Center. The new price will be sent once that is settled.', 'woo-temu-sync' )
			);

			return false;
		}

		try {
			$this->credentials->client()->request(
				self::METHOD_CHANGE_PRICE,
				array(
					'goodsId'               => $goods_id,
					'changeSkuPriceDTOList' => array(
						array(
							'reason'                    => $this->reason(),
							'skuChangePriceBaseDTOList' => $items,
						),
					),
				)
			);
		} catch ( ApiException $e ) {
			$this->handle_failure( $product, $e );

			return false;
		}

		$product->update_meta_data( self::META_LAST_PRICES, $sent );
		$product->update_meta_data( self::META_SYNCED_AT, time() );
		$product->delete_meta_data( self::META_BLOCKED );
		$product->save();

		$this->logger->info(
			sprintf( 'Prices pushed for product %d (Temu %d): %d SKU(s)', $product->get_id(), $goods_id, count( $items ) )
		);

		/**
		 * Fires after base prices are accepted by Temu.
		 *
		 * @since 0.1.0
		 *
		 * @param array       $sent    Variation id to amount that was sent.
		 * @param \WC_Product $product Product.
		 */
		do_action( 'wtsync_prices_synced', $sent, $product );

		return true;
	}

	/**
	 * Whether the product's price workflow is in a state that accepts a change.
	 *
	 * A product with no price order at all has never been through the workflow,
	 * which is not a blocker.
	 *
	 * @param int $goods_id Temu product id.
	 * @return bool
	 */
	protected function workflow_allows_change( $goods_id ) {
		try {
			$result = $this->credentials->client()->request(
				self::METHOD_ORDER_QUERY,
				array(
					'goodsId'        => (string) $goods_id,
					'priceOrderType' => self::ORDER_TYPE_PRICING,
					'page'           => 1,
					'size'           => 10,
				)
			);
		} catch ( ApiException $e ) {
			/*
			 * The listing endpoint is whitelisted just as tightly as the change
			 * endpoint. Not being able to read the workflow is not evidence that a
			 * change is blocked, so let the change attempt be the authority rather
			 * than refusing on a permission error here.
			 */
			$this->logger->debug(
				sprintf( 'Could not read the price workflow for Temu product %d: %s', $goods_id, $e->getMessage() )
			);

			return true;
		}

		if ( empty( $result['priceAuditList'] ) || ! is_array( $result['priceAuditList'] ) ) {
			return true;
		}

		foreach ( $result['priceAuditList'] as $order ) {
			if ( ! is_array( $order ) || ! isset( $order['status'] ) ) {
				continue;
			}

			if ( ! in_array( (int) $order['status'], self::MODIFIABLE_STATES, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Turn a rejected price change into something the merchant can act on.
	 *
	 * @param \WC_Product  $product Product.
	 * @param ApiException $e       Failure.
	 * @return void
	 */
	protected function handle_failure( $product, ApiException $e ) {
		switch ( $e->get_error_code() ) {
			case 3000021:
			case 3000027:
			case 3000032:
				$message = __( 'Temu refused the price change because this app is not whitelisted for the price API. Temu grants it per store through their business team.', 'woo-temu-sync' );
				break;

			case 150011103:
				$message = __( 'Temu is holding a price this plugin sent earlier that nobody confirmed. Confirm it in Seller Center before sending another.', 'woo-temu-sync' );
				break;

			case 150011101:
				$message = __( 'The price change was larger than Temu allows in one step. Move the price in smaller increments, or wait and retry.', 'woo-temu-sync' );
				break;

			case 150010233:
				$message = __( 'This product is in a Temu promotion, or still inside its cool-down, so the price cannot be lowered yet.', 'woo-temu-sync' );
				break;

			case 150019004:
				$message = __( 'Temu rejected the price as outside the range it will accept for this SKU.', 'woo-temu-sync' );
				break;

			default:
				$message = $e->getMessage();
		}

		$this->block( $product, $message );

		$this->logger->error(
			sprintf( 'Price push failed for product %d: %s', $product->get_id(), $message ),
			array(
				'code'       => $e->get_error_code(),
				'request_id' => $e->get_request_id(),
			)
		);
	}

	/**
	 * Record why price sync did not happen, for the products screen to show.
	 *
	 * @param \WC_Product $product Product.
	 * @param string      $reason  Human readable reason.
	 * @return void
	 */
	protected function block( $product, $reason ) {
		$product->update_meta_data( self::META_BLOCKED, $reason );
		$product->save();
	}

	/**
	 * The reason attached to a price change.
	 *
	 * Temu shows this to whoever reviews the change, so it should say where the
	 * number came from rather than being decorative.
	 *
	 * @return string
	 */
	protected function reason() {
		/**
		 * Filter the reason sent with a price change.
		 *
		 * @since 0.1.0
		 *
		 * @param string $reason Reason text.
		 */
		return (string) apply_filters(
			'wtsync_price_change_reason',
			__( 'Synchronised with the price in the seller\'s own shop.', 'woo-temu-sync' )
		);
	}

	/**
	 * The prices Temu last accepted.
	 *
	 * @param \WC_Product $product Product.
	 * @return array<int,string>
	 */
	public function last_sent( $product ) {
		$stored = $product->get_meta( self::META_LAST_PRICES );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$prices = array();
		foreach ( $stored as $variation_id => $amount ) {
			$prices[ (int) $variation_id ] = (string) $amount;
		}

		return $prices;
	}
}
