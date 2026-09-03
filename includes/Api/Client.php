<?php
/**
 * HTTP client for the Temu Open API.
 *
 * @package WooTemuSync
 */

namespace WooTemuSync\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Signs, sends and interprets a single Temu API call.
 *
 * Every Temu method goes to the same URL with the same POST envelope; the
 * method name travels in the `type` parameter. That makes one client class
 * enough for the whole surface.
 */
class Client {

	/**
	 * Seconds to wait for a response.
	 */
	const TIMEOUT = 30;

	/**
	 * How many times a retryable failure is re-sent before giving up.
	 */
	const MAX_ATTEMPTS = 4;

	/**
	 * Application key.
	 *
	 * @var string
	 */
	protected $app_key;

	/**
	 * Application secret.
	 *
	 * @var string
	 */
	protected $app_secret;

	/**
	 * Seller access token.
	 *
	 * @var string
	 */
	protected $access_token;

	/**
	 * Region key.
	 *
	 * @var string
	 */
	protected $region;

	/**
	 * Constructor.
	 *
	 * @param string $app_key      Application key.
	 * @param string $app_secret   Application secret.
	 * @param string $access_token Seller access token, empty during the auth handshake.
	 * @param string $region       Region key.
	 */
	public function __construct( $app_key, $app_secret, $access_token = '', $region = Region::EU ) {
		$this->app_key      = (string) $app_key;
		$this->app_secret   = (string) $app_secret;
		$this->access_token = (string) $access_token;
		$this->region       = Region::is_valid( $region ) ? $region : Region::EU;
	}

	/**
	 * Assemble the full parameter set for a call, signature included.
	 *
	 * Exposed so tests and the debug screen can inspect exactly what would go
	 * over the wire without sending it.
	 *
	 * @param string $type   API method name, for example bg.local.goods.list.query.
	 * @param array  $params Method specific parameters.
	 * @return array
	 */
	public function build_payload( $type, array $params = array() ) {
		$payload = array_merge(
			$params,
			array(
				'type'      => $type,
				'app_key'   => $this->app_key,
				'data_type' => 'JSON',
				'timestamp' => (string) $this->now(),
			)
		);

		// During the token handshake the access token is the code itself, and
		// bg.open.accesstoken.create is the only method callable without one.
		if ( '' !== $this->access_token ) {
			$payload['access_token'] = $this->access_token;
		}

		$payload['sign'] = Signer::sign( $payload, $this->app_secret );

		return $payload;
	}

	/**
	 * Perform an API call.
	 *
	 * @param string $type   API method name.
	 * @param array  $params Method specific parameters.
	 * @return array The `result` member of a successful response.
	 *
	 * @throws ApiException When the call fails after all retries.
	 */
	public function request( $type, array $params = array() ) {
		$payload = $this->build_payload( $type, $params );

		// Signer and body must share one encoder so the nested JSON that was
		// signed is byte identical to the nested JSON that is sent.
		$body = Signer::json_encode( $payload );

		$attempt    = 0;
		$last_error = null;

		while ( $attempt < self::MAX_ATTEMPTS ) {
			++$attempt;

			if ( $attempt > 1 ) {
				$this->backoff( $attempt );
			}

			try {
				return $this->dispatch( $type, $body, $payload );
			} catch ( ApiException $e ) {
				$last_error = $e;

				if ( ! $e->is_retryable() ) {
					throw $e;
				}
			}
		}

		throw $last_error;
	}

