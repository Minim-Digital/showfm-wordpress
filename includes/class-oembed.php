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

	/** Code points browsers ignore in hostnames: UTS 46 "ignored", bidi and format controls. */
	const IGNORED = '/[\x{00AD}\x{034F}\x{180B}-\x{180F}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{206F}\x{FE00}-\x{FE0F}\x{FEFF}\x{E0000}-\x{E0FFF}]/u';

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
			'title'     => self::title( $found['title'] ),
			'listenUrl' => $url,
		);
		// Upgrade core's cached iframe to a local player so the site credit setting and
		// bundled-script policy also apply to oEmbeds, without another HTTP request.
		return Embed::render( 'player', $attrs );
	}

	/**
	 * Whether the markup names a show.fm embed host once normalised (see normalise()), in any
	 * attribute or text. Fails closed: if normalising or matching hits an error, the markup
	 * counts as show.fm, so it is replaced and never passed through.
	 *
	 * @param string $html  Markup.
	 * @param bool   $intl  False skips compatibility normalisation, as on a host without the
	 *                      intl extension. For tests.
	 */
	public static function names_show_fm( string $html, bool $intl = true ): bool {
		$normalised = self::normalise( $html, $intl );
		return null === $normalised || 0 !== preg_match( self::CDN_PATTERN, $normalised );
	}

	/**
	 * A copy of the markup with the disguises undone, for matching only. In order:
	 *
	 * 1. HTML entities and percent-encoding, decoded until nothing changes (at most 8 passes);
	 * 2. invalid UTF-8 bytes replaced with U+FFFD, as browsers decode them;
	 * 3. Unicode compatibility normalisation (NFKC) when the intl extension is available,
	 *    which folds fullwidth, circled, superscript and mathematical letters and the small
	 *    and fullwidth full stops;
	 * 4. fullwidth ASCII (U+FF01 to U+FF5E) folded to ASCII, with or without intl;
	 * 5. the ideographic, fullwidth and halfwidth full stops (U+3002, U+FF0E, U+FF61) as ".";
	 * 6. the code points browsers ignore in hostnames deleted: UTS 46 "ignored" (soft hyphen,
	 *    combining grapheme joiner, Mongolian variation selectors, variation selectors, zero
	 *    width space and joiners, BOM, tags) and the bidi and format controls. Every other
	 *    non-ASCII character becomes "x", so it breaks a label instead of joining two:
	 *    embéed.cdn.media and embed.cdn.mediaé are look-alikes, never matches;
	 * 7. "\" as "/", then lower case;
	 * 8. ASCII whitespace and control characters removed.
	 *
	 * Browsers' IDNA processing maps more than this, but every host that is ASCII after it
	 * is caught. Without intl, steps 4 to 6 still catch fullwidth letters and the invisible
	 * characters.
	 *
	 * @param string $text Markup.
	 * @param bool   $intl Whether to use the intl extension when it is available.
	 * @return string|null Null when a step fails, so the caller fails closed.
	 */
	private static function normalise( string $text, bool $intl = true ): ?string {
		for ( $i = 0; $i < 8; $i++ ) {
			$decoded = rawurldecode( html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			if ( $decoded === $text ) {
				break;
			}
			$text = $decoded;
		}
		$text = self::scrub_utf8( $text );
		if ( $intl && class_exists( '\Normalizer' ) ) {
			$compatible = \Normalizer::normalize( $text, \Normalizer::FORM_KC );
			if ( ! is_string( $compatible ) ) {
				return null;
			}
			$text = $compatible;
		}
		$text = self::fold_fullwidth( $text );
		if ( null === $text ) {
			return null;
		}
		$text = str_replace( array( "\u{3002}", "\u{FF0E}", "\u{FF61}" ), '.', $text );
		$text = preg_replace( self::IGNORED, '', $text );
		$text = null === $text ? null : preg_replace( '/[^\x00-\x7F]/u', 'x', $text );
		if ( null === $text ) {
			return null;
		}
		$text = strtolower( str_replace( '\\', '/', $text ) );
		return preg_replace( '/[\x00-\x20\x7F]+/', '', $text );
	}

	/**
	 * Replaces invalid UTF-8 bytes with U+FFFD, as browsers decode them, so a bad byte breaks
	 * a label (step 6 turns it into "x") rather than joining two. Without mbstring the text
	 * is unchanged, and the Unicode steps then fail closed on it.
	 *
	 * @param string $text Text.
	 */
	private static function scrub_utf8( string $text ): string {
		if ( ! function_exists( 'mb_scrub' ) ) {
			return $text;
		}
		$previous = mb_substitute_character();
		mb_substitute_character( 0xFFFD );
		try {
			return mb_scrub( $text, 'UTF-8' );
		} finally {
			mb_substitute_character( $previous );
		}
	}

	/**
	 * Folds fullwidth ASCII (U+FF01 to U+FF5E) to ASCII (U+0021 to U+007E). Needs neither
	 * intl nor mbstring.
	 *
	 * @param string $text Text.
	 * @return string|null Null when the text isn't valid UTF-8.
	 */
	public static function fold_fullwidth( string $text ): ?string {
		$folded = preg_replace_callback(
			'/[\x{FF01}-\x{FF5E}]/u',
			static function ( array $found ): string {
				// Each is three UTF-8 bytes: EF BC 81 to EF BD 9E.
				$bytes = array_values( (array) unpack( 'C*', $found[0] ) );
				$code  = ( ( $bytes[0] & 0x0F ) << 12 ) | ( ( $bytes[1] & 0x3F ) << 6 ) | ( $bytes[2] & 0x3F );
				return chr( $code - 0xFEE0 );
			},
			$text
		);
		return is_string( $folded ) ? $folded : null;
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
			$clean = is_string( $src ) ? self::normalise( $src ) : null;
			$parts = null !== $clean ? wp_parse_url( $clean ) : false;
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
		return '<p class="showfm-oembed-link"><a href="' . esc_url( $url ) . '">' . esc_html( self::title( $title ) ) . '</a></p>';
	}

	/**
	 * The embed's title, or "Listen on show.fm" when it would print as nothing (empty, or
	 * not valid UTF-8, which escaping drops).
	 *
	 * @param string $title Title.
	 */
	private static function title( string $title ): string {
		return '' !== trim( esc_html( $title ) ) ? $title : __( 'Listen on show.fm', 'showfm' );
	}
}
