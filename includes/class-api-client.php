<?php
/**
 * HTTP client for the show.fm API.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Calls the show.fm API through `wp_safe_remote_*` and turns each response into an
 * `Api_Result`. It never logs, echoes or returns the site key.
 *
 * Only cron jobs and admin actions call this class. The front end renders from the cache.
 */
final class Api_Client {

	/** Request timeout in seconds. */
	const TIMEOUT = 5;

	/** Timeout for the best-effort revoke on Disconnect, in seconds. */
	const DISCONNECT_TIMEOUT = 3;

	/** Responses larger than this are cut off (2 MB). */
	const MAX_BODY_BYTES = 2097152;

	/** Wait used when a 429 has no usable Retry-After header. */
	const DEFAULT_RETRY_AFTER = 60;

	/** A corrupt header must not disable the connection indefinitely. */
	const MAX_RETRY_AFTER = DAY_IN_SECONDS;

	/** Option holding the time until which keyed calls wait after a 429 (autoload off). */
	const RATE_LIMIT_OPTION = 'showfm_rate_limited_until';

	/**
	 * Connection store, the source of the site key.
	 *
	 * @var Connection
	 */
	private $connection;

	/**
	 * Builds the client.
	 *
	 * @param Connection $connection Connection store.
	 */
	public function __construct( Connection $connection ) {
		$this->connection = $connection;
	}

	/**
	 * The API base URL: production, or the environment the site's own code selects with the
	 * `showfm_environment` filter (see Environment). The site key is only ever sent here.
	 */
	public static function base_url(): string {
		return Environment::get()['api'];
	}

	/**
	 * The User-Agent sent on every request. It identifies traffic and is never used for auth.
	 */
	public static function user_agent(): string {
		return 'showfm-wordpress/' . SHOWFM_VERSION . '; +' . home_url();
	}

	/**
	 * GET from the public API. No key is sent.
	 *
	 * @param string      $path Path starting with a slash, for example `/v1/episodes/{id}`.
	 * @param string|null $etag ETag of the cached copy, sent as If-None-Match.
	 */
	public function get( string $path, ?string $etag = null ): Api_Result {
		return $this->request( 'GET', $path, $this->conditional_headers( $etag ), null );
	}

	/**
	 * GET from the keyed API with the site key.
	 *
	 * @param string      $path Path starting with a slash.
	 * @param string|null $etag ETag of the cached copy, sent as If-None-Match.
	 */
	public function get_keyed( string $path, ?string $etag = null ): Api_Result {
		return $this->keyed( 'GET', $path, $this->conditional_headers( $etag ), null );
	}

	/**
	 * POST JSON to the keyed API with the site key.
	 *
	 * @param string              $path Path starting with a slash.
	 * @param array<string,mixed> $body Body, sent as JSON.
	 */
	public function post_keyed( string $path, array $body ): Api_Result {
		$json = wp_json_encode( $body );
		if ( false === $json ) {
			return new Api_Result( Api_Result::FAILED, 0, null, null, 0, 'The request body could not be encoded.' );
		}
		return $this->keyed( 'POST', $path, array( 'Content-Type' => 'application/json' ), $json );
	}

	/**
	 * POST JSON without the site key, for the one-time code exchange. The values in
	 * `$secrets` (the code and verifier) are scrubbed from any message.
	 *
	 * @param string              $path    Path starting with a slash.
	 * @param array<string,mixed> $body    Body, sent as JSON.
	 * @param string[]            $secrets Values that must never appear in a message.
	 */
	public function post( string $path, array $body, array $secrets = array() ): Api_Result {
		$json = wp_json_encode( $body );
		if ( false === $json ) {
			return new Api_Result( Api_Result::FAILED, 0, null, null, 0, 'The request body could not be encoded.' );
		}
		return $this->request( 'POST', $path, array( 'Content-Type' => 'application/json' ), $json, $secrets );
	}

	/**
	 * POST JSON with a key that is not stored yet: WP-CLI registration of a new site key.
	 * A 401 here refuses that key only and leaves the stored connection alone.
	 *
	 * @param string              $path Path starting with a slash.
	 * @param array<string,mixed> $body Body, sent as JSON.
	 * @param string              $key  The new site key.
	 */
	public function post_with_key( string $path, array $body, string $key ): Api_Result {
		$json = wp_json_encode( $body );
		if ( false === $json || '' === $key ) {
			return new Api_Result( Api_Result::FAILED, 0, null, null, 0, 'The request could not be built.' );
		}
		$waiting = self::rate_limit_remaining();
		if ( $waiting > 0 ) {
			return self::rate_limited( $waiting );
		}

		$headers = array(
			'Content-Type'  => 'application/json',
			'Authorization' => 'Bearer ' . $key,
		);
		$result  = $this->request( 'POST', $path, $headers, $json, array( $key ) );
		self::record_rate_limit( $result );
		return $result;
	}

