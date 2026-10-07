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
		$post = get_post( $report['post_id'] );
		if ( ! $post || 'publish' !== $post->post_status || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return new \WP_Error( 'showfm_post_unavailable', __( 'The published post or page is no longer available.', 'showfm' ) );
		}
		if ( Migration_Scanner::already( $post->post_content ) ) {
			return $report;
		}
		if ( ! hash_equals( $report['hash'], Migration_Scanner::hash( $post ) ) ) {
			return new \WP_Error( 'showfm_stale_report', __( 'The post changed after scanning. Start a new scan.', 'showfm' ) );
		}
		// Recheck identifiers too: a shortcode can point at a different local post's meta.
		$detected = Migration_Detectors::detect( $post );
		foreach ( $report['items'] as $index => $item ) {
			if ( ! isset( $detected[ $index ] ) || array_diff_assoc( $detected[ $index ], array_intersect_key( $item, $detected[ $index ] ) ) ) {
				return new \WP_Error( 'showfm_stale_report', __( 'The source player changed after scanning. Start a new scan.', 'showfm' ) );
			}
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
								'title'       => $episode['title'],
								'audioUrl'    => $episode['audio_url'] ?? '',
								'legacyHost'  => $item['host'],
								'legacyAudio' => Migration_Url::normalise( $item['audio_url'] ?? '' ),
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
				return $b['offset'] <=> $a['offset'];
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
		$keep = static function ( $revisions ) use ( $revision ) {
			return array_filter(
				$revisions,
				static function ( $saved ) use ( $revision ) {
					return $saved->ID !== $revision;
				}
			);
		};
		// Existing content is already stored. Preserve it exactly, including scripts on multisite.
		// Only the replacement block attributes are new, encoded by WordPress's block serialiser.
		$preserve = static function ( $data, $postarr ) use ( $post, $content ) {
			if ( (int) ( $postarr['ID'] ?? 0 ) === $post->ID ) {
				$data['post_content'] = wp_slash( $content );
			}
			return $data;
		};
		add_filter( 'wp_save_post_revision_revisions_before_deletion', $keep );
		add_filter( 'wp_insert_post_data', $preserve, PHP_INT_MAX, 2 );
		try {
			$result = wp_update_post(
				wp_slash(
					array(
						'ID'           => $post->ID,
						'post_content' => $content,
					)
				),
				true
			);
		} finally {
			remove_filter( 'wp_save_post_revision_revisions_before_deletion', $keep );
			remove_filter( 'wp_insert_post_data', $preserve, PHP_INT_MAX );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$report['revision_id']  = $revision;
		$report['revision_url'] = admin_url( 'revision.php?revision=' . $revision );
		$report['status']       = 'swapped';
		$report['swapped']      = array_column( $replacements, 'embed' );
		return $report;
	}
}
