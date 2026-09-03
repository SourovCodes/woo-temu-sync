<?php
/**
 * Logging.
 *
 * @package WooTemuSync
 */

namespace WooTemuSync;

defined( 'ABSPATH' ) || exit;

/**
 * Writes to the WooCommerce log so records show up under
 * WooCommerce > Status > Logs, where support already looks.
 */
class Logger {

	const SOURCE = 'temu-connector';

	/**
	 * WooCommerce logger.
	 *
	 * @var \WC_Logger_Interface|null
	 */
	protected $wc_logger = null;

	/**
	 * Hook into the API call action.
	 */
	public function register() {
		add_action( 'wtsync_api_call', array( $this, 'log_api_call' ), 10, 5 );
	}

	/**
	 * Record one API call.
	 *
	 * @param string     $type    API method name.
	 * @param array      $payload Request payload, already stripped of secrets.
	 * @param int        $status  HTTP status.
	 * @param array|null $decoded Decoded response.
	 * @param float      $elapsed Duration in seconds.
	 */
	public function log_api_call( $type, $payload, $status, $decoded, $elapsed ) {
		$code = isset( $decoded['errorCode'] ) ? (int) $decoded['errorCode'] : 0;
		$ok   = 1000000 === $code;

		$message = sprintf(
			'%s %s in %sms (HTTP %d, code %d)%s',
			$ok ? 'OK' : 'FAIL',
			$type,
			number_format( $elapsed * 1000, 0 ),
			$status,
			$code,
			isset( $decoded['requestId'] ) ? ' requestId=' . $decoded['requestId'] : ''
		);

		if ( $ok ) {
			$this->debug( $message );
			return;
		}

		$this->error(
			$message . ' ' . ( isset( $decoded['errorMsg'] ) ? $decoded['errorMsg'] : '' ),
			array( 'request' => $payload )
		);
	}

	/**
	 * Debug level record.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public function debug( $message, array $context = array() ) {
		$this->write( 'debug', $message, $context );
	}

	/**
	 * Info level record.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public function info( $message, array $context = array() ) {
		$this->write( 'info', $message, $context );
	}

	/**
	 * Error level record.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public function error( $message, array $context = array() ) {
		$this->write( 'error', $message, $context );
	}

	/**
	 * Write to the WooCommerce log.
	 *
	 * @param string $level   Log level.
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	protected function write( $level, $message, array $context = array() ) {
		if ( null === $this->wc_logger ) {
			$this->wc_logger = wc_get_logger();
		}

		if ( ! empty( $context ) ) {
			$message .= ' ' . wp_json_encode( $context );
		}

		$this->wc_logger->log( $level, $message, array( 'source' => self::SOURCE ) );
	}
}
