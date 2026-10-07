<?php
/**
 * Feed consumer, replay, post lifecycle and recovery tests.
 *
 * @package ShowFM
 */

use ShowFM\Api_Client;
use ShowFM\Connection;
use ShowFM\Plugin;
use ShowFM\Sync;
use ShowFM\Sync_Posts;
use ShowFM\Sync_Lock;

/** Exercises the real WordPress post APIs with intercepted service responses. */
class Test_Sync extends WP_UnitTestCase {
	const SITE    = '11111111-1111-4111-8111-111111111111';
	const EPISODE = '22222222-2222-4222-8222-222222222222';
	const PODCAST = '33333333-3333-4333-8333-333333333333';
	/**
	 * HTTP mock.
	 *
	 * @var ShowFM_Http_Mock
	 */
	private $http;
	/**
	 * Consumer.
	 *
	 * @var Sync
	 */
	private $sync;

	public function set_up(): void {
		parent::set_up();
		Plugin::reset();
		add_filter(
			'pre_option_home',
			static function () {
				return 'https://site.example/show';
			},
			99
		);
		update_option( 'siteurl', 'https://site.example/show' );
		Plugin::connection()->save( 'showfm_live_TEST_SYNC_abcdefghijk', str_repeat( 'a', 64 ), self::SITE, 0 );
		delete_option( Sync::OPTION );
		delete_option( Sync_Posts::SETTINGS );
		delete_option( Api_Client::RATE_LIMIT_OPTION );
		delete_option( ShowFM\Connect::VERIFY_PENDING_OPTION );
		$this->http = new ShowFM_Http_Mock();
		$this->sync = new Sync();
	}

	public function tear_down(): void {
		$this->http->detach();
		Plugin::reset();
		parent::tear_down();
	}

	private function row( int $seq = 1, string $status = 'published', ?string $reason = null ): array {
		return array(
			'seq'        => $seq,
			'episode_id' => self::EPISODE,
			'podcast_id' => self::PODCAST,
			'action'     => $reason ? 'tombstone' : 'upsert',
			'reason'     => $reason,
			'episode'    => $reason ? null : array(
				'id'              => self::EPISODE,
				'podcast_id'      => self::PODCAST,
				'title'           => "Episode 'one'",
				'description'     => "Description with \\ and 'quotes'",
				'show_notes_html' => '<p>Notes</p><script>alert(1)</script>',
				'status'          => $status,
				'published_at'    => '2025-01-01T12:00:00Z',
				'scheduled_for'   => gmdate( 'c', time() + 86400 + $seq * 3600 ),
				'content_hash'    => 'sha256:row' . $seq,
			),
		);
	}

	private function page( array $rows, int $after = 0, bool $more = false ): void {
		$next = $rows ? end( $rows )['seq'] : $after;
		$this->http->respond(
			200,
			wp_json_encode(
				array(
					'data'   => $rows,
					'cursor' => array(
						'after'    => $after,
						'next'     => $next,
						'latest'   => $next,
						'has_more' => $more,
					),
				)
			)
		);
	}

	private function apply( array $row ): int {
		$id = ( new Sync_Posts() )->apply( self::SITE, $row );
		$this->assertIsInt( $id );
		return $id;
	}

	private function clear_backoff(): void {
		$state             = Sync::state();
		$state['retry_at'] = 0;
		update_option( Sync::OPTION, $state );
		delete_option( Api_Client::RATE_LIMIT_OPTION );
	}

	public function test_page_receipt_report_and_final_cursor_acknowledgement(): void {
		$this->page( array( $this->row() ) );
		$this->http->respond_with(
			function ( $args ) {
				$this->assertSame( 1, Sync::state()['cursor'] );
				$this->assertCount( 1, Sync::state()['reports'] );
				$body = json_decode( $args['body'], true );
				$this->assertSame( 'published', $body['state'] );
				$this->assertStringStartsWith( 'https://site.example/show/', $body['post_url'] );
				return array( 200, '{"data":{}}' );
			}
		);
		$this->page( array(), 1 );
		$result = $this->sync->pull();
		$this->assertSame( 'caught_up', $result['status'] );
		$this->assertSame( array(), Sync::state()['reports'] );
		$this->assertStringContainsString( 'after=1', $this->http->last()['url'] );
		$id = Sync_Posts::find( self::SITE, self::EPISODE );
		$this->assertSame( "Episode 'one'", get_post( $id )->post_title );
		$this->assertStringContainsString( 'wp:showfm/player', get_post( $id )->post_content );
		$this->assertStringNotContainsString( '<script>', get_post( $id )->post_content );
	}

	public function test_failed_row_is_logged_and_later_rows_are_applied(): void {
		$row2                  = $this->row( 2 );
		$row2['episode_id']    = '44444444-4444-4444-8444-444444444444';
		$row2['episode']['id'] = $row2['episode_id'];
		$this->page( array( $this->row(), $row2 ) );
		$fail = static function ( $is_empty, $post ) {
			return false !== strpos( $post['guid'] ?? '', self::EPISODE ) ? true : $is_empty;
		};
		add_filter( 'wp_insert_post_empty_content', $fail, 10, 2 );
		$this->http->respond( 200, '{}' );
		$this->page( array(), 2 );
		$this->assertSame( 'caught_up', $this->sync->pull()['status'] );
		remove_filter( 'wp_insert_post_empty_content', $fail, 10 );
		$this->assertSame( 2, Sync::state()['cursor'] );
		$this->assertSame( 0, Sync_Posts::find( self::SITE, self::EPISODE ) );
		$this->assertGreaterThan( 0, Sync_Posts::find( self::SITE, $row2['episode_id'] ) );
		$this->assertSame( 'row_invalid', get_option( ShowFM\Sync_Log::OPTION )[0]['code'] );
	}

	public function test_schedule_reschedule_publish_unpublish_remove_delete_restore(): void {
		update_option( 'timezone_string', 'Europe/London' );
		$row = $this->row( 1, 'scheduled' );
		$id  = $this->apply( $row );
		$this->assertSame( 'future', get_post_status( $id ) );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', strtotime( $row['episode']['scheduled_for'] ) ), get_post( $id )->post_date_gmt );
		$row = $this->row( 2, 'scheduled' );
		$this->apply( $row );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', strtotime( $row['episode']['scheduled_for'] ) ), get_post( $id )->post_date_gmt );
		$this->apply( $this->row( 3 ) );
		$this->assertSame( 'publish', get_post_status( $id ) );
		foreach ( array( 'unpublished', 'removed' ) as $reason ) {
			$this->apply( $this->row( 4, '', $reason ) );
			$this->assertSame( 'draft', get_post_status( $id ) );
		}
		$this->apply( $this->row( 5, '', 'deleted' ) );
		$this->assertSame( 'trash', get_post_status( $id ) );
		$this->apply( $this->row( 6 ) );
		$this->assertSame( 'publish', get_post_status( $id ) );
	}

	public function test_detached_and_paused_keep_post_then_access_restores(): void {
		$id     = $this->apply( $this->row() );
		$before = get_post( $id )->to_array();
		foreach ( array(
			'access_removed' => 'detached',
			'plan_or_policy' => 'paused',
		) as $reason => $state ) {
			$this->apply( $this->row( 2, '', $reason ) );
			$this->assertSame( $before, get_post( $id )->to_array() );
			$this->assertSame( $state, get_post_meta( $id, '_showfm_sync_state', true ) );
		}
		$this->assertSame( 'No longer synced from show.fm', get_post_meta( $id, '_showfm_sync_notice', true ) );
		$this->apply( $this->row( 3 ) );
		$this->assertSame( 'synced', get_post_meta( $id, '_showfm_sync_state', true ) );
		$this->assertSame( '', get_post_meta( $id, '_showfm_sync_notice', true ) );
	}

	public function test_every_tombstone_without_a_post_is_a_noop(): void {
		foreach ( array( 'deleted', 'removed', 'unpublished', 'access_removed', 'plan_or_policy' ) as $reason ) {
			$this->assertSame( 0, $this->apply( $this->row( 1, '', $reason ) ) );
		}
	}

	public function test_edit_detection_is_permanent_for_title_content_and_excerpt(): void {
		foreach ( array( 'post_title', 'post_content', 'post_excerpt' ) as $field ) {
			$id       = $this->apply( $this->row() );
			$original = get_post( $id )->$field;
			wp_update_post(
				array(
					'ID'   => $id,
					$field => 'My WordPress edit',
				)
			);
			$this->apply( $this->row() ); // Same source hash still detects an edit.
			$this->assertSame( '1', get_post_meta( $id, '_showfm_edited', true ) );
			$this->apply( $this->row( 2, 'scheduled' ) );
			$this->assertSame( 'My WordPress edit', get_post( $id )->$field );
			$this->assertSame( 'future', get_post_status( $id ) );
			wp_update_post(
				array(
					'ID'   => $id,
					$field => $original,
				)
			);
			$this->apply( $this->row( 3 ) );
			$this->assertSame( '1', get_post_meta( $id, '_showfm_edited', true ) );
			wp_delete_post( $id, true );
			delete_option( ShowFM\Sync_Identity::key( 'urn:showfm:' . self::SITE . ':' . self::EPISODE ) );
		}
	}

