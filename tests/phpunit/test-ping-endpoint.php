<?php
/**
 * POST /wp-json/showfm/v1/ping.
 *
 * @package ShowFM
 */

use ShowFM\Api_Client;
use ShowFM\Connect;
use ShowFM\Connection;
use ShowFM\Ping_Endpoint;

/**
 * Ping endpoint tests.
 */
class Test_Ping_Endpoint extends WP_UnitTestCase {

	const SITE_ID = '0b5d6f0e-1c2d-4e3f-8a9b-0c1d2e3f4a5b';
	const KEY     = 'showfm_live_PINGKEYabcdefghijklmnopqrstu';
	const SECRET  = '2c26b46b68ffc68ff99b453c1d30413413422d706483bfa0f98a5e886266e7ae';

	/**
	 * HTTP spy.
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
	 * Cron spawn requests seen through the `cron_request` filter.
	 *
	 * @var int
	 */
	private $spawned = 0;

	public function set_up(): void {
		parent::set_up();
		$this->http       = new ShowFM_Http_Mock();
		$this->connection = new Connection();
		$this->connection->disconnect();
		$this->assertTrue( $this->connection->save( self::KEY, self::SECRET, self::SITE_ID, 0 ) );
		wp_clear_scheduled_hook( Ping_Endpoint::PULL_HOOK );
		delete_transient( 'doing_cron' );
		delete_option( Ping_Endpoint::LAST_PING_OPTION );
		$this->spawned = 0;
		add_filter( 'cron_request', array( $this, 'count_spawn' ) );
	}

	public function tear_down(): void {
		remove_filter( 'cron_request', array( $this, 'count_spawn' ) );
		$this->http->detach();
		$this->connection->disconnect();
		wp_clear_scheduled_hook( Ping_Endpoint::PULL_HOOK );
		parent::tear_down();
	}

	/**
	 * Counts cron spawns.
	 *
	 * @param array<string,mixed> $request Cron request.
	 * @return array<string,mixed>
	 */
	public function count_spawn( $request ) {
		++$this->spawned;
		return $request;
	}

	public function test_valid_ping_schedules_a_pull_spawns_cron_and_answers_202(): void {
		$response = $this->ping( $this->signed() );

		$this->assertSame( 202, $response->get_status() );
		$this->assertNotFalse( wp_next_scheduled( Ping_Endpoint::PULL_HOOK ) );
		$this->assertLessThanOrEqual( time(), wp_next_scheduled( Ping_Endpoint::PULL_HOOK ) );
		$this->assertSame( 1, $this->spawned, 'spawn_cron() runs the pull now, not on the next page view.' );
		$this->assertEqualsWithDelta( time(), (int) get_option( Ping_Endpoint::LAST_PING_OPTION ), 5 );
		$this->assertSame( array(), $this->api_requests(), 'The ping fetches nothing inline.' );
	}

	public function test_five_pings_leave_one_scheduled_pull(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertSame( 202, $this->ping( $this->signed() )->get_status() );
		}

