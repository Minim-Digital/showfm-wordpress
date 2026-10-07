<?php
/**
 * The account details and the connection signals the settings screen reads.
 *
 * @package ShowFM
 */

use ShowFM\Account;
use ShowFM\Api_Client;
use ShowFM\Connection;
use ShowFM\Ping_Endpoint;
use ShowFM\Plugin;

/**
 * Account and connection signal tests.
 */
class Test_Account extends WP_UnitTestCase {

	const SITE_ID = '0b5d6f0e-1c2d-4e3f-8a9b-0c1d2e3f4a5b';
	const KEY     = 'showfm_live_KEYabcdefghijklmnopqrst';

	/**
	 * HTTP mock.
	 *
	 * @var ShowFM_Http_Mock
	 */
	private $http;

	/**
	 * Store under test.
	 *
	 * @var Account
	 */
	private $account;

	public function set_up(): void {
		parent::set_up();
		$this->http = new ShowFM_Http_Mock();
		Plugin::connect()->disconnect();
		$this->assertTrue( Plugin::connection()->save( self::KEY, str_repeat( 'a', 43 ), self::SITE_ID, time() + 300 * DAY_IN_SECONDS ) );
		$this->account = new Account( Plugin::connection(), new Api_Client( Plugin::connection() ) );
	}

	public function tear_down(): void {
		$this->http->detach();
		Plugin::connect()->disconnect();
		parent::tear_down();
	}

	public function test_refresh_keeps_only_plain_valid_fields(): void {
		$this->http->respond( 200, '{"data":{"user":{"id":"u1","name":"<img src=x onerror=alert(1)>Maya"}}}' );
		$this->http->respond( 200, '{"data":[{"id":"7c9e6679-7425-40de-944b-e07fc1f90ae7","slug":"Bad Slug!","title":"Show"},{"id":"not-a-uuid","title":"Dropped"},"junk"]}' );

		$this->assertTrue( $this->account->refresh() );

		$this->assertSame( 'Bearer ' . self::KEY, $this->http->requests[0]['args']['headers']['Authorization'] );
		$this->assertSame(
			array(
				'name'  => 'Maya',
				'shows' => array(
					array(
						'id'    => '7c9e6679-7425-40de-944b-e07fc1f90ae7',
						'title' => 'Show',
						'slug'  => '',
					),
				),
			),
			$this->account->details()
		);
		$this->assertStringNotContainsString( self::KEY, maybe_serialize( get_option( Account::OPTION ) ) );
	}

	public function test_a_failed_refresh_keeps_the_stored_copy(): void {
		update_option(
			Account::OPTION,
			array(
				'state' => Connection::state_id(),
				'site'  => self::SITE_ID,
				'name'  => 'Kept',
				'shows' => array(),
			)
		);
		$this->http->respond( 200, '{"data":{"user":{"name":"New"}}}' );
		$this->http->respond( 503, '' );

		$this->assertFalse( $this->account->refresh() );

		$this->assertSame( 'Kept', $this->account->details()['name'] );
	}

	public function test_details_from_another_connection_are_ignored(): void {
		update_option(
			Account::OPTION,
			array(
				'site'  => 'another-site',
				'name'  => 'Someone else',
				'shows' => array(),
			)
		);

		$this->assertSame( '', $this->account->details()['name'] );
	}

	public function test_details_stored_without_a_state_are_not_shown(): void {
		update_option(
			Account::OPTION,
			array(
				'site'  => self::SITE_ID,
				'name'  => 'Old record',
				'shows' => array(),
			)
		);

		$this->assertSame( '', $this->account->details()['name'] );
	}