	public function test_reapply_does_not_write_post_or_revision(): void {
		$id     = $this->apply( $this->row() );
		$before = get_post( $id )->to_array();
		$writes = 0;
		$spy    = static function () use ( &$writes ) {
			++$writes;
		};
		add_action( 'save_post', $spy );
		$this->assertSame( $id, $this->apply( $this->row() ) );
		remove_action( 'save_post', $spy );
		$this->assertSame( 0, $writes );
		$this->assertSame( $before, get_post( $id )->to_array() );
	}

	public function test_report_failure_retries_without_blocking_feed_or_pings(): void {
		$this->page( array( $this->row() ) );
		$this->http->respond( 503, '{}' );
		$this->page( array(), 1 );
		$this->assertSame( 'caught_up', $this->sync->pull()['status'] );
		$this->assertCount( 1, Sync::state()['reports'] );
		$this->assertSame( 0, Sync::state()['retry_at'] );
		$this->page( array( $this->row( 2, 'scheduled' ) ), 1 );
		$this->page( array(), 2 );
		do_action( ShowFM\Ping_Endpoint::PULL_HOOK );
		$this->assertSame( 2, Sync::state()['cursor'] );
		$state = Sync::state();
		$state['report_retries'][ self::EPISODE ]['next'] = 0;
		update_option( Sync::OPTION, $state );
		$this->http->respond_with(
			function ( $args ) {
				$this->assertSame( 'scheduled', json_decode( $args['body'], true )['state'] );
				return array( 200, '{}' );
			}
		);
		$this->page( array(), 2 );
		$this->assertSame( 'caught_up', $this->sync->pull()['status'] );
		$this->assertSame( array(), Sync::state()['reports'] );
	}

	public function test_401_stops_polls_and_ping_wakeups(): void {
		$this->http->respond( 401 );
		$this->assertSame( 'unauthorised', $this->sync->pull()['status'] );
		do_action( ShowFM\Ping_Endpoint::PULL_HOOK );
		do_action( Sync::POLL_HOOK );
		$this->assertSame( 1, $this->http->count() );
		$this->assertSame( Connection::STATE_RECONNECT_NEEDED, Plugin::connection()->state() );
	}

	public function test_429_honours_retry_after_and_leaves_cursor(): void {
		$this->http->respond( 429, '', array( 'Retry-After' => '180' ) );
		$this->assertSame( 'rate_limited', $this->sync->pull()['status'] );
		$this->assertGreaterThanOrEqual( time() + 179, Sync::state()['retry_at'] );
		$this->assertSame( 0, Sync::state()['cursor'] );
		// Cron consumes an early wake-up before invoking the callback.
		wp_clear_scheduled_hook( ShowFM\Ping_Endpoint::PULL_HOOK );
		$this->sync->pull();
		$this->assertSame( Sync::state()['retry_at'], wp_next_scheduled( ShowFM\Ping_Endpoint::PULL_HOOK ) );
		$this->assertSame( 1, $this->http->count() );
	}

	public function test_unknown_row_is_skipped_without_failing_the_page(): void {
		$row           = $this->row();
		$row['action'] = 'surprise';
		$this->page( array( $row, $this->row( 2 ) ) );
		$this->http->respond( 200, '{}' );
		$this->page( array(), 2 );
		$this->assertSame( 'caught_up', $this->sync->pull()['status'] );
		$this->assertGreaterThan( 0, Sync_Posts::find( self::SITE, self::EPISODE ) );
		$this->assertSame( 2, Sync::state()['cursor'] );
		$this->assertSame( 'row_invalid', get_option( ShowFM\Sync_Log::OPTION )[0]['code'] );
	}

	public function test_nested_pull_is_single_flight_and_exception_releases_lock(): void {
		$this->http->respond_with(
			function () {
				$this->assertSame( 'locked', $this->sync->pull()['status'] );
				throw new RuntimeException( 'Simulated stopped run' );
			}
		);
		$this->assertSame( 'apply_failed', $this->sync->pull()['status'] );
		$this->clear_backoff();
		$this->page( array() );
		$this->assertSame( 'caught_up', $this->sync->pull()['status'] );
	}

	public function test_database_lock_survives_cache_flush_and_recovers_on_connection_close(): void {
		global $wpdb;
		$original = $wpdb;
		$other    = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$other->set_prefix( $original->prefix );
		$other->prefix  = $original->prefix;
		$other->options = $original->options;
		$this->assertSame( $original->dbname, $other->dbname );
		try {
			$wpdb = $other;
			$lock = new Sync_Lock();
			$this->assertTrue( $lock->acquire() );
			$wpdb = $original;
			wp_cache_flush();
			$fresh = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
			$fresh->set_prefix( $original->prefix );
			$fresh->prefix  = $original->prefix;
			$fresh->options = $original->options;
			$wpdb           = $fresh;
			$contender      = new Sync_Lock();
			$this->assertFalse( $contender->acquire() );
			$other->close(); // A crashed PHP process closes its DB session too.
			$acquired = false;
			for ( $attempt = 0; $attempt < 100 && ! $acquired; ++$attempt ) {
				usleep( 10000 );
				$acquired = $contender->acquire();
			}
				$this->assertTrue( $acquired );
			$contender->release();
			$fresh->close();
		} finally {
			$wpdb = $original;
		}
	}

	public function test_dry_run_from_start_leaves_local_state_and_posts_unchanged(): void {
		update_option(
			Sync::OPTION,
			array(
				'site'   => self::SITE,
				'cursor' => 9,
			)
		);
		$before = get_option( Sync::OPTION );
		$this->page( array( $this->row() ) );
		$this->page( array(), 1 );
		$this->assertSame( 'dry_run', $this->sync->pull( true, true )['status'] );
		$this->assertSame( $before, get_option( Sync::OPTION ) );
		$this->assertSame( 0, Sync_Posts::find( self::SITE, self::EPISODE ) );
	}

	public function test_post_url_rejects_off_host_http_and_path_escape(): void {
		$id = $this->apply( $this->row() );
		foreach ( array( 'http://site.example/show/post', 'https://evil.example/show/post', 'https://site.example/elsewhere/post', 'https://site.example/show/../elsewhere', 'https://site.example/show/%2e%2e/x', 'https://site.example:444/show/post', 'https://site.example/show/post#x' ) as $url ) {
			$filter = static function () use ( $url ) {
				return $url;
			};
			add_filter( 'post_link', $filter );
			$this->assertNull( Sync::post_url( get_post( $id ) ), $url );
			remove_filter( 'post_link', $filter );
		}
	}

	public function test_scheduling_and_frontend_render_never_make_http(): void {
		Sync::schedule();
		$this->assertNotFalse( wp_next_scheduled( Sync::POLL_HOOK ) );
		$this->assertSame( 900, wp_get_schedules()['showfm_quarter_hour']['interval'] );
		$id = $this->apply( $this->row() );
		apply_filters( 'the_content', get_post( $id )->post_content );
		$this->assertSame( 0, $this->http->count() );
	}

	public function test_multisite_cursors_and_posts_are_isolated(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
		$id = $this->apply( $this->row() );
		update_option(
			Sync::OPTION,
			array(
				'site'   => self::SITE,
				'cursor' => 50,
			)
		);
		$blog = self::factory()->blog->create();
		switch_to_blog( $blog );
		try {
			$this->assertSame( 0, Sync::state()['cursor'] );
			$this->assertSame( 0, Sync_Posts::find( self::SITE, self::EPISODE ) );
			$this->assertFalse( Plugin::connection()->is_connected() );
		} finally {
			restore_current_blog();
		}
		$this->assertSame( 50, Sync::state()['cursor'] );
		$this->assertSame( $id, Sync_Posts::find( self::SITE, self::EPISODE ) );
	}
	public function test_paging_and_report_batches_preserve_order(): void {
		$rows = array();
		for ( $seq = 1; $seq <= Sync::PAGE_SIZE; ++$seq ) {
			$row                  = $this->row( $seq );
			$row['episode_id']    = sprintf( '22222222-2222-4222-8222-%012d', $seq );
			$row['episode']['id'] = $row['episode_id'];
			$rows[]               = $row;
		}
		$this->page( $rows, 0, true );
		foreach ( $rows as $row ) {
			$this->http->respond( 200, '{}' );
		}
		$this->page( array( $this->row( 21 ) ), 20 );
		$this->http->respond( 200, '{}' );
		$this->page( array(), 21 );
		$this->assertSame( 'caught_up', $this->sync->pull()['status'] );
		$this->assertSame( 21, Sync::state()['cursor'] );
		$this->assertCount( 24, $this->http->requests );
		$this->assertStringContainsString( 'after=20', $this->http->requests[21]['url'] );
	}

