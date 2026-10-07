<?php
/**
 * Surgical content replacement with a WordPress revision as the undo.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Uses stored candidates only; unmatched items cannot be chosen. */
final class Migration_Swap {
	/**
	 * Apply a reviewed post report, returning the report with its revision link.
	 *
	 * @param Connection          $connection Connection.
	 * @param array<string,mixed> $report     Stored post report.
	 * @param array<int,string>   $choices    Embed number to candidate episode UUID.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function apply( Connection $connection, array $report, array $choices = array() ) {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_post', $report['post_id'] ) ) {
			return new \WP_Error( 'showfm_forbidden', __( 'Use a site administrator who can edit this post.', 'showfm' ) );
		}
		if ( ! $connection->is_connected() ) {
			return new \WP_Error( 'showfm_not_connected', __( 'Connect this site to show.fm before migrating embeds.', 'showfm' ) );
		}
		$post = Migration_Scanner::fresh( $report['post_id'] );
		if ( ! $post || 'publish' !== $post->post_status || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return new \WP_Error( 'showfm_post_unavailable', __( 'The published post or page is no longer available.', 'showfm' ) );
		}
		if ( ! $choices && isset( $report['applied_hash'] ) && hash_equals( $report['applied_hash'], Migration_Scanner::hash( $post ) ) ) {
			return $report;
		}
		if ( 'scanned' !== $report['status'] || ! isset( $report['evidence'] ) ) {
			return new \WP_Error( 'showfm_unsafe_report', __( 'This post has no safe, reviewed embed ranges. Start a new scan.', 'showfm' ) );
		}
		if ( ! self::unchanged( $post, $report ) ) {
			return new \WP_Error( 'showfm_stale_report', __( 'The post or source player changed after scanning. Start a new scan.', 'showfm' ) );
		}
		$occupied = array();
		foreach ( $report['items'] as $item ) {
			if ( $item['offset'] < 0 || $item['length'] < 0 || $item['offset'] + $item['length'] > strlen( $post->post_content ) || ! hash_equals( $item['range_hash'], hash( 'sha256', substr( $post->post_content, $item['offset'], $item['length'] ) ) ) || Migration_Tokens::overlap( $item, $occupied ) || ! empty( $item['range_ambiguous'] ) ) {
				return new \WP_Error( 'showfm_unsafe_ranges', __( 'The embed ranges overlap or changed. This post needs manual review.', 'showfm' ) );
			}
			$occupied[] = $item;
		}
		$replacements = array();
		foreach ( $report['items'] as $item ) {
			$episode = null;
			if ( 'matched' === $item['status'] ) {
				$episode = $item['candidates'][0];
			} elseif ( 'ambiguous' === $item['status'] && isset( $choices[ $item['embed'] ] ) ) {
				foreach ( $item['candidates'] as $candidate ) {
					if ( $choices[ $item['embed'] ] === $candidate['id'] ) {
						$episode = $candidate;
					}
				}
			}
			if ( isset( $choices[ $item['embed'] ] ) && ( 'ambiguous' !== $item['status'] || null === $episode ) ) {
				return new \WP_Error( 'showfm_invalid_choice', __( 'Choose an episode from this ambiguous embed\'s candidates.', 'showfm' ) );
			}
			unset( $choices[ $item['embed'] ] );
			if ( null !== $episode ) {
				if ( '' === Attributes::uuid( $episode['id'] ) || '' === Attributes::uuid( $episode['podcast_id'] ) ) {
					return new \WP_Error( 'showfm_invalid_episode', __( 'The episode identifiers are invalid. Start a new scan.', 'showfm' ) );
				}
				$item['block']  = get_comment_delimited_block_content(
					'showfm/player',
					array(
						'episode'  => $episode['id'],
						'podcast'  => $episode['podcast_id'],
						'snapshot' => Attributes::snapshot(
							array(
								'title'             => $episode['title'],
								'legacyHost'        => $item['host'],
								'legacyAudioSha256' => Migration_Url::fingerprint( Migration_Url::normalise( $item['audio_url'] ?? null ) ),
							)
						),
					),
					''
				);
				$replacements[] = $item;
			}
		}
		if ( $choices ) {
			return new \WP_Error( 'showfm_invalid_choice', __( 'The chosen embed does not exist in this report.', 'showfm' ) );
		}
		if ( ! $replacements ) {
			return $report;
		}
		if ( ! wp_revisions_enabled( $post ) || ! post_type_supports( $post->post_type, 'revisions' ) ) {
			return new \WP_Error( 'showfm_revisions_disabled', __( 'Enable WordPress revisions before migrating so the change can be undone.', 'showfm' ) );
		}
		$content = $post->post_content;
		usort(
			$replacements,
			static function ( $a, $b ) {
				$order = $b['offset'] <=> $a['offset'];
				return 0 !== $order ? $order : $b['embed'] <=> $a['embed'];
			}
		);
		foreach ( $replacements as $replacement ) {
			$block   = 0 === $replacement['length'] ? "\n\n" . $replacement['block'] : $replacement['block'];
			$content = substr_replace( $content, $block, $replacement['offset'], $replacement['length'] );
		}
		// Force a byte-exact pre-edit revision, even if core's whitespace comparison sees no change.
		$original = static function ( $data ) use ( $post ) {
			if ( 'revision' === $data['post_type'] && (int) $data['post_parent'] === $post->ID ) {
				$data['post_content'] = wp_slash( $post->post_content );
			}
			return $data;
		};
		add_filter( 'wp_insert_post_data', $original, PHP_INT_MAX );
		try {
			$revision = _wp_put_post_revision( $post );
		} finally {
			remove_filter( 'wp_insert_post_data', $original, PHP_INT_MAX );
		}
		if ( is_wp_error( $revision ) || ! $revision ) {
			return new \WP_Error( 'showfm_revision_failed', __( 'Could not save the undo revision. The post has not been changed.', 'showfm' ) );
		}
		$fresh = Migration_Scanner::fresh( $post->ID );
		if ( ! $fresh || ! self::unchanged( $fresh, $report ) ) {
			return new \WP_Error( 'showfm_stale_report', __( 'The post or source player changed during migration. Start a new scan.', 'showfm' ) );
		}
		global $wpdb;
		// Atomic comparison at the write, not a cached read followed by an unconditional update.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Compare-and-swap protects concurrent editor writes; caches are cleaned below.
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_content = %s, post_modified = %s, post_modified_gmt = %s WHERE ID = %d AND BINARY post_content = BINARY %s AND BINARY post_title = BINARY %s AND BINARY post_excerpt = BINARY %s AND post_status = %s AND post_date_gmt = %s AND post_modified_gmt = %s", $content, current_time( 'mysql' ), current_time( 'mysql', true ), $post->ID, $post->post_content, $post->post_title, $post->post_excerpt, $post->post_status, $post->post_date_gmt, $post->post_modified_gmt ) );
		if ( 1 !== $changed ) {
			return new \WP_Error( 'showfm_stale_report', __( 'The post changed at write time. The migration was refused.', 'showfm' ) );
		}
		clean_post_cache( $post->ID );
		$updated = Migration_Scanner::fresh( $post->ID );
		// Notify integrations after the atomic write. The pre-edit revision remains the undo.
		if ( $updated ) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core post-update notifications after the atomic write.
			do_action( 'post_updated', $post->ID, $updated, $post );
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core post-update notifications after the atomic write.
			do_action( "save_post_{$post->post_type}", $post->ID, $updated, true );
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core post-update notifications after the atomic write.
			do_action( 'save_post', $post->ID, $updated, true );
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core post-update notifications after the atomic write.
			do_action( 'wp_insert_post', $post->ID, $updated, true );
			$report['applied_hash'] = Migration_Scanner::hash( $updated );
		}
		$report['revision_id']  = $revision;
		$report['revision_url'] = admin_url( 'revision.php?revision=' . $revision );
		$report['status']       = 'swapped';
		$report['swapped']      = array_column( $replacements, 'embed' );
		return $report;
	}
	/**
	 * Compare the complete evidence, including fields that have disappeared.
	 *
	 * @param \WP_Post            $post Fresh database row.
	 * @param array<string,mixed> $report Original report.
	 */
	private static function unchanged( \WP_Post $post, array $report ): bool {
		return hash_equals( $report['hash'], Migration_Scanner::hash( $post ) ) && Migration_Detectors::detect( $post ) === $report['evidence'];
	}
}