	/**
	 * Asks show.fm to revoke this site's own key: `POST /v1/me/sites/{id}/disconnect` with an
	 * empty JSON object. Best effort, with a short timeout, and sent once: a 401 means show.fm
	 * no longer accepts the key, so there is nothing to revoke and nothing to retry. Unlike a
	 * keyed call, a 401 here never flags the stored connection, since Disconnect removes it
	 * next anyway.
	 *
	 * @param string $site_id The connected site's id at show.fm.
	 * @param string $key     The site key, from the caller's pinned connection.
	 */
	public function disconnect_site( string $site_id, string $key ): Api_Result {
		if ( '' === $key || '' === $site_id ) {
			return new Api_Result( Api_Result::FAILED, 0, null, null, 0, 'The request could not be built.' );
		}
		$waiting = self::rate_limit_remaining();
		if ( $waiting > 0 ) {
			return self::rate_limited( $waiting );
		}
		$headers = array(
			'Content-Type'  => 'application/json',
			'Authorization' => 'Bearer ' . $key,
		);
		return $this->request( 'POST', '/v1/me/sites/' . rawurlencode( $site_id ) . '/disconnect', $headers, '{}', array( $key ), self::DISCONNECT_TIMEOUT );
	}

	/**
	 * Seconds left before keyed calls may be sent again after a 429, or 0.
	 */
	public static function rate_limit_remaining(): int {
		$until = (int) get_option( self::RATE_LIMIT_OPTION, 0 );
		return max( 0, $until - time() );
	}

	/**
	 * Sends a keyed request. A 401 marks the connection as needing reconnection, after
	 * which no keyed request is sent until the admin reconnects: retrying a revoked key
	 * would spend the shared IP's auth-failure budget. A 429 holds every keyed call until
	 * its Retry-After has passed.
	 *
	 * @param string               $method  HTTP method.
	 * @param string               $path    Path starting with a slash.
	 * @param array<string,string> $headers Extra headers.
	 * @param string|null          $body    Request body.
	 */
	private function keyed( string $method, string $path, array $headers, ?string $body ): Api_Result {
		// One read: the key sent and the state it belongs to come from the same value, so a
		// 401 can only ever mark that state, never a connection saved meanwhile.
		$pinned = $this->connection->pinned();
		$state  = $pinned->snapshot()['id'];
		$key    = $pinned->key();
		if ( null === $key ) {
			return new Api_Result( Api_Result::UNAUTHORISED, 0, null, null, 0, 'This site is not connected to show.fm, or needs reconnecting.' );
		}
		$waiting = self::rate_limit_remaining();
		if ( $waiting > 0 ) {
			return self::rate_limited( $waiting );
		}

		$headers['Authorization'] = 'Bearer ' . $key;

		$result = $this->request( $method, $path, $headers, $body, array( $key ) )->for_state( $state );
		if ( $result->is( Api_Result::UNAUTHORISED ) ) {
			$pinned->mark_reconnect_needed();
		}
		self::record_rate_limit( $result );
		return $result;
	}

	/**
	 * Holds keyed calls for the Retry-After of a 429.
	 *
	 * @param Api_Result $result Result of a keyed call.
	 */
	private static function record_rate_limit( Api_Result $result ): void {
		if ( $result->is( Api_Result::RATE_LIMITED ) ) {
			update_option( self::RATE_LIMIT_OPTION, time() + $result->retry_after(), false );
		}
	}

	/**
	 * A rate-limited result for a call that was held back and never sent.
	 *
	 * @param int $seconds Seconds left to wait.
	 */
	private static function rate_limited( int $seconds ): Api_Result {
		return new Api_Result( Api_Result::RATE_LIMITED, 0, null, null, $seconds, 'Waiting for show.fm\'s rate limit to pass.' );
	}

	/**
	 * Builds the If-None-Match header.
	 *
	 * @param string|null $etag ETag of the cached copy.
	 * @return array<string,string>
	 */
	private function conditional_headers( ?string $etag ): array {
		if ( null === $etag || '' === $etag ) {
			return array();
		}
		return array( 'If-None-Match' => $etag );
	}

