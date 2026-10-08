<?php
/**
 * WP-CLI: connect, status and disconnect.
 *
 * @package ShowFM
 */

use ShowFM\Api_Client;
use ShowFM\Cache;
use ShowFM\Cli;
use ShowFM\Connect;
use ShowFM\Connection;
use ShowFM\Health;
use ShowFM\Ping_Endpoint;

/**
 * WP-CLI tests, run against the stand-in in tests/stubs/wp-cli.php.
 */
class Test_Cli extends WP_UnitTestCase {

	const SITE_ID = '0b5d6f0e-1c2d-4e3f-8a9b-0c1d2e3f4a5b';
	const KEY     = 'showfm_live_CLIKEYabcdefghijklmnopqrstuvw';
	const SECRET  = 'fcde2b2edba56bf408601fb721fe9b5c338d10ee429ea04fae5511b68fbf8fb9';

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
	 * Commands under test.
	 *
	 * @var Cli
	 */
	private $cli;

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
		$this->cli = $this->cli();
		putenv( Cli::KEY_ENV );
		ShowFM_Cli_Prompt::$answer = '';
		ShowFM_Cli_Prompt::$calls  = array();
		delete_option( Api_Client::RATE_LIMIT_OPTION );
		delete_option( Connect::VERIFY_PENDING_OPTION );
		wp_clear_scheduled_hook( Health::HOOK );
		WP_CLI::$output = array();

