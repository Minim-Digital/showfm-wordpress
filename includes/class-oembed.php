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
			wp_oembed_add_provider( 'https://showfm.dev/*', $endpoint );
			wp_oembed_add_provider( '~^https://[a-z0-9]+(?:-[a-z0-9]+)*\.showfm\.dev/?(?:[?#].*)?\z~i', $endpoint, true );
		}
		// A show homepage need not have a trailing slash.
		wp_oembed_add_provider( '~^https://[a-z0-9]+(?:-[a-z0-9]+)*\.show\.fm/?(?:[?#].*)?\z~i', $endpoint, true );
	}

	/** The show.fm script and iframe hosts. No markup from them is ever passed through. */
	const CDN_HOSTS = array( 'embed.cdn.media', 'embed.showfm.dev' );

	/**
	 * Replaces any show.fm embed in cached oEmbed markup, never passing a show.fm iframe or
	 * script through. A recognised episode or latest-episode iframe becomes the local Player
	 * block, which applies the site's credit and load settings and the bundled scripts. Any
	 * other show.fm embed becomes a plain link to the pasted URL. Markup from other sites is
	 * left alone. Enqueues only when a player is actually output, never on registration.
	 *
	 * @param string $html Cached embed HTML.
	 * @param string $url Original URL.
	 */
	public static function output( string $html, string $url ): string {
		$found = self::show_fm_tag( $html );
		if ( null === $found ) {
			return $html;
		}
		$path  = $found['parts']['path'] ?? '';
		$attrs = array();
		if ( 'IFRAME' !== $found['tag'] ) {
			return self::link( $url, $found['title'] );
		}
		if ( preg_match( '~^/ep/([^/]+)/?\z~', $path, $match ) && Attributes::uuid( $match[1] ) ) {
			$attrs['episode'] = $match[1];
		} elseif ( preg_match( '~^/latest/([a-z0-9]+(?:-[a-z0-9]+)*)/?\z~', $path, $match ) ) {
			$attrs['podcast'] = $match[1];
		} else {
			return self::link( $url, $found['title'] );
		}
		$query = array();
		wp_parse_str( $found['parts']['query'] ?? '', $query );
		$attrs['size']     = $query['size'] ?? 'standard';
		$attrs['snapshot'] = array(
			'title'     => '' !== $found['title'] ? $found['title'] : __( 'Listen on show.fm', 'showfm' ),
			'listenUrl' => $url,
		);
		// Upgrade core's cached iframe to a local player so the site credit setting and
		// bundled-script policy also apply to oEmbeds, without another HTTP request.
		return Embed::render( 'player', $attrs );
	}

	/**
	 * The first tag in the markup whose `src` is on a show.fm embed host, or null.
	 *
	 * @param string $html Markup.
	 * @return array{tag:string,parts:array<string,mixed>,title:string}|null
	 */
	private static function show_fm_tag( string $html ): ?array {
		$tags = new \WP_HTML_Tag_Processor( $html );
		while ( $tags->next_tag() ) {
			$src   = $tags->get_attribute( 'src' );
			$parts = is_string( $src ) ? wp_parse_url( trim( $src ) ) : false;
			if ( is_array( $parts ) && in_array( strtolower( (string) ( $parts['host'] ?? '' ) ), self::CDN_HOSTS, true ) ) {
				$title = $tags->get_attribute( 'title' );
				return array(
					'tag'   => (string) $tags->get_tag(),
					'parts' => $parts,
					'title' => is_string( $title ) ? trim( $title ) : '',
				);
			}
		}
		return null;
	}

	/**
	 * A show.fm embed the plugin can't play locally: a plain link to the pasted URL, or
	 * nothing when that URL isn't http or https.
	 *
	 * @param string $url   Original URL.
	 * @param string $title The embed's title, if any.
	 */
	private static function link( string $url, string $title ): string {
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		if ( ! in_array( is_string( $scheme ) ? strtolower( $scheme ) : '', array( 'http', 'https' ), true ) ) {
			return '';
		}
		$text = '' !== $title ? $title : __( 'Listen on show.fm', 'showfm' );
		return '<p class="showfm-oembed-link"><a href="' . esc_url( $url ) . '">' . esc_html( $text ) . '</a></p>';
	}
}
