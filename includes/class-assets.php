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
	const VERSION = '1.6.2';
	/** Shared script handle. */
	const HANDLE = 'showfm-embed';
	/**
	 * The package's self-hosting click loader, used instead of v1.js in load-on-click mode.
	 * It names no host: it loads only the v1.js the plugin gives it, and does nothing without.
	 */
	const CLICK_HANDLE = 'showfm-embed-click-loader';

	/** Register in the front end and enqueue_block_assets editor iframe lifecycle. */
	public static function register(): void {
		wp_register_script( self::HANDLE, self::script_url(), array(), self::VERSION, true );
		if ( ! wp_script_is( self::CLICK_HANDLE, 'registered' ) ) {
			wp_register_script( self::CLICK_HANDLE, plugins_url( 'assets/showfm-embed/click-loader-local.js', SHOWFM_FILE ), array(), self::VERSION, true );
			// The loader's first source. Script optimisers that combine or delay scripts can
			// drop data-src, or run the loader outside its own tag, but they keep inline
			// scripts and run them in order.
			wp_add_inline_script( self::CLICK_HANDLE, 'window.showfmEmbedSrc = ' . wp_json_encode( self::versioned_script_url() ) . ';', 'before' );
		}
		wp_register_style( self::HANDLE, plugins_url( 'assets/blocks.css', SHOWFM_FILE ), array(), SHOWFM_VERSION );
	}

	/** The bundled v1.js. */
	private static function script_url(): string {
		return plugins_url( 'assets/showfm-embed/v1.js', SHOWFM_FILE );
	}

	/** The bundled v1.js, with the version WordPress would add. */
	public static function versioned_script_url(): string {
		return add_query_arg( 'ver', self::VERSION, self::script_url() );
	}

	/**
	 * Enqueue once on output, including output generated after wp_head.
	 *
	 * In load-on-click mode the front end gets the package's self-hosting click loader instead
	 * of v1.js.
	 * It draws each element's "Play" or "Load" button and adds the bundled v1.js only when a
	 * visitor presses one, so nothing loads before the click. The editor always previews
	 * the real elements.
	 */
	public static function enqueue(): void {
		self::register();
		wp_enqueue_script( Embed_Settings::enabled( Embed_Settings::LOAD_ON_CLICK ) && ! is_admin() ? self::CLICK_HANDLE : self::HANDLE );
		if ( Embed_Settings::enabled( Embed_Settings::THEME_STYLES ) && ! wp_style_is( self::HANDLE, 'enqueued' ) && ! wp_style_is( self::HANDLE, 'done' ) ) {
			wp_add_inline_style( self::HANDLE, Theme::global_css() );
		}
		wp_enqueue_style( self::HANDLE );
	}

	/**
	 * Gives the click loader's own tag `data-src`, the loader's second source for the bundled
	 * v1.js after `window.showfmEmbedSrc`. The tag string also holds the inline script that
	 * sets the global, so only the tag with the loader's id gets it.
	 *
	 * @param string $tag    The script tags printed for the handle.
	 * @param string $handle Its handle.
	 */
	public static function loader_tag( string $tag, string $handle ): string {
		if ( self::CLICK_HANDLE !== $handle ) {
			return $tag;
		}
		$processor = new \WP_HTML_Tag_Processor( $tag );
		while ( $processor->next_tag( array( 'tag_name' => 'script' ) ) ) {
			if ( self::CLICK_HANDLE . '-js' === $processor->get_attribute( 'id' ) ) {
				$processor->set_attribute( 'data-src', self::versioned_script_url() );
				return $processor->get_updated_html();
			}
		}
		return $tag;
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
