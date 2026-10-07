<?php
/**
 * The block editor's REST proxy (showfm/v1/editor/*) and the post panel's sync field.
 *
 * @package ShowFM
 */

use ShowFM\Api_Client;
use ShowFM\Connection;
use ShowFM\Editor;
use ShowFM\Editor_Api;
use ShowFM\Plugin;

/** Capability, nonce, caching, states and secrecy of the editor routes. */
class Test_Editor_Api extends WP_UnitTestCase {

	const SITE    = '11111111-1111-4111-8111-111111111111';
	const PODCAST = '33333333-3333-4333-8333-333333333333';
	const OTHER   = '44444444-4444-4444-8444-444444444444';
	const EPISODE = '22222222-2222-4222-8222-222222222222';
	const KEY     = 'showfm_live_EDITORKEYabcdefghijklmnopq';
	const SECRET  = '9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08';

	/**
	 * HTTP mock.
	 *
	 * @var ShowFM_Http_Mock
	 */
	private $http;

	/**
	 * Editor user.
	 *
	 * @var int
	 */
	private $editor;

	public function set_up(): void {
		parent::set_up();
		Plugin::reset();
		Plugin::connection()->disconnect();
		delete_option( Api_Client::RATE_LIMIT_OPTION );
		delete_transient( Editor_Api::HOLD );
		update_option( ShowFM\Cache::VERSION_OPTION, ShowFM\Cache::version() + 1 );
		$this->http   = new ShowFM_Http_Mock();
		$this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->editor );
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		unset( $GLOBALS['wp_rest_auth_cookie'], $_SERVER['HTTP_X_WP_NONCE'], $_REQUEST['_wpnonce'] );
		$this->http->detach();
		Plugin::connection()->disconnect();
		Plugin::reset();
		parent::tear_down();
	}

	/**
	 * Dispatches a GET to an editor route.
	 *
	 * @param string               $route Route.
	 * @param array<string,string> $query Query.
	 */
	private function get( string $route, array $query = array() ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/showfm/v1/editor/' . $route );
		$request->set_query_params( $query );
		return rest_get_server()->dispatch( $request );
	}

	private function connect(): void {
		$this->assertTrue( Plugin::connection()->save( self::KEY, self::SECRET, self::SITE, 0 ) );
	}

	private function json( array $body ): string {
		return (string) wp_json_encode( $body );
	}

	private function public_show(): array {
		return array(
			'id'            => self::PODCAST,
			'slug'          => 'the-long-table',
			'title'         => 'The Long <b>Table</b>',
			'artwork'       => array( 'url' => 'https://m.cdn.media/art.jpg' ),
			'episode_count' => 8,
			'links'         => array( 'listen' => 'https://the-long-table.show.fm' ),
		);
	}

	private function public_episode(): array {
		return array(
			'id'             => self::EPISODE,
			'slug'           => 'sourdough',
			'title'          => 'Sourdough, salt and the slow return',
			'season_number'  => 2,
			'episode_number' => 4,
			'episode_type'   => 'full',
			'published_at'   => '2026-09-24T09:00:00Z',
			'audio'          => array(
				'url'              => 'http://insecure.example/a.mp3',
				'duration_seconds' => 3120,
			),
			'artwork'        => array( 'url' => 'https://m.cdn.media/ep.jpg' ),
			'links'          => array( 'listen' => 'https://the-long-table.show.fm/e/sourdough' ),
			'transcript'     => array( 'url' => 'https://m.cdn.media/t.vtt' ),
			'podcast'        => array(
				'id'    => self::PODCAST,
				'slug'  => 'the-long-table',
				'title' => 'The Long Table',
				'links' => array( 'listen' => 'https://the-long-table.show.fm' ),
			),
		);
	}

	private function keyed_shows(): string {
		return $this->json(
			array(
				'data' => array(
					array(
						'id'           => self::PODCAST,
						'slug'         => 'the-long-table',
						'title'        => 'The Long Table',
						'hosting_type' => 'showfm',
					),
					array(
						'id'           => self::OTHER,
						'slug'         => 'elsewhere',
						'title'        => 'Elsewhere',
						'hosting_type' => 'external',
					),
				),
			)
		);
	}

	/**
	 * Asserts no response or request leaks a secret to the browser.
	 *
	 * @param mixed $data Response data.
	 */
	private function assert_no_secrets( $data ): void {
		$text = (string) wp_json_encode( $data );
		foreach ( array( self::KEY, self::SECRET, self::SITE, 'Bearer', 'Authorization' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $text );
		}
	}

	public function test_routes_need_a_user_who_can_edit_posts(): void {
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->get( 'shows' )->get_status() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $this->get( 'show', array( 'ref' => 'the-long-table' ) )->get_status() );
		$this->assertSame( 0, $this->http->count(), 'Refused requests never reach show.fm.' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );
		$this->assertSame( 200, $this->get( 'shows' )->get_status() );
	}

	public function test_cookie_requests_need_the_rest_nonce(): void {
		$GLOBALS['wp_rest_auth_cookie'] = true;
		$this->assertTrue( rest_cookie_check_errors( null ) );
		$this->assertSame( 0, get_current_user_id(), 'Without a nonce, cookie auth is treated as logged out.' );
		$this->assertSame( 401, $this->get( 'shows' )->get_status() );

		wp_set_current_user( $this->editor );
		$_SERVER['HTTP_X_WP_NONCE'] = 'not-a-nonce';
		$error                      = rest_cookie_check_errors( null );
		$this->assertWPError( $error );
		$this->assertSame( 'rest_cookie_invalid_nonce', $error->get_error_code() );

		// A valid nonce keeps the user (core then sends a fresh nonce header, so it is not
		// called here: headers are already sent under PHPUnit).
		$this->assertSame( 1, wp_verify_nonce( wp_create_nonce( 'wp_rest' ), 'wp_rest' ) );
		$this->assertSame( 200, $this->get( 'shows' )->get_status() );
	}

	public function test_invalid_parameters_are_refused_before_any_request(): void {
		$this->assertSame( 400, $this->get( 'show', array( 'ref' => 'https://evil.example/x' ) )->get_status() );
		$this->assertSame( 400, $this->get( 'episode', array( 'id' => '../../v1/me' ) )->get_status() );
		$this->assertSame( 400, $this->get( 'episodes', array( 'podcast' => 'A B' ) )->get_status() );
		$this->assertSame( 0, $this->http->count() );
	}

	public function test_unconnected_show_reads_the_public_api_without_a_key_and_is_cached(): void {
		$this->http->respond( 200, $this->json( array( 'data' => $this->public_show() ) ) );
		$response = $this->get( 'show', array( 'ref' => 'the-long-table' ) );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'ok', $data['state'] );
		$this->assertSame( self::PODCAST, $data['show']['id'] );
		$this->assertSame( 'The Long Table', $data['show']['title'], 'Markup is stripped from API text.' );
		$this->assertSame( 8, $data['show']['episodes'] );
		$this->assertSame( 'https://api.show.fm/v1/podcasts/the-long-table', $this->http->last()['url'] );
		$this->assertArrayNotHasKey( 'Authorization', $this->http->last()['args']['headers'] );

		$this->assertSame( 'ok', $this->get( 'show', array( 'ref' => 'the-long-table' ) )->get_data()['state'] );
		$this->assertSame( 1, $this->http->count(), 'The second answer comes from the cache.' );
		$this->assertSame(
			array(
				'state'     => 'ok',
				'connected' => false,
				'shows'     => array(),
			),
			$this->get( 'shows' )->get_data()
		);
		$this->assertSame( 1, $this->http->count(), 'An unconnected site never asks for its shows.' );
	}

	public function test_not_found_and_paused_states_and_misses_are_cached_briefly(): void {
		$this->http->respond( 404, $this->json( array( 'error' => array( 'code' => 'not_found' ) ) ) );
		$this->assertSame( array( 'state' => 'not_found' ), $this->get( 'show', array( 'ref' => 'missing' ) )->get_data() );
		$this->assertSame( array( 'state' => 'not_found' ), $this->get( 'show', array( 'ref' => 'missing' ) )->get_data() );
		$this->assertSame( 1, $this->http->count() );

		$this->http->respond( 403, $this->json( array( 'error' => array( 'code' => 'unavailable' ) ) ) );
		$this->assertSame( array( 'state' => 'paused' ), $this->get( 'show', array( 'ref' => 'suspended' ) )->get_data() );
	}

	public function test_errors_are_not_cached(): void {
		$this->http->respond( 503 );
		$this->assertSame( array( 'state' => 'error' ), $this->get( 'show', array( 'ref' => 'the-long-table' ) )->get_data() );
		$this->http->fail( 'timeout' );
		$this->assertSame( array( 'state' => 'error' ), $this->get( 'show', array( 'ref' => 'the-long-table' ) )->get_data() );
		$this->assertSame( 2, $this->http->count(), 'Each try reaches show.fm again.' );
	}

	public function test_public_429_holds_every_editor_call_until_retry_after(): void {
		$this->http->respond( 429, '', array( 'Retry-After' => '90' ) );
		$first = $this->get( 'show', array( 'ref' => 'the-long-table' ) )->get_data();
		$this->assertSame( 'rate_limited', $first['state'] );
		$this->assertSame( 90, $first['retryAfter'] );
		$second = $this->get( 'episodes', array( 'podcast' => 'another-show' ) )->get_data();
		$this->assertSame( 'rate_limited', $second['state'] );
		$this->assertSame( 1, $this->http->count(), 'Held calls are never sent.' );
	}

	public function test_connected_shows_use_the_key_on_the_server_only(): void {
		$this->connect();
		$this->http->respond( 200, $this->keyed_shows() );
		$this->http->respond( 200, $this->json( array( 'data' => $this->public_show() ) ) );
		$response = $this->get( 'shows' );
		$data     = $response->get_data();
		$this->assertSame( 'ok', $data['state'] );
		$this->assertTrue( $data['connected'] );
		$this->assertCount( 2, $data['shows'] );
		$this->assertSame( 'https://m.cdn.media/art.jpg', $data['shows'][0]['artwork'] );
		$this->assertSame( 'external', $data['shows'][1]['hosting'] );
		$this->assertSame( 2, $this->http->count(), 'An external show is not looked up publicly.' );

		$keyed  = $this->http->requests[0];
		$public = $this->http->requests[1];
		$this->assertSame( 'https://api.show.fm/v1/me/podcasts?limit=50', $keyed['url'] );
		$this->assertSame( 'Bearer ' . self::KEY, $keyed['args']['headers']['Authorization'] );
		$this->assertArrayNotHasKey( 'Authorization', $public['args']['headers'] );
		$this->assert_no_secrets( $data );
		$this->assert_no_secrets( Editor_Api::settings() );
		$this->assertTrue( Editor_Api::settings()['connected'] );
	}

	public function test_external_show_is_refused_by_address(): void {
		$this->connect();
		$this->http->respond( 200, $this->keyed_shows() );
		$data = $this->get( 'show', array( 'ref' => 'elsewhere' ) )->get_data();
		$this->assertSame( 'external', $data['state'] );
		$this->assertSame( 'external', $this->get( 'episodes', array( 'podcast' => self::OTHER ) )->get_data()['state'] );
		$this->assertSame( 1, $this->http->count() );
	}

	public function test_connected_episode_list_includes_scheduled_and_drops_drafts(): void {
		$this->connect();
		$this->http->respond( 200, $this->keyed_shows() );
		$this->http->respond(
			200,
			$this->json(
				array(
					'data' => array(
						array(
							'id'            => self::EPISODE,
							'title'         => 'Bread and butter pudding',
							'status'        => 'scheduled',
							'scheduled_for' => '2030-10-14T09:00:00Z',
							'season_number' => 2,
						),
						array(
							'id'     => self::OTHER,
							'title'  => 'A draft',
							'status' => 'draft',
						),
					),
				)
			)
		);
		$data = $this->get( 'episodes', array( 'podcast' => 'the-long-table' ) )->get_data();
		$this->assertSame( 'ok', $data['state'] );
		$this->assertTrue( $data['keyed'] );
		$this->assertCount( 1, $data['episodes'] );
		$this->assertTrue( $data['episodes'][0]['scheduled'] );
		$this->assertSame( '2030-10-14T09:00:00+00:00', $data['episodes'][0]['date'] );
		$this->assertStringEndsWith( '/v1/me/podcasts/' . self::PODCAST . '/episodes?limit=100', $this->http->last()['url'] );
	}

	public function test_public_episode_payload_keeps_only_https_links(): void {
		$this->http->respond( 200, $this->json( array( 'data' => $this->public_episode() ) ) );
		$data = $this->get( 'episode', array( 'id' => self::EPISODE ) )->get_data();
		$this->assertSame( 'ok', $data['state'] );
		$episode = $data['episode'];
		$this->assertSame( 'https://the-long-table.show.fm/e/sourdough', $episode['listen'] );
		$this->assertNull( $episode['audio'], 'Non-https audio never reaches the snapshot.' );
		$this->assertTrue( $episode['transcript'] );
		$this->assertSame( 3120, $episode['duration'] );
		$this->assertSame( self::PODCAST, $episode['podcast']['id'] );
		$this->assertArrayNotHasKey( 'appUrl', $episode, 'Only connected sites link into show.fm.' );
	}

	public function test_unconnected_404_is_not_public_yet(): void {
		$this->http->respond( 404 );
		$data = $this->get(
			'episode',
			array(
				'id'      => self::EPISODE,
				'podcast' => self::PODCAST,
			)
		)->get_data();
		$this->assertSame( array( 'state' => 'not_public' ), $data );
		$this->assertSame( 1, $this->http->count() );
	}

	/**
	 * Keyed states for an episode the public API does not have.
	 *
	 * @return array<string,array{0:int,1:array<string,mixed>,2:string}>
	 */
	public function keyed_states(): array {
		return array(
			'scheduled'             => array(
				200,
				array(
					'status'        => 'scheduled',
					'scheduled_for' => '2030-10-14T09:00:00Z',
				),
				'scheduled',
			),
			'published in future'   => array(
				200,
				array(
					'status'       => 'published',
					'published_at' => '2030-10-14T09:00:00Z',
				),
				'scheduled',
			),
			'draft'                 => array( 200, array( 'status' => 'draft' ), 'unpublished' ),
			'archived'              => array( 200, array( 'status' => 'archived' ), 'archived' ),
			'published, not cached' => array(
				200,
				array(
					'status'       => 'published',
					'published_at' => '2020-01-01T00:00:00Z',
				),
				'not_public',
			),
			'deleted'               => array( 404, array(), 'deleted' ),
		);
	}

	/**
	 * @dataProvider keyed_states
	 *
	 * @param int                 $status   Keyed status code.
	 * @param array<string,mixed> $fields   Keyed episode fields.
	 * @param string              $expected Editor state.
	 */
	public function test_connected_episode_states( int $status, array $fields, string $expected ): void {
		$this->connect();
		$this->http->respond( 404 );
		$this->http->respond( 200, $this->keyed_shows() );
		$this->http->respond(
			$status,
			200 === $status ? $this->json(
				array(
					'data' => array_merge(
						array(
							'id'    => self::EPISODE,
							'slug'  => 'pudding',
							'title' => 'Bread and butter pudding',
						),
						$fields
					),
				)
			) : ''
		);
		$data = $this->get(
			'episode',
			array(
				'id'      => self::EPISODE,
				'podcast' => self::PODCAST,
			)
		)->get_data();
		$this->assertSame( $expected, $data['state'] );
		if ( 200 === $status ) {
			$this->assertSame( 'https://my.show.fm/p/the-long-table/e/pudding', $data['episode']['appUrl'] );
			$this->assertSame( 'The Long Table', $data['episode']['podcast']['title'] );
			$this->assertArrayNotHasKey( 'audio', $data['episode'], 'A non-public episode never carries audio.' );
		}
		$this->assertStringEndsWith( '/v1/me/episodes/' . self::EPISODE, $this->http->last()['url'] );
		$this->assert_no_secrets( $data );
	}

	public function test_another_accounts_episode_is_not_found_without_a_keyed_call(): void {
		$this->connect();
		$this->http->respond( 404 );
		$this->http->respond( 200, $this->keyed_shows() );
		$data = $this->get(
			'episode',
			array(
				'id'      => self::EPISODE,
				'podcast' => 'someone-elses-show',
			)
		)->get_data();
		$this->assertSame( array( 'state' => 'not_found' ), $data );
		$this->assertSame( 2, $this->http->count() );
	}

	public function test_401_marks_reconnect_and_stops_keyed_calls(): void {
		$this->connect();
		$this->http->respond( 401, $this->json( array( 'error' => array( 'code' => 'unauthorized' ) ) ) );
		$data = $this->get( 'shows' )->get_data();
		$this->assertSame(
			array(
				'state'     => 'ok',
				'connected' => false,
				'shows'     => array(),
			),
			$data
		);
		$this->assertSame( Connection::STATE_RECONNECT_NEEDED, Plugin::connection()->state() );
		$this->assertTrue( Editor_Api::settings()['reconnect'] );

		$this->http->respond( 200, $this->json( array( 'data' => array() ) ) );
		$this->assertSame( 'ok', $this->get( 'episodes', array( 'podcast' => 'the-long-table' ) )->get_data()['state'] );
		$this->assertSame( 2, $this->http->count() );
		$this->assertArrayNotHasKey( 'Authorization', $this->http->last()['args']['headers'], 'No keyed retry after a 401.' );
	}

	public function test_keyed_429_holds_keyed_calls(): void {
		$this->connect();
		$this->http->respond( 429, '', array( 'Retry-After' => '120' ) );
		$this->assertSame( 'rate_limited', $this->get( 'shows' )->get_data()['state'] );
		$this->assertSame( 'rate_limited', $this->get( 'shows' )->get_data()['state'] );
		$this->assertSame( 1, $this->http->count() );
		$this->assertGreaterThan( 0, Api_Client::rate_limit_remaining() );
	}

	public function test_sync_field_reads_post_meta_for_people_who_can_edit_the_post(): void {
		Editor::register_fields();
		$post = self::factory()->post->create( array( 'post_author' => $this->editor ) );
		update_post_meta( $post, '_showfm_site_id', self::SITE );
		update_post_meta( $post, '_showfm_sync_state', 'synced' );
		update_post_meta( $post, '_showfm_edited', 1 );
		update_post_meta( $post, '_showfm_synced_at', 1790000000 );

		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post );
		$request->set_query_params( array( 'context' => 'edit' ) );
		$data = rest_get_server()->dispatch( $request )->get_data();
		$this->assertSame(
			array(
				'synced'   => true,
				'state'    => 'synced',
				'edited'   => true,
				'syncedAt' => gmdate( 'c', 1790000000 ),
			),
			$data[ Editor::FIELD ]
		);
		$this->assert_no_secrets( $data[ Editor::FIELD ] );

		$view = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post );
		$this->assertArrayNotHasKey( Editor::FIELD, rest_get_server()->dispatch( $view )->get_data(), 'Only the edit context carries it.' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );
		$this->assertNull( Editor::sync_status( array( 'id' => $post ) ) );
	}

	public function test_editor_settings_are_printed_before_the_editor_script(): void {
		if ( ! wp_script_is( 'showfm-block-editor', 'registered' ) ) {
			wp_register_script( 'showfm-block-editor', 'https://example.test/index.js', array(), '1', true );
		}
		Editor::enqueue();
		$before = implode( '', (array) wp_scripts()->get_data( 'showfm-block-editor', 'before' ) );
		$this->assertStringContainsString( 'window.showfmEditor = {"connected":false', $before );
		$this->assertStringContainsString( '"api":"https:\/\/api.show.fm"', $before );
		$this->assertSame( 0, $this->http->count() );
	}
}
