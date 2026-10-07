<?php
/**
 * Regressions for the independent review of b435fc36 and the removed-evidence finding.
 *
 * @package ShowFM
 */

use ShowFM\Api_Client;
use ShowFM\Connection;
use ShowFM\Migration_Cli;
use ShowFM\Migration_Detectors;
use ShowFM\Migration_Legacy;
use ShowFM\Migration_Matcher;
use ShowFM\Migration_Scanner;
use ShowFM\Migration_Store;
use ShowFM\Migration_Swap;
use ShowFM\Migration_Url;
use ShowFM\Migrator;

/** Review regressions use real posts, metadata, cache and SQL writes. */
class Test_Migration_Review extends WP_UnitTestCase {
	const EPISODE = '11111111-2222-4333-8444-555555555555';
	const PODCAST = '31111111-2222-4333-8444-555555555555';
	/** @var Connection */
	private $connection;
	/** @var ShowFM_Http_Mock */
	private $http;

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->connection = new Connection();
		$this->connection->save( 'showfm_live_MIGRATION12345678901234567890', str_repeat( 'a', 64 ), self::PODCAST, 0 );
		$this->http = new ShowFM_Http_Mock();
		Migration_Store::clear();
		WP_CLI::$output = array();
	}

	public function tear_down(): void {
		$this->http->detach();
		parent::tear_down();
	}

	public function test_two_players_in_one_wrapper_are_ambiguous_and_never_overlap(): void {
		$post   = $this->post( '<!-- wp:html --><iframe src="https://www.buzzsprout.com/1/episodes/10"></iframe><iframe src="https://www.buzzsprout.com/1/episodes/11"></iframe><!-- /wp:html -->' );
		$report = $this->scan( $post );
		$this->assertSame( 'ambiguous', $report['status'] );
		$this->assertCount( 2, $report['items'] );
		$this->assertLessThanOrEqual( $report['items'][1]['offset'], $report['items'][0]['offset'] + $report['items'][0]['length'] );
		$this->assertWPError(
			Migration_Swap::apply(
				$this->connection,
				$report,
				array(
					1 => self::EPISODE,
					2 => self::EPISODE,
				)
			)
		);
		$this->assertSame( $post->post_content, get_post( $post->ID )->post_content );
	}

	public function test_mixed_post_keeps_reporting_legacy_embeds(): void {
		$post   = $this->post( '<!-- wp:showfm/player {"episode":"' . self::EPISODE . '"} /-->[powerpress url="https://media.example/a.mp3"]' );
		$report = $this->scan( $post );
		$this->assertCount( 2, $report['items'] );
		$this->assertSame( 'already_showfm', $report['items'][0]['status'] );
		$this->assertSame( 'matched', $report['items'][1]['status'] );
	}

	public function test_removed_referenced_evidence_refuses_swap(): void {
		$source = $this->post( 'Source' );
		update_post_meta( $source->ID, 'audio_file', 'https://media.example/a.mp3' );
		$post   = $this->post( '[podcast_episode episode="' . $source->ID . '" content="player"]' );
		$report = $this->scan( $post );
		$this->assertSame( 'matched', $report['items'][0]['status'] );
		wp_update_post(
			array(
				'ID'          => $source->ID,
				'post_status' => 'draft',
			)
		);
		$this->assertWPError( Migration_Swap::apply( $this->connection, $report ) );
	}

	public function test_stale_object_cache_cannot_overwrite_an_editor(): void {
		$post   = $this->post( '[powerpress url="https://media.example/a.mp3"]' );
		$report = $this->scan( $post );
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_content' => 'Concurrent edit' ), array( 'ID' => $post->ID ) );
		wp_cache_set( $post->ID, $post, 'posts' );
		$this->assertWPError( Migration_Swap::apply( $this->connection, $report ) );
		$this->assertSame( 'Concurrent edit', $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $post->ID ) ) );
	}

	public function test_edit_during_revision_save_cannot_be_overwritten(): void {
		$post   = $this->post( '[powerpress url="https://media.example/a.mp3"]' );
		$report = $this->scan( $post );
		$edit   = static function () use ( $post ) {
			global $wpdb;
			$wpdb->update( $wpdb->posts, array( 'post_content' => 'Editor won' ), array( 'ID' => $post->ID ) );
		};
		add_action( '_wp_put_post_revision', $edit );
		try {
			$this->assertWPError( Migration_Swap::apply( $this->connection, $report ) );
		} finally {
			remove_action( '_wp_put_post_revision', $edit );
		}
	}

	public function test_title_date_without_show_evidence_requires_a_choice(): void {
		$episode = $this->episode();
		$this->assertSame(
			'ambiguous',
			Migration_Matcher::match(
				array(
					'title'        => 'Hello',
					'published_at' => '2024-01-02T12:00:00Z',
				),
				array( $episode )
			)['status']
		);
	}

	public function test_yes_without_dry_run_refuses_before_http(): void {
		$cli = new Migration_Cli( new Migrator( $this->connection, new Api_Client( $this->connection ) ) );
		try {
			$cli( array(), array( 'yes' => true ) );
			$this->fail( 'A completed dry run is required.' );
		} catch ( ShowFM_Cli_Halt $halt ) {
			$this->assertStringContainsString( 'dry run', $halt->getMessage() );
		}
		$this->assertSame( 0, $this->http->count() );
	}

	public function test_third_party_filters_accept_unexpected_values(): void {
		$this->assertNull( Migration_Legacy::ssp( null, null ) );
		$this->assertSame( 'yes', Migration_Legacy::ssp( 'yes', new stdClass() ) );
	}

	public function test_pcre_failure_is_a_scan_error(): void {
		$post = $this->post( '[powerpress url="https://media.example/a.mp3"]' );
		$old  = ini_set( 'pcre.backtrack_limit', '0' );
		try {
			$this->assertSame( 'error', $this->scan( $post )['status'] );
		} finally {
			ini_set( 'pcre.backtrack_limit', $old );
		}
	}

	public function test_unclosed_opener_scan_has_a_time_bound(): void {
		$post  = $this->post( str_repeat( '<iframe ', 30000 ) );
		$start = microtime( true );
		$this->assertSame( 'error', $this->scan( $post )['status'] );
		$this->assertLessThan( 2, microtime( true ) - $start );
	}

	/**
	 * @dataProvider provenance_vectors
	 * @param array $vector App-side version 1 contract vector.
	 */
	public function test_all_provenance_vectors( array $vector ): void {
		$actual = 'enclosure' === $vector['kind'] ? Migration_Url::normalise( $vector['input'] ) : Migration_Url::guid( $vector['input'] );
		$this->assertSame( $vector['normalised'], $actual, $vector['name'] );
		$this->assertSame( $vector['sha256'], Migration_Url::fingerprint( $actual ), $vector['name'] );
	}

	public function provenance_vectors(): array {
		$vectors = json_decode( file_get_contents( dirname( __DIR__ ) . '/fixtures/provenance-vectors.json' ), true );
		return array_combine(
			array_column( $vectors, 'name' ),
			array_map(
				static function ( $vector ) {
					return array( $vector );
				},
				$vectors
			)
		);
	}

	public function test_removed_source_meta_and_stale_meta_cache_refuse(): void {
		$source = $this->post( 'Source' );
		update_post_meta( $source->ID, 'audio_file', 'https://media.example/a.mp3' );
		$post   = $this->post( '[podcast_episode episode="' . $source->ID . '"]' );
		$report = $this->scan( $post );
		get_post_meta( $source->ID, 'audio_file', true );
		global $wpdb;
		$wpdb->delete(
			$wpdb->postmeta,
			array(
				'post_id'  => $source->ID,
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Deliberately bypass the metadata cache for the regression.
				'meta_key' => 'audio_file',
			)
		);
		$this->assertWPError( Migration_Swap::apply( $this->connection, $report ) );
	}

	public function test_compare_and_swap_rejects_an_edit_at_the_actual_update(): void {
		$post   = $this->post( '[powerpress url="https://media.example/a.mp3"]' );
		$report = $this->scan( $post );
		$race   = static function ( $query ) use ( $post, &$race ) {
			global $wpdb;
			if ( false !== strpos( $query, "UPDATE {$wpdb->posts} SET post_content" ) && false !== strpos( $query, 'AND BINARY post_content' ) ) {
				remove_filter( 'query', $race );
				$wpdb->update( $wpdb->posts, array( 'post_content' => 'At write time' ), array( 'ID' => $post->ID ) );
			}
			return $query;
		};
		add_filter( 'query', $race );
		try {
			$this->assertWPError( Migration_Swap::apply( $this->connection, $report ) );
			$this->assertSame( 'At write time', Migration_Scanner::fresh( $post->ID )->post_content );
		} finally {
			remove_filter( 'query', $race );
		}
	}

	public function test_verified_show_identity_is_required_for_title_only_auto_match(): void {
		$embed = array(
			'host'         => 'buzzsprout',
			'show_id'      => '123',
			'title'        => 'Hello',
			'published_at' => '2024-01-02T12:00:00Z',
		);
		$this->assertSame( 'ambiguous', Migration_Matcher::match( $embed, array( $this->episode() ) )['status'] );
		$this->assertSame( 'matched', Migration_Matcher::match( $embed, array( $this->episode() ), array( 'buzzsprout:123' => self::PODCAST ) )['status'] );
		$this->assertSame( 'ambiguous', Migration_Matcher::match( $embed, array( $this->episode() ), array( 'buzzsprout:other' => self::PODCAST ) )['status'] );
		$episode             = $this->episode();
		$episode['rss_guid'] = 'public-guid';
		$this->assertSame( 'guid', Migration_Matcher::match( array( 'guid' => ' public-guid ' ), array( $episode ) )['method'] );
		$episode['source']['guid_sha256'] = hash( 'sha256', 'private-guid' );
		$this->assertSame( 'guid', Migration_Matcher::match( array( 'guid' => 'private-guid' ), array( $episode ) )['method'] );
	}

	public function test_safe_wrappers_shortcode_boundaries_and_invalid_local_ids(): void {
		$post   = $this->post( '<!-- wp:html {"name":"a > b"} --><div>[powerpress url="https://media.example/a.mp3"]</div><!-- /wp:html -->' );
		$report = $this->scan( $post );
		$this->assertSame( 0, $report['items'][0]['offset'] );
		$this->assertSame( strlen( $post->post_content ), $report['items'][0]['length'] );
		$this->assertNotWPError( Migration_Swap::apply( $this->connection, $report ) );
		$post = $this->post( '[powerpress-x] [embed-foo] [[wiki]] [powerpress] [[other]]' );
		$this->assertCount( 1, Migration_Detectors::detect( $post ) );
		$GLOBALS['post'] = $this->post( 'Global' );
		update_post_meta( $GLOBALS['post']->ID, 'audio_file', 'https://media.example/a.mp3' );
		try {
			$items = Migration_Detectors::detect( $this->post( '[podcast_episode id="abc"]' ) );
			$this->assertTrue( $items[0]['show_only'] );
			$this->assertArrayNotHasKey( 'audio_url', $items[0] );
		} finally {
			unset( $GLOBALS['post'] );
		}
	}

	public function test_malformed_openers_and_oversized_posts_fail_closed(): void {
		foreach ( array( '<code>', '<!-- ', '<!-- wp:ssp/x -->', '<script ', '[' ) as $opener ) {
			$post  = $this->post( str_repeat( $opener, 30000 ) );
			$start = microtime( true );
			$this->assertSame( 'error', $this->scan( $post )['status'], $opener );
			$this->assertLessThan( 2, microtime( true ) - $start );
		}
		$this->assertSame( 'error', $this->scan( $this->post( str_repeat( 'x', ShowFM\Migration_Tokens::MAX_BYTES + 1 ) ) )['status'] );
	}

	public function test_metadata_order_private_snapshots_and_single_block_parse(): void {
		$post = $this->post( 'Intro' );
		update_post_meta( $post->ID, 'enclosure', 'https://media.example/a.mp3?secret=private' );
		update_post_meta( $post->ID, 'audio_file', 'https://media.example/b.mp3?secret=other' );
		$second                               = $this->episode();
		$second['id']                         = '21111111-2222-4333-8444-555555555555';
		$second['source']['enclosure_sha256'] = hash( 'sha256', 'https://media.example/b.mp3' );
		$report                               = Migration_Scanner::scan(
			$post,
			function () use ( $second ) {
				return array( $this->episode(), $second );
			}
		);
		$result                               = Migration_Swap::apply( $this->connection, $report );
		$this->assertNotWPError( $result );
		$saved = get_post( $post->ID );
		$this->assertStringNotContainsString( 'secret', $saved->post_content );
		$this->assertStringNotContainsString( 'media.example', $saved->post_content );
		$this->assertLessThan( strpos( $saved->post_content, $second['id'] ), strpos( $saved->post_content, self::EPISODE ) );
		$calls  = 0;
		$parser = static function ( $parser_class ) use ( &$calls ) {
			++$calls;
			return $parser_class;
		};
		add_filter( 'block_parser_class', $parser );
		try {
			$this->assertFalse( Migration_Legacy::ssp( true, $saved ) );
			$this->assertFalse( Migration_Legacy::ssp( true, $saved ) );
			$this->assertCount( 2, Migration_Detectors::detect( $saved ) );
			$this->assertSame( 1, $calls );
		} finally {
			remove_filter( 'block_parser_class', $parser );
		}
		$this->assertSame( 0, $this->http->count() );
	}

	public function test_catalogue_resume_retains_pages_report_and_backoff_then_cleans_old_runs(): void {
		$engine = new Migrator( $this->connection, new Api_Client( $this->connection ) );
		$this->page( array() );
		$old = $engine->start();
		$this->assertNotWPError( $old );
		Migration_Store::put(
			$old['run'],
			array(
				'post_id' => 999,
				'items'   => array( array( 'status' => 'unmatched' ) ),
			)
		);
		$this->page( array( array( 'id' => self::PODCAST ) ) );
		$episode = $this->episode() + array( 'status' => 'published' );
		unset( $episode['audio_url'] );
		$this->page( array( $episode ), 'next+/=' );
		$this->http->respond( 429, '', array( 'Retry-After' => '90' ) );
		$this->assertWPError( $engine->start() );
		$this->assertSame( $old, Migration_Store::state() );
		$pending = get_option( ShowFM\Migration_Catalogue::PENDING );
		$this->assertSame( 1, $pending['pages'] );
		$this->assertSame( 'next+/=', $pending['episode_cursor'] );
		$this->assertGreaterThanOrEqual( time() + 89, $pending['retry_at'] );
		$this->assertWPError( $engine->start() );
		$this->assertSame( 4, $this->http->count() );
		$pending['retry_at'] = 0;
		update_option( ShowFM\Migration_Catalogue::PENDING, $pending, false );
		delete_option( Api_Client::RATE_LIMIT_OPTION );
		$this->page( array() );
		$state = $engine->start();
		$this->assertNotWPError( $state );
		$this->assertSame( $pending['run'], $state['run'] );
		$this->assertSame( 2, $state['pages'] );
		$this->assertStringEndsWith( 'cursor=next%2B%2F%3D', $this->http->requests[4]['url'] );
		$this->assertCount( 1, iterator_to_array( ShowFM\Migration_Catalogue::episodes( $state['run'], $state['pages'] ) ) );
		$this->assertSame( array(), Migration_Store::get( $old['run'], 999 ) );
		$this->assertFalse( get_option( ShowFM\Migration_Catalogue::PENDING ) );
		$this->assertSame( ShowFM\Migration_Catalogue::matcher( $state['run'], $state['pages'] ), ShowFM\Migration_Catalogue::matcher( $state['run'], $state['pages'] ) );
	}

	public function test_report_only_stores_embeds_and_errors_and_binds_apply_to_run(): void {
		$this->post( 'No players here' );
		$broken = $this->post( '<iframe ' );
		$player = $this->post( '[powerpress]' );
		$this->page( array() );
		$engine = new Migrator( $this->connection, new Api_Client( $this->connection ) );
		$state  = $engine->start();
		$this->assertNotWPError( $engine->batch( $state['run'] ) );
		$reports = iterator_to_array( Migration_Store::reports( $state['run'] ) );
		$this->assertCount( 2, $reports );
		$this->assertSame( 'error', Migration_Store::get( $state['run'], $broken->ID )['status'] );
		$this->assertWPError( $engine->swap( $player->ID, array(), 'different-run' ) );
		$this->assertWPError( $engine->batch( 'different-run' ) );
		$this->assertSame( 'safeerror', Migration_Cli::terminal( "safe\033\r\nerror" ) );
	}

	public function test_choices_for_an_unsafe_post_are_refused_before_any_write(): void {
		$post = $this->post( '<!-- wp:html --><iframe data-title="Hello" data-published-at="2024-01-02T12:00:00Z" src="https://open.spotify.com/embed/episode/one"></iframe><iframe src="https://open.spotify.com/embed/episode/two"></iframe><!-- /wp:html -->' );
		$this->page( array( array( 'id' => self::PODCAST ) ) );
		$this->page( array( $this->episode() + array( 'status' => 'published' ) ) );
		$engine = new Migrator( $this->connection, new Api_Client( $this->connection ) );
		$engine->start();
		$engine->batch();
		try {
			( new Migration_Cli( $engine ) )(
				array(),
				array(
					'yes'    => true,
					'choose' => $post->ID . ':1:' . self::EPISODE,
				)
			);
			$this->fail( 'Unsafe reports must reject choices.' );
		} catch ( ShowFM_Cli_Halt $halt ) {
			$this->assertStringContainsString( 'Each choice', $halt->getMessage() );
		}
		$this->assertSame( $post->post_content, get_post( $post->ID )->post_content );
	}

	public function test_non_strict_whatwg_authorities_and_numeric_hosts(): void {
		foreach ( array( '-host.com', 'host-.com', 'a..b', str_repeat( 'a', 64 ) . '.com', '127.0.0.1..' ) as $host ) {
			$this->assertSame( 'https://' . $host . '/', Migration_Url::normalise( 'https://' . $host ) );
		}
		$this->assertSame( 'https://127.0.0.1/', Migration_Url::normalise( 'https://0x7f.01' ) );
		$this->assertNull( Migration_Url::normalise( 'https://a%00b.com' ) );
		$this->assertNull( Migration_Url::normalise( 'https://09' ) );
	}

	public function test_report_state_bypasses_stale_cache_and_lock_refuses_reentry(): void {
		Migration_Store::save( array( 'run' => 'old' ) );
		global $wpdb;
		$wpdb->update( $wpdb->options, array( 'option_value' => serialize( array( 'run' => 'new' ) ) ), array( 'option_name' => Migration_Store::STATE ) );
		wp_cache_set( Migration_Store::STATE, array( 'run' => 'old' ), 'options' );
		$this->assertSame( 'new', Migration_Store::state()['run'] );
		$this->assertWPError(
			Migration_Store::locked(
				static function () {
					return Migration_Store::locked(
						static function () {
							return true;
						}
					);
				}
			)
		);
		$this->assertTrue(
			Migration_Store::locked(
				static function () {
					return true;
				}
			)
		);
	}

	public function test_paired_shortcodes_are_one_range_and_false_closers_error(): void {
		$post  = $this->post( '[powerpress url="https://media.example/a.mp3"][/powerpress]' );
		$items = Migration_Detectors::detect( $post );
		$this->assertSame( strlen( $post->post_content ), $items[0]['length'] );
		$this->assertSame( 'error', $this->scan( $this->post( '<iframe src="https://open.spotify.com/embed/episode/a"></iframe-other>' ) )['status'] );
	}

	public function test_invalid_utf8_matching_evidence_is_a_scan_error(): void {
		$episode          = $this->episode();
		$episode['title'] = "invalid\xff";
		$report           = Migration_Scanner::scan(
			$this->post( '[powerpress]' ),
			static function () use ( $episode ) {
				return array( $episode );
			}
		);
		$this->assertSame( 'error', $report['status'] );
	}

	public function test_defensive_ranges_refuse_tampered_reports(): void {
		$post                          = $this->post( '[powerpress url="https://media.example/a.mp3"] tail' );
		$report                        = $this->scan( $post );
		$report['items'][0]['length'] += 5;
		$this->assertWPError( Migration_Swap::apply( $this->connection, $report ) );
		$this->assertSame( $post->post_content, get_post( $post->ID )->post_content );
	}

	private function page( array $rows, ?string $cursor = null ): void {
		$this->http->respond(
			200,
			wp_json_encode(
				array(
					'data'       => $rows,
					'pagination' => array( 'next_cursor' => $cursor ),
				)
			)
		);
	}

	private function post( string $content ): WP_Post {
		$id = self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_title'    => 'Hello',
				'post_date_gmt' => '2024-01-02 12:00:00',
			)
		);
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_content' => $content ), array( 'ID' => $id ) );
		clean_post_cache( $id );
		return get_post( $id );
	}

	private function episode(): array {
		return array(
			'id'           => self::EPISODE,
			'podcast_id'   => self::PODCAST,
			'title'        => 'Hello',
			'published_at' => '2024-01-02T12:00:00Z',
			'audio_url'    => 'https://media.example/a.mp3',
			'source'       => array(
				'enclosure_sha256' => hash( 'sha256', 'https://media.example/a.mp3' ),
				'guid_sha256'      => null,
			),
			'rss_guid'     => null,
		);
	}

	private function scan( WP_Post $post ): array {
		return Migration_Scanner::scan(
			$post,
			function () {
				return array( $this->episode() );
			}
		);
	}
}
