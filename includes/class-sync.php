<?php
/**
 * Locked, paged change-feed consumer and durable post report queue.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Polls only through cron or an explicit CLI command. */
final class Sync {
	const POLL_HOOK = 'showfm_poll';
	const OPTION    = 'showfm_sync';
	const PAGE_SIZE = 20;
	const MAX_PAGES = 10;
	/**
	 * In-process guard, since MySQL locks are re-entrant.
	 *
	 * @var array<int,bool>
	 */
	private static $running = array();

	/**
	 * Per-site durable state.
	 *
	 * @return array<string,mixed>
	 */
	public static function state(): array {
		$state = (array) get_option( self::OPTION, array() );
		return array_merge(
			array(
				'site'     => '',
				'cursor'   => 0,
				'reports'  => array(),
				'failures' => 0,
				'retry_at' => 0,
				'error'    => '',
			),
			$state
		);
	}

	/**
	 * Custom 15-minute interval, with per-site first-run jitter.
	 *
	 * @param array<string,mixed> $schedules Cron intervals.
	 * @return array<string,mixed>
	 */
	public static function schedules( array $schedules ): array {
		$schedules['showfm_quarter_hour'] = array(
			'interval' => 900,
			'display'  => __( 'Every 15 minutes (show.fm)', 'showfm' ),
		);
		return $schedules;
	}

	/** Install the fallback only after connection. No HTTP. */
	public static function schedule(): void {
		if ( Plugin::connection()->is_connected() && ! wp_next_scheduled( self::POLL_HOOK ) ) {
			wp_schedule_event( time() + wp_rand( 1, 120 ), 'showfm_quarter_hour', self::POLL_HOOK );
		}
	}

	/** Cron and ping wake-ups share exactly the same entry point. */
	public static function run(): void {
		( new self() )->pull();
	}

	/**
	 * Pull a bounded run; continue through cron if the feed is larger than the budget.
	 * Dry runs read the feed but never write posts, cursor, reports or scheduling state.
	 * Authentication/rate-limit state still follows the API client safety rules.
	 *
	 * @param bool $dry_run Preview changes.
	 * @param bool $from_start Replay from zero (persist reset only after acquiring the lock).
	 * @return array{status:string,rows:int,cursor:int}
	 */
	public function pull( bool $dry_run = false, bool $from_start = false ): array {
		$state      = self::state();
		$result     = array(
			'status' => 'not_connected',
			'rows'   => 0,
			'cursor' => (int) $state['cursor'],
		);
		$connection = Plugin::connection();
		if ( ! $connection->is_connected() ) {
			return $result;
		}
		$blog = get_current_blog_id();
		$lock = new Sync_Lock();
		if ( ! empty( self::$running[ $blog ] ) || ! $lock->acquire() ) {
			$result['status'] = 'locked';
			return $result;
		}
		self::$running[ $blog ] = true;
		try {
			// Reread after acquiring, bypassing a stale per-request options cache.
			wp_cache_delete( self::OPTION, 'options' );
			$state = self::state();
			$site  = (string) $connection->site_id();
			if ( $site !== $state['site'] ) {
				$state = array(
					'site'     => $site,
					'cursor'   => 0,
					'reports'  => array(),
					'failures' => 0,
					'retry_at' => 0,
					'error'    => '',
				);
			}
			$result['cursor'] = (int) $state['cursor'];
			if ( $state['retry_at'] > time() || Api_Client::rate_limit_remaining() ) {
				$result['status'] = 'backoff';
				return $result;
			}
			if ( $from_start ) {
				$state['cursor'] = 0;
			}
			$deferred = array();
			$client   = Plugin::api_client();
			if ( ! $dry_run && Connect::verify_pending() ) {
				$verified = Plugin::connect()->verify();
				if ( ! $verified->is( Api_Result::SUCCESS ) ) {
					return $this->failure( $state, $result, $verified->type(), $verified->retry_after() );
				}
			}
			for ( $page = 0; $page < self::MAX_PAGES; ++$page ) {
				if ( ! $dry_run ) {
					$failure = $this->reports( $state, $deferred );
					if ( $failure ) {
						return $this->failure( $state, $result, $failure->type(), $failure->retry_after() );
					}
				}
				self::require_lock();
				$response = $client->get_keyed( '/v1/me/sites/' . rawurlencode( $site ) . '/changes?after=' . $state['cursor'] . '&limit=' . self::PAGE_SIZE );
				if ( ! $response->is( Api_Result::SUCCESS ) ) {
					if ( $dry_run ) {
						$result['status'] = $response->type();
						return $result;
					}
					return $this->failure( $state, $result, $response->type(), $response->retry_after() );
				}
				$data = $response->data();
				if ( ! self::valid_page( $data, (int) $state['cursor'] ) ) {
					return $dry_run ? array_merge( $result, array( 'status' => 'invalid_feed' ) ) : $this->failure( $state, $result, 'invalid_feed' );
				}
				foreach ( $data['data'] as $row ) {
					if ( ! $dry_run ) {
						self::require_lock();
						$id = ( new Sync_Posts() )->apply( $site, $row );
						if ( is_wp_error( $id ) ) {
							return $this->failure( $state, $result, $id->get_error_code(), Api_Client::rate_limit_remaining() );
						}
						if ( $id ) {
							$state['reports'][ $row['episode_id'] ] = $id;
						}
					}
					++$result['rows'];
				}
				$state['cursor']  = $data['cursor']['next'];
				$result['cursor'] = $state['cursor'];
				if ( ! $dry_run ) {
					// Reports and cursor share one durable write: never acknowledge without the outbox.
					self::save( $state );
				}
				// Always GET once more at the applied cursor, even on the last non-empty page.
				// That acknowledges last_pulled_seq to show.fm after all local applies.
				if ( empty( $data['data'] ) ) {
					if ( ! $dry_run && $state['reports'] ) {
						return $this->failure( $state, $result, 'reports_pending' );
					}
					$result['status'] = $dry_run ? 'dry_run' : 'caught_up';
					if ( ! $dry_run ) {
						$state['failures'] = 0;
						$state['retry_at'] = 0;
						$state['error']    = '';
						self::save( $state );
						update_option( Health::LAST_SYNC_OPTION, time(), false );
					}
					return $result;
				}
			}
			$result['status'] = $dry_run ? 'dry_run_limit' : 'continuing';
			if ( ! $dry_run ) {
				self::wake( time() + 10 );
			}
			return $result;
		} catch ( \Throwable $error ) {
			if ( ! $lock->owned() ) {
				return array_merge( $result, array( 'status' => 'lock_lost' ) );
			}
			// Do not retain hook exception messages: third-party hooks can include secrets.
			return $dry_run ? array_merge( $result, array( 'status' => 'apply_failed' ) ) : $this->failure( $state, $result, 'apply_failed' );
		} finally {
			unset( self::$running[ $blog ] );
			$lock->release();
		}
	}

