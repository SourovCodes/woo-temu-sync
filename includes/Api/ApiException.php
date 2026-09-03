<?php
/**
 * Typed exception for Temu API failures.
 *
 * @package WooTemuSync
 */

namespace WooTemuSync\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps a Temu gateway or business error with enough context to decide what to
 * do next: retry, re-authorise, or stop and tell the merchant.
 */
class ApiException extends \Exception {

	const SUCCESS = 1000000;

	/**
	 * Errors worth retrying with backoff. Everything else is deterministic and
	 * retrying only burns rate limit.
	 */
	const RETRYABLE = array(
		2000000, // BUSINESS_EXCEPTION, internal call failed.
		4000000, // SYSTEM_EXCEPTION.
		4000004, // RATE_LIMIT_EXCEED_EXCEPTION.
		6000001, // RPC_INTERFACE_NOT_FOUND, internal API missing.
		7000000, // BUSINESS_SERVICE_ERROR.
	);

	/**
	 * Errors that mean the seller must re-authorise the app in Seller Center.
	 */
	const REAUTH_REQUIRED = array(
		3000030, // access_token missing.
		3000031, // access_token not exists.
		3000032, // access_token lacks permission for this API.
		3000033, // access_token and app_key are not mapped.
		3000034, // access_token expired or refreshed.
		5000000, // AUTHORIZE_NOT_ALLOW.
	);

	/**
	 * Errors caused by the app registration itself, not by the seller.
	 */
	const APP_CONFIG_ERROR = array(
		3000019,
		3000020,
		3000021,
		3000022,
		3000025,
		3000026,
		3000027,
		3000028,
		5000003, // NOT_IN_IP_WHITE_LIST.
	);

	/**
	 * Temu error code.
	 *
	 * @var int
	 */
	protected $error_code;

	/**
	 * Temu request id, for support tickets.
	 *
	 * @var string
	 */
	protected $request_id;

	/**
	 * API method that failed.
	 *
	 * @var string
	 */
	protected $api_type;

	/**
	 * Constructor.
	 *
	 * @param string $message    Error message.
	 * @param int    $error_code Temu error code.
	 * @param string $request_id Temu request id.
	 * @param string $api_type   API method name.
	 */
	public function __construct( $message, $error_code = 0, $request_id = '', $api_type = '' ) {
		parent::__construct( $message, (int) $error_code );

		$this->error_code = (int) $error_code;
		$this->request_id = (string) $request_id;
		$this->api_type   = (string) $api_type;
	}

	/**
	 * Temu error code.
	 *
	 * @return int
	 */
	public function get_error_code() {
		return $this->error_code;
	}

	/**
	 * Temu request id.
	 *
	 * @return string
	 */
	public function get_request_id() {
		return $this->request_id;
	}

	/**
	 * API method that failed.
	 *
	 * @return string
	 */
	public function get_api_type() {
		return $this->api_type;
	}

	/**
	 * Whether the call is worth retrying.
	 *
	 * @return bool
	 */
	public function is_retryable() {
		return in_array( $this->error_code, self::RETRYABLE, true );
	}

	/**
	 * Whether the seller has to reconnect the store.
	 *
	 * @return bool
	 */
	public function needs_reauth() {
		return in_array( $this->error_code, self::REAUTH_REQUIRED, true );
	}

	/**
	 * Whether the fault lies with the app registration rather than the store.
	 *
	 * @return bool
	 */
	public function is_app_config_error() {
		return in_array( $this->error_code, self::APP_CONFIG_ERROR, true );
	}

	/**
	 * Whether this is the rate limiter.
	 *
	 * @return bool
	 */
	public function is_rate_limited() {
		return 4000004 === $this->error_code;
	}

	/**
	 * A plain-language hint aimed at whoever has to fix this.
	 *
	 * Temu's own messages are terse and often describe the gateway's internal
	 * view rather than the merchant's, so the common ones get translated here.
	 *
	 * @return string
	 */
	public function get_hint() {
		switch ( $this->error_code ) {
			case 3000001:
				return __( 'Signature rejected. If this started when products with image URLs or non-ASCII titles were added, the JSON encoding flags are the likely cause - see the wtsync_json_flags filter.', 'woo-temu-sync' );

			case 3000011:
			case 3000012:
				return __( 'Timestamp rejected. Temu allows 300 seconds of clock drift; check that this server\'s clock is synchronised via NTP.', 'woo-temu-sync' );

			case 3000021:
			case 3000027:
				return __( 'The app is not permitted to call this API. Request the scope for this method on the Temu partner console.', 'woo-temu-sync' );

			case 3000032:
				return __( 'The seller has not granted this permission. Ask them to re-authorise the app in Seller Center and tick the required scopes.', 'woo-temu-sync' );

			case 3000034:
				return __( 'The access token expired or was replaced. Reconnect the store to issue a new one.', 'woo-temu-sync' );

			case 4000004:
				return __( 'Rate limit exceeded. The default allowance is 20 requests per second per app; the sync will back off and retry.', 'woo-temu-sync' );

			case 5000003:
				return __( 'This server\'s IP address is not on the app\'s allow list on the Temu partner console.', 'woo-temu-sync' );

			default:
				return '';
		}
	}
}
