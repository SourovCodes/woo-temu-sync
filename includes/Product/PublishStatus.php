<?php
/**
 * Publication status of a product on Temu.
 *
 * @package WooTemuSync
 */

namespace WooTemuSync\Product;

defined( 'ABSPATH' ) || exit;

/**
 * A status reading for one product.
 *
 * Temu returns `status` and `subStatus` as bare integers and does not publish
 * the enumeration anywhere in the documentation. Rather than guess at the
 * mapping and mislabel listings in the merchant's admin, this class keeps the
 * raw codes and exposes them, with a filter so the mapping can be filled in
 * once it is confirmed against a live account.
 *
 * The only lifecycle names Temu documents are the two from the V3 guide:
 * "Draft" (incomplete, cannot be submitted) and "Incomplete" (complete and
 * automatically submitted, awaiting review).
 */
class PublishStatus {

	/**
	 * Meta keys used to persist a reading.
	 */
	const META_STATUS     = '_wtsync_temu_status';
	const META_SUB_STATUS = '_wtsync_temu_sub_status';
	const META_CHECKED_AT = '_wtsync_status_checked_at';
	const META_ATTEMPTS   = '_wtsync_status_attempts';

	/**
	 * Temu product id.
	 *
	 * @var int
	 */
	protected $goods_id;

	/**
	 * Raw status code.
	 *
	 * @var int|null
	 */
	protected $status;

	/**
	 * Raw sub-status code.
	 *
	 * @var int|null
	 */
	protected $sub_status;

	/**
	 * Constructor.
	 *
	 * @param int      $goods_id   Temu product id.
	 * @param int|null $status     Raw status code.
	 * @param int|null $sub_status Raw sub-status code.
	 */
	public function __construct( $goods_id, $status = null, $sub_status = null ) {
		$this->goods_id   = (int) $goods_id;
		$this->status     = null === $status ? null : (int) $status;
		$this->sub_status = null === $sub_status ? null : (int) $sub_status;
	}

	/**
	 * Build a reading from one entry of goodsPublishStatusList.
	 *
	 * @param array $entry Response entry.
	 * @return PublishStatus|null Null when the entry carries no product id.
	 */
	public static function from_response( array $entry ) {
		if ( empty( $entry['goodsId'] ) ) {
			return null;
		}

		return new self(
			$entry['goodsId'],
			array_key_exists( 'status', $entry ) ? $entry['status'] : null,
			array_key_exists( 'subStatus', $entry ) ? $entry['subStatus'] : null
		);
	}

	/**
	 * Temu product id.
	 *
	 * @return int
	 */
	public function get_goods_id() {
		return $this->goods_id;
	}

	/**
	 * Raw status code.
	 *
	 * @return int|null
	 */
	public function get_status() {
		return $this->status;
	}

	/**
	 * Raw sub-status code.
	 *
	 * @return int|null
	 */
	public function get_sub_status() {
		return $this->sub_status;
	}

	/**
	 * Whether Temu reported anything at all.
	 *
	 * @return bool
	 */
	public function is_known() {
		return null !== $this->status;
	}

	/**
	 * Human readable label for the reading.
	 *
	 * Deliberately honest: without a documented enumeration the raw code is
	 * shown, which a merchant can quote to Temu support, rather than a guessed
	 * word that might be wrong.
	 *
	 * @return string
	 */
	public function get_label() {
		if ( ! $this->is_known() ) {
			return __( 'Not reported yet', 'woo-temu-sync' );
		}

		/**
		 * Filter the map of Temu status codes to labels.
		 *
		 * Temu does not publish this enumeration. Once the codes are confirmed
		 * against a live seller account, add them here, for example:
		 *
		 *     add_filter( 'wtsync_publish_status_labels', function ( $labels ) {
		 *         $labels[1] = __( 'Draft', 'my-plugin' );
		 *         return $labels;
		 *     } );
		 *
		 * @since 0.1.0
		 *
		 * @param array $labels Map of status code to label.
		 */
		$labels = apply_filters( 'wtsync_publish_status_labels', array() );

		if ( isset( $labels[ $this->status ] ) ) {
			return $labels[ $this->status ];
		}

		return sprintf(
			/* translators: 1: raw status code, 2: raw sub-status code. */
			__( 'Temu status %1$s / %2$s', 'woo-temu-sync' ),
			$this->status,
			null === $this->sub_status ? '-' : $this->sub_status
		);
	}

	/**
	 * Whether this reading differs from another.
	 *
	 * @param PublishStatus|null $other Previous reading.
	 * @return bool
	 */
	public function differs_from( $other ) {
		if ( ! $other instanceof self ) {
			return true;
		}

		return $this->status !== $other->get_status() || $this->sub_status !== $other->get_sub_status();
	}
}
