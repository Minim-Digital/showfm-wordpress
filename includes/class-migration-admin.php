<?php
/**
 * The Migrate tab's work: bounded scan and swap steps, the run lease, choices and the view.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Drives `Migrator` one bounded step per request, so the browser can show progress and a
 * reload resumes where the last step stopped. Nothing here runs on its own: no cron, no
 * activation hook. Every step needs `manage_options`, as `Migrator` checks.
 *
 * - **Lease.** A scan or swap belongs to the admin who runs it. Each step renews a lease of
 *   `LEASE_TTL` seconds; while it is live, another admin's steps are refused, and they see
 *   who is running it. A closed tab lets the lease lapse, so nobody is locked out for long.
 * - **Choices.** An admin's pick for an ambiguous embed is stored per run, and only when the
 *   stored report lists that episode as one of the embed's candidates.
 * - **Swap cursor.** The swap works through the reports in post ID order, a few posts per
 *   step, and records the posts it could not change with the reason.
 *
 * The browser gets titles, dates, hosts and links. It never gets the key, byte offsets,
 * provenance fingerprints or legacy audio URLs.
 */
final class Migration_Admin {

	/** Who is running a scan or swap, and until when (autoload off). */
	const LEASE = 'showfm_migrate_lease';

	/** Embeds found and when the scan finished, for the run named inside (autoload off). */
	const PROGRESS = 'showfm_migrate_progress';

	/** How long a step keeps the run for its admin, in seconds. */
	const LEASE_TTL = 120;

	/** Seconds a pick or a lease change waits for another request's site lock. */
	const WAIT = 5;

	/** Catalogue requests per scan step. */
	const CATALOGUE_REQUESTS = 10;

	/** Posts swapped per step. Each creates a revision and runs the save hooks. */
	const SWAP_POSTS = 5;

	/** Report groups, in the order the tab shows them. */
	const GROUPS = array( 'ready', 'choose', 'unmatched', 'already', 'review' );

	/** Hosts the scanner detects, by the detector's host key. Brand names, not translated. */
	const HOSTS = array(
		'buzzsprout' => 'Buzzsprout',
		'libsyn'     => 'Libsyn',
		'captivate'  => 'Captivate',
		'transistor' => 'Transistor',
		'spotify'    => 'Spotify',
		'podbean'    => 'Podbean',
		'powerpress' => 'PowerPress',
		'ssp'        => 'Seriously Simple Podcasting',
	);

	/** Player addresses by host, for the short reference under each embed. */
	const DOMAINS = array(
		'buzzsprout' => 'buzzsprout.com',
		'libsyn'     => 'html5-player.libsyn.com',
		'captivate'  => 'player.captivate.fm',
		'transistor' => 'share.transistor.fm',
		'spotify'    => 'open.spotify.com',
		'podbean'    => 'podbean.com',
	);

	/**
	 * Engine.
	 *
	 * @var Migrator
	 */
	private $migrator;

	/**
	 * Builds the service.
	 *
	 * @param Migrator $migrator Engine.
	 */
	public function __construct( Migrator $migrator ) {
		$this->migrator = $migrator;
	}

	/**
	 * One scan step: start or continue reading the episode catalogue, or check the next
	 * batch of posts. A restart discards any unfinished scan and starts afresh.
	 *
	 * @param bool   $restart Start a new scan.
	 * @param string $run     The run the screen shows, or '' to skip the check.
	 * @return true|\WP_Error
	 */
	public function scan( bool $restart, string $run ) {
		$ready = $this->begin();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		$pending = self::fresh( Migration_Catalogue::PENDING );
		$state   = Migration_Store::state();
		$current = (string) ( $pending['run'] ?? $state['run'] ?? '' );
		if ( ! $restart && '' !== $run && $run !== $current ) {
			return self::stale();
		}
		// A scan left unfinished under another key can't continue: start it again.
		if ( $restart || $pending || ! $state || ( empty( $state['complete'] ) && ! $this->migrator->owns( $state ) ) ) {
			$started = $this->migrator->start( $restart ? 0 : (int) ( $pending['post_id'] ?? 0 ), $restart, self::CATALOGUE_REQUESTS );
			if ( is_wp_error( $started ) ) {
				return 'showfm_catalogue_more' === $started->get_error_code() ? true : $started;
			}
			self::save_progress( $started['run'], 0, 0 );
			return true;
		}
		if ( ! empty( $state['complete'] ) ) {
			return self::release() ? true : self::unreleased();
		}
		$after = (int) $state['after'];
		$next  = $this->migrator->batch( $state['run'] );
		if ( is_wp_error( $next ) ) {
			return $next;
		}
		$progress = self::progress( $state['run'] );
		$found    = $progress['found'];
		foreach ( Migration_Scanner::ids( $after, (int) $next['after'], (int) $next['post_id'] ) as $id ) {
			foreach ( Migration_Store::get( $next['run'], $id )['items'] ?? array() as $item ) {
				$found += 'already_showfm' === $item['status'] ? 0 : 1;
			}
		}
		self::save_progress( $next['run'], $found, $next['complete'] ? time() : 0 );
		if ( $next['complete'] && ! self::release() ) {
			return self::unreleased();
		}
		return true;
	}

