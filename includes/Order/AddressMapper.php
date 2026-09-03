<?php
/**
 * Temu shipping address to WooCommerce address.
 *
 * @package WooTemuSync
 */

namespace WooTemuSync\Order;

defined( 'ABSPATH' ) || exit;

/**
 * Turns Temu's shipping payload into the fields a WooCommerce order expects.
 *
 * Temu returns the address in two forms from two endpoints with the same shape:
 * bg.order.shippinginfo.v2.get gives a masked version, and
 * bg.order.decryptshippinginfo.get gives the real one. Only the second can
 * actually be shipped to, which is why fetching it is a deliberate step rather
 * than something the importer does for every order it sees.
 */
class AddressMapper {

	/**
	 * Build a WooCommerce shipping address.
	 *
	 * @param array $shipping  Decoded shipping info result.
	 * @param int   $region_id Temu regionId from the parent order.
	 * @return array Address fields, ready for WC_Order::set_address().
	 */
	public function to_wc_address( array $shipping, $region_id = 0 ) {
		$extra = isset( $shipping['addressExtra'] ) && is_array( $shipping['addressExtra'] )
			? $shipping['addressExtra']
			: array();

		list( $first_name, $last_name ) = $this->names( $shipping, $extra );

		$address = array(
			'first_name' => $first_name,
			'last_name'  => $last_name,
			'address_1'  => $this->text( $shipping, 'addressLine1' ),
			'address_2'  => $this->address_line_2( $shipping ),
			'city'       => $this->city( $shipping ),
			'state'      => $this->text( $shipping, 'regionName2' ),
			'postcode'   => $this->text( $shipping, 'postCode' ),
			'country'    => RegionMap::country_code( $region_id, $this->text( $shipping, 'regionName1' ) ),
			'phone'      => $this->text( $shipping, 'mobile' ),
		);

		/**
		 * Filter the WooCommerce address built from a Temu order.
		 *
		 * @since 0.1.0
		 *
		 * @param array $address  WooCommerce address fields.
		 * @param array $shipping Raw Temu shipping payload.
		 */
		return apply_filters( 'wtsync_order_address', $address, $shipping );
	}

	/**
	 * Split the recipient into a first and last name.
	 *
	 * The structured names in addressExtra are preferred; receiptName is the
	 * fallback and is split on the last space, which is the least-wrong guess
	 * for a single display name.
	 *
	 * @param array $shipping Shipping payload.
	 * @param array $extra    The addressExtra member.
	 * @return array{0:string,1:string}
	 */
	protected function names( array $shipping, array $extra ) {
		$first = isset( $extra['firstName'] ) ? trim( (string) $extra['firstName'] ) : '';
		$last  = isset( $extra['lastName'] ) ? trim( (string) $extra['lastName'] ) : '';

		if ( '' !== $first || '' !== $last ) {
			return array( $first, $last );
		}

		$full = $this->text( $shipping, 'receiptName' );

		if ( '' === $full ) {
			return array( '', '' );
		}

		$position = strrpos( $full, ' ' );

		if ( false === $position ) {
			return array( $full, '' );
		}

		return array(
			trim( substr( $full, 0, $position ) ),
			trim( substr( $full, $position + 1 ) ),
		);
	}

	/**
	 * Join the remaining address lines into WooCommerce's single second line.
	 *
	 * Temu supplies up to three lines and WooCommerce holds two, so lines two
	 * and three are joined rather than the third being dropped: an apartment
	 * number is not optional information.
	 *
	 * @param array $shipping Shipping payload.
	 * @return string
	 */
	protected function address_line_2( array $shipping ) {
		$parts = array_filter(
			array(
				$this->text( $shipping, 'addressLine2' ),
				$this->text( $shipping, 'addressLine3' ),
			),
			static function ( $part ) {
				return '' !== $part;
			}
		);

		return implode( ', ', $parts );
	}

	/**
	 * The city, which Temu reports as the third region level.
	 *
	 * Some markets leave that empty and use the fourth, so fall back rather than
	 * shipping an order with no town on it.
	 *
	 * @param array $shipping Shipping payload.
	 * @return string
	 */
	protected function city( array $shipping ) {
		$city = $this->text( $shipping, 'regionName3' );

		return '' !== $city ? $city : $this->text( $shipping, 'regionName4' );
	}

	/**
	 * Read a scalar field as trimmed text.
	 *
	 * @param array  $source Payload.
	 * @param string $key    Field name.
	 * @return string
	 */
	protected function text( array $source, $key ) {
		if ( ! isset( $source[ $key ] ) || ! is_scalar( $source[ $key ] ) ) {
			return '';
		}

		return trim( (string) $source[ $key ] );
	}

	/**
	 * Whether a payload looks like the masked version rather than the real one.
	 *
	 * Temu masks phone numbers into a "XXX-XXX" shape in the undecrypted
	 * response. Shipping to a masked address fails, so the importer needs to be
	 * able to tell the two apart rather than trusting which endpoint it called.
	 *
	 * @param array $shipping Shipping payload.
	 * @return bool
	 */
	public function looks_masked( array $shipping ) {
		$mobile = $this->text( $shipping, 'mobile' );

		if ( '' !== $mobile && preg_match( '/^\**[-\s]*\**$/', $mobile ) ) {
			return true;
		}

		// A masked payload commonly blanks the street entirely.
		return '' === $this->text( $shipping, 'addressLine1' );
	}
}
