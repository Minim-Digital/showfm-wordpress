<?php
/**
 * REST route show.fm fetches to prove the site started a connection.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `GET /wp-json/showfm/v1/challenge?state=…` (plan 5.2.2 step 3).
 *
 * Public, because show.fm's server calls it. It answers only the S256 challenge stored for
 * that exact state, never the state or the verifier, and 404 for anything else. Answers are
 * never cacheable.
 *
 * While no connection is being started, every request gets a 404 without any lookup or
 * write. While one is, a right state is always answered, so show.fm's own fetch is never
 * held back. Wrong states share one global budget per minute: one counter, whatever the
 * caller's address, so rotating addresses adds no rows and a proxy that hides the client
 * address changes nothing.
 */
final class Challenge_Endpoint {

	/** REST namespace. */
	const NAMESPACE = 'showfm/v1';

	/** Route. */
	const ROUTE = '/challenge';

	/** Wrong states answered with 404 in one window, across all callers. */
	const LIMIT = 60;

	/** Rate-limit window, in seconds. */
	const WINDOW = 60;

	/** Prefix of the counter: one options row per window (autoload off, value is the misses). */
	const COUNTER_PREFIX = 'showfm_challenge_misses_';

	/**
	 * Registers the route. Runs on `rest_api_init`.
	 */
	public static function register(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'handle' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'state' => array(
						'type'     => 'string',
						'required' => false,
					),
				),
			)
		);
	}

	/**
	 * Answers the challenge for a state.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function handle( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! Connect::challenge_open() ) {
			return self::not_found();
		}

		$state     = $request->get_param( 'state' );
		$challenge = is_string( $state ) ? Connect::challenge_for_state( $state ) : null;
		if ( null !== $challenge ) {
			return self::response( array( 'code_challenge' => $challenge ), 200 );
		}

		if ( ! self::count_miss() ) {
			$response = self::response(
				array(
					'code'    => 'showfm_rate_limited',
					'message' => 'Too many requests.',
				),
				429
			);
			$response->header( 'Retry-After', (string) ( self::WINDOW - time() % self::WINDOW ) );
			return $response;
		}
		return self::not_found();
	}

	/**
	 * The 404 for every unknown, expired or missing state.
	 */
	private static function not_found(): \WP_REST_Response {
		return self::response(
			array(
				'code'    => 'showfm_not_found',
				'message' => 'Not found.',
			),
			404
		);
	}

	/**
	 * A response no browser, proxy or CDN may keep.
	 *
	 * @param array<string,string> $data   Body.
	 * @param int                  $status Status.
	 */
	private static function response( array $data, int $status ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'Expires', '0' );
		$response->header( 'X-Robots-Tag', 'noindex' );
		return $response;
	}

	/**
	 * Counts a wrong state against the one global window, in one options row per window.
	 * Returns false once the window's budget is spent.
	 *
	 * The conditional UPDATE is one atomic statement, so concurrent misses can never take
	 * the count past LIMIT: a miss is admitted only when its UPDATE changed the row. The
	 * first miss of a window creates the row with INSERT IGNORE and removes the rows of
	 * strictly older windows only, so a request delayed across a boundary can never remove
	 * a newer window's count. The database is the one counter, with or without an object
	 * cache, so there is one budget.
	 */
	private static function count_miss(): bool {
		global $wpdb;

		$window = (int) floor( time() / self::WINDOW );
		$name   = self::COUNTER_PREFIX . $window;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- add_option() is not atomic (it upserts), INSERT IGNORE is.
		$created = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '0', 'off')",
				$name
			)
		);
		if ( 1 === $created ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Counter rows are never read through the options API.
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(SUBSTRING(option_name, %d) AS UNSIGNED) < %d",
					$wpdb->esc_like( self::COUNTER_PREFIX ) . '%',
					strlen( self::COUNTER_PREFIX ) + 1,
					$window
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One atomic check-and-increment.
		$admitted = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = CAST(option_value AS UNSIGNED) + 1 WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d",
				$name,
				self::LIMIT
			)
		);

		return 1 === $admitted;
	}
}
