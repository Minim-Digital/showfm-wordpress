<?php
/**
 * Server block registration. Editor controls follow in WP-2b.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Four dynamic blocks using the same cache-only renderer. */
final class Blocks {
	/** Register metadata and the minimal editor script. */
	public static function register(): void {
		add_filter( 'content_save_pre', array( self::class, 'sanitize_content' ) );
		$asset = require SHOWFM_DIR . '/build/index.asset.php';
		wp_register_script( 'showfm-block-editor', plugins_url( 'build/index.js', SHOWFM_FILE ), $asset['dependencies'], $asset['version'], true );
		wp_set_script_translations( 'showfm-block-editor', 'showfm' );
		foreach ( array( 'player', 'episodes', 'play', 'transcript' ) as $type ) {
			register_block_type(
				SHOWFM_DIR . '/blocks/' . $type,
				array(
					'render_callback' => static function ( $attributes ) use ( $type ) {
						return Embed::render( $type, $attributes, true );
					},
				)
			);
		}
	}

	/**
	 * Sanitize snapshot URLs on save, including nested blocks and REST/classic saves.
	 * Leave content byte-identical when no snapshot needs changing.
	 *
	 * @param string $content Slashed post content from content_save_pre.
	 */
	public static function sanitize_content( string $content ): string {
		if ( false === strpos( $content, 'wp:showfm/' ) ) {
			return $content;
		}
		$blocks  = parse_blocks( wp_unslash( $content ) );
		$changed = false;
		$blocks  = self::sanitize_snapshots( $blocks, $changed );
		return $changed ? wp_slash( serialize_blocks( $blocks ) ) : $content;
	}

	/**
	 * Apply the same URL policy to every authored snapshot, including inner blocks.
	 *
	 * @param array<array<string,mixed>> $blocks Parsed blocks.
	 * @param bool                       $changed Whether any snapshot was changed.
	 * @return array<array<string,mixed>>
	 */
	private static function sanitize_snapshots( array $blocks, bool &$changed ): array {
		foreach ( $blocks as &$block ) {
			if ( in_array( $block['blockName'], array( 'showfm/player', 'showfm/episodes', 'showfm/play', 'showfm/transcript' ), true ) && is_array( $block['attrs']['snapshot'] ?? null ) ) {
				$snapshot = Attributes::snapshot( $block['attrs']['snapshot'] );
				if ( $snapshot !== $block['attrs']['snapshot'] ) {
					$block['attrs']['snapshot'] = $snapshot;
					$changed                    = true;
				}
			}
			$block['innerBlocks'] = self::sanitize_snapshots( $block['innerBlocks'], $changed );
		}
		return $blocks;
	}
}
