<?php
/**
 * Paged catalogue of the connected key's published episodes.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Uses the keyed contract only. Original enclosure and GUID are not exposed yet. */
final class Migration_Catalogue {
	/**
	 * Fetch into non-autoloaded pages. An incomplete catalogue must never be matched.
	 *
	 * @param Api_Client $api   Connected API client.
	 * @param string     $run   Report run UUID.
	 * @return int|\WP_Error Number of catalogue pages.
	 * @throws \RuntimeException Internally caught on malformed catalogue data.
	 */
	public static function fetch( Api_Client $api, string $run ) {
		$number = 0;
		try {
			foreach ( self::pages( $api, '/v1/me/podcasts' ) as $podcasts ) {
				foreach ( $podcasts as $podcast ) {
					$podcast_id = Attributes::uuid( $podcast['id'] ?? null );
					if ( '' === $podcast_id ) {
						throw new \RuntimeException( 'Invalid podcast response.' );
					}
					foreach ( self::pages( $api, '/v1/me/podcasts/' . $podcast_id . '/episodes?status=published' ) as $episodes ) {
						$page = array();
						foreach ( $episodes as $episode ) {
							$id = Attributes::uuid( $episode['id'] ?? null );
							if ( '' === $id ) {
								throw new \RuntimeException( 'Invalid episode response.' );
							}
							$result = $api->get_keyed( '/v1/me/episodes/' . $id );
							$data   = self::body( $result );
							$detail = $data['data'];
							if ( ! is_array( $detail ) || ( $detail['id'] ?? '' ) !== $id || ( $detail['podcast_id'] ?? '' ) !== $podcast_id || ! is_string( $detail['title'] ?? null ) ) {
								throw new \RuntimeException( 'Invalid episode detail.' );
							}
							// Only public episodes can produce a playable insertion snapshot.
							if ( 'published' !== ( $detail['status'] ?? '' ) || ! is_array( $detail['media'] ?? null ) ) {
								continue;
							}
							$audio  = $detail['media']['audio']['url'] ?? '';
							$page[] = array(
								'id'           => $id,
								'podcast_id'   => $podcast_id,
								'title'        => $detail['title'],
								'published_at' => is_string( $detail['published_at'] ?? null ) ? $detail['published_at'] : '',
								'audio_url'    => is_string( $audio ) ? $audio : '',
							);
						}
						update_option( self::key( $run, ++$number ), $page, false );
						wp_cache_delete( self::key( $run, $number ), 'options' );
					}
				}
			}
		} catch ( \RuntimeException $error ) {
			return new \WP_Error( 'showfm_catalogue', __( 'Could not read the complete episode catalogue. Check the connection and rate limit, then start a new scan.', 'showfm' ) );
		}
		return $number;
	}

	/**
	 * Read a bounded page at a time, dropping runtime option caches afterwards.
	 *
	 * @param string $run   Report run UUID.
	 * @param int    $pages Number of completed pages.
	 * @return \Generator<int,array<string,mixed>>
	 */
	public static function episodes( string $run, int $pages ): \Generator {
		for ( $page = 1; $page <= $pages; ++$page ) {
			$key = self::key( $run, $page );
			foreach ( get_option( $key, array() ) as $episode ) {
				yield $episode;
			}
			wp_cache_delete( $key, 'options' );
		}
	}

	/**
	 * Page a documented keyed endpoint, refusing malformed or repeated cursors.
	 *
	 * @param Api_Client $api  Client.
	 * @param string     $path API path.
	 * @return \Generator<int,array<int,array<string,mixed>>>
	 * @throws \RuntimeException On malformed pagination.
	 */
	private static function pages( Api_Client $api, string $path ): \Generator {
		$cursor = '';
		$seen   = array();
		do {
			$url        = $path . ( false === strpos( $path, '?' ) ? '?' : '&' ) . 'limit=50' . ( '' !== $cursor ? '&cursor=' . rawurlencode( $cursor ) : '' );
			$data       = self::body( $api->get_keyed( $url ) );
			$pagination = $data['pagination'] ?? null;
			if ( ! is_array( $pagination ) || ! array_key_exists( 'next_cursor', $pagination ) || ! is_array( $data['data'] ) || count( $data['data'] ) > 50 ) {
				throw new \RuntimeException( 'Invalid pagination.' );
			}
			yield $data['data'];
			if ( null === $pagination['next_cursor'] ) {
				break;
			}
			$cursor = $pagination['next_cursor'];
			if ( ! is_string( $cursor ) || '' === $cursor || isset( $seen[ $cursor ] ) ) {
				throw new \RuntimeException( 'Invalid cursor.' );
			}
			$seen[ $cursor ] = true;
		} while ( true );
	}

	/**
	 * Require a complete success response, never treat an HTTP error as an empty list.
	 *
	 * @param Api_Result $result API result.
	 * @return array<string,mixed>
	 * @throws \RuntimeException On an unsuccessful response.
	 */
	private static function body( Api_Result $result ): array {
		$data = $result->data();
		if ( ! $result->is( Api_Result::SUCCESS ) || ! is_array( $data ) || ! isset( $data['data'] ) ) {
			throw new \RuntimeException( 'Catalogue request failed.' );
		}
		return $data;
	}

	/**
	 * Option name.
	 *
	 * @param string $run  Run UUID.
	 * @param int    $page Page number.
	 */
	private static function key( string $run, int $page ): string {
		return 'showfm_migration_' . $run . '_catalogue_' . $page;
	}
}
