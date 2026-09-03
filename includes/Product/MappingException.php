<?php
/**
 * Raised when a WooCommerce product cannot be expressed as a Temu listing.
 *
 * @package WooTemuSync
 */

namespace WooTemuSync\Product;

defined( 'ABSPATH' ) || exit;

/**
 * A local validation failure, distinct from a rejection by Temu.
 */
class MappingException extends \Exception {
}
