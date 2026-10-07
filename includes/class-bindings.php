<?php
/**
 * Public episode block bindings and protected identity meta.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Binds escaped public episode fields to core blocks. */
final class Bindings {
	/** Register on init. */
	public static function register(): void {
		register_post_meta(
			'',
			'_showfm_episode_id',
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => array( Attributes::class, 'uuid' ),
				'auth_callback'     => static function ( $allowed, $key, $post_id ) {
					return current_user_can( 'edit_post', $post_id );
				},
			)
		);
		register_block_bindings_source(
			'showfm/episode',
			array(
				'label'              => __( 'show.fm episode', 'showfm' ),
				'uses_context'       => array( 'postId', 'showfm/episode' ),
				'get_value_callback' => array( self::class, 'value' ),
			)
		);
	}

	/**
	 * No network or private fields; an unavailable/missing episode binds empty text.
	 *
	 * @param array<string,mixed> $args Source args, with a field key.
	 * @param \WP_Block           $block Block context.
	 */
	public static function value( array $args, \WP_Block $block ): ?string {
		$field = $args['key'] ?? '';
		if ( ! in_array( $field, array( 'title', 'description', 'published_date', 'season_episode' ), true ) ) {
			return null;
		}
		$id = Attributes::uuid( $block->context['showfm/episode'] ?? get_post_meta( (int) ( $block->context['postId'] ?? 0 ), '_showfm_episode_id', true ) );
		if ( '' === $id ) {
			return '';
		}
		$data    = Plugin::cache()->get( '/v1/episodes/' . $id );
		$episode = $data['data'] ?? array();
		if ( 'published_date' === $field ) {
			$time = isset( $episode['published_at'] ) ? strtotime( $episode['published_at'] ) : false;
			return false === $time ? '' : esc_html( wp_date( get_option( 'date_format' ), $time ) );
		}
		if ( 'season_episode' === $field ) {
			$parts = array();
			if ( isset( $episode['season_number'] ) ) {
				/* translators: %s: season number. */
				$parts[] = sprintf( __( 'Season %s', 'showfm' ), $episode['season_number'] );
			}
			if ( isset( $episode['episode_number'] ) ) {
				/* translators: %s: episode number. */
				$parts[] = sprintf( __( 'Episode %s', 'showfm' ), $episode['episode_number'] );
			}
			return esc_html( implode( ', ', $parts ) );
		}
		return esc_html( $episode[ $field ] ?? '' );
	}
}
