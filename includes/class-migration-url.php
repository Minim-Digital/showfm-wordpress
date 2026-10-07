<?php
/**
 * Conservative enclosure URL comparison, without network requests.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Removes only recognised tracking wrappers; retains media path case and query data. */
final class Migration_Url {
	/**
	 * A comparison key, or an empty string for a non-HTTP URL.
	 *
	 * @param string $url Enclosure URL.
	 */
	public static function normalise( string $url ): string {
		$url = html_entity_decode( trim( $url ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		for ( $depth = 0; $depth < 10; ++$depth ) {
			if ( 0 === strpos( $url, '//' ) ) {
				$url = 'https:' . $url;
			}
			$parts = wp_parse_url( $url );
			if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ?? '' ), array( 'http', 'https' ), true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
				return '';
			}
			$host = strtolower( $parts['host'] );
			$path = $parts['path'] ?? '/';
			$tail = '';
			if ( 'dts.podtrac.com' === $host && preg_match( '~^/redirect\.(?:mp3|m4a|mp4)/(.*)$~', $path, $match ) ) {
				$tail = $match[1];
			} elseif ( 'chtbl.com' === $host && preg_match( '~^/track/[^/]+/(.*)$~', $path, $match ) ) {
				$tail = $match[1];
			} elseif ( 'op3.dev' === $host && preg_match( '~^/e/(?:[^/]+/)?(https?://.*)$~', $path, $match ) ) {
				$tail = $match[1];
			}
			if ( '' === $tail ) {
				$port = isset( $parts['port'] ) && ! in_array( $parts['port'], array( 80, 443 ), true ) ? ':' . $parts['port'] : '';
				return $host . $port . $path . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
			}
			$url = ( preg_match( '~^https?://~i', $tail ) ? '' : 'https://' ) . $tail . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
		}
		return '';
	}
}
