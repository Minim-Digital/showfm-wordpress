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

	public function test_a_changed_entry_cleans_the_post_cache_of_the_posts_that_render_it(): void {
		$episode = '2f1c9a1e-0000-4000-8000-000000000001';
		$block   = self::factory()->post->create( array( 'post_content' => '<!-- wp:showfm/player {"episode":"' . $episode . '"} /-->' ) );
		$code    = self::factory()->post->create( array( 'post_content' => '[showfm episode="' . $episode . '"]' ) );
		$synced  = self::factory()->post->create( array( 'post_content' => 'Synced.' ) );
		update_post_meta( $synced, '_showfm_episode_id', $episode );
		$other   = self::factory()->post->create( array( 'post_content' => 'Mentions ' . $episode . ' without a block.' ) );
		$draft   = self::factory()->post->create(
			array(
				'post_status'  => 'draft',
				'post_content' => '<!-- wp:showfm/player {"episode":"' . $episode . '"} /-->',
			)
		);
		$cleaned = array();
		add_action(
			'clean_post_cache',
			static function ( $id ) use ( &$cleaned ) {
				$cleaned[] = (int) $id;
			}
		);

		$this->http->respond( 200, '{"title":"First"}' );
		$this->cache->refresh( self::PATH );
		sort( $cleaned );
		$expected = array( $block, $code, $synced );
		sort( $expected );
		$this->assertSame( $expected, $cleaned, 'The first fill purges the cold render.' );
		$this->assertNotContains( $other, $cleaned );
		$this->assertNotContains( $draft, $cleaned );

		// A fresh entry isn't asked about again at all.
		$cleaned = array();
		$this->cache->refresh( self::PATH );
		$this->assertSame( 1, $this->http->count() );

		// Once stale, the same answer again changes nothing, so nothing is purged.
		$this->age( self::PATH );
		$this->http->respond( 200, '{"title":"First"}' );
		$this->cache->refresh( self::PATH );
		$this->assertSame( 2, $this->http->count() );
		$this->assertSame( array(), $cleaned );

		// A changed answer, and the episode becoming unavailable, both purge.
		$this->age( self::PATH );
		$this->http->respond( 200, '{"title":"Second"}' );
		$this->cache->refresh( self::PATH );
		$this->assertCount( 3, $cleaned );
		$cleaned = array();
		$this->age( self::PATH );
		$this->http->respond( 404, '{"error":{"code":"not_found"}}' );
		$this->cache->refresh( self::PATH );
		$this->assertCount( 3, $cleaned );
	}

	public function test_a_stale_no_transcript_answer_cannot_hide_a_transcript_for_good(): void {
		update_option( 'showfm_load_on_click', true );
		$block = '<!-- wp:showfm/transcript {"episode":"2f1c9a1e-0000-4000-8000-000000000001","snapshot":{"title":"T"}} /-->';
		$post  = self::factory()->post->create( array( 'post_content' => $block ) );
		$this->http->respond( 200, '{"data":{"id":"2f1c9a1e-0000-4000-8000-000000000001","title":"T","transcript":null}}' );
		$this->cache->refresh( self::PATH );
		$this->assertSame( '', do_blocks( $block ), 'No transcript: nothing in click mode.' );

		$cleaned = array();
		add_action(
			'clean_post_cache',
			static function ( $id ) use ( &$cleaned ) {
				$cleaned[] = (int) $id;
			}
		);
		$this->age( self::PATH );
		$this->http->respond( 200, '{"data":{"id":"2f1c9a1e-0000-4000-8000-000000000001","title":"T","transcript":{"url":"https://m.cdn.media/t.vtt"}}}' );
		$this->cache->refresh( self::PATH );
		$this->assertContains( $post, $cleaned, 'The changed answer purges the post.' );
		$this->assertStringContainsString( '<showfm-transcript', do_blocks( $block ) );
	}

	public function test_the_purge_reads_every_shortcode_and_block_form_the_renderer_accepts(): void {
		$uuid     = '2f1c9a1e-0000-4000-8000-000000000001';
		$matches  = array(
			'[showfm episode="' . $uuid . '"]',
			'[showfm episode = "' . $uuid . '"]',
			'[showfm episode=' . $uuid . ']',
			"[showfm type='player' episode='" . $uuid . "']",
			'[showfm episode="' . strtoupper( $uuid ) . '"]',
			'<!-- wp:showfm/player {"episode":"' . $uuid . '"} /-->',
			'<!-- wp:group --><div class="wp-block-group"><!-- wp:showfm/transcript {"episode":"' . $uuid . '"} /--></div><!-- /wp:group -->',
		);
		$misses   = array(
			'A post that only mentions ' . $uuid . '.',
			'[other episode="' . $uuid . '"]',
			'<!-- wp:paragraph --><p>{"episode":"' . $uuid . '"}</p><!-- /wp:paragraph -->',
			'[showfm podcast="' . $uuid . '"]',
		);
		$expected = array();
		foreach ( $matches as $content ) {
			$expected[] = self::factory()->post->create( array( 'post_content' => $content ) );
		}
		foreach ( $misses as $content ) {
			self::factory()->post->create( array( 'post_content' => $content ) );
		}
		$cleaned = Cache::purge_posts( '/v1/episodes/' . $uuid );
		sort( $cleaned );
		sort( $expected );
		$this->assertSame( $expected, $cleaned );
	}

	public function test_a_short_slug_purges_only_posts_that_name_it(): void {
		$list    = self::factory()->post->create( array( 'post_content' => '<!-- wp:showfm/episodes {"podcast":"news"} /-->' ) );
		$code    = self::factory()->post->create( array( 'post_content' => "[showfm type='episodes' podcast='news']" ) );
		$mention = self::factory()->post->create( array( 'post_content' => '<!-- wp:showfm/player {"podcast":"newsroom"} /--> The news.' ) );
		$room    = self::factory()->post->create( array( 'post_content' => '[showfm type="episodes" podcast=newsroom] [showfm podcast = "news-room"]' ) );
		$cleaned = Cache::purge_posts( '/v1/podcasts/news/episodes?limit=10' );
		sort( $cleaned );
		$expected = array( $list, $code );
		sort( $expected );
		$this->assertSame( $expected, $cleaned );
		$this->assertNotContains( $mention, $cleaned );
		$this->assertNotContains( $room, $cleaned );
	}

	/**
	 * Makes an entry stale, as if it was fetched longer ago than FRESH_FOR.
	 *
	 * @param string $path Path.
	 */
	private function age( string $path ): void {
		$entry               = get_transient( Cache::key( $path ) );
		$entry['fetched_at'] = time() - Cache::FRESH_FOR - 1;
		set_transient( Cache::key( $path ), $entry, HOUR_IN_SECONDS );
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
