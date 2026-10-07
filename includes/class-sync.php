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
	const POLL_HOOK      = 'showfm_poll';
	const OPTION         = 'showfm_sync';
	const PAGE_SIZE      = 20;
	const MAX_PAGES      = 10;
	const APPLY_ATTEMPTS = 5;
	/**
	 * In-process guard, since MySQL locks are re-entrant.
	 *
	 * @var array<int,bool>
	 */
	private static $running = array();
	/**
	 * Active lease objects, per blog.
	 *
	 * @var array<int,Sync_Lock>
	 */
	private static $locks = array();

	/**
	 * Per-site durable state.
	 *
	 * @return array<string,mixed>
	 */
	public static function state(): array {
		$state = (array) get_option( self::OPTION, array() );
		return array_merge(
			array(
				'site'           => '',
				'cursor'         => 0,
				'reports'        => array(),
				'report_retries' => array(),
				'failures'       => 0,
				'retry_at'       => 0,
				'error'          => '',
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
	 * Dry runs preview one page and never request a cursor beyond locally applied rows.
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
			if ( ! $dry_run ) {
				self::wake( time() + 15 );
			}
			$result['status'] = 'locked';
			return $result;
		}
		self::$running[ $blog ] = true;
		self::$locks[ $blog ]   = $lock;
		try {
			// Reread after acquiring, bypassing a stale per-request options cache.
			wp_cache_delete( self::OPTION, 'options' );
			$state = self::state();
			$site  = (string) $connection->site_id();
			if ( $site !== $state['site'] ) {
				$state = array(
					'site'           => $site,
					'cursor'         => 0,
					'reports'        => array(),
					'report_retries' => array(),
					'failures'       => 0,
					'retry_at'       => 0,
					'error'          => '',
				);
			}
			$result['cursor'] = (int) $state['cursor'];
			if ( $state['retry_at'] > time() || Api_Client::rate_limit_remaining() ) {
				if ( ! $dry_run ) {
					self::wake( max( (int) $state['retry_at'], time() + Api_Client::rate_limit_remaining() ) );
				}
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
					$failure = Sync_Local_Reports::send( $site );
					if ( ! $failure ) {
						$failure = $this->reports( $state, $deferred );
					}
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
				$seen = (int) $state['cursor'];
				foreach ( $data['data'] as $row ) {
					++$result['rows'];
					if ( ! is_array( $row ) || ! Sync_Posts::valid( $row ) || $row['seq'] <= $seen || $row['seq'] > $data['cursor']['next'] ) {
						if ( ! $dry_run ) {
							Sync_Log::record( 'row_invalid', is_array( $row ) && is_int( $row['seq'] ?? null ) ? $row['seq'] : 0 );
							if ( is_array( $row ) && is_int( $row['seq'] ?? null ) && $row['seq'] > $seen && $row['seq'] <= $data['cursor']['next'] ) {
								$state['cursor'] = $row['seq'];
								$seen            = $row['seq'];
							}
						}
						continue;
					}
					$seen = $row['seq'];
					if ( ! $dry_run ) {
						self::require_lock();
						$retry = $connection->sync_status()['retry'];
						if ( ( $retry['seq'] ?? 0 ) === $row['seq'] && 'row_write_failed' === ( $retry['code'] ?? '' ) && $retry['attempts'] >= self::APPLY_ATTEMPTS ) {
							$state['cursor'] = $row['seq'];
							continue; // Recover a crash after exhaustion was recorded, before the cursor was saved.
						}
						try {
							$id = ( new Sync_Posts() )->apply( $site, $row );
						} catch ( \Throwable $error ) {
							self::require_lock();
							$id = new \WP_Error( 'showfm_row_failed' );
						}
						if ( is_wp_error( $id ) ) {
							$code = self::apply_error( $id );
							if ( 'row_invalid' !== $code && ! self::exhausted( $row['seq'], $code ) ) {
								return $this->failure( $state, $result, $code );
							}
							if ( 'row_invalid' === $code ) {
								Sync_Log::record( $code, $row['seq'] );
							}
							$state['cursor'] = $row['seq'];
							continue;
						}
						$state['cursor'] = $row['seq'];
						$changed_at      = is_string( $row['changed_at'] ?? null ) ? strtotime( $row['changed_at'] ) : false;
						if ( false !== $changed_at ) {
							Ping_Endpoint::note_change( $changed_at );
						}
						$detached = in_array( $row['reason'] ?? null, array( 'access_removed', 'plan_or_policy' ), true );
						if ( $id && ! $detached ) {
							$state['reports'][ $row['episode_id'] ] = $id;
						} else {
							unset( $state['reports'][ $row['episode_id'] ], $state['report_retries'][ $row['episode_id'] ] );
						}
					}
				}
				$state['cursor']  = $data['cursor']['next'];
				$result['cursor'] = $state['cursor'];
				if ( ! $dry_run ) {
					// Reports and cursor share one durable write: never acknowledge without the outbox.
					self::save( $state );
					self::clear_apply_retry( (int) $state['cursor'] );
				}
				if ( $dry_run ) {
					// The server records each requested after as applied. Previewing another
					// page would falsely acknowledge the rows we deliberately did not apply.
					$result['status'] = $data['cursor']['has_more'] ? 'dry_run_limit' : 'dry_run';
					return $result;
				}
				// Always GET once more at the applied cursor, even on the last non-empty page.
				// That acknowledges last_pulled_seq to show.fm after all local applies.
				if ( empty( $data['data'] ) ) {
					( new Sync_Artwork() )->retry();
					$result['status']  = 'caught_up';
					$state['failures'] = 0;
					$state['retry_at'] = 0;
					$state['error']    = '';
					self::save( $state );
					update_option( Health::LAST_SYNC_OPTION, time(), false );
					return $result;
				}
			}
			$result['status'] = 'continuing';
			self::wake( time() + 10 );
			return $result;
		} catch ( \Throwable $error ) {
			if ( ! $lock->owned() ) {
				return array_merge( $result, array( 'status' => 'lock_lost' ) );
			}
			// Do not retain hook exception messages: third-party hooks can include secrets.
			return $dry_run ? array_merge( $result, array( 'status' => 'apply_failed' ) ) : $this->failure( $state, $result, 'apply_failed' );
		} finally {
			unset( self::$running[ $blog ], self::$locks[ $blog ] );
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
		if ( $cursor['next'] < $after || $cursor['next'] > $cursor['latest'] ) {
			return false;
		}
		if ( empty( $data['data'] ) ) {
			return $cursor['next'] === $after && ! $cursor['has_more'];
		}
		$tail = end( $data['data'] );
		if ( ! is_array( $tail ) || ! is_int( $tail['seq'] ?? null ) ) {
			return false;
		}
		// Content may be poison; sequence boundaries still have to account for the cursor.
		$boundary = $after;
		foreach ( $data['data'] as $row ) {
			if ( is_array( $row ) && is_int( $row['seq'] ?? null ) ) {
				$boundary = max( $boundary, $row['seq'] );
			}
		}
		return $boundary > $after && $boundary === $cursor['next'];
	}

	/**
	 * Only known validation refusals are permanent; all other write errors may recover.
	 *
	 * @param \WP_Error $error Apply failure.
	 */
	private static function apply_error( \WP_Error $error ): string {
		$codes = array(
			'empty_content'    => 'row_invalid',
			'showfm_post_type' => 'row_post_type',
			'showfm_author'    => 'row_author',
		);
		return $codes[ $error->get_error_code() ] ?? 'row_write_failed';
	}

	/**
	 * Count failures per sequence and retain exhausted reasons for connection status.
	 *
	 * @param int    $seq Failing sequence.
	 * @param string $code Own reason code.
	 */
	private static function exhausted( int $seq, string $code ): bool {
		$connection = Plugin::connection();
		$status     = $connection->sync_status();
		$previous   = $status['retry'];
		$attempts   = ( $previous['seq'] ?? 0 ) === $seq && ( $previous['code'] ?? '' ) === $code ? $previous['attempts'] + 1 : 1;
		// Configuration cannot recover by discarding an episode. Retain it indefinitely.
		if ( in_array( $code, array( 'row_post_type', 'row_author' ), true ) ) {
			$attempts = 0;
		}
		$entry           = array(
			'seq'      => $seq,
			'code'     => $code,
			'attempts' => $attempts,
			'at'       => time(),
		);
		$exhausted       = $attempts >= self::APPLY_ATTEMPTS;
		$status['retry'] = $entry;
		if ( $exhausted ) {
			$status['skipped'][] = $entry;
			$status['skipped']   = array_slice( $status['skipped'], -50 );
		}
		$connection->save_sync_status( $status );
		Sync_Log::record( $exhausted ? 'row_attempts_exhausted' : $code, $seq );
		return $exhausted;
	}

	/**
	 * Clear the active retry only after its sequence has been durably handled.
	 *
	 * @param int $cursor Applied or deliberately skipped boundary.
	 */
	private static function clear_apply_retry( int $cursor ): void {
		$connection = Plugin::connection();
		$status     = $connection->sync_status();
		if ( $status['retry'] && $status['retry']['seq'] <= $cursor ) {
			$status['retry'] = array();
			$connection->save_sync_status( $status );
		}
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
			$retry  = $state['report_retries'][ $episode ] ?? array(
				'attempts' => 0,
				'next'     => 0,
			);
			$post   = get_post( $id );
			$reason = ! $post ? 'report_missing_post' : '';
			if ( $post && in_array( get_post_meta( $id, '_showfm_sync_state', true ), array( 'detached', 'paused', 'local_detached' ), true ) ) {
				$reason = 'report_detached';
			}
			$url = $post ? self::post_url( $post ) : null;
			if ( '' === $reason && null === $url ) {
				$reason = 'report_invalid_url';
			}
			if ( '' !== $reason ) {
				$this->drop_report( $state, (string) $episode, $reason );
				continue;
			}
			if ( $retry['next'] > time() ) {
				$deferred[ (string) $episode ] = true;
				unset( $state['reports'][ $episode ] );
				$state['reports'][ $episode ] = $id;
				self::save( $state );
				self::wake( $retry['next'] );
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
			if ( is_string( $hash ) && 1 === preg_match( '/^[0-9A-Za-z:_-]{1,128}$/', $hash ) ) {
				$body['content_hash'] = $hash;
			}
			self::require_lock();
			$response = Plugin::api_client()->post_keyed( '/v1/me/sites/' . rawurlencode( $state['site'] ) . '/episodes/' . rawurlencode( $episode ) . '/post', $body );
			if ( $response->is( Api_Result::UNAUTHORISED ) || $response->is( Api_Result::RATE_LIMITED ) ) {
				return $response; // Connection-wide auth/rate limits still apply to feed reads.
			}
			if ( $response->is( Api_Result::SUCCESS ) ) {
				unset( $state['reports'][ $episode ], $state['report_retries'][ $episode ] );
				self::save( $state );
			} elseif ( $response->is( Api_Result::TRANSIENT_FAILURE ) ) {
				++$retry['attempts'];
				$retry['next']                       = time() + min( 3600, 30 * ( 2 ** min( 7, $retry['attempts'] - 1 ) ) );
				$state['report_retries'][ $episode ] = $retry;
				$deferred[ (string) $episode ]       = true;
				// Rotate deferred entries so a large outbox cannot starve later reports.
				unset( $state['reports'][ $episode ] );
				$state['reports'][ $episode ] = $id;
				Sync_Log::record( 'report_retry' );
				self::save( $state );
				self::wake( $retry['next'] );
			} else {
				$code = in_array( $response->status(), array( 400, 403, 404 ), true ) ? 'report_' . $response->status() : 'report_terminal';
				$this->drop_report( $state, (string) $episode, $code );
			}
		}
		if ( array_diff_key( $state['reports'], $deferred ) ) {
			self::wake( time() + 15 );
		}
		return null;
	}

	/**
	 * Discard a terminal report with a content-free audit receipt.
	 *
	 * @param array<string,mixed> $state Consumer state.
	 * @param string              $episode Episode ID.
	 * @param string              $reason Own reason code.
	 */
	private function drop_report( array &$state, string $episode, string $reason ): void {
		Sync_Log::record( $reason );
		unset( $state['reports'][ $episode ], $state['report_retries'][ $episode ] );
		self::save( $state );
	}

	/**
	 * Validate the actual permalink against the registered home's host and path.
	 *
	 * @param \WP_Post $post Post being reported.
	 */
	public static function post_url( \WP_Post $post ): ?string {
		return self::validate_post_url( get_permalink( $post ) );
	}

	/**
	 * Validate saved deletion URLs against the current registered home too.
	 *
	 * @param string|false $url Permalink snapshot.
	 */
	public static function validate_post_url( $url ): ?string {
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

	/** Guard writes after optional artwork HTTP when called inside a pull. */
	public static function guard(): void {
		if ( isset( self::$locks[ get_current_blog_id() ] ) ) {
			self::require_lock();
		}
	}

	/**
	 * Refuse further work if WordPress reconnected and lost the session lock.
	 *
	 * @throws \RuntimeException If the database session no longer owns the lock.
	 */
	public static function require_lock(): void {
		$lock = self::$locks[ get_current_blog_id() ] ?? null;
		if ( ! $lock || ! $lock->owned() ) {
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
		$error = in_array( $error, array( Api_Result::UNAUTHORISED, Api_Result::RATE_LIMITED, Api_Result::TRANSIENT_FAILURE, Api_Result::FAILED, Api_Result::UNAVAILABLE, Api_Result::NOT_MODIFIED, 'invalid_feed', 'apply_failed', 'row_post_type', 'row_author', 'row_write_failed' ), true ) ? $error : 'apply_failed';
		++$state['failures'];
		$state['error']    = $error;
		$state['retry_at'] = time() + max( $retry_after, min( 3600, 30 * ( 2 ** min( 7, $state['failures'] - 1 ) ) + wp_rand( 0, 15 ) ) );
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
	public static function wake( int $at ): void {
		$next = wp_next_scheduled( Ping_Endpoint::PULL_HOOK );
		if ( $next && $next > $at ) {
			wp_clear_scheduled_hook( Ping_Endpoint::PULL_HOOK );
			$next = false;
		}
		if ( ! $next ) {
			wp_schedule_single_event( $at, Ping_Endpoint::PULL_HOOK );
		}
	}
}