		$this->assertSame( 1, $this->count_pull_events() );
		$this->assertSame( array(), $this->api_requests() );
	}

	public function test_body_is_ignored(): void {
		$headers  = $this->signed();
		$response = $this->ping( $headers, '{"episode_id":"x","action":"delete","url":"https://evil.example"}' );

		$this->assertSame( 202, $response->get_status() );
		$this->assertNull( $response->get_data() );
		$this->assertSame( 1, $this->count_pull_events() );
		$this->assertSame( array(), $this->api_requests() );
	}

	public function test_wrong_secret_is_refused(): void {
		$this->assert_refused( $this->signed( array(), str_repeat( 'b', 64 ) ) );
	}

	public function test_the_api_key_used_as_the_hmac_key_is_refused(): void {
		$this->assert_refused( $this->signed( array(), self::KEY ) );
	}

	public function test_another_sites_id_is_refused(): void {
		$this->assert_refused( $this->signed( array( 'site' => '1b5d6f0e-1c2d-4e3f-8a9b-0c1d2e3f4a5b' ) ) );
	}

	public function test_site_id_case_does_not_matter(): void {
		$this->assertSame( 202, $this->ping( $this->signed( array( 'site' => strtoupper( self::SITE_ID ) ) ) )->get_status() );
	}

	/**
	 * @dataProvider timestamps
	 *
	 * @param int  $offset   Seconds from now.
	 * @param bool $accepted Whether the ping is accepted.
	 */
	public function test_timestamp_window_is_300_seconds( int $offset, bool $accepted ): void {
		$response = $this->ping( $this->signed( array( 'timestamp' => (string) ( time() + $offset ) ) ) );

		$this->assertSame( $accepted ? 202 : 401, $response->get_status() );
	}

	/**
	 * @return array<string,array{0:int,1:bool}>
	 */
	public function timestamps(): array {
		return array(
			'now'         => array( 0, true ),
			'299 s old'   => array( -299, true ),
			'299 s ahead' => array( 299, true ),
			'301 s old'   => array( -301, false ),
			'301 s ahead' => array( 301, false ),
			'an hour old' => array( -3600, false ),
		);
	}

	public function test_replayed_nonce_is_refused(): void {
		$headers = $this->signed();
		$this->assertSame( 202, $this->ping( $headers )->get_status() );

		$this->assert_refused( $headers );

		$again = $this->signed(
			array(
				'timestamp' => (string) ( time() + 1 ),
				'nonce'     => $headers['x-showfm-nonce'],
			)
		);
		$this->assert_refused( $again, 'A nonce is refused again even with a new timestamp.' );
	}

	public function test_nonces_are_remembered_for_10_minutes(): void {
		$headers = $this->signed();
		$this->ping( $headers );

		$row = $this->nonce_row( $headers['x-showfm-nonce'] );
		$this->assertNotNull( $row );
		$this->assertEqualsWithDelta( time() + 600, (int) $row->option_value, 5 );
		$this->assertSame( 'off', $row->autoload );
	}

	public function test_a_nonce_is_claimed_once(): void {
		$headers = $this->signed();
		$request = new WP_REST_Request( 'POST', '/showfm/v1/ping' );
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}
		$endpoint = new Ping_Endpoint( $this->connection );

		$this->assertTrue( $endpoint->verify( $request ) );
		$second = $endpoint->verify( $request );
		$this->assertWPError( $second );
		$this->assertSame( 'showfm_ping_refused', $second->get_error_code() );
	}

	public function test_a_claim_from_a_concurrent_request_is_refused(): void {
		global $wpdb;
		$headers = $this->signed();
		// Another request claimed the nonce after this one started: a row this request's
		// object cache has never seen. The old check-then-set let both through.
		$wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => $this->nonce_name( $headers['x-showfm-nonce'] ),
				'option_value' => (string) ( time() + 600 ),
				'autoload'     => 'off',
			)
		);

		$this->assert_refused( $headers );
	}

	public function test_expired_claims_are_removed_on_the_next_claim(): void {
		global $wpdb;
		$old = bin2hex( random_bytes( 16 ) );
		$wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => $this->nonce_name( $old ),
				'option_value' => (string) ( time() - 1 ),
				'autoload'     => 'off',
			)
		);

		$this->assertSame( 202, $this->ping( $this->signed() )->get_status() );

		$this->assertNull( $this->nonce_row( $old ) );
		$this->assertSame( 202, $this->ping( $this->signed( array( 'nonce' => $old ) ) )->get_status(), 'An expired claim does not block the nonce.' );
	}

	public function test_disconnect_forgets_the_nonces(): void {
		$headers = $this->signed();
		$this->ping( $headers );
		$this->assertNotNull( $this->nonce_row( $headers['x-showfm-nonce'] ) );

		( new Connect( $this->connection, new Api_Client( $this->connection ) ) )->disconnect();

		$this->assertNull( $this->nonce_row( $headers['x-showfm-nonce'] ) );
	}

	public function test_a_failed_claim_is_refused(): void {
		$block = static function ( $query ) {
			return 0 === strpos( $query, 'INSERT IGNORE' ) ? '' : $query;
		};
		add_filter( 'query', $block );

		$this->assert_refused( $this->signed() );
		remove_filter( 'query', $block );
	}

	public function test_a_refused_ping_does_not_spend_its_nonce(): void {
		$good = $this->signed();
		$bad  = $good;

		$bad['x-showfm-signature'] = 'v1=' . str_repeat( '0', 64 );
		$this->assert_refused( $bad );

		$this->assertSame( 202, $this->ping( $good )->get_status() );
	}

	/**
	 * @dataProvider headers
	 *
	 * @param string $header Header to drop.
	 */
	public function test_missing_header_is_refused( string $header ): void {
		$headers = $this->signed();
		unset( $headers[ $header ] );

		$this->assert_refused( $headers );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function headers(): array {
		return array(
			'site'      => array( 'x-showfm-site' ),
			'timestamp' => array( 'x-showfm-timestamp' ),
			'nonce'     => array( 'x-showfm-nonce' ),
			'signature' => array( 'x-showfm-signature' ),
		);
	}

	public function test_malformed_signature_is_refused(): void {
		$headers = $this->signed();

		$headers['x-showfm-signature'] = substr( $headers['x-showfm-signature'], 3 );
		$this->assert_refused( $headers, 'The v1= prefix is required.' );

		$headers                       = $this->signed();
		$headers['x-showfm-signature'] = 'v2=' . substr( $headers['x-showfm-signature'], 3 );
		$this->assert_refused( $headers, 'Only v1 is known.' );
	}

	public function test_refused_when_not_connected(): void {
		$headers = $this->signed();
		$this->connection->disconnect();

		$this->assert_refused( $headers );
	}

	public function test_refused_when_reconnect_is_needed(): void {
		$headers = $this->signed();
		$this->connection->mark_reconnect_needed();

		$this->assert_refused( $headers );
	}

	public function test_only_post_is_routed(): void {
		$routes = rest_get_server()->get_routes();
		$this->assertSame( array( 'POST' => true ), $routes['/showfm/v1/ping'][0]['methods'] );
	}

	/**
	 * Option name of a nonce claim for this site.
	 *
	 * @param string $nonce Nonce.
	 */
	private function nonce_name( string $nonce ): string {
		return Ping_Endpoint::NONCE_PREFIX . hash( 'sha256', self::SITE_ID . '.' . $nonce );
	}

	/**
	 * The claim row for a nonce, read straight from the database.
	 *
	 * @param string $nonce Nonce.
	 * @return object|null
	 */
	private function nonce_row( string $nonce ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $this->nonce_name( $nonce ) ) );
	}

	/**
	 * Headers for a correctly signed ping, with overrides.
	 *
	 * @param array<string,string> $override site, timestamp or nonce.
	 * @param string               $secret   Secret to sign with.
	 * @return array<string,string>
	 */
	private function signed( array $override = array(), string $secret = self::SECRET ): array {
		$site      = $override['site'] ?? self::SITE_ID;
		$timestamp = $override['timestamp'] ?? (string) time();
		$nonce     = $override['nonce'] ?? bin2hex( random_bytes( 16 ) );

		return array(
			'x-showfm-site'      => $site,
			'x-showfm-timestamp' => $timestamp,
			'x-showfm-nonce'     => $nonce,
			'x-showfm-signature' => 'v1=' . hash_hmac( 'sha256', "v1.{$site}.{$timestamp}.{$nonce}", $secret ),
		);
	}

	/**
	 * Dispatches a ping.
	 *
	 * @param array<string,string> $headers Headers.
	 * @param string               $body    Body.
	 */
	private function ping( array $headers, string $body = '' ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/showfm/v1/ping' );
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}
		if ( '' !== $body ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( $body );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Asserts a ping is refused with 401 and changes nothing.
	 *
	 * @param array<string,string> $headers Headers.
	 * @param string               $message Failure message.
	 */
	private function assert_refused( array $headers, string $message = '' ): void {
		$before   = $this->count_pull_events();
		$response = $this->ping( $headers );

		$this->assertSame( 401, $response->get_status(), $message );
		$this->assertSame( 'showfm_ping_refused', $response->get_data()['code'] );
		$this->assertSame( $before, $this->count_pull_events(), 'A refused ping schedules nothing.' );
	}

	/**
	 * Number of scheduled pull events.
	 */
	private function count_pull_events(): int {
		$count = 0;
		foreach ( _get_cron_array() as $events ) {
			if ( isset( $events[ Ping_Endpoint::PULL_HOOK ] ) ) {
				$count += count( $events[ Ping_Endpoint::PULL_HOOK ] );
			}
		}
		return $count;
	}

	/**
	 * Requests to the show.fm API (the cron spawn's loopback is not one).
	 *
	 * @return string[]
	 */
	private function api_requests(): array {
		$urls = array();
		foreach ( $this->http->requests as $request ) {
			if ( false === strpos( $request['url'], 'wp-cron.php' ) ) {
				$urls[] = $request['url'];
			}
		}
		return $urls;
	}
}
