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

	public function test_partial_page_failure_never_advances_cursor_and_replay_has_no_duplicate(): void {
		$row2                  = $this->row( 2 );
		$row2['episode_id']    = '44444444-4444-4444-8444-444444444444';
		$row2['episode']['id'] = $row2['episode_id'];
		$this->page( array( $this->row(), $row2 ) );
		$fail = static function ( $is_empty, $post ) use ( $row2 ) {
			return false !== strpos( $post['guid'] ?? '', $row2['episode_id'] ) ? true : $is_empty;
		};
		add_filter( 'wp_insert_post_empty_content', $fail, 10, 2 );
		$this->sync->pull();
		remove_filter( 'wp_insert_post_empty_content', $fail, 10 );
		$this->assertSame( 0, Sync::state()['cursor'] );
		$id = Sync_Posts::find( self::SITE, self::EPISODE );
		$this->assertGreaterThan( 0, $id );
		$this->clear_backoff();
		$this->http->respond( 200, '{}' ); // Report retained from the successful first row.
		$this->page( array( $this->row(), $row2 ) );
		$this->http->respond( 200, '{}' );
		$this->http->respond( 200, '{}' );
		$this->page( array(), 2 );
		$this->assertSame( 'caught_up', $this->sync->pull()['status'] );
		$this->assertSame( $id, Sync_Posts::find( self::SITE, self::EPISODE ) );
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

	public function test_report_failure_retains_outbox_then_retries_without_duplicate_posts(): void {
		$this->page( array( $this->row() ) );
		$this->http->respond( 503, '{}' );
		$this->assertSame( 'transient_failure', $this->sync->pull()['status'] );
		$this->assertSame( 1, Sync::state()['cursor'] );
		$this->assertCount( 1, Sync::state()['reports'] );
		$this->assertSame( 'backoff', $this->sync->pull()['status'] );
		$this->assertSame( 2, $this->http->count() );
		$this->clear_backoff();
		$this->http->respond( 200, '{}' );
		$this->page( array(), 1 );
		$this->assertSame( 'caught_up', $this->sync->pull()['status'] );
		$this->assertSame( array(), Sync::state()['reports'] );
		$this->assertSame( $this->http->requests[1]['args']['body'], $this->http->requests[2]['args']['body'] );
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
		$this->sync->pull();
		$this->assertSame( 1, $this->http->count() );
	}

	public function test_malformed_page_is_rejected_before_first_apply(): void {
		$row           = $this->row( 2 );
		$row['action'] = 'surprise';
		$this->page( array( $this->row(), $row ) );
		$this->assertSame( 'invalid_feed', $this->sync->pull()['status'] );
		$this->assertSame( 0, Sync_Posts::find( self::SITE, self::EPISODE ) );
		$this->assertSame( 0, Sync::state()['cursor'] );
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
		$other->prefix = $original->prefix;
		$this->assertSame( $original->dbname, $other->dbname );
		try {
			$wpdb = $other;
			$lock = new Sync_Lock();
			$this->assertTrue( $lock->acquire() );
			$wpdb = $original;
			wp_cache_flush();
			$contender = new Sync_Lock();
			$this->assertFalse( $contender->acquire() );
			$other->close(); // A crashed PHP process closes its DB session too.
			$acquired = false;
			for ( $attempt = 0; $attempt < 100 && ! $acquired; ++$attempt ) {
				usleep( 10000 );
				$acquired = $contender->acquire();
			}
				$this->assertTrue( $acquired );
			$contender->release();
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

	public function test_refused_detached_report_does_not_block_access_restoration(): void {
		$this->apply( $this->row() );
		$this->page( array( $this->row( 2, '', 'access_removed' ) ) );
		$this->http->respond( 404, '{}' );
		$this->page( array(), 2 );
		$this->assertSame( 'reports_pending', $this->sync->pull()['status'] );
		$this->assertSame( 2, Sync::state()['cursor'] );
		$this->assertCount( 1, Sync::state()['reports'] );
		$this->clear_backoff();
		$this->http->respond( 200, '{}' );
		$this->page( array( $this->row( 3 ) ), 2 );
		$this->http->respond( 200, '{}' );
		$this->page( array(), 3 );
		$this->assertSame( 'caught_up', $this->sync->pull()['status'] );
	}

	public function test_recover_crash_after_initial_insert_before_identity_meta(): void {
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
		$attachment = $artwork->import( 'https://images.example/cover.png', $id );
		$this->assertIsInt( $attachment );
		$this->assertTrue( wp_attachment_is_image( $attachment ) );
		$this->assertSame( $attachment, $artwork->import( 'https://images.example/cover.png', $id ) );
		$this->assertSame( 1, $this->http->count() );
		$this->assertSame( 0, $this->http->last()['args']['redirection'] );
		$this->assertTrue( $this->http->last()['args']['reject_unsafe_urls'] );
		$this->assertArrayNotHasKey( 'Authorization', $this->http->last()['args']['headers'] );
		$this->http->respond( 200, wp_json_encode( array( 'data' => array( 'artwork' => array( 'url' => 'https://images.example/cover.png' ) ) ) ) );
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
		$this->assertWPError( $artwork->import( 'https://images.example/redirect.png', 0 ) );
		$this->http->respond_with(
			static function ( $args ) {
				file_put_contents( $args['filename'], '<svg><script>alert(1)</script></svg>' );
				return array( 200, '' );
			}
		);
		$this->assertWPError( $artwork->import( 'https://images.example/fake.png', 0 ) );
	}
	public function test_lost_database_session_stops_before_post_or_cursor_writes(): void {
		$row = $this->row();
		$this->http->respond_with(
			static function () use ( $row ) {
				( new Sync_Lock() )->release();
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

	public function test_metadata_failure_does_not_acknowledge_the_page(): void {
		$this->page( array( $this->row() ) );
		$fail = static function ( $check, $id, $key ) {
			return '_showfm_content_hash' === $key ? false : $check;
		};
		add_filter( 'update_post_metadata', $fail, 10, 3 );
		$this->assertSame( 'apply_failed', $this->sync->pull()['status'] );
		remove_filter( 'update_post_metadata', $fail, 10 );
		$this->assertSame( 0, Sync::state()['cursor'] );
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

	public function test_artwork_waits_for_publication_and_retries_missing_public_metadata(): void {
		update_option( Sync_Posts::SETTINGS, array( 'featured_image' => true ) );
		$row = $this->row( 1, 'scheduled' );
		$id  = $this->apply( $row );
		$this->assertSame( 0, $this->http->count() );
		$row['episode']['status'] = 'published';
		$this->page( array( $row ) );
		$this->http->respond( 404, '{}' );
		$this->assertSame( 'showfm_artwork_metadata', $this->sync->pull()['status'] );
		$this->assertSame( 0, Sync::state()['cursor'] );
		$this->assertSame( '', get_post_meta( $id, '_showfm_artwork_done', true ) );
		$this->clear_backoff();
		$this->page( array( $row ) );
		$this->http->respond( 200, '{"data":{"artwork":{"url":null}}}' );
		$this->http->respond( 200, '{}' );
		$this->page( array(), 1 );
		$this->assertSame( 'caught_up', $this->sync->pull()['status'] );
		$this->assertSame( '1', get_post_meta( $id, '_showfm_artwork_done', true ) );
	}
	public function test_dry_run_does_not_acknowledge_previewed_rows_to_the_server(): void {
		$this->page( array( $this->row() ), 0, true );
		$this->assertSame( 'dry_run_limit', $this->sync->pull( true )['status'] );
		$this->assertSame( 1, $this->http->count() );
		$this->assertStringContainsString( 'after=0', $this->http->last()['url'] );
		$this->assertSame( 0, Sync::state()['cursor'] );
	}
}
