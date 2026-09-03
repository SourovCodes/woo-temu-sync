<?php
/**
 * Storage for Temu app credentials and the seller access token.
 *
 * @package WooTemuSync
 */

namespace WooTemuSync\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the connection settings.
 *
 * The app secret and access token are encrypted at rest with a key derived
 * from the site's WordPress salts. That protects against a leaked database
 * dump; it does not protect against an attacker who already has wp-config.php,
 * which is an accepted limit of anything that must decrypt unattended.
 */
class Credentials {

	const OPTION = 'woo_temu_sync_connection';

	/**
	 * Cached settings.
	 *
	 * @var array|null
	 */
	protected $data = null;

	/**
	 * Default shape of the stored settings.
	 *
	 * @return array
	 */
	protected function defaults() {
		return array(
			'app_key'      => '',
			'app_secret'   => '',
			'access_token' => '',
			'region'       => Region::EU,
			'seller_type'  => Region::SELLER_LOCAL,
			'mall_id'      => '',
			'expires_at'   => 0,
			'connected_at' => 0,
			'api_scopes'   => array(),
			'events'       => array(),
		);
	}

	/**
	 * Load settings from the database.
	 *
	 * @return array
	 */
	public function all() {
		if ( null === $this->data ) {
			$stored = get_option( self::OPTION, array() );
			$stored = is_array( $stored ) ? $stored : array();

			$this->data = array_merge( $this->defaults(), $stored );

			foreach ( array( 'app_secret', 'access_token' ) as $secret ) {
				$this->data[ $secret ] = $this->decrypt( $this->data[ $secret ] );
			}
		}

		return $this->data;
	}

	/**
	 * Read a single setting.
	 *
	 * @param string $key      Setting name.
	 * @param mixed  $fallback Value returned when the setting is absent.
	 * @return mixed
	 */
	public function get( $key, $fallback = '' ) {
		$all = $this->all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Merge and persist settings.
	 *
	 * @param array $values Settings to write.
	 */
	public function update( array $values ) {
		$merged = array_merge( $this->all(), $values );

		$to_store = $merged;
		foreach ( array( 'app_secret', 'access_token' ) as $secret ) {
			$to_store[ $secret ] = $this->encrypt( $merged[ $secret ] );
		}

		update_option( self::OPTION, $to_store, false );

		$this->data = $merged;
	}

	/**
	 * Forget the seller-specific half of the connection.
	 *
	 * The app key and secret belong to the developer and survive a disconnect;
	 * the token, mall and granted scopes belong to the seller and do not.
	 */
	public function disconnect() {
		$this->update(
			array(
				'access_token' => '',
				'mall_id'      => '',
				'expires_at'   => 0,
				'connected_at' => 0,
				'api_scopes'   => array(),
				'events'       => array(),
			)
		);
	}

	/**
	 * Whether the app itself has been registered in the settings.
	 *
	 * @return bool
	 */
	public function has_app() {
		return '' !== $this->get( 'app_key' ) && '' !== $this->get( 'app_secret' );
	}

	/**
	 * Whether a store is currently connected.
	 *
	 * @return bool
	 */
	public function is_connected() {
		return $this->has_app() && '' !== $this->get( 'access_token' );
	}

	/**
	 * Whether the stored token is past its expiry.
	 *
	 * Temu issues tokens with roughly a year of life and offers no refresh
	 * grant, so the only remedy is for the seller to authorise again. Knowing
	 * this early lets the plugin warn instead of failing mid-sync.
	 *
	 * @return bool
	 */
	public function is_expired() {
		$expires = (int) $this->get( 'expires_at', 0 );

		return $expires > 0 && $expires <= time();
	}

	/**
	 * Seconds until the token expires, or null when unknown.
	 *
	 * @return int|null
	 */
	public function expires_in() {
		$expires = (int) $this->get( 'expires_at', 0 );

		return $expires > 0 ? $expires - time() : null;
	}

	/**
	 * Whether the connection holds a given API scope.
	 *
	 * Scopes on this platform are literal API method names, so a missing scope
	 * can be reported as the exact call that will fail.
	 *
	 * @param string $method API method name.
	 * @return bool
	 */
	public function has_scope( $method ) {
		$scopes = $this->get( 'api_scopes', array() );

		return is_array( $scopes ) && in_array( $method, $scopes, true );
	}

	/**
	 * Build a configured API client from the stored credentials.
	 *
	 * @return Client
	 */
	public function client() {
		return new Client(
			$this->get( 'app_key' ),
			$this->get( 'app_secret' ),
			$this->get( 'access_token' ),
			$this->get( 'region' )
		);
	}

	/**
	 * Encryption key derived from the site salts.
	 *
	 * @return string
	 */
	protected function key() {
		return hash( 'sha256', wp_salt( 'auth' ) . '|wtsync', true );
	}

	/**
	 * Whether this server can encrypt the stored secrets.
	 *
	 * Surfaced so the settings screen can refuse to take an app secret it would
	 * not be able to protect, rather than the merchant finding out later.
	 *
	 * @return bool
	 */
	public static function has_encryption() {
		return function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' );
	}

	/**
	 * Encrypt a value for storage.
	 *
	 * There is deliberately no plaintext fallback. A Temu access token is worth
	 * as much as the seller's password, and writing it to the options table in
	 * the clear because an extension happened to be missing would be a silent
	 * downgrade nobody would notice until the database leaked. Failing to store
	 * anything is recoverable; leaking a token is not.
	 *
	 * @param string $value Plain text.
	 * @return string Empty string when the value cannot be protected.
	 */
	protected function encrypt( $value ) {
		$value = (string) $value;

		if ( '' === $value ) {
			return '';
		}

		if ( ! self::has_encryption() ) {
			return '';
		}

		$iv         = random_bytes( 16 );
		$ciphertext = openssl_encrypt( $value, 'aes-256-cbc', $this->key(), OPENSSL_RAW_DATA, $iv );

		if ( false === $ciphertext ) {
			return '';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding raw AES output for storage in a text column, not obfuscating code.
		return 'wtsync1:' . base64_encode( $iv . $ciphertext );
	}

	/**
	 * Decrypt a stored value.
	 *
	 * Anything without the marker is returned untouched, which covers both an
	 * empty setting and a value some future format wrote.
	 *
	 * @param string $value Stored value.
	 * @return string
	 */
	protected function decrypt( $value ) {
		$value = (string) $value;

		if ( 0 !== strpos( $value, 'wtsync1:' ) || ! self::has_encryption() ) {
			return $value;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding this plugin's own ciphertext, written by encrypt() above.
		$binary = base64_decode( substr( $value, 6 ), true );

		if ( false === $binary || strlen( $binary ) <= 16 ) {
			return '';
		}

		$plain = openssl_decrypt(
			substr( $binary, 16 ),
			'aes-256-cbc',
			$this->key(),
			OPENSSL_RAW_DATA,
			substr( $binary, 0, 16 )
		);

		return false === $plain ? '' : $plain;
	}
}
