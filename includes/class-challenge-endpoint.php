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
 * never cacheable, and each IP address gets a small budget per minute.
 */
final class Challenge_Endpoint {

	/** REST namespace. */
	const NAMESPACE = 'showfm/v1';

	/** Route. */
	const ROUTE = '/challenge';

	/** Requests allowed per IP address in one window. */
	const LIMIT = 30;

	/** Rate-limit window, in seconds. */
	const WINDOW = 60;

	/** Transient prefix for the per-IP counters. */
	const COUNTER_PREFIX = 'showfm_rl_challenge_';

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
		if ( ! self::within_limit() ) {
			$response = self::response(
				array(
					'code'    => 'showfm_rate_limited',
					'message' => 'Too many requests.',
				),
				429
			);
			$response->header( 'Retry-After', (string) self::WINDOW );
			return $response;
		}

		$state     = $request->get_param( 'state' );
		$challenge = is_string( $state ) ? Connect::challenge_for_state( $state ) : null;
		if ( null === $challenge ) {
			return self::response(
				array(
					'code'    => 'showfm_not_found',
					'message' => 'Not found.',
				),
				404
			);
		}
		return self::response( array( 'code_challenge' => $challenge ), 200 );
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
	 * Counts this request against the caller's IP address. Only REMOTE_ADDR is used: a
	 * forwarded-for header is set by the caller and would let it pick its own budget.
	 */
	private static function within_limit(): bool {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key = self::COUNTER_PREFIX . md5( $ip . '|' . (int) floor( time() / self::WINDOW ) );

		$count = (int) get_transient( $key );
		if ( $count >= self::LIMIT ) {
			return false;
		}
		set_transient( $key, $count + 1, self::WINDOW );
		return true;
	}
}
