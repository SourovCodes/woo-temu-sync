<?php
/**
 * Plugin container.
 *
 * @package WooTemuSync
 */

namespace WooTemuSync;

use WooTemuSync\Admin\ProductColumn;
use WooTemuSync\Admin\SettingsPage;
use WooTemuSync\Api\ApiException;
use WooTemuSync\Api\Auth;
use WooTemuSync\Api\Credentials;
use WooTemuSync\Order\AddressMapper;
use WooTemuSync\Order\OrderImporter;
use WooTemuSync\Order\OrderPoller;
use WooTemuSync\Product\Mapper;
use WooTemuSync\Product\Publisher;
use WooTemuSync\Product\Repository;
use WooTemuSync\Product\Scheduler;
use WooTemuSync\Product\StatusPoller;
use WooTemuSync\Sync\PriceSync;
use WooTemuSync\Sync\SkuMap;
use WooTemuSync\Sync\StockSync;
use WooTemuSync\Sync\SyncQueue;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the pieces together and owns the shared service instances.
 */
final class Plugin {

	/**
	 * The plugin's text domain.
	 *
	 * @var string
	 */
	const TEXT_DOMAIN = 'woo-temu-sync';

	/**
	 * Option holding the version that last finished its upgrade routine.
	 *
	 * Autoloaded, because it is read on every request.
	 */
	const VERSION_KEY = 'woo_temu_sync_version';

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

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
	 * Product publisher.
	 *
	 * @var Publisher
	 */
	protected $publisher;

	/**
	 * Status poller.
	 *
	 * @var StatusPoller
	 */
	protected $poller;

	/**
	 * Background scheduler.
	 *
	 * @var Scheduler
	 */
	protected $scheduler;

	/**
	 * Stock and price sync queue.
	 *
	 * @var SyncQueue
	 */
	protected $sync_queue;

	/**
	 * Order poller.
	 *
	 * @var OrderPoller
	 */
	protected $order_poller;

	/**
	 * Whether init() has already registered the plugin's hooks.
	 *
	 * @var bool
	 */
	private $initialised = false;

	/**
	 * Shared instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->credentials = new Credentials();
		$this->auth        = new Auth( $this->credentials );
		$this->logger      = new Logger();
		$this->publisher   = new Publisher( $this->credentials, new Mapper(), $this->logger );
		$this->poller      = new StatusPoller( $this->credentials, $this->logger );
		$this->scheduler   = new Scheduler( $this->poller, new Repository(), $this->logger );

		$mapper  = new Mapper();
		$sku_map = new SkuMap( $this->credentials, $mapper, $this->logger );

		$this->sync_queue = new SyncQueue(
			new StockSync( $this->credentials, $sku_map, $this->logger ),
			new PriceSync( $this->credentials, $sku_map, $this->logger ),
			new Repository(),
			$this->logger
		);

		$this->order_poller = new OrderPoller(
			$this->credentials,
			new OrderImporter( $this->credentials, new AddressMapper(), $this->logger ),
			$this->logger
		);
	}

	/**
	 * Register hooks.
	 */
	public function init() {
		if ( $this->initialised ) {
			return;
		}

		$this->initialised = true;

		$this->logger->register();
		$this->scheduler->register();
		$this->sync_queue->register();
		$this->order_poller->register();

		add_action( 'init', array( $this, 'maybe_handle_callback' ) );

		if ( is_admin() ) {
			( new SettingsPage( $this->credentials, $this->auth, $this->logger ) )->register();
			( new ProductColumn() )->register();
		}
	}

	/**
	 * Credential store.
	 *
	 * @return Credentials
	 */
	public function credentials() {
		return $this->credentials;
	}

	/**
	 * Authorisation handler.
	 *
	 * @return Auth
	 */
	public function auth() {
		return $this->auth;
	}

	/**
	 * Logger.
	 *
	 * @return Logger
	 */
	public function logger() {
		return $this->logger;
	}

	/**
	 * Product publisher.
	 *
	 * @return Publisher
	 */
	public function publisher() {
		return $this->publisher;
	}

	/**
	 * Status poller.
	 *
	 * @return StatusPoller
	 */
	public function poller() {
		return $this->poller;
	}

	/**
	 * Stock and price sync queue.
	 *
	 * @return SyncQueue
	 */
	public function sync_queue() {
		return $this->sync_queue;
	}

	/**
	 * Order poller.
	 *
	 * @return OrderPoller
	 */
	public function order_poller() {
		return $this->order_poller;
	}

	/**
	 * Catch Temu's authorisation redirect on the front end.
	 *
	 * The redirect URL has to be a plain front-end URL because Temu sends the
	 * seller's browser there directly, and an admin-only endpoint would break
	 * for anyone not already logged in to WordPress in that browser.
	 */
	public function maybe_handle_callback() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The state parameter is the CSRF token here; it is verified in Auth::handle_callback().
		if ( ! isset( $_GET[ Auth::CALLBACK_VAR ] ) ) {
			return;
		}

		$query = array_map( 'sanitize_text_field', wp_unslash( $_GET ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$redirect = admin_url( 'admin.php?page=' . SettingsPage::SLUG );

		try {
			$this->auth->handle_callback( $query );

			$redirect = add_query_arg( 'wtsync-connected', '1', $redirect );
		} catch ( ApiException $e ) {
			$this->logger->error(
				'Authorisation callback failed: ' . $e->getMessage(),
				array(
					'code'       => $e->get_error_code(),
					'request_id' => $e->get_request_id(),
				)
			);

			$redirect = add_query_arg(
				array(
					'wtsync-error' => rawurlencode( $e->getMessage() ),
				),
				$redirect
			);
		}

		wp_safe_redirect( $redirect );
		exit;
	}
}
