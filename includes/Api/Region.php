<?php
/**
 * Temu regional endpoints.
 *
 * @package WooTemuSync
 */

namespace WooTemuSync\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Maps a seller's Temu region to its API host and partner console.
 *
 * A seller account belongs to exactly one region and tokens are not portable
 * between them, so this is chosen once at connect time and then locked.
 */
final class Region {

	const EU     = 'eu';
	const US     = 'us';
	const GLOBAL = 'global';

	/**
	 * Seller types. Local sellers hold stock in-market and control their own
	 * pricing and shipping; crossborder sellers ship into Temu's warehouses.
	 * They authorise through different back ends.
	 */
	const SELLER_LOCAL       = 'local';
	const SELLER_CROSSBORDER = 'crossborder';

	/**
	 * Region definitions.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function all() {
		return array(
			self::EU     => array(
				'label'       => 'Europe',
				'host'        => 'https://openapi-b-eu.temu.com/openapi/router',
				'partner'     => 'https://partner-eu.temu.com',
				'seller'      => 'https://seller-eu.temu.com',
				'agentseller' => 'https://agentseller-eu.temu.com',
				'markets'     => 'Germany, France, Italy, Spain, Netherlands, Poland, United Kingdom and other European sites',
			),
			self::US     => array(
				'label'       => 'United States',
				'host'        => 'https://openapi-b-us.temu.com/openapi/router',
				'partner'     => 'https://partner-us.temu.com',
				'seller'      => 'https://seller.temu.com',
				'agentseller' => 'https://agentseller.temu.com',
				'markets'     => 'United States',
			),
			self::GLOBAL => array(
				'label'       => 'Global',
				'host'        => 'https://openapi-b-global.temu.com/openapi/router',
				'partner'     => 'https://partner.temu.com',
				'seller'      => 'https://seller.temu.com',
				'agentseller' => 'https://agentseller.temu.com',
				'markets'     => 'Mexico, Japan and other global sites',
			),
		);
	}

	/**
	 * Whether a region key is known.
	 *
	 * @param string $region Region key.
	 * @return bool
	 */
	public static function is_valid( $region ) {
		return array_key_exists( $region, self::all() );
	}

	/**
	 * API host for a region.
	 *
	 * @param string $region Region key.
	 * @return string
	 */
	public static function host( $region ) {
		$all = self::all();

		return isset( $all[ $region ] ) ? $all[ $region ]['host'] : $all[ self::EU ]['host'];
	}

	/**
	 * Partner console base URL for a region.
	 *
	 * @param string $region Region key.
	 * @return string
	 */
	public static function partner_url( $region ) {
		$all = self::all();

		return isset( $all[ $region ] ) ? $all[ $region ]['partner'] : $all[ self::EU ]['partner'];
	}

	/**
	 * Seller Center base URL for a region and seller type.
	 *
	 * @param string $region      Region key.
	 * @param string $seller_type Seller type.
	 * @return string
	 */
	public static function seller_center( $region, $seller_type = self::SELLER_LOCAL ) {
		$all = self::all();
		$def = isset( $all[ $region ] ) ? $all[ $region ] : $all[ self::EU ];

		return self::SELLER_CROSSBORDER === $seller_type ? $def['agentseller'] : $def['seller'];
	}

	/**
	 * URL of the Seller Center screen where a seller authorises an app by hand.
	 *
	 * Crossborder sellers get a slightly deeper path than local sellers.
	 *
	 * @param string $region      Region key.
	 * @param string $seller_type Seller type.
	 * @return string
	 */
	public static function authorization_manage_url( $region, $seller_type = self::SELLER_LOCAL ) {
		$base = self::seller_center( $region, $seller_type );

		return self::SELLER_CROSSBORDER === $seller_type
			? $base . '/open-platform/system-manage/client-manage'
			: $base . '/open-platform/client-manage';
	}

	/**
	 * Build the in-app authorisation URL a seller is sent to.
	 *
	 * Temu returns the seller to $redirect_uri with app_key, callback_host,
	 * code and state on the query string.
	 *
	 * @param string $region       Region key.
	 * @param string $app_key      Application key.
	 * @param string $redirect_uri Callback URL registered on the partner console.
	 * @param string $state        Opaque value echoed back, used here as a CSRF nonce.
	 * @param string $seller_type  Seller type.
	 * @return string
	 */
	public static function authorize_url( $region, $app_key, $redirect_uri, $state = '', $seller_type = self::SELLER_LOCAL ) {
		$args = array(
			'appKey'       => $app_key,
			'redirect_uri' => $redirect_uri,
		);

		if ( '' !== $state ) {
			$args['state'] = $state;
		}

		return self::seller_center( $region, $seller_type )
			. '/open-platform/client-manage/authorization?'
			. http_build_query( $args );
	}

	/**
	 * Resolve a region from the callback_host Temu returns after authorisation.
	 *
	 * More trustworthy than the region the merchant picked in the settings, so
	 * it is used to correct the stored value on connect.
	 *
	 * @param string $callback_host Host from the callback query string.
	 * @return string|null Region key, or null when unrecognised.
	 */
	public static function from_callback_host( $callback_host ) {
		$callback_host = strtolower( trim( (string) $callback_host ) );

		if ( '' === $callback_host ) {
			return null;
		}

		foreach ( self::all() as $key => $definition ) {
			if ( false !== strpos( $definition['host'], $callback_host ) ) {
				return $key;
			}
		}

		return null;
	}

	/**
	 * URL of the app edit screen where the redirect URL is configured.
	 *
	 * @param string $region  Region key.
	 * @param string $app_key Application key.
	 * @return string
	 */
	public static function app_settings_url( $region, $app_key ) {
		return self::partner_url( $region ) . '/app/app-mgmt/detail/edit?app_key=' . rawurlencode( $app_key );
	}
}
