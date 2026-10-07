<?php
/**
 * The settings screen's REST routes and the Connection tab's states.
 *
 * @package ShowFM
 */

use ShowFM\Account;
use ShowFM\Admin_Status;
use ShowFM\Api_Result;
use ShowFM\Connect;
use ShowFM\Connection;
use ShowFM\Health;
use ShowFM\Notices;
use ShowFM\Ping_Endpoint;
use ShowFM\Plugin;
use ShowFM\Sync;

/**
 * Admin endpoint tests.
 */
class Test_Admin_Endpoint extends WP_UnitTestCase {

	const SITE_ID = '0b5d6f0e-1c2d-4e3f-8a9b-0c1d2e3f4a5b';
	const KEY     = 'showfm_live_SECRETKEYabcdefghijklmn7f3a';
	const SECRET  = 'pingsecretpingsecretpingsecretpingsecret12';
	const SHOW_ID = '7c9e6679-7425-40de-944b-e07fc1f90ae7';

	/**
	 * The admin.
	 *
	 * @var int
	 */
	private $admin;

	/**
	 * HTTP mock, so no test reaches the network.
	 *
	 * @var ShowFM_Http_Mock
	 */
	private $http;

	public function set_up(): void {
		parent::set_up();
		$this->http = new ShowFM_Http_Mock();
		Plugin::connect()->disconnect();
		delete_option( Health::LAST_SYNC_OPTION );
		wp_clear_scheduled_hook( Sync::POLL_HOOK );
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $this->admin );
		}
		wp_set_current_user( $this->admin );
		do_action( 'rest_api_init' );
	}

	public function tear_down(): void {
		$this->http->detach();
		Plugin::connect()->disconnect();
		unset( $_REQUEST['_wpnonce'], $GLOBALS['wp_rest_auth_cookie'] );
		parent::tear_down();
	}

	public function test_routes_are_registered(): void {
		$routes = rest_get_server()->get_routes();
		foreach ( array( '/showfm/v1/admin/connection', '/showfm/v1/admin/connection/dismiss-result', '/showfm/v1/admin/disconnect', '/showfm/v1/admin/notices/dismiss' ) as $route ) {
			$this->assertArrayHasKey( $route, $routes );
		}
	}

	/**
	 * @dataProvider routes
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route.
	 */
	public function test_users_who_cannot_manage_options_are_refused( string $method, string $route ): void {
		$this->connect();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$response = $this->dispatch(
			$method,
			$route,
			array(
				'key'   => 'expiry30:1',
				'state' => Connection::state_id(),
			)
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( Connection::STATE_CONNECTED, Plugin::connection()->state(), 'Nothing changed.' );
	}

	/**
	 * @dataProvider routes
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route.
	 */
	public function test_logged_out_requests_are_refused( string $method, string $route ): void {
		wp_set_current_user( 0 );

		$this->assertSame(
			401,
			$this->dispatch(
				$method,
				$route,
				array(
					'key'   => 'expiry30:1',
					'state' => Connection::state_id(),
				)
			)->get_status()
		);
	}

	/**
	 * @return array<string,array{string,string}>
	 */
	public function routes(): array {
		return array(
			'connection'     => array( 'GET', '/showfm/v1/admin/connection' ),
			'dismiss result' => array( 'POST', '/showfm/v1/admin/connection/dismiss-result' ),
			'disconnect'     => array( 'POST', '/showfm/v1/admin/disconnect' ),
			'dismiss'        => array( 'POST', '/showfm/v1/admin/notices/dismiss' ),
		);
	}

	public function test_a_cookie_request_without_the_rest_nonce_runs_logged_out(): void {
		$this->connect();
		$GLOBALS['wp_rest_auth_cookie'] = true;

		// The REST API's cookie check, as it runs for every browser request.
		$this->assertTrue( rest_cookie_check_errors( null ) );

		$this->assertSame( 0, get_current_user_id() );
		$this->assertSame( 401, $this->dispatch( 'POST', '/showfm/v1/admin/disconnect', array( 'state' => Connection::state_id() ) )->get_status() );
		$this->assertSame( Connection::STATE_CONNECTED, Plugin::connection()->state(), 'The connection is kept.' );
	}

	public function test_a_cookie_request_with_the_rest_nonce_is_accepted(): void {
		$GLOBALS['wp_rest_auth_cookie'] = true;
		$_REQUEST['_wpnonce']           = wp_create_nonce( 'wp_rest' );

		$this->assertTrue( rest_cookie_check_errors( null ) );

		$this->assertSame( $this->admin, get_current_user_id() );
		$this->assertSame( 200, $this->dispatch( 'GET', '/showfm/v1/admin/connection' )->get_status() );
	}

	public function test_not_connected(): void {
		$data = $this->view();

		$this->assertSame( 'not_connected', $data['state'] );
		$this->assertSame( '', $data['key']['masked'] );
		$this->assertSame( array(), $data['shows'] );
		$this->assertSame( admin_url( 'admin-post.php' ), $data['connect']['url'] );
		$this->assertSame( Connect::ACTION, $data['connect']['action'] );
		$this->assertSame( 1, wp_verify_nonce( $data['connect']['nonce'], Connect::ACTION ) );
		$this->assertNull( $data['notice'] );
	}

	public function test_connected_shows_the_account_shows_key_and_updates_without_secrets(): void {
		$this->connect( time() + 300 * DAY_IN_SECONDS );
		update_option( Health::LAST_SYNC_OPTION, time() - 180 );

		$response = $this->dispatch( 'GET', '/showfm/v1/admin/connection' );
		$data     = $response->get_data();

		$this->assertSame( 'connected', $data['state'] );
		$this->assertSame( 'Maya Lindgren', $data['account'] );
		$this->assertSame( home_url(), $data['site'] );
		$this->assertSame(
			array(
				array(
					'id'      => self::SHOW_ID,
					'title'   => 'The Long Table',
					'address' => 'the-long-table.show.fm',
					'artwork' => '',
				),
			),
			$data['shows']
		);
		$this->assertSame( "\u{2022}\u{2022}\u{2022}\u{2022}7f3a", $data['key']['masked'] );
		$this->assertSame( Admin_Status::date( time() + 300 * DAY_IN_SECONDS ), $data['key']['expiresOn'] );
		$this->assertSame( '3 minutes ago', $data['lastChecked'] );
		$this->assertSame( 300, $data['daysLeft'] );
		$this->assertStringContainsString( 'no-store', $response->get_headers()['Cache-Control'] );

		$json = (string) wp_json_encode( $data );
		$this->assertStringNotContainsString( self::KEY, $json, 'The key never reaches the browser.' );
		$this->assertStringNotContainsString( substr( self::KEY, 0, -4 ), $json );
		$this->assertStringNotContainsString( self::SECRET, $json, 'Nor the ping secret.' );
	}

	public function test_artwork_comes_from_the_public_cache_and_reading_makes_no_request(): void {
		$this->connect();
		$this->http->respond( 200, '{"data":{"id":"' . self::SHOW_ID . '","artwork":{"url":"https://m.cdn.media/art.jpg"}}}' );
		Plugin::cache()->refresh( '/v1/podcasts/' . self::SHOW_ID );
		$this->assertSame( 1, $this->http->count() );

		$shows = $this->view()['shows'];

		$this->assertSame( 1, $this->http->count(), 'Reading the screen makes no request.' );
		$this->assertSame( 'https://m.cdn.media/art.jpg', $shows[0]['artwork'] );
	}

	public function test_artwork_that_is_not_https_is_dropped(): void {
		$this->connect();
		$this->http->respond( 200, '{"data":{"artwork":{"url":"javascript:alert(1)"}}}' );
		Plugin::cache()->refresh( '/v1/podcasts/' . self::SHOW_ID );

		$this->assertSame( '', $this->view()['shows'][0]['artwork'] );
	}

	/**
	 * @dataProvider expiring
	 *
	 * @param int $days     Days until expiry.
	 * @param int $expected Days shown.
	 */
	public function test_expiring( int $days, int $expected ): void {
		$this->connect( time() + $days * DAY_IN_SECONDS - HOUR_IN_SECONDS );

		$data = $this->view();

		$this->assertSame( 'expiring', $data['state'] );
		$this->assertSame( $expected, $data['daysLeft'] );
	}

	/**
	 * @return array<string,array{int,int}>
	 */
	public function expiring(): array {
		return array(
			'30 days' => array( 30, 30 ),
			'7 days'  => array( 7, 7 ),
			'1 day'   => array( 1, 1 ),
		);
	}

	public function test_31_days_is_not_expiring_yet(): void {
		$this->connect( time() + 31 * DAY_IN_SECONDS );

		$this->assertSame( 'connected', $this->view()['state'] );
	}

	public function test_expired(): void {
		$expires = time() - 3 * DAY_IN_SECONDS;
		$this->connect( $expires );

		$data = $this->view();

		$this->assertSame( 'expired', $data['state'] );
		$this->assertSame( Admin_Status::date( $expires ), $data['key']['expiresOn'] );
	}

	public function test_refused_records_when_show_fm_stopped_accepting_the_key(): void {
		$this->connect();
		$this->http->respond( 401, '{"error":{"code":"invalid_api_key","message":"The API key is invalid, revoked or expired."}}' );

		Plugin::api_client()->get_keyed( '/v1/me' );
		$data = $this->view();

		$this->assertSame( 'refused', $data['state'] );
		$this->assertSame( Admin_Status::date( time() ), $data['key']['refusedOn'] );
	}

	public function test_paused_follows_plan_upgrade_required_and_lifts_on_success(): void {
		$this->connect();

		$state = Connection::state_id();

		$this->assertFalse( Connection::note_report( new Api_Result( Api_Result::UNAVAILABLE, 403, null, null, 0, '', 'plan_upgrade_required' ) ), 'A result that names no state changes nothing.' );
		$this->assertSame( 'connected', $this->view()['state'] );

		Connection::note_report( ( new Api_Result( Api_Result::UNAVAILABLE, 403, null, null, 0, '', 'plan_upgrade_required' ) )->for_state( $state ) );
		$this->assertSame( 'paused', $this->view()['state'] );

		Connection::note_report( ( new Api_Result( Api_Result::UNAVAILABLE, 403, null, null, 0, '', 'insufficient_scope' ) )->for_state( $state ) );
		$this->assertSame( 'paused', $this->view()['state'], 'Another 403 changes nothing.' );

		Connection::note_report( ( new Api_Result( Api_Result::SUCCESS, 200 ) )->for_state( $state ) );
		$this->assertSame( 'connected', $this->view()['state'] );
	}

	public function test_scheduled_checks_when_pings_are_missed(): void {
		$this->connect();
		update_option( Connection::CONNECTED_AT_OPTION, time() - DAY_IN_SECONDS );
		wp_schedule_event( time() + 12 * MINUTE_IN_SECONDS - 30, 'showfm_quarter_hour', Sync::POLL_HOOK );

		Ping_Endpoint::note_change( time() - HOUR_IN_SECONDS );
		$data = $this->view();

		$this->assertSame( 'scheduled', $data['state'] );
		$this->assertSame( 12, $data['nextCheck'] );
	}

	public function test_unreadable_after_the_salts_change(): void {
		$this->connect();
		$stored      = get_option( Connection::OPTION );
		$stored['c'] = base64_encode( str_repeat( 'x', 80 ) );
		update_option( Connection::OPTION, $stored );

		$data = $this->view();

		$this->assertSame( 'unreadable', $data['state'] );
		$this->assertSame( '', $data['key']['masked'] );
		$this->assertSame( '', $data['account'], 'Nothing is shown for a connection that cannot be read.' );
	}

	public function test_the_connect_outcome_survives_reads_until_it_is_dismissed(): void {
		$this->connect();
		$this->result( Connect::STATUS_CONNECTED, '' );
		$expected = array(
			'status'  => 'connected',
			'error'   => '',
			'message' => '',
			'action'  => 'none',
		);

		$this->assertSame( $expected, $this->view()['result'] );
		$this->assertSame( $expected, $this->view()['result'], 'A reload or a second tab still shows it.' );

		$response = $this->dispatch( 'POST', '/showfm/v1/admin/connection/dismiss-result' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNull( $this->view()['result'], 'Gone once dismissed.' );
	}

	public function test_a_failed_connect_is_kept_for_the_result_ttl(): void {
		Plugin::connect()->fail( $this->admin, Connect::ERROR_CANCELLED );

		$timeout = (int) get_option( '_transient_timeout_' . Connect::RESULT_PREFIX . $this->admin );
		$this->assertEqualsWithDelta( time() + Connect::RESULT_TTL, $timeout, 5 );
		$this->assertSame( 'cancelled', $this->view()['result']['error'] );
		$this->assertSame( 'cancelled', $this->view()['result']['error'] );
	}

	/**
	 * @dataProvider errors
	 *
	 * @param string $error   Error type.
	 * @param string $message Message shown.
	 * @param string $action  The one action.
	 */
	public function test_each_connect_error_has_a_message_and_at_most_one_action( string $error, string $message, string $action ): void {
		$this->result( Connect::STATUS_FAILED, $error );

		$result = $this->view()['result'];

		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( $message, $result['message'] );
		$this->assertSame( $action, $result['action'] );
	}

	/**
	 * @return array<string,array{string,string,string}>
	 */
	public function errors(): array {
		return array(
			'cancelled'        => array( Connect::ERROR_CANCELLED, 'The connection was cancelled.', 'retry' ),
			'https'            => array( Connect::ERROR_INSECURE, 'This site’s address must use https.', 'none' ),
			'expired'          => array( Connect::ERROR_EXPIRED, 'The connection request expired. Try again.', 'retry' ),
			'exchange refused' => array( Connect::ERROR_EXCHANGE_REFUSED, 'The connection request expired. Try again.', 'retry' ),
			'state mismatch'   => array( Connect::ERROR_STATE_MISMATCH, 'The connection request didn’t start in this browser. Try again.', 'retry' ),
			'unreachable'      => array( Connect::ERROR_UNREACHABLE, 'show.fm couldn’t be reached. Try again in a few minutes.', 'retry' ),
			'storage'          => array( Connect::ERROR_STORAGE, Connect::message( Connect::ERROR_STORAGE ), 'none' ),
		);
	}

	public function test_disconnect_refuses_when_the_screen_showed_another_state(): void {
		$this->connect();
		$seen = $this->view()['stateId'];
		// Another admin reconnects after this screen loaded.
		$this->assertTrue( ( new Connection() )->save( self::KEY, self::SECRET, self::SITE_ID, time() + 300 * DAY_IN_SECONDS ) );

		$response = $this->dispatch( 'POST', '/showfm/v1/admin/disconnect', array( 'state' => $seen ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'showfm_state_moved', $response->get_data()['code'] );
		$this->assertSame( 'connected', $response->get_data()['data']['view']['state'], 'The fresh view comes back.' );
		$this->assertSame( Connection::STATE_CONNECTED, Plugin::connection()->state(), 'Nothing was disconnected.' );
	}

	public function test_disconnect_with_the_state_the_screen_showed_succeeds(): void {
		$this->connect();
		$seen = $this->view()['stateId'];
		$this->assertTrue( wp_is_uuid( $seen, 4 ) );

		$response = $this->dispatch( 'POST', '/showfm/v1/admin/disconnect', array( 'state' => $seen ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'not_connected', $response->get_data()['state'] );
	}

	public function test_disconnect_refuses_when_a_reconnect_lands_while_it_waits_for_the_lock(): void {
		$this->connect();
		$seen = $this->view()['stateId'];
		$held = new ShowFM\Sync_Lock( Connection::LOCK_OPTION, Connection::LOCK_TTL );
		$this->assertTrue( $held->acquire(), 'Another request holds the connection lock.' );
		$once = static function () use ( $held ): void {
			static $done = false;
			if ( ! $done ) {
				// That request reconnects, then lets go.
				$done = true;
				$held->release();
				( new Connection() )->save( self::KEY, self::SECRET, self::SITE_ID, time() + 300 * DAY_IN_SECONDS );
			}
		};
		add_action( 'showfm_connection_lock_waiting', $once );
		try {
			$response = $this->dispatch( 'POST', '/showfm/v1/admin/disconnect', array( 'state' => $seen ) );
		} finally {
			remove_action( 'showfm_connection_lock_waiting', $once );
			$held->release();
		}

		$this->assertSame( 409, $response->get_status(), 'Decided on the fresh state inside the lock.' );
		$this->assertSame( Connection::STATE_CONNECTED, Plugin::connection()->state() );
	}

	public function test_disconnect_needs_the_state_the_screen_showed(): void {
		$this->connect();

		foreach ( array( array(), array( 'state' => '' ) ) as $body ) {
			$response = $this->dispatch( 'POST', '/showfm/v1/admin/disconnect', $body );
			$this->assertSame( 400, $response->get_status() );
		}
		$this->assertSame( Connection::STATE_CONNECTED, Plugin::connection()->state(), 'Nothing was disconnected.' );
	}

	public function test_disconnect_reports_busy_when_the_lock_is_held_too_long(): void {
		$this->connect();
		$seen = $this->view()['stateId'];
		$held = new ShowFM\Sync_Lock( Connection::LOCK_OPTION, Connection::LOCK_TTL );
		$this->assertTrue( $held->acquire() );
		$short = static function (): int {
			return 100;
		};
		add_filter( 'showfm_connection_lock_wait_ms', $short );
		try {
			$response = $this->dispatch( 'POST', '/showfm/v1/admin/disconnect', array( 'state' => $seen ) );
		} finally {
			remove_filter( 'showfm_connection_lock_wait_ms', $short );
			$held->release();
		}

		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( 'showfm_busy', $response->get_data()['code'] );
		$this->assertSame( Connection::STATE_CONNECTED, Plugin::connection()->state(), 'Nothing changed.' );
	}

	public function test_disconnect_clears_the_stored_connect_result(): void {
		$this->connect();
		$this->result( Connect::STATUS_CONNECTED, '' );
		$this->assertSame( 'connected', $this->view()['result']['status'] );

		$this->dispatch( 'POST', '/showfm/v1/admin/disconnect', array( 'state' => Connection::state_id() ) );

		$this->assertNull( Connect::result( $this->admin ), 'Cleared, not just hidden.' );
		$this->assertNull( $this->view()['result'], 'A reload after disconnecting shows no "Connected".' );
	}

	public function test_another_admins_success_is_hidden_after_a_disconnect(): void {
		$other = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $other );
		}
		$this->connect();
		$this->result( Connect::STATUS_CONNECTED, '', null, $other );

		$this->dispatch( 'POST', '/showfm/v1/admin/disconnect', array( 'state' => Connection::state_id() ) );
		wp_set_current_user( $other );

		$this->assertNull( $this->view()['result'], 'The live state wins for every user.' );
	}

	/**
	 * @dataProvider broken_states
	 *
	 * @param string $state State to put the connection into.
	 */
	public function test_a_success_is_hidden_once_the_key_stops_working( string $state ): void {
		$this->connect( 'expired' === $state ? time() - DAY_IN_SECONDS : 0 );
		$this->result( Connect::STATUS_CONNECTED, '' );
		if ( 'refused' === $state ) {
			Plugin::connection()->mark_reconnect_needed();
		} elseif ( 'unreadable' === $state ) {
			$stored      = get_option( Connection::OPTION );
			$stored['c'] = base64_encode( str_repeat( 'x', 80 ) );
			update_option( Connection::OPTION, $stored );
		}

		$data = $this->view();

		$this->assertSame( $state, $data['state'] );
		$this->assertNull( $data['result'] );
	}

	/**
	 * @return array<string,array{string}>
	 */
	public function broken_states(): array {
		return array(
			'expired'                                      => array( 'expired' ),
			'refused, for example after a password change' => array( 'refused' ),
			'unreadable after a salt change'               => array( 'unreadable' ),
		);
	}

	public function test_a_success_from_an_earlier_connection_is_hidden(): void {
		$this->result( Connect::STATUS_CONNECTED, '' );
		$this->connect();

		$this->assertNull( $this->view()['result'], 'A later connection (for example WP-CLI) overtakes it.' );
	}

	public function test_a_failure_is_hidden_once_a_later_connection_is_stored(): void {
		$this->result( Connect::STATUS_FAILED, Connect::ERROR_CANCELLED );
		$this->assertSame( 'cancelled', $this->view()['result']['error'] );

		$this->connect();

		$this->assertNull( $this->view()['result'], 'A reconnect that succeeded elsewhere wins.' );
	}

	public function test_a_failed_reconnect_still_shows_while_the_old_key_works(): void {
		$this->connect();
		$this->result( Connect::STATUS_FAILED, Connect::ERROR_EXPIRED );

		$data = $this->view();

		$this->assertSame( 'connected', $data['state'] );
		$this->assertSame( 'expired', $data['result']['error'] );
		$this->assertArrayNotHasKey( 'state_id', $data['result'], 'The state id never reaches the browser.' );
	}

	public function test_an_outcome_without_a_state_id_is_hidden(): void {
		set_transient(
			Connect::RESULT_PREFIX . $this->admin,
			array(
				'status' => Connect::STATUS_FAILED,
				'error'  => Connect::ERROR_CANCELLED,
			),
			Connect::RESULT_TTL
		);

		$this->assertNull( $this->view()['result'], 'An outcome stored before state ids existed.' );
	}

	public function test_a_failure_is_hidden_after_a_same_second_disconnect_and_reconnect(): void {
		$this->connect();
		Plugin::connect()->fail( $this->admin, Connect::ERROR_EXPIRED );
		$this->assertSame( 'expired', $this->view()['result']['error'] );

		// Another tab disconnects and WP-CLI reconnects, all within the same second.
		( new Connection() )->disconnect();
		$this->assertTrue( ( new Connection() )->save( self::KEY, self::SECRET, self::SITE_ID, time() + 300 * DAY_IN_SECONDS ) );

		$this->assertNull( $this->view()['result'] );
	}

	public function test_disconnect_removes_the_local_connection_and_says_the_key_stays_valid(): void {
		$this->connect();

		$response = $this->dispatch( 'POST', '/showfm/v1/admin/disconnect', array( 'state' => Connection::state_id() ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'not_connected', $data['state'] );
		$this->assertSame( Connection::STATE_DISCONNECTED, Plugin::connection()->state() );
		$this->assertFalse( get_option( Account::OPTION ) );
		$this->assertSame( 0, $this->http->count(), 'show.fm has no route for a site key to revoke itself.' );
		$this->assertSame(
			array(
				'keyRevoked' => false,
				'sitesUrl'   => 'https://my.show.fm/p/the-long-table/settings/sites',
			),
			$data['disconnected'],
			'The answer says the key stays valid and where to disconnect it.'
		);
	}

	public function test_the_connected_sites_link_follows_the_first_show(): void {
		$this->assertSame( 'https://my.show.fm/dashboard', $this->view()['sitesUrl'], 'No show known.' );

		$this->connect();

		$this->assertSame( 'https://my.show.fm/p/the-long-table/settings/sites', $this->view()['sitesUrl'] );
	}

	public function test_dismiss_stores_the_key_for_this_user(): void {
		$response = $this->dispatch( 'POST', '/showfm/v1/admin/notices/dismiss', array( 'key' => 'expiry30:1791363600' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( get_current_blog_id() . '|expiry30:1791363600' ), get_user_meta( $this->admin, Notices::META, true ) );
	}

	public function test_dismiss_refuses_a_malformed_key(): void {
		$response = $this->dispatch( 'POST', '/showfm/v1/admin/notices/dismiss', array( 'key' => '<script>' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( '', get_user_meta( $this->admin, Notices::META, true ) );
	}

	public function test_the_view_carries_the_admin_notice_for_the_other_tabs(): void {
		$expires = time() + 20 * DAY_IN_SECONDS;
		$this->connect( $expires );

		$notice = $this->view()['notice'];

		$this->assertSame( 'expiry30:' . $expires, $notice['key'] );
		$this->assertSame( 'Reconnect', $notice['action']['label'] );
	}

	/**
	 * Stores a connection and its account details.
	 *
	 * @param int $expires Key expiry.
	 */
	private function connect( int $expires = 0 ): void {
		$this->assertTrue( Plugin::connection()->save( self::KEY, self::SECRET, self::SITE_ID, $expires ? $expires : time() + 300 * DAY_IN_SECONDS ) );
		update_option(
			Account::OPTION,
			array(
				'state' => Connection::state_id(),
				'site'  => self::SITE_ID,
				'name'  => 'Maya Lindgren',
				'shows' => array(
					array(
						'id'    => self::SHOW_ID,
						'title' => 'The Long Table',
						'slug'  => 'the-long-table',
					),
				),
			)
		);
	}

	/**
	 * Records a connect outcome for a user, as Connect does.
	 *
	 * @param string      $status     Outcome status.
	 * @param string      $error      Error type.
	 * @param string|null $state_id   State id it belongs to (the stored one by default).
	 * @param int|null    $user_id    The user (the admin by default).
	 */
	private function result( string $status, string $error, ?string $state_id = null, ?int $user_id = null ): void {
		set_transient(
			Connect::RESULT_PREFIX . ( $user_id ?? $this->admin ),
			array(
				'status'      => $status,
				'error'       => $error,
				'retry_after' => 0,
				'reason'      => '',
				'state_id'    => $state_id ?? Connection::state_id(),
			),
			Connect::RESULT_TTL
		);
	}

	/**
	 * The Connection tab's data.
	 *
	 * @return array<string,mixed>
	 */
	private function view(): array {
		$response = $this->dispatch( 'GET', '/showfm/v1/admin/connection' );
		$this->assertSame( 200, $response->get_status() );
		return $response->get_data();
	}

	/**
	 * Dispatches a REST request.
	 *
	 * @param string              $method HTTP method.
	 * @param string              $route  Route.
	 * @param array<string,mixed> $body   Body parameters.
	 */
	private function dispatch( string $method, string $route, array $body = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );
		if ( $body && 'POST' === $method ) {
			$request->set_body_params( $body );
		}
		return rest_get_server()->dispatch( $request );
	}
}