	/**
	 * Send one attempt and interpret the response.
	 *
	 * @param string $type    API method name.
	 * @param string $body    Encoded request body.
	 * @param array  $payload Decoded payload, for logging.
	 * @return array
	 *
	 * @throws ApiException On transport or API failure.
	 */
	protected function dispatch( $type, $body, array $payload ) {
		$started  = microtime( true );
		$response = $this->send( $body );
		$elapsed  = microtime( true ) - $started;

		if ( is_wp_error( $response ) ) {
			throw new ApiException(
				sprintf(
					/* translators: %s: transport error message. */
					esc_html__( 'Could not reach Temu: %s', 'woo-temu-sync' ),
					esc_html( $response->get_error_message() )
				),
				4000000,
				'',
				esc_html( $type )
			);
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$raw     = (string) wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );

		$this->log( $type, $payload, $status, $decoded, $elapsed );

		if ( ! is_array( $decoded ) ) {
			throw new ApiException(
				sprintf(
					/* translators: %d: HTTP status code. */
					esc_html__( 'Temu returned a response that could not be parsed (HTTP %d).', 'woo-temu-sync' ),
					absint( $status )
				),
				// 5xx is worth another attempt; a malformed 200 is not.
				$status >= 500 ? 4000000 : 3000000,
				'',
				esc_html( $type )
			);
		}

		$code       = isset( $decoded['errorCode'] ) ? (int) $decoded['errorCode'] : 0;
		$success    = ! empty( $decoded['success'] ) && ApiException::SUCCESS === $code;
		$request_id = isset( $decoded['requestId'] ) ? (string) $decoded['requestId'] : '';

		if ( ! $success ) {
			$message = isset( $decoded['errorMsg'] ) && '' !== $decoded['errorMsg']
				? (string) $decoded['errorMsg']
				: __( 'Unknown Temu API error.', 'woo-temu-sync' );

			// Escaped because WPCS treats an exception message as output; the text
			// comes from Temu and is rendered verbatim in admin notices.
			throw new ApiException( esc_html( $message ), absint( $code ), esc_html( $request_id ), esc_html( $type ) );
		}

		return isset( $decoded['result'] ) && is_array( $decoded['result'] ) ? $decoded['result'] : array();
	}

	/**
	 * Put the request on the wire.
	 *
	 * @param string $body Encoded request body.
	 * @return array|\WP_Error
	 */
	protected function send( $body ) {
		return wp_remote_post(
			$this->get_host(),
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => $body,
			)
		);
	}

	/**
	 * Sleep between retries.
	 *
	 * Exponential with a little jitter, so a shop with several sync jobs does
	 * not have all of them retry against the rate limiter in lockstep.
	 *
	 * @param int $attempt Attempt number, 1 based.
	 */
	protected function backoff( $attempt ) {
		$seconds = min( 8, pow( 2, $attempt - 2 ) );
		$jitter  = wp_rand( 0, 500 ) / 1000;

		usleep( (int) ( ( $seconds + $jitter ) * 1000000 ) );
	}

	/**
	 * Current unix timestamp in seconds.
	 *
	 * Deliberately server time rather than WordPress site time: Temu validates
	 * against real UTC with a 300 second window, and the site timezone setting
	 * must not shift it.
	 *
	 * @return int
	 */
	protected function now() {
		return time();
	}

	/**
	 * API host for the configured region.
	 *
	 * @return string
	 */
	public function get_host() {
		return Region::host( $this->region );
	}

	/**
	 * Emit a log record for one call.
	 *
	 * @param string     $type    API method name.
	 * @param array      $payload Request payload.
	 * @param int        $status  HTTP status.
	 * @param array|null $decoded Decoded response.
	 * @param float      $elapsed Duration in seconds.
	 */
	protected function log( $type, array $payload, $status, $decoded, $elapsed ) {
		// Never log the signature or the token; they are as good as a password.
		unset( $payload['sign'], $payload['access_token'] );

		/**
		 * Fires after every Temu API call, successful or not.
		 *
		 * The payload has already had its signature and access token stripped.
		 *
		 * @since 0.1.0
		 *
		 * @param string     $type    API method name.
		 * @param array      $payload Request payload, without secrets.
		 * @param int        $status  HTTP status code.
		 * @param array|null $decoded Decoded response body.
		 * @param float      $elapsed Duration in seconds.
		 */
		do_action( 'wtsync_api_call', $type, $payload, $status, $decoded, $elapsed );
	}
}
