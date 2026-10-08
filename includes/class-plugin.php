<?php
/**
 * Plugin wiring: hooks, activation and deactivation.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the plugin's services into WordPress. Nothing here makes a network call.
 */
final class Plugin {

	/** Sites handled per query during network deactivation. */
	const SITES_PER_BATCH = 100;

	/**
	 * Every WP-Cron hook the plugin schedules. Deactivation and uninstall clear them all.
	 *
	 * @var string[]
	 */
	const CRON_HOOKS = array(
		Cache::REFRESH_HOOK,
		Health::HOOK,
		Sync::POLL_HOOK,
		Ping_Endpoint::PULL_HOOK,
	);

	/**
	 * Shared connection store.
	 *
	 * @var Connection|null
	 */
	private static $connection = null;

	/**
	 * Shared API client.
	 *
	 * @var Api_Client|null
	 */
	private static $api_client = null;

	/**
	 * Shared cache.
	 *
	 * @var Cache|null
	 */
	private static $cache = null;

	/**
	 * Shared connect service.
	 *
	 * @var Connect|null
	 */
	private static $connect = null;

	/**
	 * Shared health reporter.
	 *
	 * @var Health|null
	 */
	private static $health = null;

	/**
	 * Registers hooks. Runs on `plugins_loaded`. Nothing here makes a request.
	 */
	public static function boot(): void {
		add_action( Cache::REFRESH_HOOK, array( self::cache(), 'refresh' ) );
		add_filter( 'site_status_tests', array( Health::class, 'site_tests' ) );
		add_action( Health::HOOK, array( self::health(), 'run' ) );
		// phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- Sync::schedules sets a 900-second interval.
		add_filter( 'cron_schedules', array( Sync::class, 'schedules' ) );
		add_action( Sync::POLL_HOOK, array( Sync::class, 'run' ) );
		add_action( Ping_Endpoint::PULL_HOOK, array( Sync::class, 'run' ) );
		add_action( 'init', array( Sync::class, 'schedule' ) );
		add_action( 'transition_post_status', array( Sync_Identity::class, 'transition' ), 10, 3 );
		add_action( 'before_delete_post', array( Sync_Identity::class, 'detach' ) );
		add_action( 'deleted_post', array( Sync_Identity::class, 'report_detachment' ), 10, 2 );

		Migration_Legacy::register();

		add_action( 'init', array( Blocks::class, 'register' ) );
		add_action( 'init', array( Bindings::class, 'register' ) );
		add_action( 'init', array( Oembed::class, 'register' ) );
		add_action( 'init', array( Embed_Settings::class, 'register' ) );
		add_action( 'init', array( Assets::class, 'register' ) );
		add_filter( 'script_loader_tag', array( Assets::class, 'loader_tag' ), 10, 2 );
		add_action( 'enqueue_block_assets', array( Assets::class, 'editor_assets' ) );
		add_action( 'wp_footer', array( Assets::class, 'late_styles' ) );
		add_filter( 'embed_oembed_html', array( Oembed::class, 'output' ), 10, 2 );
		add_shortcode( 'showfm', array( Shortcode::class, 'render' ) );

		add_action( 'rest_api_init', array( Challenge_Endpoint::class, 'register' ) );
		add_action( 'rest_api_init', array( Editor_Api::class, 'register' ) );
		add_action( 'rest_api_init', array( Editor::class, 'register_fields' ) );
		add_action( 'enqueue_block_editor_assets', array( Editor::class, 'enqueue' ) );
		add_action( 'rest_api_init', array( new Ping_Endpoint( self::connection() ), 'register' ) );
		add_action( 'rest_api_init', array( self::class, 'register_admin_routes' ) );

		add_action( 'admin_init', array( Privacy::class, 'register' ) );
		add_action( 'admin_init', array( Connection::class, 'upgrade' ) );
		add_action( 'admin_init', array( Connect::class, 'finish_teardown_quietly' ) );
		add_action( 'admin_init', array( self::health(), 'ensure_scheduled' ) );
		if ( is_admin() ) {
			( new Admin( self::connect(), self::connection() ) )->boot();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Cli::register( new Cli( self::connect(), self::connection() ) );
			\WP_CLI::add_command( 'showfm migrate-embeds', new Migration_Cli( new Migrator( self::connection(), self::api_client() ) ) );
		}
	}

	/**
	 * Registers the settings screen's REST routes.
	 */
	public static function register_admin_routes(): void {
		$status = new Admin_Status( self::connection() );
		( new Admin_Endpoint( self::connect(), $status ) )->register();
		( new Publishing_Endpoint( self::connection() ) )->register();
		( new Migrate_Endpoint( new Migration_Admin( new Migrator( self::connection(), self::api_client() ) ) ) )->register();
	}

	/**
	 * Activation. Stores the cache version and nothing else: no requests, no scheduled events.
	 */
	public static function activate(): void {
		Cache::ensure_version();
	}

	/**
	 * Deactivation only unschedules events. Settings and the connection stay until uninstall.
	 *
	 * @param bool $network_wide Whether the plugin is being deactivated for the network.
	 */
	public static function deactivate( bool $network_wide = false ): void {
		if ( ! $network_wide || ! is_multisite() ) {
			self::unschedule_events();
			return;
		}

		$offset = 0;
		do {
			$site_ids = get_sites(
				array(
					'network_id' => get_current_network_id(),
					'fields'     => 'ids',
					'number'     => self::SITES_PER_BATCH,
					'offset'     => $offset,
				)
			);
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				try {
					self::unschedule_events();
				} finally {
					restore_current_blog();
				}
			}
			$offset    += self::SITES_PER_BATCH;
			$batch_size = count( $site_ids );
		} while ( self::SITES_PER_BATCH === $batch_size );
	}

	/**
	 * Removes the plugin's scheduled events on the current site.
	 */
	public static function unschedule_events(): void {
		foreach ( self::CRON_HOOKS as $hook ) {
			wp_unschedule_hook( $hook );
		}
	}

	/**
	 * The connection store.
	 */
	public static function connection(): Connection {
		if ( null === self::$connection ) {
			self::$connection = new Connection();
		}
		return self::$connection;
	}

	/**
	 * The API client.
	 */
	public static function api_client(): Api_Client {
		if ( null === self::$api_client ) {
			self::$api_client = new Api_Client( self::connection() );
		}
		return self::$api_client;
	}

	/**
	 * The cache.
	 */
	public static function cache(): Cache {
		if ( null === self::$cache ) {
			self::$cache = new Cache( self::api_client() );
		}
		return self::$cache;
	}

	/**
	 * The connect service.
	 */
	public static function connect(): Connect {
		if ( null === self::$connect ) {
			self::$connect = new Connect( self::connection(), self::api_client() );
		}
		return self::$connect;
	}

	/**
	 * The health reporter.
	 */
	public static function health(): Health {
		if ( null === self::$health ) {
			self::$health = new Health( self::connection(), self::api_client(), self::connect() );
		}
		return self::$health;
	}

	/**
	 * Drops the shared instances. Used by tests.
	 */
	public static function reset(): void {
		self::$connection = null;
		self::$api_client = null;
		self::$cache      = null;
		self::$connect    = null;
		self::$health     = null;
	}
}
