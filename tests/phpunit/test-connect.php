<?php
/**
 * The browser connect flow: start, return, exchange and verify.
 *
 * @package ShowFM
 */

use ShowFM\Admin;
use ShowFM\Api_Client;
use ShowFM\Connect;
use ShowFM\Connection;
use ShowFM\Health;

/**
 * Connect flow tests.
 */
class Test_Connect extends WP_UnitTestCase {

	const SITE_ID = '0b5d6f0e-1c2d-4e3f-8a9b-0c1d2e3f4a5b';
	const KEY     = 'showfm_live_NEWKEYabcdefghijklmnopqrstuv';
	const SECRET  = '9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08';
	const CODE    = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQ';

	/**
	 * HTTP mock.
	 *
	 * @var ShowFM_Http_Mock
	 */
	private $http;

	/**
	 * Connection.
	 *
	 * @var Connection
	 */
	private $connection;

	/**
	 * Service under test.
	 *
	 * @var Connect
	 */
	private $connect;

	/**
	 * The admin running the flow.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Captured error log.
	 *
	 * @var string
	 */
	private $log_file;

	/**
	 * Previous error_log setting.
	 *
	 * @var string|false
	 */
	private $previous_log;

	public function set_up(): void {
		parent::set_up();
		$this->http       = new ShowFM_Http_Mock();
		$this->connection = new Connection();
		$this->connection->disconnect();
		$this->connect = new Connect( $this->connection, new Api_Client( $this->connection ) );
		$this->user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $this->user_id );
		}
		wp_set_current_user( $this->user_id );
		delete_option( Api_Client::RATE_LIMIT_OPTION );
		delete_option( Connect::VERIFY_PENDING_OPTION );
		wp_clear_scheduled_hook( Health::HOOK );

		$this->log_file     = wp_tempnam( 'showfm-log' );
		$this->previous_log = ini_set( 'error_log', $this->log_file );
	}

	public function tear_down(): void {
		unset( $_REQUEST['_wpnonce'], $_GET['code'], $_GET['state'] );
		remove_filter( 'home_url', array( $this, 'https_home' ) );
		remove_filter( 'home_url', array( $this, 'http_home' ) );
		ini_set( 'error_log', (string) $this->previous_log );
		$log = (string) file_get_contents( $this->log_file );
		unlink( $this->log_file );
		$this->http->detach();
		$this->connection->disconnect();
		wp_clear_scheduled_hook( Health::HOOK );
		parent::tear_down();
		foreach ( array( self::KEY, self::CODE, self::SECRET ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $log, 'Secrets must never be logged.' );
		}
	}


	public function test_start_stores_the_flow_and_builds_the_redirect_without_http(): void {
		$url = $this->connect->start( $this->user_id );

		$this->assertSame( 0, $this->http->count(), 'Starting the flow makes no request.' );

		$parts = wp_parse_url( $url );
		$this->assertSame( 'https', $parts['scheme'] );
		$this->assertSame( 'my.show.fm', $parts['host'] );
		$this->assertSame( '/connect/wordpress', $parts['path'] );
		parse_str( $parts['query'], $query );
		$this->assertSame( array( 'site_url', 'rest_root', 'state', 'code_challenge', 'return' ), array_keys( $query ) );
		$this->assertSame( home_url(), $query['site_url'] );
		$this->assertSame( rest_url(), $query['rest_root'] );
		$this->assertSame( admin_url( 'options-general.php?page=showfm&showfm_return=' . get_transient( Connect::FLOW_PREFIX . $this->user_id )['return'] ), $query['return'] );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{22}$/', get_transient( Connect::FLOW_PREFIX . $this->user_id )['return'], 'A random token per flow.' );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{43}$/', $query['state'], '32 random bytes, base64url.' );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{43}$/', $query['code_challenge'] );

		$flow = get_transient( Connect::FLOW_PREFIX . $this->user_id );
		$this->assertSame( $query['state'], $flow['state'] );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9._~-]{43,128}$/', $flow['verifier'] );
		$this->assertSame( $query['code_challenge'], Connect::s256( $flow['verifier'] ) );
		$this->assertStringNotContainsString( $flow['verifier'], $url, 'The verifier never leaves the server.' );
		$this->assertSame( $query['code_challenge'], Connect::challenge_for_state( $query['state'] ) );

		$timeout = (int) get_option( '_transient_timeout_' . Connect::FLOW_PREFIX . $this->user_id );
		$this->assertEqualsWithDelta( time() + 600, $timeout, 5, 'The flow lives 10 minutes.' );
	}

	public function test_s256_matches_the_rfc_7636_vector(): void {
		$this->assertSame( 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', Connect::s256( 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk' ) );
	}

	public function test_each_start_uses_a_new_state_and_drops_the_old_challenge(): void {
		parse_str( (string) wp_parse_url( $this->connect->start( $this->user_id ), PHP_URL_QUERY ), $first );
		parse_str( (string) wp_parse_url( $this->connect->start( $this->user_id ), PHP_URL_QUERY ), $second );

		$this->assertNotSame( $first['state'], $second['state'] );
		$this->assertNull( Connect::challenge_for_state( $first['state'] ) );
		$this->assertNotNull( Connect::challenge_for_state( $second['state'] ) );
	}

	public function test_partner_is_validated(): void {
		$this->assertNull( Connect::partner(), 'Unset by default.' );
		$this->assertStringNotContainsString( 'partner=', $this->connect->start( $this->user_id ) );

		$this->assertSame( 'acme-hosting', Connect::sanitize_partner( ' Acme-Hosting ' ) );
		$this->assertNull( Connect::sanitize_partner( 'a' ) );
		$this->assertNull( Connect::sanitize_partner( '-acme' ) );
		$this->assertNull( Connect::sanitize_partner( 'acme hosting' ) );
		$this->assertNull( Connect::sanitize_partner( str_repeat( 'a', 41 ) ) );
		$this->assertNull( Connect::sanitize_partner( array( 'acme' ) ) );
	}

	public function test_app_url_accepts_only_show_fm_hosts(): void {
		$this->assertSame( 'https://my.show.fm', Connect::app_url(), 'Production by default.' );
		$this->assertSame( 'https://my.showfm.dev', Connect::sanitize_app_url( 'https://my.showfm.dev' ) );
		$this->assertSame( 'https://my.showfm.dev', Connect::sanitize_app_url( 'https://MY.showfm.dev/' ) );
		$this->assertSame( 'https://my.show.fm', Connect::sanitize_app_url( 'https://evil.example' ) );
		$this->assertSame( 'https://my.show.fm', Connect::sanitize_app_url( 'http://my.showfm.dev' ) );
		$this->assertSame( 'https://my.show.fm', Connect::sanitize_app_url( 'https://my.showfm.dev/x' ) );
		$this->assertSame( 'https://my.show.fm', Connect::sanitize_app_url( 'https://my.showfm.dev:8443' ) );
		$this->assertSame( 'https://my.show.fm', Connect::sanitize_app_url( 'https://user@my.showfm.dev' ) );
		$this->assertSame( 'https://my.show.fm', Connect::sanitize_app_url( null ) );
	}

	/**
	 * Constants cannot be undefined, so this runs in its own process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_constants_set_the_partner_and_the_staging_app(): void {
		define( 'SHOWFM_PARTNER', 'Acme-Hosting' );
		define( 'SHOWFM_APP_URL', 'https://my.showfm.dev' );

		$url = $this->connect->start( $this->user_id );

		$this->assertStringStartsWith( 'https://my.showfm.dev/connect/wordpress?', $url );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$this->assertSame( 'acme-hosting', $query['partner'] );
		$this->assertSame( array( 'my.showfm.dev' ), array_values( array_intersect( array( 'my.showfm.dev' ), Admin::allow_app_host( array() ) ) ) );
	}

	public function test_admin_post_needs_manage_options(): void {
		$admin = new Admin( $this->connect, $this->connection );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( Connect::ACTION );

		$this->expectException( WPDieException::class );
		$admin->start_connect();
	}

	public function test_admin_post_needs_a_nonce(): void {
		$admin                = new Admin( $this->connect, $this->connection );
		$_REQUEST['_wpnonce'] = 'not-a-nonce';

		try {
			$admin->start_connect();
			$this->fail( 'A bad nonce must stop the flow.' );
		} catch ( WPDieException $e ) {
			$this->assertFalse( get_transient( Connect::FLOW_PREFIX . $this->user_id ) );
		}
	}

	public function test_admin_post_redirects_to_the_app(): void {
		add_filter( 'home_url', array( $this, 'https_home' ) );
		$admin                = new Admin( $this->connect, $this->connection );
		$_REQUEST['_wpnonce'] = wp_create_nonce( Connect::ACTION );
		$location             = $this->capture_redirect( array( $admin, 'start_connect' ) );

		$this->assertStringStartsWith( 'https://my.show.fm/connect/wordpress?', $location );
		$this->assertSame( 0, $this->http->count() );
	}

	public function test_admin_post_stops_a_site_without_https(): void {
		add_filter( 'home_url', array( $this, 'http_home' ) );
		$admin                = new Admin( $this->connect, $this->connection );
		$_REQUEST['_wpnonce'] = wp_create_nonce( Connect::ACTION );
		$location             = $this->capture_redirect( array( $admin, 'start_connect' ) );

		$this->assertSame( admin_url( 'options-general.php?page=showfm' ), $location );
		$this->assertSame( Connect::ERROR_INSECURE, Connect::result( $this->user_id )['error'] );
		$this->assertFalse( get_transient( Connect::FLOW_PREFIX . $this->user_id ), 'No flow is started.' );
		$this->assertSame( 0, $this->http->count() );
	}

	public function test_returning_without_approval_records_a_cancel(): void {
		$state = $this->started_state();

		$clean = $this->connect->handle_return( $this->user_id, array( Connect::RETURN_ARG => $this->return_token() ) );

		$this->assertSame( admin_url( 'options-general.php?page=showfm' ), $clean );
		$this->assertSame( Connect::ERROR_CANCELLED, Connect::result( $this->user_id )['error'] );
		$this->assertFalse( get_transient( Connect::FLOW_PREFIX . $this->user_id ), 'The flow is over.' );
		$this->assertNull( Connect::challenge_for_state( $state ) );
	}

	public function test_a_crafted_return_link_does_not_cancel_the_flow(): void {
		$state = $this->started_state();

		$this->connect->handle_return( $this->user_id, array( Connect::RETURN_ARG => '1' ) );
		$this->connect->handle_return( $this->user_id, array( Connect::RETURN_ARG => str_repeat( 'a', 22 ) ) );

		$this->assertNull( Connect::result( $this->user_id ), 'No spurious "cancelled".' );
		$this->assertSame( $state, get_transient( Connect::FLOW_PREFIX . $this->user_id )['state'], 'The flow goes on.' );
		$this->assertNotNull( Connect::challenge_for_state( $state ) );
	}

	public function test_a_return_marker_without_a_flow_changes_nothing(): void {
		$clean = $this->connect->handle_return( $this->user_id, array( Connect::RETURN_ARG => '1' ) );

		$this->assertSame( admin_url( 'options-general.php?page=showfm' ), $clean );
		$this->assertNull( Connect::result( $this->user_id ) );
	}

	public function test_a_return_marker_does_not_cancel_a_code_waiting_for_exchange(): void {
		$this->started_state();
		$token = $this->return_token();
		$this->connect->handle_return(
			$this->user_id,
			array(
				'code'  => self::CODE,
				'state' => get_transient( Connect::FLOW_PREFIX . $this->user_id )['state'],
			)
		);

		$this->connect->handle_return( $this->user_id, array( Connect::RETURN_ARG => $token ) );

		$this->assertSame( self::CODE, get_transient( Connect::FLOW_PREFIX . $this->user_id )['code'] );
		$this->assertNull( Connect::result( $this->user_id ) );
	}

	public function test_the_old_settings_address_redirects_and_keeps_the_return(): void {
		global $pagenow;
		$previous      = $pagenow;
		$pagenow       = 'admin.php';
		$_GET['page']  = 'showfm';
		$_GET['code']  = self::CODE;
		$_GET['state'] = 'abc_DEF-123';
		$_GET['other'] = 'dropped';

		try {
			$location = $this->capture_redirect( array( Admin::class, 'redirect_old_address' ) );
		} finally {
			$pagenow = $previous;
			unset( $_GET['page'], $_GET['other'] );
		}

		$this->assertSame( admin_url( 'options-general.php?page=showfm&code=' . self::CODE . '&state=abc_DEF-123' ), $location );
	}

	public function test_other_admin_pages_are_not_redirected(): void {
		global $pagenow;
		$previous     = $pagenow;
		$pagenow      = 'admin.php';
		$_GET['page'] = 'another-plugin';
		$redirected   = false;
		$watch        = static function ( $location ) use ( &$redirected ) {
			$redirected = true;
			return $location;
		};
		add_filter( 'wp_redirect', $watch );

		Admin::redirect_old_address();

		remove_filter( 'wp_redirect', $watch );
		$pagenow = $previous;
		unset( $_GET['page'] );
		$this->assertFalse( $redirected );
	}


	public function test_no_code_or_state_is_nothing_to_do(): void {
		$this->assertNull( $this->connect->handle_return( $this->user_id, array( 'page' => 'showfm' ) ) );
		$this->assertNull( Connect::result( $this->user_id ) );
	}

	public function test_matching_state_keeps_the_code_server_side_and_cleans_the_url(): void {
		$state = $this->started_state();

		$clean = $this->connect->handle_return(
			$this->user_id,
			array(
				'code'  => self::CODE,
				'state' => $state,
			)
		);

		$this->assertSame( admin_url( 'options-general.php?page=showfm' ), $clean );
		$this->assertStringNotContainsString( 'code=', $clean );
		$this->assertStringNotContainsString( 'state=', $clean );
		$this->assertSame( 0, $this->http->count(), 'The exchange runs on the clean page load, not here.' );
		$flow = get_transient( Connect::FLOW_PREFIX . $this->user_id );
		$this->assertSame( self::CODE, $flow['code'] );
		$this->assertArrayNotHasKey( 'state', $flow, 'The state is spent.' );
		$this->assertNull( Connect::challenge_for_state( $state ) );
	}

	public function test_state_is_single_use(): void {
		$state = $this->started_state();
		$query = array(
			'code'  => self::CODE,
			'state' => $state,
		);
		$this->connect->handle_return( $this->user_id, $query );

		$this->connect->handle_return( $this->user_id, $query );

		$this->assertSame( Connect::ERROR_EXPIRED, Connect::result( $this->user_id )['error'] );
	}

	public function test_wrong_state_is_refused_and_the_real_flow_survives(): void {
		$state = $this->started_state();

		$clean = $this->connect->handle_return(
			$this->user_id,
			array(
				'code'  => self::CODE,
				'state' => str_repeat( 'x', 43 ),
			)
		);

		$this->assertSame( admin_url( 'options-general.php?page=showfm' ), $clean );
		$this->assertSame( Connect::ERROR_STATE_MISMATCH, Connect::result( $this->user_id )['error'] );
		$this->assertSame( $state, get_transient( Connect::FLOW_PREFIX . $this->user_id )['state'] );
		$this->assertFalse( $this->connect->complete_pending( $this->user_id ) );
		$this->assertSame( 0, $this->http->count() );
	}

	/**
	 * @dataProvider stray_returns
	 *
	 * @param bool $flow_exists Whether a flow is in progress.
	 */
	public function test_a_stray_return_keeps_the_notice_waiting( bool $flow_exists ): void {
		$this->assertTrue( $this->connection->save( self::KEY, self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS ) );
		$state = $this->started_state();
		set_transient(
			Connect::RESULT_PREFIX . $this->user_id,
			array(
				'status'      => Connect::STATUS_CONNECTED,
				'error'       => '',
				'retry_after' => 0,
				'reason'      => '',
				'generation'  => Connection::generation(),
			),
			60
		);
		if ( ! $flow_exists ) {
			delete_transient( Connect::FLOW_PREFIX . $this->user_id );
		}

		$this->connect->handle_return(
			$this->user_id,
			array(
				'code'  => self::CODE,
				'state' => str_repeat( 'x', 43 ),
			)
		);

		$this->assertSame( Connect::STATUS_CONNECTED, Connect::result( $this->user_id )['status'] );
		$this->assertSame( '', Connect::result( $this->user_id )['error'] );
		if ( $flow_exists ) {
			$this->assertSame( $state, get_transient( Connect::FLOW_PREFIX . $this->user_id )['state'] );
		}
	}

	public function test_a_stale_outcome_does_not_hide_a_new_failure(): void {
		$this->started_state();
		set_transient(
			Connect::RESULT_PREFIX . $this->user_id,
			array(
				'status'      => Connect::STATUS_CONNECTED,
				'error'       => '',
				'retry_after' => 0,
				'reason'      => '',
				'generation'  => Connection::generation(),
			),
			60
		);
		// Another tab reconnects: the stored "Connected" is now hidden on the screen.
		$this->assertTrue( ( new Connection() )->save( self::KEY, self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS ) );

		$this->connect->handle_return(
			$this->user_id,
			array(
				'code'  => self::CODE,
				'state' => str_repeat( 'x', 43 ),
			)
		);

		$this->assertSame( Connect::ERROR_STATE_MISMATCH, Connect::result( $this->user_id )['error'], 'The new failure is recorded where the admin can see it.' );
		$this->assertSame( Connection::generation(), Connect::result( $this->user_id )['generation'] );
	}

	public function test_a_connected_outcome_without_its_own_save_gets_no_generation(): void {
		$this->assertTrue( ( new Connection() )->save( self::KEY, self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS ) );
		$record = new ReflectionMethod( Connect::class, 'record' );
		$record->setAccessible( true );

		$record->invoke(
			$this->connect,
			$this->user_id,
			array(
				'status'      => Connect::STATUS_CONNECTED,
				'error'       => '',
				'retry_after' => 0,
				'reason'      => '',
			)
		);

		$this->assertNull( Connect::result( $this->user_id )['generation'], 'Never attached to another request\'s credentials.' );
		$this->assertNull( ShowFM\Admin_Status::current_result( Connect::result( $this->user_id ), 'connected' ) );
	}

	public function test_the_counter_path_keeps_generation_and_counter_in_step(): void {
		$this->assert_generations_stay_in_step();
	}

	public function test_an_untrusted_last_insert_id_falls_back_to_the_counter_read_back(): void {
		$lie = static function ( string $query ): string {
			return 'SELECT LAST_INSERT_ID()' === $query ? 'SELECT 999999' : $query;
		};
		add_filter( 'query', $lie );
		try {
			$this->assert_generations_stay_in_step();
		} finally {
			remove_filter( 'query', $lie );
		}
	}

	public function test_the_options_api_fallback_keeps_generation_and_counter_in_step(): void {
		global $wpdb;
		$no_atomic_update = static function ( string $query ) use ( $wpdb ): string {
			// A database without LAST_INSERT_ID(expr): the atomic UPDATE changes nothing.
			return false !== strpos( $query, 'LAST_INSERT_ID(CAST' ) ? "UPDATE {$wpdb->options} SET option_value = option_value WHERE 1 = 0" : $query;
		};
		add_filter( 'query', $no_atomic_update );
		try {
			$this->assert_generations_stay_in_step();
		} finally {
			remove_filter( 'query', $no_atomic_update );
		}
	}

	/**
	 * Connects, disconnects and reconnects, checking after each step that the counter only
	 * rises, the stored credentials carry the value their save took, and the disconnected
	 * generation is the counter negated.
	 */
	private function assert_generations_stay_in_step(): void {
		$counter = static function (): int {
			wp_cache_delete( Connection::GENERATION_OPTION, 'options' );
			return (int) get_option( Connection::GENERATION_OPTION, 0 );
		};
		$last    = $counter();
		$this->assertGreaterThanOrEqual( 0, $last );
		$seen = array();
		foreach ( array( 'save', 'disconnect', 'save', 'save', 'disconnect', 'disconnect', 'save' ) as $step ) {
			$connection = new Connection();
			if ( 'save' === $step ) {
				$this->assertTrue( $connection->save( self::KEY, self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS ) );
				$this->assertSame( $counter(), $connection->saved_generation(), 'The save took the counter\'s new value.' );
				$this->assertSame( $connection->saved_generation(), Connection::generation(), 'And stored it with the credentials.' );
			} else {
				$connection->disconnect();
				$this->assertSame( -$counter(), Connection::generation(), 'Disconnected: the counter negated.' );
			}
			$this->assertSame( $last + 1, $counter(), 'One step up, never back, never reset.' );
			$last   = $counter();
			$seen[] = Connection::generation();
		}
		$this->assertSame( $seen, array_unique( $seen ), 'No generation repeats.' );
	}

	/**
	 * @return array<string,array{0:bool}>
	 */
	public function stray_returns(): array {
		return array(
			'wrong state' => array( true ),
			'no flow'     => array( false ),
		);
	}

	public function test_another_users_state_is_refused(): void {
		$state = $this->started_state();
		$other = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->connect->handle_return(
			$other,
			array(
				'code'  => self::CODE,
				'state' => $state,
			)
		);

		$this->assertSame( Connect::ERROR_EXPIRED, Connect::result( $other )['error'] );
		$this->assertFalse( $this->connect->complete_pending( $other ) );
	}

	public function test_expired_flow_is_refused(): void {
		$state = $this->started_state();
		delete_transient( Connect::FLOW_PREFIX . $this->user_id );

		$this->connect->handle_return(
			$this->user_id,
			array(
				'code'  => self::CODE,
				'state' => $state,
			)
		);

		$this->assertSame( Connect::ERROR_EXPIRED, Connect::result( $this->user_id )['error'] );
	}

	public function test_malformed_code_is_refused_and_spends_the_state(): void {
		$state = $this->started_state();

		$this->connect->handle_return(
			$this->user_id,
			array(
				'code'  => 'short',
				'state' => $state,
			)
		);

		$this->assertSame( Connect::ERROR_EXCHANGE_REFUSED, Connect::result( $this->user_id )['error'] );
		$this->assertFalse( get_transient( Connect::FLOW_PREFIX . $this->user_id ) );
	}

	public function test_settings_load_redirects_to_the_clean_url(): void {
		$admin         = new Admin( $this->connect, $this->connection );
		$state         = $this->started_state();
		$_GET['code']  = self::CODE;
		$_GET['state'] = $state;

		$location = $this->capture_redirect( array( $admin, 'load' ) );

		unset( $_GET['code'], $_GET['state'] );
		$this->assertSame( admin_url( 'options-general.php?page=showfm' ), $location );
		$this->assertSame( 0, $this->http->count() );
	}


	public function test_a_success_shows_right_after_its_own_save_and_not_after_a_same_second_reconnect(): void {
		$this->returned();
		$this->http->respond( 200, $this->exchange_body() );
		$this->http->respond( 200, '{"data":{"status":"active","activated":true}}' );
		$this->connect->complete_pending( $this->user_id );

		$result = Connect::result( $this->user_id );
		$this->assertSame( Connection::generation(), $result['generation'], 'It belongs to the connection it saved.' );
		$this->assertNotNull( ShowFM\Admin_Status::current_result( $result, 'connected' ), 'Shown straight after its own save.' );

		// WP-CLI or another tab stores a new connection in the same second.
		$this->assertTrue( ( new Connection() )->save( 'showfm_live_OTHERKEYabcdefghijklmnopq', self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS ) );

		$this->assertNull( ShowFM\Admin_Status::current_result( Connect::result( $this->user_id ), 'connected' ) );
	}

	public function test_a_success_records_the_generation_it_saved_even_if_another_save_follows(): void {
		$this->returned();
		$this->http->respond( 200, $this->exchange_body() );
		$this->http->respond_with(
			function () {
				// Another tab reconnects while this one is still reporting in.
				( new Connection() )->save( 'showfm_live_OTHERKEYabcdefghijklmnopq', self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS );
				return array( 200, '{"data":{"status":"active"}}' );
			}
		);
		$this->connect->complete_pending( $this->user_id );

		$this->assertLessThan( Connection::generation(), Connect::result( $this->user_id )['generation'] );
		$this->assertNull( ShowFM\Admin_Status::current_result( Connect::result( $this->user_id ), 'connected' ), 'The newer connection wins.' );
	}

	public function test_every_connect_reconnect_and_disconnect_gets_a_new_generation(): void {
		$seen  = array( Connection::generation() );
		$saves = array();
		foreach ( array( 'save', 'save', 'disconnect', 'save', 'disconnect' ) as $step ) {
			$connection = new Connection();
			if ( 'save' === $step ) {
				$this->assertTrue( $connection->save( self::KEY, self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS ) );
				$this->assertSame( Connection::generation(), $connection->saved_generation(), 'Read back from the credentials option.' );
				$this->assertSame( $connection->saved_generation(), get_option( Connection::OPTION )['g'], 'Stored in the same option value as the credentials.' );
				$this->assertGreaterThan( 0, Connection::generation() );
				$saves[] = Connection::generation();
			} else {
				$connection->disconnect();
				$this->assertLessThan( 0, Connection::generation(), 'Nothing stored: never equal to a stored connection.' );
			}
			$seen[] = Connection::generation();
		}

		$this->assertSame( $seen, array_unique( $seen ), 'All within one second, and never repeated.' );
		$sorted = $saves;
		sort( $sorted );
		$this->assertSame( $sorted, $saves, 'Each save gets a higher generation.' );
	}

	public function test_interleaved_reconnects_keep_each_credential_with_its_own_generation(): void {
		$this->assertTrue( ( new Connection() )->save( self::KEY, self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS ), 'An existing connection: both are reconnects.' );
		$a       = new Connection();
		$b       = new Connection();
		$nested  = false;
		$key_a   = 'showfm_live_AAAAKEYabcdefghijklmnopqr';
		$key_b   = 'showfm_live_BBBBKEYabcdefghijklmnopqr';
		$overlap = static function ( $value ) use ( $b, $key_b, &$nested ) {
			if ( ! $nested ) {
				// B takes the next generation and writes while A is between taking its own
				// generation and writing: A's write then lands last.
				$nested = true;
				$b->save( $key_b, self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS );
			}
			return $value;
		};
		add_filter( 'pre_update_option_' . Connection::OPTION, $overlap );

		$this->assertTrue( $a->save( $key_a, self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS ) );
		remove_filter( 'pre_update_option_' . Connection::OPTION, $overlap );

		$this->assertGreaterThan( $a->saved_generation(), $b->saved_generation(), 'B took its generation after A.' );
		$stored = new Connection();
		$this->assertSame( $key_a, $stored->key(), 'A wrote last, so A\'s credentials are stored.' );
		$this->assertSame( $a->saved_generation(), Connection::generation(), 'With A\'s own generation, from the same write.' );
		$this->assertNotSame( $b->saved_generation(), Connection::generation(), 'B\'s outcome can never match A\'s credentials.' );
	}

	public function test_interleaved_reconnect_hides_the_overtaken_outcome(): void {
		// Admin A's connect is reporting in when another tab's reconnect writes.
		$this->returned();
		$this->http->respond( 200, $this->exchange_body() );
		$this->http->respond_with(
			function () {
				( new Connection() )->save( 'showfm_live_BBBBKEYabcdefghijklmnopqr', self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS );
				return array( 200, '{"data":{"status":"active"}}' );
			}
		);

		$this->connect->complete_pending( $this->user_id );

		$this->assertSame( 'showfm_live_BBBBKEYabcdefghijklmnopqr', ( new Connection() )->key() );
		$this->assertNotSame( Connection::generation(), Connect::result( $this->user_id )['generation'] );
		$this->assertNull( ShowFM\Admin_Status::current_result( Connect::result( $this->user_id ), 'connected' ), 'A\'s "Connected" does not describe B\'s credentials.' );
	}

	public function test_exchange_stores_the_credentials_and_verifies(): void {
		$this->returned();
		$verifier = get_transient( Connect::FLOW_PREFIX . $this->user_id )['verifier'];
		$this->http->respond( 200, $this->exchange_body() );
		$this->http->respond( 200, '{"data":{"status":"active","activated":true}}' );
		$this->http->respond( 200, '{"data":{"user":{"id":"u1","name":"Maya Lindgren"},"key":{"id":"k1"}}}' );
		$this->http->respond( 200, '{"data":[{"id":"7C9E6679-7425-40DE-944B-E07FC1F90AE7","slug":"the-long-table","title":"The <b>Long</b> Table"}],"pagination":{"next_cursor":null}}' );

		$this->assertTrue( $this->connect->complete_pending( $this->user_id ) );

		$this->assertSame( 4, $this->http->count() );
		$this->assertSame( 'https://api.show.fm/v1/me', $this->http->requests[2]['url'] );
		$this->assertSame( 'https://api.show.fm/v1/me/podcasts?limit=50', $this->http->requests[3]['url'] );
		$this->assertSame(
			array(
				'name'  => 'Maya Lindgren',
				'shows' => array(
					array(
						'id'    => '7c9e6679-7425-40de-944b-e07fc1f90ae7',
						'title' => 'The Long Table',
						'slug'  => 'the-long-table',
					),
				),
			),
			( new ShowFM\Account( $this->connection, new Api_Client( $this->connection ) ) )->details()
		);
		$exchange = $this->http->requests[0];
		$this->assertSame( 'https://api.show.fm/v1/sites/exchange', $exchange['url'], 'Nothing secret in the URL.' );
		$this->assertSame( 'POST', $exchange['args']['method'] );
		$this->assertArrayNotHasKey( 'Authorization', $exchange['args']['headers'] );
		$this->assertSame(
			array(
				'code'          => self::CODE,
				'code_verifier' => $verifier,
			),
			json_decode( $exchange['args']['body'], true )
		);

		$verify = $this->http->requests[1];
		$this->assertSame( 'https://api.show.fm/v1/me/sites/' . self::SITE_ID . '/verify', $verify['url'] );
		$this->assertSame( 'Bearer ' . self::KEY, $verify['args']['headers']['Authorization'] );
		$body = json_decode( $verify['args']['body'], true );
		$this->assertSame( SHOWFM_VERSION, $body['plugin_version'] );
		$this->assertSame( get_bloginfo( 'version' ), $body['wp_version'] );
		$this->assertMatchesRegularExpression( '/^[0-9A-Za-z.+_ -]{1,32}$/', $body['php_version'] );
		$this->assertSame( get_bloginfo( 'name' ), $body['site_name'] );

		$fresh = new Connection();
		$this->assertSame( Connection::STATE_CONNECTED, $fresh->state() );
		$this->assertSame( self::KEY, $fresh->key() );
		$this->assertSame( self::SECRET, $fresh->ping_secret() );
		$this->assertSame( self::SITE_ID, $fresh->site_id() );
		$this->assertSame( strtotime( '2027-10-08T09:00:00.000Z' ), $fresh->expires_at() );

		$stored = maybe_serialize( get_option( Connection::OPTION ) );
		$this->assertStringNotContainsString( self::KEY, $stored, 'Stored encrypted.' );
		$this->assertStringNotContainsString( self::SECRET, $stored );

		$this->assertSame( Connect::STATUS_CONNECTED, Connect::result( $this->user_id )['status'] );
		$this->assertSame( '', Connect::result( $this->user_id )['error'] );
		$this->assertFalse( Connect::verify_pending() );
		$this->assertNotFalse( wp_next_scheduled( Health::HOOK ) );
		$this->assertFalse( get_transient( Connect::FLOW_PREFIX . $this->user_id ), 'The code is used once.' );
		$this->assertFalse( $this->connect->complete_pending( $this->user_id ) );
	}

	/**
	 * @dataProvider exchange_failures
	 *
	 * @param int                  $status   Status, or 0 for a network error.
	 * @param string               $body     Body.
	 * @param array<string,string> $headers  Headers.
	 * @param string               $expected Error type.
	 * @param int                  $retry    Expected retry_after.
	 */
	public function test_exchange_failures_leave_a_typed_error( int $status, string $body, array $headers, string $expected, int $retry ): void {
		$this->returned();
		if ( 0 === $status ) {
			$this->http->fail( 'cURL error 28: timed out with code ' . self::CODE );
		} else {
			$this->http->respond( $status, $body, $headers );
		}

		$this->assertTrue( $this->connect->complete_pending( $this->user_id ) );

		$result = Connect::result( $this->user_id );
		$this->assertSame( Connect::STATUS_FAILED, $result['status'] );
		$this->assertSame( $expected, $result['error'] );
		$this->assertSame( $retry, $result['retry_after'] );
		$this->assertSame( Connection::STATE_DISCONNECTED, $this->connection->state() );
		$this->assertSame( 1, $this->http->count(), 'No verify after a failed exchange.' );
		$this->assertNotSame( '', Connect::message( $result['error'], $result['retry_after'] ) );
		$this->assertStringNotContainsString( self::CODE, (string) wp_json_encode( $result ) );
		$this->assertFalse( wp_next_scheduled( Health::HOOK ) );
	}

	/**
	 * @return array<string,array{0:int,1:string,2:array<string,string>,3:string,4:int}>
	 */
	public function exchange_failures(): array {
		$refused = '{"error":{"code":"invalid_request","message":"The code is invalid, already used or expired."}}';
		return array(
			'code refused'      => array( 400, $refused, array(), Connect::ERROR_EXCHANGE_REFUSED, 0 ),
			'not found'         => array( 404, '', array(), Connect::ERROR_EXCHANGE_REFUSED, 0 ),
			'rate limited'      => array( 429, '', array( 'Retry-After' => '120' ), Connect::ERROR_RATE_LIMITED, 120 ),
			'server error'      => array( 503, '', array( 'Retry-After' => '30' ), Connect::ERROR_UNREACHABLE, 0 ),
			'network error'     => array( 0, '', array(), Connect::ERROR_UNREACHABLE, 0 ),
			'not json'          => array( 200, '<html>', array(), Connect::ERROR_BAD_RESPONSE, 0 ),
			'no data'           => array( 200, '{"site_id":"x"}', array(), Connect::ERROR_BAD_RESPONSE, 0 ),
			'missing key'       => array( 200, '{"data":{"site_id":"' . self::SITE_ID . '","ping_secret":"' . self::SECRET . '","expires_at":"2027-10-08T09:00:00Z"}}', array(), Connect::ERROR_BAD_RESPONSE, 0 ),
			'bad site id'       => array( 200, '{"data":{"site_id":"../../x","api_key":"' . self::KEY . '","ping_secret":"' . self::SECRET . '","expires_at":"2027-10-08T09:00:00Z"}}', array(), Connect::ERROR_BAD_RESPONSE, 0 ),
			'bad expiry'        => array( 200, '{"data":{"site_id":"' . self::SITE_ID . '","api_key":"' . self::KEY . '","ping_secret":"' . self::SECRET . '","expires_at":"soon"}}', array(), Connect::ERROR_BAD_RESPONSE, 0 ),
			'short ping secret' => array( 200, '{"data":{"site_id":"' . self::SITE_ID . '","api_key":"' . self::KEY . '","ping_secret":"abc","expires_at":"2027-10-08T09:00:00Z"}}', array(), Connect::ERROR_BAD_RESPONSE, 0 ),
		);
	}

	public function test_storage_failure_is_reported(): void {
		$this->returned();
		$this->http->respond( 200, $this->exchange_body() );
		add_filter(
			'query',
			static function ( $query ) {
				return 0 === strpos( $query, 'INSERT' ) && false !== strpos( $query, "'" . Connection::OPTION . "'" ) ? '' : $query;
			}
		);

		$this->connect->complete_pending( $this->user_id );

		$this->assertSame( Connect::ERROR_STORAGE, Connect::result( $this->user_id )['error'] );
		$this->assertSame( 1, $this->http->count() );
	}

	public function test_failed_verify_keeps_the_connection_and_retries_later(): void {
		$this->returned();
		$this->http->respond( 200, $this->exchange_body() );
		$this->http->respond( 503 );

		$this->connect->complete_pending( $this->user_id );

		$result = Connect::result( $this->user_id );
		$this->assertSame( Connect::STATUS_CONNECTED, $result['status'] );
		$this->assertSame( Connect::ERROR_VERIFY, $result['error'] );
		$this->assertTrue( $this->connection->is_connected() );
		$this->assertTrue( Connect::verify_pending() );
		$this->assertNotFalse( wp_next_scheduled( Health::HOOK ), 'The health check retries the verify.' );
	}

	public function test_verify_401_marks_reconnect_needed(): void {
		$this->returned();
		$this->http->respond( 200, $this->exchange_body() );
		$this->http->respond( 401, '{"error":{"code":"unauthorised","message":"No."}}' );

		$this->connect->complete_pending( $this->user_id );

		$this->assertSame( Connection::STATE_RECONNECT_NEEDED, $this->connection->state() );
		$this->assertSame( Connect::ERROR_VERIFY, Connect::result( $this->user_id )['error'] );
	}


	public function test_reconnect_keeps_the_old_credentials_until_the_exchange_succeeds(): void {
		$this->connection->save( 'showfm_live_OLDKEY000000000000000000001', str_repeat( 'a', 64 ), self::SITE_ID, 0 );

		$this->returned();
		$this->assertSame( 'showfm_live_OLDKEY000000000000000000001', $this->connection->key(), 'Starting a reconnect changes nothing.' );

		$this->http->respond( 400 );
		$this->connect->complete_pending( $this->user_id );
		$this->assertSame( 'showfm_live_OLDKEY000000000000000000001', $this->connection->key(), 'A failed exchange keeps the old key.' );

		$this->returned();
		$this->http->respond( 200, $this->exchange_body() );
		$this->http->respond( 200, '{"data":{}}' );
		$this->connect->complete_pending( $this->user_id );
		$this->assertSame( self::KEY, $this->connection->key() );
		$this->assertSame( self::SECRET, $this->connection->ping_secret() );
	}

	public function test_reconnect_keeps_the_old_credentials_when_storage_fails(): void {
		$this->connection->save( 'showfm_live_OLDKEY000000000000000000001', str_repeat( 'a', 64 ), self::SITE_ID, 0 );
		$this->returned();
		$this->http->respond( 200, $this->exchange_body() );
		$block = static function ( $query ) {
			return preg_match( '/^\s*(INSERT|UPDATE)\b/i', $query ) && false !== strpos( $query, "'" . Connection::OPTION . "'" ) ? '' : $query;
		};
		add_filter( 'query', $block );

		$this->connect->complete_pending( $this->user_id );

		remove_filter( 'query', $block );
		wp_cache_flush();
		$this->assertSame( Connect::ERROR_STORAGE, Connect::result( $this->user_id )['error'] );
		$this->assertSame( Connection::STATE_CONNECTED, $this->connection->state() );
		$this->assertSame( 'showfm_live_OLDKEY000000000000000000001', $this->connection->key(), 'The working key survives.' );
		$this->assertSame( str_repeat( 'a', 64 ), $this->connection->ping_secret() );
		$this->assertSame( 1, $this->http->count(), 'No verify with credentials that were not stored.' );
	}

	public function test_reconnect_after_a_401_clears_reconnect_needed(): void {
		$this->connection->save( 'showfm_live_OLDKEY000000000000000000001', str_repeat( 'a', 64 ), self::SITE_ID, 0 );
		$this->connection->mark_reconnect_needed();

		$this->returned();
		$this->http->respond( 200, $this->exchange_body() );
		$this->http->respond( 200, '{"data":{}}' );
		$this->connect->complete_pending( $this->user_id );

		$this->assertSame( Connection::STATE_CONNECTED, $this->connection->state() );
		$this->assertSame( 'Bearer ' . self::KEY, $this->http->last()['args']['headers']['Authorization'] );
	}


	public function test_each_site_of_a_network_connects_separately(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
		$blog_id = self::factory()->blog->create();

		$this->returned();
		$this->http->respond( 200, $this->exchange_body() );
		$this->http->respond( 200, '{"data":{}}' );
		$this->connect->complete_pending( $this->user_id );
		$this->assertTrue( $this->connection->is_connected() );

		switch_to_blog( $blog_id );
		try {
			$this->assertSame( Connection::STATE_DISCONNECTED, $this->connection->state(), 'Site 2 is not connected by site 1.' );
			$this->assertFalse( $this->connect->complete_pending( $this->user_id ), 'Site 1\'s flow does not exist on site 2.' );

			$other_site = '1b5d6f0e-1c2d-4e3f-8a9b-0c1d2e3f4a5b';
			$this->returned();
			$this->http->respond( 200, $this->exchange_body( $other_site, 'showfm_live_SITE2KEYabcdefghijklmnopqrs' ) );
			$this->http->respond( 200, '{"data":{}}' );
			$this->connect->complete_pending( $this->user_id );

			$this->assertSame( $other_site, $this->connection->site_id() );
			$this->assertSame( 'showfm_live_SITE2KEYabcdefghijklmnopqrs', $this->connection->key() );
			$this->assertStringContainsString( '/v1/me/sites/' . $other_site . '/verify', $this->http->requests[ $this->http->count() - 2 ]['url'], 'Verify, then the account refresh (blocked here).' );
			$this->connection->disconnect();
		} finally {
			restore_current_blog();
		}

		$this->assertSame( self::SITE_ID, $this->connection->site_id(), 'Site 1 keeps its own connection.' );
		$this->assertSame( self::KEY, $this->connection->key() );
	}

	public function test_start_on_a_network_site_uses_that_sites_addresses(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
		$blog_id = self::factory()->blog->create( array( 'path' => '/second/' ) );

		switch_to_blog( $blog_id );
		try {
			parse_str( (string) wp_parse_url( $this->connect->start( $this->user_id ), PHP_URL_QUERY ), $query );
			$this->assertSame( home_url(), $query['site_url'] );
			$this->assertStringEndsWith( '/second', $query['site_url'], 'A subdirectory site sends its path; show.fm accepts it once #741 is merged.' );
			$this->assertStringStartsWith( admin_url( 'options-general.php?page=showfm&showfm_return=' ), $query['return'] );
			$this->assertStringContainsString( '/second/wp-admin/', $query['return'] );
		} finally {
			restore_current_blog();
		}
		$this->assertFalse( get_transient( Connect::FLOW_PREFIX . $this->user_id ), 'The flow is stored on site 2 only.' );
	}


	/**
	 * The return token of the flow in progress.
	 */
	private function return_token(): string {
		return get_transient( Connect::FLOW_PREFIX . $this->user_id )['return'];
	}

	/**
	 * Starts a flow and returns its state.
	 */
	private function started_state(): string {
		parse_str( (string) wp_parse_url( $this->connect->start( $this->user_id ), PHP_URL_QUERY ), $query );
		return $query['state'];
	}

	/**
	 * Starts a flow and returns to the settings page with a code, ready to exchange.
	 */
	private function returned(): void {
		$this->connect->handle_return(
			$this->user_id,
			array(
				'code'  => self::CODE,
				'state' => $this->started_state(),
			)
		);
	}

	/**
	 * A successful exchange response.
	 *
	 * @param string $site_id Site id.
	 * @param string $key     Key.
	 */
	private function exchange_body( string $site_id = self::SITE_ID, string $key = self::KEY ): string {
		return (string) wp_json_encode(
			array(
				'data' => array(
					'site_id'     => $site_id,
					'api_key'     => $key,
					'ping_secret' => self::SECRET,
					'expires_at'  => '2027-10-08T09:00:00.000Z',
				),
			)
		);
	}

	/**
	 * Runs a handler that redirects and returns the location.
	 *
	 * @param callable $handler Handler.
	 */
	/**
	 * The home address over https.
	 *
	 * @param string $url Home URL.
	 */
	public function https_home( string $url ): string {
		return set_url_scheme( $url, 'https' );
	}

	/**
	 * The home address over http.
	 *
	 * @param string $url Home URL.
	 */
	public function http_home( string $url ): string {
		return set_url_scheme( $url, 'http' );
	}

	private function capture_redirect( callable $handler ): string {
		$throw = static function ( $location ) {
			throw new ShowFM_Test_Redirect( $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test exception, never output.
		};
		add_filter( 'wp_redirect', $throw );
		try {
			$handler();
		} catch ( ShowFM_Test_Redirect $redirect ) {
			return $redirect->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $throw );
		}
		$this->fail( 'The handler did not redirect.' );
		return '';
	}
}
