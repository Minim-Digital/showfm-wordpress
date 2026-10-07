<?php
/**
 * Registers show.fm listen-page oEmbed providers.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Core handles fetching and caching the provider response in post meta. */
final class Oembed {
	/** Register host-form and legacy path-form listen pages, including staging. */
	public static function register(): void {
		$endpoint = Api_Client::base_url() . '/v1/oembed';
		wp_oembed_add_provider( 'https://*.show.fm/*', $endpoint );
		wp_oembed_add_provider( 'https://show.fm/*', $endpoint );
		if ( 'https://api.showfm.dev' === Api_Client::base_url() ) {
			wp_oembed_add_provider( 'https://*.showfm.dev/*', $endpoint );
			wp_oembed_add_provider( '~^https://[a-z0-9]+(?:-[a-z0-9]+)*\.showfm\.dev/?(?:[?#].*)?$~i', $endpoint, true );
		}
		// A show homepage need not have a trailing slash.
		wp_oembed_add_provider( '~^https://[a-z0-9]+(?:-[a-z0-9]+)*\.show\.fm/?(?:[?#].*)?$~i', $endpoint, true );
	}

	/**
	 * Enqueue only when cached oEmbed markup is actually output, never on registration.
	 * The provider remains authoritative about accepted paths.
	 *
	 * @param string $html Cached embed HTML.
	 * @param string $url Original listen URL.
	 */
	public static function output( string $html, string $url ): string {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$root = 'https://api.showfm.dev' === Api_Client::base_url() ? 'showfm.dev' : 'show.fm';
		if ( ! is_string( $host ) || ! preg_match( '/^(?:[a-z0-9-]+\.)?' . preg_quote( $root, '/' ) . '$/i', $host ) ) {
			return $html;
		}
		$tags = new \WP_HTML_Tag_Processor( $html );
		if ( ! $tags->next_tag( array( 'tag_name' => 'iframe' ) ) ) {
			return $html;
		}
		$src   = $tags->get_attribute( 'src' );
		$parts = is_string( $src ) ? wp_parse_url( $src ) : false;
		if ( ! is_array( $parts ) || ! in_array( $parts['host'] ?? '', array( 'embed.cdn.media', 'embed.showfm.dev' ), true ) ) {
			return $html;
		}
		$path  = $parts['path'] ?? '';
		$attrs = array();
		if ( preg_match( '~^/ep/([^/]+)$~', $path, $match ) && Attributes::uuid( $match[1] ) ) {
			$attrs['episode'] = $match[1];
		} elseif ( preg_match( '~^/latest/([a-z0-9]+(?:-[a-z0-9]+)*)$~', $path, $match ) ) {
			$attrs['podcast'] = $match[1];
		} else {
			return $html;
		}
		$query = array();
		wp_parse_str( $parts['query'] ?? '', $query );
		$attrs['size']     = $query['size'] ?? 'standard';
		$title             = $tags->get_attribute( 'title' );
		$attrs['snapshot'] = array(
			'title'     => is_string( $title ) ? $title : __( 'Listen on show.fm', 'showfm' ),
			'listenUrl' => $url,
		);
		// Upgrade core's cached iframe to a local player so the site credit setting and
		// bundled-script policy also apply to oEmbeds, without another HTTP request.
		return Embed::render( 'player', $attrs );
	}
}
