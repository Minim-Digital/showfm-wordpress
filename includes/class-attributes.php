<?php
/**
 * Element attribute allowlists shared by blocks and shortcodes.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Validates attributes before they reach markup or a cache key. */
final class Attributes {
	/**
	 * Attributes from the package manifest and the approved element contracts.
	 *
	 * @param string $type Element type.
	 * @return string[]
	 */
	public static function names( string $type ): array {
		$common = array( 'theme', 'accent', 'api', 'credit', 'load', 'lang' );
		$types  = array(
			'player'     => array( 'id', 'episode', 'podcast', 'size', 'wave', 'heading-level', 'transcript', 'mini-player', 'mini-player-position', 'strings' ),
			'episodes'   => array( 'id', 'podcast', 'variant', 'layout', 'count', 'season', 'hide', 'descriptions', 'mini-player', 'mini-player-position', 'heading-level' ),
			'play'       => array( 'episode', 'podcast', 'variant', 'size', 'mini-player', 'mini-player-position' ),
			'transcript' => array( 'episode', 'for', 'height' ),
		);
		return isset( $types[ $type ] ) ? array_merge( $common, $types[ $type ] ) : array();
	}

	/**
	 * Valid UUID or empty string.
	 *
	 * @param mixed $value Candidate UUID.
	 */
	public static function uuid( $value ): string {
		return is_string( $value ) && 36 === strlen( $value ) && wp_is_uuid( $value ) ? strtolower( $value ) : '';
	}

	/** A safe element id: the showfm- prefix, then letters, digits, hyphens or underscores. */
	const ELEMENT_ID = '/\Ashowfm-[a-zA-Z0-9_-]{1,57}\z/';

	/** Longest snapshot title kept, in characters. */
	const MAX_TITLE = 300;

	/**
	 * Whether `SHOWFM_API_URL` selects staging, which adds the staging hosts below.
	 */
	private static function staging(): bool {
		return 'https://api.showfm.dev' === Api_Client::base_url();
	}

	/**
	 * Hosts whose subdomains serve show.fm listen pages.
	 *
	 * @return string[]
	 */
	public static function listen_roots(): array {
		return self::staging() ? array( 'show.fm', 'showfm.dev' ) : array( 'show.fm' );
	}

	/**
	 * Media hosts (show.fm's) a snapshot's audio URL may use.
	 *
	 * @return string[]
	 */
	public static function audio_hosts(): array {
		$hosts = array( 'm.cdn.media', 'media.podcasterplus.com' );
		return self::staging() ? array_merge( $hosts, array( 'm.showfm.dev', 'media.podcasterplus.dev' ) ) : $hosts;
	}

