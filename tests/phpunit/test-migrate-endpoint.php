<?php
/**
 * Migrate tab: the REST routes, the run lease, choices, resume and the swap.
 *
 * @package ShowFM
 */

use ShowFM\Api_Client;
use ShowFM\Connection;
use ShowFM\Migration_Admin;
use ShowFM\Migration_Catalogue;
use ShowFM\Migration_Cli;
use ShowFM\Migration_Store;
use ShowFM\Migration_Url;
use ShowFM\Migrator;
use ShowFM\Plugin;

/**
 * Covers `showfm/v1/admin/migrate` against stored posts and a mocked episode catalogue.
 */
class Test_Migrate_Endpoint extends WP_UnitTestCase {

	const ROUTE   = '/showfm/v1/admin/migrate';
	const PODCAST = '31111111-2222-4333-8444-555555555555';
	const HELLO   = '11111111-2222-4333-8444-555555555555';
	const BAKERY  = '21111111-2222-4333-8444-555555555555';
	const BAKERY2 = '41111111-2222-4333-8444-555555555555';
	const KEY     = 'showfm_live_MIGRATETAB1234567890123456789';

	/**
	 * Administrator.
	 *
	 * @var int
	 */
	private $admin;

	/**
	 * HTTP mock, so nothing leaves the test.
	 *
	 * @var ShowFM_Http_Mock
	 */
	private $http;

	public function set_up(): void {
		parent::set_up();
		Plugin::reset();
		$this->http  = new ShowFM_Http_Mock();
		$this->admin = self::factory()->user->create(
			array(
				'role'         => 'administrator',
				'display_name' => 'Maya Lindgren',
			)
		);
		wp_set_current_user( $this->admin );
		Plugin::connection()->save( self::KEY, str_repeat( 'a', 64 ), self::PODCAST, 0 );
		Migration_Store::clear();
		delete_option( Migration_Catalogue::PENDING );
		delete_option( Migration_Admin::LEASE );
		delete_option( Migration_Admin::PROGRESS );
		delete_option( Api_Client::RATE_LIMIT_OPTION );
		do_action( 'rest_api_init' );
	}

	public function tear_down(): void {
		$this->http->detach();
		Plugin::connection()->disconnect();
		Plugin::reset();
		unset( $_REQUEST['_wpnonce'], $GLOBALS['wp_rest_auth_cookie'] );
		parent::tear_down();
	}

	public function test_the_routes_are_registered(): void {
		$routes = rest_get_server()->get_routes();
		foreach ( array( '', '/scan', '/stop', '/rows', '/choice', '/swap' ) as $path ) {
			$this->assertArrayHasKey( self::ROUTE . $path, $routes, $path );
		}
	}

