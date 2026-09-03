<?php
/**
 * Field limits and helpers for the Temu product schema.
 *
 * @package WooTemuSync
 */

namespace WooTemuSync\Product;

defined( 'ABSPATH' ) || exit;

/**
 * Constraints published for temu.local.goods.v3.add, plus the pure functions
 * that enforce them.
 *
 * Temu silently truncates or discards values that break these limits rather
 * than rejecting the call, which turns a bad listing into a mystery. Applying
 * the limits here means what we send is what we meant to send.
 */
final class Schema {

	/**
	 * The V3 create method.
	 */
	const METHOD_ADD = 'temu.local.goods.v3.add';

	const MAX_GOODS_NAME     = 500;
	const MAX_GOODS_DESC     = 10000;
	const MAX_EXT_CAT_NAME   = 500;
	const MAX_EXTERNAL_ID    = 128;
	const MAX_CAROUSEL_IMAGE = 10;
	const MAX_DETAIL_IMAGE   = 50;
	const MAX_SKU_IMAGE      = 10;
	const MAX_ATTRIBUTES     = 200;
	const MAX_ATTR_LENGTH    = 128;
	const MAX_ATTR_VALUES    = 1000;
	const MAX_SKUS           = 500;
	const MAX_VARIATIONS     = 5;

	/**
	 * Product type enumeration.
	 */
	const TYPE_NORMAL      = 1;
	const TYPE_CUSTOM      = 2;
	const TYPE_MTO         = 3;
	const TYPE_SECOND_HAND = 4;

	/**
	 * Barcode types Temu accepts.
	 */
	const BARCODE_TYPES = array( 'EAN', 'UPC', 'ISBN', 'GTIN-14' );

	/**
	 * Flatten rich text to a plain single-line string within a length limit.
	 *
	 * Temu strips rich text tags and filters emoji from names and attribute
	 * values anyway; doing it up front keeps the value we store as "sent"
	 * identical to what Temu will hold.
	 *
	 * @param string $text  Raw text.
	 * @param int    $limit Maximum characters.
	 * @return string
	 */
	public static function plain_text( $text, $limit ) {
		$text = (string) $text;
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = self::strip_emoji( $text );
		$text = preg_replace( '/\s+/u', ' ', $text );
		$text = trim( (string) $text );

		return self::truncate( $text, $limit );
	}

	/**
	 * Trim rich text to a length limit, keeping the markup Temu allows.
	 *
	 * @param string $html  Raw HTML.
	 * @param int    $limit Maximum characters.
	 * @return string
	 */
	public static function rich_text( $html, $limit ) {
		$html = trim( (string) $html );

		return self::truncate( $html, $limit );
	}

	/**
	 * Multibyte-safe truncation.
	 *
	 * @param string $text  Text.
	 * @param int    $limit Maximum characters.
	 * @return string
	 */
	public static function truncate( $text, $limit ) {
		return mb_strlen( $text, 'UTF-8' ) > $limit
			? mb_substr( $text, 0, $limit, 'UTF-8' )
			: $text;
	}

	/**
	 * Remove emoji and other pictographic characters.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function strip_emoji( $text ) {
		$stripped = preg_replace(
			'/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}]/u',
			'',
			$text
		);

		return null === $stripped ? $text : $stripped;
	}

	/**
	 * Format a price as the decimal string Temu expects.
	 *
	 * Money is never sent as a JSON number: the signature is computed over the
	 * literal string, and float formatting would make the signed value and the
	 * intended value drift apart. Temu rejects amounts with more decimals than
	 * the currency allows (error 150011018).
	 *
	 * @param mixed $amount   Price value.
	 * @param int   $decimals Decimal places for the currency.
	 * @return string
	 */
	public static function money( $amount, $decimals = 2 ) {
		return number_format( (float) $amount, (int) $decimals, '.', '' );
	}

