<?php
namespace Automattic\VIP\Prometheus;

use Automattic\VIP\Utils\Context;
use Prometheus\CollectorRegistry;
use Prometheus\RegistryInterface;
use Prometheus\Storage\Adapter;
use Prometheus\Storage\APCng;
use Prometheus\Storage\InMemory;

class Plugin {
	protected static ?Plugin $instance     = null;
	protected ?RegistryInterface $registry = null;
	/** @var CollectorInterface[] */
	protected array $collectors = [];

	protected string $site_label = '';

	const MAX_NETWORK_SITES = 50;
	/**
	 * @return static
	 */
	public static function get_instance(): self {
		if ( ! self::$instance ) {
			self::$instance = new static();
		}

		return self::$instance;
	}

	/**
	 * @codeCoverageIgnore -- invoked before the tests start
	 */
	protected function __construct() {
		if ( Context::is_installing() ) {
			return;
		}

		add_action( 'vip_mu_plugins_loaded', [ $this, 'init_registry' ], 9 );
		add_action( 'vip_mu_plugins_loaded', [ $this, 'load_collectors' ] );
		add_action( 'mu_plugins_loaded', [ $this, 'load_collectors' ] );
		add_action( 'plugins_loaded', [ $this, 'load_collectors' ] );

		// Currently there's no way to persist the storage on non-web requests because APCu is not available.
		// So instead we collect metrics on shutdown after flushing the response to the client
		if ( Context::is_web_request() ) {
			add_action( 'shutdown', [ $this, 'collect_metrics_on_shutdown' ], PHP_INT_MAX );
		}

		// Cron callback to do the heavy lifting.
		add_action( 'vip_aggregated_cron_hourly', [ $this, 'process_metrics' ] );

		do_action( 'vip_prometheus_loaded' );
	}

	public function init_registry() {
		$this->registry = self::create_registry();
	}

	public function load_collectors(): void {
		$available_collectors = apply_filters( 'vip_prometheus_collectors', [], current_action() );
		if ( ! is_array( $available_collectors ) ) {
			$available_collectors = [];
		}

		$available_collectors = array_filter( $available_collectors, fn ( $collector ) => $collector instanceof CollectorInterface );

		$available_collectors = array_udiff(
			$available_collectors,
			$this->collectors,
			fn ( $a, $b ) => spl_object_id( $a ) - spl_object_id( $b )
		);

		array_walk( $available_collectors, fn ( CollectorInterface $collector ) => $collector->initialize( $this->registry ) );

		$this->collectors = array_merge( $this->collectors, $available_collectors );
	}

	private static function create_registry(): RegistryInterface {
		// @codeCoverageIgnoreStart -- APCu may or may not be available during tests
		/** @var Adapter $storage */
		if ( extension_loaded( 'apcu' ) && apcu_enabled() ) {
			$storage_backend = APCng::class;
		} else {
			$storage_backend = InMemory::class;
		}
		// @codeCoverageIgnoreEnd

		$storage_backend = apply_filters( 'vip_prometheus_storage_backend', $storage_backend );
		if ( is_string( $storage_backend ) && class_exists( $storage_backend ) ) {
			$storage = new $storage_backend();
		} elseif ( ! is_object( $storage_backend ) || ! ( $storage_backend instanceof Adapter ) ) {
			trigger_error( 'Invalid storage backend', E_USER_WARNING );
			$storage = new InMemory();
		} else {
			$storage = $storage_backend;
		}

		return new CollectorRegistry( $storage );
	}

	public function get_collectors(): array {
		return $this->collectors;
	}

	/**
	 * Cron callback to process the metrics for collection
	 *
	 * @return void
	 */
	public function process_metrics(): void {
		if ( Context::is_web_request() ) {
			trigger_error( __METHOD__ . ' should not be called on web requests', E_USER_WARNING );
			return;
		}

		foreach ( $this->collectors as $collector ) {
			if ( is_callable( [ $collector, 'process_metrics' ] ) ) {
				$collector->process_metrics();
			}
		}
	}

	/**
	 * We're going to send the response to the client, then collect metrics from each registered collector
	 */
	public function collect_metrics_on_shutdown(): void {
		$ts = time();
		if ( wp_cache_add( 'vip_prometheus_last_collection_run', $ts, 'vip_prometheus', 5 * MINUTE_IN_SECONDS ) ) {
			/**
			 * Flush the request to the client before proceeding.
			 *
			 * Can be unhooked via
			 * // remove_action( 'shutdown', [ \Automattic\VIP\Prometheus\Plugin::get_instance(), 'collect_metrics_on_shutdown' ], PHP_INT_MAX );
			 */
			if ( function_exists( 'fastcgi_finish_request' ) ) {
				fastcgi_finish_request();
			}

			foreach ( $this->collectors as $collector ) {
				$collector->collect_metrics();
			}

			if ( time() - $ts > 4 * MINUTE_IN_SECONDS ) {
				trigger_error( 'Prometheus: collecting metrics took longer than expected', E_USER_WARNING );
			}
		}
	}

	/**
	 * To avoid accidentally blowing up cardinality on large multisite we'll roll up anything over 50 sites into a single label
	 * @todo 50 is tentative, we may want to adjust this later.
	 * @return string
	 */
	public function get_site_label(): string {
		if ( $this->site_label ) {
			return $this->site_label;
		}

		$this->site_label = (string) get_current_blog_id();

		if ( ! is_multisite() ) {
			return $this->site_label;
		}

		$sites_count = wp_count_sites();
		if ( $sites_count['all'] > self::MAX_NETWORK_SITES ) {
			$this->site_label = 'network';
		}

		return $this->site_label;
	}
}
