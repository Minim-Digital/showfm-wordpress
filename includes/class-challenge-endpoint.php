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

	/** Option holding the one counter: the window number and its misses (autoload off). */
	const COUNTER_OPTION = 'showfm_challenge_misses';

	/** Object cache group for the counter when a persistent cache is in use. */
	const CACHE_GROUP = 'showfm';

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
	 * Counts a wrong state against the one global window. Returns false once the window's
	 * budget is spent. A persistent object cache counts atomically with one key per window,
	 * which expires on its own; otherwise one option holds the current window. Either way
	 * the number of keys never grows with the number of callers.
	 */
	private static function count_miss(): bool {
		$window = (int) floor( time() / self::WINDOW );

		if ( wp_using_ext_object_cache() ) {
			$key = 'challenge_misses_' . $window;
			wp_cache_add( $key, 0, self::CACHE_GROUP, 2 * self::WINDOW );
			$count = wp_cache_incr( $key, 1, self::CACHE_GROUP );
			return false === $count || $count <= self::LIMIT;
		}

		$stored = get_option( self::COUNTER_OPTION );
		$count  = is_array( $stored ) && ( $stored['window'] ?? null ) === $window ? (int) ( $stored['count'] ?? 0 ) : 0;
		if ( $count >= self::LIMIT ) {
			return false;
		}
		update_option(
			self::COUNTER_OPTION,
			array(
				'window' => $window,
				'count'  => $count + 1,
			),
			false
		);
		return true;
	}
}
