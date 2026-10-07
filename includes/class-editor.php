<?php
/**
 * Block editor wiring: start-up settings and the post panel's sync status.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Gives the editor script what it needs, and nothing secret. */
final class Editor {

	/** REST field with a post's sync status, read by the "show.fm" post panel. */
	const FIELD = 'showfm_sync';

	/** Adds the settings before the editor script, on `enqueue_block_editor_assets`. */
	public static function enqueue(): void {
		wp_add_inline_script( 'showfm-block-editor', 'window.showfmEditor = ' . wp_json_encode( Editor_Api::settings() ) . ';', 'before' );
		// The post panel lives outside the editor iframe, so its styles load here too.
		wp_enqueue_style( 'showfm-block-editor' );
	}

	/** Registers the read-only sync status on every post type the editor can open. */
	public static function register_fields(): void {
		foreach ( get_post_types( array( 'show_in_rest' => true ) ) as $type ) {
			register_rest_field(
				$type,
				self::FIELD,
				array(
					'get_callback' => array( self::class, 'sync_status' ),
					'schema'       => array(
						'description' => __( 'show.fm sync status for this post.', 'showfm' ),
						'type'        => array( 'object', 'null' ),
						'context'     => array( 'edit' ),
						'readonly'    => true,
					),
				)
			);
		}
	}

	/**
	 * The post's sync status from WP-4a's post meta, for people who can edit it.
	 *
	 * @param array<string,mixed> $post Prepared post data.
	 * @return array<string,mixed>|null
	 */
	public static function sync_status( array $post ): ?array {
		$id = (int) ( $post['id'] ?? 0 );
		if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
			return null;
		}
		$state  = (string) get_post_meta( $id, '_showfm_sync_state', true );
		$synced = (int) get_post_meta( $id, '_showfm_synced_at', true );
		return array(
			'synced'   => '' !== (string) get_post_meta( $id, '_showfm_site_id', true ),
			'state'    => preg_match( '/\A[a-z_]{1,32}\z/', $state ) ? $state : '',
			'edited'   => (bool) get_post_meta( $id, '_showfm_edited', true ),
			'syncedAt' => $synced > 0 ? gmdate( 'c', $synced ) : null,
		);
	}
}