	public function test_detached_and_paused_rows_do_not_report_refused_states(): void {
		$this->apply( $this->row() );
		$this->page( array( $this->row( 2, '', 'access_removed' ), $this->row( 3, '', 'plan_or_policy' ) ) );
		$this->page( array(), 3 );
		$this->assertSame( 'caught_up', $this->sync->pull()['status'] );
		$this->assertSame( array(), Sync::state()['reports'] );
		$this->assertSame( 2, $this->http->count() );
		$this->page( array( $this->row( 4 ) ), 3 );
		$this->http->respond( 200, '{}' );
		$this->page( array(), 4 );
		$this->assertSame( 'caught_up', $this->sync->pull()['status'] );
	}

	public function test_recover_crash_after_initial_insert_before_identity_meta(): void {
		ShowFM\Sync_Identity::prepare( 'urn:showfm:' . self::SITE . ':' . self::EPISODE );
		$id = wp_insert_post(
			array(
				'post_title' => 'Partial insert',
				'guid'       => 'urn:showfm:' . self::SITE . ':' . self::EPISODE,
			)
		);
		$this->assertSame( $id, $this->apply( $this->row() ) );
		$this->assertSame( self::EPISODE, get_post_meta( $id, '_showfm_episode_id', true ) );
	}

	public function test_from_start_replays_without_duplicate_posts_or_losing_edits(): void {
		$id = $this->apply( $this->row() );
		wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => 'Keep my edit',
			)
		);
		update_option(
			Sync::OPTION,
			array(
				'site'   => self::SITE,
				'cursor' => 99,
			)
		);
		$this->page( array( $this->row( 2 ) ) );
		$this->http->respond( 200, '{}' );
		$this->page( array(), 2 );
		$this->assertSame( 'caught_up', $this->sync->pull( false, true )['status'] );
		$this->assertSame( $id, Sync_Posts::find( self::SITE, self::EPISODE ) );
		$this->assertSame( 'Keep my edit', get_post( $id )->post_content );
	}

	public function test_cli_status_is_local_and_dry_run_flags_are_used(): void {
		$cli = new ShowFM\Cli( Plugin::connect(), Plugin::connection() );
		$cli->sync( array( 'status' ), array() );
		$this->assertSame( 0, $this->http->count() );
		$this->page( array( $this->row() ) );
		$this->page( array(), 1 );
		$cli->sync(
			array(),
			array(
				'dry-run'    => true,
				'from-start' => true,
			)
		);
		$this->assertSame( 0, Sync_Posts::find( self::SITE, self::EPISODE ) );
		$this->assertSame( 0, Sync::state()['cursor'] );
	}

	public function test_artwork_is_opt_in_and_deduplicated_in_media_library(): void {
		$id = $this->apply( $this->row() );
		$this->assertSame( 0, $this->http->count() );
		$this->http->respond_with(
			static function ( $args ) {
				file_put_contents( $args['filename'], base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aOuoAAAAASUVORK5CYII=' ) );
				return array( 200, '' );
			}
		);
		$artwork    = new ShowFM\Sync_Artwork();
		$attachment = $artwork->import( 'https://m.cdn.media/cover.png', $id );
		$this->assertIsInt( $attachment );
		$this->assertTrue( wp_attachment_is_image( $attachment ) );
		$this->assertSame( $attachment, $artwork->import( 'https://m.cdn.media/cover.png', $id ) );
		$this->assertSame( 1, $this->http->count() );
		$this->assertSame( 0, $this->http->last()['args']['redirection'] );
		$this->assertTrue( $this->http->last()['args']['reject_unsafe_urls'] );
		$this->assertArrayNotHasKey( 'Authorization', $this->http->last()['args']['headers'] );
		$this->http->respond( 200, wp_json_encode( array( 'data' => array( 'artwork' => array( 'url' => 'https://m.cdn.media/cover.png' ) ) ) ) );
		update_option( Sync_Posts::SETTINGS, array( 'featured_image' => true ) );
		$this->apply( $this->row( 2 ) );
		$this->assertSame( $attachment, (int) get_post_thumbnail_id( $id ) );
		$this->apply( $this->row( 3 ) );
		$this->assertSame( 2, $this->http->count() );
	}

	public function test_artwork_rejects_redirects_and_non_images(): void {
		$artwork = new ShowFM\Sync_Artwork();
		$this->assertWPError( $artwork->import( 'http://127.0.0.1/test.png', 0 ) );
		$this->assertSame( 0, $this->http->count() );
		$this->http->respond( 302, '', array( 'Location' => 'http://127.0.0.1/test.png' ) );
		$this->assertWPError( $artwork->import( 'https://m.cdn.media/redirect.png', 0 ) );
		$this->http->respond_with(
			static function ( $args ) {
				file_put_contents( $args['filename'], '<svg><script>alert(1)</script></svg>' );
				return array( 200, '' );
			}
		);
		$this->assertWPError( $artwork->import( 'https://m.cdn.media/fake.png', 0 ) );
	}
	public function test_lost_database_session_stops_before_post_or_cursor_writes(): void {
		$row = $this->row();
		$this->http->respond_with(
			static function () use ( $row ) {
				global $wpdb;
				$wpdb->get_var( 'SELECT RELEASE_ALL_LOCKS()' );
				return array(
					200,
					wp_json_encode(
						array(
							'data'   => array( $row ),
							'cursor' => array(
								'after'    => 0,
								'next'     => 1,
								'latest'   => 1,
								'has_more' => false,
							),
						)
					),
				);
			}
		);
		$this->assertSame( 'lock_lost', $this->sync->pull()['status'] );
		$this->assertSame( 0, Sync::state()['cursor'] );
		$this->assertSame( 0, Sync_Posts::find( self::SITE, self::EPISODE ) );
	}

	public function test_metadata_failure_is_retried_without_advancing_cursor(): void {
		$this->page( array( $this->row() ) );
				$fail = static function ( $check, $id, $key ) {
					return '_showfm_content_hash' === $key ? false : $check;
				};
		add_filter( 'update_post_metadata', $fail, 10, 3 );
		$this->assertSame( 'row_write_failed', $this->sync->pull()['status'] );
		remove_filter( 'update_post_metadata', $fail, 10 );
		$this->assertSame( 0, Sync::state()['cursor'] );
		$this->assertSame( 'row_write_failed', get_option( 'showfm_connection_sync_status' )['retry']['code'] );
		$this->assertSame( 'row_write_failed', get_option( ShowFM\Sync_Log::OPTION )[0]['code'] );
	}

	public function test_unchanged_content_hash_still_applies_lifecycle_changes(): void {
		$row                             = $this->row( 1, 'scheduled' );
		$id                              = $this->apply( $row );
		$row['episode']['scheduled_for'] = gmdate( 'c', time() + 3 * DAY_IN_SECONDS );
		$this->apply( $row );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', strtotime( $row['episode']['scheduled_for'] ) ), get_post( $id )->post_date_gmt );
		$row['episode']['status'] = 'published';
		$this->apply( $row );
		$this->assertSame( 'publish', get_post_status( $id ) );
		$this->assertSame( '2025-01-01 12:00:00', get_post( $id )->post_date_gmt );
	}

	public function test_artwork_retries_independently_without_rewriting_unchanged_post(): void {
		update_option( Sync_Posts::SETTINGS, array( 'featured_image' => true ) );
		$row = $this->row( 1, 'scheduled' );
		$id  = $this->apply( $row );
		$this->assertSame( 0, $this->http->count() );
		$row['episode']['status'] = 'published';
		$this->page( array( $row ) );
		$this->http->respond( 404, '{}' );
		$this->http->respond( 200, '{}' );
		$this->page( array(), 1 );
		$this->assertSame( 'caught_up', $this->sync->pull()['status'] );
		$this->assertSame( 1, Sync::state()['cursor'] );
		$this->assertSame( '', get_post_meta( $id, '_showfm_artwork_done', true ) );
		$queue                = get_option( ShowFM\Sync_Artwork::QUEUE );
		$queue[ $id ]['next'] = 0;
		update_option( ShowFM\Sync_Artwork::QUEUE, $queue );
		$this->http->respond( 200, '{"data":{"artwork":{"url":null}}}' );
		$writes = 0;
		$spy    = static function () use ( &$writes ) {
			++$writes;
		};
		add_action( 'save_post', $spy );
		$this->apply( $row );
		remove_action( 'save_post', $spy );
		$this->assertSame( 0, $writes );
		$this->assertSame( '1', get_post_meta( $id, '_showfm_artwork_done', true ) );
		$this->assertSame( array(), get_option( ShowFM\Sync_Artwork::QUEUE ) );
	}

	public function test_dry_run_does_not_acknowledge_previewed_rows_to_the_server(): void {
		$this->page( array( $this->row() ), 0, true );
		$this->assertSame( 'dry_run_limit', $this->sync->pull( true )['status'] );
		$this->assertSame( 1, $this->http->count() );
		$this->assertStringContainsString( 'after=0', $this->http->last()['url'] );
		$this->assertSame( 0, Sync::state()['cursor'] );
	}
	public function test_terminal_report_responses_are_dropped_once_with_own_reason(): void {
		foreach ( array( 400, 403, 404 ) as $status ) {
			delete_option( Sync::OPTION );
			$this->page( array( $this->row() ) );
			$this->http->respond( $status, '{"error":{"code":"untrusted_secret","message":"private"}}' );
			$this->page( array(), 1 );
			$this->assertSame( 'caught_up', $this->sync->pull()['status'] );
			$this->assertSame( array(), Sync::state()['reports'] );
			$log = get_option( ShowFM\Sync_Log::OPTION );
			$this->assertSame( 'report_' . $status, end( $log )['code'] );
			$before = $this->http->count();
			$this->page( array(), 1 );
			$this->sync->pull();
			$this->assertSame( $before + 1, $this->http->count() );
		}
		$this->assertStringNotContainsString( 'untrusted_secret', wp_json_encode( get_option( ShowFM\Sync_Log::OPTION ) ) );
	}

	public function test_missing_post_and_invalid_url_reports_are_terminal_without_http(): void {
		$id      = $this->apply( $this->row() );
		$invalid = static function () {
			return false;
		};
		add_filter( 'post_link', $invalid );
		foreach ( array(
			$id    => 'report_invalid_url',
			999999 => 'report_missing_post',
		) as $post_id => $reason ) {
			update_option(
				Sync::OPTION,
				array(
					'site'    => self::SITE,
					'reports' => array( self::EPISODE => $post_id ),
				)
			);
			$this->page( array() );
			$this->assertSame( 'caught_up', $this->sync->pull()['status'] );
			$this->assertSame( array(), Sync::state()['reports'] );
			$log = get_option( ShowFM\Sync_Log::OPTION );
			$this->assertSame( $reason, end( $log )['code'] );
		}
		remove_filter( 'post_link', $invalid );
		$this->assertSame( 2, $this->http->count() );
	}

	public function test_report_authentication_and_rate_limits_remain_connection_wide(): void {
		$id = $this->apply( $this->row() );
		update_option(
			Sync::OPTION,
			array(
				'site'    => self::SITE,
				'reports' => array( self::EPISODE => $id ),
			)
		);
		$this->http->respond( 429, '', array( 'Retry-After' => '999999999' ) );
		$this->assertSame( 'rate_limited', $this->sync->pull()['status'] );
		$this->assertLessThanOrEqual( time() + DAY_IN_SECONDS, Sync::state()['retry_at'] );
		$this->assertCount( 1, Sync::state()['reports'] );
		$this->clear_backoff();
		$this->http->respond( 401 );
		$this->assertSame( 'unauthorised', $this->sync->pull()['status'] );
		$this->assertSame( Connection::STATE_RECONNECT_NEEDED, Plugin::connection()->state() );
		$this->assertSame( 2, $this->http->count() );
	}

	public function test_every_feed_field_rejects_wrong_types_and_unknown_enums(): void {
		$rows = array();
		foreach ( array( 'title', 'description', 'show_notes_html', 'slug', 'episode_type', 'scheduled_for', 'published_at', 'episode_number', 'season_number', 'explicit', 'content_hash', 'id', 'podcast_id', 'status' ) as $field ) {
			$row                      = $this->row( count( $rows ) + 1 );
			$row['episode'][ $field ] = array( 'unexpected' );
			$this->assertFalse( Sync_Posts::valid( $row ), $field );
			$rows[] = $row;
		}
		foreach ( array( 'reason', 'action', 'access_state', 'changed_at', 'post', 'episode_id', 'podcast_id' ) as $field ) {
			$row           = $this->row( count( $rows ) + 1 );
			$row[ $field ] = array( 'unexpected' );
			$this->assertFalse( Sync_Posts::valid( $row ), $field );
			$rows[] = $row;
		}
		foreach ( array( 'action', 'reason' ) as $field ) {
			$row           = $this->row( count( $rows ) + 1, '', 'deleted' );
			$row[ $field ] = 'new_server_value';
			$rows[]        = $row;
		}
		$row                      = $this->row( count( $rows ) + 1 );
		$row['episode']['status'] = 'new_server_status';
		$rows[]                   = $row;
		$last                     = count( $rows ) + 1;
		$rows[]                   = $this->row( $last );
		$this->page( $rows );
		$this->http->respond( 200, '{}' );
		$this->page( array(), $last );
		$this->assertSame( 'caught_up', $this->sync->pull()['status'] );
		$this->assertSame( $last, Sync::state()['cursor'] );
		$this->assertCount( $last - 1, get_option( ShowFM\Sync_Log::OPTION ) );
		$this->assertGreaterThan( 0, Sync_Posts::find( self::SITE, self::EPISODE ) );
	}

	public function test_malformed_page_envelope_never_advances_cursor(): void {
		$this->http->respond( 200, '{"data":[],"cursor":{"after":0,"next":5,"latest":5,"has_more":false}}' );
		$this->assertSame( 'invalid_feed', $this->sync->pull()['status'] );
		$this->assertSame( 0, Sync::state()['cursor'] );
	}

	public function test_hook_exception_is_retried_with_only_our_error_code(): void {
		$this->page( array( $this->row() ) );
				$fail = static function () {
					throw new RuntimeException( 'secret third party detail' );
				};
		add_filter( 'wp_insert_post_empty_content', $fail );
		$this->assertSame( 'row_write_failed', $this->sync->pull()['status'] );
		remove_filter( 'wp_insert_post_empty_content', $fail );
		$this->assertSame( 0, Sync::state()['cursor'] );
		$this->assertSame( 'row_write_failed', get_option( 'showfm_connection_sync_status' )['retry']['code'] );
		$this->assertSame( 'row_write_failed', get_option( ShowFM\Sync_Log::OPTION )[0]['code'] );
		$this->assertStringNotContainsString( 'secret', wp_json_encode( get_option( ShowFM\Sync_Log::OPTION ) ) );
	}

	public function test_user_trash_is_sticky_even_when_replaying_from_start(): void {
		$id = $this->apply( $this->row() );
		wp_trash_post( $id );
		$this->http->respond( 200, '{}' );
		$this->page( array( $this->row( 2 ) ) );
		$this->page( array(), 2 );
		$this->assertSame( 'caught_up', $this->sync->pull( false, true )['status'] );
		$this->assertSame( 'trash', get_post_status( $id ) );
		$this->assertSame( 'local_detached', get_post_meta( $id, '_showfm_sync_state', true ) );
		$this->assertSame( array(), Sync::state()['reports'] );
		$this->assertSame( 3, $this->http->count() );
	}

	public function test_user_permanent_deletion_is_never_recreated(): void {
		$id = $this->apply( $this->row() );
		wp_delete_post( $id, true );
		$this->assertNull( get_post( $id ) );
		$this->http->respond( 200, '{}' );
		$this->page( array( $this->row( 2 ) ) );
		$this->page( array(), 2 );
		$this->assertSame( 'caught_up', $this->sync->pull( false, true )['status'] );
		$this->assertSame( $id, Sync_Posts::find( self::SITE, self::EPISODE ) );
		$this->assertNull( get_post( $id ) );
		$this->assertSame( array(), Sync::state()['reports'] );
	}

	public function test_artwork_permanent_failure_does_not_pin_cursor_or_retry(): void {
		update_option( Sync_Posts::SETTINGS, array( 'featured_image' => true ) );
		$this->page( array( $this->row() ) );
		$this->http->respond( 200, '{"data":{"artwork":{"url":"https://untrusted.example/image.png"}}}' );
		$this->http->respond( 200, '{}' );
		$this->page( array(), 1 );
		$this->assertSame( 'caught_up', $this->sync->pull()['status'] );
		$id = Sync_Posts::find( self::SITE, self::EPISODE );
		$this->assertSame( 'failed', get_post_meta( $id, '_showfm_artwork_done', true ) );
		$this->assertSame( 'artwork_terminal', get_post_meta( $id, '_showfm_artwork_error', true ) );
		$this->apply( $this->row( 2 ) );
		$this->assertSame( 4, $this->http->count() );
		$this->assertSame( array(), get_option( ShowFM\Sync_Artwork::QUEUE ) );
	}

	public function test_artwork_transient_failures_exhaust_after_three_attempts(): void {
		update_option( Sync_Posts::SETTINGS, array( 'featured_image' => true ) );
		$this->page( array( $this->row() ) );
		$this->http->respond( 429, '', array( 'Retry-After' => '120' ) );
		$this->http->respond( 200, '{}' );
		$this->page( array(), 1 );
		$this->assertSame( 'caught_up', $this->sync->pull()['status'] );
		$id = Sync_Posts::find( self::SITE, self::EPISODE );
		$this->assertSame( 0, Api_Client::rate_limit_remaining() );
		$queue = get_option( ShowFM\Sync_Artwork::QUEUE );
		$this->assertGreaterThanOrEqual( time() + 119, $queue[ $id ]['next'] );
		for ( $attempt = 2; $attempt <= 3; ++$attempt ) {
			$queue[ $id ]['next'] = 0;
			update_option( ShowFM\Sync_Artwork::QUEUE, $queue );
			$this->page( array(), 1 );
			$this->http->respond( 503, '{}' );
			$this->assertSame( 'caught_up', $this->sync->pull()['status'] );
			$queue = get_option( ShowFM\Sync_Artwork::QUEUE );
		}
		$this->assertSame( array(), $queue );
		$this->assertSame( 'artwork_exhausted', get_post_meta( $id, '_showfm_artwork_error', true ) );
		$this->assertSame( 'failed', get_post_meta( $id, '_showfm_artwork_done', true ) );
		$this->assertSame( 1, Sync::state()['cursor'] );
	}

	public function test_artwork_allowlist_rejects_credentials_ports_and_lookalikes(): void {
		$art = new ShowFM\Sync_Artwork();
		foreach ( array( 'https://m.cdn.media.evil.test/a.png', 'https://evil.test/m.cdn.media/a.png', 'https://m.cdn.media:443/a.png', 'https://user:pass@m.cdn.media/a.png', 'http://m.cdn.media/a.png', 'https://m.cdn.media/a.png#fragment' ) as $url ) {
			$this->assertWPError( $art->import( $url, 0 ) );
		}
		$this->assertSame( 0, $this->http->count() );
	}

	public function test_artwork_checks_bytes_and_header_dimensions_before_media_processing(): void {
		$art   = new ShowFM\Sync_Artwork();
		$media = 0;
		$spy   = static function ( $file ) use ( &$media ) {
			++$media;
			return $file;
		};
		add_filter( 'wp_handle_sideload_prefilter', $spy );
		foreach ( array( array( 8001, 1 ), array( 1, 8001 ), array( 7000, 7000 ) ) as $dimensions ) {
			$this->http->respond_with(
				function ( $args ) use ( $dimensions ) {
					$this->assertSame( ShowFM\Sync_Artwork::MAX_BYTES + 1, $args['limit_response_size'] );
					$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aOuoAAAAASUVORK5CYII=' );
					$png = substr_replace( $png, pack( 'NN', $dimensions[0], $dimensions[1] ), 16, 8 );
					file_put_contents( $args['filename'], $png );
					return array( 200, '' );
				}
			);
			$this->assertWPError( $art->import( 'https://m.cdn.media/large.png', 0 ) );
		}
		$this->http->respond_with(
			static function ( $args ) {
				$file = fopen( $args['filename'], 'wb' );
				ftruncate( $file, ShowFM\Sync_Artwork::MAX_BYTES + 1 );
				fclose( $file );
				return array( 200, '' );
			}
		);
		$this->assertWPError( $art->import( 'https://m.cdn.media/oversize.png', 0 ) );
		remove_filter( 'wp_handle_sideload_prefilter', $spy );
		$this->assertSame( 0, $media );
	}

	public function test_lock_contention_queues_followup_and_same_session_cannot_take_over(): void {
		wp_clear_scheduled_hook( ShowFM\Ping_Endpoint::PULL_HOOK );
		$lock = new Sync_Lock();
		$this->assertTrue( $lock->acquire() );
		try {
			$this->assertSame( 'locked', $this->sync->pull()['status'] );
			$this->assertTrue( $lock->owned() );
			$this->assertEqualsWithDelta( time() + 15, wp_next_scheduled( ShowFM\Ping_Endpoint::PULL_HOOK ), 2 );
		} finally {
			$lock->release();
		}
		$this->page( array() );
		$this->assertSame( 'caught_up', $this->sync->pull()['status'] );
	}

	public function test_options_lock_fallback_crash_recovery_and_stale_owner_release(): void {
		add_filter( 'showfm_sync_use_named_lock', '__return_false' );
		$first  = new Sync_Lock();
		$second = new Sync_Lock();
		$this->assertTrue( $first->acquire() );
		wp_cache_flush();
		$this->assertFalse( $second->acquire() );
		$value    = explode( '|', get_option( Sync_Lock::OPTION ) );
		$value[1] = time() - 1;
		update_option( Sync_Lock::OPTION, implode( '|', $value ) );
		$this->assertTrue( $second->acquire() );
		$this->assertFalse( $first->owned() );
		$first->release();
		$this->assertTrue( $second->owned() );
		$second->release();
		remove_filter( 'showfm_sync_use_named_lock', '__return_false' );
		$this->assertFalse( get_option( Sync_Lock::OPTION ) );
	}

	public function test_unsupported_get_lock_automatically_uses_options_lock(): void {
		$unsupported = static function ( $query ) {
			return 0 === strpos( $query, 'SELECT GET_LOCK(' ) ? 'SELECT NULL' : $query;
		};
		add_filter( 'query', $unsupported );
		$lock = new Sync_Lock();
		$this->assertTrue( $lock->acquire() );
		$this->assertStringContainsString( '|options|', get_option( Sync_Lock::OPTION ) );
		$this->assertTrue( $lock->owned() );
		$lock->release();
		remove_filter( 'query', $unsupported );
	}

	public function test_expired_options_lease_is_reclaimed_atomically_even_with_stale_cache(): void {
		add_filter( 'showfm_sync_use_named_lock', '__return_false' );
		update_option( Sync_Lock::OPTION, 'dead|' . ( time() - 1 ) . '|options|' );
		$lock = new Sync_Lock();
		$this->assertTrue( $lock->acquire() );
		$this->assertTrue( $lock->owned() );
		$contender = new Sync_Lock();
		$this->assertFalse( $contender->acquire() );
		$lock->release();
		remove_filter( 'showfm_sync_use_named_lock', '__return_false' );
	}

	public function test_author_is_revalidated_after_demotion_and_deletion(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		update_option( Sync_Posts::SETTINGS, array( 'author' => $author ) );
		$id = $this->apply( $this->row() );
		$this->assertSame( $author, (int) get_post( $id )->post_author );
		( new WP_User( $author ) )->set_role( 'subscriber' );
		$this->apply( $this->row() );
		$replacement = (int) get_post( $id )->post_author;
		$this->assertNotSame( $author, $replacement );
		$this->assertTrue( user_can( $replacement, 'publish_posts' ) );
		update_option( Sync_Posts::SETTINGS, array( 'author' => 999999 ) );
		$row                  = $this->row( 2 );
		$row['episode_id']    = '44444444-4444-4444-8444-444444444444';
		$row['episode']['id'] = $row['episode_id'];
		$other                = $this->apply( $row );
		$this->assertTrue( user_can( (int) get_post( $other )->post_author, 'publish_posts' ) );
	}

	public function test_nested_and_unclosed_comments_cannot_inject_blocks(): void {
		$row                               = $this->row();
		$row['episode']['show_notes_html'] = '<p>Good</p><!<!-- remove -->-- wp:evil/block --><p>More</p><!-- unclosed';
		$id                                = $this->apply( $row );
		$content                           = get_post( $id )->post_content;
		$this->assertStringNotContainsString( 'wp:evil', $content );
		$this->assertStringNotContainsString( 'unclosed', $content );
		$this->assertStringContainsString( '<p>Good</p>', $content );
		$this->assertStringContainsString( '<p>More</p>', $content );
	}

	public function test_diagnostics_are_bounded_and_only_store_our_codes(): void {
		for ( $i = 0; $i < 60; ++$i ) {
			ShowFM\Sync_Log::record( 'third_party_secret', $i );
		}
		$log = get_option( ShowFM\Sync_Log::OPTION );
		$this->assertCount( 50, $log );
		$this->assertSame( 10, $log[0]['seq'] );
		$this->assertSame( 'internal_error', $log[0]['code'] );
		$this->assertStringNotContainsString( 'third_party', wp_json_encode( $log ) );
	}
	public function test_same_hash_lifecycle_update_sends_current_status_report(): void {
		$row                      = $this->row( 1, 'scheduled' );
		$id                       = $this->apply( $row );
		$row['seq']               = 2;
		$row['episode']['status'] = 'published';
		$this->page( array( $row ) );
		$this->http->respond_with(
			function ( $args ) use ( $id ) {
				$body = json_decode( $args['body'], true );
				$this->assertSame( $id, $body['wp_post_id'] );
				$this->assertSame( 'published', $body['state'] );
				$this->assertSame( 'sha256:row1', $body['content_hash'] );
				return array( 200, '{}' );
			}
		);
		$this->page( array(), 2 );
		$this->assertSame( 'caught_up', $this->sync->pull()['status'] );
	}

	public function test_dry_run_at_saved_cursor_performs_only_one_read(): void {
		update_option(
			Sync::OPTION,
			array(
				'site'   => self::SITE,
				'cursor' => 9,
			)
		);
		$this->page( array( $this->row( 10 ) ), 9, true );
		$this->assertSame( 'dry_run_limit', $this->sync->pull( true )['status'] );
		$this->assertSame( 9, Sync::state()['cursor'] );
		$this->assertSame( 1, $this->http->count() );
		$this->assertStringContainsString( 'after=9', $this->http->last()['url'] );
	}

	public function test_artwork_crashed_attempts_are_bounded_and_hook_errors_do_not_fail_rows(): void {
		$id = $this->apply( $this->row() );
		update_option( Sync_Posts::SETTINGS, array( 'featured_image' => true ) );
		$this->http->respond_with(
			static function () {
				throw new RuntimeException( 'private hook detail' );
			}
		);
		$this->apply( $this->row() );
		$this->assertSame( '1', get_post_meta( $id, '_showfm_artwork_attempts', true ) );
		$this->assertSame( 'artwork_retry', get_post_meta( $id, '_showfm_artwork_error', true ) );
		// A worker died after recording its third attempt, before any result receipt.
		delete_option( ShowFM\Sync_Artwork::QUEUE );
		update_post_meta( $id, '_showfm_artwork_attempts', 3 );
		$this->apply( $this->row() );
		$this->assertSame( 'artwork_exhausted', get_post_meta( $id, '_showfm_artwork_error', true ) );
		$this->assertSame( 1, $this->http->count() );
	}

	public function test_legacy_identity_migrates_via_meta_without_unindexed_guid_query(): void {
		$id = $this->apply( $this->row() );
		delete_option( ShowFM\Sync_Identity::key( 'urn:showfm:' . self::SITE . ':' . self::EPISODE ) );
		$queries = array();
		$spy     = static function ( $query ) use ( &$queries ) {
			$queries[] = $query;
			return $query;
		};
		add_filter( 'query', $spy );
		$this->assertSame( $id, Sync_Posts::find( self::SITE, self::EPISODE ) );
		remove_filter( 'query', $spy );
		$this->assertStringContainsString( "meta_key = '_showfm_episode_id'", implode( "\n", $queries ) );
		$this->assertStringNotContainsString( 'WHERE guid', implode( "\n", $queries ) );
	}

	public function test_no_publishing_author_retries_row_and_records_reason(): void {
		$deny = static function ( $caps, $cap ) {
			return 'publish_posts' === $cap ? array( 'do_not_allow' ) : $caps;
		};
		add_filter( 'map_meta_cap', $deny, 10, 2 );
		$this->page( array( $this->row() ) );
				$this->assertSame( 'row_author', $this->sync->pull()['status'] );
		remove_filter( 'map_meta_cap', $deny );
		$this->assertSame( 0, Sync::state()['cursor'] );
		$this->assertSame( 'row_author', get_option( 'showfm_connection_sync_status' )['retry']['code'] );
		$this->assertSame( 0, Sync_Posts::find( self::SITE, self::EPISODE ) );
		$this->assertSame( 'row_author', get_option( ShowFM\Sync_Log::OPTION )[0]['code'] );
	}

	public function test_multisite_options_locks_are_independent(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
		$blog = self::factory()->blog->create();
		add_filter( 'showfm_sync_use_named_lock', '__return_false' );
		$main = new Sync_Lock();
		$this->assertTrue( $main->acquire() );
		switch_to_blog( $blog );
		try {
			$other = new Sync_Lock();
			$this->assertTrue( $other->acquire() );
			$other->release();
		} finally {
			restore_current_blog();
		}
		$this->assertTrue( $main->owned() );
		$main->release();
		remove_filter( 'showfm_sync_use_named_lock', '__return_false' );
	}
	public function test_review_cursor_boundary_rejects_missing_sequences_before_any_apply(): void {
		$this->http->respond(
			200,
			wp_json_encode(
				array(
					'data'   => array( $this->row() ),
					'cursor' => array(
						'after'    => 0,
						'next'     => 100,
						'latest'   => 100,
						'has_more' => false,
					),
				)
			)
		);
		$this->assertSame( 'invalid_feed', $this->sync->pull()['status'] );
		$this->assertSame( 0, Sync::state()['cursor'] );
		$this->assertSame( 0, Sync_Posts::find( self::SITE, self::EPISODE ) );
		$this->assertSame( 1, $this->http->count() );

		$this->clear_backoff();
		$this->http->respond(
			200,
			wp_json_encode(
				array(
					'data'   => array( $this->row( 2 ) ),
					'cursor' => array(
						'after'    => 0,
						'next'     => 1,
						'latest'   => 2,
						'has_more' => false,
					),
				)
			)
		);
		$this->assertSame( 'invalid_feed', $this->sync->pull()['status'] );
		$this->assertSame( 0, Sync::state()['cursor'] );
		$this->clear_backoff();
		$poison                      = $this->row( 2 );
		$poison['episode']['status'] = 'new_server_status';
		$this->page( array( $this->row(), $poison ) );
		$this->http->respond( 200, '{}' );
		$this->page( array(), 2 );
		$this->assertSame( 'caught_up', $this->sync->pull()['status'] );
		$this->assertSame( 2, Sync::state()['cursor'] );
		$this->assertSame( 'row_invalid', get_option( ShowFM\Sync_Log::OPTION )[0]['code'] );
	}

	public function test_review_completed_artwork_entries_are_removed_before_real_retries(): void {
		update_option( Sync_Posts::SETTINGS, array( 'featured_image' => true ) );
		$queue = array();
		for ( $i = 0; $i <= Sync::PAGE_SIZE; ++$i ) {
			$id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
			update_post_meta( $id, '_showfm_sync_state', 'synced' );
			if ( $i < Sync::PAGE_SIZE ) {
				update_post_meta( $id, '_showfm_artwork_done', $i % 2 ? 'failed' : 1 );
			}
			$queue[ $id ] = array(
				'episode'  => self::EPISODE,
				'attempts' => 1,
				'next'     => 0,
			);
		}
		update_option( ShowFM\Sync_Artwork::QUEUE, $queue );
		$this->page( array() );
		$this->assertSame( 'caught_up', $this->sync->pull()['status'] );
		$this->assertCount( 1, get_option( ShowFM\Sync_Artwork::QUEUE ) );
		$this->page( array() );
		$this->http->respond( 200, '{"data":{"artwork":{"url":null}}}' );
		$this->assertSame( 'caught_up', $this->sync->pull()['status'] );
		$this->assertSame( array(), get_option( ShowFM\Sync_Artwork::QUEUE ) );
		$this->assertSame( '1', get_post_meta( $id, '_showfm_artwork_done', true ) );

		// Completion cleanup also wins over a future retry timestamp.
		update_option(
			ShowFM\Sync_Artwork::QUEUE,
			array(
				$id => array(
					'episode'  => self::EPISODE,
					'attempts' => 1,
					'next'     => time() + HOUR_IN_SECONDS,
				),
			)
		);
		$requests = $this->http->count();
		( new ShowFM\Sync_Artwork() )->apply( $id, self::EPISODE );
		$this->assertSame( array(), get_option( ShowFM\Sync_Artwork::QUEUE ) );
		$this->assertSame( $requests, $this->http->count() );
	}

	public function test_review_config_failure_keeps_cursor_and_recovers_when_type_returns(): void {
		update_option( Sync_Posts::SETTINGS, array( 'post_type' => 'unavailable_type' ) );
		$this->page( array( $this->row() ) );
		$this->assertSame( 'row_post_type', $this->sync->pull()['status'] );
		$this->assertSame( 0, Sync::state()['cursor'] );
		$this->assertSame( 'backoff', $this->sync->pull()['status'] );
		$this->assertSame( 1, $this->http->count() );
		$status = get_option( 'showfm_connection_sync_status' );
		$this->assertSame( 'row_post_type', $status['retry']['code'] );
		$this->assertSame( 1, $status['retry']['seq'] );
		$this->assertSame( 0, $status['retry']['attempts'] );
		WP_CLI::$output = array();
		( new ShowFM\Cli( Plugin::connect(), Plugin::connection() ) )->sync( array( 'status' ), array() );
		$this->assertStringContainsString( 'row_post_type', wp_json_encode( WP_CLI::$output ) );
		delete_option( Sync_Posts::SETTINGS );
		$this->clear_backoff();
		$this->page( array( $this->row() ) );
		$this->http->respond( 200, '{}' );
		$this->page( array(), 1 );
		$this->assertSame( 'caught_up', $this->sync->pull()['status'] );
		$this->assertSame( array(), get_option( 'showfm_connection_sync_status' )['retry'] );
	}

	public function test_review_write_failure_saves_successful_prefix_and_retries_same_post(): void {
		$row2                  = $this->row( 2 );
		$row2['episode_id']    = '44444444-4444-4444-8444-444444444444';
		$row2['episode']['id'] = $row2['episode_id'];
		$fail                  = static function ( $check, $id, $key ) use ( $row2 ) {
			return '_showfm_content_hash' === $key && get_post_meta( $id, '_showfm_episode_id', true ) === $row2['episode_id'] ? false : $check;
		};
		add_filter( 'update_post_metadata', $fail, 10, 3 );
		$this->page( array( $this->row(), $row2 ) );
		$this->assertSame( 'row_write_failed', $this->sync->pull()['status'] );
		remove_filter( 'update_post_metadata', $fail, 10 );
		$this->assertSame( 1, Sync::state()['cursor'] );
		$this->assertCount( 1, Sync::state()['reports'] );
		$id = Sync_Posts::find( self::SITE, $row2['episode_id'] );
		$this->assertGreaterThan( 0, $id );
		$this->clear_backoff();
		$this->http->respond( 200, '{}' );
		$this->page( array( $row2 ), 1 );
		$this->http->respond( 200, '{}' );
		$this->page( array(), 2 );
		$this->assertSame( 'caught_up', $this->sync->pull()['status'] );
		$this->assertSame( $id, Sync_Posts::find( self::SITE, $row2['episode_id'] ) );
	}

	public function test_review_apply_attempt_budget_skips_only_after_five_failures(): void {
		$fail = static function () {
			throw new RuntimeException( 'Simulated write failure' );
		};
		add_filter( 'wp_insert_post_empty_content', $fail );
		for ( $attempt = 1; $attempt <= 5; ++$attempt ) {
			$this->clear_backoff();
			$this->page( array( $this->row() ) );
			if ( 5 === $attempt ) {
				$this->page( array(), 1 );
			}
			$this->assertSame( 5 === $attempt ? 'caught_up' : 'row_write_failed', $this->sync->pull()['status'] );
			$this->assertSame( 5 === $attempt ? 1 : 0, Sync::state()['cursor'] );
		}
		$status = get_option( 'showfm_connection_sync_status' );
		$this->assertSame( array(), $status['retry'] );
		$this->assertSame( 1, $status['skipped'][0]['seq'] );
		$this->assertSame( 'row_write_failed', $status['skipped'][0]['code'] );
		$this->assertSame( 5, $status['skipped'][0]['attempts'] );
		$this->page( array( $this->row( 2 ) ), 1 );
		$this->assertSame( 'row_write_failed', $this->sync->pull()['status'] );
		$this->assertSame( 1, get_option( 'showfm_connection_sync_status' )['retry']['attempts'] );
		$this->assertSame( 1, Sync::state()['cursor'] );
		remove_filter( 'wp_insert_post_empty_content', $fail );
	}

	public function test_review_user_trash_queues_one_trashed_report_after_the_write(): void {
		$id = $this->apply( $this->row() );
		wp_trash_post( $id );
		$this->assertSame( 0, $this->http->count() );
		$this->http->respond_with(
			function ( $args ) use ( $id ) {
				$this->assertSame( 'trash', get_post_status( $id ) );
				$body = json_decode( $args['body'], true );
				$this->assertSame( $id, $body['wp_post_id'] );
				$this->assertSame( 'trashed', $body['state'] );
				$this->assertStringStartsWith( 'https://site.example/show/', $body['post_url'] );
				return array( 200, '{}' );
			}
		);
		$this->page( array() );
		$this->assertSame( 'caught_up', $this->sync->pull()['status'] );
		ShowFM\Sync_Identity::transition( 'trash', 'publish', get_post( $id ) );
		$this->page( array( $this->row() ) );
		$this->page( array(), 1 );
		$this->assertSame( 'caught_up', $this->sync->pull()['status'] );
		$this->assertSame( 4, $this->http->count() );
		$this->assertSame( 'trash', get_post_status( $id ) );

		foreach ( array( 400, 403, 404 ) as $status ) {
			$row                  = $this->row();
			$row['episode_id']    = sprintf( '44444444-4444-4444-8444-%012d', $status );
			$row['episode']['id'] = $row['episode_id'];
			$trashed              = $this->apply( $row );
			wp_trash_post( $trashed );
			$this->http->respond( $status, '{}' );
			$this->page( array(), 1 );
			$this->assertSame( 'caught_up', $this->sync->pull()['status'] );
			$this->assertFalse( get_option( ShowFM\Sync_Local_Reports::PREFIX . self::SITE . '_' . $row['episode_id'] ) );
			$log = get_option( ShowFM\Sync_Log::OPTION );
			$this->assertSame( 'report_' . $status, end( $log )['code'] );
		}
		// Trash followed by permanent deletion still has one terminal report.
		$count = $this->http->count();
		wp_delete_post( $id, true );
		$this->page( array(), 1 );
		$this->assertSame( 'caught_up', $this->sync->pull()['status'] );
		$this->assertSame( $count + 1, $this->http->count() );
	}

	public function test_review_permanent_deletion_reports_saved_identity_after_delete(): void {
		$id = $this->apply( $this->row() );
		wp_delete_post( $id, true );
		$this->assertSame( 0, $this->http->count() );
		$this->http->respond_with(
			function ( $args ) use ( $id ) {
				$this->assertNull( get_post( $id ) );
				$body = json_decode( $args['body'], true );
				$this->assertSame( $id, $body['wp_post_id'] );
				$this->assertSame( 'trashed', $body['state'] );
				$this->assertStringStartsWith( 'https://site.example/show/', $body['post_url'] );
				return array( 200, '{}' );
			}
		);
		$this->page( array() );
		$this->assertSame( 'caught_up', $this->sync->pull()['status'] );
		$this->page( array() );
		$this->assertSame( 'caught_up', $this->sync->pull()['status'] );
		$this->assertSame( 3, $this->http->count() );
		$this->assertNull( get_post( $id ) );

		$row                  = $this->row( 2 );
		$row['episode_id']    = '44444444-4444-4444-8444-444444444444';
		$row['episode']['id'] = $row['episode_id'];
		$other                = $this->apply( $row );
		wp_delete_post( $other, true );
		$key      = ShowFM\Sync_Local_Reports::PREFIX . self::SITE . '_' . $row['episode_id'];
		$snapshot = get_option( $key )['body'];
		$this->http->respond( 503, '{}' );
		$this->page( array() );
		$this->assertSame( 'caught_up', $this->sync->pull()['status'] );
		$entry = get_option( $key );
		$this->assertSame( 1, $entry['attempts'] );
		$this->assertGreaterThan( time(), $entry['next'] );
		$this->assertSame( 0, Sync::state()['retry_at'] );
		$entry['next'] = 0;
		update_option( $key, $entry );
		$this->http->respond_with(
			function ( $args ) use ( $snapshot ) {
				$this->assertSame( $snapshot, json_decode( $args['body'], true ) );
				return array( 200, '{}' );
			}
		);
		$this->page( array() );
		$this->assertSame( 'caught_up', $this->sync->pull()['status'] );
		$this->assertFalse( get_option( $key ) );
	}

	public function test_review_artwork_above_sixteen_megapixels_never_reaches_sideload(): void {
		$processed = 0;
		$spy       = static function ( $file ) use ( &$processed ) {
			++$processed;
			$file['error'] = 'Stop test sideload';
			return $file;
		};
		add_filter( 'wp_handle_sideload_prefilter', $spy );
		$this->http->respond_with(
			static function ( $args ) {
				$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aOuoAAAAASUVORK5CYII=' );
				file_put_contents( $args['filename'], substr_replace( $png, pack( 'NN', 4001, 4000 ), 16, 8 ) );
				return array( 200, '' );
			}
		);
		$this->assertWPError( ( new ShowFM\Sync_Artwork() )->import( 'https://m.cdn.media/17mp.png', 0 ) );
		remove_filter( 'wp_handle_sideload_prefilter', $spy );
		$this->assertSame( 0, $processed );
	}
	public function test_final_configuration_never_exhausts_and_resumes_at_saved_cursor(): void {
		update_option( Sync_Posts::SETTINGS, array( 'post_type' => 'unavailable_type' ) );
		for ( $attempt = 1; $attempt <= 12; ++$attempt ) {
			$this->clear_backoff();
			$this->page( array( $this->row() ) );
			$this->assertSame( 'row_post_type', $this->sync->pull()['status'] );
			$this->assertSame( 0, Sync::state()['cursor'] );
			$this->assertLessThanOrEqual( time() + HOUR_IN_SECONDS, Sync::state()['retry_at'] );
		}
		$status = Plugin::connection()->sync_status();
		$this->assertSame( 0, $status['retry']['attempts'] );
		$this->assertSame( array(), $status['skipped'] );
		$this->assertGreaterThanOrEqual( time() + HOUR_IN_SECONDS - 2, Sync::state()['retry_at'] );
		delete_option( Sync_Posts::SETTINGS );
		$this->clear_backoff();
		$this->page( array( $this->row() ) );
		$this->http->respond( 200, '{}' );
		$this->page( array(), 1 );
		$this->assertSame( 'caught_up', $this->sync->pull()['status'] );
		$this->assertSame( array(), Plugin::connection()->sync_status()['retry'] );
		$this->assertGreaterThan( 0, Sync_Posts::find( self::SITE, self::EPISODE ) );
	}

	public function test_final_missing_author_does_not_use_skip_budget(): void {
		$deny = static function ( $caps, $cap ) {
			return 'publish_posts' === $cap ? array( 'do_not_allow' ) : $caps;
		};
		add_filter( 'map_meta_cap', $deny, 10, 2 );
		for ( $attempt = 1; $attempt <= 7; ++$attempt ) {
			$this->clear_backoff();
			$this->page( array( $this->row() ) );
			$this->assertSame( 'row_author', $this->sync->pull()['status'] );
			$this->assertSame( 0, Sync::state()['cursor'] );
		}
		remove_filter( 'map_meta_cap', $deny );
		$this->assertSame( 0, Plugin::connection()->sync_status()['retry']['attempts'] );
		$this->assertSame( array(), Plugin::connection()->sync_status()['skipped'] );
		WP_CLI::$output = array();
		( new ShowFM\Cli( Plugin::connect(), Plugin::connection() ) )->sync( array( 'status' ), array() );
		$this->assertStringContainsString( 'row_author', wp_json_encode( WP_CLI::$output ) );
		$this->clear_backoff();
		$this->page( array( $this->row() ) );
		$this->http->respond( 200, '{}' );
		$this->page( array(), 1 );
		$this->assertSame( 'caught_up', $this->sync->pull()['status'] );
	}

	public function test_final_site_health_names_configuration_problems_without_http(): void {
		$tests = apply_filters( 'site_status_tests', array( 'direct' => array() ) );
		$this->assertArrayHasKey( 'showfm_sync', $tests['direct'] );
		foreach ( array(
			'row_post_type' => 'post type',
			'row_author'    => 'author',
		) as $code => $label ) {
			Plugin::connection()->save_sync_status(
				array(
					'site'    => self::SITE,
					'retry'   => array(
						'seq'      => 1,
						'code'     => $code,
						'attempts' => 0,
						'at'       => time(),
					),
					'skipped' => array(),
				)
			);
			$health = call_user_func( $tests['direct']['showfm_sync']['test'] );
			$this->assertSame( 'critical', $health['status'] );
			$this->assertStringContainsString( $label, strtolower( $health['description'] ) );
			$this->assertStringContainsString( 'automatically', $health['description'] );
			$this->assertSame( 'showfm_sync', $health['test'] );
		}
		$this->assertSame( 0, $this->http->count() );
	}

	public function test_final_untrash_reattaches_and_replaces_pending_trash_report(): void {
		$id = $this->apply( $this->row() );
		wp_trash_post( $id );
		wp_untrash_post( $id );
		$receipt = ShowFM\Sync_Identity::get( get_post( $id )->guid );
		$this->assertEmpty( $receipt['detached'] ?? false );
		$this->assertSame( 'synced', get_post_meta( $id, '_showfm_sync_state', true ) );
		$this->assertSame( 0, $this->http->count() );
		$this->http->respond_with(
			function ( $args ) use ( $id ) {
				$body = json_decode( $args['body'], true );
				$this->assertSame( $id, $body['wp_post_id'] );
				$this->assertSame( 'draft', $body['state'] );
				$this->assertSame( 'draft', get_post_status( $id ) );
				return array( 200, '{}' );
			}
		);
		$this->page( array() );
		$this->assertSame( 'caught_up', $this->sync->pull()['status'] );
		$row                     = $this->row( 2 );
		$row['episode']['title'] = 'Updated after reattachment';
		$this->page( array( $row ) );
		$this->http->respond( 200, '{}' );
		$this->page( array(), 2 );
		$this->assertSame( 'caught_up', $this->sync->pull()['status'] );
		$this->assertSame( 'Updated after reattachment', get_post( $id )->post_title );
		$this->assertSame( 'publish', get_post_status( $id ) );
		$this->assertSame( '', get_post_meta( $id, '_showfm_edited', true ) );
		wp_trash_post( $id );
		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => 'My change while in the bin',
			)
		);
		wp_untrash_post( $id );
		$this->apply( $this->row( 3 ) );
		$this->assertSame( 'My change while in the bin', get_post( $id )->post_title );
		$this->assertSame( '1', get_post_meta( $id, '_showfm_edited', true ) );
	}

	public function test_final_untrash_preserves_edit_detection_and_reports_actual_state(): void {
		$id = $this->apply( $this->row() );
		wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => 'My edited content',
			)
		);
		$this->apply( $this->row() );
		wp_trash_post( $id );
		$restore = static function () {
			return 'publish';
		};
		add_filter( 'wp_untrash_post_status', $restore );
		wp_untrash_post( $id );
		remove_filter( 'wp_untrash_post_status', $restore );
		$this->assertSame( 'synced', get_post_meta( $id, '_showfm_sync_state', true ) );
		$this->assertSame( '1', get_post_meta( $id, '_showfm_edited', true ) );
		$this->http->respond_with(
			function ( $args ) {
				$this->assertSame( 'published', json_decode( $args['body'], true )['state'] );
				return array( 200, '{}' );
			}
		);
		$this->page( array() );
		$this->assertSame( 'caught_up', $this->sync->pull()['status'] );
		$this->apply( $this->row( 2, 'scheduled' ) );
		$this->assertSame( 'My edited content', get_post( $id )->post_content );
		$this->assertSame( 'future', get_post_status( $id ) );
	}

	public function test_final_untrash_report_survives_inflight_trash_acknowledgement(): void {
		$id = $this->apply( $this->row() );
		wp_trash_post( $id );
		$this->http->respond_with(
			static function () use ( $id ) {
				wp_untrash_post( $id );
				return array( 200, '{}' );
			}
		);
		$this->page( array() );
		$this->assertSame( 'caught_up', $this->sync->pull()['status'] );
		$key     = ShowFM\Sync_Local_Reports::PREFIX . self::SITE . '_' . self::EPISODE;
		$pending = get_option( $key );
		$this->assertIsArray( $pending );
		$this->assertSame( 'draft', $pending['body']['state'] );
		$this->http->respond_with(
			function ( $args ) {
				$this->assertSame( 'draft', json_decode( $args['body'], true )['state'] );
				return array( 200, '{}' );
			}
		);
		$this->page( array() );
		$this->assertSame( 'caught_up', $this->sync->pull()['status'] );
		$this->assertFalse( get_option( $key ) );
	}

	public function test_final_noninteger_tail_is_a_visible_server_contract_problem(): void {
		$tail        = $this->row( 2 );
		$tail['seq'] = '2';
		$this->http->respond(
			200,
			wp_json_encode(
				array(
					'data'   => array( $this->row(), $tail ),
					'cursor' => array(
						'after'    => 0,
						'next'     => 2,
						'latest'   => 2,
						'has_more' => false,
					),
				)
			)
		);
		$this->assertSame( 'invalid_feed', $this->sync->pull()['status'] );
		$this->assertSame( 0, Sync::state()['cursor'] );
		$tests = apply_filters( 'site_status_tests', array( 'direct' => array() ) );
		$this->assertArrayHasKey( 'showfm_sync', $tests['direct'] );
		$health = call_user_func( $tests['direct']['showfm_sync']['test'] );
		$this->assertSame( 'critical', $health['status'] );
		$this->assertStringContainsString( 'server contract', strtolower( $health['description'] ) );
		$this->assertSame( 1, $this->http->count() );
		// A malformed tail cannot be hidden by a cursor matching an earlier integer row.
		$this->clear_backoff();
		$this->http->respond(
			200,
			wp_json_encode(
				array(
					'data'   => array( $this->row(), $tail ),
					'cursor' => array(
						'after'    => 0,
						'next'     => 1,
						'latest'   => 2,
						'has_more' => true,
					),
				)
			)
		);
		$this->assertSame( 'invalid_feed', $this->sync->pull()['status'] );
		$this->assertSame( 0, Sync::state()['cursor'] );
		$this->assertSame( 0, Sync_Posts::find( self::SITE, self::EPISODE ) );
		$this->clear_backoff();
		$this->page( array() );
		$this->assertSame( 'caught_up', $this->sync->pull()['status'] );
		$this->assertSame( 'good', call_user_func( $tests['direct']['showfm_sync']['test'] )['status'] );
	}
}
