<?php
/**
 * The [showfm] shortcode.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Shortcode entry point; unknown attributes never reach the renderer. */
final class Shortcode {
	/**
	 * Render a recognised element.
	 *
	 * @param mixed $attributes Shortcode attributes.
	 */
	public static function render( $attributes ): string {
		$attributes = is_array( $attributes ) ? $attributes : array();
		$type       = $attributes['type'] ?? 'player';
		if ( ! is_string( $type ) || ! Attributes::names( $type ) ) {
			return '';
		}
		return Embed::render( $type, array_intersect_key( $attributes, array_flip( Attributes::names( $type ) ) ) );
	}
}