	/**
	 * Sends one request and types the response.
	 *
	 * @param string               $method  HTTP method.
	 * @param string               $path    Path starting with a slash.
	 * @param array<string,string> $headers Request headers.
	 * @param string|null          $body    Request body.
	 * @param string[]             $secrets Values to scrub from any message.
	 * @param int                  $timeout Timeout in seconds.
	 */
	private function request( string $method, string $path, array $headers, ?string $body, array $secrets = array(), int $timeout = self::TIMEOUT ): Api_Result {
		if ( ! self::is_valid_path( $path ) ) {
			return new Api_Result( Api_Result::FAILED, 0, null, null, 0, 'Invalid API path.' );
		}

		$args = array(
			'method'              => $method,
			'timeout'             => $timeout,
			'redirection'         => 0,
			'user-agent'          => self::user_agent(),
			'headers'             => array_merge( array( 'Accept' => 'application/json' ), $headers ),
			'limit_response_size' => self::MAX_BODY_BYTES,
		);
		if ( null !== $body ) {
			$args['body'] = $body;
		}

		$url      = self::base_url() . $path;
		$response = 'POST' === $method ? wp_safe_remote_post( $url, $args ) : wp_safe_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			return new Api_Result(
				Api_Result::TRANSIENT_FAILURE,
				0,
				null,
				null,
				0,
				self::scrub( 'Network error: ' . $response->get_error_message(), $secrets )
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$etag   = self::header( $response, 'etag' );

		if ( $status >= 200 && $status < 300 ) {
			$raw = (string) wp_remote_retrieve_body( $response );
			if ( '' === $raw ) {
				return new Api_Result( Api_Result::SUCCESS, $status, null, $etag );
			}
			$data = json_decode( $raw, true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return new Api_Result( Api_Result::FAILED, $status, null, null, 0, 'The API returned a body that is not JSON.' );
			}
			return new Api_Result( Api_Result::SUCCESS, $status, $data, $etag );
		}

		if ( 304 === $status ) {
			$sent = $headers['If-None-Match'] ?? null;
			return new Api_Result( Api_Result::NOT_MODIFIED, $status, null, null !== $etag ? $etag : $sent );
		}

		list( $error_code, $error_reason ) = self::error_fields( (string) wp_remote_retrieve_body( $response ) );

		if ( 401 === $status ) {
			return new Api_Result( Api_Result::UNAUTHORISED, $status, null, null, 0, 'show.fm did not accept the site key.', $error_code, $error_reason );
		}

		if ( 403 === $status || 404 === $status ) {
			return new Api_Result( Api_Result::UNAVAILABLE, $status, null, null, 0, 'Not available from show.fm.', $error_code, $error_reason );
		}

		if ( 429 === $status ) {
			return new Api_Result(
				Api_Result::RATE_LIMITED,
				$status,
				null,
				null,
				self::parse_retry_after( self::header( $response, 'retry-after' ) ),
				'Rate limited by show.fm.'
			);
		}

		if ( $status >= 500 ) {
			return new Api_Result( Api_Result::TRANSIENT_FAILURE, $status, null, null, 0, 'show.fm returned a server error.' );
		}

		return new Api_Result( Api_Result::FAILED, $status, null, null, 0, 'Unexpected response from show.fm.', $error_code, $error_reason );
	}

	/**
	 * Reads `error.code` and the first `error.details[].reason` from an error body. Both are
	 * short machine-readable tokens; anything else is dropped, and the message is never kept.
	 *
	 * @param string $raw Response body.
	 * @return array{0:string,1:string}
	 */
	private static function error_fields( string $raw ): array {
		$body = '' === $raw ? null : json_decode( $raw, true );
		if ( ! is_array( $body ) || ! is_array( $body['error'] ?? null ) ) {
			return array( '', '' );
		}
		$error  = $body['error'];
		$code   = self::token( $error['code'] ?? null );
		$reason = '';
		if ( is_array( $error['details'] ?? null ) && is_array( $error['details'][0] ?? null ) ) {
			$reason = self::token( $error['details'][0]['reason'] ?? null );
		}
		return array( $code, $reason );
	}

	/**
	 * A machine-readable token from the API, or '' when the value is not one.
	 *
	 * @param mixed $value Value from the body.
	 */
	private static function token( $value ): string {
		return is_string( $value ) && preg_match( '/^[a-z0-9_]{1,64}$/', $value ) ? $value : '';
	}

	/**
	 * Paths are relative to the base URL: one leading slash, no scheme, no host.
	 *
	 * @param string $path Requested path.
	 */
	private static function is_valid_path( string $path ): bool {
		return '' !== $path
			&& '/' === $path[0]
			&& 0 !== strpos( $path, '//' )
			&& false === strpos( $path, '\\' )
			&& ! preg_match( '/[\x00-\x20\x7f]/', $path );
	}

	/**
	 * Reads one response header as a string.
	 *
	 * @param array<string,mixed> $response Response from the HTTP API.
	 * @param string              $name     Header name.
	 */
	private static function header( array $response, string $name ): ?string {
		$value = wp_remote_retrieve_header( $response, $name );
		if ( is_array( $value ) ) {
			$value = end( $value );
		}
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}
		return $value;
	}

	/**
	 * Converts Retry-After (seconds or an HTTP date) into seconds, bounded to one day.
	 *
	 * @param string|null $value Header value.
	 */
	public static function parse_retry_after( ?string $value ): int {
		$seconds = self::DEFAULT_RETRY_AFTER;
		if ( null !== $value ) {
			$value = trim( $value );
			if ( ctype_digit( $value ) ) {
				$seconds = (int) $value;
			} else {
				$time = strtotime( $value );
				if ( false !== $time ) {
					$seconds = $time - time();
				}
			}
		}
		return max( 1, min( self::MAX_RETRY_AFTER, $seconds ) );
	}

	/**
	 * Removes secrets from a message.
	 *
	 * @param string   $message Message.
	 * @param string[] $secrets Secrets.
	 */
	private static function scrub( string $message, array $secrets ): string {
		foreach ( $secrets as $secret ) {
			if ( '' !== $secret ) {
				$message = str_replace( $secret, '[redacted]', $message );
			}
		}
		return $message;
	}
}
