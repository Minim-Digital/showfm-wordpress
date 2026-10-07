<?php
/**
 * The browser connect flow: start, return, exchange and verify.
 *
 * @package ShowFM
 */

use ShowFM\Admin;
use ShowFM\Api_Client;
use ShowFM\Api_Result;
use ShowFM\Connect;
use ShowFM\Connection;
use ShowFM\Health;
use ShowFM\Plugin;

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
				'state_id'    => Connection::state_id(),
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
				'state_id'    => Connection::state_id(),
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
		$this->assertSame( Connection::state_id(), Connect::result( $this->user_id )['state_id'] );
	}

	public function test_a_connected_outcome_without_its_own_save_matches_no_connection(): void {
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
			),
			$this->connection->pinned()->snapshot()
		);

		$this->assertNull( Connect::result( $this->user_id )['state_id'], 'Never attached to another request\'s credentials.' );
		$this->assertNull( ShowFM\Admin_Status::current_result( Connect::result( $this->user_id ), 'connected', Connection::state_id() ) );
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
		$this->assertSame( Connection::state_id(), $result['state_id'], 'It belongs to the connection it saved.' );
		$this->assertNotNull( ShowFM\Admin_Status::current_result( $result, 'connected', Connection::state_id() ), 'Shown straight after its own save.' );

		// WP-CLI or another tab stores a new connection in the same second.
		$this->assertTrue( ( new Connection() )->save( 'showfm_live_OTHERKEYabcdefghijklmnopq', self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS ) );

		$this->assertNull( ShowFM\Admin_Status::current_result( Connect::result( $this->user_id ), 'connected', Connection::state_id() ) );
	}

	public function test_a_failed_reconnect_stays_with_the_state_it_started_from_when_another_tab_saves(): void {
		$this->assertTrue( $this->connection->save( self::KEY, self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS ) );
		$started = Connection::state_id();
		$this->returned();
		$this->http->respond_with(
			function () {
				// Another tab reconnects while this exchange is in flight.
				( new Connection() )->save( 'showfm_live_OTHERKEYabcdefghijklmnopq', self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS );
				return array( 400, '{"error":{"code":"invalid_request","message":"The code is invalid."}}' );
			}
		);

		$this->connect->complete_pending( $this->user_id );

		$this->assertSame( $started, Connect::result( $this->user_id )['state_id'], 'Bound to the state the flow started from.' );
		$this->assertNotSame( $started, Connection::state_id() );
		$this->assertNull( ShowFM\Admin_Status::current_result( Connect::result( $this->user_id ), 'connected', Connection::state_id() ), 'Never shown against the new connection.' );
	}

	public function test_a_failed_reconnect_stays_with_its_state_when_another_admin_disconnects(): void {
		$this->assertTrue( $this->connection->save( self::KEY, self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS ) );
		$started = Connection::state_id();
		$this->returned();
		$this->http->respond_with(
			static function () {
				( new Connection() )->disconnect();
				return array( 400, '{"error":{"code":"invalid_request","message":"The code is invalid."}}' );
			}
		);

		$this->connect->complete_pending( $this->user_id );

		$this->assertSame( $started, Connect::result( $this->user_id )['state_id'] );
		$this->assertSame( Connection::STATE_DISCONNECTED, ( new Connection() )->state(), 'The disconnect stands.' );
		$this->assertNull( ShowFM\Admin_Status::current_result( Connect::result( $this->user_id ), 'not_connected', Connection::state_id() ) );
	}

	public function test_a_state_changed_between_start_and_return_binds_the_cancel_to_the_start(): void {
		$this->connection->disconnect();
		$this->started_state();
		$token = get_transient( Connect::FLOW_PREFIX . $this->user_id )['return'];
		$this->assertFalse( get_transient( Connect::FLOW_PREFIX . $this->user_id )['snapshot']['credentials'] );

		// WP-CLI connects while the admin is on show.fm.
		$this->assertTrue( ( new Connection() )->save( self::KEY, self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS ) );
		$this->connect->handle_return( $this->user_id, array( Connect::RETURN_ARG => $token ) );

		$this->assertSame( self::KEY, ( new Connection() )->key(), 'The new credentials are untouched.' );
		$this->assertNull( Connect::result( $this->user_id )['state_id'], 'The cancel belongs to the state that has gone, so it matches nothing.' );
		$this->assertNull( ShowFM\Admin_Status::current_result( Connect::result( $this->user_id ), 'connected', Connection::state_id() ) );
	}

	public function test_the_flow_carries_the_state_it_started_from_without_the_credentials(): void {
		$this->assertTrue( $this->connection->save( self::KEY, self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS ) );

		$this->started_state();

		$flow = get_transient( Connect::FLOW_PREFIX . $this->user_id );
		$this->assertSame(
			array(
				'credentials' => true,
				'id'          => Connection::state_id(),
			),
			$flow['snapshot']
		);
		$this->assertStringNotContainsString( get_option( Connection::OPTION )['c'], maybe_serialize( $flow ), 'Only the id and a flag, never the ciphertext.' );
	}

	/**
	 * @dataProvider one_read_flows
	 *
	 * @param string $flow Flow to run.
	 */
	public function test_each_flow_reads_the_connection_once( string $flow ): void {
		$this->assertTrue( $this->connection->save( self::KEY, self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS ) );
		$state = $this->started_state();
		if ( 'exchange failure' === $flow ) {
			$this->connect->handle_return(
				$this->user_id,
				array(
					'code'  => self::CODE,
					'state' => $state,
				)
			);
			$this->http->respond( 400, '{"error":{"code":"invalid_request","message":"No."}}' );
		}
		$reads = 0;
		$count = static function ( $value ) use ( &$reads ) {
			// The fresh read a change makes inside the connection lock is its own; this
			// counts the flow's reads.
			if ( ! Connection::in_mutation() ) {
				++$reads;
			}
			return $value;
		};
		add_filter( 'option_' . Connection::OPTION, $count );
		try {
			switch ( $flow ) {
				case 'state mismatch':
					$this->connect->handle_return(
						$this->user_id,
						array(
							'code'  => self::CODE,
							'state' => str_repeat( 'x', 43 ),
						)
					);
					break;
				case 'cancel':
					$this->connect->handle_return( $this->user_id, array( Connect::RETURN_ARG => get_transient( Connect::FLOW_PREFIX . $this->user_id )['return'] ) );
					break;
				case 'exchange failure':
					$this->connect->complete_pending( $this->user_id );
					break;
				case 'settings view':
					( new ShowFM\Admin_Status( $this->connection ) )->view( $this->user_id );
					break;
			}
		} finally {
			remove_filter( 'option_' . Connection::OPTION, $count );
		}

		if ( 'settings view' !== $flow ) {
			$this->assertNotNull( Connect::result( $this->user_id ), 'The flow recorded its outcome.' );
		}
		$this->assertSame( 'exchange failure' === $flow ? 0 : 1, $reads, 'The exchange uses the snapshot its flow carries; every other request reads once.' );
	}

	/**
	 * @return array<string,array{string}>
	 */
	public function one_read_flows(): array {
		return array(
			'state mismatch'   => array( 'state mismatch' ),
			'cancel'           => array( 'cancel' ),
			'exchange failure' => array( 'exchange failure' ),
			'settings view'    => array( 'settings view' ),
		);
	}

	public function test_a_pinned_read_of_unreadable_credentials_never_flags_a_reconnect_saved_since(): void {
		$this->assertTrue( $this->connection->save( self::KEY, self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS ) );
		$stored      = get_option( Connection::OPTION );
		$stored['c'] = base64_encode( str_repeat( 'x', 80 ) );
		update_option( Connection::OPTION, $stored, false );
		$pinned = $this->connection->pinned();

		// Another request reconnects successfully.
		$this->assertTrue( ( new Connection() )->save( 'showfm_live_FRESHKEYabcdefghijklmnopq', self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS ) );

		$this->assertSame( Connection::STATE_RECONNECT_NEEDED, $pinned->state(), 'The pinned copy still describes the old state.' );
		$this->assertFalse( $pinned->mark_reconnect_needed(), 'The state moved on, so nothing is written.' );
		$this->assertFalse( get_option( Connection::STATE_OPTION ) );
		$this->assertSame( Connection::STATE_CONNECTED, ( new Connection() )->state(), 'The new connection keeps working.' );
		$this->assertSame( 'showfm_live_FRESHKEYabcdefghijklmnopq', ( new Connection() )->key() );
	}

	public function test_a_401_for_an_old_key_never_flags_a_reconnect_that_landed_meanwhile(): void {
		$this->assertTrue( $this->connection->save( self::KEY, self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS ) );
		$this->http->respond_with(
			function () {
				// Another tab reconnects while the old key's request is in flight.
				( new Connection() )->save( 'showfm_live_FRESHKEYabcdefghijklmnopq', self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS );
				return array( 401, '{"error":{"code":"invalid_api_key","message":"Revoked."}}' );
			}
		);

		$result = ( new Api_Client( $this->connection ) )->get_keyed( '/v1/me' );

		$this->assertTrue( $result->is( Api_Result::UNAUTHORISED ) );
		$this->assertNotSame( Connection::state_id(), $result->state_id(), 'The result names the old state.' );
		$this->assertSame( Connection::STATE_CONNECTED, ( new Connection() )->state(), 'The new connection is not flagged.' );
	}

	public function test_a_flag_written_after_a_reconnect_slips_in_still_names_the_old_state(): void {
		$this->assertTrue( $this->connection->save( self::KEY, self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS ) );
		$pinned = $this->connection->pinned();
		$nested = false;
		$race   = function ( $value ) use ( &$nested ) {
			if ( ! $nested ) {
				// A reconnect lands between the live check and the flag write.
				$nested = true;
				$this->assertTrue( ( new Connection() )->save( 'showfm_live_FRESHKEYabcdefghijklmnopq', self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS ) );
			}
			return $value;
		};
		add_filter( 'pre_update_option_' . Connection::STATE_OPTION, $race );
		try {
			$pinned->mark_reconnect_needed();
		} finally {
			remove_filter( 'pre_update_option_' . Connection::STATE_OPTION, $race );
		}

		$this->assertTrue( $nested );
		$this->assertSame( $pinned->snapshot()['id'], get_option( Connection::STATE_OPTION ), 'The flag names the state it was meant for.' );
		$this->assertSame( Connection::STATE_CONNECTED, ( new Connection() )->state(), 'The new connection is not flagged.' );
	}

	public function test_a_plan_pause_reported_for_an_old_state_is_ignored(): void {
		$this->assertTrue( $this->connection->save( self::KEY, self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS ) );
		$old = Connection::state_id();
		$this->assertTrue( ( new Connection() )->save( self::KEY, self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS ) );

		$this->assertFalse( Connection::note_report( ( new Api_Result( Api_Result::UNAVAILABLE, 403, null, null, 0, '', 'plan_upgrade_required' ) )->for_state( $old ) ) );

		$this->assertSame( 0, Connection::paused_at() );
		$this->assertFalse( get_option( Connection::PAUSED_OPTION ) );
	}

	public function test_a_pinned_disconnect_never_undoes_a_reconnect_saved_since(): void {
		$this->assertTrue( $this->connection->save( self::KEY, self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS ) );
		$pinned = $this->connection->pinned();
		$this->assertTrue( ( new Connection() )->save( 'showfm_live_FRESHKEYabcdefghijklmnopq', self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS ) );
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, Health::HOOK );

		$this->assertFalse( $this->connect->disconnect( $pinned ), 'The state moved on.' );

		$this->assertSame( 'showfm_live_FRESHKEYabcdefghijklmnopq', ( new Connection() )->key(), 'The reconnect stands.' );
		$this->assertNotFalse( wp_next_scheduled( Health::HOOK ), 'Nothing else was torn down.' );
	}

	/**
	 * Runs a change while another request holds the connection lock. On the change's first
	 * wait, that request does its own change and lets go, so the first change then decides on
	 * what the other one left.
	 *
	 * @param callable $first  The change under test.
	 * @param callable $second What the other request does while holding the lock.
	 * @return mixed What the first change returns.
	 */
	private function while_another_request_holds_the_lock( callable $first, callable $second ) {
		$held = new ShowFM\Sync_Lock( Connection::LOCK_OPTION, Connection::LOCK_TTL );
		$this->assertTrue( $held->acquire(), 'The other request holds the lock.' );
		$ran  = false;
		$hook = static function () use ( $held, $second, &$ran ): void {
			if ( ! $ran ) {
				$ran = true;
				$held->release();
				$second();
			}
		};
		add_action( 'showfm_connection_lock_waiting', $hook );
		try {
			return $first();
		} finally {
			remove_action( 'showfm_connection_lock_waiting', $hook );
			$held->release();
			$this->assertTrue( $ran, 'The change waited for the lock.' );
		}
	}

	public function test_a_stale_refusal_never_overwrites_a_newer_states_flag(): void {
		$this->assertTrue( $this->connection->save( self::KEY, self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS ) );
		$old = $this->connection->pinned();

		$marked = $this->while_another_request_holds_the_lock(
			static function () use ( $old ): bool {
				return $old->mark_reconnect_needed();
			},
			function (): void {
				// Meanwhile: a reconnect stores state B, and a 401 for B flags B.
				$this->assertTrue( ( new Connection() )->save( 'showfm_live_BBBBKEYabcdefghijklmnopqr', self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS ) );
				$this->assertTrue( ( new Connection() )->pinned()->mark_reconnect_needed() );
			}
		);

		$this->assertFalse( $marked, 'The old refusal is for a state that has gone.' );
		$this->assertSame( Connection::state_id(), get_option( Connection::STATE_OPTION ), 'B keeps its own flag.' );
		$this->assertSame( Connection::STATE_RECONNECT_NEEDED, ( new Connection() )->state(), 'B stays refused, so no request is sent with its refused key.' );
	}

	public function test_a_stale_success_never_clears_a_newer_states_pause(): void {
		$this->assertTrue( $this->connection->save( self::KEY, self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS ) );
		$a = Connection::state_id();

		$noted = $this->while_another_request_holds_the_lock(
			static function () use ( $a ): bool {
				return Connection::note_report( ( new Api_Result( Api_Result::SUCCESS, 200 ) )->for_state( $a ) );
			},
			function (): void {
				// Meanwhile: state B is saved and its report records a plan pause.
				$this->assertTrue( ( new Connection() )->save( self::KEY, self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS ) );
				$this->assertTrue( Connection::note_report( ( new Api_Result( Api_Result::UNAVAILABLE, 403, null, null, 0, '', 'plan_upgrade_required' ) )->for_state( Connection::state_id() ) ) );
			}
		);

		$this->assertFalse( $noted, 'A\'s success is for a state that has gone.' );
		$this->assertGreaterThan( 0, Connection::paused_at(), 'B stays paused.' );
		$this->assertSame( Connection::state_id(), get_option( Connection::PAUSED_OPTION )['id'] );
	}

	public function test_a_reconnect_cannot_land_inside_a_disconnects_teardown(): void {
		$this->assertTrue( $this->connection->save( self::KEY, self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS ) );
		$pinned  = $this->connection->pinned();
		$blocked = null;
		$inside  = function ( string $option ) use ( &$blocked ): void {
			if ( null !== $blocked || Connect::VERIFY_PENDING_OPTION !== $option ) {
				return;
			}
			// Part way through the teardown, another request tries to reconnect.
			add_filter( 'showfm_connection_lock_reentrant', '__return_false' );
			add_filter( 'showfm_connection_lock_wait_ms', array( $this, 'short_wait' ) );
			try {
				( new Connection() )->save( 'showfm_live_BBBBKEYabcdefghijklmnopqr', self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS );
				$blocked = false;
			} catch ( ShowFM\Connection_Busy $busy ) {
				$blocked = true;
			} finally {
				remove_filter( 'showfm_connection_lock_reentrant', '__return_false' );
				remove_filter( 'showfm_connection_lock_wait_ms', array( $this, 'short_wait' ) );
			}
		};
		update_option( Connect::VERIFY_PENDING_OPTION, 1, false );
		add_action( 'delete_option', $inside );
		try {
			$this->assertTrue( $this->connect->disconnect( $pinned ) );
		} finally {
			remove_action( 'delete_option', $inside );
		}
		$this->assertTrue( $blocked, 'The reconnect had to wait: it could not interleave with the teardown.' );
		$this->assertSame( Connection::STATE_DISCONNECTED, ( new Connection() )->state() );

		// The reconnect, ordered after the disconnect, then keeps everything that is its own.
		$this->http->respond( 201, $this->exchange_body( self::SITE_ID, 'showfm_live_BBBBKEYabcdefghijklmnopqr' ) );
		$this->http->respond( 500, '' );
		$this->assertSame( Connect::STATUS_CONNECTED, $this->connect->register_with_key( 'showfm_live_BBBBKEYabcdefghijklmnopqr' )['status'] );
		$this->assertNotFalse( wp_next_scheduled( Health::HOOK ), 'Its jobs are scheduled.' );
		$this->assertTrue( Connect::verify_pending(), 'Its verify marker stands.' );
		$this->assertSame( Connection::STATE_CONNECTED, ( new Connection() )->state() );
	}

	public function test_a_disconnect_decides_on_the_state_another_request_left(): void {
		$this->assertTrue( $this->connection->save( self::KEY, self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS ) );
		$pinned = $this->connection->pinned();

		$done = $this->while_another_request_holds_the_lock(
			function () use ( $pinned ): bool {
				return $this->connect->disconnect( $pinned );
			},
			function (): void {
				$this->assertTrue( ( new Connection() )->save( 'showfm_live_BBBBKEYabcdefghijklmnopqr', self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS ) );
				wp_schedule_single_event( time() + HOUR_IN_SECONDS, Health::HOOK );
			}
		);

		$this->assertFalse( $done );
		$this->assertSame( 'showfm_live_BBBBKEYabcdefghijklmnopqr', ( new Connection() )->key(), 'The reconnect stands.' );
		$this->assertNotFalse( wp_next_scheduled( Health::HOOK ), 'Its jobs are untouched.' );
	}

	public function test_a_failure_while_disconnected_decides_on_the_state_another_request_left(): void {
		$this->connection->disconnect();
		$snapshot = $this->connection->pinned()->snapshot();

		$this->while_another_request_holds_the_lock(
			function () use ( $snapshot ): void {
				$this->connect->fail( $this->user_id, Connect::ERROR_CANCELLED, 0, $snapshot );
			},
			function (): void {
				$this->assertTrue( ( new Connection() )->save( self::KEY, self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS ) );
			}
		);

		$this->assertSame( self::KEY, ( new Connection() )->key(), 'The credentials saved meanwhile are kept.' );
		$this->assertNull( Connect::result( $this->user_id )['state_id'], 'The failure matches nothing.' );
	}

	public function test_a_change_gives_up_as_busy_when_the_lock_is_held_too_long(): void {
		$held = new ShowFM\Sync_Lock( Connection::LOCK_OPTION, Connection::LOCK_TTL );
		$this->assertTrue( $held->acquire() );
		add_filter( 'showfm_connection_lock_wait_ms', array( $this, 'short_wait' ) );
		$started = microtime( true );
		try {
			Connection::mutate(
				static function (): void {
					throw new LogicException( 'Must not run without the lock.' );
				}
			);
			$this->fail( 'Expected Connection_Busy.' );
		} catch ( ShowFM\Connection_Busy $busy ) {
			$this->assertLessThan( 2.0, microtime( true ) - $started, 'A short wait, then busy.' );
		} finally {
			remove_filter( 'showfm_connection_lock_wait_ms', array( $this, 'short_wait' ) );
			$held->release();
		}

		// The callers that cannot report busy change nothing and say so.
		$this->assertTrue( $this->connection->save( self::KEY, self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS ) );
		$this->assertTrue( $held->acquire() );
		add_filter( 'showfm_connection_lock_wait_ms', array( $this, 'short_wait' ) );
		try {
			$this->assertFalse( $this->connection->pinned()->mark_reconnect_needed() );
			$this->assertFalse( Connection::note_report( ( new Api_Result( Api_Result::SUCCESS, 200 ) )->for_state( Connection::state_id() ) ) );
		} finally {
			remove_filter( 'showfm_connection_lock_wait_ms', array( $this, 'short_wait' ) );
			$held->release();
		}
		$this->assertSame( Connection::STATE_CONNECTED, ( new Connection() )->state() );
	}

	public function test_a_stale_lock_is_recovered(): void {
		// A holder that died: its lease ran out a minute ago.
		update_option( Connection::LOCK_OPTION, wp_generate_uuid4() . '|' . ( time() - 60 ) . '|options|', false );
		add_filter( 'showfm_connection_lock_wait_ms', array( $this, 'short_wait' ) );
		try {
			$this->assertTrue( $this->connection->save( self::KEY, self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS ) );
		} finally {
			remove_filter( 'showfm_connection_lock_wait_ms', array( $this, 'short_wait' ) );
		}
		$this->assertSame( Connection::STATE_CONNECTED, ( new Connection() )->state() );
		$this->assertFalse( get_option( Connection::LOCK_OPTION ), 'The lock is released afterwards.' );
	}

	public function test_every_connection_state_write_happens_under_the_lock(): void {
		$guarded = array( Connection::OPTION, Connection::STATE_OPTION, Connection::REFUSED_AT_OPTION, Connection::PAUSED_OPTION, Connection::CONNECTED_AT_OPTION, Connect::VERIFY_PENDING_OPTION, ShowFM\Account::OPTION );
		$outside = array();
		$inside  = 0;
		$watch   = static function ( string $option ) use ( $guarded, &$outside, &$inside ): void {
			if ( ! in_array( $option, $guarded, true ) ) {
				return;
			}
			if ( Connection::in_mutation() ) {
				++$inside;
			} else {
				$outside[] = $option;
			}
		};
		$results = static function ( string $transient ) use ( &$outside ): void {
			if ( 0 === strpos( $transient, Connect::RESULT_PREFIX ) && ! Connection::in_mutation() ) {
				$outside[] = $transient;
			}
		};
		foreach ( array( 'add_option', 'update_option', 'delete_option' ) as $action ) {
			add_action( $action, $watch );
		}
		add_action( 'set_transient', $results );
		try {
			// Connect in the browser, report in, fetch the account, get refused, reconnect,
			// pause, unpause, fail, disconnect.
			$this->returned();
			$this->http->respond( 200, $this->exchange_body() );
			$this->http->respond( 200, '{"data":{"status":"active"}}' );
			$this->http->respond( 200, '{"data":{"user":{"name":"Maya"}}}' );
			$this->http->respond( 200, '{"data":[]}' );
			$this->connect->complete_pending( $this->user_id );
			$this->http->respond( 401, '{"error":{"code":"invalid_api_key","message":"No."}}' );
			( new Api_Client( new Connection() ) )->get_keyed( '/v1/me' );
			$this->http->respond( 201, $this->exchange_body() );
			$this->http->respond( 403, '{"error":{"code":"plan_upgrade_required","message":"No."}}' );
			$this->connect->register_with_key( self::KEY );
			$this->http->respond( 200, '{"data":{"recorded":true}}' );
			$this->http->respond( 500, '' );
			( new Health( new Connection(), new Api_Client( new Connection() ), $this->connect ) )->report();
			$this->connect->fail( $this->user_id, Connect::ERROR_EXPIRED );
			$this->connect->disconnect( null, $this->user_id );
			$this->connect->fail( $this->user_id, Connect::ERROR_CANCELLED );
			( new Connection() )->disconnect();
		} finally {
			foreach ( array( 'add_option', 'update_option', 'delete_option' ) as $action ) {
				remove_action( $action, $watch );
			}
			remove_action( 'set_transient', $results );
		}

		$this->assertGreaterThan( 10, $inside, 'The flows did write connection state.' );
		$this->assertSame( array(), $outside, 'No connection state is written outside Connection::mutate().' );
	}

	public function test_only_the_connection_classes_write_the_connection_state(): void {
		$allowed = array( 'class-connection.php', 'class-connect.php', 'class-account.php' );
		$pattern = '/(?:update|add|delete)_option\(\s*(?:self|Connection|Connect|Account)::(?:OPTION|STATE_OPTION|REFUSED_AT_OPTION|PAUSED_OPTION|CONNECTED_AT_OPTION|VERIFY_PENDING_OPTION)\b/';
		foreach ( glob( SHOWFM_DIR . '/includes/*.php' ) as $file ) {
			if ( in_array( basename( $file ), $allowed, true ) || 'class-sync.php' === basename( $file ) || 0 === strpos( basename( $file ), 'class-sync-' ) || 0 === strpos( basename( $file ), 'class-migrat' ) || 'class-cache.php' === basename( $file ) ) {
				continue;
			}
			$this->assertSame( 0, preg_match( $pattern, (string) file_get_contents( $file ) ), basename( $file ) . ' writes connection state outside Connection::mutate().' );
		}
	}

	/**
	 * A wait short enough for tests.
	 */
	public function short_wait(): int {
		return 150;
	}

	/**
	 * Rewrites the connection lock's lease row, as time passing or another request would.
	 *
	 * @param callable $edit Gets the lease parts (token, expiry, mode, session) and returns new ones.
	 */
	private function edit_lease( callable $edit ): void {
		global $wpdb;
		$row   = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Connection::LOCK_OPTION ) );
		$parts = $edit( explode( '|', $row ) );
		$wpdb->update( $wpdb->options, array( 'option_value' => implode( '|', $parts ) ), array( 'option_name' => Connection::LOCK_OPTION ) );
		wp_cache_delete( Connection::LOCK_OPTION, 'options' );
	}

	/**
	 * @dataProvider lost_leases
	 *
	 * @param string $how How the lease is lost.
	 */
	public function test_a_change_that_outlives_its_lease_stops_before_its_next_write( string $how ): void {
		add_filter( 'showfm_sync_use_named_lock', '__return_false' );
		$this->assertTrue( $this->connection->save( self::KEY, self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS ) );
		try {
			Connection::mutate(
				function () use ( $how ): void {
					Connection::write( Connection::REFUSED_AT_OPTION, 111 );
					$this->edit_lease(
						static function ( array $parts ) use ( $how ): array {
							if ( 'expired' === $how ) {
								// The holder was slow: its lease ran out.
								$parts[1] = (string) ( time() - 1 );
							} else {
								// Its lease ran out and another request took the lock over.
								$parts[0] = wp_generate_uuid4();
								$parts[1] = (string) ( time() + Connection::LOCK_TTL );
							}
							return $parts;
						}
					);
					Connection::write( Connection::REFUSED_AT_OPTION, 222 );
				}
			);
			$this->fail( 'Expected Connection_Lost.' );
		} catch ( ShowFM\Connection_Lost $lost ) {
			$this->assertSame( 111, (int) get_option( Connection::REFUSED_AT_OPTION ), 'The write after the lease was lost never happened.' );
		} finally {
			remove_filter( 'showfm_sync_use_named_lock', '__return_false' );
			// The other request's lease, so this test's teardown can take the lock.
			delete_option( Connection::LOCK_OPTION );
		}
	}

	/**
	 * @return array<string,array{string}>
	 */
	public function lost_leases(): array {
		return array(
			'expired'    => array( 'expired' ),
			'taken over' => array( 'taken over' ),
		);
	}

	public function test_the_lease_is_renewed_before_each_write(): void {
		add_filter( 'showfm_sync_use_named_lock', '__return_false' );
		$expiry = static function (): int {
			global $wpdb;
			$row = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Connection::LOCK_OPTION ) );
			return (int) explode( '|', $row )[1];
		};
		try {
			Connection::mutate(
				static function () use ( $expiry ): void {
					$before = $expiry();
					// A slow step: time passes inside the change.
					sleep( 1 );
					Connection::write( Connection::REFUSED_AT_OPTION, 333 );
					$after = $expiry();
					if ( $after <= $before ) {
						throw new LogicException( 'The lease was not renewed before the write.' );
					}
				}
			);
		} finally {
			remove_filter( 'showfm_sync_use_named_lock', '__return_false' );
		}
		$this->assertSame( 333, (int) get_option( Connection::REFUSED_AT_OPTION ) );
	}

	public function test_a_disconnect_that_loses_its_lease_leaves_the_rest_to_whoever_took_over(): void {
		add_filter( 'showfm_sync_use_named_lock', '__return_false' );
		$this->assertTrue( $this->connection->save( self::KEY, self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS ) );
		update_option( Connect::VERIFY_PENDING_OPTION, 1, false );
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, Health::HOOK );
		$pinned   = $this->connection->pinned();
		$takeover = function ( string $option ): void {
			if ( Connection::OPTION === $option ) {
				// Straight after the swap, the lease is lost to another request.
				$this->edit_lease(
					static function ( array $parts ): array {
						$parts[0] = wp_generate_uuid4();
						return $parts;
					}
				);
			}
		};
		add_action( 'updated_option', $takeover );
		try {
			$this->connect->disconnect( $pinned );
			$this->fail( 'Expected Connection_Lost.' );
		} catch ( ShowFM\Connection_Lost $lost ) {
			$this->assertSame( Connection::STATE_DISCONNECTED, ( new Connection() )->state(), 'The swap, made while the lock was held, stands.' );
			$this->assertNotFalse( wp_next_scheduled( Health::HOOK ), 'Nothing after the loss ran: the jobs are untouched.' );
			$this->assertTrue( Connect::verify_pending(), 'The verify marker is untouched.' );
		} finally {
			remove_action( 'updated_option', $takeover );
			remove_filter( 'showfm_sync_use_named_lock', '__return_false' );
			delete_option( Connection::LOCK_OPTION );
		}
	}

	public function test_a_disconnect_that_loses_its_lease_while_unscheduling_leaves_the_other_jobs(): void {
		add_filter( 'showfm_sync_use_named_lock', '__return_false' );
		$this->assertTrue( $this->connection->save( self::KEY, self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS ) );
		foreach ( Plugin::CRON_HOOKS as $hook ) {
			wp_schedule_single_event( time() + HOUR_IN_SECONDS, $hook );
		}
		$pinned   = $this->connection->pinned();
		$cron     = 0;
		$takeover = function ( string $option ) use ( &$cron ): void {
			if ( 'cron' === $option && 1 === ++$cron ) {
				// The first job is gone, then the lease is lost to a reconnect.
				$this->edit_lease(
					static function ( array $parts ): array {
						$parts[0] = wp_generate_uuid4();
						return $parts;
					}
				);
			}
		};
		add_action( 'updated_option', $takeover );
		try {
			$this->connect->disconnect( $pinned );
			$this->fail( 'Expected Connection_Lost.' );
		} catch ( ShowFM\Connection_Lost $lost ) {
			$this->assertFalse( wp_next_scheduled( Plugin::CRON_HOOKS[0] ), 'The first job went while the lock was held.' );
			foreach ( array_slice( Plugin::CRON_HOOKS, 1 ) as $hook ) {
				$this->assertNotFalse( wp_next_scheduled( $hook ), $hook . ' is left for whoever took over.' );
			}
		} finally {
			remove_action( 'updated_option', $takeover );
			remove_filter( 'showfm_sync_use_named_lock', '__return_false' );
			delete_option( Connection::LOCK_OPTION );
		}
	}

	public function test_every_write_inside_a_guarded_step_checks_the_lease(): void {
		add_filter( 'showfm_sync_use_named_lock', '__return_false' );
		try {
			Connection::mutate(
				function (): void {
					Connection::guarded(
						function (): void {
							update_option( 'showfm_test_first', 1 );
							$this->edit_lease(
								static function ( array $parts ): array {
									$parts[1] = (string) ( time() - 1 );
									return $parts;
								}
							);
							set_transient( 'showfm_test_second', 1 );
							delete_option( 'showfm_test_first' );
						}
					);
				}
			);
			$this->fail( 'Expected Connection_Lost.' );
		} catch ( ShowFM\Connection_Lost $lost ) {
			$this->assertSame( 1, (int) get_option( 'showfm_test_first' ), 'The write before the loss stands.' );
			$this->assertFalse( get_transient( 'showfm_test_second' ), 'No write after the loss happened.' );
		} finally {
			remove_filter( 'showfm_sync_use_named_lock', '__return_false' );
			delete_option( 'showfm_test_first' );
			delete_option( Connection::LOCK_OPTION );
		}
	}

	public function test_option_writes_outside_a_change_are_not_checked(): void {
		Connection::mutate( '__return_true' );
		$this->assertTrue( update_option( 'showfm_test_outside', 1 ) );
		delete_option( 'showfm_test_outside' );
	}

	public function test_account_details_fetched_with_another_states_key_are_discarded(): void {
		$this->assertTrue( $this->connection->save( self::KEY, self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS ) );
		$stale = $this->connection->pinned();
		// The connection is replaced after the refresh read its site id, before the requests.
		$this->assertTrue( ( new Connection() )->save( 'showfm_live_BBBBKEYabcdefghijklmnopqr', self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS ) );
		$this->http->respond( 200, '{"data":{"user":{"name":"Someone"}}}' );
		$this->http->respond( 200, '{"data":[]}' );

		$account = new ShowFM\Account( $this->connection, new Api_Client( $this->connection ) );

		$this->assertFalse( $account->refresh_from( $stale ), 'Both answers carry the new state, not the one the site id came from.' );
		$this->assertFalse( get_option( ShowFM\Account::OPTION ), 'Nothing is stored under the old state.' );
	}

	public function test_account_details_are_discarded_when_the_state_changes_between_the_requests(): void {
		$this->assertTrue( $this->connection->save( self::KEY, self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS ) );
		$this->http->respond_with(
			static function () {
				( new Connection() )->save( 'showfm_live_BBBBKEYabcdefghijklmnopqr', self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS );
				return array( 200, '{"data":{"user":{"name":"Someone"}}}' );
			}
		);
		$this->http->respond( 200, '{"data":[]}' );

		$this->assertFalse( ( new ShowFM\Account( $this->connection, new Api_Client( $this->connection ) ) )->refresh() );
		$this->assertFalse( get_option( ShowFM\Account::OPTION ) );
	}

	public function test_account_details_from_one_state_are_stored_with_its_site_id(): void {
		$this->assertTrue( $this->connection->save( self::KEY, self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS ) );
		$this->http->respond( 200, '{"data":{"user":{"name":"Maya"}}}' );
		$this->http->respond( 200, '{"data":[]}' );

		$this->assertTrue( ( new ShowFM\Account( $this->connection, new Api_Client( $this->connection ) ) )->refresh() );
		$this->assertSame( self::SITE_ID, get_option( ShowFM\Account::OPTION )['site'] );
		$this->assertSame( 'Maya', ShowFM\Account::details_for( self::SITE_ID, Connection::state_id() )['name'] );
	}

	public function test_every_state_writes_its_own_random_id(): void {
		delete_option( Connection::OPTION );
		$this->assertSame( '', Connection::state_id(), 'A new install: nothing stored yet.' );
		$ids = array();
		foreach ( array( 'save', 'save', 'disconnect', 'save', 'disconnect', 'disconnect' ) as $step ) {
			$connection = new Connection();
			if ( 'save' === $step ) {
				$this->assertTrue( $connection->save( self::KEY, self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS ) );
				$stored = get_option( Connection::OPTION );
				$this->assertSame( $stored['i'], $connection->saved_state_id(), 'Written in the same option value as the credentials.' );
				$this->assertArrayHasKey( 'c', $stored );
			} else {
				$connection->disconnect();
				$stored = get_option( Connection::OPTION );
				$this->assertSame( array( 'i' ), array_keys( $stored ), 'Disconnected: the credentials go and a fresh id stays, in one write.' );
				$this->assertSame( Connection::STATE_DISCONNECTED, $connection->state() );
			}
			$this->assertTrue( wp_is_uuid( Connection::state_id(), 4 ) );
			$ids[] = Connection::state_id();
		}
		$this->assertSame( $ids, array_unique( $ids ), 'Every state, connected or not, has its own id.' );
		$this->assertFalse( get_option( Connection::LEGACY_GENERATION_OPTION ), 'No counter is written.' );
	}

	public function test_a_first_failure_on_a_new_install_creates_the_state_id(): void {
		delete_option( Connection::OPTION );

		$this->connect->fail( $this->user_id, Connect::ERROR_INSECURE );

		$this->assertSame( array( 'i' ), array_keys( get_option( Connection::OPTION ) ), 'Created on first write.' );
		$this->assertSame( Connection::state_id(), Connect::result( $this->user_id )['state_id'] );
		$this->assertSame( Connection::STATE_DISCONNECTED, $this->connection->state() );
		$this->assertNotNull( ShowFM\Admin_Status::current_result( Connect::result( $this->user_id ), 'not_connected', Connection::state_id() ) );
	}

	public function test_a_failure_then_connect_and_disconnect_never_brings_the_failure_back(): void {
		// Admin A's attempt fails while the site is disconnected.
		$this->connect->fail( $this->user_id, Connect::ERROR_CANCELLED );
		$this->assertNotNull( ShowFM\Admin_Status::current_result( Connect::result( $this->user_id ), 'not_connected', Connection::state_id() ) );

		// WP-CLI connects, then another admin disconnects (neither clears A's result).
		$this->assertTrue( ( new Connection() )->save( self::KEY, self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS ) );
		( new Connection() )->disconnect();

		// A reloads: disconnected again, but a different disconnected state.
		$this->assertSame( Connection::STATE_DISCONNECTED, $this->connection->state() );
		$this->assertNull( ShowFM\Admin_Status::current_result( Connect::result( $this->user_id ), 'not_connected', Connection::state_id() ), 'No stale failure.' );
	}

	public function test_failures_in_two_tabs_while_disconnected_each_start_a_new_state(): void {
		$other = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->connect->fail( $this->user_id, Connect::ERROR_CANCELLED );
		$first = Connect::result( $this->user_id )['state_id'];
		$this->connect->fail( $other, Connect::ERROR_EXPIRED );

		$this->assertNotSame( $first, Connect::result( $other )['state_id'] );
		$this->assertSame( Connection::state_id(), Connect::result( $other )['state_id'], 'The newest attempt is the current state.' );
		$this->assertNull( ShowFM\Admin_Status::current_result( Connect::result( $this->user_id ), 'not_connected', Connection::state_id() ), 'The older one is overtaken.' );
	}

	public function test_a_failed_reconnect_keeps_the_connections_state_id(): void {
		$this->assertTrue( $this->connection->save( self::KEY, self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS ) );
		$id = Connection::state_id();

		$this->connect->fail( $this->user_id, Connect::ERROR_EXPIRED );

		$this->assertSame( $id, Connection::state_id(), 'The credentials and their id are untouched.' );
		$this->assertSame( $id, Connect::result( $this->user_id )['state_id'] );
		$this->assertSame( self::KEY, $this->connection->key() );
	}

	public function test_interleaved_reconnects_in_two_tabs_keep_each_credential_with_its_own_id(): void {
		$this->assertTrue( ( new Connection() )->save( self::KEY, self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS ), 'An existing connection: both are reconnects.' );
		$a       = new Connection();
		$b       = new Connection();
		$nested  = false;
		$key_a   = 'showfm_live_AAAAKEYabcdefghijklmnopqr';
		$key_b   = 'showfm_live_BBBBKEYabcdefghijklmnopqr';
		$overlap = static function ( $value ) use ( $b, $key_b, &$nested ) {
			if ( ! $nested ) {
				// Tab B saves while tab A is about to write: A's write lands last.
				$nested = true;
				$b->save( $key_b, self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS );
			}
			return $value;
		};
		add_filter( 'pre_update_option_' . Connection::OPTION, $overlap );

		$this->assertTrue( $a->save( $key_a, self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS ) );
		remove_filter( 'pre_update_option_' . Connection::OPTION, $overlap );

		$this->assertSame( $key_a, ( new Connection() )->key(), 'A wrote last, so A\'s credentials are stored.' );
		$this->assertSame( $a->saved_state_id(), Connection::state_id(), 'With A\'s own id, from the same write.' );
		$this->assertNotSame( $b->saved_state_id(), Connection::state_id(), 'B\'s outcome can never match A\'s credentials.' );
	}

	public function test_a_wp_cli_reconnect_hides_a_browser_outcome(): void {
		$this->returned();
		$this->http->respond( 200, $this->exchange_body() );
		$this->http->respond( 200, '{"data":{"status":"active","activated":true}}' );
		$this->connect->complete_pending( $this->user_id );
		$this->assertNotNull( ShowFM\Admin_Status::current_result( Connect::result( $this->user_id ), 'connected', Connection::state_id() ) );

		// `wp showfm connect` registers a new key in a separate process.
		$cli_connection = new Connection();
		$cli            = new Connect( $cli_connection, new Api_Client( $cli_connection ) );
		$this->http->respond( 201, $this->exchange_body() );
		$this->http->respond( 200, '{"data":{"status":"active"}}' );
		$this->assertSame( Connect::STATUS_CONNECTED, $cli->register_with_key( 'showfm_live_CLIKEYabcdefghijklmnopqrs' )['status'] );

		$this->assertNull( ShowFM\Admin_Status::current_result( Connect::result( $this->user_id ), 'connected', Connection::state_id() ) );
	}

	public function test_reconnecting_after_a_disconnect_gets_a_new_id(): void {
		$this->assertTrue( $this->connection->save( self::KEY, self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS ) );
		$first = Connection::state_id();
		$this->connect->fail( $this->user_id, Connect::ERROR_EXPIRED );

		$this->connection->disconnect();
		$this->assertNull( ShowFM\Admin_Status::current_result( Connect::result( $this->user_id ), 'not_connected', Connection::state_id() ), 'Recorded against the old connection.' );
		$this->connect->fail( $this->user_id, Connect::ERROR_CANCELLED );
		$this->assertSame( Connection::state_id(), Connect::result( $this->user_id )['state_id'], 'Recorded against this disconnected state.' );
		$this->assertNotNull( ShowFM\Admin_Status::current_result( Connect::result( $this->user_id ), 'not_connected', Connection::state_id() ), 'Shown while that state lasts.' );

		$this->assertTrue( $this->connection->save( self::KEY, self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS ) );

		$this->assertNotSame( $first, Connection::state_id() );
		$this->assertNull( ShowFM\Admin_Status::current_result( Connect::result( $this->user_id ), 'connected', Connection::state_id() ), 'The disconnected failure is hidden once connected.' );
	}

	public function test_upgrade_from_the_counter(): void {
		// What an earlier build left: credentials with a numeric `g`, the counter, and an
		// outcome recorded against a generation.
		$this->assertTrue( $this->connection->save( self::KEY, self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS ) );
		$stored = get_option( Connection::OPTION );
		unset( $stored['i'] );
		$stored['g'] = 7;
		update_option( Connection::OPTION, $stored, false );
		update_option( Connection::LEGACY_GENERATION_OPTION, 7, false );
		set_transient(
			Connect::RESULT_PREFIX . $this->user_id,
			array(
				'status'      => Connect::STATUS_CONNECTED,
				'error'       => '',
				'retry_after' => 0,
				'reason'      => '',
				'generation'  => 7,
			),
			60
		);

		Connection::upgrade();

		$this->assertFalse( get_option( Connection::LEGACY_GENERATION_OPTION ), 'The counter is removed.' );
		$this->assertSame( Connection::STATE_CONNECTED, $this->connection->state(), 'The connection keeps working.' );
		$this->assertSame( self::KEY, $this->connection->key() );
		$this->assertSame( Connection::LEGACY_ID, Connection::state_id() );
		$this->assertNull( Connect::result( $this->user_id )['state_id'], 'An outcome from the counter era matches nothing.' );
		set_transient(
			Connect::RESULT_PREFIX . $this->user_id,
			array(
				'status'      => Connect::STATUS_FAILED,
				'error'       => Connect::ERROR_CANCELLED,
				'retry_after' => 0,
				'reason'      => '',
				'connection'  => '',
			),
			60
		);
		$this->assertNull( Connect::result( $this->user_id )['state_id'], 'Nor one keyed by the connection id of the previous build.' );
		$this->assertNull( ShowFM\Admin_Status::current_result( Connect::result( $this->user_id ), 'connected', Connection::state_id() ) );
		$this->assertNotFalse( has_action( 'admin_init', array( Connection::class, 'upgrade' ) ) );

		$this->assertTrue( $this->connection->save( self::KEY, self::SECRET, self::SITE_ID, time() + DAY_IN_SECONDS ) );
		$this->assertTrue( wp_is_uuid( Connection::state_id(), 4 ), 'A reconnect writes a real id.' );
	}

	public function test_uninstall_removes_the_old_counter(): void {
		update_option( Connection::LEGACY_GENERATION_OPTION, 3, false );

		ShowFM\Uninstaller::run();

		$this->assertFalse( get_option( Connection::LEGACY_GENERATION_OPTION ) );
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
		$this->assertNotSame( Connection::state_id(), Connect::result( $this->user_id )['state_id'] );
		$this->assertNull( ShowFM\Admin_Status::current_result( Connect::result( $this->user_id ), 'connected', Connection::state_id() ), 'A\'s "Connected" does not describe B\'s credentials.' );
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
				// The credentials write fails, whether it is an INSERT or (after a disconnect left a state id) an UPDATE.
				return preg_match( '/^\s*(INSERT|UPDATE)\b/i', $query ) && false !== strpos( $query, "'" . Connection::OPTION . "'" ) ? '' : $query;
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