	/**
	 * Format a physical measurement as a bare numeric string.
	 *
	 * Temu wants numbers without units, and treats 0 as "not supplied" and
	 * substitutes its own defaults, so a rounded-to-zero value is returned as
	 * an empty string for the caller to omit.
	 *
	 * @param mixed $value    Measurement.
	 * @param int   $decimals Decimal places.
	 * @return string
	 */
	public static function measurement( $value, $decimals = 2 ) {
		$value = (float) $value;

		if ( $value <= 0 ) {
			return '';
		}

		$formatted = number_format( $value, $decimals, '.', '' );

		if ( 0.0 === (float) $formatted ) {
			return '';
		}

		// Trim pointless trailing zeros: "22.00" and "22" are the same weight,
		// and the shorter form is easier to eyeball in a log. Only ever touch
		// the fractional part -- trimming "350" down to "35" would ship the
		// wrong weight.
		if ( false !== strpos( $formatted, '.' ) ) {
			$formatted = rtrim( rtrim( $formatted, '0' ), '.' );
		}

		return $formatted;
	}

	/**
	 * Normalise a barcode type to one Temu recognises.
	 *
	 * @param string $type Raw type.
	 * @return string Empty string when unrecognised.
	 */
	public static function barcode_type( $type ) {
		$type = strtoupper( trim( (string) $type ) );

		return in_array( $type, self::BARCODE_TYPES, true ) ? $type : '';
	}

	/**
	 * Guess a barcode type from the code itself.
	 *
	 * WooCommerce stores GTIN, UPC, EAN and ISBN in one field, so the type has
	 * to be inferred from length. Temu corrects a mismatched type on its side,
	 * but sending the right one avoids a needless round trip.
	 *
	 * @param string $code Barcode digits.
	 * @return string Empty string when it does not look like a barcode.
	 */
	public static function detect_barcode_type( $code ) {
		$digits = preg_replace( '/[^0-9Xx]/', '', (string) $code );

		switch ( strlen( (string) $digits ) ) {
			case 14:
				return 'GTIN-14';
			case 13:
				// A 13 digit code starting 978/979 is a bookland ISBN.
				return preg_match( '/^97[89]/', $digits ) ? 'ISBN' : 'EAN';
			case 12:
				return 'UPC';
			case 10:
				return 'ISBN';
			case 8:
				return 'EAN';
			default:
				return '';
		}
	}

	/**
	 * Whether a URL is publicly fetchable by Temu's image crawler.
	 *
	 * Temu downloads images from the URLs we send. A localhost or private URL
	 * leaves the product stuck in Draft with no useful error, so those are
	 * caught before the call rather than after.
	 *
	 * @param string $url Image URL.
	 * @return bool
	 */
	public static function is_public_image_url( $url ) {
		$url = trim( (string) $url );

		/**
		 * Filter whether an image URL is considered reachable by Temu.
		 *
		 * Development sites are the reason this exists. A Local by WP Engine site
		 * is served from a .local host, so every product published from one is
		 * refused here - correctly, because Temu genuinely cannot fetch those
		 * images. Return true to publish anyway when the images are reachable by
		 * some other route, such as a tunnel or a CDN rewrite.
		 *
		 * @since 0.1.0
		 *
		 * @param bool|null $reachable Null to run the built-in check, or a boolean to decide.
		 * @param string    $url       The image URL.
		 */
		$override = apply_filters( 'wtsync_is_public_image_url', null, $url );

		if ( null !== $override ) {
			return (bool) $override;
		}

		if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
			return false;
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( ! $host ) {
			return false;
		}

		$host = strtolower( $host );

		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return false;
		}

		// No dot means an intranet name such as "staging" or "shop.local".
		if ( false === strpos( $host, '.' ) || preg_match( '/\.(local|test|localhost|internal|invalid)$/', $host ) ) {
			return false;
		}

		// RFC1918 and link-local ranges.
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return (bool) filter_var(
				$host,
				FILTER_VALIDATE_IP,
				FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
			);
		}

		return true;
	}
}
