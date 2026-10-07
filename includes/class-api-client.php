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

	/** Production API. */
	const DEFAULT_BASE_URL = 'https://api.show.fm';

	/**
	 * Hosts `SHOWFM_API_URL` may point at. The site key is only ever sent to these.
	 *
	 * @var string[]
	 */
	const ALLOWED_HOSTS = array( 'api.show.fm', 'api.showfm.dev' );

	/** Request timeout in seconds. */
	const TIMEOUT = 5;

	/** Responses larger than this are cut off (2 MB). */
	const MAX_BODY_BYTES = 2097152;

	/** Wait used when a 429 has no usable Retry-After header. */
	const DEFAULT_RETRY_AFTER = 60;

	/** Longest wait honoured from Retry-After (one hour). */
	const MAX_RETRY_AFTER = 3600;

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
	 * The API base URL. `SHOWFM_API_URL` in wp-config.php can point it at staging
	 * (`https://api.showfm.dev`). Anything other than https on an allowed host is ignored.
	 */
	public static function base_url(): string {
		if ( ! defined( 'SHOWFM_API_URL' ) ) {
			return self::DEFAULT_BASE_URL;
		}
		return self::sanitize_base_url( constant( 'SHOWFM_API_URL' ) );
	}

	/**
	 * Returns the URL's origin if it is https on an allowed host with no port, credentials,
	 * path, query or fragment, otherwise the production URL.
	 *
	 * @param mixed $url Candidate base URL.
	 */
	public static function sanitize_base_url( $url ): string {
		if ( ! is_string( $url ) ) {
			return self::DEFAULT_BASE_URL;
		}

		$parts = wp_parse_url( $url );
		if (
			! is_array( $parts )
			|| ! isset( $parts['scheme'], $parts['host'] )
			|| 'https' !== strtolower( $parts['scheme'] )
			|| ! in_array( strtolower( $parts['host'] ), self::ALLOWED_HOSTS, true )
			|| isset( $parts['port'] ) || isset( $parts['user'] ) || isset( $parts['pass'] )
			|| isset( $parts['query'] ) || isset( $parts['fragment'] )
			|| ( isset( $parts['path'] ) && '/' !== $parts['path'] )
		) {
			return self::DEFAULT_BASE_URL;
		}

		return 'https://' . strtolower( $parts['host'] );
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
		return $this->request( 'GET', $path, $this->conditional_headers( $etag ), null, '' );
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
	 * Sends a keyed request. A 401 marks the connection as needing reconnection, after
	 * which no keyed request is sent until the admin reconnects: retrying a revoked key
	 * would spend the shared IP's auth-failure budget.
	 *
	 * @param string               $method  HTTP method.
	 * @param string               $path    Path starting with a slash.
	 * @param array<string,string> $headers Extra headers.
	 * @param string|null          $body    Request body.
	 */
	private function keyed( string $method, string $path, array $headers, ?string $body ): Api_Result {
		$key = $this->connection->key();
		if ( null === $key ) {
			return new Api_Result( Api_Result::UNAUTHORISED, 0, null, null, 0, 'This site is not connected to show.fm, or needs reconnecting.' );
		}

		$headers['Authorization'] = 'Bearer ' . $key;

		$result = $this->request( $method, $path, $headers, $body, $key );
		if ( $result->is( Api_Result::UNAUTHORISED ) ) {
			$this->connection->mark_reconnect_needed();
		}
		return $result;
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
	 * @param string               $secret  Value to scrub from any message, or ''.
	 */
	private function request( string $method, string $path, array $headers, ?string $body, string $secret ): Api_Result {
		if ( ! self::is_valid_path( $path ) ) {
			return new Api_Result( Api_Result::FAILED, 0, null, null, 0, 'Invalid API path.' );
		}

		$args = array(
			'method'              => $method,
			'timeout'             => self::TIMEOUT,
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
				self::scrub( 'Network error: ' . $response->get_error_message(), $secret )
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

		if ( 401 === $status ) {
			return new Api_Result( Api_Result::UNAUTHORISED, $status, null, null, 0, 'show.fm did not accept the site key.' );
		}

		if ( 403 === $status || 404 === $status ) {
			return new Api_Result( Api_Result::UNAVAILABLE, $status, null, null, 0, 'Not available from show.fm.' );
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

		return new Api_Result( Api_Result::FAILED, $status, null, null, 0, 'Unexpected response from show.fm.' );
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
	 * Converts Retry-After (seconds or an HTTP date) into seconds, between 1 and 3600.
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
	 * Removes a secret from a message.
	 *
	 * @param string $message Message.
	 * @param string $secret  Secret, or ''.
	 */
	private static function scrub( string $message, string $secret ): string {
		if ( '' === $secret ) {
			return $message;
		}
		return str_replace( $secret, '[redacted]', $message );
	}
}