	/**
	 * One swap step: applies the next few reviewed posts of the finished scan.
	 *
	 * The first step freezes the picks into the swap cursor, under the same lock a pick is
	 * written under, so a pick can't land after the swap has started. The cursor records who
	 * confirmed the swap. Another admin carries it on only with `$confirm`, after their own
	 * confirm dialog, and then it is theirs.
	 *
	 * @param string $run     The run the screen showed when the admin confirmed.
	 * @param bool   $confirm Whether the admin confirmed this swap in the dialog.
	 * @return true|\WP_Error
	 */
	public function swap( string $run, bool $confirm = false ) {
		$ready = $this->begin();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		$state = Migration_Store::state();
		if ( ( $state['run'] ?? '' ) !== $run || empty( $state['complete'] ) || self::fresh( Migration_Catalogue::PENDING ) ) {
			return self::stale();
		}
		if ( ! $this->migrator->owns( $state ) ) {
			return self::reconnected();
		}
		$user     = get_current_user_id();
		$migrator = $this->migrator;
		$cursor   = Migration_Store::locked(
			static function () use ( $run, $user, $confirm, $migrator ) {
				// Checked again under the lock: another tab may have started a new scan, or the
				// site may have reconnected with another key, while this request waited.
				$state = Migration_Store::state();
				if ( ( $state['run'] ?? '' ) !== $run || empty( $state['complete'] ) || self::fresh( Migration_Catalogue::PENDING ) ) {
					return self::stale();
				}
				if ( ! $migrator->owns_now( $state ) ) {
					return self::reconnected();
				}
				$cursor = self::cursor( $run );
				if ( ( $cursor['user'] ?? 0 ) === $user ) {
					return $cursor;
				}
				if ( ! $confirm ) {
					return $cursor ? self::error(
						'confirm',
						409,
						/* translators: %s: the name of the admin who started the swap. */
						sprintf( __( '%s started this swap. Confirm to carry it on.', 'showfm' ), self::name( (int) ( $cursor['user'] ?? 0 ) ) )
					) : self::error( 'confirm', 409, __( 'Confirm the swap first.', 'showfm' ) );
				}
				if ( ! $cursor ) {
					$choices = self::choices( $run );
					$total   = 0;
					foreach ( Migration_Store::reports( $run ) as $report ) {
						$total += self::swappable( $report, $choices[ $report['post_id'] ] ?? array() ) ? 1 : 0;
					}
					$cursor = array(
						'after'    => 0,
						'done'     => false,
						'total'    => $total,
						'checked'  => 0,
						'posts'    => 0,
						'embeds'   => 0,
						'failures' => array(),
						'choices'  => $choices,
						'started'  => time(),
						'finished' => 0,
					);
				}
				$cursor['user'] = $user;
				self::save_cursor( $run, $cursor );
				return $cursor;
			}
		);
		if ( is_wp_error( $cursor ) ) {
			return $cursor;
		}
		if ( $cursor['done'] ) {
			return self::release() ? true : self::unreleased();
		}
		$choices = $cursor['choices'] ?? array();
		$reports = Migration_Store::after( $run, $cursor['after'], self::SWAP_POSTS );
		foreach ( $reports as $report ) {
			$post_id = (int) $report['post_id'];
			$chosen  = $choices[ $post_id ] ?? array();
			if ( self::swappable( $report, $chosen ) ) {
				$result = $this->migrator->swap( $post_id, $chosen, $run );
				if ( is_wp_error( $result ) ) {
					// Stop the run, without moving on, when it can't go on at all. A post this
					// admin can't edit is that post's failure; the rest carry on.
					if ( in_array( $result->get_error_code(), array( 'showfm_not_connected', 'showfm_busy', 'showfm_stale_run' ), true ) || ( 'showfm_forbidden' === $result->get_error_code() && ! current_user_can( 'manage_options' ) ) ) {
						self::save_cursor( $run, $cursor );
						return $result;
					}
					$cursor['failures'][ $post_id ] = 'showfm_forbidden' === $result->get_error_code() ? __( 'You can’t edit this post, so it wasn’t changed.', 'showfm' ) : $result->get_error_message();
				} elseif ( 'swapped' === ( $result['status'] ?? '' ) ) {
					++$cursor['posts'];
					$cursor['embeds'] += count( $result['swapped'] ?? array() );
				}
				++$cursor['checked'];
			}
			$cursor['after'] = $post_id;
			self::save_cursor( $run, $cursor );
		}
		if ( count( $reports ) < self::SWAP_POSTS ) {
			$cursor['done']     = true;
			$cursor['finished'] = time();
			self::save_cursor( $run, $cursor );
			return self::release() ? true : self::unreleased();
		}
		return true;
	}

