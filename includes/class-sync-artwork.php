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

	const MAX_DIMENSION = 8000;
	const MAX_PIXELS    = 16000000;
	const MAX_ATTEMPTS  = 3;
	const QUEUE         = 'showfm_artwork_queue';

	/**
	 * Optional artwork never prevents committing the episode row.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $episode Episode UUID.
	 */
	public function apply( int $post_id, string $episode ): void {
		$queue   = (array) get_option( self::QUEUE, array() );
		$pending = $queue[ $post_id ] ?? array(
			'episode'  => $episode,
			'attempts' => (int) get_post_meta( $post_id, '_showfm_artwork_attempts', true ),
			'next'     => 0,
		);
		if ( get_post_meta( $post_id, '_showfm_artwork_done', true ) ) {
			Sync::guard();
			unset( $queue[ $post_id ] );
			update_option( self::QUEUE, $queue, false );
			return;
		}
		if ( $pending['next'] > time() ) {
			return;
		}
		Sync::guard();
		if ( $pending['attempts'] >= self::MAX_ATTEMPTS ) {
			$result = new \WP_Error( 'showfm_artwork_transient' );
		} else {
			++$pending['attempts'];
			// Count attempts before I/O, including a worker that dies during image processing.
			update_post_meta( $post_id, '_showfm_artwork_attempts', $pending['attempts'] );
			$pending['next']   = time() + 60;
			$queue[ $post_id ] = $pending;
			update_option( self::QUEUE, $queue, false );
			try {
				$result = $this->fetch( $post_id, $episode );
			} catch ( \Throwable $error ) {
				$result = new \WP_Error( 'showfm_artwork_transient' );
			}
		}
		Sync::guard();
		if ( ! is_wp_error( $result ) ) {
			update_post_meta( $post_id, '_showfm_artwork_done', 1 );
			delete_post_meta( $post_id, '_showfm_artwork_error' );
			unset( $queue[ $post_id ] );
		} elseif ( 'showfm_artwork_transient' === $result->get_error_code() && $pending['attempts'] < self::MAX_ATTEMPTS ) {
			$pending['next']   = time() + max( 60 * $pending['attempts'], (int) $result->get_error_data() );
			$queue[ $post_id ] = $pending;
			update_post_meta( $post_id, '_showfm_artwork_error', 'artwork_retry' );
			Sync_Log::record( 'artwork_retry' );
			Sync::wake( $pending['next'] );
		} else {
			$code = 'showfm_artwork_transient' === $result->get_error_code() ? 'artwork_exhausted' : 'artwork_terminal';
			update_post_meta( $post_id, '_showfm_artwork_done', 'failed' );
			update_post_meta( $post_id, '_showfm_artwork_error', $code );
			Sync_Log::record( $code );
			unset( $queue[ $post_id ] );
		}
		update_option( self::QUEUE, $queue, false );
	}

	/** Retry a bounded batch independently of the change-feed cursor. */
	public function retry(): void {
		$queue = (array) get_option( self::QUEUE, array() );
		uasort(
			$queue,
			static function ( $a, $b ) {
				return $a['next'] <=> $b['next'];
			}
		);
		if ( count( $queue ) > Sync::PAGE_SIZE ) {
			Sync::wake( time() + 15 );
		}
		foreach ( array_slice( $queue, 0, Sync::PAGE_SIZE, true ) as $id => $pending ) {
			if ( get_post_meta( $id, '_showfm_artwork_done', true ) ) {
				$this->apply( (int) $id, $pending['episode'] );
				continue;
			}
			$post = get_post( $id );
			if ( ! $post || 'publish' !== $post->post_status || 'synced' !== get_post_meta( $id, '_showfm_sync_state', true ) || ! Publishing::options_of( (int) $id )['featured_image'] || ( new Sync_Posts() )->edited( $post ) ) {
				$current = (array) get_option( self::QUEUE, array() );
				unset( $current[ $id ] );
				update_option( self::QUEUE, $current, false );
				continue;
			}
			if ( $pending['next'] <= time() ) {
				Sync::require_lock();
				$this->apply( (int) $id, $pending['episode'] );
			} else {
				Sync::wake( $pending['next'] );
			}
		}
	}

	/**
	 * Fetch artwork, translating every external failure into our own classification.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $episode Episode UUID.
	 * @return true|\WP_Error Outcome.
	 */
	private function fetch( int $post_id, string $episode ) {
		$response = Plugin::api_client()->get( '/v1/episodes/' . rawurlencode( $episode ) );
		if ( ! $response->is( Api_Result::SUCCESS ) ) {
			$transient = in_array( $response->type(), array( Api_Result::RATE_LIMITED, Api_Result::TRANSIENT_FAILURE ), true ) || 404 === $response->status();
			return new \WP_Error( $transient ? 'showfm_artwork_transient' : 'showfm_artwork_terminal', '', $response->retry_after() );
		}
		// The players render from the same public answer: keep it, so the post's first render
		// has the episode, not just its saved copy.
		if ( 1 === preg_match( '/\A[0-9a-f-]{36}\z/i', $episode ) ) {
			Plugin::cache()->store( '/v1/episodes/' . strtolower( $episode ), $response );
		}
		$data    = $response->data();
		$artwork = is_array( $data ) && is_array( $data['data'] ?? null ) ? ( $data['data']['artwork'] ?? null ) : null;
		if ( ! is_array( $artwork ) || ! array_key_exists( 'url', $artwork ) || ( null !== $artwork['url'] && ! is_string( $artwork['url'] ) ) ) {
			return new \WP_Error( 'showfm_artwork_terminal' );
		}
		if ( empty( $artwork['url'] ) ) {
			return true;
		}
		$id = $this->import( $artwork['url'], $post_id );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		Sync::guard();
		if ( ! set_post_thumbnail( $post_id, $id ) && (int) get_post_thumbnail_id( $post_id ) !== $id ) {
			return new \WP_Error( 'showfm_artwork_transient' );
		}
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
		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || ! in_array( strtolower( $parts['host'] ?? '' ), Environment::media_hosts(), true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['port'] ) || isset( $parts['fragment'] ) ) {
			return new \WP_Error( 'showfm_artwork_url', __( 'Artwork must use a public HTTPS address.', 'showfm' ) );
		}
		$guid     = 'urn:showfm:artwork:' . hash( 'sha256', $url );
		$existing = Sync_Identity::find( $guid );
		if ( $existing && wp_attachment_is_image( $existing ) ) {
			return $existing;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$temp = wp_tempnam( 'showfm-artwork' );
		if ( ! $temp ) {
			return new \WP_Error( 'showfm_artwork_transient', __( 'A temporary artwork file could not be created.', 'showfm' ) );
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
			if ( is_wp_error( $response ) ) {
				return new \WP_Error( 'showfm_artwork_transient' );
			}
			$status = wp_remote_retrieve_response_code( $response );
			if ( 429 === $status || $status >= 500 ) {
				$retry = wp_remote_retrieve_header( $response, 'retry-after' );
				return new \WP_Error( 'showfm_artwork_transient', '', Api_Client::parse_retry_after( is_string( $retry ) ? $retry : null ) );
			}
			if ( 200 !== $status || filesize( $temp ) > self::MAX_BYTES ) {
				return new \WP_Error( 'showfm_artwork_terminal' );
			}
			$size = wp_getimagesize( $temp );
			if ( ! $size || $size[0] > self::MAX_DIMENSION || $size[1] > self::MAX_DIMENSION || $size[0] * $size[1] > self::MAX_PIXELS ) {
				return new \WP_Error( 'showfm_artwork_terminal' );
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
			Sync::guard();
			Sync_Identity::prepare( $guid );
			$result = media_handle_sideload(
				array(
					'name'     => 'showfm-' . substr( hash( 'sha256', $url ), 0, 16 ) . '.' . $types[ $mime ],
					'tmp_name' => $temp,
				),
				$post_id,
				null,
				array( 'guid' => $guid )
			);
			if ( is_wp_error( $result ) ) {
				return new \WP_Error( 'showfm_artwork_transient' );
			}
			Sync::guard();
			Sync_Identity::remember( $guid, $result );
			return $result;
		} finally {
			wp_delete_file( $temp );
		}
	}
}
