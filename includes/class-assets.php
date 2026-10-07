<?php
/**
 * Locally bundled classic-script assets.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Registers assets early, but only enqueues them for rendered content. */
final class Assets {
	/** Exact npm package version. */
	const VERSION = '1.4.0';
	/** Shared script handle. */
	const HANDLE = 'showfm-embed';

	/** Register in the front end and enqueue_block_assets editor iframe lifecycle. */
	public static function register(): void {
		wp_register_script( self::HANDLE, plugins_url( 'assets/showfm-embed/v1.js', SHOWFM_FILE ), array(), self::VERSION, true );
		wp_register_style( self::HANDLE, plugins_url( 'assets/blocks.css', SHOWFM_FILE ), array(), SHOWFM_VERSION );
	}

	/** Enqueue once on output, including output generated after wp_head. */
	public static function enqueue(): void {
		self::register();
		wp_enqueue_script( self::HANDLE );
		if ( ! wp_style_is( self::HANDLE, 'enqueued' ) && ! wp_style_is( self::HANDLE, 'done' ) ) {
			wp_add_inline_style( self::HANDLE, Theme::global_css() );
		}
		wp_enqueue_style( self::HANDLE );
	}

	/** Print styles enqueued by shortcodes after the theme printed its head. */
	public static function late_styles(): void {
		if ( wp_style_is( self::HANDLE, 'enqueued' ) && ! wp_style_is( self::HANDLE, 'done' ) ) {
			wp_print_styles( array( self::HANDLE ) );
		}
	}

	/**
	 * Register for the iframe. In the editor the elements are always loaded, so a block
	 * inserted into a new post previews the real element. The front end loads them only
	 * from render callbacks.
	 */
	public static function editor_assets(): void {
		self::register();
		if ( is_admin() ) {
			self::enqueue();
		}
	}
}
