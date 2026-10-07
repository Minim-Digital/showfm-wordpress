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
	 * Registers hooks. Runs on `plugins_loaded`.
	 */
	public static function boot(): void {
		add_action( Cache::REFRESH_HOOK, array( self::cache(), 'refresh' ) );
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
					'fields' => 'ids',
					'number' => self::SITES_PER_BATCH,
					'offset' => $offset,
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
	private static function unschedule_events(): void {
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
	 * Drops the shared instances. Used by tests.
	 */
	public static function reset(): void {
		self::$connection = null;
		self::$api_client = null;
		self::$cache      = null;
	}
}
