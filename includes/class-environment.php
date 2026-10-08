<?php
/**
 * The show.fm environment the plugin talks to.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every show.fm host the plugin uses comes from here: the API (the only host the site key
 * is ever sent to), the app, the listen domain, the media hosts and the embed hosts.
 *
 * Production is built in. A site's own code, such as a must-use plugin, can return another
 * show.fm environment for testing from the `showfm_environment` filter:
 *
 *     add_filter( 'showfm_environment', function () {
 *         return array(
 *             'api'    => 'https://api.example.test',
 *             'app'    => 'https://my.example.test',
 *             'listen' => 'example.test',
 *             'media'  => array( 'm.example.test' ),
 *             'embed'  => array( 'embed.example.test' ),
 *         );
 *     } );
 *
 * Every part must be given. `api` and `app` are https origins, `listen` is the domain whose
 * subdomains serve listen pages, and `media` and `embed` list hosts. Hosts are plain names:
 * no wildcards, ports, credentials or IP addresses. If anything is missing or invalid the
 * whole value is ignored and production is used.
 */
final class Environment {

	/** The filter a site's own code can return another environment from. */
	const FILTER = 'showfm_environment';

	/** Production show.fm. */
	const PRODUCTION = array(
		'api'    => 'https://api.show.fm',
		'app'    => 'https://my.show.fm',
		'listen' => 'show.fm',
		'media'  => array( 'm.cdn.media', 'media.podcasterplus.com' ),
		'embed'  => array( 'embed.cdn.media' ),
	);

	/** At most this many media or embed hosts. */
	const MAX_HOSTS = 10;

	/** A lower-case host name with at least two labels, the last starting with a letter. */
	const HOST = '/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?\z/';

	/**
	 * Whether an invalid filtered value was reported in this request.
	 *
	 * @var bool
	 */
	private static $warned = false;

	/**
	 * The environment in use: production, or a valid one from the filter.
	 *
	 * @return array{api:string,app:string,listen:string,media:string[],embed:string[]}
	 */
	public static function get(): array {
		/**
		 * Filters the show.fm environment. Return null for production, or an array with
		 * `api`, `app`, `listen`, `media` and `embed` (see the class comment).
		 *
		 * @param array|null $environment Null.
		 */
		$filtered = apply_filters( 'showfm_environment', null );
		if ( null === $filtered ) {
			return self::PRODUCTION;
		}
		$environment = self::validate( $filtered );
		if ( null === $environment ) {
			if ( ! self::$warned ) {
				self::$warned = true;
				_doing_it_wrong( 'showfm_environment', esc_html__( 'The show.fm environment needs an https api and app origin, a listen domain, and media and embed hosts. Using production.', 'showfm' ), '1.0.2' );
			}
			return self::PRODUCTION;
		}
		return $environment;
	}

	/**
	 * Whether the environment is production.
	 */
	public static function is_production(): bool {
		return self::PRODUCTION === self::get();
	}

	/**
	 * Domains whose subdomains (and paths, in the legacy form) serve listen pages: show.fm's,
	 * and the environment's.
	 *
	 * @return string[]
	 */
	public static function listen_domains(): array {
		return array_values( array_unique( array( self::PRODUCTION['listen'], self::get()['listen'] ) ) );
	}

	/**
	 * Media hosts: show.fm's, and the environment's.
	 *
	 * @return string[]
	 */
	public static function media_hosts(): array {
		return array_values( array_unique( array_merge( self::PRODUCTION['media'], self::get()['media'] ) ) );
	}

	/**
	 * Embed script and iframe hosts: show.fm's, and the environment's.
	 *
	 * @return string[]
	 */
	public static function embed_hosts(): array {
		return array_values( array_unique( array_merge( self::PRODUCTION['embed'], self::get()['embed'] ) ) );
	}

	/**
	 * A whole environment, normalised, or null if any part is missing or invalid.
	 *
	 * @param mixed $environment Candidate.
	 * @return array{api:string,app:string,listen:string,media:string[],embed:string[]}|null
	 */
	public static function validate( $environment ): ?array {
		if ( ! is_array( $environment ) ) {
			return null;
		}
		$valid = array(
			'api'    => self::origin( $environment['api'] ?? null ),
			'app'    => self::origin( $environment['app'] ?? null ),
			'listen' => self::host( $environment['listen'] ?? null ),
			'media'  => self::hosts( $environment['media'] ?? null ),
			'embed'  => self::hosts( $environment['embed'] ?? null ),
		);
		if ( in_array( null, $valid, true ) ) {
			return null;
		}
		return $valid;
	}

	/**
	 * An https origin on a valid host, with no port, credentials, path, query or fragment.
	 *
	 * @param mixed $url Candidate.
	 */
	private static function origin( $url ): ?string {
		$parts = is_string( $url ) ? wp_parse_url( $url ) : false;
		if (
			! is_array( $parts )
			|| 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) )
			|| isset( $parts['port'] ) || isset( $parts['user'] ) || isset( $parts['pass'] )
			|| isset( $parts['query'] ) || isset( $parts['fragment'] )
			|| ( isset( $parts['path'] ) && '/' !== $parts['path'] )
		) {
			return null;
		}
		$host = self::host( $parts['host'] ?? null );
		return null === $host ? null : 'https://' . $host;
	}

	/**
	 * A valid host name, lower case.
	 *
	 * @param mixed $host Candidate.
	 */
	private static function host( $host ): ?string {
		if ( ! is_string( $host ) ) {
			return null;
		}
		$host = strtolower( $host );
		return preg_match( self::HOST, $host ) ? $host : null;
	}

	/**
	 * A non-empty list of at most MAX_HOSTS valid hosts.
	 *
	 * @param mixed $hosts Candidate.
	 * @return string[]|null
	 */
	private static function hosts( $hosts ): ?array {
		if ( ! is_array( $hosts ) || ! $hosts || count( $hosts ) > self::MAX_HOSTS ) {
			return null;
		}
		$valid = array();
		foreach ( $hosts as $host ) {
			$host = self::host( $host );
			if ( null === $host ) {
				return null;
			}
			$valid[] = $host;
		}
		return array_values( array_unique( $valid ) );
	}
}