	/**
	 * Fail closed on malformed pages before applying anything.
	 *
	 * @param mixed $data API body.
	 * @param int   $after Requested cursor.
	 */
	private static function valid_page( $data, int $after ): bool {
		if ( ! is_array( $data ) || ! is_array( $data['data'] ?? null ) || ! is_array( $data['cursor'] ?? null ) ) {
			return false;
		}
		$cursor = $data['cursor'];
		if ( ( $cursor['after'] ?? null ) !== $after || ! is_int( $cursor['next'] ?? null ) || ! is_int( $cursor['latest'] ?? null ) || ! is_bool( $cursor['has_more'] ?? null ) ) {
			return false;
		}
		$last = $after;
		foreach ( $data['data'] as $row ) {
			if ( ! is_array( $row ) || ! Sync_Posts::valid( $row ) || $row['seq'] <= $last ) {
				return false;
			}
			$last = $row['seq'];
		}
		return $last === $cursor['next'] && $last <= $cursor['latest'] && ( ! $cursor['has_more'] || $last > $after );
	}

	/**
	 * Drain at most one page's reports. The server accepts one POST per episode.
	 * Rebuild the payload from the post on every retry, so a scheduled publish is current.
	 *
	 * @param array<string,mixed> $state Durable state, updated after every acknowledgement.
	 * @param array<string,bool>  $deferred Refused reports already attempted this run.
	 */
	private function reports( array &$state, array &$deferred ): ?Api_Result {
		foreach ( array_slice( array_diff_key( $state['reports'], $deferred ), 0, self::PAGE_SIZE, true ) as $episode => $id ) {
			if ( isset( $deferred[ $episode ] ) ) {
				continue;
			}
			$post = get_post( $id );
			if ( ! $post ) {
				$deferred[ (string) $episode ] = true;
				unset( $state['reports'][ $episode ] );
				$state['reports'][ $episode ] = $id;
				self::save( $state );
				continue;
			}
			$url = self::post_url( $post );
			if ( null === $url ) {
				$deferred[ (string) $episode ] = true;
				unset( $state['reports'][ $episode ] );
				$state['reports'][ $episode ] = $id;
				self::save( $state );
				continue;
			}
			$states = array(
				'future'  => 'scheduled',
				'publish' => 'published',
				'trash'   => 'trashed',
			);
			$body   = array(
				'wp_post_id' => $id,
				'post_url'   => $url,
				'state'      => $states[ $post->post_status ] ?? 'draft',
			);
			$hash   = get_post_meta( $id, '_showfm_content_hash', true );
			if ( is_string( $hash ) && '' !== $hash ) {
				$body['content_hash'] = $hash;
			}
			self::require_lock();
			$response = Plugin::api_client()->post_keyed( '/v1/me/sites/' . rawurlencode( $state['site'] ) . '/episodes/' . rawurlencode( $episode ) . '/post', $body );
			if ( in_array( $response->status(), array( 400, 403, 404 ), true ) ) {
				$deferred[ (string) $episode ] = true;
				unset( $state['reports'][ $episode ] );
				$state['reports'][ $episode ] = $id;
				self::save( $state );
				continue;
			}
			if ( ! $response->is( Api_Result::SUCCESS ) ) {
				return $response;
			}
			unset( $state['reports'][ $episode ] );
			self::save( $state );
		}
		return null;
	}

