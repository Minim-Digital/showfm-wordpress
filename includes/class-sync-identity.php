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
		self::save(
			$post->guid,
			array_merge(
				self::get( $post->guid ),
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
		}
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
