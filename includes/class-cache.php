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
 * - Keys carry a per-install namespace and version. Flushing bumps the version only.
 */
final class Cache {

	/** WP-Cron hook that refreshes one entry. */
	const REFRESH_HOOK = 'showfm_cache_refresh';

	/** Option holding the key version. */
	const VERSION_OPTION = 'showfm_cache_version';

	/** Option holding the random namespace, removed on uninstall. */
	const NAMESPACE_OPTION = 'showfm_cache_namespace';

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
	 * Refresh-aware episode visibility for a cached list or latest response.
	 *
	 * A fresh public response containing the episode can supersede an older marker
	 * for this render. Keep the marker stored until its own refresh succeeds: list
	 * items are not complete episode payloads, and old sources must still be hidden.
	 * Equal timestamps favour the marker because their ordering is unknown.
	 *
	 * @param mixed  $episode_id Episode UUID in the public response.
	 * @param string $source_path Cache path of that list or latest response.
	 */
	public function is_episode_unavailable( $episode_id, string $source_path ): bool {
		if ( ! is_string( $episode_id ) || '' === $episode_id ) {
			return false;
		}
		$path   = '/v1/episodes/' . $episode_id;
		$marker = $this->read( $path );
		if ( null === $marker || self::STATE_UNAVAILABLE !== $marker['state'] ) {
			return false;
		}
		// This read schedules a stale marker's refresh and honours its existing back-off.
		// Do not fan out requests for episodes without an unavailable marker.
		$this->get( $path );
		$source = $this->read( $source_path );
		if ( null === $source || self::STATE_OK !== $source['state'] || $this->is_stale( $source ) || $source['fetched_at'] <= $marker['fetched_at'] ) {
			return true;
		}
		$data = $source['data']['data'] ?? null;
		if ( ! is_array( $data ) ) {
			return true;
		}
		$episodes = isset( $data['id'] ) ? array( $data ) : $data;
		foreach ( $episodes as $episode ) {
			if ( is_array( $episode ) && ( $episode['id'] ?? null ) === $episode_id ) {
				return false;
			}
		}
		return true;
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
		if ( null !== $entry && self::STATE_PENDING !== $entry['state'] && ! $this->is_stale( $entry ) ) {
			// Still fresh, for example just stored by the sync: nothing to ask.
			return;
		}
		if ( null !== $entry && $entry['next_attempt'] > time() ) {
			$this->schedule_refresh( $path, $entry['next_attempt'] );
			return;
		}

		$etag   = null !== $entry && self::STATE_OK === $entry['state'] ? $entry['etag'] : null;
		$result = $this->api_client->get( $path, $etag );
		$now    = time();

		switch ( $result->type() ) {
			case Api_Result::SUCCESS:
				$this->store( $path, $result, $entry );
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
				if ( null === $entry || self::STATE_UNAVAILABLE !== $entry['state'] ) {
					self::purge_posts( $path );
				}
				return;
		}

		$this->back_off( $path, $entry, $result );
	}

	/**
	 * Stores a successful public API answer the sync already fetched (the artwork step reads
	 * the same `/v1/episodes/{id}` the players render from), so the first render after a post
	 * is created can use it. Also the refresh's own success path.
	 *
	 * @param string                   $path     Public API path.
	 * @param Api_Result               $result   A successful result.
	 * @param array<string,mixed>|null $previous The entry it replaces, if already read.
	 */
	public function store( string $path, Api_Result $result, ?array $previous = null ): void {
		if ( ! $result->is( Api_Result::SUCCESS ) ) {
			return;
		}
		$previous = $previous ?? $this->read( $path );
		$this->write(
			$path,
			array(
				'state'        => self::STATE_OK,
				'data'         => $result->data(),
				'etag'         => $result->etag(),
				'fetched_at'   => time(),
				'backoff'      => 0,
				'next_attempt' => 0,
			)
		);
		if ( null === $previous || self::STATE_OK !== $previous['state'] || $previous['data'] !== $result->data() ) {
			self::purge_posts( $path );
		}
	}

