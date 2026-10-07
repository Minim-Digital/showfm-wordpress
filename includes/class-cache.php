<?php
/**
 * Transient cache for public API data.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Caches public API responses in transients.
 *
 * - Reads never make an HTTP call. A stale or missing entry schedules a WP-Cron refresh
 *   with 0 to 120 seconds of jitter, so many sites on one host do not fire together.
 * - Data is fresh for 15 minutes and served stale for up to 7 days.
 * - A 403 or 404 stores an "unavailable" marker. Callers then render nothing, never a
 *   snapshot, so an unpublished episode's audio URL does not linger in page HTML.
 * - Network errors, 429 and 5xx keep the last good copy and back off, doubling up to an hour.
 * - Keys carry a version. Flushing bumps the version; it never calls wp_cache_flush().
 */
final class Cache {

	/** WP-Cron hook that refreshes one entry. */
	const REFRESH_HOOK = 'showfm_cache_refresh';

	/** Option holding the key version. */
	const VERSION_OPTION = 'showfm_cache_version';

	/** Data is fresh for 15 minutes. */
	const FRESH_FOR = 900;

	/** Stale data is served for up to 7 days, then the transient expires. */
	const STALE_FOR = 604800;

	/** Largest random delay added to a scheduled refresh, in seconds. */
	const MAX_JITTER = 120;

	/** First back-off after a failure, in seconds. */
	const MIN_BACKOFF = 60;

	/** Longest back-off, in seconds. */
	const MAX_BACKOFF = 3600;

	/** Entry has data. */
	const STATE_OK = 'ok';

	/** The API said the resource is not available (403 or 404). */
	const STATE_UNAVAILABLE = 'unavailable';

	/** Nothing fetched yet; the entry only records back-off. */
	const STATE_PENDING = 'pending';

	/**
	 * Client used by the cron refresh. Never used on a read.
	 *
	 * @var Api_Client
	 */
	private $api_client;

	/**
	 * Builds the cache.
	 *
	 * @param Api_Client $api_client API client for refreshes.
	 */
	public function __construct( Api_Client $api_client ) {
		$this->api_client = $api_client;
	}

	/**
	 * Data for rendering, or null when there is nothing to render.
	 *
	 * Null means the caller renders nothing (for "unavailable") or its own fallback (for a
	 * miss). Use `is_unavailable()` to tell the two apart before falling back to a snapshot.
	 * Never makes an HTTP call; schedules a refresh when the entry is stale or missing.
	 *
	 * @param string $path Public API path, for example `/v1/episodes/{id}`.
	 * @return mixed
	 */
	public function get( string $path ) {
		$entry = $this->read( $path );

		if ( null === $entry || $this->is_stale( $entry ) ) {
			$this->schedule_refresh( $path, null === $entry ? 0 : $entry['next_attempt'] );
		}

		if ( null === $entry || self::STATE_OK !== $entry['state'] ) {
			return null;
		}
		return $entry['data'];
	}

	/**
	 * Whether the API said this resource is unavailable. Callers must then render nothing,
	 * not a snapshot saved in the block.
	 *
	 * @param string $path Public API path.
	 */
	public function is_unavailable( string $path ): bool {
		$entry = $this->read( $path );
		return null !== $entry && self::STATE_UNAVAILABLE === $entry['state'];
	}

	/**
	 * Refreshes one entry. Runs from WP-Cron only.
	 *
	 * @param mixed $path Public API path, as passed to the cron event.
	 */
	public function refresh( $path ): void {
		if ( ! is_string( $path ) || '' === $path ) {
			return;
		}

		$entry = $this->read( $path );
		if ( null !== $entry && $entry['next_attempt'] > time() ) {
			$this->schedule_refresh( $path, $entry['next_attempt'] );
			return;
		}

		$etag   = null !== $entry && self::STATE_OK === $entry['state'] ? $entry['etag'] : null;
		$result = $this->api_client->get( $path, $etag );
		$now    = time();

		switch ( $result->type() ) {
			case Api_Result::SUCCESS:
				$this->write(
					$path,
					array(
						'state'        => self::STATE_OK,
						'data'         => $result->data(),
						'etag'         => $result->etag(),
						'fetched_at'   => $now,
						'backoff'      => 0,
						'next_attempt' => 0,
					)
				);
				return;

			case Api_Result::NOT_MODIFIED:
				if ( null !== $entry && self::STATE_OK === $entry['state'] ) {
					$entry['fetched_at']   = $now;
					$entry['backoff']      = 0;
					$entry['next_attempt'] = 0;
					$entry['etag']         = null !== $result->etag() ? $result->etag() : $entry['etag'];
					$this->write( $path, $entry );
					return;
				}
				break;

			case Api_Result::UNAVAILABLE:
				$this->write(
					$path,
					array(
						'state'        => self::STATE_UNAVAILABLE,
						'data'         => null,
						'etag'         => null,
						'fetched_at'   => $now,
						'backoff'      => 0,
						'next_attempt' => 0,
					)
				);
				return;
		}

		$this->back_off( $path, $entry, $result );
	}

