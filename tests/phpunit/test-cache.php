<?php
/**
 * Cache behaviour: freshness, stale-while-revalidate, unavailable markers, back-off.
 *
 * @package ShowFM
 */

use ShowFM\Api_Client;
use ShowFM\Cache;
use ShowFM\Connection;

/**
 * Cache tests.
 */
class Test_Cache extends WP_UnitTestCase {

	const PATH = '/v1/episodes/2f1c9a1e-0000-4000-8000-000000000001';

	/**
	 * HTTP mock.
	 *
	 * @var ShowFM_Http_Mock
	 */
	private $http;

	/**
	 * Cache under test.
	 *
	 * @var Cache
	 */
	private $cache;

	public function set_up(): void {
		parent::set_up();
		$this->http  = new ShowFM_Http_Mock();
		$this->cache = new Cache( new Api_Client( new Connection() ) );
		_set_cron_array( array() );
	}

	public function tear_down(): void {
		$this->http->detach();
		parent::tear_down();
	}

	public function test_a_miss_returns_nothing_schedules_a_refresh_and_makes_no_request(): void {
		$before = time();

		$this->assertNull( $this->cache->get( self::PATH ) );

		$this->assertSame( 0, $this->http->count(), 'Reads never make an HTTP call.' );
		$scheduled = wp_next_scheduled( Cache::REFRESH_HOOK, array( self::PATH ) );
		$this->assertIsInt( $scheduled );
		$this->assertGreaterThanOrEqual( $before, $scheduled );
		$this->assertLessThanOrEqual( time() + Cache::MAX_JITTER, $scheduled );
	}

	public function test_repeated_reads_schedule_one_refresh(): void {
		$this->cache->get( self::PATH );
		$this->cache->get( self::PATH );
		$this->cache->get( self::PATH );

		$this->assertSame( 1, $this->count_refresh_events() );
	}

	public function test_fresh_data_is_served_without_scheduling(): void {
		$this->http->respond( 200, '{"title":"Fresh"}', array( 'ETag' => '"e1"' ) );
		$this->cache->refresh( self::PATH );
		_set_cron_array( array() );

		$this->assertSame( array( 'title' => 'Fresh' ), $this->cache->get( self::PATH ) );
		$this->assertSame( 0, $this->count_refresh_events() );
		$this->assertSame( 1, $this->http->count() );
	}

	public function test_stale_data_is_served_while_a_refresh_is_scheduled(): void {
		$this->http->respond( 200, '{"title":"Old"}', array( 'ETag' => '"e1"' ) );
		$this->cache->refresh( self::PATH );
		$this->age_entry( Cache::FRESH_FOR + 1 );
		_set_cron_array( array() );

		$this->assertSame( array( 'title' => 'Old' ), $this->cache->get( self::PATH ) );
		$this->assertSame( 1, $this->count_refresh_events() );
		$this->assertSame( 1, $this->http->count(), 'The stale read itself made no request.' );

		$this->http->respond( 304, '', array( 'ETag' => '"e1"' ) );
		$this->cache->refresh( self::PATH );

		$this->assertSame( '"e1"', $this->http->last()['args']['headers']['If-None-Match'] );
		_set_cron_array( array() );
		$this->assertSame( array( 'title' => 'Old' ), $this->cache->get( self::PATH ) );
		$this->assertSame( 0, $this->count_refresh_events(), 'A 304 makes the entry fresh again.' );
	}

	public function test_data_older_than_seven_days_is_not_served(): void {
		$this->http->respond( 200, '{"title":"Ancient"}' );
		$this->cache->refresh( self::PATH );
		$this->age_entry( Cache::STALE_FOR + 1 );

		$this->assertNull( $this->cache->get( self::PATH ) );
	}

	public function test_404_and_403_store_an_unavailable_marker_that_replaces_data(): void {
		$this->http->respond( 200, '{"title":"Was public","audio_url":"https://m.cdn.media/a.mp3"}' );
		$this->cache->refresh( self::PATH );
		$this->age_entry( Cache::FRESH_FOR + 1 );

		$this->http->respond( 404 );
		$this->cache->refresh( self::PATH );

		$this->assertNull( $this->cache->get( self::PATH ), 'Callers get no data to render.' );
		$this->assertTrue( $this->cache->is_unavailable( self::PATH ) );

		$this->age_entry( Cache::FRESH_FOR + 1 );
		$this->http->respond( 403 );
		$this->cache->refresh( self::PATH );
		$this->assertTrue( $this->cache->is_unavailable( self::PATH ) );
	}

	public function test_a_transient_failure_keeps_the_unavailable_marker(): void {
		$this->http->respond( 404 );
		$this->cache->refresh( self::PATH );
		$this->age_entry( Cache::FRESH_FOR + 1 );

		$this->http->respond( 500 );
		$this->cache->refresh( self::PATH );

		$this->assertTrue( $this->cache->is_unavailable( self::PATH ) );
		$this->assertNull( $this->cache->get( self::PATH ) );
	}

