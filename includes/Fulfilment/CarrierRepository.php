<?php
/**
 * Carriers Temu will accept for a region.
 *
 * @package WooTemuSync
 */

namespace WooTemuSync\Fulfilment;

use WooTemuSync\Api\ApiException;
use WooTemuSync\Api\Credentials;
use WooTemuSync\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Looks up the logistics providers Temu recognises in a market.
 *
 * A shipment is confirmed with a numeric carrierId, and Temu validates the
 * tracking number against that carrier's own format, so guessing the carrier
 * produces a rejected shipment rather than a mislabelled one. The list is
 * per-region and changes rarely, so it is cached.
 */
class CarrierRepository {

	/**
	 * The carrier listing method.
	 */
	const METHOD_COMPANIES = 'bg.logistics.companies.get';

	/**
	 * Transient prefix for a region's carrier list.
	 */
	const CACHE_PREFIX = 'wtsync_carriers_';

	/**
	 * How long a carrier list is trusted.
	 */
	const CACHE_TTL = DAY_IN_SECONDS;

	/**
	 * Credential store.
	 *
	 * @var Credentials
	 */
	private $credentials;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Credentials $credentials Credential store.
	 * @param Logger      $logger      Logger.
	 */
	public function __construct( Credentials $credentials, Logger $logger ) {
		$this->credentials = $credentials;
		$this->logger      = $logger;
	}

	/**
	 * Carriers Temu accepts for a region.
	 *
	 * @param int $region_id Temu regionId.
	 * @return array<int,string> Carrier id to display name.
	 */
	public function for_region( $region_id ) {
		$region_id = (int) $region_id;
		$key       = self::CACHE_PREFIX . $region_id;
		$cached    = get_transient( $key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		try {
			$result = $this->credentials->client()->request(
				self::METHOD_COMPANIES,
				array( 'regionId' => $region_id )
			);
		} catch ( ApiException $e ) {
			$this->logger->error(
				sprintf( 'Could not read the carrier list for region %d: %s', $region_id, $e->getMessage() ),
				array( 'code' => $e->get_error_code() )
			);

			return array();
		}

		$carriers = $this->parse( $result );

		// Cache an empty result briefly too, so a broken permission does not turn
		// every order screen into an API call.
		set_transient( $key, $carriers, empty( $carriers ) ? MINUTE_IN_SECONDS * 15 : self::CACHE_TTL );

		return $carriers;
	}

	/**
	 * Turn the response into an id to name map.
	 *
	 * @param array $result Decoded API result.
	 * @return array<int,string>
	 */
	protected function parse( array $result ) {
		// The carrier list is the result itself rather than a named member.
		$rows = isset( $result['result'] ) && is_array( $result['result'] ) ? $result['result'] : $result;

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$carriers = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['logisticsServiceProviderId'] ) ) {
				continue;
			}

			$name = '';

			foreach ( array( 'logisticsServiceProviderName', 'logisticsBrandName' ) as $field ) {
				if ( ! empty( $row[ $field ] ) ) {
					$name = (string) $row[ $field ];
					break;
				}
			}

			$id = (int) $row['logisticsServiceProviderId'];

			$carriers[ $id ] = '' !== $name ? $name : (string) $id;
		}

		asort( $carriers );

		return $carriers;
	}

	/**
	 * Whether a carrier id is one Temu accepts for a region.
	 *
	 * @param int $carrier_id Carrier id.
	 * @param int $region_id  Temu regionId.
	 * @return bool
	 */
	public function is_valid( $carrier_id, $region_id ) {
		$carriers = $this->for_region( $region_id );

		// An empty list means the lookup failed, not that no carrier is valid.
		// Blocking a shipment on that would be worse than letting Temu decide.
		if ( empty( $carriers ) ) {
			return true;
		}

		return isset( $carriers[ (int) $carrier_id ] );
	}

	/**
	 * Forget the cached list for a region.
	 *
	 * @param int $region_id Temu regionId.
	 * @return void
	 */
	public function forget( $region_id ) {
		delete_transient( self::CACHE_PREFIX . (int) $region_id );
	}
}
