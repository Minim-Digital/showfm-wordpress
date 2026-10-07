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

/** Resumes successful list pages after errors; details are never requested. */
final class Migration_Catalogue {
	const PENDING = 'showfm_migration_pending';

	/**
	 * Request-local immutable catalogue indexes.
	 *
	 * @var array<string,Migration_Matcher>
	 */
	private static $indexes = array();

	/**
	 * Fetch one resumable catalogue, persisting each successful list page.
	 *
	 * @param Api_Client $api Client.
	 * @param string     $run Run UUID.
	 * @return int|\WP_Error
	 * @throws \RuntimeException Internally caught for malformed API responses.
	 */
	public static function fetch( Api_Client $api, string $run ) {
		$state = get_option( self::PENDING, array() );
		if ( ( $state['run'] ?? '' ) !== $run ) {
			$state = array();
		}
		if ( ! isset( $state['pages'] ) ) {
			$state += array(
				'run'            => $run,
				'podcast_cursor' => '',
				'episode_cursor' => '',
				'podcasts'       => array(),
				'index'          => 0,
				'pages'          => 0,
				'podcasts_done'  => false,
				'seen'           => array(),
				'retry_at'       => 0,
			);
		}
		if ( $state['retry_at'] > time() ) {
			return new \WP_Error( 'showfm_backoff', __( 'The catalogue is waiting for API backoff. Resume the dry run after the retry time.', 'showfm' ), array( 'retry_at' => $state['retry_at'] ) );
		}
		while ( true ) {
			$podcast = $state['podcasts'][ $state['index'] ] ?? null;
			if ( null === $podcast && $state['podcasts_done'] ) {
				return $state['pages'];
			}
			$field  = null === $podcast ? 'podcast_cursor' : 'episode_cursor';
			$path   = null === $podcast ? '/v1/me/podcasts' : '/v1/me/podcasts/' . $podcast . '/episodes?status=published';
			$url    = $path . ( null === $podcast ? '?' : '&' ) . 'limit=50' . ( '' !== $state[ $field ] ? '&cursor=' . rawurlencode( $state[ $field ] ) : '' );
			$result = $api->get_keyed( $url );
			$data   = $result->data();
			try {
				if ( ! $result->is( Api_Result::SUCCESS ) || ! is_array( $data ) || ! is_array( $data['data'] ?? null ) || count( $data['data'] ) > 50 || ! is_array( $data['pagination'] ?? null ) || ! array_key_exists( 'next_cursor', $data['pagination'] ) ) {
					throw new \RuntimeException( 'Incomplete catalogue response.' );
				}
				$next       = $data['pagination']['next_cursor'];
				$cursor_key = $path . ':' . ( is_string( $next ) ? $next : '' );
				if ( null !== $next && ( ! is_string( $next ) || '' === $next || isset( $state['seen'][ $cursor_key ] ) ) ) {
					throw new \RuntimeException( 'Invalid pagination cursor.' );
				}
				$page = array();
				foreach ( $data['data'] as $row ) {
					if ( ! is_array( $row ) || '' === Attributes::uuid( $row['id'] ?? null ) ) {
						throw new \RuntimeException( 'Invalid catalogue identity.' );
					}
					if ( null === $podcast ) {
						$page[] = $row['id'];
						continue;
					}
					if ( ( $row['podcast_id'] ?? '' ) !== $podcast || ! is_string( $row['title'] ?? null ) || ! is_array( $row['source'] ?? null ) || ! array_key_exists( 'rss_guid', $row ) || ( null !== $row['rss_guid'] && ! is_string( $row['rss_guid'] ) ) ) {
						throw new \RuntimeException( 'The episode list requires the provenance contract.' );
					}
					foreach ( array( 'enclosure_sha256', 'guid_sha256' ) as $key ) {
						if ( ! array_key_exists( $key, $row['source'] ) || ( null !== $row['source'][ $key ] && ( ! is_string( $row['source'][ $key ] ) || ! Migration_Tokens::match( '/\A[a-f0-9]{64}\z/', $row['source'][ $key ] ) ) ) ) {
							throw new \RuntimeException( 'Invalid provenance fingerprint.' );
						}
					}
					if ( 'published' === ( $row['status'] ?? '' ) ) {
						$page[] = array(
							'id'           => $row['id'],
							'podcast_id'   => $podcast,
							'title'        => $row['title'],
							'published_at' => is_string( $row['published_at'] ?? null ) ? $row['published_at'] : '',
							'source'       => $row['source'],
							'rss_guid'     => $row['rss_guid'],
						);
					}
				}
			} catch ( \RuntimeException $error ) {
				$state['retry_at'] = time() + max( 5, $result->retry_after() );
				update_option( self::PENDING, $state, false );
				return new \WP_Error( 'showfm_catalogue', __( 'The catalogue is incomplete. The episode list must expose provenance fingerprints. Saved pages and the previous report are retained; resume the dry run after backoff.', 'showfm' ), array( 'retry_at' => $state['retry_at'] ) );
			}
			if ( null === $podcast ) {
				$state['podcasts']      = $page;
				$state['index']         = 0;
				$state['podcasts_done'] = null === $next;
			} else {
				update_option( self::key( $run, $state['pages'] + 1 ), $page, false );
				++$state['pages'];
				if ( null === $next ) {
					++$state['index'];
				}
			}
			$state[ $field ] = $next ?? '';
			if ( null !== $next ) {
				$state['seen'][ $cursor_key ] = true;
			}
			$state['retry_at'] = 0;
			update_option( self::PENDING, $state, false );
		}
	}

	/**
	 * Build the matching index once per site/run in this request.
	 *
	 * @param string $run Run UUID.
	 * @param int    $pages Completed catalogue pages.
	 */
	public static function matcher( string $run, int $pages ): Migration_Matcher {
		$key = get_current_blog_id() . ':' . $run . ':' . $pages;
		if ( ! isset( self::$indexes[ $key ] ) ) {
			self::$indexes[ $key ] = new Migration_Matcher( self::episodes( $run, $pages ) );
		}
		return self::$indexes[ $key ];
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
	 * Option name.
	 *
	 * @param string $run  Run UUID.
	 * @param int    $page Page number.
	 */
	private static function key( string $run, int $page ): string {
		return 'showfm_migration_' . $run . '_catalogue_' . $page;
	}
}
