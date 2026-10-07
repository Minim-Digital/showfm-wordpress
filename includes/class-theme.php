<?php
/**
 * Theme and block style mapping.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Maps theme.json and block supports onto the element's CSS custom properties. */
final class Theme {
	/** Theme defaults have low specificity; element inline overrides win. */
	public static function global_css(): string {
		$settings = wp_get_global_settings();
		$styles   = wp_get_global_styles();
		$values   = array();
		foreach ( array( 'default', 'theme', 'custom' ) as $origin ) {
			foreach ( $settings['color']['palette'][ $origin ] ?? array() as $colour ) {
				if ( 'primary' === ( $colour['slug'] ?? '' ) ) {
					$values['--showfm-accent'] = $colour['color'];
				}
			}
		}
		$font = $styles['typography']['fontFamily'] ?? null;
		if ( $font ) {
			$values['--showfm-font'] = self::preset( $font );
		} else {
			foreach ( array( 'default', 'theme', 'custom' ) as $origin ) {
				foreach ( $settings['typography']['fontFamilies'][ $origin ] ?? array() as $family ) {
					if ( 'body' === ( $family['slug'] ?? '' ) ) {
						$values['--showfm-font'] = $family['fontFamily'];
					}
				}
			}
		}
		return ':where(showfm-player,showfm-episodes,showfm-play,showfm-transcript){' . self::declarations( $values ) . '}';
	}

	/**
	 * Convert WordPress preset notation to a CSS variable.
	 *
	 * @param string $value CSS or preset token.
	 */
	private static function preset( string $value ): string {
		if ( preg_match( '/^var:preset\|([a-zA-Z-]+)\|([a-zA-Z0-9-]+)\z/', $value, $match ) ) {
			return 'var(--wp--preset--' . $match[1] . '--' . $match[2] . ')';
		}
		return $value;
	}

	/**
	 * Limit custom-property values to inert CSS tokens; never URLs or declarations.
	 *
	 * @param array<string,mixed> $values Custom properties.
	 */
	private static function declarations( array $values ): string {
		$css = '';
		foreach ( $values as $property => $value ) {
			if ( is_string( $value ) && ! preg_match( '/[;{}<>\\\\\x00-\x1f]|url\s*\(|expression\s*\(|\/\*/i', $value ) ) {
				$css .= $property . ':' . self::preset( $value ) . ';';
			}
		}
		return $css;
	}

	/**
	 * Explicit block supports override theme defaults on this element only.
	 *
	 * @param array<string,mixed> $attrs Block attributes.
	 */
	public static function block_style( array $attrs ): string {
		$style  = is_array( $attrs['style'] ?? null ) ? $attrs['style'] : array();
		$values = array();
		$maps   = array(
			'color'      => array(
				'text'       => '--showfm-text',
				'background' => '--showfm-background',
			),
			'typography' => array(
				'fontFamily' => '--showfm-font',
				'fontSize'   => '--showfm-font-size',
				'lineHeight' => '--showfm-line-height',
			),
		);
		foreach ( $maps as $group => $properties ) {
			foreach ( $properties as $key => $variable ) {
				$values[ $variable ] = $style[ $group ][ $key ] ?? null;
			}
		}
		foreach ( array(
			'textColor'       => array( '--showfm-text', 'color' ),
			'backgroundColor' => array( '--showfm-background', 'color' ),
			'fontFamily'      => array( '--showfm-font', 'font-family' ),
			'fontSize'        => array( '--showfm-font-size', 'font-size' ),
		) as $key => $mapping ) {
			if ( isset( $attrs[ $key ] ) && is_string( $attrs[ $key ] ) && preg_match( '/^[a-zA-Z0-9-]+\z/', $attrs[ $key ] ) ) {
				$values[ $mapping[0] ] = 'var(--wp--preset--' . $mapping[1] . '--' . $attrs[ $key ] . ')';
			}
		}
		if ( ! empty( $attrs['accent'] ) && is_string( $attrs['accent'] ) ) {
			$values['--showfm-accent'] = ( preg_match( '/\A#(?:[a-f0-9]{3}|[a-f0-9]{6})\z/i', $attrs['accent'] ) ? $attrs['accent'] : null );
		} elseif ( isset( $values['--showfm-text'] ) ) {
			$values['--showfm-accent'] = $values['--showfm-text'];
		}
		foreach ( array( 'padding', 'margin' ) as $property ) {
			$spacing = $style['spacing'][ $property ] ?? null;
			if ( is_array( $spacing ) ) {
				foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
					$values[ '--showfm-' . $property . '-' . $side ] = $spacing[ $side ] ?? null;
				}
			} else {
				$values[ '--showfm-' . $property ] = $spacing;
			}
		}
		return self::declarations( $values );
	}
}
