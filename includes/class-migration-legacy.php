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
	 * @param mixed $show Whether SSP would show a player.
	 * @param mixed $post Content post.
	 * @return mixed
	 */
	public static function ssp( $show, $post ) {
		try {
			return $post instanceof \WP_Post && self::replaced( $post, 'ssp', 'audio_file' ) ? false : $show;
		} catch ( \RuntimeException $error ) {
			return $show;
		}
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
		try {
			if ( is_array( $settings ) && doing_filter( 'the_content' ) && ! is_feed() && $post instanceof \WP_Post && self::replaced( $post, 'powerpress', 'enclosure' ) ) {
				$settings['disable_appearance'] = 1;
			}
		} catch ( \RuntimeException $error ) {
			return $settings;
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
		$urls = self::coverage( $post, $host );
		$meta = get_post_meta( $post->ID, $key, false );
		if ( ! $meta ) {
			return false;
		}
		foreach ( $meta as $value ) {
			$url = is_string( $value ) ? Migration_Url::fingerprint( Migration_Url::normalise( (string) strtok( $value, "\r\n" ) ) ) : null;
			if ( null === $url || ! in_array( $url, $urls, true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Parsed content for this request.
	 *
	 * @var array<string,array<string,string[]>>
	 */
	private static $parsed = array();

	/**
	 * Parse each post/content version once, shared by detection and both filters.
	 *
	 * @param \WP_Post $post Post.
	 * @param string   $host Provider.
	 * @return string[]
	 */
	public static function coverage( \WP_Post $post, string $host ): array {
		if ( ! Migration_Scanner::already( $post->post_content ) ) {
			return array();
		}
		$key = get_current_blog_id() . ':' . $post->ID . ':' . hash( 'sha256', $post->post_content );
		if ( ! isset( self::$parsed[ $key ] ) ) {
			if ( count( self::$parsed ) >= Migration_Scanner::BATCH_SIZE ) {
				array_shift( self::$parsed );
			}
			$blocks               = parse_blocks( $post->post_content );
			self::$parsed[ $key ] = array(
				'powerpress' => self::markers( $blocks, 'powerpress' ),
				'ssp'        => self::markers( $blocks, 'ssp' ),
			);
		}
		return self::$parsed[ $key ][ $host ] ?? array();
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
			if ( 'showfm/player' === $block['blockName'] && ( $snapshot['legacyHost'] ?? '' ) === $host && is_string( $snapshot['legacyAudioSha256'] ?? null ) ) {
				$urls[] = $snapshot['legacyAudioSha256'];
			}
			$urls = array_merge( $urls, self::markers( $block['innerBlocks'] ?? array(), $host ) );
		}
		return $urls;
	}
}