	/**
	 * Stores or clears the admin's pick for one ambiguous embed of the finished scan.
	 *
	 * @param string $run     Run the screen shows.
	 * @param int    $post_id Post ID.
	 * @param int    $embed   One-based embed number in the post.
	 * @param string $episode Episode UUID from the embed's candidates, or '' to clear.
	 * @return string|\WP_Error The stored pick, or '' when cleared.
	 */
	public function choose( string $run, int $post_id, int $embed, string $episode ) {
		$access = $this->migrator->access();
		if ( is_wp_error( $access ) ) {
			return $access;
		}
		// Checked and written under the lock the swap's first step freezes the picks under.
		return Migration_Store::locked(
			static function () use ( $run, $post_id, $embed, $episode ) {
				$state = Migration_Store::state();
				if ( ( $state['run'] ?? '' ) !== $run || empty( $state['complete'] ) ) {
					return self::stale();
				}
				if ( self::cursor( $run ) ) {
					return self::error( 'swapped', 409, __( 'The swap has started, so choices can’t change. Scan again to review the rest.', 'showfm' ) );
				}
				$report = Migration_Store::get( $run, $post_id );
				$valid  = false;
				foreach ( 'scanned' === ( $report['status'] ?? '' ) ? $report['items'] : array() as $item ) {
					if ( $embed === $item['embed'] && 'ambiguous' === $item['status'] ) {
						$valid = '' === $episode || in_array( $episode, array_column( $item['candidates'], 'id' ), true );
					}
				}
				if ( ! $valid ) {
					return self::error( 'invalid_choice', 400, __( 'Choose one of the episodes listed for this embed.', 'showfm' ) );
				}
				$choices = self::choices( $run );
				if ( '' === $episode ) {
					unset( $choices[ $post_id ][ $embed ] );
				} else {
					$choices[ $post_id ][ $embed ] = $episode;
				}
				update_option( self::choices_key( $run ), array_filter( $choices ), false );
				wp_cache_delete( self::choices_key( $run ), 'options' );
				return $episode;
			},
			self::WAIT
		);
	}

	/**
	 * Pauses the scan: gives the run back, so another admin can start one, and remembers the
	 * pause, so a reload doesn't carry on by itself. Only the admin holding the run can.
	 *
	 * @return bool False when the run couldn't be given back (see `release()`).
	 */
	public static function stop(): bool {
		$holder = self::holder();
		if ( null !== $holder && get_current_user_id() !== $holder['user'] ) {
			return true;
		}
		$pending = self::fresh( Migration_Catalogue::PENDING );
		$run     = (string) ( $pending['run'] ?? Migration_Store::state()['run'] ?? '' );
		if ( '' !== $run ) {
			$progress = self::progress( $run );
			self::save_progress( $run, $progress['found'], $progress['finished'], true );
		}
		return self::release();
	}

	/**
	 * Everything the tab needs to draw the current step, read fresh.
	 *
	 * @return array<string,mixed>
	 */
	public function view(): array {
		$pending = self::fresh( Migration_Catalogue::PENDING );
		$state   = Migration_Store::state();
		$holder  = self::holder();
		$view    = array(
			'connected'  => $this->migrator->connected(),
			'connectUrl' => add_query_arg( 'tab', 'connection', Connect::settings_url() ),
			'hosts'      => array_values( self::HOSTS ),
			'posts'      => self::count( PHP_INT_MAX, PHP_INT_MAX, 0 ),
			'lease'      => null === $holder ? null : array(
				'mine' => get_current_user_id() === $holder['user'],
				'name' => self::name( $holder['user'] ),
			),
			'phase'      => 'intro',
			'run'        => '',
			'scan'       => null,
			'report'     => null,
			'swap'       => null,
			'problem'    => null,
		);
		if ( $pending ) {
			$upper           = Migration_Scanner::upper_bound( (int) ( $pending['post_id'] ?? 0 ) );
			$view['phase']   = 'scanning';
			$view['run']     = (string) ( $pending['run'] ?? '' );
			$view['scan']    = array(
				'checked'   => 0,
				'total'     => self::count( $upper, $upper, (int) ( $pending['post_id'] ?? 0 ) ),
				'found'     => 0,
				'catalogue' => true,
				'stopped'   => self::progress( $view['run'] )['stopped'],
			);
			$view['problem'] = self::waiting( $pending );
			return $view;
		}
		if ( ! $state ) {
			return $view;
		}
		$run         = (string) $state['run'];
		$view['run'] = $run;
		if ( empty( $state['complete'] ) ) {
			$view['phase'] = 'scanning';
			$view['scan']  = array(
				'checked'   => self::count( (int) $state['after'], (int) $state['upper'], (int) $state['post_id'] ),
				'total'     => self::count( (int) $state['upper'], (int) $state['upper'], (int) $state['post_id'] ),
				'found'     => self::progress( $run )['found'],
				'catalogue' => false,
				'stopped'   => self::progress( $run )['stopped'],
			);
			return $view;
		}
		$cursor = self::cursor( $run );
		if ( $cursor ) {
			$view['swap'] = array(
				'total'    => $cursor['total'],
				'checked'  => $cursor['checked'],
				'posts'    => $cursor['posts'],
				'embeds'   => $cursor['embeds'],
				'failed'   => count( $cursor['failures'] ),
				'mine'     => get_current_user_id() === (int) ( $cursor['user'] ?? 0 ),
				'by'       => self::name( (int) ( $cursor['user'] ?? 0 ) ),
				'finished' => $cursor['finished'] ? Sync_Activity::when( $cursor['finished'] ) : '',
			);
			if ( ! $cursor['done'] ) {
				$view['phase'] = 'swapping';
				return $view;
			}
		}
		$summary        = self::summary( $run );
		$finished       = self::progress( $run )['finished'];
		$summary['ago'] = Admin_Status::ago( $finished ? $finished : (int) ( $state['created_at'] ?? time() ) );
		$view['report'] = $summary;
		if ( ! $cursor && ! $this->migrator->owns( $state ) && $this->migrator->connected() ) {
			$view['problem'] = self::problem( self::reconnected() );
		}
		if ( $cursor ) {
			$view['phase'] = 'results';
		} elseif ( 0 === $summary['counts']['ready'] + $summary['counts']['choose'] + $summary['counts']['unmatched'] + $summary['counts']['review'] ) {
			$view['phase'] = 'empty';
		} else {
			$view['phase'] = 'report';
		}
		return $view;
	}