	/**
	 * Cleans the post cache of every post that renders this path, so page-cache plugins that
	 * hook clean_post_cache purge the pages showing the old render (or the cold one, with no
	 * data). Matches synced posts by their episode and other posts by the ID or slug in a
	 * show.fm block or shortcode. Runs only when an entry changed, from WP-Cron or the sync.
	 *
	 * @param string $path Public API path.
	 * @return int[] The posts cleaned.
	 */
	public static function purge_posts( string $path ): array {
		global $wpdb;
		if ( ! preg_match( '~\A/v1/(episodes|podcasts)/([a-z0-9-]+)~i', $path, $match ) ) {
			return array();
		}
		$token = strtolower( $match[2] );
		$name  = 'episodes' === strtolower( $match[1] ) ? 'episode' : 'podcast';
		// A cheap prefilter on the value alone. Each candidate is then confirmed with
		// WordPress's own block and shortcode parsers, so every form the renderer accepts
		// matches and a short slug ("news") never matches a longer one ("newsroom").
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A bounded lookup, run only when a cached entry changes.
		$candidates = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_content FROM {$wpdb->posts} WHERE post_status IN ('publish','future','private') AND post_content LIKE %s LIMIT 200",
				'%' . $wpdb->esc_like( $token ) . '%'
			)
		);
		$ids        = array();
		foreach ( $candidates as $candidate ) {
			if ( self::renders( (string) $candidate->post_content, $name, $token ) ) {
				$ids[] = (int) $candidate->ID;
			}
		}
		$synced = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_showfm_episode_id' AND meta_value = %s LIMIT 200",
				$token
			)
		);
		// phpcs:enable
		$ids = array_values( array_unique( array_map( 'intval', array_merge( $ids, $synced ) ) ) );
		foreach ( $ids as $id ) {
			clean_post_cache( $id );
		}
		return $ids;
	}

	/**
	 * Whether the content has a show.fm block or [showfm] shortcode whose attribute is the
	 * value, read by WordPress's block parser and shortcode regex, as the renderer reads it.
	 *
	 * @param string $content Post content.
	 * @param string $name    Attribute: episode or podcast.
	 * @param string $value   The value, lower case.
	 */
	private static function renders( string $content, string $name, string $value ): bool {
		$blocks = parse_blocks( $content );
		while ( $blocks ) {
			$block = array_shift( $blocks );
			if ( 0 === strpos( (string) $block['blockName'], 'showfm/' ) && is_string( $block['attrs'][ $name ] ?? null ) && strtolower( $block['attrs'][ $name ] ) === $value ) {
				return true;
			}
			$blocks = array_merge( $blocks, $block['innerBlocks'] );
		}
		if ( false === strpos( $content, '[showfm' ) || ! preg_match_all( '/' . get_shortcode_regex( array( 'showfm' ) ) . '/', $content, $found, PREG_SET_ORDER ) ) {
			return false;
		}
		foreach ( $found as $shortcode ) {
			// An empty attribute string comes back as '', not an array.
			$attrs = (array) shortcode_parse_atts( $shortcode[3] );
			if ( is_string( $attrs[ $name ] ?? null ) && strtolower( $attrs[ $name ] ) === $value ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Invalidates every entry, the players' and lists' and the block editor's, by bumping the
	 * key version, then deletes the stored entries it can find. Never calls
	 * wp_cache_flush(), so the rest of the object cache is untouched.
	 *
	 * A site that never cached anything has no namespace yet, and is left untouched.
	 *
	 * @return int Stored entries actually deleted from the options table. With a persistent
	 *             object cache, transients live there instead and simply become unreachable.
	 */
	public function flush(): int {
		global $wpdb;
		$namespace = (string) get_option( self::NAMESPACE_OPTION, '' );
		if ( '' === $namespace ) {
			return 0;
		}
		update_option( self::VERSION_OPTION, self::version() + 1 );
		// Transient names are hashed, so only a query finds them.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cache flush.
		$names   = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_showfm_c' . $namespace . '_' ) . '%'
			)
		);
		$deleted = 0;
		foreach ( $names as $name ) {
			// The rows themselves, so the count is what this removed, with or without a
			// persistent object cache in front of them.
			if ( delete_option( $name ) ) {
				++$deleted;
			}
			delete_option( '_transient_timeout_' . substr( $name, strlen( '_transient_' ) ) );
		}
		return $deleted;
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
	 * Transient name under this installation's namespace and current version.
	 *
	 * @param string $path Public API path.
	 */
	public static function key( string $path ): string {
		$namespace = get_option( self::NAMESPACE_OPTION, '' );
		if ( '' === $namespace ) {
			add_option( self::NAMESPACE_OPTION, wp_generate_uuid4() );
			$namespace = get_option( self::NAMESPACE_OPTION );
		}

		return 'showfm_c' . $namespace . '_' . self::version() . '_' . md5( $path );
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