	public function test_a_reconnect_with_the_same_site_hides_the_old_accounts_details_until_a_fetch_succeeds(): void {
		$this->http->respond( 200, '{"data":{"user":{"name":"Old account"}}}' );
		$this->http->respond( 200, '{"data":[{"id":"7c9e6679-7425-40de-944b-e07fc1f90ae7","slug":"old-show","title":"Old show"}]}' );
		$this->assertTrue( $this->account->refresh() );
		$this->assertSame( 'Old account', $this->account->details()['name'] );

		// A reconnect: same site id, new key, new state.
		$this->assertTrue( Plugin::connection()->save( 'showfm_live_NEWabcdefghijklmnopqrst', str_repeat( 'b', 43 ), self::SITE_ID, time() + 300 * DAY_IN_SECONDS ) );
		$hidden = array(
			'name'  => '',
			'shows' => array(),
		);
		$this->assertSame( $hidden, $this->account->details(), 'The old key\'s account is hidden at once.' );
		$this->assertSame( ShowFM\Connect::app_url() . '/dashboard', ShowFM\Admin_Status::sites_url_for( Plugin::connection()->pinned() ), 'The Connected sites link does not use the old shows.' );

		// The fetch for the new state fails: still hidden, never the old account.
		$this->http->respond( 503, '' );
		$this->assertFalse( $this->account->refresh() );
		$this->assertSame( $hidden, $this->account->details() );

		// It succeeds: the new account shows.
		$this->http->respond( 200, '{"data":{"user":{"name":"New account"}}}' );
		$this->http->respond( 200, '{"data":[]}' );
		$this->assertTrue( $this->account->refresh() );
		$this->assertSame( 'New account', $this->account->details()['name'] );
	}

	public function test_no_request_while_not_connected(): void {
		Plugin::connect()->disconnect();

		$this->assertFalse( $this->account->refresh() );
		$this->assertSame( 0, $this->http->count() );
	}

	public function test_a_change_without_a_ping_is_noticed_after_the_grace_period(): void {
		update_option( Connection::CONNECTED_AT_OPTION, time() - DAY_IN_SECONDS );

		Ping_Endpoint::note_change( time() - Ping_Endpoint::PING_GRACE + 60 );
		$this->assertSame( 0, Ping_Endpoint::missed_at(), 'show.fm may still be retrying the ping.' );

		Ping_Endpoint::note_change( time() - Ping_Endpoint::PING_GRACE - 60 );
		$this->assertGreaterThan( 0, Ping_Endpoint::missed_at() );
	}

	public function test_a_change_before_connecting_or_already_pinged_is_not_a_miss(): void {
		update_option( Connection::CONNECTED_AT_OPTION, time() - HOUR_IN_SECONDS );
		Ping_Endpoint::note_change( time() - 2 * HOUR_IN_SECONDS );
		$this->assertSame( 0, Ping_Endpoint::missed_at(), 'Backlog from before the connection.' );

		update_option( Connection::CONNECTED_AT_OPTION, time() - DAY_IN_SECONDS );
		update_option( Ping_Endpoint::LAST_PING_OPTION, time() - 30 * MINUTE_IN_SECONDS );
		Ping_Endpoint::note_change( time() - 40 * MINUTE_IN_SECONDS );
		$this->assertSame( 0, Ping_Endpoint::missed_at(), 'A ping arrived after the change.' );
	}

	public function test_an_accepted_ping_clears_the_miss(): void {
		update_option( Ping_Endpoint::MISSED_OPTION, time() - HOUR_IN_SECONDS );

		( new Ping_Endpoint( Plugin::connection() ) )->handle();

		$this->assertSame( 0, Ping_Endpoint::missed_at() );
		wp_clear_scheduled_hook( Ping_Endpoint::PULL_HOOK );
	}

	public function test_reconnecting_clears_refusal_and_pause(): void {
		Plugin::connection()->mark_reconnect_needed();
		update_option( Connection::PAUSED_OPTION, time() );
		$this->assertGreaterThan( 0, Connection::refused_at() );

		Plugin::connection()->save( self::KEY, str_repeat( 'a', 43 ), self::SITE_ID, time() + 300 * DAY_IN_SECONDS );

		$this->assertSame( 0, Connection::refused_at() );
		$this->assertSame( 0, Connection::paused_at() );
		$this->assertEqualsWithDelta( time(), Connection::connected_at(), 5 );
	}
}
