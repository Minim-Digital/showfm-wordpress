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
}
