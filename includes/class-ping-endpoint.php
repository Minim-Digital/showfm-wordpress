<?php
/**
 * Signed wake-up ping from show.fm.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `POST /wp-json/showfm/v1/ping` (plan 5.2.6 and 5.3.5).
 *
 * The permission callback verifies the signature exactly as show.fm signs it:
 *
 *     X-Showfm-Signature: v1={hex HMAC-SHA256(ping_secret, "v1.{site_id}.{timestamp}.{nonce}")}
 *
 * with the timestamp within 300 seconds and each nonce accepted once in 10 minutes. The body
 * is never read. The handler only queues one `showfm_pull` and starts WP-Cron; the pull
 * itself runs later, so a ping never makes this request fetch anything.
 */
final class Ping_Endpoint {

	/** REST namespace. */
	const NAMESPACE = 'showfm/v1';

	/** Route. */
	const ROUTE = '/ping';

	/** WP-Cron hook that pulls the change feed. */
	const PULL_HOOK = 'showfm_pull';

	/** Accepted clock difference, in seconds. */
	const WINDOW = 300;

	/** How long a nonce is remembered, in seconds. */
	const NONCE_TTL = 600;

	/** Option prefix for claimed nonces (autoload off). Each value is its expiry time. */
	const NONCE_PREFIX = 'showfm_ping_nonce_';

	/** Option holding the time of the last accepted ping (autoload off). */
	const LAST_PING_OPTION = 'showfm_last_ping_at';

	/** Signature scheme prefix. */
	const SCHEME = 'v1';

	/**
	 * Connection store, read by the permission callback.
	 *
	 * @var Connection
	 */
	private $connection;

	/**
	 * Builds the endpoint.
	 *
	 * @param Connection $connection Connection store.
	 */
	public function __construct( Connection $connection ) {
		$this->connection = $connection;
	}

	/**
	 * Registers the route. Runs on `rest_api_init`.
	 */
	public function register(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'verify' ),
			)
		);
	}

	/**
	 * Permission callback: true only for a fresh, correctly signed ping for this site.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function verify( \WP_REST_Request $request ) {
		$site_id   = (string) $request->get_header( 'x_showfm_site' );
		$timestamp = (string) $request->get_header( 'x_showfm_timestamp' );
		$nonce     = (string) $request->get_header( 'x_showfm_nonce' );
		$signature = strtolower( (string) $request->get_header( 'x_showfm_signature' ) );

		if (
			! preg_match( '/^[0-9a-fA-F-]{36}$/', $site_id )
			|| ! preg_match( '/^[0-9]{1,12}$/', $timestamp )
			|| ! preg_match( '/^[A-Za-z0-9_+\/=-]{16,128}$/', $nonce )
			|| ! preg_match( '/^v1=[0-9a-f]{64}$/', $signature )
		) {
			return self::refused();
		}

		if ( abs( time() - (int) $timestamp ) > self::WINDOW ) {
			return self::refused();
		}

		$secret = $this->connection->ping_secret();
		$stored = $this->connection->site_id();
		if ( null === $secret || null === $stored || ! hash_equals( strtolower( $stored ), strtolower( $site_id ) ) ) {
			return self::refused();
		}

		$expected = self::SCHEME . '=' . hash_hmac( 'sha256', self::SCHEME . '.' . $site_id . '.' . $timestamp . '.' . $nonce, $secret );
		if ( ! hash_equals( $expected, $signature ) ) {
			return self::refused();
		}

		// Only a correctly signed ping is remembered, so nobody else can fill the store.
		if ( ! self::claim( self::NONCE_PREFIX . hash( 'sha256', strtolower( $site_id ) . '.' . $nonce ) ) ) {
			return self::refused();
		}

		return true;
	}

	/**
	 * Claims a nonce for NONCE_TTL seconds. The INSERT IGNORE on the unique option_name
	 * key is atomic, so when two copies of a ping arrive together only one claims it.
	 * Expired claims are removed first, so the store holds at most 10 minutes of pings.
	 *
	 * Options are used rather than transients because a transient has no atomic add, and
	 * the database stays the one store with or without a persistent object cache.
	 *
	 * @param string $name Option name for the nonce.
	 * @return bool Whether this request claimed it.
	 */
	private static function claim( string $name ): bool {
		global $wpdb;

		$now = time();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Expired nonce claims are never read through the options API.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) <= %d",
				$wpdb->esc_like( self::NONCE_PREFIX ) . '%',
				$now
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- add_option() is not atomic (it upserts), INSERT IGNORE is.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
				$name,
				(string) ( $now + self::NONCE_TTL )
			)
		);

		return 1 === $inserted;
	}

	/**
	 * Queues one pull and starts WP-Cron. Ignores the body. Answers 202.
	 */
	public function handle(): \WP_REST_Response {
		if ( false === wp_next_scheduled( self::PULL_HOOK ) ) {
			wp_schedule_single_event( time(), self::PULL_HOOK );
		}
		update_option( self::LAST_PING_OPTION, time(), false );

		// Without this the pull waits for the next page view (before WordPress 6.9).
		spawn_cron();

		$response = new \WP_REST_Response( null, 202 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * The one refusal for every failure, so a caller learns nothing about which check failed.
	 */
	private static function refused(): \WP_Error {
		return new \WP_Error( 'showfm_ping_refused', 'Ping refused.', array( 'status' => 401 ) );
	}
}
