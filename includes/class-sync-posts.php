<?php
/**
 * Applies the connected site's change feed to WordPress posts.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** One-way post writer. All entry points are called under the sync lock. */
final class Sync_Posts {
	/** Publishing settings, per blog, ready for the Publishing tab. */
	const SETTINGS = 'showfm_publishing';
	/**
	 * Posts the sync itself is moving to the bin.
	 *
	 * @var array<int,bool>
	 */
	private static $writing = array();

	/**
	 * Exclude our own lifecycle writes from user-deletion detection.
	 *
	 * @param int $id Post ID.
	 */
	public static function is_writing( int $id ): bool {
		return isset( self::$writing[ $id ] );
	}

	/**
	 * Validate each row independently before it reaches WordPress APIs.
	 *
	 * @param array<string,mixed> $row Feed row.
	 */
	public static function valid( array $row ): bool {
		if ( ! is_int( $row['seq'] ?? null ) || $row['seq'] < 1 || '' === Attributes::uuid( $row['episode_id'] ?? '' ) || '' === Attributes::uuid( $row['podcast_id'] ?? '' ) ) {
			return false;
		}
		if ( array_key_exists( 'changed_at', $row ) && ( ! is_string( $row['changed_at'] ) || false === strtotime( $row['changed_at'] ) ) ) {
			return false;
		}
		if ( array_key_exists( 'access_state', $row ) && ! in_array( $row['access_state'], array( 'ok', 'paused', 'detached' ), true ) ) {
			return false;
		}
		if ( array_key_exists( 'post', $row ) ) {
			if ( ! is_array( $row['post'] ) || ! array_key_exists( 'wp_post_id', $row['post'] ) || ! array_key_exists( 'post_url', $row['post'] ) || ! array_key_exists( 'state', $row['post'] ) ) {
				return false;
			}
			$post = $row['post'];
			if ( ( isset( $post['wp_post_id'] ) && ( ! is_int( $post['wp_post_id'] ) || $post['wp_post_id'] < 1 ) ) || ( isset( $post['post_url'] ) && ! is_string( $post['post_url'] ) ) || ( isset( $post['state'] ) && ! in_array( $post['state'], array( 'queued', 'scheduled', 'published', 'draft', 'trashed' ), true ) ) ) {
				return false;
			}
		}
		if ( 'tombstone' === ( $row['action'] ?? '' ) ) {
			return null === ( $row['episode'] ?? null ) && in_array( $row['reason'] ?? '', array( 'deleted', 'removed', 'unpublished', 'access_removed', 'plan_or_policy' ), true );
		}
		$episode = $row['episode'] ?? null;
		if ( 'upsert' !== ( $row['action'] ?? '' ) || ! is_array( $episode ) || ( $episode['id'] ?? '' ) !== $row['episode_id'] || ( $episode['podcast_id'] ?? '' ) !== $row['podcast_id'] ) {
			return false;
		}
		foreach ( array( 'description', 'show_notes_html', 'slug', 'episode_type', 'scheduled_for', 'published_at' ) as $field ) {
			if ( isset( $episode[ $field ] ) && ! is_string( $episode[ $field ] ) ) {
				return false;
			}
		}
		foreach ( array( 'episode_number', 'season_number' ) as $field ) {
			if ( isset( $episode[ $field ] ) && ( ! is_int( $episode[ $field ] ) || $episode[ $field ] < 0 ) ) {
				return false;
			}
		}
		if ( ( array_key_exists( 'explicit', $episode ) && ! is_bool( $episode['explicit'] ) ) || ( $row['access_state'] ?? 'ok' ) !== 'ok' || null !== ( $row['reason'] ?? null ) ) {
			return false;
		}
		$status = $episode['status'] ?? '';
		$date   = 'scheduled' === $status ? ( $episode['scheduled_for'] ?? null ) : ( $episode['published_at'] ?? null );
		return in_array( $status, array( 'scheduled', 'published' ), true ) && is_string( $episode['title'] ?? null ) && is_string( $date ) && false !== strtotime( $date ) && is_string( $episode['content_hash'] ?? null ) && 1 === preg_match( '/^[0-9A-Za-z:_-]{1,128}$/', $episode['content_hash'] );
	}