	/**
	 * One page of rows for a report group, or for the results (`changed`, `failed`).
	 *
	 * @param string $run    Run the screen shows.
	 * @param string $group  Group.
	 * @param int    $offset Rows to skip.
	 * @param int    $limit  Most rows.
	 * @return array{rows:array<int,array<string,mixed>>,total:int}|\WP_Error
	 */
	public function rows( string $run, string $group, int $offset, int $limit ) {
		$state = Migration_Store::state();
		if ( ( $state['run'] ?? '' ) !== $run || empty( $state['complete'] ) ) {
			return self::stale();
		}
		$rows  = array();
		$total = 0;
		$keep  = static function ( array $row ) use ( &$rows, &$total, $offset, $limit ): void {
			if ( $total >= $offset && count( $rows ) < $limit ) {
				$rows[] = $row;
			}
			++$total;
		};
		if ( 'failed' === $group ) {
			foreach ( self::cursor( $run )['failures'] ?? array() as $post_id => $reason ) {
				$keep(
					array(
						'id'     => (string) $post_id,
						'post'   => self::post( (int) $post_id ),
						'reason' => $reason,
					)
				);
			}
			return array(
				'rows'  => $rows,
				'total' => $total,
			);
		}
		$choices = self::choices( $run );
		foreach ( Migration_Store::reports( $run ) as $report ) {
			if ( 'changed' === $group ) {
				if ( 'swapped' === $report['status'] ) {
					$keep(
						array(
							'id'          => (string) $report['post_id'],
							'post'        => self::post( (int) $report['post_id'] ),
							'change'      => self::change( $report ),
							'revisionUrl' => (string) ( $report['revision_url'] ?? '' ),
						)
					);
				}
				continue;
			}
			if ( ! $report['items'] ) {
				if ( 'review' === $group ) {
					$keep( self::row( $report, null, $choices ) );
				}
				continue;
			}
			foreach ( $report['items'] as $item ) {
				if ( self::group( $report, $item ) === $group ) {
					$keep( self::row( $report, $item, $choices ) );
				}
			}
		}
		return array(
			'rows'  => $rows,
			'total' => $total,
		);
	}

	/**
	 * Turns an engine or step error into one the tab can explain, with an HTTP status and a
	 * `reason`: not_connected, connection_lost, busy, stale, rate_limited, unreachable,
	 * reconnected, swapped, invalid_choice, confirm, unreleased, forbidden or failed.
	 *
	 * @param \WP_Error $error   Error.
	 * @param bool      $running Whether a scan or swap was under way.
	 */
	public static function explain( \WP_Error $error, bool $running = false ): \WP_Error {
		$code   = (string) $error->get_error_code();
		$data   = $error->get_error_data();
		$data   = is_array( $data ) ? $data : array();
		$reason = (string) ( $data['reason'] ?? '' );
		switch ( $code ) {
			case 'showfm_not_connected':
				return $running ? self::error( 'connection_lost', 409, __( 'The connection to show.fm was lost part way through. Nothing else has changed. Reconnect, then carry on.', 'showfm' ) ) : self::error( 'not_connected', 409, __( 'Connect this site to show.fm to swap old embeds. Matching needs your connected shows.', 'showfm' ) );
			case 'showfm_forbidden':
				return self::error( 'forbidden', 403, __( 'Only a site administrator who can edit these posts can migrate embeds.', 'showfm' ) );
			case 'showfm_busy':
				return self::error( 'busy', 409, __( 'Another scan or swap is running on this site, perhaps from WP-CLI. Try again when it finishes.', 'showfm' ) );
			case 'showfm_stale_run':
			case 'showfm_scan_required':
			case 'showfm_pending_scan':
				return self::stale();
			case 'showfm_backoff':
			case 'showfm_catalogue':
				$retry = (int) ( $data['retry_at'] ?? 0 );
				if ( Api_Result::UNAUTHORISED === $reason ) {
					return self::error( 'connection_lost', 409, __( 'show.fm no longer accepts this site’s key. Reconnect, then scan again.', 'showfm' ) );
				}
				if ( Api_Result::RATE_LIMITED === $reason ) {
					return self::error(
						'rate_limited',
						503,
						/* translators: %s: time of day, for example "10:42". */
						sprintf( __( 'show.fm is limiting requests from this site. Your progress is saved. Try again after %s.', 'showfm' ), (string) wp_date( (string) get_option( 'time_format' ), $retry ) ),
						$retry
					);
				}
				return self::error( 'unreachable', 503, __( 'We couldn’t reach show.fm to list your episodes. Your progress is saved. Try again in a moment.', 'showfm' ), $retry );
			default:
				if ( isset( $data['reason'], $data['status'] ) ) {
					return $error;
				}
				return self::error( 'failed', 500, $error->get_error_message() );
		}
	}