	/**
	 * @dataProvider routes
	 *
	 * @param string              $method Method.
	 * @param string              $path   Path.
	 * @param array<string,mixed> $params Parameters.
	 */
	public function test_logged_out_requests_are_refused( string $method, string $path, array $params ): void {
		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->dispatch( $method, $path, $params )->get_status() );
		$this->assertSame( array(), Migration_Store::state() );
		$this->assertSame( 0, $this->http->count() );
	}

	/**
	 * @dataProvider routes
	 *
	 * @param string              $method Method.
	 * @param string              $path   Path.
	 * @param array<string,mixed> $params Parameters.
	 */
	public function test_users_who_cannot_manage_options_are_refused( string $method, string $path, array $params ): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( 403, $this->dispatch( $method, $path, $params )->get_status() );
		$this->assertSame( array(), Migration_Store::state() );
		$this->assertSame( 0, $this->http->count() );
	}

	/**
	 * Every route, with valid parameters.
	 *
	 * @return array<string,array{0:string,1:string,2:array<string,mixed>}>
	 */
	public function routes(): array {
		$run = '51111111-2222-4333-8444-555555555555';
		return array(
			'view'   => array( 'GET', '', array() ),
			'scan'   => array( 'POST', '/scan', array() ),
			'stop'   => array( 'POST', '/stop', array() ),
			'rows'   => array(
				'GET',
				'/rows',
				array(
					'run'   => $run,
					'group' => 'ready',
				),
			),
			'choice' => array(
				'POST',
				'/choice',
				array(
					'run'     => $run,
					'post'    => 1,
					'embed'   => 1,
					'episode' => self::HELLO,
				),
			),
			'swap'   => array( 'POST', '/swap', array( 'run' => $run ) ),
		);
	}

	public function test_a_cookie_request_without_the_rest_nonce_runs_logged_out_and_changes_nothing(): void {
		$GLOBALS['wp_rest_auth_cookie'] = true;

		$this->assertTrue( rest_cookie_check_errors( null ) );

		$this->assertSame( 0, get_current_user_id() );
		$this->assertSame( 401, $this->dispatch( 'POST', '/scan' )->get_status() );
		$this->assertSame( array(), Migration_Store::state() );
		$this->assertFalse( get_option( Migration_Catalogue::PENDING ) );
	}

	public function test_a_cookie_request_with_the_rest_nonce_is_accepted(): void {
		$GLOBALS['wp_rest_auth_cookie'] = true;
		$_REQUEST['_wpnonce']           = wp_create_nonce( 'wp_rest' );

		$this->assertTrue( rest_cookie_check_errors( null ) );

		$this->assertSame( $this->admin, get_current_user_id() );
		$response = $this->dispatch( 'GET' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'intro', $response->get_data()['phase'] );
	}

	/**
	 * @dataProvider invalid
	 *
	 * @param string              $method Method.
	 * @param string              $path   Path.
	 * @param array<string,mixed> $params Parameters.
	 */
	public function test_invalid_input_is_a_400( string $method, string $path, array $params ): void {
		$response = $this->dispatch( $method, $path, $params );

		$this->assertSame( 400, $response->get_status() );
		$this->assertContains( $response->get_data()['code'], array( 'rest_invalid_param', 'rest_missing_callback_param' ) );
		$this->assertSame( array(), Migration_Store::state() );
		$this->assertSame( 0, $this->http->count() );
	}

	/**
	 * Refused input for each route.
	 *
	 * @return array<string,array{0:string,1:string,2:array<string,mixed>}>
	 */
	public function invalid(): array {
		$run = '51111111-2222-4333-8444-555555555555';
		return array(
			'scan run'        => array( 'POST', '/scan', array( 'run' => 'not-a-run' ) ),
			'scan restart'    => array( 'POST', '/scan', array( 'restart' => 'often' ) ),
			'rows group'      => array(
				'GET',
				'/rows',
				array(
					'run'   => $run,
					'group' => 'everything',
				),
			),
			'rows offset'     => array(
				'GET',
				'/rows',
				array(
					'run'    => $run,
					'group'  => 'ready',
					'offset' => -1,
				),
			),
			'rows limit'      => array(
				'GET',
				'/rows',
				array(
					'run'   => $run,
					'group' => 'ready',
					'limit' => 101,
				),
			),
			'choice post'     => array(
				'POST',
				'/choice',
				array(
					'run'     => $run,
					'post'    => 0,
					'embed'   => 1,
					'episode' => self::HELLO,
				),
			),
			'choice episode'  => array(
				'POST',
				'/choice',
				array(
					'run'     => $run,
					'post'    => 1,
					'embed'   => 1,
					'episode' => '<script>',
				),
			),
			'choice no embed' => array(
				'POST',
				'/choice',
				array(
					'run'     => $run,
					'post'    => 1,
					'episode' => self::HELLO,
				),
			),
			'swap no run'     => array( 'POST', '/swap', array() ),
			'swap empty run'  => array( 'POST', '/swap', array( 'run' => '' ) ),
		);
	}

	public function test_the_intro_lists_the_hosts_and_counts_published_posts(): void {
		$this->post( 'Plain words.', 'One' );
		self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$view = $this->dispatch( 'GET' )->get_data();

		$this->assertTrue( $view['connected'] );
		$this->assertSame( 'intro', $view['phase'] );
		$this->assertSame( array( 'Buzzsprout', 'Libsyn', 'Captivate', 'Transistor', 'Spotify', 'Podbean', 'PowerPress', 'Seriously Simple Podcasting' ), $view['hosts'] );
		$this->assertSame( 1, $view['posts'] );
		$this->assertNull( $view['lease'] );
		$this->assertStringContainsString( 'tab=connection', $view['connectUrl'] );
	}

	public function test_not_connected_shows_the_state_and_refuses_steps_without_http(): void {
		Plugin::connection()->disconnect();

		$view = $this->dispatch( 'GET' )->get_data();
		$this->assertFalse( $view['connected'] );

		$response = $this->dispatch( 'POST', '/scan' );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'showfm_migration_not_connected', $response->get_data()['code'] );
		$this->assertSame( 'not_connected', $response->get_data()['data']['reason'] );
		$this->assertFalse( $response->get_data()['data']['view']['connected'] );
		$this->assertSame( 0, $this->http->count() );
	}

	public function test_a_scan_runs_in_steps_and_reports_each_group(): void {
		$hello   = $this->post( '[powerpress url="https://media.example/Hello.mp3"]', 'Hello' );
		$bakery  = $this->post( '<iframe src="https://www.buzzsprout.com/1234/episodes/1234567-bakery"></iframe>', 'The village bakery', '2024-03-01 09:00:00' );
		$spotify = $this->post( '<iframe src="https://open.spotify.com/embed/episode/abc123"></iframe>', 'Guest spot' );
		$already = $this->post( '<!-- wp:showfm/player {"episode":"' . self::HELLO . '"} /-->', 'Already' );
		$this->catalogue();

		$first = $this->dispatch( 'POST', '/scan' );
		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( 'scanning', $first->get_data()['phase'] );
		$this->assertTrue( $first->get_data()['lease']['mine'] );
		$view = $this->scan_until( 'report', $first->get_data()['run'] );

		$this->assertSame( 2, $this->http->count(), 'One podcast list and one episode list.' );
		$this->assertSame(
			array(
				'ready'     => 1,
				'choose'    => 1,
				'unmatched' => 1,
				'already'   => 1,
				'review'    => 0,
			),
			$view['report']['counts']
		);
		$this->assertSame( 4, $view['report']['embeds'] );
		$this->assertSame( 4, $view['report']['posts'] );
		$this->assertSame(
			array(
				'embeds' => 1,
				'posts'  => 1,
			),
			$view['report']['swap']
		);
		$this->assertNull( $view['lease'], 'A finished scan gives the run back.' );

		$ready = $this->rows( $view['run'], 'ready' );
		$this->assertSame( 1, $ready['total'] );
		$this->assertSame( $hello, $ready['rows'][0]['post']['id'] );
		$this->assertSame( 'Hello', $ready['rows'][0]['post']['title'] );
		$this->assertSame( get_permalink( $hello ), $ready['rows'][0]['post']['url'] );
		$this->assertSame( 'PowerPress', $ready['rows'][0]['host'] );
		$this->assertSame( '[powerpress url=…/Hello.mp3]', $ready['rows'][0]['ref'] );
		$this->assertSame( 'Audio file', $ready['rows'][0]['method'] );
		$this->assertSame( 'Hello', $ready['rows'][0]['episode'] );

		$choose = $this->rows( $view['run'], 'choose' )['rows'][0];
		$this->assertSame( $bakery, $choose['post']['id'] );
		$this->assertSame( 'Title and date', $choose['method'] );
		$this->assertSame( 'buzzsprout.com/…/1234567', $choose['ref'] );
		$this->assertSame( '', $choose['choice'] );
		$this->assertTrue( $choose['canChoose'] );
		$this->assertEqualsCanonicalizing( array( self::BAKERY, self::BAKERY2 ), array_column( $choose['candidates'], 'id' ) );
		$this->assertSame( array( 'id', 'title', 'date' ), array_keys( $choose['candidates'][0] ) );

		$this->assertSame( $spotify, $this->rows( $view['run'], 'unmatched' )['rows'][0]['post']['id'] );
		$this->assertSame( $already, $this->rows( $view['run'], 'already' )['rows'][0]['post']['id'] );
	}

	public function test_nothing_private_reaches_the_browser(): void {
		$this->post( '[powerpress url="https://media.example/Hello.mp3?token=SECRET123"]', 'Hello' );
		$this->catalogue();
		$view = $this->scan_until( 'report' );

		$body = wp_json_encode( array( $view, $this->rows( $view['run'], 'ready' ) ) );
		$this->assertStringNotContainsString( self::KEY, $body );
		$this->assertStringNotContainsString( 'SECRET123', $body );
		$this->assertStringNotContainsString( 'media.example', $body );
		$this->assertStringNotContainsString( 'enclosure_sha256', $body );
		$this->assertStringNotContainsString( '"offset"', $body );
		$this->assertStringNotContainsString( 'range_hash', $body );
	}

	public function test_nothing_to_migrate_is_its_own_step(): void {
		$this->post( 'No players here.', 'Plain' );
		$this->post( '<!-- wp:showfm/player {"episode":"' . self::HELLO . '"} /-->', 'Already' );
		$this->catalogue();

		$view = $this->scan_until( 'empty' );

		$this->assertSame( 2, $view['posts'] );
		$this->assertSame( 1, $view['report']['counts']['already'], 'A post that already uses show.fm has nothing to migrate.' );
	}

	public function test_a_scan_resumes_in_batches_and_after_a_reload(): void {
		for ( $i = 0; $i < 51; ++$i ) {
			$this->post( 'Words ' . $i, 'Post ' . $i );
		}
		$last = $this->post( '[powerpress url="https://media.example/Hello.mp3"]', 'Hello' );
		$this->catalogue();

		$run  = $this->dispatch( 'POST', '/scan' )->get_data()['run'];
		$view = $this->dispatch( 'POST', '/scan', array( 'run' => $run ) )->get_data();
		$this->assertSame( 'scanning', $view['phase'] );
		$this->assertSame( 50, $view['scan']['checked'] );
		$this->assertSame( 52, $view['scan']['total'] );
		$this->assertSame( 0, $view['scan']['found'] );

		// A reload reads the same progress, then carries on from it.
		Plugin::reset();
		$reloaded = $this->dispatch( 'GET' )->get_data();
		$this->assertSame( 'scanning', $reloaded['phase'] );
		$this->assertSame( 50, $reloaded['scan']['checked'] );
		$this->assertFalse( $reloaded['scan']['stopped'] );

		$done = $this->dispatch( 'POST', '/scan', array( 'run' => $run ) )->get_data();
		$this->assertSame( 'report', $done['phase'] );
		$this->assertSame( 1, $done['report']['counts']['ready'] );
		$this->assertSame( $last, $this->rows( $run, 'ready' )['rows'][0]['post']['id'] );
	}

	public function test_the_catalogue_is_read_a_few_pages_per_step(): void {
		$this->post( '[powerpress url="https://media.example/Hello.mp3"]', 'Hello' );
		$this->http->respond(
			200,
			wp_json_encode(
				array(
					'data'       => array( array( 'id' => self::PODCAST ) ),
					'pagination' => array( 'next_cursor' => null ),
				)
			)
		);
		for ( $page = 1; $page < Migration_Admin::CATALOGUE_REQUESTS + 2; ++$page ) {
			$this->http->respond(
				200,
				wp_json_encode(
					array(
						'data'       => array(),
						'pagination' => array( 'next_cursor' => 'page' . $page ),
					)
				)
			);
		}
		$this->http->respond(
			200,
			wp_json_encode(
				array(
					'data'       => array( $this->episode( self::HELLO, 'Hello', '2024-01-02T12:00:00Z', 'https://media.example/Hello.mp3' ) ),
					'pagination' => array( 'next_cursor' => null ),
				)
			)
		);

		$first = $this->dispatch( 'POST', '/scan' )->get_data();
		$this->assertSame( Migration_Admin::CATALOGUE_REQUESTS, $this->http->count() );
		$this->assertSame( 'scanning', $first['phase'] );
		$this->assertTrue( $first['scan']['catalogue'] );

		$view = $this->scan_until( 'report', $first['run'] );
		$this->assertSame( Migration_Admin::CATALOGUE_REQUESTS + 3, $this->http->count() );
		$this->assertSame( 1, $view['report']['counts']['ready'] );
	}

	public function test_show_fm_unreachable_keeps_progress_and_says_so(): void {
		$this->post( '[powerpress url="https://media.example/Hello.mp3"]', 'Hello' );
		$this->http->respond( 503 );

		$response = $this->dispatch( 'POST', '/scan' );

		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( 'unreachable', $response->get_data()['data']['reason'] );
		$view = $response->get_data()['data']['view'];
		$this->assertSame( 'scanning', $view['phase'] );
		$this->assertSame( 'unreachable', $view['problem']['reason'] );
		$this->assertSame( 'unreachable', $this->dispatch( 'GET' )->get_data()['problem']['reason'], 'The problem survives a reload.' );
	}

	public function test_a_rate_limit_says_when_to_try_again(): void {
		$this->post( '[powerpress url="https://media.example/Hello.mp3"]', 'Hello' );
		$this->http->respond( 429, '', array( 'Retry-After' => '90' ) );

		$response = $this->dispatch( 'POST', '/scan' );

		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( 'showfm_migration_rate_limited', $response->get_data()['code'] );
		$this->assertGreaterThan( time() + 60, $response->get_data()['data']['retryAt'] );
		$this->assertStringContainsString( 'Try again after', $response->get_data()['message'] );

		$run   = $response->get_data()['data']['view']['run'];
		$again = $this->dispatch( 'POST', '/scan', array( 'run' => $run ) );
		$this->assertSame( 'rate_limited', $again->get_data()['data']['reason'] );
		$this->assertSame( 1, $this->http->count(), 'Waiting for the retry time sends nothing.' );

		// Once the retry time has passed, the notice goes and the scan carries on.
		$pending             = get_option( Migration_Catalogue::PENDING );
		$pending['retry_at'] = time() - 1;
		update_option( Migration_Catalogue::PENDING, $pending, false );
		delete_option( Api_Client::RATE_LIMIT_OPTION );
		$view = $this->dispatch( 'GET' )->get_data();
		$this->assertNull( $view['problem'] );
		$this->assertSame( 'scanning', $view['phase'] );
		$this->catalogue();
		$this->assertSame( 'report', $this->scan_until( 'report', $run )['phase'] );
	}

	public function test_a_connection_lost_mid_run_is_explained(): void {
		for ( $i = 0; $i < 51; ++$i ) {
			$this->post( 'Words ' . $i, 'Post ' . $i );
		}
		$this->catalogue();
		$run = $this->dispatch( 'POST', '/scan' )->get_data()['run'];

		Plugin::connection()->disconnect();
		$response = $this->dispatch( 'POST', '/scan', array( 'run' => $run ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'connection_lost', $response->get_data()['data']['reason'] );
		$this->assertFalse( $response->get_data()['data']['view']['connected'] );
	}

	public function test_a_second_admin_cannot_run_while_the_first_one_is(): void {
		for ( $i = 0; $i < 51; ++$i ) {
			$this->post( 'Words ' . $i, 'Post ' . $i );
		}
		$this->catalogue();
		$run = $this->dispatch( 'POST', '/scan' )->get_data()['run'];

		$other = self::factory()->user->create(
			array(
				'role'         => 'administrator',
				'display_name' => 'Tom Reyes',
			)
		);
		wp_set_current_user( $other );
		$view = $this->dispatch( 'GET' )->get_data();
		$this->assertFalse( $view['lease']['mine'] );
		$this->assertSame( 'Maya Lindgren', $view['lease']['name'] );

		foreach ( array( array( 'run' => $run ), array( 'restart' => true ) ) as $params ) {
			$response = $this->dispatch( 'POST', '/scan', $params );
			$this->assertSame( 409, $response->get_status() );
			$this->assertSame( 'busy', $response->get_data()['data']['reason'] );
			$this->assertStringContainsString( 'Maya Lindgren is already running', $response->get_data()['message'] );
		}
		$this->dispatch( 'POST', '/stop' );
		$this->assertSame( $this->admin, get_option( Migration_Admin::LEASE )['user'], 'Only the holder can give the run back.' );
		$this->assertSame( $run, Migration_Store::state()['run'], 'Nothing was restarted.' );

		// A lease nobody renews lapses, so a closed tab doesn't lock others out for long.
		update_option(
			Migration_Admin::LEASE,
			array(
				'user'  => $this->admin,
				'until' => time() - 1,
			),
			false
		);
		$this->assertSame( 'empty', $this->scan_until( 'empty', $run )['phase'] );
		$this->assertNull( get_option( Migration_Admin::LEASE, null ) );
	}

	public function test_a_step_waits_for_another_request_on_the_same_site(): void {
		$response = Migration_Store::locked(
			function () {
				return $this->dispatch( 'POST', '/scan' );
			}
		);

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'busy', $response->get_data()['data']['reason'] );
		$this->assertSame( 0, $this->http->count() );
	}

	public function test_stopping_pauses_until_the_admin_resumes(): void {
		for ( $i = 0; $i < 51; ++$i ) {
			$this->post( 'Words ' . $i, 'Post ' . $i );
		}
		$this->catalogue();
		$run = $this->dispatch( 'POST', '/scan' )->get_data()['run'];

		$view = $this->dispatch( 'POST', '/stop' )->get_data();

		$this->assertTrue( $view['scan']['stopped'] );
		$this->assertNull( $view['lease'] );
		$this->assertFalse( $this->dispatch( 'POST', '/scan', array( 'run' => $run ) )->get_data()['scan']['stopped'] );
	}

	public function test_a_step_for_a_run_that_changed_returns_the_fresh_view(): void {
		$this->post( '[powerpress url="https://media.example/Hello.mp3"]', 'Hello' );
		$this->catalogue();
		$view = $this->scan_until( 'report' );

		$response = $this->dispatch( 'POST', '/scan', array( 'run' => '51111111-2222-4333-8444-555555555555' ) );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'stale', $response->get_data()['data']['reason'] );
		$this->assertSame( $view['run'], $response->get_data()['data']['view']['run'] );

		$swap = $this->dispatch( 'POST', '/swap', array( 'run' => '51111111-2222-4333-8444-555555555555' ) );
		$this->assertSame( 'stale', $swap->get_data()['data']['reason'] );
		$this->assertSame(
			'stale',
			$this->dispatch(
				'GET',
				'/rows',
				array(
					'run'   => '51111111-2222-4333-8444-555555555555',
					'group' => 'ready',
				)
			)->get_data()['data']['reason']
		);
	}

	public function test_a_choice_must_be_one_of_the_stored_candidates(): void {
		$hello  = $this->post( '[powerpress url="https://media.example/Hello.mp3"]', 'Hello' );
		$bakery = $this->post( '<iframe src="https://www.buzzsprout.com/1234/episodes/1234567-bakery"></iframe>', 'The village bakery', '2024-03-01 09:00:00' );
		$this->catalogue();
		$run = $this->scan_until( 'report' )['run'];

		$refused = array(
			'another episode'   => array( $bakery, 1, self::HELLO ),
			'a matched embed'   => array( $hello, 1, self::HELLO ),
			'a missing embed'   => array( $bakery, 2, self::BAKERY ),
			'a post not listed' => array( $bakery + 100, 1, self::BAKERY ),
		);
		foreach ( $refused as $case => list( $post, $embed, $episode ) ) {
			$response = $this->dispatch(
				'POST',
				'/choice',
				array(
					'run'     => $run,
					'post'    => $post,
					'embed'   => $embed,
					'episode' => $episode,
				)
			);
			$this->assertSame( 400, $response->get_status(), $case );
			$this->assertSame( 'invalid_choice', $response->get_data()['data']['reason'], $case );
		}
		$this->assertSame( 0, $this->dispatch( 'GET' )->get_data()['report']['chosen'] );

		$view = $this->choose( $run, $bakery, strtoupper( self::BAKERY2 ) );
		$this->assertSame( self::BAKERY2, $view['choice'], 'The answer says what was stored.' );
		$this->assertSame( 1, $view['report']['chosen'] );
		$this->assertSame(
			array(
				'embeds' => 2,
				'posts'  => 2,
			),
			$view['report']['swap']
		);
		$row = $this->rows( $run, 'choose' )['rows'][0];
		$this->assertSame( self::BAKERY2, $row['choice'] );

		$cleared = $this->choose( $run, $bakery, '' );
		$this->assertSame( 0, $cleared['report']['chosen'] );
		$this->assertSame( '', $this->rows( $run, 'choose' )['rows'][0]['choice'] );
	}

	public function test_the_swap_runs_in_steps_and_links_each_revision(): void {
		$hello  = $this->post( '[powerpress url="https://media.example/Hello.mp3"]', 'Hello' );
		$bakery = $this->post( '<iframe src="https://www.buzzsprout.com/1234/episodes/1234567-bakery"></iframe>', 'The village bakery', '2024-03-01 09:00:00' );
		$skip   = $this->post( '<iframe src="https://open.spotify.com/embed/episode/abc123"></iframe>', 'Guest spot' );
		$this->catalogue();
		$run = $this->scan_until( 'report' )['run'];
		$this->choose( $run, $bakery, self::BAKERY );

		$view = $this->swap( $run );

		$this->assertSame( 'results', $view['phase'] );
		$this->assertSame( 2, $view['swap']['embeds'] );
		$this->assertSame( 2, $view['swap']['posts'] );
		$this->assertSame( 0, $view['swap']['failed'] );
		$this->assertStringContainsString( '<!-- wp:showfm/player', get_post( $hello )->post_content );
		$this->assertStringContainsString( self::BAKERY, get_post( $bakery )->post_content );
		$this->assertStringNotContainsString( 'showfm/player', get_post( $skip )->post_content );

		$changed = $this->rows( $run, 'changed' );
		$this->assertSame( 2, $changed['total'] );
		$by_post = array_column( $changed['rows'], null, 'id' );
		$this->assertSame( 'PowerPress shortcode → show.fm Player', $by_post[ (string) $hello ]['change'] );
		$this->assertSame( 'Buzzsprout embed → show.fm Player', $by_post[ (string) $bakery ]['change'] );
		$this->assertStringContainsString( 'revision.php?revision=', $by_post[ (string) $hello ]['revisionUrl'] );
		$this->assertSame( get_permalink( $hello ), $by_post[ (string) $hello ]['post']['url'] );

		$choice = $this->dispatch(
			'POST',
			'/choice',
			array(
				'run'     => $run,
				'post'    => $bakery,
				'embed'   => 1,
				'episode' => self::BAKERY2,
			)
		);
		$this->assertSame( 'swapped', $choice->get_data()['data']['reason'], 'Choices are fixed once the swap starts.' );
		$this->assertSame( 'swapped', $this->rows( $run, 'ready' )['rows'][0]['status'] );
	}

	public function test_a_post_edited_after_the_scan_is_listed_with_the_reason(): void {
		$hello = $this->post( '[powerpress url="https://media.example/Hello.mp3"]', 'Hello' );
		$this->catalogue();
		$run = $this->scan_until( 'report' )['run'];
		wp_update_post(
			array(
				'ID'         => $hello,
				'post_title' => 'Hello again',
			)
		);

		$view = $this->swap( $run );

		$this->assertSame( 'results', $view['phase'] );
		$this->assertSame( 1, $view['swap']['failed'] );
		$failed = $this->rows( $run, 'failed' )['rows'][0];
		$this->assertSame( $hello, $failed['post']['id'] );
		$this->assertStringContainsString( 'changed after scanning', $failed['reason'] );
	}

	public function test_a_swap_after_reconnecting_with_another_key_needs_a_new_scan(): void {
		$hello = $this->post( '[powerpress url="https://media.example/Hello.mp3"]', 'Hello' );
		$this->catalogue();
		$run = $this->scan_until( 'report' )['run'];

		Plugin::connection()->save( 'showfm_live_ANOTHERKEY12345678901234567890', str_repeat( 'b', 64 ), self::PODCAST, 0 );
		Plugin::reset();

		$this->assertSame( 'reconnected', $this->dispatch( 'GET' )->get_data()['problem']['reason'] );
		$response = $this->dispatch( 'POST', '/swap', array( 'run' => $run ) );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'reconnected', $response->get_data()['data']['reason'] );
		$this->assertStringNotContainsString( 'showfm/player', get_post( $hello )->post_content );
	}

	public function test_a_swap_that_waited_while_the_site_reconnected_does_not_swap(): void {
		$hello = $this->post( '[powerpress url="https://media.example/Hello.mp3"]', 'Hello' );
		$this->catalogue();
		$run = $this->scan_until( 'report' )['run'];

		// The site reconnects with another key while this swap request waits for the lock,
		// and this request's option cache still holds the old connection.
		$old     = get_option( Connection::OPTION );
		$reads   = 0;
		$started = false;
		$hook    = static function ( $sql ) use ( &$reads, &$started, $old ) {
			if ( ! $started && false !== strpos( $sql, Migration_Store::STATE ) && 0 === strpos( ltrim( $sql ), 'SELECT option_value' ) && 2 === ++$reads ) {
				$started = true;
				( new Connection() )->save( 'showfm_live_ANOTHERKEY12345678901234567890', str_repeat( 'b', 64 ), self::PODCAST, 0 );
				wp_cache_set( Connection::OPTION, $old, 'options' );
				$all = wp_cache_get( 'alloptions', 'options' );
				if ( is_array( $all ) && array_key_exists( Connection::OPTION, $all ) ) {
					$all[ Connection::OPTION ] = maybe_serialize( $old );
					wp_cache_set( 'alloptions', $all, 'options' );
				}
			}
			return $sql;
		};
		add_filter( 'query', $hook );
		$response = $this->dispatch(
			'POST',
			'/swap',
			array(
				'run'     => $run,
				'confirm' => true,
			)
		);
		remove_filter( 'query', $hook );

		$this->assertTrue( $started, 'The interleaving was simulated.' );
		$this->assertSame( 409, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'reconnected', $response->get_data()['data']['reason'] );
		$this->assertStringNotContainsString( 'showfm/player', get_post( $hello )->post_content );
	}

	public function test_a_connection_lost_during_the_swap_stops_it_without_moving_on(): void {
		$hello = $this->post( '[powerpress url="https://media.example/Hello.mp3"]', 'Hello' );
		$this->catalogue();
		$run = $this->scan_until( 'report' )['run'];

		Plugin::connection()->disconnect();
		$response = $this->dispatch( 'POST', '/swap', array( 'run' => $run ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'connection_lost', $response->get_data()['data']['reason'] );
		$this->assertStringNotContainsString( 'showfm/player', get_post( $hello )->post_content );
	}

	public function test_the_cli_table_uses_the_reports_words(): void {
		$this->post( '[powerpress url="https://media.example/Hello.mp3"]', 'Hello' );
		$this->post( '<iframe src="https://www.buzzsprout.com/1234/episodes/1234567-bakery"></iframe>', 'The village bakery', '2024-03-01 09:00:00' );
		$this->catalogue();
		WP_CLI::$output = array();

		( new Migration_Cli( new Migrator( Plugin::connection(), Plugin::api_client() ) ) )( array(), array( 'dry-run' => true ) );

		$lines = array_column( WP_CLI::$output, 1 );
		$this->assertSame( "POST\tEMBED\tHOST\tSTATUS\tMETHOD\tCANDIDATES\tREVISION", $lines[0] );
		$table = implode( "\n", $lines );
		$this->assertStringContainsString( "\t1\tPowerPress\tReady\tAudio file\t" . self::HELLO . "\t-", $table );
		$this->assertStringContainsString( "\t1\tBuzzsprout\tChoose one\tTitle and date\t", $table );
	}

	public function test_wp_cli_waits_while_another_admin_runs_the_tab(): void {
		for ( $i = 0; $i < 51; ++$i ) {
			$this->post( 'Words ' . $i, 'Post ' . $i );
		}
		$this->catalogue();
		$run = $this->dispatch( 'POST', '/scan' )->get_data()['run'];

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		try {
			( new Migration_Cli( new Migrator( Plugin::connection(), Plugin::api_client() ) ) )( array(), array( 'dry-run' => true ) );
			$this->fail( 'WP-CLI must wait for the admin running the tab.' );
		} catch ( ShowFM_Cli_Halt $halt ) {
			$this->assertStringContainsString( 'Maya Lindgren is already running a scan or swap', $halt->getMessage() );
		}
		$this->assertSame( $run, Migration_Store::state()['run'] );
	}

	public function test_a_swap_needs_a_confirm_and_another_admin_confirms_to_carry_it_on(): void {
		for ( $i = 0; $i < 6; ++$i ) {
			$this->post( '[powerpress url="https://media.example/Hello.mp3"]', 'Hello' );
		}
		$this->catalogue();
		$run = $this->scan_until( 'report' )['run'];

		$unconfirmed = $this->dispatch( 'POST', '/swap', array( 'run' => $run ) );
		$this->assertSame( 409, $unconfirmed->get_status() );
		$this->assertSame( 'confirm', $unconfirmed->get_data()['data']['reason'] );

		$view = $this->swap( $run );
		$this->assertSame( 'swapping', $view['phase'] );
		$this->assertTrue( $view['swap']['mine'] );
		$this->assertSame( 5, $view['swap']['checked'] );

		// The first admin closes the tab; the lease lapses.
		update_option(
			Migration_Admin::LEASE,
			array(
				'user'  => $this->admin,
				'until' => time() - 1,
			),
			false
		);
		$other = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $other );
		}
		wp_set_current_user( $other );
		$watching = $this->dispatch( 'GET' )->get_data();
		$this->assertFalse( $watching['swap']['mine'] );
		$this->assertSame( 'Maya Lindgren', $watching['swap']['by'] );

		$refused = $this->dispatch( 'POST', '/swap', array( 'run' => $run ) );
		$this->assertSame( 'confirm', $refused->get_data()['data']['reason'] );
		$this->assertStringContainsString( 'Maya Lindgren started this swap', $refused->get_data()['message'] );
		$this->assertSame( 5, $refused->get_data()['data']['view']['swap']['checked'], 'Nothing moved on.' );

		$done = $this->swap( $run );
		$this->assertSame( 'results', $done['phase'] );
		$this->assertSame( 6, $done['swap']['posts'] );
		$this->assertTrue( $done['swap']['mine'] );
	}

	public function test_picks_are_frozen_when_the_swap_starts(): void {
		for ( $i = 0; $i < 5; ++$i ) {
			$this->post( '[powerpress url="https://media.example/Hello.mp3"]', 'Hello' );
		}
		$bakery = $this->post( '<iframe src="https://www.buzzsprout.com/1234/episodes/1234567-bakery"></iframe>', 'The village bakery', '2024-03-01 09:00:00' );
		$this->catalogue();
		$run = $this->scan_until( 'report' )['run'];

		$this->assertSame( 'swapping', $this->swap( $run )['phase'] );

		$late = $this->dispatch(
			'POST',
			'/choice',
			array(
				'run'     => $run,
				'post'    => $bakery,
				'embed'   => 1,
				'episode' => self::BAKERY,
			)
		);
		$this->assertSame( 409, $late->get_status() );
		$this->assertSame( 'swapped', $late->get_data()['data']['reason'] );

		// Even a pick written behind the route's back after the start is ignored.
		update_option( 'showfm_migration_' . $run . '_choices', array( $bakery => array( 1 => self::BAKERY ) ), false );
		$done = $this->swap( $run );

		$this->assertSame( 'results', $done['phase'] );
		$this->assertSame( 5, $done['swap']['posts'] );
		$this->assertStringNotContainsString( 'showfm/player', get_post( $bakery )->post_content );
	}

	public function test_a_swap_that_waited_while_a_new_scan_started_does_not_swap(): void {
		$hello = $this->post( '[powerpress url="https://media.example/Hello.mp3"]', 'Hello' );
		$this->catalogue();
		$run = $this->scan_until( 'report' )['run'];

		// Another tab starts a new scan while this swap request waits for the site lock:
		// the second read of the state (the one under the lock) sees the new run.
		$reads   = 0;
		$started = false;
		$hook    = static function ( $sql ) use ( &$reads, &$started ) {
			if ( ! $started && false !== strpos( $sql, Migration_Store::STATE ) && 0 === strpos( ltrim( $sql ), 'SELECT option_value' ) && 2 === ++$reads ) {
				$started = true;
				$state   = Migration_Store::state();
				update_option(
					Migration_Store::STATE,
					array_merge(
						$state,
						array(
							'run'      => 'replacement',
							'complete' => false,
						)
					),
					false
				);
			}
			return $sql;
		};
		add_filter( 'query', $hook );
		$response = $this->dispatch(
			'POST',
			'/swap',
			array(
				'run'     => $run,
				'confirm' => true,
			)
		);
		remove_filter( 'query', $hook );

		$this->assertTrue( $started, 'The interleaving was simulated.' );
		$this->assertSame( 409, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertStringNotContainsString( 'showfm/player', get_post( $hello )->post_content );
		$this->assertSame( array(), get_option( 'showfm_migration_' . $run . '_swap', array() ), 'No cursor for the superseded run.' );
	}

	public function test_a_post_the_admin_cannot_edit_is_a_failure_row_and_the_swap_goes_on(): void {
		$locked = $this->post( '[powerpress url="https://media.example/Hello.mp3"]', 'Hello' );
		$open   = $this->post( '[powerpress url="https://media.example/Hello.mp3"]', 'Hello' );
		$this->catalogue();
		$run  = $this->scan_until( 'report' )['run'];
		$deny = static function ( $caps, $cap, $user_id, $args ) use ( $locked ) {
			return 'edit_post' === $cap && (int) ( $args[0] ?? 0 ) === $locked ? array( 'do_not_allow' ) : $caps;
		};
		add_filter( 'map_meta_cap', $deny, 10, 4 );

		$view = $this->swap( $run );
		remove_filter( 'map_meta_cap', $deny, 10 );

		$this->assertSame( 'results', $view['phase'] );
		$this->assertSame( 1, $view['swap']['posts'] );
		$this->assertSame( 1, $view['swap']['failed'] );
		$failed = $this->rows( $run, 'failed' )['rows'][0];
		$this->assertSame( $locked, $failed['post']['id'] );
		$this->assertSame( 'You can’t edit this post, so it wasn’t changed.', $failed['reason'] );
		$this->assertStringContainsString( 'showfm/player', get_post( $open )->post_content );
	}

	public function test_stop_says_so_when_the_run_cannot_be_given_back(): void {
		for ( $i = 0; $i < 51; ++$i ) {
			$this->post( 'Words ' . $i, 'Post ' . $i );
		}
		$this->catalogue();
		$this->dispatch( 'POST', '/scan' );

		$response = Migration_Store::locked(
			function () {
				return $this->dispatch( 'POST', '/stop' );
			}
		);

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'busy', $response->get_data()['data']['reason'] );
		$this->assertSame( $this->admin, get_option( Migration_Admin::LEASE )['user'] );
	}

	public function test_the_lease_is_per_site(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
		for ( $i = 0; $i < 51; ++$i ) {
			$this->post( 'Words ' . $i, 'Post ' . $i );
		}
		$this->catalogue();
		$this->dispatch( 'POST', '/scan' );
		$blog  = self::factory()->blog->create();
		$other = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $other );

		switch_to_blog( $blog );
		$elsewhere = Migration_Admin::claim();
		$lease     = get_option( Migration_Admin::LEASE );
		restore_current_blog();

		$this->assertTrue( $elsewhere, 'Another site has its own lease.' );
		$this->assertSame( $other, $lease['user'] );
		$here = Migration_Admin::claim();
		$this->assertWPError( $here );
		$this->assertSame( 'showfm_migration_busy', $here->get_error_code() );
	}

	public function test_wp_cli_reset_waits_while_another_admin_runs_the_tab(): void {
		$this->post( '[powerpress url="https://media.example/Hello.mp3"]', 'Hello' );
		$this->http->respond( 503 );
		$this->dispatch( 'POST', '/scan' );
		$pending = get_option( Migration_Catalogue::PENDING );
		$this->assertNotEmpty( $pending );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		try {
			( new Migration_Cli( new Migrator( Plugin::connection(), Plugin::api_client() ) ) )( array(), array( 'reset' => true ) );
			$this->fail( 'WP-CLI must not reset the admin\'s live scan.' );
		} catch ( ShowFM_Cli_Halt $halt ) {
			$this->assertStringContainsString( 'Maya Lindgren is already running a scan or swap', $halt->getMessage() );
		}
		$this->assertSame( $pending, get_option( Migration_Catalogue::PENDING ) );
		$this->assertSame( $this->admin, get_option( Migration_Admin::LEASE )['user'] );
	}

	public function test_wp_cli_takes_the_lease_and_gives_it_back_on_success_and_failure(): void {
		$this->post( '[powerpress url="https://media.example/Hello.mp3"]', 'Hello' );
		$this->catalogue();
		$cli    = new Migration_Cli( new Migrator( Plugin::connection(), Plugin::api_client() ) );
		$leases = array();
		$spy    = static function ( $value ) use ( &$leases ) {
			$leases[] = $value['user'] ?? null;
			return $value;
		};
		add_filter( 'pre_update_option_' . Migration_Admin::LEASE, $spy );

		$cli( array(), array( 'dry-run' => true ) );
		$this->assertContains( $this->admin, $leases, 'The run held the lease.' );
		$this->assertFalse( get_option( Migration_Admin::LEASE ) );

		Migration_Store::clear();
		try {
			$cli( array(), array( 'yes' => true ) );
			$this->fail( 'Applying needs a finished dry run.' );
		} catch ( ShowFM_Cli_Halt $halt ) {
			$this->assertStringContainsString( 'Complete a dry run', $halt->getMessage() );
		}
		remove_filter( 'pre_update_option_' . Migration_Admin::LEASE, $spy );
		$this->assertFalse( get_option( Migration_Admin::LEASE ), 'A failed run gives the lease back too.' );
	}

	/**
	 * Steps the scan until it reaches a phase.
	 *
	 * @param string $phase Phase.
	 * @param string $run   Run to step, or '' for a new scan.
	 * @return array<string,mixed> View.
	 */
	private function scan_until( string $phase, string $run = '' ): array {
		for ( $i = 0; $i < 30; ++$i ) {
			$response = $this->dispatch( 'POST', '/scan', '' === $run ? array() : array( 'run' => $run ) );
			$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
			$view = $response->get_data();
			$run  = $view['run'];
			if ( $phase === $view['phase'] ) {
				return $view;
			}
		}
		$this->fail( 'The scan never reached ' . $phase . ': ' . wp_json_encode( $view ) );
	}

	/**
	 * One confirmed swap step.
	 *
	 * @param string $run Run.
	 * @return array<string,mixed> View.
	 */
	private function swap( string $run ): array {
		$response = $this->dispatch(
			'POST',
			'/swap',
			array(
				'run'     => $run,
				'confirm' => true,
			)
		);
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		return $response->get_data();
	}

	/**
	 * Picks an episode.
	 *
	 * @param string $run     Run.
	 * @param int    $post    Post ID.
	 * @param string $episode Episode UUID, or ''.
	 * @return array<string,mixed> View.
	 */
	private function choose( string $run, int $post, string $episode ): array {
		$response = $this->dispatch(
			'POST',
			'/choice',
			array(
				'run'     => $run,
				'post'    => $post,
				'embed'   => 1,
				'episode' => $episode,
			)
		);
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		return $response->get_data();
	}

	/**
	 * A page of rows.
	 *
	 * @param string $run   Run.
	 * @param string $group Group.
	 * @return array<string,mixed> Rows and total.
	 */
	private function rows( string $run, string $group ): array {
		$response = $this->dispatch(
			'GET',
			'/rows',
			array(
				'run'   => $run,
				'group' => $group,
			)
		);
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		return $response->get_data();
	}

	/**
	 * Dispatches a request to a migrate route.
	 *
	 * @param string              $method Method.
	 * @param string              $path   Path after the base route.
	 * @param array<string,mixed> $params Parameters.
	 */
	private function dispatch( string $method, string $path = '', array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, self::ROUTE . $path );
		foreach ( $params as $name => $value ) {
			$request->set_param( $name, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * A published post with the given content, stored as an import would leave it.
	 *
	 * @param string $content Content.
	 * @param string $title   Title.
	 * @param string $date    Date (GMT).
	 */
	private function post( string $content, string $title, string $date = '2024-01-02 12:00:00' ): int {
		$id = self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_title'    => $title,
				'post_date'     => $date,
				'post_date_gmt' => $date,
			)
		);
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_content' => $content ), array( 'ID' => $id ) );
		clean_post_cache( $id );
		return $id;
	}

	/**
	 * A keyed episode list row with provenance.
	 *
	 * @param string      $id    Episode UUID.
	 * @param string      $title Title.
	 * @param string      $date  Published at.
	 * @param string|null $audio Source enclosure, or null.
	 * @return array<string,mixed>
	 */
	private function episode( string $id, string $title, string $date, ?string $audio = null ): array {
		return array(
			'id'           => $id,
			'podcast_id'   => self::PODCAST,
			'title'        => $title,
			'status'       => 'published',
			'published_at' => $date,
			'source'       => array(
				'enclosure_sha256' => null === $audio ? null : Migration_Url::fingerprint( Migration_Url::normalise( $audio ) ),
				'guid_sha256'      => null,
			),
			'rss_guid'     => null,
		);
	}

	/** Queues the podcast list and one page of episodes. */
	private function catalogue(): void {
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
					'data'       => array(
						$this->episode( self::HELLO, 'Hello', '2024-01-02T12:00:00Z', 'https://media.example/Hello.mp3' ),
						$this->episode( self::BAKERY, 'The village bakery', '2024-03-01T10:00:00Z' ),
						$this->episode( self::BAKERY2, 'The village bakery', '2024-03-01T18:00:00Z' ),
					),
					'pagination' => array( 'next_cursor' => null ),
				)
			)
		);
	}
}