	/**
	 * A show's listen page, as the API builds it from the slug, or null.
	 *
	 * @param string $slug Show slug.
	 */
	public static function show_listen_url( string $slug ): ?string {
		if ( ! preg_match( '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug ) || strlen( $slug ) > 63 ) {
			return null;
		}
		return 'https://' . $slug . '.' . ( self::staging() ? 'showfm.dev' : 'show.fm' );
	}

	/**
	 * Keep only what a snapshot may hold, at both the save and render boundaries: a title
	 * (a string, at most 300 characters), an https listen URL on a show.fm listen host and an
	 * https audio URL on a show.fm media host. Other keys (such as the migrator's) are kept.
	 *
	 * @param array<string,mixed> $snapshot Insertion snapshot.
	 * @return array<string,mixed>
	 */
	public static function snapshot( array $snapshot ): array {
		$hosts = array(
			'listenUrl' => static function ( string $host ): bool {
				foreach ( self::listen_roots() as $root ) {
					if ( $host === $root || substr( $host, -strlen( '.' . $root ) ) === '.' . $root ) {
						return true;
					}
				}
				return false;
			},
			'audioUrl'  => static function ( string $host ): bool {
				return in_array( $host, self::audio_hosts(), true );
			},
		);
		foreach ( $hosts as $key => $allowed ) {
			$url  = $snapshot[ $key ] ?? null;
			$host = is_string( $url ) && preg_match( '~\Ahttps://[^\s]+\z~i', $url ) ? wp_parse_url( $url, PHP_URL_HOST ) : null;
			if ( ! is_string( $host ) || '' === $host || null !== wp_parse_url( $url, PHP_URL_USER ) || null !== wp_parse_url( $url, PHP_URL_PORT ) || ! $allowed( strtolower( $host ) ) ) {
				unset( $snapshot[ $key ] );
			}
		}
		if ( array_key_exists( 'title', $snapshot ) ) {
			if ( ! is_string( $snapshot['title'] ) ) {
				unset( $snapshot['title'] );
			} else {
				$snapshot['title'] = self::cap_title( $snapshot['title'] );
			}
		}
		return $snapshot;
	}

	/**
	 * Cap a title at MAX_TITLE characters. mbstring is optional in WordPress, so this
	 * falls back to a UTF-8 aware preg_match when it is missing.
	 *
	 * @param string    $title    Title.
	 * @param bool|null $mbstring Whether to use mbstring; null detects it. Tests pass false.
	 * @return string At most MAX_TITLE characters.
	 */
	public static function cap_title( string $title, ?bool $mbstring = null ): string {
		return Text::cut( $title, self::MAX_TITLE, $mbstring );
	}

	/**
	 * Drop unknown/invalid attributes. Blocks persist UUIDs; shortcodes also accept slugs.
	 * Credit is governed by the site owner's opt-in, not by post authors.
	 *
	 * @param string              $type Element type.
	 * @param array<string,mixed> $input Untrusted attributes.
	 * @param bool                $block Whether identifiers must be UUIDs.
	 * @return array<string,string>
	 */
	public static function clean( string $type, array $input, bool $block = false ): array {
		$enums  = array(
			'theme'                => array( 'auto', 'light', 'dark' ),
			'wave'                 => array( 'true', 'false' ),
			'heading-level'        => array( '2', '3', '4', '5', '6' ),
			'credit'               => array( 'auto', 'on', 'off' ),
			'load'                 => array( 'click' ),
			'variant'              => 'episodes' === $type ? array( 'card', 'minimal' ) : array( 'icon', 'label', 'link' ),
			'layout'               => array( 'list', 'grid', 'auto', 'compact' ),
			'descriptions'         => array( 'on', 'off' ),
			'mini-player'          => array( 'on', 'off' ),
			'mini-player-position' => array( 'left', 'right' ),
			'transcript'           => array( 'on', 'off', 'open' ),
			'size'                 => 'play' === $type ? array( 'sm', 'lg' ) : array( 'standard', 'compact' ),
		);
		$output = array();
		foreach ( self::names( $type ) as $name ) {
			if ( ! isset( $input[ $name ] ) || ! is_scalar( $input[ $name ] ) ) {
				continue;
			}
			$value = (string) $input[ $name ];
			if ( '' === $value || ( isset( $enums[ $name ] ) && ! in_array( $value, $enums[ $name ], true ) ) ) {
				continue;
			}
			if ( 'episode' === $name || ( 'podcast' === $name && $block ) ) {
				$value = self::uuid( $value );
			} elseif ( 'podcast' === $name && ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*\z/', $value ) ) {
				continue;
			} elseif ( 'strings' === $name ) {
				$strings = json_decode( $value );
				if ( ! is_object( $strings ) || array_filter(
					(array) $strings,
					static function ( $text ) {
						return ! is_string( $text ); }
				) ) {
					continue;
				}
				$value = (string) wp_json_encode( $strings );
			} elseif ( 'accent' === $name ) {
				$value = preg_match( '/\A#(?:[a-f0-9]{3}|[a-f0-9]{6})\z/i', $value ) ? $value : '';
			} elseif ( 'api' === $name ) {
				$value = Api_Client::sanitize_base_url( $value );
			} elseif ( in_array( $name, array( 'count', 'season', 'height' ), true ) ) {
				if ( ! ctype_digit( $value ) || (int) $value < ( 'season' === $name ? 0 : 1 ) ) {
					continue;
				}
				$value = (string) min( (int) $value, 'count' === $name ? 50 : 9999 );
			} elseif ( 'hide' === $name ) {
				$value = implode( ',', array_intersect( array( 'trailer', 'bonus' ), explode( ',', $value ) ) );
			} elseif ( 'lang' === $name && ! preg_match( '/^[a-zA-Z0-9_-]+\z/', $value ) ) {
				continue;
			} elseif ( in_array( $name, array( 'id', 'for' ), true ) && ! preg_match( self::ELEMENT_ID, $value ) ) {
				// Element ids carry the showfm- prefix, so they cannot clobber page ids.
				continue;
			}
			if ( '' !== $value ) {
				$output[ $name ] = $value;
			}
		}
		$output['credit'] = Embed_Settings::enabled( Embed_Settings::CREDIT ) ? ( $output['credit'] ?? 'on' ) : 'off';
		// The show.fm WordPress plugin: with it, credit="off" hides "Powered by show.fm" for
		// any show, because WordPress.org needs a credit in plugin code to be opt-in.
		$output['platform'] = 'wordpress'; // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- The package's attribute value is lowercase.
		// Consent mode is the site owner's choice: it applies to every embed when on.
		if ( Embed_Settings::enabled( Embed_Settings::LOAD_ON_CLICK ) ) {
			$output['load'] = 'click';
		}
		// A per-embed origin would make browser and server cache disagree. Staging is site-wide.
		$output['api'] = Api_Client::base_url();
		return $output;
	}
}
