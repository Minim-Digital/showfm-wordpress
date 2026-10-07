<?php
/**
 * Durable indexed identity and local deletion receipts.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Options use a unique indexed name; interrupted inserts use a bounded primary-key range. */
final class Sync_Identity {
	/**
	 * Indexed receipt name.
	 *
	 * @param string $guid Plugin identity.
	 */
	public static function key( string $guid ): string {
		return 'showfm_identity_' . hash( 'sha256', $guid );
	}

	/**
	 * Read a receipt without caching it on the service instance.
	 *
	 * @param string $guid Identity.
	 * @return array<string,mixed>
	 */
	public static function get( string $guid ): array {
		return (array) get_option( self::key( $guid ), array() );
	}

	/**
	 * Recover an insert interrupted before post meta or the ID receipt was written.
	 *
	 * @throws \RuntimeException When the database lookup fails.
	 *
	 * @param string $guid Identity.
	 */
	public static function find( string $guid ): int {
		$receipt = self::get( $guid );
		if ( isset( $receipt['id'] ) ) {
			return (int) $receipt['id'];
		}
		if ( ! isset( $receipt['after'] ) ) {
			return 0;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Recovery is bounded by the indexed primary key, never a full GUID scan.
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND guid = %s ORDER BY ID LIMIT 1", $receipt['after'], $guid ) );
		if ( $wpdb->last_error ) {
			throw new \RuntimeException( 'Identity lookup failed.' );
		}
		if ( $id ) {
			self::remember( $guid, $id );
		}
		return $id;
	}

	/**
	 * Persist the insertion boundary before inserting a post or attachment.
	 *
	 * @throws \RuntimeException When the database lookup fails.
	 *
	 * @param string $guid Identity.
	 */
	public static function prepare( string $guid ): void {
		if ( self::get( $guid ) ) {
			return;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- MAX uses the posts primary key.
		$after = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" );
		if ( $wpdb->last_error ) {
			throw new \RuntimeException( 'Identity boundary lookup failed.' );
		}
		self::save( $guid, array( 'after' => $after ) );
	}

	/**
	 * Keep the ID even after WordPress removes the post and its meta.
	 *
	 * @param string $guid Identity.
	 * @param int    $id Post ID.
	 */
	public static function remember( string $guid, int $id ): void {
		self::save( $guid, array_merge( self::get( $guid ), array( 'id' => $id ) ) );
	}

	/**
	 * Record a user deletion independently of post meta.
	 *
	 * @param int $id Post ID.
	 */
	public static function detach( int $id ): void {
		$post = get_post( $id );
		if ( ! $post || 0 !== strpos( $post->guid, 'urn:showfm:' ) || 'attachment' === $post->post_type || Sync_Posts::is_writing( $id ) ) {
			return;
		}
		$receipt = self::get( $post->guid );
		if ( ! array_key_exists( 'edited_before_detach', $receipt ) ) {
			$receipt['edited_before_detach'] = (bool) get_post_meta( $id, '_showfm_edited', true );
		}
		// Capture while the post still exists; permanent deletion removes all its meta.
		if ( ! isset( $receipt['local_report'] ) ) {
			$receipt['local_report'] = array(
				'wp_post_id' => $id,
				'post_url'   => Sync::post_url( $post ),
				'state'      => 'trashed',
			);
		}
		self::save(
			$post->guid,
			array_merge(
				$receipt,
				array(
					'id'       => $id,
					'detached' => true,
				)
			)
		);
		update_post_meta( $id, '_showfm_sync_state', 'local_detached' );
		update_post_meta( $id, '_showfm_edited', 1 );
	}

	/**
	 * Catch both the Trash action and direct status updates.
	 *
	 * @param string   $new_status New status.
	 * @param string   $old Previous status.
	 * @param \WP_Post $post Post.
	 */
	public static function transition( string $new_status, string $old, \WP_Post $post ): void {
		if ( 'trash' === $new_status && 'trash' !== $old ) {
			self::detach( $post->ID );
			self::report_detachment( $post->ID, $post );
		} elseif ( 'trash' === $old && 'trash' !== $new_status ) {
			self::reattach( $post );
		}
	}

	/**
	 * Restore local attachment and report the actual restored status once.
	 *
	 * @param \WP_Post $post Restored post.
	 */
	private static function reattach( \WP_Post $post ): void {
		$receipt = self::get( $post->guid );
		$parts   = explode( ':', $post->guid );
		if ( empty( $receipt['detached'] ) || Sync_Posts::is_writing( $post->ID ) || 4 !== count( $parts ) || 'urn' !== $parts[0] || 'showfm' !== $parts[1] || '' === Attributes::uuid( $parts[2] ) || '' === Attributes::uuid( $parts[3] ) ) {
			return;
		}
		// Old receipts without this flag conservatively retain their edit protection.
		if ( isset( $receipt['edited_before_detach'] ) && ! $receipt['edited_before_detach'] ) {
			delete_post_meta( $post->ID, '_showfm_edited' );
		}
		( new Sync_Posts() )->edited( $post );
		update_post_meta( $post->ID, '_showfm_sync_state', 'synced' );
		$states = array(
			'publish' => 'published',
			'future'  => 'scheduled',
		);
		Sync_Local_Reports::queue(
			$parts[2],
			$parts[3],
			array(
				'wp_post_id' => $post->ID,
				'post_url'   => Sync::post_url( $post ),
				'state'      => $states[ $post->post_status ] ?? 'draft',
			)
		);
		unset( $receipt['detached'], $receipt['local_report'], $receipt['report_queued'], $receipt['edited_before_detach'] );
		self::save( $post->guid, $receipt );
	}

	/**
	 * Called only after a successful trash transition or permanent deletion.
	 *
	 * @param int      $id Original post ID.
	 * @param \WP_Post $post Original post, supplied by WordPress after deletion.
	 */
	public static function report_detachment( int $id, \WP_Post $post ): void {
		$receipt = self::get( $post->guid );
		if ( empty( $receipt['detached'] ) || ! empty( $receipt['report_queued'] ) || empty( $receipt['local_report'] ) || Sync_Posts::is_writing( $id ) ) {
			return;
		}
		$parts = explode( ':', $post->guid );
		if ( 4 !== count( $parts ) || '' === Attributes::uuid( $parts[2] ) || '' === Attributes::uuid( $parts[3] ) ) {
			return;
		}
		$body = $receipt['local_report'];
		if ( null === $body['post_url'] ) {
			Sync_Log::record( 'report_invalid_url' );
		} else {
			Sync_Local_Reports::queue( $parts[2], $parts[3], $body );
		}
		$receipt['report_queued'] = true;
		self::save( $post->guid, $receipt );
	}

	/**
	 * Persist a receipt or fail the row on a database failure.
	 *
	 * @param string              $guid Identity.
	 * @param array<string,mixed> $receipt Receipt.
	 * @throws \RuntimeException When storage fails.
	 */
	private static function save( string $guid, array $receipt ): void {
		$key = self::key( $guid );
		if ( ! update_option( $key, $receipt, false ) && get_option( $key ) !== $receipt ) {
			throw new \RuntimeException( 'Identity receipt could not be saved.' );
		}
	}
}