	/**
	 * Whether a scan or swap is under way, so a lost connection reads as lost mid-run.
	 */
	public static function running(): bool {
		$state  = Migration_Store::state();
		$cursor = isset( $state['run'] ) ? self::cursor( (string) $state['run'] ) : array();
		return (bool) self::fresh( Migration_Catalogue::PENDING ) || ( $state && empty( $state['complete'] ) ) || ( $cursor && ! $cursor['done'] );
	}

	/**
	 * Checks access and takes the lease for this admin.
	 *
	 * @return true|\WP_Error
	 */
	private function begin() {
		$access = $this->migrator->access();
		if ( is_wp_error( $access ) ) {
			return $access;
		}
		return self::claim();
	}

	/**
	 * Takes or renews the lease for the current user, unless another admin's is live. The
	 * tab's steps and each WP-CLI batch and swap call this.
	 *
	 * @return true|\WP_Error
	 */
	public static function claim() {
		$user = get_current_user_id();
		return Migration_Store::locked(
			static function () use ( $user ) {
				$holder = self::holder();
				if ( null !== $holder && $holder['user'] !== $user ) {
					return self::error(
						'busy',
						409,
						/* translators: %s: the name of the admin running the scan or swap. */
						sprintf( __( '%s is already running a scan or swap. Try again when it finishes.', 'showfm' ), self::name( $holder['user'] ) )
					);
				}
				update_option(
					self::LEASE,
					array(
						'user'  => $user,
						'until' => time() + self::LEASE_TTL,
					),
					false
				);
				wp_cache_delete( self::LEASE, 'options' );
				return true;
			},
			self::WAIT
		);
	}

	/**
	 * Gives the lease back if the current user holds it. It waits up to `WAIT` seconds for a
	 * request holding the site lock.
	 *
	 * @return bool False when the lock stayed busy, so the lease still stands until it lapses.
	 */
	public static function release(): bool {
		$user   = get_current_user_id();
		$result = Migration_Store::locked(
			static function () use ( $user ) {
				$holder = self::holder();
				if ( null !== $holder && $holder['user'] === $user ) {
					delete_option( self::LEASE );
				}
				return true;
			},
			self::WAIT
		);
		return true === $result;
	}

	/** The run finished, but the lease couldn't be given back. */
	private static function unreleased(): \WP_Error {
		return self::error( 'unreleased', 503, __( 'Done, but another request held the site lock, so other admins may have to wait up to two minutes to start a scan.', 'showfm' ) );
	}

	/**
	 * The live lease, or null.
	 *
	 * @return array{user:int,until:int}|null
	 */
	private static function holder(): ?array {
		$lease = self::fresh( self::LEASE );
		if ( ! isset( $lease['user'], $lease['until'] ) || (int) $lease['until'] <= time() ) {
			return null;
		}
		return array(
			'user'  => (int) $lease['user'],
			'until' => (int) $lease['until'],
		);
	}

	/**
	 * Counts all reports of a finished scan by group, and what a swap would change.
	 *
	 * @param string $run Run UUID.
	 * @return array<string,mixed>
	 */
	private static function summary( string $run ): array {
		$counts  = array_fill_keys( self::GROUPS, 0 );
		$embeds  = 0;
		$posts   = 0;
		$chosen  = 0;
		$swap    = 0;
		$touched = 0;
		$choices = self::choices( $run );
		foreach ( Migration_Store::reports( $run ) as $report ) {
			if ( ! $report['items'] ) {
				++$counts['review'];
				continue;
			}
			++$posts;
			$picked = $choices[ $report['post_id'] ] ?? array();
			$before = $swap;
			foreach ( $report['items'] as $item ) {
				++$embeds;
				$group = self::group( $report, $item );
				++$counts[ $group ];
				if ( 'choose' === $group && isset( $picked[ $item['embed'] ] ) ) {
					++$chosen;
				}
				if ( 'scanned' === $report['status'] && ( 'ready' === $group || ( 'choose' === $group && isset( $picked[ $item['embed'] ] ) ) ) ) {
					++$swap;
				}
			}
			$touched += $swap > $before ? 1 : 0;
		}
		return array(
			'counts' => $counts,
			'embeds' => $embeds,
			'posts'  => $posts,
			'chosen' => $chosen,
			'swap'   => array(
				'embeds' => $swap,
				'posts'  => $touched,
			),
		);
	}

	/**
	 * Which group an embed belongs to.
	 *
	 * @param array<string,mixed> $report Post report.
	 * @param array<string,mixed> $item   Embed.
	 */
	private static function group( array $report, array $item ): string {
		if ( ! in_array( $report['status'], array( 'scanned', 'swapped' ), true ) ) {
			return 'review';
		}
		$groups = array(
			'matched'        => 'ready',
			'ambiguous'      => 'choose',
			'unmatched'      => 'unmatched',
			'already_showfm' => 'already',
		);
		return $groups[ $item['status'] ] ?? 'review';
	}

