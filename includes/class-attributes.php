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
			'player'     => array( 'episode', 'podcast', 'size', 'wave', 'heading-level', 'transcript', 'mini-player', 'strings' ),
			'episodes'   => array( 'podcast', 'variant', 'layout', 'count', 'season', 'hide', 'descriptions', 'mini-player', 'heading-level' ),
			'play'       => array( 'episode', 'variant', 'size', 'mini-player' ),
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
		return is_string( $value ) && wp_is_uuid( $value ) ? strtolower( $value ) : '';
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
			'theme'         => array( 'auto', 'light', 'dark' ),
			'wave'          => array( 'true', 'false' ),
			'heading-level' => array( '2', '3', '4', '5', '6' ),
			'credit'        => array( 'auto', 'on', 'off' ),
			'load'          => array( 'click' ),
			'variant'       => 'episodes' === $type ? array( 'card', 'minimal' ) : array( 'icon', 'label', 'link' ),
			'layout'        => array( 'list', 'grid', 'auto', 'compact' ),
			'descriptions'  => array( 'on', 'off' ),
			'mini-player'   => array( 'on', 'off' ),
			'transcript'    => array( 'on', 'off', 'open' ),
			'size'          => 'play' === $type ? array( 'small', 'medium', 'large', 'standard', 'compact' ) : array( 'standard', 'compact' ),
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
			} elseif ( 'podcast' === $name && ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value ) ) {
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
				$value = sanitize_hex_color( $value ) ?? '';
			} elseif ( 'api' === $name ) {
				$value = Api_Client::sanitize_base_url( $value );
			} elseif ( in_array( $name, array( 'count', 'season', 'height' ), true ) ) {
				if ( ! ctype_digit( $value ) || (int) $value < ( 'season' === $name ? 0 : 1 ) ) {
					continue;
				}
				$value = (string) min( (int) $value, 'count' === $name ? 50 : 9999 );
			} elseif ( 'hide' === $name ) {
				$value = implode( ',', array_intersect( array( 'trailer', 'bonus' ), explode( ',', $value ) ) );
			} elseif ( in_array( $name, array( 'for', 'lang' ), true ) && ! preg_match( '/^[a-zA-Z0-9_-]+$/', $value ) ) {
				continue;
			}
			if ( '' !== $value ) {
				$output[ $name ] = $value;
			}
		}
		$output['credit'] = get_option( 'showfm_show_credit', false ) ? ( $output['credit'] ?? 'on' ) : 'off';
		// A per-embed origin would make browser and server cache disagree. Staging is site-wide.
		$output['api'] = Api_Client::base_url();
		return $output;
	}
}
