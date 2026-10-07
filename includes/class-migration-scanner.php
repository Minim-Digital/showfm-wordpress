<?php
/**
 * Keyset scanner of published posts and pages.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** One batch holds at most fifty posts. Deleted or unpublished rows do not shift offsets. */
final class Migration_Scanner {
	const BATCH_SIZE = 50;

	/**
	 * Last eligible ID at scan start, excluding posts published later.
	 *
	 * @param int $post_id Optional single post.
	 */
	public static function upper_bound( int $post_id = 0 ): int {
		global $wpdb;
		if ( $post_id > 0 ) {
			return $post_id;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Bounded keyset scan, no cacheable query result.
		return (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('post','page')" );
	}

	/**
	 * Eligible IDs after the last completed post.
	 *
	 * @param int $after   Last processed ID.
	 * @param int $upper   Initial upper bound.
	 * @param int $post_id Single-post restriction, or zero.
	 * @return int[]
	 */
	public static function ids( int $after, int $upper, int $post_id = 0 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Keyset pagination avoids a growing OFFSET or full post cache.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND ID <= %d AND (%d = 0 OR ID = %d) AND post_status = 'publish' AND post_type IN ('post','page') ORDER BY ID LIMIT %d", $after, $upper, $post_id, $post_id, self::BATCH_SIZE ) );
		return array_map( 'intval', $ids );
	}

	/**
	 * Detect and match one post. The caller checks the connection before providing episodes.
	 *
	 * @param \WP_Post $post     Source.
	 * @param callable $episodes Lazy catalogue factory.
	 * @phpstan-param callable(): iterable<array<string,mixed>> $episodes
	 * @return array<string,mixed>
	 */
	public static function scan( \WP_Post $post, callable $episodes ): array {
		$report = array(
			'post_id'     => $post->ID,
			'hash'        => self::hash( $post ),
			'items'       => array(),
			'revision_id' => 0,
		);
		if ( self::already( $post->post_content ) ) {
			$report['status'] = 'already_showfm';
			return $report;
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			$report['status'] = 'forbidden';
			return $report;
		}
		foreach ( Migration_Detectors::detect( $post ) as $embed ) {
			$match             = Migration_Matcher::match( $embed, empty( $embed['show_only'] ) ? $episodes() : array() );
			$report['items'][] = array_merge( $embed, $match );
		}
		$report['status'] = 'scanned';
		return $report;
	}

	/**
	 * Detect any show.fm block, including nested ones, without rendering.
	 *
	 * @param string $content Content.
	 */
	public static function already( string $content ): bool {
		return 1 === preg_match( '~<!--\s+wp:showfm/[a-z-]+(?:\s|/)~', $content );
	}

	/**
	 * Reject stale reports when either content or player metadata changed.
	 *
	 * @param \WP_Post $post Source.
	 */
	public static function hash( \WP_Post $post ): string {
		return hash( 'sha256', (string) wp_json_encode( array( $post->post_content, $post->post_title, $post->post_date_gmt, get_post_meta( $post->ID, 'enclosure', false ), get_post_meta( $post->ID, 'audio_file', false ) ) ) );
	}
}