	/**
	 * Validate the actual permalink against the registered home's host and path.
	 *
	 * @param \WP_Post $post Post being reported.
	 */
	public static function post_url( \WP_Post $post ): ?string {
		$url   = get_permalink( $post );
		$parts = $url ? wp_parse_url( $url ) : false;
		$home  = wp_parse_url( home_url() );
		if ( ! is_array( $parts ) || ! is_array( $home ) || 'https' !== ( $parts['scheme'] ?? '' ) || strtolower( $parts['host'] ?? '' ) !== strtolower( $home['host'] ?? '' ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['port'] ) || isset( $parts['fragment'] ) ) {
			return null;
		}
		$path = $parts['path'] ?? '/';
		$base = rtrim( $home['path'] ?? '', '/' );
		if ( ( '' !== $base && 0 !== strpos( $path, $base . '/' ) ) || preg_match( '#(?:^|/)\.{1,2}(?:/|$)|%2e|%2f|%5c|[\\\\\x00-\x20]#i', $path ) || strlen( $url ) > 2048 ) {
			return null;
		}
		return $url;
	}

	/**
	 * Persist a receipt; storage failure must prevent acknowledging the feed.
	 *
	 * @param array<string,mixed> $state New state.
	 * @throws \RuntimeException If the receipt cannot be stored.
	 */
	private static function save( array $state ): void {
		self::require_lock();
		if ( ! update_option( self::OPTION, $state, false ) && get_option( self::OPTION ) !== $state ) {
			throw new \RuntimeException( 'Sync state could not be saved.' );
		}
	}

	/**
	 * Refuse further work if WordPress reconnected and lost the session lock.
	 *
	 * @throws \RuntimeException If the database session no longer owns the lock.
	 */
	private static function require_lock(): void {
		if ( ! ( new Sync_Lock() )->owned() ) {
			throw new \RuntimeException( 'Sync lock lost.' );
		}
	}

	/**
	 * Durable backoff, shared by polls and pings.
	 *
	 * @param array<string,mixed>                      $state State.
	 * @param array{status:string,rows:int,cursor:int} $result Run summary.
	 * @param string                                   $error Machine-readable error.
	 * @param int                                      $retry_after Minimum delay.
	 * @return array{status:string,rows:int,cursor:int}
	 */
	private function failure( array $state, array $result, string $error, int $retry_after = 0 ): array {
		++$state['failures'];
		$state['error']    = $error;
		$state['retry_at'] = time() + max( $retry_after, min( 3600, 30 * ( 2 ** min( 7, $state['failures'] - 1 ) ) ) + wp_rand( 0, 15 ) );
		self::save( $state );
		update_option( Health::SYNC_ERRORS_OPTION, min( 1000000, 1 + (int) get_option( Health::SYNC_ERRORS_OPTION, 0 ) ), false );
		if ( Plugin::connection()->is_connected() ) {
			self::wake( $state['retry_at'] );
		}
		$result['status'] = $error;
		$result['cursor'] = (int) $state['cursor'];
		return $result;
	}

	/**
	 * Queue a continuation or retry, coalescing earlier wake-ups.
	 *
	 * @param int $at Timestamp.
	 */
	private static function wake( int $at ): void {
		$next = wp_next_scheduled( Ping_Endpoint::PULL_HOOK );
		if ( $next && $next < $at ) {
			wp_clear_scheduled_hook( Ping_Endpoint::PULL_HOOK );
			$next = false;
		}
		if ( ! $next ) {
			wp_schedule_single_event( $at, Ping_Endpoint::PULL_HOOK );
		}
	}
}
