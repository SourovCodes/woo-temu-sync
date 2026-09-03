<?php
/**
 * Raised when a shipment cannot be confirmed to Temu.
 *
 * @package WooTemuSync
 */

namespace WooTemuSync\Fulfilment;

defined( 'ABSPATH' ) || exit;

/**
 * A fulfilment failure, already translated into something a seller can read.
 */
class FulfilmentException extends \Exception {
}
