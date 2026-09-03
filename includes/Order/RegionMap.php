<?php
/**
 * Temu region identifiers.
 *
 * @package WooTemuSync
 */

namespace WooTemuSync\Order;

defined( 'ABSPATH' ) || exit;

/**
 * Translates Temu's numeric regionId into an ISO 3166-1 alpha-2 country code.
 *
 * Temu identifies the buyer's country by its own integer and reports the
 * country name only as free text, so an order cannot be given a WooCommerce
 * country without this. The table covers the markets Temu actually sells in;
 * anything outside it falls back to matching the name Temu supplied against
 * WooCommerce's own country list, which is how a newly opened market keeps
 * working before this table is updated.
 */
final class RegionMap {

	/**
	 * Temu regionId to ISO 3166-1 alpha-2.
	 *
	 * Taken from the regionId enum in Temu's order retrieval guide.
	 */
	const REGIONS = array(
		12  => 'AU',
		13  => 'AT',
		20  => 'BE',
		29  => 'BR',
		32  => 'BG',
		37  => 'CA',
		42  => 'CL',
		45  => 'CO',
		50  => 'HR',
		52  => 'CY',
		53  => 'CZ',
		54  => 'DK',
		64  => 'EE',
		68  => 'FI',
		69  => 'FR',
		76  => 'DE',
		79  => 'GR',
		90  => 'HU',
		91  => 'IS',
		96  => 'IE',
		98  => 'IT',
		100 => 'JP',
		108 => 'LV',
		112 => 'LI',
		113 => 'LT',
		114 => 'LU',
		119 => 'MY',
		122 => 'MT',
		128 => 'MX',
		141 => 'NL',
		144 => 'NZ',
		151 => 'NO',
		162 => 'PL',
		163 => 'PT',
		167 => 'RO',
		179 => 'SG',
		180 => 'SK',
		181 => 'SI',
		184 => 'ZA',
		185 => 'KR',
		186 => 'ES',
		191 => 'SE',
		192 => 'CH',
		210 => 'GB',
		211 => 'US',
	);

	/**
	 * Resolve a country code for an order.
	 *
	 * @param int    $region_id   Temu regionId.
	 * @param string $region_name Country name Temu returned with the address.
	 * @return string ISO 3166-1 alpha-2, or an empty string when unresolvable.
	 */
	public static function country_code( $region_id, $region_name = '' ) {
		$region_id = (int) $region_id;

		if ( isset( self::REGIONS[ $region_id ] ) ) {
			return self::REGIONS[ $region_id ];
		}

		$code = self::from_name( $region_name );

		/**
		 * Filter the country code resolved for a Temu order.
		 *
		 * @since 0.1.0
		 *
		 * @param string $code        Resolved ISO 3166-1 alpha-2 code, or an empty string.
		 * @param int    $region_id   Temu regionId.
		 * @param string $region_name Country name Temu supplied.
		 */
		return (string) apply_filters( 'wtsync_order_country_code', $code, $region_id, $region_name );
	}

	/**
	 * Match a country name against WooCommerce's own list.
	 *
	 * @param string $region_name Country name.
	 * @return string ISO code, or an empty string.
	 */
	protected static function from_name( $region_name ) {
		$region_name = trim( (string) $region_name );

		if ( '' === $region_name || ! function_exists( 'WC' ) || ! WC()->countries ) {
			return '';
		}

		$needle = strtolower( $region_name );

		foreach ( WC()->countries->get_countries() as $code => $name ) {
			if ( strtolower( wp_strip_all_tags( $name ) ) === $needle ) {
				return $code;
			}
		}

		return '';
	}
}
