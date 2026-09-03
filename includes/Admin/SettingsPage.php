<?php
/**
 * Connection settings screen.
 *
 * @package WooTemuSync
 */

namespace WooTemuSync\Admin;

use WooTemuSync\Api\ApiException;
use WooTemuSync\Api\Auth;
use WooTemuSync\Api\Credentials;
use WooTemuSync\Api\Region;
use WooTemuSync\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Renders and handles the Temu connection screen.
 */
class SettingsPage {

	const SLUG    = 'wtsync-temu';
	const NONCE   = 'wtsync_settings';
	const CAP     = 'manage_woocommerce';
	const NOTICES = 'wtsync_notices';

	/**
	 * Credential store.
	 *
	 * @var Credentials
	 */
	protected $credentials;

	/**
	 * Authorisation handler.
	 *
	 * @var Auth
	 */
	protected $auth;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	protected $logger;

	/**
	 * Constructor.
	 *
	 * @param Credentials $credentials Credential store.
	 * @param Auth        $auth        Authorisation handler.
	 * @param Logger      $logger      Logger.
	 */
	public function __construct( Credentials $credentials, Auth $auth, Logger $logger ) {
		$this->credentials = $credentials;
		$this->auth        = $auth;
		$this->logger      = $logger;
	}

	/**
	 * Register hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_wtsync_save_app', array( $this, 'handle_save_app' ) );
		add_action( 'admin_post_wtsync_connect', array( $this, 'handle_connect' ) );
		add_action( 'admin_post_wtsync_manual_token', array( $this, 'handle_manual_token' ) );
		add_action( 'admin_post_wtsync_disconnect', array( $this, 'handle_disconnect' ) );
		add_action( 'admin_post_wtsync_test', array( $this, 'handle_test' ) );
	}

	/**
	 * Add the submenu entry.
	 */
	public function add_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Temu Connector', 'woo-temu-sync' ),
			__( 'Temu', 'woo-temu-sync' ),
			self::CAP,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Screen URL.
	 *
	 * @return string
	 */
	protected function page_url() {
		return admin_url( 'admin.php?page=' . self::SLUG );
	}