	/**
	 * Invalidates every entry by bumping the key version. Old transients expire on their own.
	 */
	public function flush(): void {
		update_option( self::VERSION_OPTION, self::version() + 1 );
	}

	/**
	 * Current key version.
	 */
	public static function version(): int {
		return max( 1, (int) get_option( self::VERSION_OPTION, 1 ) );
	}

	/**
	 * Stores the key version if it is missing.
	 */
	public static function ensure_version(): void {
		add_option( self::VERSION_OPTION, 1 );
	}

	/**
	 * Transient name for a path under the current version. Short enough for any install.
	 *
	 * @param string $path Public API path.
	 */
	public static function key( string $path ): string {
		return 'showfm_c' . self::version() . '_' . md5( $path );
	}

	/**
	 * Records a failed refresh: keeps any good copy, doubles the back-off and reschedules.
	 *
	 * @param string                   $path   Public API path.
	 * @param array<string,mixed>|null $entry  Current entry.
	 * @param Api_Result               $result Failed result.
	 */
	private function back_off( string $path, ?array $entry, Api_Result $result ): void {
		$previous = null === $entry ? 0 : (int) $entry['backoff'];
		$backoff  = 0 === $previous ? self::MIN_BACKOFF : $previous * 2;
		if ( $result->is( Api_Result::RATE_LIMITED ) ) {
			$backoff = max( $backoff, $result->retry_after() );
		}
		$backoff = min( self::MAX_BACKOFF, $backoff );

		if ( null === $entry ) {
			$entry = array(
				'state'      => self::STATE_PENDING,
				'data'       => null,
				'etag'       => null,
				'fetched_at' => 0,
			);
		}
		$entry['backoff']      = $backoff;
		$entry['next_attempt'] = time() + $backoff;

		$this->write( $path, $entry );
		wp_clear_scheduled_hook( self::REFRESH_HOOK, array( $path ) );
		$this->schedule_refresh( $path, $entry['next_attempt'] );
	}

	/**
	 * Whether the entry is due a refresh.
	 *
	 * @param array<string,mixed> $entry Entry.
	 */
	private function is_stale( array $entry ): bool {
		return self::STATE_PENDING === $entry['state'] || time() - (int) $entry['fetched_at'] >= self::FRESH_FOR;
	}

	/**
	 * Schedules one refresh for the path unless one is already queued.
	 *
	 * @param string $path      Public API path.
	 * @param int    $not_before Earliest time to run, or 0 for now.
	 */
	private function schedule_refresh( string $path, int $not_before ): void {
		$args = array( $path );
		if ( false !== wp_next_scheduled( self::REFRESH_HOOK, $args ) ) {
			return;
		}
		$start = max( time(), $not_before );
		wp_schedule_single_event( $start + wp_rand( 0, self::MAX_JITTER ), self::REFRESH_HOOK, $args );
	}

	/**
	 * Reads and validates an entry.
	 *
	 * @param string $path Public API path.
	 * @return array<string,mixed>|null
	 */
	private function read( string $path ): ?array {
		$entry = get_transient( self::key( $path ) );
		if (
			! is_array( $entry )
			|| ! in_array( $entry['state'] ?? null, array( self::STATE_OK, self::STATE_UNAVAILABLE, self::STATE_PENDING ), true )
			|| ! array_key_exists( 'data', $entry )
			|| ! isset( $entry['fetched_at'], $entry['backoff'], $entry['next_attempt'] )
		) {
			return null;
		}
		if ( self::STATE_PENDING !== $entry['state'] && time() - (int) $entry['fetched_at'] >= self::STALE_FOR ) {
			return null;
		}
		if ( ! isset( $entry['etag'] ) || ! is_string( $entry['etag'] ) ) {
			$entry['etag'] = null;
		}
		return $entry;
	}

	/**
	 * Writes an entry. Data and markers expire 7 days after they were fetched, however
	 * many failed refreshes follow; a pending entry lives long enough to carry its back-off.
	 *
	 * @param string              $path  Public API path.
	 * @param array<string,mixed> $entry Entry.
	 */
	private function write( string $path, array $entry ): void {
		if ( self::STATE_PENDING === $entry['state'] ) {
			$expires_in = (int) $entry['backoff'] + self::MAX_BACKOFF;
		} else {
			$expires_in = (int) $entry['fetched_at'] + self::STALE_FOR - time();
		}
		if ( $expires_in > 0 ) {
			set_transient( self::key( $path ), $entry, $expires_in );
		}
	}
}
