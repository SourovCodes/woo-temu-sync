<?php
/**
 * Seller authorisation handshake.
 *
 * @package WooTemuSync
 */

namespace WooTemuSync\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Connects a Temu store to this site.
 *
 * Temu supports two authorisation styles and this plugin implements both,
 * because they solve different problems:
 *
 * - Callback: the seller is sent to Seller Center and returned here with a
 *   code, which is exchanged for a token. Better experience, but it requires
 *   the app to be published on Temu's App Store with a registered redirect URL.
 * - Manual: the seller authorises in Seller Center, copies the access token and
 *   pastes it in. Ugly, but it works on day one with an unpublished app, which
 *   matters for a plugin people install before any partner onboarding is done.
 */
class Auth {

	/**
	 * Query var used for the callback endpoint.
	 */
	const CALLBACK_VAR = 'wtsync-temu-callback';

	/**
	 * Transient holding the pending CSRF state value.
	 */
	const STATE_TRANSIENT = 'wtsync_auth_state';

	/**
	 * Credential store.
	 *
	 * @var Credentials
	 */
	protected $credentials;

	/**
	 * Constructor.
	 *
	 * @param Credentials $credentials Credential store.
	 */
	public function __construct( Credentials $credentials ) {
		$this->credentials = $credentials;
	}

	/**
	 * URL Temu should return the seller to after authorisation.
	 *
	 * This exact string has to be registered as the redirect URL on the app in
	 * the Temu partner console, so it is shown verbatim in the settings screen.
	 *
	 * @return string
	 */
	public function get_redirect_uri() {
		return add_query_arg( self::CALLBACK_VAR, '1', home_url( '/' ) );
	}

	/**
	 * Start a callback authorisation and return the URL to send the seller to.
	 *
	 * @return string
	 */
	public function get_authorize_url() {
		$state = wp_generate_password( 32, false );

		set_transient( self::STATE_TRANSIENT, $state, 15 * MINUTE_IN_SECONDS );

		return Region::authorize_url(
			$this->credentials->get( 'region' ),
			$this->credentials->get( 'app_key' ),
			$this->get_redirect_uri(),
			$state,
			$this->credentials->get( 'seller_type' )
		);
	}

	/**
	 * Handle Temu's redirect back to this site.
	 *
	 * @param array $query Query parameters from the request.
	 * @return true
	 *
	 * @throws ApiException When the handshake fails.
	 */
	public function handle_callback( array $query ) {
		$expected = get_transient( self::STATE_TRANSIENT );
		$given    = isset( $query['state'] ) ? (string) $query['state'] : '';

		if ( ! $expected || ! hash_equals( (string) $expected, $given ) ) {
			throw new ApiException(
				esc_html__( 'The authorisation response could not be verified. Start the connection again from the settings screen.', 'woo-temu-sync' ),
				3000000
			);
		}

		delete_transient( self::STATE_TRANSIENT );

		$code = isset( $query['code'] ) ? sanitize_text_field( $query['code'] ) : '';

		if ( '' === $code ) {
			throw new ApiException(
				esc_html__( 'Temu did not return an authorisation code.', 'woo-temu-sync' ),
				3000000
			);
		}

		// Temu tells us which gateway this token belongs to. Trust it over the
		// region the merchant guessed at in the settings form.
		if ( isset( $query['callback_host'] ) ) {
			$region = Region::from_callback_host( sanitize_text_field( $query['callback_host'] ) );

			if ( $region ) {
				$this->credentials->update( array( 'region' => $region ) );
			}
		}

		$this->exchange_code( $code );

		return true;
	}

	/**
	 * Exchange an authorisation code for an access token.
	 *
	 * On the first call the platform expects the code to be sent as the access
	 * token as well, which is why the client is built with the code in that
	 * slot rather than with an empty token.
	 *
	 * @param string $code Authorisation code.
	 * @return array Stored connection details.
	 *
	 * @throws ApiException When the exchange fails.
	 */
	public function exchange_code( $code ) {
		$client = new Client(
			$this->credentials->get( 'app_key' ),
			$this->credentials->get( 'app_secret' ),
			$code,
			$this->credentials->get( 'region' )
		);

		$result = $client->request( 'bg.open.accesstoken.create', array( 'code' => $code ) );

		return $this->store_token_result( $result );
	}

	/**
	 * Verify and store a token the seller pasted in by hand.
	 *
	 * The token is checked against the platform before it is saved, so a typo
	 * fails here with a clear message rather than during the first sync.
	 *
	 * @param string $token Access token.
	 * @return array Stored connection details.
	 *
	 * @throws ApiException When the token is not usable.
	 */
	public function connect_with_token( $token ) {
		$token = trim( (string) $token );

		if ( '' === $token ) {
			throw new ApiException(
				esc_html__( 'Enter the access token from Temu Seller Center.', 'woo-temu-sync' ),
				3000030
			);
		}

		$client = new Client(
			$this->credentials->get( 'app_key' ),
			$this->credentials->get( 'app_secret' ),
			$token,
			$this->credentials->get( 'region' )
		);

		$result                = $client->request( 'bg.open.accesstoken.info.get' );
		$result['accessToken'] = $token;

		return $this->store_token_result( $result );
	}

	/**
	 * Re-read the current token's details from Temu.
	 *
	 * @return array
	 *
	 * @throws ApiException When the call fails.
	 */
	public function refresh_token_info() {
		$result                = $this->credentials->client()->request( 'bg.open.accesstoken.info.get' );
		$result['accessToken'] = $this->credentials->get( 'access_token' );

		return $this->store_token_result( $result );
	}

	/**
	 * Persist the details returned by either token endpoint.
	 *
	 * @param array $result Decoded API result.
	 * @return array
	 */
	protected function store_token_result( array $result ) {
		$values = array(
			'connected_at' => time(),
		);

		if ( ! empty( $result['accessToken'] ) ) {
			$values['access_token'] = (string) $result['accessToken'];
		}

		if ( ! empty( $result['mallId'] ) ) {
			$values['mall_id'] = (string) $result['mallId'];
		}

		if ( ! empty( $result['expiredTime'] ) ) {
			$values['expires_at'] = (int) $result['expiredTime'];
		}

		if ( ! empty( $result['apiScopeList'] ) && is_array( $result['apiScopeList'] ) ) {
			$values['api_scopes'] = array_values( array_map( 'strval', $result['apiScopeList'] ) );
		}

		if ( ! empty( $result['appSubscribeEventCodeList'] ) && is_array( $result['appSubscribeEventCodeList'] ) ) {
			$values['events'] = array_values( array_map( 'strval', $result['appSubscribeEventCodeList'] ) );
		}

		$this->credentials->update( $values );

		return $values;
	}
}