	/**
	 * Verify the request is a permitted, signed admin action and hand back its data.
	 *
	 * Returning the payload rather than letting each handler reach into $_POST
	 * keeps the superglobal in one place, immediately after the capability and
	 * nonce checks that authorise reading it.
	 *
	 * @return array The unslashed request data. Values are still raw and must be sanitised.
	 */
	protected function guard() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to manage this connection.', 'woo-temu-sync' ) );
		}

		check_admin_referer( self::NONCE );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by check_admin_referer() immediately above.
		return (array) wp_unslash( $_POST );
	}

	/**
	 * Queue a notice for the next page load.
	 *
	 * @param string $type    Notice type: success or error.
	 * @param string $message Message.
	 */
	protected function notice( $type, $message ) {
		$notices   = get_transient( self::NOTICES );
		$notices   = is_array( $notices ) ? $notices : array();
		$notices[] = array(
			'type'    => $type,
			'message' => $message,
		);

		set_transient( self::NOTICES, $notices, 60 );
	}

	/**
	 * Redirect back to the settings screen.
	 */
	protected function bounce() {
		wp_safe_redirect( $this->page_url() );
		exit;
	}

	/**
	 * Turn an API failure into a notice with an actionable hint.
	 *
	 * @param ApiException $e Exception.
	 */
	protected function notice_from_exception( ApiException $e ) {
		$message = sprintf(
			/* translators: 1: Temu error message, 2: Temu error code. */
			__( 'Temu rejected the request: %1$s (code %2$d).', 'woo-temu-sync' ),
			$e->getMessage(),
			$e->get_error_code()
		);

		$hint = $e->get_hint();
		if ( '' !== $hint ) {
			$message .= ' ' . $hint;
		}

		if ( '' !== $e->get_request_id() ) {
			$message .= ' ' . sprintf(
				/* translators: %s: Temu request id. */
				__( 'Temu request id: %s', 'woo-temu-sync' ),
				$e->get_request_id()
			);
		}

		$this->notice( 'error', $message );
	}

	/**
	 * Save the app registration details.
	 */
	public function handle_save_app() {
		$posted = $this->guard();

		$region = isset( $posted['region'] ) ? sanitize_key( $posted['region'] ) : Region::EU;
		$type   = isset( $posted['seller_type'] ) ? sanitize_key( $posted['seller_type'] ) : Region::SELLER_LOCAL;

		$values = array(
			'region'      => Region::is_valid( $region ) ? $region : Region::EU,
			'seller_type' => in_array( $type, array( Region::SELLER_LOCAL, Region::SELLER_CROSSBORDER ), true ) ? $type : Region::SELLER_LOCAL,
			'app_key'     => isset( $posted['app_key'] ) ? sanitize_text_field( $posted['app_key'] ) : '',
		);

		// An empty secret field means "leave the stored one alone", so a merchant
		// can change the region without re-entering the secret.
		$secret = isset( $posted['app_secret'] ) ? trim( sanitize_text_field( $posted['app_secret'] ) ) : '';
		if ( '' !== $secret ) {
			$values['app_secret'] = $secret;
		}

		$this->credentials->update( $values );
		$this->notice( 'success', __( 'App details saved.', 'woo-temu-sync' ) );
		$this->bounce();
	}

	/**
	 * Send the merchant to Temu to authorise.
	 */
	public function handle_connect() {
		$this->guard();

		if ( ! $this->credentials->has_app() ) {
			$this->notice( 'error', __( 'Enter the app key and app secret before connecting.', 'woo-temu-sync' ) );
			$this->bounce();
		}

		wp_redirect( $this->auth->get_authorize_url() ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Deliberately leaving the site for Temu Seller Center.
		exit;
	}

	/**
	 * Store a token the merchant pasted in.
	 */
	public function handle_manual_token() {
		$posted = $this->guard();

		$token = isset( $posted['access_token'] ) ? sanitize_text_field( $posted['access_token'] ) : '';

		try {
			$this->auth->connect_with_token( $token );
			$this->notice( 'success', __( 'Store connected.', 'woo-temu-sync' ) );
		} catch ( ApiException $e ) {
			$this->notice_from_exception( $e );
		}

		$this->bounce();
	}

	/**
	 * Drop the seller half of the connection.
	 */
	public function handle_disconnect() {
		$this->guard();

		$this->credentials->disconnect();
		$this->notice( 'success', __( 'Store disconnected. The app key and secret were kept.', 'woo-temu-sync' ) );
		$this->bounce();
	}

	/**
	 * Re-read the token details from Temu as a live connection test.
	 */
	public function handle_test() {
		$this->guard();

		try {
			$info = $this->auth->refresh_token_info();

			$this->notice(
				'success',
				sprintf(
					/* translators: 1: mall id, 2: number of granted scopes. */
					__( 'Connection is healthy. Mall %1$s, %2$d permissions granted.', 'woo-temu-sync' ),
					isset( $info['mall_id'] ) ? $info['mall_id'] : $this->credentials->get( 'mall_id' ),
					count( $this->credentials->get( 'api_scopes', array() ) )
				)
			);
		} catch ( ApiException $e ) {
			$this->notice_from_exception( $e );
		}

		$this->bounce();
	}

	/**
	 * Print queued notices plus any carried on the query string.
	 */
	protected function render_notices() {
		$notices = get_transient( self::NOTICES );
		delete_transient( self::NOTICES );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only display of the redirect outcome.
		if ( isset( $_GET['wtsync-connected'] ) ) {
			$notices[] = array(
				'type'    => 'success',
				'message' => __( 'Store connected.', 'woo-temu-sync' ),
			);
		}

		if ( isset( $_GET['wtsync-error'] ) ) {
			$encoded = sanitize_text_field( wp_unslash( $_GET['wtsync-error'] ) );

			$notices[] = array(
				'type'    => 'error',
				'message' => sanitize_text_field( rawurldecode( $encoded ) ),
			);
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! is_array( $notices ) ) {
			return;
		}

		foreach ( $notices as $notice ) {
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				'success' === $notice['type'] ? 'success' : 'error',
				esc_html( $notice['message'] )
			);
		}
	}

	/**
	 * Open a form that posts to admin-post.php.
	 *
	 * @param string $action Action name.
	 */
	protected function form_open( $action ) {
		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( $action ) );
		wp_nonce_field( self::NONCE );
	}

	/**
	 * Render the screen.
	 */
	public function render() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$connected = $this->credentials->is_connected();
		$region    = $this->credentials->get( 'region' );
		$type      = $this->credentials->get( 'seller_type' );
		$app_key   = (string) $this->credentials->get( 'app_key' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Temu Connector', 'woo-temu-sync' ); ?></h1>
			<?php $this->render_notices(); ?>

			<?php $this->render_status( $connected ); ?>

			<h2><?php esc_html_e( '1. Register your Temu app', 'woo-temu-sync' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: %s: partner console link. */
					esc_html__( 'Create an app on the Temu partner console for your region, then paste its credentials here. Partner console: %s', 'woo-temu-sync' ),
					'<a href="' . esc_url( Region::partner_url( $region ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( Region::partner_url( $region ) ) . '</a>'
				);
				?>
			</p>

			<?php $this->form_open( 'wtsync_save_app' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wtsync-region"><?php esc_html_e( 'Temu region', 'woo-temu-sync' ); ?></label></th>
					<td>
						<select name="region" id="wtsync-region">
							<?php foreach ( Region::all() as $key => $definition ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $region, $key ); ?>>
									<?php echo esc_html( $definition['label'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php
							$all = Region::all();
							echo esc_html( isset( $all[ $region ] ) ? $all[ $region ]['markets'] : '' );
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Seller type', 'woo-temu-sync' ); ?></th>
					<td>
						<label>
							<input type="radio" name="seller_type" value="<?php echo esc_attr( Region::SELLER_LOCAL ); ?>" <?php checked( $type, Region::SELLER_LOCAL ); ?> />
							<?php esc_html_e( 'Local seller - you hold stock in-market and set your own prices', 'woo-temu-sync' ); ?>
						</label><br />
						<label>
							<input type="radio" name="seller_type" value="<?php echo esc_attr( Region::SELLER_CROSSBORDER ); ?>" <?php checked( $type, Region::SELLER_CROSSBORDER ); ?> />
							<?php esc_html_e( 'Cross-border seller - you ship into Temu warehouses', 'woo-temu-sync' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'The two types authorise through different Seller Center back ends. Local sellers get the most from this plugin, because they control price, stock and shipping.', 'woo-temu-sync' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wtsync-app-key"><?php esc_html_e( 'App key', 'woo-temu-sync' ); ?></label></th>
					<td>
						<input type="text" class="regular-text" name="app_key" id="wtsync-app-key"
							value="<?php echo esc_attr( $this->credentials->get( 'app_key' ) ); ?>" autocomplete="off" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wtsync-app-secret"><?php esc_html_e( 'App secret', 'woo-temu-sync' ); ?></label></th>
					<td>
						<input type="password" class="regular-text" name="app_secret" id="wtsync-app-secret"
							value="" autocomplete="new-password"
							placeholder="<?php echo $this->credentials->get( 'app_secret' ) ? esc_attr__( 'Saved - leave blank to keep', 'woo-temu-sync' ) : ''; ?>" />
						<p class="description"><?php esc_html_e( 'Stored encrypted using this site\'s WordPress salts.', 'woo-temu-sync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Redirect URL', 'woo-temu-sync' ); ?></th>
					<td>
						<code><?php echo esc_html( $this->auth->get_redirect_uri() ); ?></code>
						<p class="description">
							<?php
							printf(
								/* translators: %s: app settings link. */
								esc_html__( 'Register this exact URL as the app\'s redirect URL, otherwise the one-click connection below cannot return here. Set it at: %s', 'woo-temu-sync' ),
								'<code>' . esc_html( Region::app_settings_url( $region, '' !== $app_key ? $app_key : 'YOUR_APP_KEY' ) ) . '</code>'
							);
							?>
						</p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save app details', 'woo-temu-sync' ) ); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( '2. Connect your store', 'woo-temu-sync' ); ?></h2>

			<?php if ( ! $this->credentials->has_app() ) : ?>
				<p><em><?php esc_html_e( 'Save your app key and secret first.', 'woo-temu-sync' ); ?></em></p>
			<?php else : ?>
				<h3><?php esc_html_e( 'Option A - one click', 'woo-temu-sync' ); ?></h3>
				<p><?php esc_html_e( 'Sends you to Temu Seller Center to approve the permissions, then brings you back. Requires the redirect URL above to be registered on your app.', 'woo-temu-sync' ); ?></p>
				<?php $this->form_open( 'wtsync_connect' ); ?>
					<?php submit_button( __( 'Connect with Temu', 'woo-temu-sync' ), 'primary', 'submit', false ); ?>
				</form>

				<h3 style="margin-top:2em;"><?php esc_html_e( 'Option B - paste an access token', 'woo-temu-sync' ); ?></h3>
				<p>
					<?php
					printf(
						/* translators: %s: Seller Center authorisation link. */
						esc_html__( 'Authorise the app in Seller Center, copy the access token it shows you, and paste it below. Use this if your app is not published on the Temu App Store yet. Authorisation page: %s', 'woo-temu-sync' ),
						'<a href="' . esc_url( Region::authorization_manage_url( $region, $type ) ) . '" target="_blank" rel="noopener noreferrer">'
							. esc_html( Region::authorization_manage_url( $region, $type ) ) . '</a>'
					);
					?>
				</p>
				<?php $this->form_open( 'wtsync_manual_token' ); ?>
					<input type="text" class="large-text code" name="access_token" autocomplete="off"
						placeholder="<?php esc_attr_e( 'Paste the access token from Seller Center', 'woo-temu-sync' ); ?>" />
					<?php submit_button( __( 'Save token and verify', 'woo-temu-sync' ), 'secondary' ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the connection status panel.
	 *
	 * @param bool $connected Whether a store is connected.
	 */
	protected function render_status( $connected ) {
		if ( ! $connected ) {
			return;
		}

		$expires_in = $this->credentials->expires_in();
		$scopes     = $this->credentials->get( 'api_scopes', array() );
		?>
		<div class="card" style="max-width:none;padding:1em 1.5em;">
			<h2 style="margin-top:0;"><?php esc_html_e( 'Connection', 'woo-temu-sync' ); ?></h2>
			<table class="widefat striped" style="max-width:900px;">
				<tbody>
					<tr>
						<th style="width:220px;"><?php esc_html_e( 'Status', 'woo-temu-sync' ); ?></th>
						<td>
							<?php if ( $this->credentials->is_expired() ) : ?>
								<strong style="color:#b32d2e;"><?php esc_html_e( 'Token expired - reconnect required', 'woo-temu-sync' ); ?></strong>
							<?php else : ?>
								<strong style="color:#008a20;"><?php esc_html_e( 'Connected', 'woo-temu-sync' ); ?></strong>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Mall ID', 'woo-temu-sync' ); ?></th>
						<td><code><?php echo esc_html( $this->credentials->get( 'mall_id' ) ); ?></code></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'API host', 'woo-temu-sync' ); ?></th>
						<td><code><?php echo esc_html( Region::host( $this->credentials->get( 'region' ) ) ); ?></code></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Token expires', 'woo-temu-sync' ); ?></th>
						<td>
							<?php
							if ( null === $expires_in ) {
								esc_html_e( 'Unknown', 'woo-temu-sync' );
							} elseif ( $expires_in <= 0 ) {
								esc_html_e( 'Expired', 'woo-temu-sync' );
							} else {
								echo esc_html(
									sprintf(
										/* translators: 1: date, 2: human readable interval. */
										__( '%1$s (in %2$s)', 'woo-temu-sync' ),
										date_i18n(
											get_option( 'date_format' ),
											(int) $this->credentials->get( 'expires_at' )
										),
										human_time_diff( time(), (int) $this->credentials->get( 'expires_at' ) )
									)
								);
							}
							?>
							<p class="description"><?php esc_html_e( 'Temu tokens last about a year and cannot be refreshed automatically. The seller has to authorise again before this date.', 'woo-temu-sync' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Granted permissions', 'woo-temu-sync' ); ?></th>
						<td>
							<?php if ( empty( $scopes ) ) : ?>
								<em><?php esc_html_e( 'None reported.', 'woo-temu-sync' ); ?></em>
							<?php else : ?>
								<details>
									<summary>
										<?php
										echo esc_html(
											sprintf(
												/* translators: %d: number of API methods. */
												_n( '%d API method', '%d API methods', count( $scopes ), 'woo-temu-sync' ),
												count( $scopes )
											)
										);
										?>
									</summary>
									<p style="margin-top:.75em;">
										<?php foreach ( $scopes as $scope ) : ?>
											<code style="display:inline-block;margin:0 .4em .4em 0;"><?php echo esc_html( $scope ); ?></code>
										<?php endforeach; ?>
									</p>
								</details>
							<?php endif; ?>
						</td>
					</tr>
				</tbody>
			</table>

			<p style="margin-bottom:0;">
				<?php $this->form_open( 'wtsync_test' ); ?>
					<?php submit_button( __( 'Test connection', 'woo-temu-sync' ), 'secondary', 'submit', false ); ?>
				</form>
				<?php $this->form_open( 'wtsync_disconnect' ); ?>
					<?php submit_button( __( 'Disconnect', 'woo-temu-sync' ), 'delete', 'submit', false ); ?>
				</form>
			</p>
		</div>
		<?php
	}
}