	/**
	 * How an embed matched, in the report's words.
	 *
	 * @param string $method Engine method: enclosure, guid, title_date or ''.
	 */
	public static function method( string $method ): string {
		$methods = array(
			'enclosure'  => __( 'Audio file', 'showfm' ),
			'guid'       => __( 'Episode ID', 'showfm' ),
			'title_date' => __( 'Title and date', 'showfm' ),
		);
		return $methods[ $method ] ?? __( 'None', 'showfm' );
	}

	/**
	 * The report's labels for one embed, for the WP-CLI table: host, status and method.
	 *
	 * @param array<string,mixed> $report Post report.
	 * @param array<string,mixed> $item   Embed.
	 * @return array{host:string,status:string,method:string}
	 */
	public static function labels( array $report, array $item ): array {
		$group    = self::group( $report, $item );
		$statuses = array(
			'ready'     => __( 'Ready', 'showfm' ),
			'choose'    => __( 'Choose one', 'showfm' ),
			'unmatched' => __( 'Left as is', 'showfm' ),
			'already'   => __( 'Nothing to do', 'showfm' ),
			'review'    => __( 'Check by hand', 'showfm' ),
		);
		$swapped  = 'swapped' === $report['status'] && in_array( $item['embed'], $report['swapped'] ?? array(), true );
		return array(
			'host'   => 'showfm' === $item['host'] ? __( 'show.fm block', 'showfm' ) : ( self::HOSTS[ $item['host'] ] ?? (string) $item['host'] ),
			'status' => $swapped ? __( 'Swapped', 'showfm' ) : $statuses[ $group ],
			'method' => 'already' === $group ? __( 'None', 'showfm' ) : self::method( (string) $item['method'] ),
		);
	}

	/**
	 * A report row: the post, the embed, how it matched, and what happens to it.
	 *
	 * @param array<string,mixed>          $report  Post report.
	 * @param array<string,mixed>|null     $item    Embed, or null for a post that couldn't be checked.
	 * @param array<int,array<int,string>> $choices Picks by post and embed.
	 * @return array<string,mixed>
	 */
	private static function row( array $report, ?array $item, array $choices ): array {
		$post_id = (int) $report['post_id'];
		if ( null === $item ) {
			$reason = 'forbidden' === $report['status'] ? __( 'You can’t edit this post.', 'showfm' ) : (string) ( $report['error'] ?? __( 'This post couldn’t be checked.', 'showfm' ) );
			return array(
				'id'     => $post_id . ':0',
				'post'   => self::post( $post_id ),
				'embed'  => 0,
				'host'   => '',
				'ref'    => '',
				'group'  => 'review',
				'status' => 'review',
				'reason' => $reason,
			);
		}
		$group  = self::group( $report, $item );
		$labels = self::labels( $report, $item );
		$row    = array(
			'id'      => $post_id . ':' . $item['embed'],
			'post'    => self::post( $post_id ),
			'embed'   => (int) $item['embed'],
			'host'    => $labels['host'],
			'ref'     => self::reference( $item ),
			'group'   => $group,
			'status'  => $group,
			'method'  => $labels['method'],
			'episode' => 'ready' === $group ? (string) ( $item['candidates'][0]['title'] ?? '' ) : '',
		);
		if ( 'swapped' === $report['status'] && in_array( $item['embed'], $report['swapped'] ?? array(), true ) ) {
			$row['status'] = 'swapped';
		}
		if ( 'review' === $group ) {
			$row['reason'] = 'ambiguous' === $report['status'] ? __( 'Two players share one block, so this post needs editing by hand.', 'showfm' ) : (string) ( $report['error'] ?? '' );
		}
		if ( 'choose' === $group ) {
			$row['choice']     = (string) ( $choices[ $post_id ][ $item['embed'] ] ?? '' );
			$row['candidates'] = array_map(
				static function ( array $candidate ): array {
					$time = strtotime( (string) ( $candidate['published_at'] ?? '' ) );
					return array(
						'id'    => (string) $candidate['id'],
						'title' => (string) $candidate['title'],
						'date'  => false === $time ? '' : self::day( $time ),
					);
				},
				$item['candidates']
			);
			if ( $row['choice'] ) {
				$row['status'] = 'swapped' === $row['status'] ? 'swapped' : 'chosen';
			}
			// Only an embed in a cleanly scanned post can be swapped, so only it is a pick.
			$row['canChoose'] = 'scanned' === $report['status'];
		}
		return $row;
	}

