<?php
/**
 * Daily health report, and keyed calls after a 401 or a 429.
 *
 * @package ShowFM
 */

use ShowFM\Api_Client;
use ShowFM\Api_Result;
use ShowFM\Connect;
use ShowFM\Connection;
use ShowFM\Health;
use ShowFM\Plugin;

/**
 * Health tests.
 */
class Test_Health extends WP_UnitTestCase {

	const SITE_ID = '0b5d6f0e-1c2d-4e3f-8a9b-0c1d2e3f4a5b';
	const KEY     = 'showfm_live_HEALTHKEYabcdefghijklmnopqrs';

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
	 * Reporter under test.
	 *
	 * @var Health
	 */
	private $health;

	public function set_up(): void {
		parent::set_up();
		$this->http       = new ShowFM_Http_Mock();
		$this->connection = new Connection();
		$this->connection->disconnect();
		$this->assertTrue( $this->connection->save( self::KEY, str_repeat( 'c', 64 ), self::SITE_ID, 0 ) );
		$client       = new Api_Client( $this->connection );
		$this->health = new Health( $this->connection, $client, new Connect( $this->connection, $client ) );
		delete_option( Api_Client::RATE_LIMIT_OPTION );
		delete_option( Connect::VERIFY_PENDING_OPTION );
		delete_option( Health::LAST_SYNC_OPTION );
		delete_option( Health::SYNC_ERRORS_OPTION );
		wp_clear_scheduled_hook( Health::HOOK );
	}

	public function tear_down(): void {
		$this->http->detach();
		$this->connection->disconnect();
		delete_option( Api_Client::RATE_LIMIT_OPTION );
		wp_clear_scheduled_hook( Health::HOOK );
		parent::tear_down();
	}

	public function test_report_posts_versions_last_sync_and_errors(): void {
		update_option( Health::LAST_SYNC_OPTION, 1791363600 );
		update_option( Health::SYNC_ERRORS_OPTION, 3 );
		$this->http->respond( 200, '{"data":{"recorded":true}}' );

		$result = $this->health->report();

		$this->assertTrue( $result->is( Api_Result::SUCCESS ) );
		$request = $this->http->last();
		$this->assertSame( 'https://api.show.fm/v1/me/sites/' . self::SITE_ID . '/health', $request['url'] );
		$this->assertSame( 'POST', $request['args']['method'] );
		$this->assertSame( 'Bearer ' . self::KEY, $request['args']['headers']['Authorization'] );
		$this->assertSame(
			array(
				'plugin_version'   => SHOWFM_VERSION,
				'wp_version'       => get_bloginfo( 'version' ),
				'php_version'      => Health::versions()['php_version'],
				'last_sync_at'     => '2026-10-07T09:00:00Z',
				'sync_error_count' => 3,
			),
			json_decode( $request['args']['body'], true )
		);
	}

	public function test_never_synced_sends_null_and_zero(): void {
		$payload = Health::payload();

		$this->assertNull( $payload['last_sync_at'] );
		$this->assertSame( 0, $payload['sync_error_count'] );
	}

	public function test_versions_fit_the_api_pattern(): void {
		add_filter(
			'bloginfo',
			static function ( $value, $show ) {
				return 'version' === $show ? '7.0-beta1-<script>-' . str_repeat( '9', 40 ) : $value;
			},
			10,
			2
		);

		foreach ( Health::versions() as $version ) {
			$this->assertMatchesRegularExpression( '/^[0-9A-Za-z.+_ -]{1,32}$/', $version );
		}
	}

	public function test_does_nothing_when_not_connected(): void {
		$this->connection->disconnect();

		$this->assertNull( $this->health->report() );
		$this->assertSame( 0, $this->http->count() );
	}