		$this->log_file     = wp_tempnam( 'showfm-log' );
		$this->previous_log = ini_set( 'error_log', $this->log_file );
	}

	public function tear_down(): void {
		putenv( Cli::KEY_ENV );
		ini_set( 'error_log', (string) $this->previous_log );
		$log = (string) file_get_contents( $this->log_file );
		unlink( $this->log_file );
		$this->http->detach();
		$this->connection->disconnect();
		wp_clear_scheduled_hook( Health::HOOK );
		parent::tear_down();
		$this->assertStringNotContainsString( self::KEY, $log );
		$this->assertStringNotContainsString( self::KEY, (string) wp_json_encode( WP_CLI::$output ), 'The key is never printed.' );
	}

	public function test_connect_registers_with_state_and_challenge_in_one_request(): void {
		$seen = array();
		$this->http->respond_with(
			function ( $args ) use ( &$seen ) {
				$body = json_decode( $args['body'], true );
				// show.fm fetches the challenge back while this request is open.
				$request = new WP_REST_Request( 'GET', '/showfm/v1/challenge' );
				$request->set_query_params( array( 'state' => $body['state'] ) );
				$seen = array(
					'body'     => $body,
					'answer'   => rest_get_server()->dispatch( $request )->get_data(),
					'headers'  => $args['headers'],
					'method'   => $args['method'],
					'verifier' => null,
				);
				return array( 201, $this->registration_body() );
			}
		);
		$this->http->respond( 200, '{"data":{"status":"active"}}' );

		$this->cli->connect( array(), array( 'key' => self::KEY ) );

		$this->assertSame( 3, $this->http->count(), 'Register, verify, then the account refresh (blocked here).' );
		$this->assertSame( 'https://api.show.fm/v1/me/sites', $this->http->requests[0]['url'] );
		$this->assertSame( 'POST', $seen['method'] );
		$this->assertSame( 'Bearer ' . self::KEY, $seen['headers']['Authorization'] );
		$this->assertSame( array( 'site_url', 'rest_root', 'state', 'code_challenge' ), array_keys( $seen['body'] ) );
		$this->assertSame( home_url(), $seen['body']['site_url'] );
		$this->assertSame( rest_url(), $seen['body']['rest_root'] );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{43}$/', $seen['body']['state'] );
		$this->assertSame( array( 'code_challenge' => $seen['body']['code_challenge'] ), $seen['answer'], 'The challenge answers during the request.' );
		$this->assertStringNotContainsString( self::KEY, $this->http->requests[0]['url'] );

		$this->assertNull( Connect::challenge_for_state( $seen['body']['state'] ), 'The challenge is removed afterwards.' );
		$this->assertStringEndsWith( '/v1/me/sites/' . self::SITE_ID . '/verify', $this->http->requests[1]['url'] );
		$this->assertSame( 'Bearer ' . self::KEY, $this->http->requests[1]['args']['headers']['Authorization'] );

		$this->assertSame( Connection::STATE_CONNECTED, $this->connection->state() );
		$this->assertSame( self::KEY, $this->connection->key() );
		$this->assertSame( self::SECRET, $this->connection->ping_secret() );
		$this->assertSame( self::SITE_ID, $this->connection->site_id() );
		$this->assertNotFalse( wp_next_scheduled( Health::HOOK ) );
		$this->assertSame( 'success', end( WP_CLI::$output )[0] );
		$this->assertStringContainsString( Connection::mask( self::KEY ), end( WP_CLI::$output )[1] );
	}

	public function test_connect_without_a_key_stops(): void {
		$this->assert_halts(
			function () {
				$this->cli->connect( array(), array() );
			}
		);
		$this->assertSame( 0, $this->http->count() );
		$this->assertStringContainsString( 'SHOWFM_KEY', end( WP_CLI::$output )[1] );
		$this->assertSame( array(), ShowFM_Cli_Prompt::$calls, 'No prompt without a terminal.' );
	}

	public function test_key_from_the_environment(): void {
		putenv( Cli::KEY_ENV . '=' . self::KEY );
		$this->respond_connected();

		$this->cli->connect( array(), array() );

		$this->assertSame( self::KEY, $this->connection->key() );
		$this->assertSame( array(), $this->warnings() );
	}

	public function test_key_from_standard_input(): void {
		putenv( Cli::KEY_ENV . '=showfm_live_ENVKEYabcdefghijklmnopqrstuvw' );
		$stdin = wp_tempnam( 'showfm-stdin' );
		file_put_contents( $stdin, self::KEY . "\nignored second line\n" );
		$this->respond_connected();

		$this->cli( $stdin )->connect( array(), array( 'key' => '-' ) );
		unlink( $stdin );

		$this->assertSame( self::KEY, $this->connection->key(), '--key=- wins over SHOWFM_KEY.' );
		$this->assertSame( array(), $this->warnings() );
	}

	public function test_empty_standard_input_stops(): void {
		$stdin = wp_tempnam( 'showfm-stdin' );
		$cli   = $this->cli( $stdin );

		$this->assert_halts(
			static function () use ( $cli ) {
				$cli->connect( array(), array( 'key' => '-' ) );
			}
		);
		unlink( $stdin );
		$this->assertSame( 0, $this->http->count() );
	}

	public function test_key_from_a_hidden_prompt_in_a_terminal(): void {
		ShowFM_Cli_Prompt::$answer = self::KEY . "\n";
		$this->respond_connected();

		$this->cli( 'php://stdin', true )->connect( array(), array() );

		$this->assertSame( self::KEY, $this->connection->key() );
		$this->assertCount( 1, ShowFM_Cli_Prompt::$calls );
		$this->assertTrue( ShowFM_Cli_Prompt::$calls[0][1], 'The key is not echoed.' );
	}

	public function test_the_environment_is_used_before_a_prompt(): void {
		putenv( Cli::KEY_ENV . '=' . self::KEY );
		$this->respond_connected();

		$this->cli( 'php://stdin', true )->connect( array(), array() );

		$this->assertSame( self::KEY, $this->connection->key() );
		$this->assertSame( array(), ShowFM_Cli_Prompt::$calls );
	}

	public function test_key_on_the_command_line_still_works_with_a_warning(): void {
		$this->respond_connected();

		$this->cli->connect( array(), array( 'key' => self::KEY ) );

		$this->assertSame( self::KEY, $this->connection->key() );
		$this->assertCount( 1, $this->warnings() );
		$this->assertStringContainsString( 'shell history', $this->warnings()[0] );
	}

	public function test_connect_with_a_malformed_key_sends_nothing(): void {
		$this->assert_halts(
			function () {
				$this->cli->connect( array(), array( 'key' => "bad key\n" ) );
			}
		);
		$this->assertSame( 0, $this->http->count() );
	}

	public function test_refused_key_leaves_the_existing_connection_alone(): void {
		$this->connection->save( 'showfm_live_OLDKEY000000000000000000001', str_repeat( 'a', 64 ), self::SITE_ID, 0 );
		$this->http->respond( 401, '{"error":{"code":"unauthorised","message":"Invalid key."}}' );

		$this->assert_halts(
			function () {
				$this->cli->connect( array(), array( 'key' => self::KEY ) );
			}
		);

		$this->assertSame( 1, $this->http->count() );
		$this->assertSame( Connection::STATE_CONNECTED, $this->connection->state(), 'A 401 for a new key is not a 401 for the stored one.' );
		$this->assertSame( 'showfm_live_OLDKEY000000000000000000001', $this->connection->key() );
		$this->assertSame( Connect::message( Connect::ERROR_KEY_REFUSED ), end( WP_CLI::$output )[1] );
	}

	public function test_refused_registration_shows_the_reason(): void {
		$this->http->respond(
			400,
			'{"error":{"code":"invalid_request","message":"Your site did not recognise this connection request.","details":[{"field":"site_url","message":"x","reason":"mismatch"}]}}'
		);

		$this->assert_halts(
			function () {
				$this->cli->connect( array(), array( 'key' => self::KEY ) );
			}
		);

		$message = end( WP_CLI::$output )[1];
		$this->assertStringContainsString( Connect::message( Connect::ERROR_REGISTRATION_REFUSED ), $message );
		$this->assertStringContainsString( 'mismatch', $message );
		$this->assertSame( Connection::STATE_DISCONNECTED, $this->connection->state() );
	}

	/**
	 * @dataProvider refusals
	 *
	 * @param int    $status   Status.
	 * @param string $expected Error type.
	 */
	public function test_each_refusal_has_its_own_message( int $status, string $expected ): void {
		$this->http->respond( $status, '{"error":{"code":"x","message":"y"}}' );

		$this->assert_halts(
			function () {
				$this->cli->connect( array(), array( 'key' => self::KEY ) );
			}
		);

		$this->assertStringStartsWith( Connect::message( $expected ), end( WP_CLI::$output )[1] );
	}

	/**
	 * @return array<string,array{0:int,1:string}>
	 */
	public function refusals(): array {
		return array(
			'bad request'  => array( 400, Connect::ERROR_REGISTRATION_REFUSED ),
			'forbidden'    => array( 403, Connect::ERROR_KEY_REFUSED ),
			'not found'    => array( 404, Connect::ERROR_KEY_REFUSED ),
			'not allowed'  => array( 405, Connect::ERROR_BAD_RESPONSE ),
			'server error' => array( 500, Connect::ERROR_UNREACHABLE ),
		);
	}

	public function test_rate_limited_registration_says_when_to_retry(): void {
		$this->http->respond( 429, '', array( 'Retry-After' => '90' ) );

		$this->assert_halts(
			function () {
				$this->cli->connect( array(), array( 'key' => self::KEY ) );
			}
		);

		$this->assertSame( Connect::message( Connect::ERROR_RATE_LIMITED, 90 ), end( WP_CLI::$output )[1] );
	}

	public function test_connected_but_not_verified_warns(): void {
		$this->http->respond( 201, $this->registration_body() );
		$this->http->respond( 503 );

		$this->cli->connect( array(), array( 'key' => self::KEY ) );

		$types = array_column( WP_CLI::$output, 0 );
		$this->assertContains( 'warning', $types );
		$this->assertSame( 'success', end( $types ) );
		$this->assertTrue( Connect::verify_pending() );
	}

	public function test_status_when_disconnected(): void {
		$this->cli->status();

		$this->assertSame( array( array( 'line', 'State: not connected' ) ), WP_CLI::$output );
	}

	public function test_status_when_connected_masks_the_key(): void {
		$this->connection->save( self::KEY, self::SECRET, self::SITE_ID, 1822899600 );
		update_option( Health::LAST_SYNC_OPTION, 1791363600 );
		update_option( Ping_Endpoint::LAST_PING_OPTION, 1791363660 );

		$this->cli->status();

		$lines = array_column( WP_CLI::$output, 1 );
		$this->assertContains( 'State: connected', $lines );
		$this->assertContains( 'Site ID: ' . self::SITE_ID, $lines );
		$this->assertContains( 'Key: ' . Connection::mask( self::KEY ), $lines );
		$this->assertContains( 'Key expires: 2027-10-07 09:00 UTC', $lines );
		$this->assertContains( 'Last sync: 2026-10-07 09:00 UTC', $lines );
		$this->assertContains( 'Last ping: 2026-10-07 09:01 UTC', $lines );
	}

	public function test_status_when_reconnect_is_needed(): void {
		$this->connection->save( self::KEY, self::SECRET, self::SITE_ID, 0 );
		$this->connection->mark_reconnect_needed();

		$this->cli->status();

		$lines = array_column( WP_CLI::$output, 1 );
		$this->assertContains( 'State: reconnect needed', $lines );
		$this->assertContains( 'Key expires: never', $lines );
		$this->assertContains( 'Last sync: never', $lines );
	}

	public function test_disconnect_leaves_a_reconnect_that_lands_meanwhile_alone(): void {
		$this->connection->save( self::KEY, self::SECRET, self::SITE_ID, 0 );
		$held = new ShowFM\Sync_Lock( Connection::LOCK_OPTION, Connection::LOCK_TTL );
		$this->assertTrue( $held->acquire(), 'Another request holds the connection lock.' );
		$ran  = false;
		$hook = static function () use ( $held, &$ran ): void {
			if ( ! $ran ) {
				// That request reconnects, then lets go.
				$ran = true;
				$held->release();
				( new Connection() )->save( 'showfm_live_FRESHKEYabcdefghijklmnopq', self::SECRET, self::SITE_ID, 0 );
			}
		};
		add_action( 'showfm_connection_lock_waiting', $hook );
		try {
			$this->assert_halts(
				function () {
					$this->cli->disconnect( array(), array( 'yes' => true ) );
				}
			);
		} finally {
			remove_action( 'showfm_connection_lock_waiting', $hook );
			$held->release();
		}

		$this->assertTrue( $ran );
		$this->assertSame( 'showfm_live_FRESHKEYabcdefghijklmnopq', ( new Connection() )->key(), 'The reconnect stands.' );
		$this->assertStringContainsString( 'nothing was disconnected', end( WP_CLI::$output )[1] );
	}

	public function test_disconnect_says_busy_when_the_lock_is_held_too_long(): void {
		$this->connection->save( self::KEY, self::SECRET, self::SITE_ID, 0 );
		$held = new ShowFM\Sync_Lock( Connection::LOCK_OPTION, Connection::LOCK_TTL );
		$this->assertTrue( $held->acquire() );
		$short = static function (): int {
			return 100;
		};
		add_filter( 'showfm_connection_lock_wait_ms', $short );
		try {
			$this->assert_halts(
				function () {
					$this->cli->disconnect( array(), array( 'yes' => true ) );
				}
			);
		} finally {
			remove_filter( 'showfm_connection_lock_wait_ms', $short );
			$held->release();
		}

		$this->assertSame( Connect::message( Connect::ERROR_BUSY ), end( WP_CLI::$output )[1] );
		$this->assertSame( Connection::STATE_CONNECTED, $this->connection->state(), 'Nothing changed.' );
	}

	public function test_disconnect_asks_first(): void {
		$this->connection->save( self::KEY, self::SECRET, self::SITE_ID, 0 );
		Health::schedule();

		$this->assert_halts(
			function () {
				$this->cli->disconnect( array(), array() );
			}
		);

		$this->assertSame( 'confirm', WP_CLI::$output[0][0] );
		$this->assertTrue( $this->connection->is_connected(), 'Nothing is removed without confirmation.' );
		$this->assertNotFalse( wp_next_scheduled( Health::HOOK ) );
	}

	public function test_disconnect_with_yes_removes_credentials_and_events(): void {
		$this->connection->save( self::KEY, self::SECRET, self::SITE_ID, 0 );
		Health::schedule();
		wp_schedule_single_event( time(), Ping_Endpoint::PULL_HOOK );
		update_option( Ping_Endpoint::LAST_PING_OPTION, time() );
		update_option( Connect::VERIFY_PENDING_OPTION, 1 );

		$this->http->respond( 200, '{"data":{"disconnected":true}}' );
		$this->cli->disconnect( array(), array( 'yes' => true ) );

		$this->assertSame( Connection::STATE_DISCONNECTED, $this->connection->state() );
		$this->assertSame( array( 'i' ), array_keys( get_option( Connection::OPTION ) ), 'Only a fresh state id is left.' );
		$this->assertFalse( wp_next_scheduled( Health::HOOK ) );
		$this->assertFalse( wp_next_scheduled( Ping_Endpoint::PULL_HOOK ) );
		$this->assertFalse( get_option( Ping_Endpoint::LAST_PING_OPTION ) );
		$this->assertFalse( Connect::verify_pending() );
		$this->assertSame( array( 'success', 'Disconnected. This site’s key was revoked at show.fm.' ), end( WP_CLI::$output ) );
		$this->assertSame( 1, $this->http->count() );
		$this->assertStringEndsWith( '/v1/me/sites/' . self::SITE_ID . '/disconnect', $this->http->last()['url'] );
	}

	public function test_a_reconnect_after_disconnect_shows_no_sync_health_from_the_old_connection(): void {
		$this->connection->save( self::KEY, self::SECRET, self::SITE_ID, 0 );
		update_option( Health::LAST_SYNC_OPTION, 1791363600 );
		update_option( Health::SYNC_ERRORS_OPTION, 3 );
		update_option(
			ShowFM\Sync_Log::OPTION,
			array(
				array(
					'code' => 'row_author',
					'seq'  => 4,
					'at'   => 1791363600,
				),
			)
		);

		$this->http->respond( 200, '{"data":{"disconnected":true}}' );
		$this->cli->disconnect( array(), array( 'yes' => true ) );

		$this->assertFalse( get_option( Health::LAST_SYNC_OPTION ) );
		$this->assertFalse( get_option( Health::SYNC_ERRORS_OPTION ) );
		$this->assertFalse( get_option( ShowFM\Sync_Log::OPTION ) );

		// Another account's site: nothing from the old connection until it syncs.
		$this->connection->save( self::KEY, self::SECRET, '0b5d6f0e-1c2d-4e3f-8a9b-0c1d2e3f4a5b', 0 );
		WP_CLI::$output = array();
		$this->cli->status();
		$this->assertContains( 'Last sync: never', array_column( WP_CLI::$output, 1 ) );
		$this->assertSame( 0, ShowFM\Health::payload()['sync_error_count'] );
		$this->assertNull( ShowFM\Health::payload()['last_sync_at'] );
	}

	public function test_disconnect_after_a_401_clears_locally_and_says_nothing_was_left_to_revoke(): void {
		$this->connection->save( self::KEY, self::SECRET, self::SITE_ID, 0 );
		$this->http->respond( 401 );

		$this->cli->disconnect( array(), array( 'yes' => true ) );

		$this->assertSame( Connection::STATE_DISCONNECTED, $this->connection->state() );
		$this->assertSame( 1, $this->http->count() );
		$this->assertSame( 'success', end( WP_CLI::$output )[0] );
		$this->assertStringContainsString( 'nothing left to revoke', end( WP_CLI::$output )[1] );
	}

	public function test_a_busy_lock_after_a_revoke_says_the_key_was_revoked_and_running_again_finishes(): void {
		$this->connection->save( self::KEY, self::SECRET, self::SITE_ID, 0 );
		$held = new ShowFM\Sync_Lock( Connection::LOCK_OPTION, Connection::LOCK_TTL );
		$this->assertTrue( $held->acquire() );
		$short = static function (): int {
			return 100;
		};
		$this->http->respond( 200, '{"data":{"disconnected":true}}' );
		add_filter( 'showfm_connection_lock_wait_ms', $short );
		try {
			$this->assert_halts(
				function () {
					$this->cli->disconnect( array(), array( 'yes' => true ) );
				}
			);
		} finally {
			remove_filter( 'showfm_connection_lock_wait_ms', $short );
			$held->release();
		}

		$error = end( WP_CLI::$output );
		$this->assertSame( 'error', $error[0] );
		$this->assertStringStartsWith( 'This site’s key was revoked at show.fm', $error[1] );
		$this->assertStringContainsString( 'Run wp showfm disconnect again to finish.', $error[1] );
		$this->assertTrue( $this->connection->is_connected() );

		$this->http->respond( 401 );
		$this->cli->disconnect( array(), array( 'yes' => true ) );

		$this->assertSame( Connection::STATE_DISCONNECTED, $this->connection->state() );
		$this->assertSame( 'success', end( WP_CLI::$output )[0] );
	}

	public function test_a_lease_lost_after_the_swap_is_finished_by_running_disconnect_again(): void {
		$this->connection->save( self::KEY, self::SECRET, self::SITE_ID, 0 );
		Health::schedule();
		$this->http->fail( 'Could not resolve host' );
		$expire = $this->lose_lease_after_swap();
		try {
			$this->assert_halts(
				function () {
					$this->cli->disconnect( array(), array( 'yes' => true ) );
				}
			);
		} finally {
			$this->keep_lease( $expire );
		}

		$error = end( WP_CLI::$output );
		$this->assertSame( 'error', $error[0] );
		$this->assertStringContainsString( 'show.fm didn’t confirm the key was revoked', $error[1] );
		$this->assertStringContainsString( 'This site is disconnected, but another change took over before its clean-up finished. It finishes the next time an admin page loads, or run wp showfm disconnect again.', $error[1] );
		$this->assertNotFalse( wp_next_scheduled( Health::HOOK ) );

		// The site reads as disconnected, but the command does not stop there while the clean-up is pending.
		$this->cli->disconnect( array(), array( 'yes' => true ) );

		$this->assertSame( array( 'success', 'This site finished disconnecting. show.fm didn’t confirm the key was revoked, so it may still work. Revoke it in show.fm under Connected sites.' ), end( WP_CLI::$output ) );
		$this->assertFalse( wp_next_scheduled( Health::HOOK ) );

		$this->cli->disconnect( array(), array() );
		$this->assertSame( array( 'success', 'This site is not connected to show.fm.' ), end( WP_CLI::$output ) );
	}

	public function test_disconnect_when_showfm_cannot_be_reached_warns_with_the_connected_sites_link(): void {
		$this->connection->save( self::KEY, self::SECRET, self::SITE_ID, 0 );
		$this->http->fail( 'Could not resolve host' );

		$this->cli->disconnect( array(), array( 'yes' => true ) );

		$this->assertSame( Connection::STATE_DISCONNECTED, $this->connection->state(), 'Cleared locally either way.' );
		$warning = WP_CLI::$output[ count( WP_CLI::$output ) - 2 ];
		$this->assertSame( 'warning', $warning[0] );
		$this->assertStringContainsString( 'Revoke it in show.fm under Connected sites.', $warning[1] );
		$this->assertStringContainsString( 'https://my.show.fm/', $warning[1] );
		$this->assertSame( array( 'success', 'Disconnected here.' ), end( WP_CLI::$output ) );
	}

	public function test_disconnect_when_not_connected_is_a_no_op(): void {
		$this->cli->disconnect( array(), array() );

		$this->assertSame( 'success', WP_CLI::$output[0][0] );
	}

	public function test_each_network_site_registers_separately(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
		$blog_id = self::factory()->blog->create();
		$this->http->respond( 201, $this->registration_body() );
		$this->http->respond( 200, '{"data":{}}' );
		$this->cli->connect( array(), array( 'key' => self::KEY ) );

		switch_to_blog( $blog_id );
		try {
			WP_CLI::$output = array();
			$this->cli->status();
			$this->assertSame( array( array( 'line', 'State: not connected' ) ), WP_CLI::$output );

			$this->http->respond_with(
				function ( $args ) {
					$body = json_decode( $args['body'], true );
					$this->assertSame( home_url(), $body['site_url'], 'Site 2 registers its own address.' );
					return array( 201, $this->registration_body( '1b5d6f0e-1c2d-4e3f-8a9b-0c1d2e3f4a5b' ) );
				}
			);
			$this->http->respond( 200, '{"data":{}}' );
			$this->cli->connect( array(), array( 'key' => 'showfm_live_SITE2KEYabcdefghijklmnopqrs' ) );
			$this->assertSame( '1b5d6f0e-1c2d-4e3f-8a9b-0c1d2e3f4a5b', $this->connection->site_id() );
			$this->connection->disconnect();
		} finally {
			restore_current_blog();
		}
		$this->assertSame( self::SITE_ID, $this->connection->site_id() );
	}

	public function test_registers_the_command_under_wp_cli(): void {
		Cli::register( $this->cli );

		$this->assertSame( $this->cli, WP_CLI::$commands['showfm'] );
	}

	/**
	 * A successful registration response.
	 *
	 * @param string $site_id Site id.
	 */
	/**
	 * Commands with a given standard input and terminal.
	 *
	 * @param string $stdin       Stream for `--key=-`.
	 * @param bool   $interactive Whether a person is at the terminal.
	 */
	private function cli( string $stdin = 'php://stdin', bool $interactive = false ): Cli {
		return new Cli(
			new Connect( $this->connection, new Api_Client( $this->connection ) ),
			$this->connection,
			$stdin,
			static function () use ( $interactive ): bool {
				return $interactive;
			}
		);
	}

	/**
	 * Queues a successful registration and verify.
	 */
	private function respond_connected(): void {
		$this->http->respond( 201, $this->registration_body() );
		$this->http->respond( 200, '{"data":{"status":"active"}}' );
	}

	/**
	 * Warnings printed so far.
	 *
	 * @return string[]
	 */
	private function warnings(): array {
		$warnings = array();
		foreach ( WP_CLI::$output as $line ) {
			if ( 'warning' === $line[0] ) {
				$warnings[] = $line[1];
			}
		}
		return $warnings;
	}

	private function registration_body( string $site_id = self::SITE_ID ): string {
		return (string) wp_json_encode(
			array(
				'data' => array(
					'site_id'     => $site_id,
					'site_url'    => 'https://example.org',
					'status'      => 'awaiting_registration',
					'ping_secret' => self::SECRET,
					'expires_at'  => '2027-10-08T09:00:00.000Z',
				),
			)
		);
	}

	public function test_cache_flush_reports_the_version_with_a_persistent_object_cache(): void {
		set_transient( Cache::key( '/v1/episodes/11111111-2222-4333-8444-555555555555' ), array( 'state' => 'ok' ), HOUR_IN_SECONDS );
		$previous = wp_using_ext_object_cache( true );
		try {
			$this->cli->cache( array( 'flush' ), array() );
		} finally {
			// The global can start null, and passing null back changes nothing.
			wp_using_ext_object_cache( (bool) $previous );
		}
		$this->assertSame( array( 'success', 'Flushed the show.fm cache (cache version now ' . Cache::version() . ').' ), end( WP_CLI::$output ) );
	}

	public function test_cache_flush_removes_the_stored_entries_and_says_how_many(): void {
		$episode = Cache::key( '/v1/episodes/11111111-2222-4333-8444-555555555555' );
		$editor  = Cache::key( 'editor:public:/v1/podcasts/my-show' );
		set_transient( $episode, array( 'state' => 'ok' ), HOUR_IN_SECONDS );
		set_transient( $editor, array( 'type' => 'success' ), HOUR_IN_SECONDS );
		set_transient( 'showfm_editor_hold', 123, HOUR_IN_SECONDS );
		wp_cache_set( 'unrelated', 'kept', 'showfm-test' );
		$version = Cache::version();

		$this->cli->cache( array( 'flush' ), array() );

		$this->assertSame( array( 'success', 'Flushed the show.fm cache: removed 2 stored entries.' ), end( WP_CLI::$output ) );
		$this->assertSame( $version + 1, Cache::version() );
		$this->assertFalse( get_transient( $episode ) );
		$this->assertFalse( get_transient( $editor ) );
		// Only the cache: the editor's rate-limit hold and the object cache stay.
		$this->assertSame( 123, get_transient( 'showfm_editor_hold' ) );
		$this->assertSame( 'kept', wp_cache_get( 'unrelated', 'showfm-test' ) );
		$this->assertSame( 0, $this->http->count() );

		WP_CLI::$output = array();
		$this->cli->cache( array( 'flush' ), array() );
		$this->assertSame( array( 'success', 'Flushed the show.fm cache: removed 0 stored entries.' ), end( WP_CLI::$output ) );
	}

	public function test_cache_flush_leaves_a_site_that_never_cached_anything_untouched(): void {
		delete_option( Cache::NAMESPACE_OPTION );
		delete_option( Cache::VERSION_OPTION );

		$this->cli->cache( array( 'flush' ), array() );

		$this->assertSame( array( 'success', 'Flushed the show.fm cache: removed 0 stored entries.' ), end( WP_CLI::$output ) );
		$this->assertFalse( get_option( Cache::NAMESPACE_OPTION ) );
		$this->assertFalse( get_option( Cache::VERSION_OPTION ) );
	}

	public function test_cache_needs_the_flush_action(): void {
		foreach ( array( array(), array( 'clear' ), array( 'flush', 'now' ) ) as $args ) {
			$this->assert_halts(
				function () use ( $args ) {
					$this->cli->cache( $args, array() );
				}
			);
		}
	}

	public function test_cache_flush_network_flushes_every_site(): void {
		if ( ! is_multisite() ) {
			$this->assert_halts(
				function () {
					$this->cli->cache( array( 'flush' ), array( 'network' => true ) );
				}
			);
			$this->assertStringContainsString( 'not a multisite network', end( WP_CLI::$output )[1] );
			return;
		}
		$second = self::factory()->blog->create();
		$unused = self::factory()->blog->create();
		$keys   = array();
		foreach ( array( get_current_blog_id(), $second ) as $blog ) {
			switch_to_blog( $blog );
			$keys[ $blog ] = Cache::key( '/v1/episodes/11111111-2222-4333-8444-555555555555' );
			set_transient( $keys[ $blog ], array( 'state' => 'ok' ), HOUR_IN_SECONDS );
			restore_current_blog();
		}

		$this->cli->cache( array( 'flush' ), array( 'network' => true ) );

		foreach ( $keys as $blog => $key ) {
			switch_to_blog( $blog );
			$this->assertFalse( get_transient( $key ), "Site $blog" );
			restore_current_blog();
		}
		// A site where the plugin never cached anything gets no option written.
		switch_to_blog( $unused );
		$this->assertFalse( get_option( Cache::VERSION_OPTION ) );
		$this->assertFalse( get_option( Cache::NAMESPACE_OPTION ) );
		restore_current_blog();
		$sites = count(
			get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			)
		);
		$this->assertSame( array( 'success', "Flushed the show.fm cache on $sites sites: removed 2 stored entries." ), end( WP_CLI::$output ) );
		$this->assertSame( 0, $this->http->count() );
	}

	/**
	 * Asserts a command stops where WP-CLI would exit.
	 *
	 * @param callable $command Command.
	 */
	private function assert_halts( callable $command ): void {
		try {
			$command();
		} catch ( ShowFM_Cli_Halt $halt ) {
			$this->assertNotSame( '', $halt->getMessage() );
			return;
		}
		$this->fail( 'The command should have stopped.' );
	}

	/**
	 * Makes the next disconnect lose its lease straight after the swap, as a slow request
	 * would. Returns the hook to remove afterwards.
	 */
	private function lose_lease_after_swap(): callable {
		add_filter( 'showfm_sync_use_named_lock', '__return_false' );
		$expire = static function ( string $option ): void {
			if ( Connection::OPTION !== $option ) {
				return;
			}
			global $wpdb;
			$row      = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Connection::LOCK_OPTION ) );
			$parts    = explode( '|', $row );
			$parts[1] = (string) ( time() - 1 );
			$wpdb->update( $wpdb->options, array( 'option_value' => implode( '|', $parts ) ), array( 'option_name' => Connection::LOCK_OPTION ) );
			wp_cache_delete( Connection::LOCK_OPTION, 'options' );
		};
		add_action( 'updated_option', $expire );
		return $expire;
	}

	/**
	 * Undoes `lose_lease_after_swap()`.
	 *
	 * @param callable $expire The hook it returned.
	 */
	private function keep_lease( callable $expire ): void {
		remove_action( 'updated_option', $expire );
		remove_filter( 'showfm_sync_use_named_lock', '__return_false' );
		delete_option( Connection::LOCK_OPTION );
	}
}