	/**
	 * Locate only a post owned by this connection. Never trust the feed's WordPress ID.
	 * The GUID is inserted with the post itself, so replay recovers a crash before meta.
	 *
	 * @throws \RuntimeException If the identity lookup fails.
	 *
	 * @param string $site Site ID.
	 * @param string $episode Episode ID.
	 */
	public static function find( string $site, string $episode ): int {
		$guid = self::guid( $site, $episode );
		$id   = Sync_Identity::find( $guid );
		if ( $id ) {
			return $id;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Upgrade path uses WordPress's meta_key index, never scans GUIDs.
		$candidates = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_showfm_episode_id' AND meta_value = %s", $episode ) );
		if ( $wpdb->last_error ) {
			throw new \RuntimeException( 'Post lookup failed.' );
		}
		foreach ( $candidates as $candidate ) {
			$post = get_post( (int) $candidate );
			if ( $post && $post->guid === $guid ) {
				Sync_Identity::remember( $guid, $post->ID );
				return $post->ID;
			}
		}
		$id = 0;
		return $id;
	}

	/**
	 * Stable identity stored atomically with the post row.
	 *
	 * @param string $site Site ID.
	 * @param string $episode Episode ID.
	 */
	private static function guid( string $site, string $episode ): string {
		return 'urn:showfm:' . $site . ':' . $episode;
	}

	/**
	 * Apply one row. Zero means a tombstone arrived before any post existed.
	 *
	 * @param string              $site Connection ID.
	 * @param array<string,mixed> $row Valid feed row.
	 * @return int|\WP_Error Post ID or failure.
	 */
	public function apply( string $site, array $row ) {
		$id       = self::find( $site, $row['episode_id'] );
		$post     = $id ? get_post( $id ) : null;
		$guid     = self::guid( $site, $row['episode_id'] );
		$identity = Sync_Identity::get( $guid );
		if ( ! empty( $identity['detached'] ) || ( $id && ! $post ) ) {
			return 0;
		}
		if ( $post && 'trash' === $post->post_status && 'deleted' !== get_post_meta( $id, '_showfm_sync_state', true ) ) {
			Sync_Identity::detach( $id );
			return 0;
		}

		if ( 'tombstone' === $row['action'] ) {
			if ( ! $post ) {
				return 0;
			}
			$reason = $row['reason'];
			if ( 'access_removed' === $reason || 'plan_or_policy' === $reason ) {
				self::meta( $id, '_showfm_sync_state', 'access_removed' === $reason ? 'detached' : 'paused' );
				if ( 'access_removed' === $reason ) {
					self::meta( $id, '_showfm_sync_notice', __( 'No longer synced from show.fm', 'showfm' ) );
				}
				return $id;
			}
			$status = 'deleted' === $reason ? 'trash' : 'draft';
			if ( $status !== $post->post_status ) {
				// EMPTY_TRASH_DAYS=0 makes wp_trash_post permanently delete; refuse that path.
				self::$writing[ $id ] = true;
				try {
					if ( 'trash' === $status && EMPTY_TRASH_DAYS ) {
						if ( ! wp_trash_post( $id ) ) {
							return new \WP_Error( 'showfm_trash', __( 'The post could not be moved to the bin.', 'showfm' ) );
						}
					} else {
						$result = wp_update_post(
							array(
								'ID'          => $id,
								'post_status' => $status,
							),
							true
						);
						if ( is_wp_error( $result ) ) {
							return $result;
						}
					}
				} finally {
					unset( self::$writing[ $id ] );
				}
			}
			self::meta( $id, '_showfm_sync_state', $reason );
			return $id;
		}

		$episode   = $row['episode'];
		$settings  = (array) get_option( self::SETTINGS, array() );
		$edited    = $post && $this->edited( $post );
		$timestamp = strtotime( 'scheduled' === $episode['status'] ? $episode['scheduled_for'] : $episode['published_at'] );
		$date      = gmdate( 'Y-m-d H:i:s', $timestamp );
		// Match core's handling of a future date less than a minute away or already past.
		$status        = 'scheduled' === $episode['status'] && $timestamp >= time() + MINUTE_IN_SECONDS ? 'future' : 'publish';
		$needs_artwork = 'published' === $episode['status'] && ! empty( $settings['featured_image'] ) && ! $edited && ! get_post_meta( $id, '_showfm_artwork_done', true );
		$type          = $post ? $post->post_type : ( $settings['post_type'] ?? 'post' );
		if ( ! is_string( $type ) || ! post_type_exists( $type ) || ! is_post_type_viewable( $type ) ) {
			return new \WP_Error( 'showfm_post_type' );
		}
		$author = self::author( $type, $post ? (int) $post->post_author : absint( $settings['author'] ?? 0 ) );
		if ( ! $author ) {
			return new \WP_Error( 'showfm_author' );
		}
		$same = $post && get_post_meta( $id, '_showfm_content_hash', true ) === $episode['content_hash'] && 'synced' === get_post_meta( $id, '_showfm_sync_state', true );
		if ( $same && $post->post_status === $status && $post->post_date_gmt === $date && (int) $post->post_author === $author ) {
			if ( $needs_artwork ) {
				( new Sync_Artwork() )->apply( $id, $row['episode_id'] );
			}
			return $id;
		}
		$fields = array(
			'ID'            => $id,
			'post_author'   => $author,
			'post_status'   => $status,
			'post_date_gmt' => $date,
			'post_date'     => get_date_from_gmt( $date ),
			'edit_date'     => true,
		);
		if ( ! $edited ) {
			$fields['post_title']   = wp_strip_all_tags( $episode['title'] );
			$fields['post_excerpt'] = wp_strip_all_tags( $episode['description'] ?? '' );
			$fields['post_content'] = self::content( $episode );
		}
		if ( ! $post ) {
			$fields['post_type'] = $type;
			Sync_Identity::prepare( $guid );
			$fields['post_category'] = array_map( 'absint', (array) ( $settings['categories'] ?? array() ) );
			$fields['guid']          = self::guid( $site, $row['episode_id'] );
			$fields['meta_input']    = array(
				'_showfm_episode_id' => $row['episode_id'],
				'_showfm_site_id'    => $site,
			);
		}
		if ( $post && ! $edited ) {
			// A crash after the post UPDATE but before its receipt must not look like an edit.
			self::meta( $id, '_showfm_pending_revision', self::hash_fields( $fields ) );
		}
		$result = $post ? wp_update_post( wp_slash( $fields ), true ) : wp_insert_post( wp_slash( $fields ), true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$id = $result;
		Sync_Identity::remember( $guid, $id );
		self::meta( $id, '_showfm_episode_id', $row['episode_id'] );
		self::meta( $id, '_showfm_site_id', $site );
		if ( ! $edited ) {
			self::meta( $id, '_showfm_synced_revision', self::hash( get_post( $id ) ) );
		}
		delete_post_meta( $id, '_showfm_pending_revision' );
		if ( $needs_artwork ) {
			( new Sync_Artwork() )->apply( $id, $row['episode_id'] );
		}
		self::meta( $id, '_showfm_sync_state', 'synced' );
		delete_post_meta( $id, '_showfm_sync_notice' );
		self::meta( $id, '_showfm_content_hash', $episode['content_hash'] );
		return $id;
	}

	/**
	 * Keep a valid publishing author, or choose a site member with the required capability.
	 *
	 * @param string $type Post type.
	 * @param int    $preferred Configured or existing author.
	 */
	private static function author( string $type, int $preferred ): int {
		$object = get_post_type_object( $type );
		if ( ! $object ) {
			return 0;
		}
		$capability = $object->cap->publish_posts;
		if ( $preferred && get_userdata( $preferred ) && user_can( $preferred, $capability ) && ( ! is_multisite() || is_user_member_of_blog( $preferred ) ) ) {
			return $preferred;
		}
		if ( $preferred ) {
			Sync_Log::record( 'author_invalid' );
		}
		$authors = get_users(
			array(
				'capability' => $capability,
				'number'     => 1,
				'fields'     => 'ID',
				'orderby'    => 'ID',
			)
		);
		return isset( $authors[0] ) && user_can( (int) $authors[0], $capability ) ? (int) $authors[0] : 0;
	}

	/**
	 * Write a receipt or fail the row. A false return can also mean unchanged metadata.
	 *
	 * @param int        $id Post ID.
	 * @param string     $key Meta key.
	 * @param string|int $value Receipt value.
	 * @throws \RuntimeException When metadata could not be stored.
	 */
	private static function meta( int $id, string $key, $value ): void {
		if ( ! update_post_meta( $id, $key, wp_slash( $value ) ) && (string) get_post_meta( $id, $key, true ) !== (string) $value ) {
			throw new \RuntimeException( 'Post receipt could not be saved.' );
		}
	}

	/**
	 * Mark WordPress edits permanently, even when the source hash is unchanged.
	 *
	 * @param \WP_Post $post Existing post.
	 */
	public function edited( \WP_Post $post ): bool {
		if ( get_post_meta( $post->ID, '_showfm_edited', true ) ) {
			return true;
		}
		$written = get_post_meta( $post->ID, '_showfm_synced_revision', true );
		$current = self::hash( $post );
		if ( $written && $written !== $current && get_post_meta( $post->ID, '_showfm_pending_revision', true ) !== $current ) {
			self::meta( $post->ID, '_showfm_edited', 1 );
			return true;
		}
		return false;
	}

	/**
	 * Hash just the editable fields, excluding status and dates.
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function hash( \WP_Post $post ): string {
		return self::hash_fields( (array) $post );
	}

	/**
	 * Hash fields before or after a write.
	 *
	 * @param array<string,mixed> $fields Post fields.
	 */
	private static function hash_fields( array $fields ): string {
		return hash( 'sha256', (string) wp_json_encode( array( $fields['post_title'], $fields['post_content'], $fields['post_excerpt'] ) ) );
	}

	/**
	 * Player plus a saved description. Static text honours one-way edits; live bindings
	 * would overwrite the reader's view after WordPress editing.
	 *
	 * @param array<string,mixed> $episode Episode content.
	 */
	private static function content( array $episode ): string {
		$player      = get_comment_delimited_block_content( 'showfm/player', array( 'episode' => $episode['id'] ), '' );
		$description = $episode['show_notes_html'] ?? '';
		do {
			$previous    = $description;
			$description = (string) preg_replace( '/<!--(?:.*?-->|.*$)/s', '', $description );
		} while ( $previous !== $description );
		$description = wp_kses_post( $description );
		if ( '' === $description ) {
			$description = wpautop( esc_html( $episode['description'] ?? '' ) );
		}
		return $player . "\n\n" . get_comment_delimited_block_content( 'core/html', array(), $description );
	}
}