	public function test_a_pending_verify_runs_first(): void {
		update_option( Connect::VERIFY_PENDING_OPTION, 1 );
		$this->http->respond( 200, '{"data":{}}' );
		$this->http->respond( 200, '{"data":{}}' );

		$this->health->report();

		$this->assertSame( 2, $this->http->count() );
		$this->assertStringEndsWith( '/verify', $this->http->requests[0]['url'] );
		$this->assertStringEndsWith( '/health', $this->http->requests[1]['url'] );
		$this->assertFalse( Connect::verify_pending() );
	}

	public function test_401_marks_reconnect_needed_and_stops_keyed_calls(): void {
		$this->http->respond( 401, '{"error":{"code":"unauthorised","message":"Invalid key."}}' );

		$this->assertTrue( $this->health->report()->is( Api_Result::UNAUTHORISED ) );
		$this->assertSame( Connection::STATE_RECONNECT_NEEDED, $this->connection->state() );

		$this->assertNull( $this->health->report() );
		$client = new Api_Client( $this->connection );
		$this->assertTrue( $client->post_keyed( '/v1/me/sites/' . self::SITE_ID . '/health', array() )->is( Api_Result::UNAUTHORISED ) );
		$this->assertTrue( $client->get_keyed( '/v1/me/sites/' . self::SITE_ID . '/changes' )->is( Api_Result::UNAUTHORISED ) );
		$this->assertSame( 1, $this->http->count(), 'No keyed call is sent after a 401.' );
	}

	public function test_429_holds_keyed_calls_for_retry_after(): void {
		$this->http->respond( 429, '', array( 'Retry-After' => '120' ) );

		$result = $this->health->report();
		$this->assertTrue( $result->is( Api_Result::RATE_LIMITED ) );
		$this->assertSame( 120, $result->retry_after() );
		$this->assertEqualsWithDelta( 120, Api_Client::rate_limit_remaining(), 2 );

		$held = $this->health->report();
		$this->assertTrue( $held->is( Api_Result::RATE_LIMITED ) );
		$this->assertGreaterThan( 0, $held->retry_after() );
		$this->assertSame( 1, $this->http->count(), 'Nothing is sent before Retry-After has passed.' );
		$this->assertSame( Connection::STATE_CONNECTED, $this->connection->state() );

		update_option( Api_Client::RATE_LIMIT_OPTION, time() - 1 );
		$this->http->respond( 200, '{"data":{}}' );
		$this->assertTrue( $this->health->report()->is( Api_Result::SUCCESS ) );
		$this->assertSame( 2, $this->http->count() );
	}

	public function test_cron_event_runs_the_report(): void {
		$this->http->respond( 200, '{"data":{}}' );

		do_action( Health::HOOK );

		$this->assertSame( 1, $this->http->count() );
		$this->assertStringEndsWith( '/health', $this->http->last()['url'] );
	}

	public function test_schedule_is_daily_and_not_duplicated(): void {
		Health::schedule();
		Health::schedule();

		$this->assertSame( 'daily', wp_get_schedule( Health::HOOK ) );
		$count = 0;
		foreach ( _get_cron_array() as $events ) {
			$count += isset( $events[ Health::HOOK ] ) ? count( $events[ Health::HOOK ] ) : 0;
		}
		$this->assertSame( 1, $count );
	}

	public function test_ensure_scheduled_only_when_connected(): void {
		$this->health->ensure_scheduled();
		$this->assertNotFalse( wp_next_scheduled( Health::HOOK ) );

		wp_clear_scheduled_hook( Health::HOOK );
		$this->connection->disconnect();
		$this->health->ensure_scheduled();
		$this->assertFalse( wp_next_scheduled( Health::HOOK ) );
		$this->assertSame( 0, $this->http->count() );
	}

	public function test_deactivation_clears_the_new_events(): void {
		Health::schedule();
		wp_schedule_single_event( time(), 'showfm_pull' );

		Plugin::deactivate();

		$this->assertFalse( wp_next_scheduled( Health::HOOK ) );
		$this->assertFalse( wp_next_scheduled( 'showfm_pull' ) );
	}
}
