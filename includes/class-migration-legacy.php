<?php
/**
 * Keep feed metadata while suppressing replaced automatic legacy players.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Revisions undo this behaviour too: no migration marker means no suppression. */
final class Migration_Legacy {
	/** Register local-only filters, without loading either legacy plugin. */
	public static function register(): void {
		add_filter( 'ssp_show_media_player_in_content', array( self::class, 'ssp' ), 10, 2 );
		add_filter( 'option_powerpress_general', array( self::class, 'powerpress' ) );
	}

	/**
	 * Suppress SSP's automatic content player only for its migrated audio file.
	 *
	 * @param bool     $show Whether SSP would show a player.
	 * @param \WP_Post $post Content post.
	 */
	public static function ssp( bool $show, \WP_Post $post ): bool {
		return $show && ! self::replaced( $post, 'ssp', 'audio_file' );
	}

	/**
	 * Disable PowerPress's automatic content insertion for migrated enclosures only.
	 * Shortcodes, feeds, settings and unrelated posts keep their normal behaviour.
	 *
	 * @param mixed $settings PowerPress settings, or false if not installed.
	 * @return mixed
	 */
	public static function powerpress( $settings ) {
		global $post;
		if ( is_array( $settings ) && doing_filter( 'the_content' ) && ! is_feed() && $post instanceof \WP_Post && self::replaced( $post, 'powerpress', 'enclosure' ) ) {
			$settings['disable_appearance'] = 1;
		}
		return $settings;
	}

	/**
	 * All current metadata URLs must be covered. An unmatched player is never suppressed.
	 *
	 * @param \WP_Post $post Content post.
	 * @param string   $host Legacy provider.
	 * @param string   $key  Metadata key.
	 */
	private static function replaced( \WP_Post $post, string $host, string $key ): bool {
		if ( ! Migration_Scanner::already( $post->post_content ) ) {
			return false;
		}
		$urls = self::markers( parse_blocks( $post->post_content ), $host );
		$meta = get_post_meta( $post->ID, $key, false );
		if ( ! $meta ) {
			return false;
		}
		foreach ( $meta as $value ) {
			$url = is_string( $value ) ? Migration_Url::normalise( (string) strtok( $value, "\r\n" ) ) : '';
			if ( '' === $url || ! in_array( $url, $urls, true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Read only migration snapshots, including players nested in core layout blocks.
	 *
	 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
	 * @param string                         $host   Provider.
	 * @return string[]
	 */
	private static function markers( array $blocks, string $host ): array {
		$urls = array();
		foreach ( $blocks as $block ) {
			$snapshot = $block['attrs']['snapshot'] ?? array();
			if ( 'showfm/player' === $block['blockName'] && ( $snapshot['legacyHost'] ?? '' ) === $host && is_string( $snapshot['legacyAudio'] ?? null ) ) {
				$urls[] = $snapshot['legacyAudio'];
			}
			$urls = array_merge( $urls, self::markers( $block['innerBlocks'] ?? array(), $host ) );
		}
		return $urls;
	}
}