	/**
	 * What a swap changed in a post: "Buzzsprout embed → show.fm Player".
	 *
	 * @param array<string,mixed> $report Swapped post report.
	 */
	private static function change( array $report ): string {
		$swapped = $report['swapped'] ?? array();
		if ( count( $swapped ) > 1 ) {
			/* translators: %d: number of embeds, always more than one. */
			return sprintf( _n( '%d embed → show.fm Player', '%d embeds → show.fm Player', count( $swapped ), 'showfm' ), count( $swapped ) );
		}
		foreach ( $report['items'] as $item ) {
			if ( in_array( $item['embed'], $swapped, true ) ) {
				$host = self::HOSTS[ $item['host'] ] ?? $item['host'];
				if ( 'content' !== ( $item['source'] ?? 'content' ) ) {
					/* translators: %s: podcast host, for example "PowerPress". */
					return sprintf( __( '%s episode file → show.fm Player', 'showfm' ), $host );
				}
				if ( 'powerpress' === $item['host'] ) {
					/* translators: %s: podcast host, for example "PowerPress". */
					return sprintf( __( '%s shortcode → show.fm Player', 'showfm' ), $host );
				}
				if ( 'ssp' === $item['host'] ) {
					/* translators: %s: podcast host, for example "Seriously Simple Podcasting". */
					return sprintf( __( '%s player → show.fm Player', 'showfm' ), $host );
				}
				/* translators: %s: podcast host, for example "Buzzsprout". */
				return sprintf( __( '%s embed → show.fm Player', 'showfm' ), $host );
			}
		}
		return __( 'Embed → show.fm Player', 'showfm' );
	}

	/**
	 * A short, safe reference for an embed: the player address and episode ID, or the audio
	 * file's name. Never a whole URL, so no query tokens reach the screen.
	 *
	 * @param array<string,mixed> $item Embed.
	 */
	private static function reference( array $item ): string {
		if ( 'showfm' === $item['host'] ) {
			return 'showfm/player';
		}
		if ( 'content' !== ( $item['source'] ?? 'content' ) ) {
			/* translators: %s: post field name, for example "enclosure". */
			return sprintf( __( '%s field', 'showfm' ), (string) $item['source'] );
		}
		$audio = is_string( $item['audio_url'] ?? null ) ? (string) wp_parse_url( $item['audio_url'], PHP_URL_PATH ) : '';
		if ( '' !== $audio && 'powerpress' === $item['host'] ) {
			return '[powerpress url=…' . self::tail( $audio ) . ']';
		}
		if ( '' !== $audio ) {
			return '…' . self::tail( $audio );
		}
		$id = is_string( $item['episode_id'] ?? null ) ? $item['episode_id'] : '';
		if ( 'ssp' === $item['host'] && '' !== $id ) {
			return '[ss_player id="' . $id . '"]';
		}
		if ( isset( self::DOMAINS[ $item['host'] ] ) ) {
			return self::DOMAINS[ $item['host'] ] . '/…' . ( '' !== $id ? '/' . self::cut( $id ) : '' );
		}
		return '';
	}

	/**
	 * The last path segment of an audio file, with its slash.
	 *
	 * @param string $path URL path.
	 */
	private static function tail( string $path ): string {
		$name = basename( $path );
		return '/' . self::cut( '' === $name ? $path : $name );
	}

	/**
	 * Cuts a long identifier: "4kQ…".
	 *
	 * @param string $text Text.
	 */
	private static function cut( string $text ): string {
		return strlen( $text ) > 24 ? substr( $text, 0, 12 ) . '…' : $text;
	}

