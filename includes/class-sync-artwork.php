<?php
/**
 * Optional, bounded artwork import into the local media library.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Never fetches media on front-end requests or sends a key to an artwork URL. */
final class Sync_Artwork {
	/** Ten megabytes, with one extra byte to detect truncation. */
	const MAX_BYTES = 10485760;

	/**
	 * Import public artwork once, after publication. Missing metadata is retried.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $episode Episode UUID.
	 * @return true|\WP_Error Outcome.
	 */
	public function apply( int $post_id, string $episode ) {
		$response = Plugin::api_client()->get( '/v1/episodes/' . rawurlencode( $episode ) );
		if ( $response->is( Api_Result::RATE_LIMITED ) ) {
			update_option( Api_Client::RATE_LIMIT_OPTION, time() + $response->retry_after(), false );
		}
		if ( ! $response->is( Api_Result::SUCCESS ) ) {
			return new \WP_Error( 'showfm_artwork_metadata', __( 'Artwork metadata could not be loaded.', 'showfm' ) );
		}
		$data = $response->data();
		$url  = $data['data']['artwork']['url'] ?? null;
		if ( ! is_string( $url ) || '' === $url ) {
			update_post_meta( $post_id, '_showfm_artwork_done', 1 );
			return true;
		}
		$id = $this->import( $url, $post_id );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		set_post_thumbnail( $post_id, $id );
		update_post_meta( $post_id, '_showfm_artwork_done', 1 );
		return true;
	}

	/**
	 * Safe sideload with source-URL dedupe across this site's media library.
	 *
	 * @param string $url Public artwork URL.
	 * @param int    $post_id Parent post.
	 * @return int|\WP_Error Attachment ID or failure.
	 */
	public function import( string $url, int $post_id ) {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['port'] ) || isset( $parts['fragment'] ) ) {
			return new \WP_Error( 'showfm_artwork_url', __( 'Artwork must use a public HTTPS address.', 'showfm' ) );
		}
		$guid = 'urn:showfm:artwork:' . hash( 'sha256', $url );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Finds an attachment even after a crash before its metadata was written.
		$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND guid = %s LIMIT 1", $guid ) );
		if ( $existing && wp_attachment_is_image( $existing ) ) {
			return $existing;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$temp = wp_tempnam( 'showfm-artwork' );
		if ( ! $temp ) {
			return new \WP_Error( 'showfm_artwork_temp', __( 'A temporary artwork file could not be created.', 'showfm' ) );
		}
		try {
			$response = wp_safe_remote_get(
				$url,
				array(
					'timeout'             => 10,
					'redirection'         => 0,
					'stream'              => true,
					'filename'            => $temp,
					'limit_response_size' => self::MAX_BYTES + 1,
				)
			);
			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) || filesize( $temp ) > self::MAX_BYTES ) {
				return new \WP_Error( 'showfm_artwork_download', __( 'Artwork could not be downloaded safely.', 'showfm' ) );
			}
			$mime  = wp_get_image_mime( $temp );
			$types = array(
				'image/jpeg' => 'jpg',
				'image/png'  => 'png',
				'image/webp' => 'webp',
				'image/gif'  => 'gif',
			);
			if ( ! isset( $types[ $mime ] ) ) {
				return new \WP_Error( 'showfm_artwork_type', __( 'Artwork must be a JPEG, PNG, WebP or GIF image.', 'showfm' ) );
			}
			return media_handle_sideload(
				array(
					'name'     => 'showfm-' . substr( hash( 'sha256', $url ), 0, 16 ) . '.' . $types[ $mime ],
					'tmp_name' => $temp,
				),
				$post_id,
				null,
				array( 'guid' => $guid )
			);
		} finally {
			wp_delete_file( $temp );
		}
	}
}
