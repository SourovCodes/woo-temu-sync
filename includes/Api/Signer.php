<?php
/**
 * Request signing for the Temu Open API.
 *
 * @package WooTemuSync
 */

namespace WooTemuSync\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the MD5 request signature Temu expects on every call.
 *
 * Algorithm (per Temu "Signature Method for API Request"):
 *   1. Sort every request parameter by key, ASCII ascending.
 *   2. Concatenate as key . value with no separators.
 *   3. Prepend AND append the app_secret.
 *   4. MD5 the result, uppercase it.
 *
 * The subtlety that breaks most implementations: nested objects and arrays are
 * signed as their JSON serialisation, so the string fed to MD5 must be byte
 * identical to what ends up in the request body. Signer::encode_value() and
 * Client::build_body() therefore share one encoder with one set of flags.
 */
final class Signer {

	/**
	 * Parameters that are never part of the signature base string.
	 */
	const EXCLUDED = array( 'sign' );

	/**
	 * JSON flags used for both signing and the wire body.
	 *
	 * Temu's documented example contains no slashes or unicode, so it cannot
	 * settle whether they canonicalise with escaping. Using one encoder for
	 * both halves keeps us self consistent either way. If the API starts
	 * rejecting calls with a signature error once image URLs or non-ASCII
	 * titles are involved, flip these flags first -- see the wtsync_json_flags
	 * filter.
	 *
	 * @return int
	 */
	public static function json_flags() {
		/**
		 * Filter the JSON flags used for both signing and the request body.
		 *
		 * @since 0.1.0
		 *
		 * @param int $flags Bitmask passed to wp_json_encode().
		 */
		return (int) apply_filters( 'wtsync_json_flags', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Serialise a single parameter value the way Temu expects to see it.
	 *
	 * @param mixed $value Raw parameter value.
	 * @return string
	 *
	 * @throws \InvalidArgumentException When the value is a float, which cannot be signed reliably.
	 */
	public static function encode_value( $value ) {
		if ( is_string( $value ) ) {
			return $value;
		}

		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		if ( is_int( $value ) ) {
			return (string) $value;
		}

		if ( is_float( $value ) ) {
			// Floats are never safe to sign: 1.10 and 1.1 hash differently and
			// PHP's float to string conversion is locale and precision bound.
			// Callers must pass money as a string or in minor units.
			throw new \InvalidArgumentException(
				'Float parameters cannot be signed reliably. Pass the value as a string instead.'
			);
		}

		if ( is_array( $value ) || is_object( $value ) ) {
			return (string) self::json_encode( $value );
		}

		return '';
	}

	/**
	 * JSON encode a value with the shared flags.
	 *
	 * Uses wp_json_encode() rather than json_encode() so the depth guard and the
	 * invalid-UTF-8 handling apply: a product title carrying a broken byte
	 * would otherwise silently encode as false and sign an empty value.
	 *
	 * @param mixed $value Value to encode.
	 * @return string
	 */
	public static function json_encode( $value ) {
		return (string) wp_json_encode( $value, self::json_flags() );
	}

	/**
	 * Normalise a parameter set into the flat scalar map used for signing.
	 *
	 * Null values are dropped rather than signed as an empty string, which is
	 * what the platform does with absent optional parameters.
	 *
	 * @param array $params Raw parameters.
	 * @return array<string,string>
	 */
	public static function normalize( array $params ) {
		$normalized = array();

		foreach ( $params as $key => $value ) {
			if ( null === $value || in_array( $key, self::EXCLUDED, true ) ) {
				continue;
			}

			$normalized[ (string) $key ] = self::encode_value( $value );
		}

		return $normalized;
	}

	/**
	 * Build the exact string that gets hashed. Exposed for debugging and tests.
	 *
	 * @param array  $params     Raw parameters.
	 * @param string $app_secret Application secret.
	 * @return string
	 */
	public static function base_string( array $params, $app_secret ) {
		$normalized = self::normalize( $params );

		// SORT_STRING gives byte-wise ASCII ordering. Do not use the default
		// sort flags here: PHP would compare numeric-looking keys as numbers.
		ksort( $normalized, SORT_STRING );

		$buffer = $app_secret;
		foreach ( $normalized as $key => $value ) {
			$buffer .= $key . $value;
		}
		$buffer .= $app_secret;

		return $buffer;
	}

	/**
	 * Compute the uppercase MD5 signature for a request.
	 *
	 * @param array  $params     Raw parameters, excluding sign.
	 * @param string $app_secret Application secret.
	 * @return string
	 */
	public static function sign( array $params, $app_secret ) {
		return strtoupper( md5( self::base_string( $params, $app_secret ) ) );
	}
}