	/**
	 * A post's title, link and date for a report row.
	 *
	 * @param int $post_id Post ID.
	 * @return array{id:int,title:string,url:string,date:string}
	 */
	private static function post( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array(
				'id'    => $post_id,
				/* translators: %d: post ID. */
				'title' => sprintf( __( 'Deleted post %d', 'showfm' ), $post_id ),
				'url'   => '',
				'date'  => '',
			);
		}
		$title = wp_strip_all_tags( get_the_title( $post ) );
		return array(
			'id'    => $post_id,
			/* translators: %d: post ID. */
			'title' => '' === $title ? sprintf( __( 'Untitled post %d', 'showfm' ), $post_id ) : html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			'url'   => (string) get_permalink( $post ),
			'date'  => self::day( (int) get_post_time( 'U', true, $post ) ),
		);
	}

	/**
	 * A short date: "24 Sept 2026".
	 *
	 * @param int $timestamp Unix time.
	 */
	private static function day( int $timestamp ): string {
		return (string) wp_date( _x( 'j M Y', 'migration report date', 'showfm' ), $timestamp );
	}

	/**
	 * Whether a stored report has anything the swap would change.
	 *
	 * @param array<string,mixed> $report Post report.
	 * @param array<int,string>   $picked Picks for this post, by embed.
	 */
	private static function swappable( array $report, array $picked ): bool {
		if ( 'scanned' !== $report['status'] ) {
			return false;
		}
		foreach ( $report['items'] as $item ) {
			if ( 'matched' === $item['status'] || ( 'ambiguous' === $item['status'] && isset( $picked[ $item['embed'] ] ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Published posts and pages up to `$upper`, counted up to `$after`, as the scanner sees them.
	 *
	 * @param int $after   Count IDs up to here.
	 * @param int $upper   The scan's upper bound.
	 * @param int $post_id Single-post restriction, or zero.
	 */
	private static function count( int $after, int $upper, int $post_id ): int {
		global $wpdb;
		$after = min( $after, $upper );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Live progress count, same filter as Migration_Scanner::ids().
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID <= %d AND (%d = 0 OR ID = %d) AND post_status = 'publish' AND post_type IN ('post','page')", $after, $post_id, $post_id ) );
	}

	/**
	 * The problem a pending catalogue is waiting on, if it is waiting.
	 *
	 * @param array<string,mixed> $pending Pending catalogue state.
	 * @return array<string,mixed>|null
	 */
	private static function waiting( array $pending ): ?array {
		$retry = (int) ( $pending['retry_at'] ?? 0 );
		// Once the retry time has passed, the scan may carry on, so there is nothing to say.
		if ( $retry <= time() ) {
			return null;
		}
		return self::problem(
			self::explain(
				new \WP_Error(
					'showfm_backoff',
					'',
					array(
						'retry_at' => $retry,
						'reason'   => (string) ( $pending['reason'] ?? '' ),
					)
				),
				true
			)
		);
	}

	/**
	 * A REST error the tab explains by its `reason`.
	 *
	 * @param string $reason  Reason.
	 * @param int    $status  HTTP status.
	 * @param string $message Message.
	 * @param int    $retry   When to try again, as a Unix time, or zero.
	 */
	private static function error( string $reason, int $status, string $message, int $retry = 0 ): \WP_Error {
		return new \WP_Error(
			'showfm_migration_' . $reason,
			$message,
			array(
				'status'  => $status,
				'reason'  => $reason,
				'retryAt' => $retry,
			)
		);
	}

	/**
	 * The error's message and reason, for the view.
	 *
	 * @param \WP_Error $error Explained error.
	 * @return array{reason:string,message:string,retryAt:int}
	 */
	public static function problem( \WP_Error $error ): array {
		$data = (array) $error->get_error_data();
		return array(
			'reason'  => (string) ( $data['reason'] ?? 'failed' ),
			'message' => $error->get_error_message(),
			'retryAt' => (int) ( $data['retryAt'] ?? 0 ),
		);
	}

	/** The screen showed a run that has since changed. */
	private static function stale(): \WP_Error {
		return self::error( 'stale', 409, __( 'The scan changed in another tab or by another admin. This is the latest.', 'showfm' ) );
	}

	/** The site reconnected with another key since this scan. */
	private static function reconnected(): \WP_Error {
		return self::error( 'reconnected', 409, __( 'This site reconnected to show.fm after the scan. Scan again before swapping.', 'showfm' ) );
	}

	/**
	 * A user's display name, or a stand-in.
	 *
	 * @param int $user_id User ID.
	 */
	private static function name( int $user_id ): string {
		$user = get_userdata( $user_id );
		return false === $user ? __( 'Another admin', 'showfm' ) : $user->display_name;
	}

	/**
	 * Found embeds and finish time for a run.
	 *
	 * @param string $run Run UUID.
	 * @return array{found:int,finished:int,stopped:bool}
	 */
	private static function progress( string $run ): array {
		$progress = self::fresh( self::PROGRESS );
		if ( ( $progress['run'] ?? '' ) !== $run ) {
			return array(
				'found'    => 0,
				'finished' => 0,
				'stopped'  => false,
			);
		}
		return array(
			'found'    => (int) ( $progress['found'] ?? 0 ),
			'finished' => (int) ( $progress['finished'] ?? 0 ),
			'stopped'  => ! empty( $progress['stopped'] ),
		);
	}

	/**
	 * Saves the found count, finish time and pause for a run.
	 *
	 * @param string $run      Run UUID.
	 * @param int    $found    Embeds found.
	 * @param int    $finished Finish time, or zero.
	 * @param bool   $stopped  Whether the admin paused the scan.
	 */
	private static function save_progress( string $run, int $found, int $finished, bool $stopped = false ): void {
		update_option(
			self::PROGRESS,
			array(
				'run'      => $run,
				'found'    => $found,
				'finished' => $finished,
				'stopped'  => $stopped,
			),
			false
		);
		wp_cache_delete( self::PROGRESS, 'options' );
	}

	/**
	 * Picks for a run, by post and embed.
	 *
	 * @param string $run Run UUID.
	 * @return array<int,array<int,string>>
	 */
	private static function choices( string $run ): array {
		return self::fresh( self::choices_key( $run ) );
	}

	/**
	 * Option holding a run's picks. It sits under the run's prefix, so a new scan or a
	 * discarded run removes it with the reports.
	 *
	 * @param string $run Run UUID.
	 */
	private static function choices_key( string $run ): string {
		return 'showfm_migration_' . $run . '_choices';
	}

	/**
	 * A run's swap cursor, or an empty array before the swap starts.
	 *
	 * @param string $run Run UUID.
	 * @return array<string,mixed>
	 */
	private static function cursor( string $run ): array {
		return self::fresh( 'showfm_migration_' . $run . '_swap' );
	}

	/**
	 * Saves a run's swap cursor.
	 *
	 * @param string              $run    Run UUID.
	 * @param array<string,mixed> $cursor Cursor.
	 */
	private static function save_cursor( string $run, array $cursor ): void {
		update_option( 'showfm_migration_' . $run . '_swap', $cursor, false );
		wp_cache_delete( 'showfm_migration_' . $run . '_swap', 'options' );
	}

	/**
	 * An array option read from the database, not a cache another request may have outdated.
	 *
	 * @param string $name Option name.
	 * @return array<mixed>
	 */
	private static function fresh( string $name ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Steps from other requests change these rows.
		$value = maybe_unserialize( (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ) );
		return is_array( $value ) ? $value : array();
	}
}
