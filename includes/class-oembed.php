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

	/** The show.fm script and iframe hosts. No markup naming them is ever passed through. */
	const CDN_HOSTS = array( 'embed.cdn.media', 'embed.showfm.dev' );

	/**
	 * Either host on label boundaries in normalised markup: a subdomain or trailing dots
	 * still match, `notembed.cdn.media` and `embed.cdn.media.evil.test` don't.
	 */
	const CDN_PATTERN = '~(?<![a-z0-9-])embed\.(?:cdn\.media|showfm\.dev)\.*+(?![a-z0-9-])~';

	/**
	 * Replaces any show.fm embed in cached oEmbed markup, never passing markup that names a
	 * show.fm embed host through, however it is spelled. A recognised episode or
	 * latest-episode iframe becomes the local Player block, which applies the site's credit
	 * and load settings and the bundled scripts. Any other show.fm markup becomes a plain
	 * link to the pasted URL. Markup from other sites is left alone. Enqueues only when a
	 * player is actually output, never on registration.
	 *
	 * @param string $html Cached embed HTML.
	 * @param string $url Original URL.
	 */
	public static function output( string $html, string $url ): string {
		if ( ! self::names_show_fm( $html ) ) {
			return $html;
		}
		$found = self::show_fm_iframe( $html );
		if ( null === $found ) {
			return self::link( $url, '' );
		}
		$path  = $found['parts']['path'] ?? '';
		$attrs = array();
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
	 * Whether the markup names a show.fm embed host in any spelling a browser would follow:
	 * entity-encoded, percent-encoded, upper case, with Unicode dots, whitespace or control
	 * characters inside it, backslashes for slashes, a trailing dot, in any attribute or text.
	 *
	 * @param string $html Markup.
	 */
	public static function names_show_fm( string $html ): bool {
		return 1 === preg_match( self::CDN_PATTERN, self::normalise( $html ) );
	}

	/**
	 * A copy of the markup with every disguise undone, for matching only.
	 *
	 * @param string $text Markup.
	 */
	private static function normalise( string $text ): string {
		// Entities and percent-encoding can nest, so decode until nothing changes, within a
		// bound.
		for ( $i = 0; $i < 8; $i++ ) {
			$decoded = rawurldecode( html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			if ( $decoded === $text ) {
				break;
			}
			$text = $decoded;
		}
		$text = str_replace( array( "\u{3002}", "\u{FF0E}", "\u{FF61}", '\\' ), array( '.', '.', '.', '/' ), $text );
		return (string) preg_replace( '/[\x00-\x20\x7F]+/', '', strtolower( $text ) );
	}

	/**
	 * The first iframe whose normalised `src` is on a show.fm embed host, or null.
	 *
	 * @param string $html Markup.
	 * @return array{parts:array<string,mixed>,title:string}|null
	 */
	private static function show_fm_iframe( string $html ): ?array {
		$tags = new \WP_HTML_Tag_Processor( $html );
		while ( $tags->next_tag( array( 'tag_name' => 'iframe' ) ) ) {
			$src   = $tags->get_attribute( 'src' );
			$parts = is_string( $src ) ? wp_parse_url( self::normalise( $src ) ) : false;
			if ( is_array( $parts ) && in_array( rtrim( (string) ( $parts['host'] ?? '' ), '.' ), self::CDN_HOSTS, true ) ) {
				$title = $tags->get_attribute( 'title' );
				return array(
					'parts' => $parts,
					'title' => is_string( $title ) ? trim( $title ) : '',
				);
			}
		}
		return null;
	}

	/**
	 * A show.fm embed the plugin can't play locally: a plain link to the pasted URL, or
	 * nothing when that URL isn't http or https, or is itself on a show.fm embed host.
	 *
	 * @param string $url   Original URL.
	 * @param string $title The embed's title, if any.
	 */
	private static function link( string $url, string $title ): string {
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		if ( ! in_array( is_string( $scheme ) ? strtolower( $scheme ) : '', array( 'http', 'https' ), true ) || self::names_show_fm( $url ) ) {
			return '';
		}
		$text = '' !== $title ? $title : __( 'Listen on show.fm', 'showfm' );
		return '<p class="showfm-oembed-link"><a href="' . esc_url( $url ) . '">' . esc_html( $text ) . '</a></p>';
	}
}