	public function test_network_errors_429_and_5xx_keep_the_last_good_copy_and_back_off(): void {
		$this->http->respond( 200, '{"title":"Good"}' );
		$this->cache->refresh( self::PATH );
		$this->age_entry( Cache::FRESH_FOR + 1 );

		$this->http->fail( 'cURL error 28' );
		$this->cache->refresh( self::PATH );
		$this->assertSame( array( 'title' => 'Good' ), $this->cache->get( self::PATH ) );
		$this->assertSame( 60, $this->entry()['backoff'] );

		$this->rewind_back_off();
		$this->http->respond( 503 );
		$this->cache->refresh( self::PATH );
		$this->assertSame( array( 'title' => 'Good' ), $this->cache->get( self::PATH ) );
		$this->assertSame( 120, $this->entry()['backoff'] );

		$this->rewind_back_off();
		$this->http->respond( 429, '', array( 'Retry-After' => '900' ) );
		$this->cache->refresh( self::PATH );
		$this->assertSame( array( 'title' => 'Good' ), $this->cache->get( self::PATH ) );
		$this->assertSame( 900, $this->entry()['backoff'], 'Retry-After wins when it is longer.' );
		$scheduled = wp_next_scheduled( Cache::REFRESH_HOOK, array( self::PATH ) );
		$this->assertGreaterThanOrEqual( time() + 900, $scheduled );
		$this->assertLessThanOrEqual( time() + 900 + Cache::MAX_JITTER, $scheduled );
	}

	public function test_back_off_is_capped_at_one_hour(): void {
		for ( $i = 0; $i < 10; $i++ ) {
			$this->rewind_back_off();
			$this->http->respond( 500 );
			$this->cache->refresh( self::PATH );
		}

		$this->assertSame( Cache::MAX_BACKOFF, $this->entry()['backoff'] );
		$this->assertNull( $this->cache->get( self::PATH ) );
	}

	public function test_refresh_waits_for_the_back_off_to_pass(): void {
		$this->http->respond( 500 );
		$this->cache->refresh( self::PATH );
		$this->assertSame( 1, $this->http->count() );

		$this->cache->refresh( self::PATH );

		$this->assertSame( 1, $this->http->count(), 'No request inside the back-off window.' );
	}

	public function test_success_clears_the_back_off(): void {
		$this->http->respond( 500 );
		$this->cache->refresh( self::PATH );
		$this->rewind_back_off();

		$this->http->respond( 200, '{"title":"Back"}' );
		$this->cache->refresh( self::PATH );

		$this->assertSame( 0, $this->entry()['backoff'] );
		$this->assertSame( array( 'title' => 'Back' ), $this->cache->get( self::PATH ) );
	}

	public function test_flush_bumps_the_version_without_flushing_the_object_cache(): void {
		$this->http->respond( 200, '{"title":"Before"}' );
		$this->cache->refresh( self::PATH );
		wp_cache_set( 'unrelated', 'kept', 'showfm-test' );
		$version   = Cache::version();
		$old_key   = Cache::key( self::PATH );
		$namespace = get_option( Cache::NAMESPACE_OPTION );

		$this->cache->flush();

		$this->assertSame( $version + 1, Cache::version() );
		$this->assertSame( $namespace, get_option( Cache::NAMESPACE_OPTION ) );
		$this->assertNotSame( $old_key, Cache::key( self::PATH ) );
		$this->assertNull( $this->cache->get( self::PATH ) );
		$this->assertSame( 'kept', wp_cache_get( 'unrelated', 'showfm-test' ) );
	}

	public function test_jitter_stays_within_zero_to_120_seconds(): void {
		for ( $i = 0; $i < 50; $i++ ) {
			$path   = '/v1/episodes/jitter-' . $i;
			$before = time();
			$this->cache->get( $path );
			$scheduled = wp_next_scheduled( Cache::REFRESH_HOOK, array( $path ) );
			$this->assertGreaterThanOrEqual( $before, $scheduled );
			$this->assertLessThanOrEqual( time() + Cache::MAX_JITTER, $scheduled );
		}
	}

	public function test_refresh_ignores_bad_cron_arguments(): void {
		$this->cache->refresh( array( 'not', 'a', 'path' ) );
		$this->cache->refresh( '' );

		$this->assertSame( 0, $this->http->count() );
	}

	public function test_the_cron_hook_is_wired_to_refresh(): void {
		$this->http->respond( 200, '{"title":"From cron"}' );

		do_action( Cache::REFRESH_HOOK, self::PATH );

		$this->assertSame( 1, $this->http->count() );
		$this->assertSame( 'https://api.show.fm' . self::PATH, $this->http->last()['url'] );
	}

	/**
	 * The raw entry.
	 *
	 * @return array<string,mixed>
	 */
	private function entry(): array {
		$entry = get_transient( Cache::key( self::PATH ) );
		$this->assertIsArray( $entry );
		return $entry;
	}

	/**
	 * Moves the entry's fetch time into the past.
	 *
	 * @param int $seconds Age in seconds.
	 */
	private function age_entry( int $seconds ): void {
		$entry               = $this->entry();
		$entry['fetched_at'] = time() - $seconds;
		set_transient( Cache::key( self::PATH ), $entry, DAY_IN_SECONDS );
	}

	/**
	 * Ends the current back-off window so the next refresh calls the API.
	 */
	private function rewind_back_off(): void {
		$entry = get_transient( Cache::key( self::PATH ) );
		if ( is_array( $entry ) ) {
			$entry['next_attempt'] = time() - 1;
			set_transient( Cache::key( self::PATH ), $entry, DAY_IN_SECONDS );
		}
	}

	/**
	 * Number of scheduled refresh events.
	 */
	private function count_refresh_events(): int {
		$count = 0;
		foreach ( _get_cron_array() as $events ) {
			if ( isset( $events[ Cache::REFRESH_HOOK ] ) ) {
				$count += count( $events[ Cache::REFRESH_HOOK ] );
			}
		}
		return $count;
	}
}
