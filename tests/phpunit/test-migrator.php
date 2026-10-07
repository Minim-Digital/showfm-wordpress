<?php
/**
 * Migration fixtures and real WordPress integration tests.
 *
 * @package ShowFM
 */

use ShowFM\Api_Client;
use ShowFM\Connection;
use ShowFM\Migration_Catalogue;
use ShowFM\Migration_Cli;
use ShowFM\Migration_Detectors;
use ShowFM\Migration_Matcher;
use ShowFM\Migration_Scanner;
use ShowFM\Migration_Store;
use ShowFM\Migration_Swap;
use ShowFM\Migration_Url;
use ShowFM\Migrator;

/** Tests the migration engine against stored WordPress posts. */
class Test_Migrator extends WP_UnitTestCase {
	const EPISODE = '11111111-2222-4333-8444-555555555555';
	const SECOND  = '21111111-2222-4333-8444-555555555555';
	const PODCAST = '31111111-2222-4333-8444-555555555555';
	/** @var Connection */
	private $connection;
	/** @var ShowFM_Http_Mock */
	private $http;
	/** @var Migrator */
	private $engine;

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->connection = new Connection();
		$this->connection->save( 'showfm_live_MIGRATION12345678901234567890', str_repeat( 'a', 64 ), self::PODCAST, 0 );
		$this->http   = new ShowFM_Http_Mock();
		$this->engine = new Migrator( $this->connection, new Api_Client( $this->connection ) );
		Migration_Store::clear();
		WP_CLI::$output = array();
	}

	public function tear_down(): void {
		$this->http->detach();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * @dataProvider fixtures
	 * @param array $fixture Fixture data.
	 */
	public function test_host_fixture( array $fixture ): void {
		$content = file_get_contents( dirname( __DIR__ ) . '/fixtures/migrator/' . $fixture['file'] );
		$post    = $this->post( $content );
		foreach ( $fixture['meta'] as $key => $value ) {
			update_post_meta( $post->ID, $key, $value );
		}
		$items = Migration_Detectors::detect( $post );
		$this->assertCount( 1, $items, $fixture['file'] );
		$this->assertSame( $fixture['host'], $items[0]['host'] );
		foreach ( $fixture['expected'] as $key => $value ) {
			$this->assertSame( $value, $items[0][ $key ] ?? null, $key );
		}
		$this->assertSame( $content, substr( $post->post_content, $items[0]['offset'], $items[0]['length'] ) );
		$this->assertSame( 0, $this->http->count() );
	}

	public function fixtures(): array {
		$data = json_decode( file_get_contents( dirname( __DIR__ ) . '/fixtures/migrator/manifest.json' ), true );
		return array_combine(
			array_column( $data, 'file' ),
			array_map(
				static function ( $row ) {
					return array( $row );
				},
				$data
			)
		);
	}

	public function test_normalisation_is_conservative_and_unwraps_nested_trackers(): void {
		$this->assertSame( 'http://media.example/Hello.mp3', Migration_Url::normalise( 'HTTP://MEDIA.EXAMPLE/Hello.mp3?episode=12&amp;x=3#play' ) );
		$this->assertSame( 'https://media.example/Hello.mp3', Migration_Url::normalise( 'https://dts.podtrac.com/redirect.mp3/chtbl.com/track/ABCD/op3.dev/e/https://MEDIA.EXAMPLE/Hello.mp3?token=private' ) );
		$this->assertNotSame( Migration_Url::normalise( 'https://media.example/A.mp3' ), Migration_Url::normalise( 'https://media.example/a.mp3' ) );
		$this->assertSame( Migration_Url::normalise( 'https://media.example/a?e=1' ), Migration_Url::normalise( 'https://media.example/a?e=2' ) );
		foreach ( array( 'javascript:alert(1)', 'file:///tmp/x' ) as $url ) {
			$this->assertNull( Migration_Url::normalise( $url ) );
		}
		$this->assertSame( 'https://media.example/a', Migration_Url::normalise( 'https://user:pass@media.example/a' ) );
		$this->assertStringStartsWith( 'https://dts.podtrac.com.evil/', Migration_Url::normalise( 'https://dts.podtrac.com.evil/redirect.mp3/media.example/a' ) );
	}

	public function test_match_order_ambiguity_and_date_boundary(): void {
		$a      = $this->episode();
		$b      = array_merge(
			$a,
			array(
				'id'        => self::SECOND,
				'audio_url' => 'https://elsewhere.example/audio.mp3',
				'source'    => array(
					'enclosure_sha256' => hash( 'sha256', 'https://elsewhere.example/audio.mp3' ),
					'guid_sha256'      => hash( 'sha256', 'original-guid' ),
				),
			)
		);
		$embed  = array_merge( $a, array( 'guid' => 'original-guid' ) );
		$result = Migration_Matcher::match( $embed, array( $b, $a ) );
		$this->assertSame( 'enclosure', $result['method'] );
		$this->assertSame( self::EPISODE, $result['candidates'][0]['id'] );
		unset( $embed['audio_url'] );
		$this->assertSame( 'guid', Migration_Matcher::match( $embed, array( $a, $b ) )['method'] );
		unset( $embed['guid'] );
		$this->assertSame( 'ambiguous', Migration_Matcher::match( $embed, array( $a, $b ) )['status'] );
		$embed['published_at'] = '2024-01-03T12:00:00Z';
		$this->assertSame( 'ambiguous', Migration_Matcher::match( $embed, array( $a ) )['status'] );
		$embed['published_at'] = '2024-01-03T12:00:01Z';
		$this->assertSame( 'unmatched', Migration_Matcher::match( $embed, array( $a ) )['status'] );
		$this->assertSame( 'unmatched', Migration_Matcher::match( array( 'episode_id' => self::EPISODE ), array( $a ) )['status'] );
		$this->assertSame( 'matched', Migration_Matcher::match( $a, array( $a, $a ) )['status'] );
	}

	public function test_multiple_players_do_not_inherit_the_posts_title(): void {
		$post  = $this->post( '<iframe src="https://open.spotify.com/embed/episode/abc"></iframe><iframe src="https://share.transistor.fm/e/def"></iframe>' );
		$items = Migration_Detectors::detect( $post );
		$this->assertCount( 2, $items );
		$this->assertArrayNotHasKey( 'title', $items[0] );
		$this->assertArrayNotHasKey( 'title', $items[1] );
	}

	public function test_negative_examples_and_show_players_are_not_swapped(): void {
		$post = $this->post( '<pre>[powerpress]</pre><code>[ss_player]</code>[[powerpress]]<!-- [powerpress] --><a href="https://open.spotify.com/episode/abc">Listen</a><iframe src="https://spotify.com.evil/embed/episode/a"></iframe>' );
		$this->assertSame( array(), Migration_Detectors::detect( $post ) );
		$post   = $this->post( '<iframe src="https://open.spotify.com/embed/show/abc"></iframe>' );
		$report = $this->scan( $post );
		$this->assertSame( 'unmatched', $report['items'][0]['status'] );
	}

	public function test_metadata_only_and_default_shortcodes_are_deduplicated(): void {
		$post = $this->post( 'Before' );
		update_post_meta( $post->ID, 'audio_file', $this->episode()['audio_url'] );
		$items = Migration_Detectors::detect( $post );
		$this->assertSame( 'audio_file', $items[0]['source'] );
		$this->assertSame( 0, $items[0]['length'] );
		$report = $this->scan( $post );
		$result = Migration_Swap::apply( $this->connection, $report );
		$this->assertSame( 'swapped', $result['status'] );
		$this->assertStringStartsWith( "Before\n\n<!-- wp:showfm/player", get_post( $post->ID )->post_content );
		$this->assertSame( $this->episode()['audio_url'], get_post_meta( $post->ID, 'audio_file', true ), 'Keep feed metadata intact.' );
		$this->assertSame( 'Before', get_post( $result['revision_id'] )->post_content );
	}

	public function test_swap_preserves_every_other_byte_and_revision_and_is_idempotent(): void {
		$before = "<p>Été \\path &amp; <b>hello</b></p>\r\n<script>const a = 'keep';</script>\n";
		$embed  = '[powerpress url="https://media.example/Hello.mp3"]';
		$after  = "\n<p data-x='odd'>After</p>\n[powerpress url=\"https://other.example/unmatched.mp3\"]";
		$post   = $this->post( $before . $embed . $after );
		$report = $this->scan( $post );
		// Keep only the strong URL match. Different source is not given post-title hints.
		$this->assertSame( 'unmatched', $report['items'][1]['status'] );
		$result = Migration_Swap::apply( $this->connection, $report );
		$this->assertNotWPError( $result );
		$saved = get_post( $post->ID )->post_content;
		$this->assertStringStartsWith( $before . '<!-- wp:showfm/player ', $saved );
		$this->assertStringEndsWith( $after, $saved );
		$this->assertSame( $post->post_content, get_post( $result['revision_id'] )->post_content );
		$block = parse_blocks( $saved )[1];
		$this->assertSame( self::EPISODE, $block['attrs']['episode'] );
		$this->assertSame( self::PODCAST, $block['attrs']['podcast'] );
		$this->assertSame( 'Hello', $block['attrs']['snapshot']['title'] );
		$count = count( wp_get_post_revisions( $post->ID ) );
		Migration_Swap::apply( $this->connection, $result );
		$this->assertSame( $saved, get_post( $post->ID )->post_content );
		$this->assertCount( $count, wp_get_post_revisions( $post->ID ) );
		$rescanned = $this->scan( get_post( $post->ID ) );
		$this->assertCount( 2, $rescanned['items'] );
		$this->assertSame( 'already_showfm', $rescanned['items'][0]['status'] );
		$this->assertSame( 'ambiguous', $rescanned['items'][1]['status'] );
		wp_restore_post_revision( $result['revision_id'] );
		$restored = get_post( $post->ID )->post_content;
		$this->assertStringContainsString( $embed, $restored );
		$this->assertStringNotContainsString( 'wp:showfm/player', $restored );
		if ( ! is_multisite() ) {
			$this->assertSame( $post->post_content, $restored );
		}
	}

	public function test_ambiguous_choice_only_accepts_a_reported_candidate(): void {
		$post     = $this->post( '[powerpress url="https://media.example/Hello.mp3"]' );
		$episodes = array( $this->episode(), array_merge( $this->episode(), array( 'id' => self::SECOND ) ) );
		$report   = Migration_Scanner::scan(
			$post,
			static function () use ( $episodes ) {
				return $episodes;
			}
		);
		$this->assertSame( 'ambiguous', $report['items'][0]['status'] );
		$this->assertSame( $report, Migration_Swap::apply( $this->connection, $report ) );
		$this->assertWPError( Migration_Swap::apply( $this->connection, $report, array( 1 => self::PODCAST ) ) );
		$result = Migration_Swap::apply( $this->connection, $report, array( 1 => self::SECOND ) );
		$this->assertSame( 'swapped', $result['status'] );
		$this->assertStringContainsString( self::SECOND, get_post( $post->ID )->post_content );
	}

	public function test_stale_report_and_disabled_revisions_refuse_without_content_changes(): void {
		$post   = $this->post( '[powerpress]' );
		$report = $this->scan( $post );
		wp_update_post(
			array(
				'ID'           => $post->ID,
				'post_content' => 'New content',
			)
		);
		$this->assertSame( 'showfm_stale_report', Migration_Swap::apply( $this->connection, $report )->get_error_code() );
		$this->assertSame( 'New content', get_post( $post->ID )->post_content );
		$post = $this->post( '[powerpress]' );
		add_filter( 'wp_revisions_to_keep', '__return_zero' );
		try {
			$this->assertSame( 'showfm_revisions_disabled', Migration_Swap::apply( $this->connection, $this->scan( $post ) )->get_error_code() );
		} finally {
			remove_filter( 'wp_revisions_to_keep', '__return_zero' );
		}
	}

	public function test_pre_edit_revision_survives_a_limit_of_one(): void {
		$post = $this->post( '[powerpress]' );
		add_filter( 'wp_revisions_to_keep', array( $this, 'one_revision' ) );
		try {
			$result = Migration_Swap::apply( $this->connection, $this->scan( $post ) );
			$this->assertSame( $post->post_content, get_post( $result['revision_id'] )->post_content );
		} finally {
			remove_filter( 'wp_revisions_to_keep', array( $this, 'one_revision' ) );
		}
	}

	public function one_revision(): int {
		return 1; }

	public function test_scanner_batches_resume_with_gaps_and_skips_other_post_types(): void {
		$this->catalogue_response();
		$ids = self::factory()->post->create_many(
			52,
			array(
				'post_status'   => 'publish',
				'post_content'  => '[powerpress]',
				'post_title'    => 'Hello',
				'post_date_gmt' => '2024-01-02 12:00:00',
			)
		);
		self::factory()->post->create( array( 'post_status' => 'draft' ) );
		self::factory()->post->create(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			)
		);
		$state = $this->engine->start();
		$this->assertNotWPError( $state );
		$first = $this->engine->batch();
		$this->assertFalse( $first['complete'] );
		$this->assertCount( 50, iterator_to_array( Migration_Store::reports( $state['run'] ) ) );
		wp_delete_post( $ids[0], true );
		$second = ( new Migrator( $this->connection, new Api_Client( $this->connection ) ) )->batch();
		$this->assertTrue( $second['complete'] );
		$this->assertCount( 52, iterator_to_array( Migration_Store::reports( $state['run'] ) ) );
		$this->assertSame( 2, $this->http->count(), 'No HTTP while scanning or resuming.' );
		global $wpdb;
		$this->assertSame( '0', (string) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'showfm_migration_%' AND autoload IN ('yes','on','auto','auto-on')" ) );
	}

	public function test_catalogue_uses_existing_fields_only_and_refuses_partial_failure(): void {
		$this->catalogue_response();
		$state = $this->engine->start();
		$this->assertNotWPError( $state );
		$episodes = iterator_to_array( Migration_Catalogue::episodes( $state['run'], $state['pages'] ) );
		$expected = $this->episode();
		unset( $expected['audio_url'] );
		$this->assertSame( array( $expected ), $episodes );
		$this->assertArrayNotHasKey( 'guid', $episodes[0] );
		$this->assertSame( 'https://api.show.fm/v1/me/podcasts?limit=50', $this->http->requests[0]['url'] );
		$this->assertStringEndsWith( '/episodes?status=published&limit=50', $this->http->requests[1]['url'] );
		$this->assertSame( 2, $this->http->count(), 'List responses are sufficient; no detail requests.' );
		$this->http->respond( 503 );
		$this->assertWPError( $this->engine->start() );
		$this->assertSame( $state, Migration_Store::state() );
	}

	public function test_not_connected_and_cli_capabilities_refuse_before_http(): void {
		foreach ( array( 0, self::factory()->user->create( array( 'role' => 'subscriber' ) ), self::factory()->user->create( array( 'role' => 'editor' ) ) ) as $user ) {
			wp_set_current_user( $user );
			$this->assertSame( 'showfm_forbidden', $this->engine->start()->get_error_code() );
			try {
				( new Migration_Cli( $this->engine ) )( array(), array( 'dry-run' => true ) );
				$this->fail( 'CLI must reject an unauthorised user.' );
			} catch ( ShowFM_Cli_Halt $halt ) {
				$this->assertStringContainsString( '--user', $halt->getMessage() );
			}
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->connection->disconnect();
		$this->assertSame( 'showfm_not_connected', $this->engine->start()->get_error_code() );
		$this->assertSame( 0, $this->http->count() );
	}

	public function test_cli_dry_run_json_then_resume_swap(): void {
		$post = $this->post( '[powerpress]' );
		$this->catalogue_response();
		$cli = new Migration_Cli( $this->engine );
		$cli(
			array(),
			array(
				'dry-run' => true,
				'post'    => (string) $post->ID,
				'format'  => 'json',
			)
		);
		$json = json_decode( implode( "\n", array_column( WP_CLI::$output, 1 ) ), true );
		$this->assertTrue( $json['complete'] );
		$this->assertCount( 1, $json['reports'] );
		$this->assertSame( '[powerpress]', get_post( $post->ID )->post_content );
		WP_CLI::$output = array();
		$cli(
			array(),
			array(
				'yes'    => true,
				'resume' => true,
			)
		);
		$this->assertStringContainsString( '<!-- wp:showfm/player', get_post( $post->ID )->post_content );
		$this->assertSame( 2, $this->http->count() );
	}

	public function test_report_and_connection_are_per_blog(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
		$this->catalogue_response();
		$state = $this->engine->start();
		$other = self::factory()->blog->create();
		switch_to_blog( $other );
		try {
			$this->assertSame( array(), Migration_Store::state() );
			$this->assertFalse( $this->connection->is_connected() );
			$this->assertWPError( $this->engine->batch() );
		} finally {
			restore_current_blog();
		}
		$this->assertSame( $state, Migration_Store::state() );
	}

	public function test_ssp_referenced_episode_and_changed_meta_are_checked(): void {
		$source = $this->post( 'Episode' );
		update_post_meta( $source->ID, 'audio_file', $this->episode()['audio_url'] );
		$post   = $this->post( '<!-- wp:seriously-simple-podcasting/castos-html-player {"episodeId":"' . $source->ID . '"} /-->' );
		$report = $this->scan( $post );
		$this->assertSame( 'enclosure', $report['items'][0]['method'] );
		update_post_meta( $source->ID, 'audio_file', 'https://changed.example/audio.mp3' );
		$this->assertSame( 'showfm_stale_report', Migration_Swap::apply( $this->connection, $report )->get_error_code() );
	}

	public function test_legacy_auto_players_are_suppressed_only_while_the_migrated_block_exists(): void {
		$post = $this->post( 'Hello' );
		update_post_meta( $post->ID, 'audio_file', $this->episode()['audio_url'] );
		$this->assertTrue( ShowFM\Migration_Legacy::ssp( true, $post ) );
		$result = Migration_Swap::apply( $this->connection, $this->scan( $post ) );
		$this->assertFalse( ShowFM\Migration_Legacy::ssp( true, get_post( $post->ID ) ) );
		wp_restore_post_revision( $result['revision_id'] );
		$this->assertTrue( ShowFM\Migration_Legacy::ssp( true, get_post( $post->ID ) ) );

		$post = $this->post( 'PowerPress' );
		update_post_meta( $post->ID, 'enclosure', $this->episode()['audio_url'] . "\n123\naudio/mpeg" );
		$result          = Migration_Swap::apply( $this->connection, $this->scan( $post ) );
		$GLOBALS['post'] = get_post( $post->ID );
		$this->assertSame( array(), ShowFM\Migration_Legacy::powerpress( array() ) );
		$check = static function () {
			return ShowFM\Migration_Legacy::powerpress( array() );
		};
		add_filter( 'the_content', $check, PHP_INT_MAX );
		try {
			$this->assertSame( array( 'disable_appearance' => 1 ), apply_filters( 'the_content', '' ) );
			add_post_meta( $post->ID, 'enclosure', 'https://unmatched.example/audio.mp3' );
			$this->assertSame( array(), apply_filters( 'the_content', '' ), 'An unmatched enclosure keeps its original player.' );
		} finally {
			remove_filter( 'the_content', $check, PHP_INT_MAX );
			unset( $GLOBALS['post'] );
		}
		$this->assertSame( 0, $this->http->count() );
	}

	public function test_pagination_follows_encoded_cursor_and_rejects_repetition(): void {
		$this->http->respond(
			200,
			wp_json_encode(
				array(
					'data'       => array(),
					'pagination' => array( 'next_cursor' => 'opaque+/=' ),
				)
			)
		);
		$this->catalogue_response();
		$state = $this->engine->start();
		$this->assertNotWPError( $state );
		$this->assertStringEndsWith( 'limit=50&cursor=opaque%2B%2F%3D', $this->http->requests[1]['url'] );
		for ( $i = 0; $i < 2; ++$i ) {
			$this->http->respond(
				200,
				wp_json_encode(
					array(
						'data'       => array(),
						'pagination' => array( 'next_cursor' => 'same' ),
					)
				)
			);
		}
		$this->assertWPError( $this->engine->start() );
		$this->assertSame( $state, Migration_Store::state() );
	}

	public function test_key_refusal_and_rate_limit_stop_catalogue_reads(): void {
		$this->http->respond( 429, '', array( 'Retry-After' => '90' ) );
		$this->assertWPError( $this->engine->start() );
		$this->assertWPError( $this->engine->start() );
		$this->assertSame( 1, $this->http->count() );
		delete_option( Api_Client::RATE_LIMIT_OPTION );
		$pending             = get_option( Migration_Catalogue::PENDING );
		$pending['retry_at'] = 0;
		update_option( Migration_Catalogue::PENDING, $pending, false );
		$this->http->respond( 401 );
		$this->assertWPError( $this->engine->start() );
		$this->assertWPError( $this->engine->start() );
		$this->assertSame( 2, $this->http->count() );
	}

	public function test_block_metadata_comments_and_collection_shortcodes_are_not_replaced(): void {
		$post = $this->post( '<!-- wp:paragraph {"metadata":{"name":"[powerpress]"}} --><p>text</p><!-- /wp:paragraph -->' );
		$this->assertSame( array(), Migration_Detectors::detect( $post ) );
		foreach ( array( '<iframe src="https://player.captivate.fm/show/11111111-2222-4333-8444-555555555555"></iframe>', '<iframe src="https://share.transistor.fm/e/showname/playlist"></iframe>', '[ss_podcast]', '[podcast_playlist]', '[podcast_episode content="title,details"]', '[powerpress channel="other"]', '<!-- wp:seriously-simple-podcasting/playlist-player /-->' ) as $content ) {
			$this->assertSame( 'unmatched', $this->scan( $this->post( $content ) )['items'][0]['status'] );
		}
	}

	public function test_reconnection_requires_a_fresh_report_and_single_post_must_be_editable(): void {
		$id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$this->assertWPError( $this->engine->start( $id ) );
		$this->assertSame( 0, $this->http->count() );
		$post = $this->post( '[powerpress]' );
		$this->catalogue_response();
		$this->engine->start( $post->ID );
		$this->engine->batch();
		$this->connection->save( 'showfm_live_DIFFERENT1234567890123456789', str_repeat( 'b', 64 ), self::PODCAST, 0 );
		$this->assertWPError( $this->engine->batch() );
		$this->assertWPError( $this->engine->swap( $post->ID ) );
		$this->assertSame( '[powerpress]', get_post( $post->ID )->post_content );
	}

	public function test_cli_choices_and_confirmation_are_validated_before_writes(): void {
		foreach ( array( '0:1:' . self::EPISODE, '1:0:' . self::EPISODE, '1:1:garbage', '1:1:' . self::EPISODE . ',1:1:' . self::SECOND ) as $choice ) {
			$this->assertWPError( Migration_Cli::choices( $choice ) );
		}
		$this->assertSame( array( 1 => array( 2 => self::EPISODE ) ), Migration_Cli::choices( '1:2:' . self::EPISODE ) );
		try {
			( new Migration_Cli( $this->engine ) )( array(), array() );
			$this->fail( 'A mode is required.' );
		} catch ( ShowFM_Cli_Halt $halt ) {
			$this->assertStringContainsString( '--dry-run', $halt->getMessage() );
		}
		$this->assertSame( 0, $this->http->count() );
	}

	private function episode(): array {
		return array(
			'id'           => self::EPISODE,
			'podcast_id'   => self::PODCAST,
			'title'        => 'Hello',
			'published_at' => '2024-01-02T12:00:00Z',
			'audio_url'    => 'https://media.example/Hello.mp3',
			'source'       => array(
				'enclosure_sha256' => hash( 'sha256', 'https://media.example/Hello.mp3' ),
				'guid_sha256'      => null,
			),
			'rss_guid'     => null,
		);
	}

	private function post( string $content ): WP_Post {
		$id = self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_title'    => 'Hello',
				'post_date'     => '2024-01-02 12:00:00',
				'post_date_gmt' => '2024-01-02 12:00:00',
			)
		);
		// Fixtures represent existing imported content, including old scripts on multisite.
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_content' => $content ), array( 'ID' => $id ) );
		clean_post_cache( $id );
		if ( '[powerpress]' === $content ) {
			update_post_meta( $id, 'enclosure', 'https://media.example/Hello.mp3' );
		}
		return get_post( $id );
	}

	private function scan( WP_Post $post ): array {
		return Migration_Scanner::scan(
			$post,
			function () {
				return array( $this->episode() );
			}
		);
	}

	private function catalogue_response(): void {
		$this->http->respond(
			200,
			wp_json_encode(
				array(
					'data'       => array( array( 'id' => self::PODCAST ) ),
					'pagination' => array( 'next_cursor' => null ),
				)
			)
		);
		$this->http->respond(
			200,
			wp_json_encode(
				array(
					'data'       => array( array_diff_key( $this->episode(), array( 'audio_url' => true ) ) + array( 'status' => 'published' ) ),
					'pagination' => array( 'next_cursor' => null ),
				)
			)
		);
	}
}
