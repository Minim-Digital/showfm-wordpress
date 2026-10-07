<?php
/**
 * Api_Client result typing and key handling.
 *
 * @package ShowFM
 */

use ShowFM\Api_Client;
use ShowFM\Api_Result;
use ShowFM\Connection;

/**
 * Api_Client tests.
 */
class Test_Api_Client extends WP_UnitTestCase {

	const KEY = 'showfm_live_SECRETKEYabcdefghijklmnop1234';

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
	 * Client under test.
	 *
	 * @var Api_Client
	 */
	private $client;

	/**
	 * Error log captured during the test.
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
		$this->assertTrue( $this->connection->save( self::KEY, 'ping-secret-value-0001', 'site-123', 0 ) );
		$this->client = new Api_Client( $this->connection );

		$this->log_file     = wp_tempnam( 'showfm-log' );
		$this->previous_log = ini_set( 'error_log', $this->log_file );
	}

	public function tear_down(): void {
		ini_set( 'error_log', (string) $this->previous_log );
		$log = (string) file_get_contents( $this->log_file );
		unlink( $this->log_file );
		$this->http->detach();
		$this->connection->disconnect();
		parent::tear_down();
		$this->assertStringNotContainsString( self::KEY, $log, 'The key must never be logged.' );
	}

	public function test_200_is_success_with_data_and_etag(): void {
		$this->http->respond( 200, '{"id":"ep-1","title":"Hello"}', array( 'ETag' => '"v1"' ) );

		$result = $this->client->get( '/v1/episodes/ep-1' );

		$this->assertSame( Api_Result::SUCCESS, $result->type() );
		$this->assertSame( 200, $result->status() );
		$this->assertSame(
			array(
				'id'    => 'ep-1',
				'title' => 'Hello',
			),
			$result->data()
		);
		$this->assertSame( '"v1"', $result->etag() );
		$this->assertNoKeyIn( $result );
	}

	public function test_requests_use_safe_http_with_timeout_and_user_agent(): void {
		$this->http->respond( 200, '{}' );

		$this->client->get( '/v1/episodes/ep-1', '"v0"' );

		$request = $this->http->last();
		$this->assertSame( 'https://api.show.fm/v1/episodes/ep-1', $request['url'] );
		$this->assertSame( 'GET', $request['args']['method'] );
		$this->assertTrue( $request['args']['reject_unsafe_urls'] );
		$this->assertSame( 5, $request['args']['timeout'] );
		$this->assertSame( 0, $request['args']['redirection'] );
		$this->assertSame( 'showfm-wordpress/' . SHOWFM_VERSION . '; +' . home_url(), $request['args']['user-agent'] );
		$this->assertSame( '"v0"', $request['args']['headers']['If-None-Match'] );
		$this->assertArrayNotHasKey( 'Authorization', $request['args']['headers'], 'Public calls never send the key.' );
	}

	public function test_keyed_calls_send_the_key_as_a_bearer_token_only(): void {
		$this->http->respond( 200, '{"ok":true}' );

		$result = $this->client->post_keyed( '/v1/me/sites/site-123/health', array( 'version' => SHOWFM_VERSION ) );

		$request = $this->http->last();
		$this->assertSame( Api_Result::SUCCESS, $result->type() );
		$this->assertSame( 'POST', $request['args']['method'] );
		$this->assertSame( 'Bearer ' . self::KEY, $request['args']['headers']['Authorization'] );
		$this->assertStringNotContainsString( self::KEY, $request['url'] );
		$this->assertStringNotContainsString( self::KEY, $request['args']['body'] );
		$this->assertTrue( $request['args']['reject_unsafe_urls'] );
	}

	public function test_304_is_not_modified_and_keeps_the_etag(): void {
		$this->http->respond( 304 );

		$result = $this->client->get( '/v1/episodes/ep-1', '"v1"' );

		$this->assertSame( Api_Result::NOT_MODIFIED, $result->type() );
		$this->assertSame( '"v1"', $result->etag() );
		$this->assertNull( $result->data() );
	}

	public function test_401_sets_reconnect_needed_and_stops_all_keyed_calls(): void {
		$this->http->respond( 401, '{"error":"unauthorized"}' );

		$result = $this->client->get_keyed( '/v1/me/sites/site-123/changes?after=0' );

		$this->assertSame( Api_Result::UNAUTHORISED, $result->type() );
		$this->assertSame( Connection::STATE_RECONNECT_NEEDED, $this->connection->state() );
		$this->assertNull( $this->connection->key() );
		$this->assertSame( 1, $this->http->count() );

		$again = $this->client->get_keyed( '/v1/me/sites/site-123/changes?after=0' );
		$post  = $this->client->post_keyed( '/v1/me/sites/site-123/health', array() );

		$this->assertSame( Api_Result::UNAUTHORISED, $again->type() );
		$this->assertSame( Api_Result::UNAUTHORISED, $post->type() );
		$this->assertSame( 1, $this->http->count(), 'No keyed request may be sent after a 401.' );
		$this->assertNoKeyIn( $result );
		$this->assertNoKeyIn( $again );
	}

	public function test_403_and_404_are_authoritative_unavailable(): void {
		$this->http->respond( 403, '{"error":"unavailable"}' );
		$this->http->respond( 404, '{"error":"not_found"}' );

		$forbidden = $this->client->get( '/v1/episodes/private' );
		$missing   = $this->client->get( '/v1/episodes/deleted' );

		$this->assertSame( Api_Result::UNAVAILABLE, $forbidden->type() );
		$this->assertSame( 403, $forbidden->status() );
		$this->assertSame( Api_Result::UNAVAILABLE, $missing->type() );
		$this->assertSame( 404, $missing->status() );
	}

	public function test_429_honours_retry_after_seconds(): void {
		$this->http->respond( 429, '', array( 'Retry-After' => '120' ) );

		$result = $this->client->get( '/v1/episodes/ep-1' );

		$this->assertSame( Api_Result::RATE_LIMITED, $result->type() );
		$this->assertSame( 120, $result->retry_after() );
	}

	public function test_429_honours_retry_after_http_date_and_bounds(): void {
		$this->http->respond( 429, '', array( 'Retry-After' => gmdate( 'D, d M Y H:i:s', time() + 300 ) . ' GMT' ) );
		$this->http->respond( 429 );

		$dated   = $this->client->get( '/v1/episodes/ep-1' );
		$missing = $this->client->get( '/v1/episodes/ep-1' );

		$this->assertEqualsWithDelta( 300, $dated->retry_after(), 2 );
		$this->assertSame( Api_Client::DEFAULT_RETRY_AFTER, $missing->retry_after() );
		$this->assertSame( Api_Client::MAX_RETRY_AFTER, Api_Client::parse_retry_after( '999999' ) );
		$this->assertSame( 1, Api_Client::parse_retry_after( '0' ) );
	}

	public function test_500_is_transient_failure(): void {
		$this->http->respond( 500, 'Internal error' );
		$this->http->respond( 503 );

		$this->assertSame( Api_Result::TRANSIENT_FAILURE, $this->client->get( '/v1/episodes/ep-1' )->type() );
		$this->assertSame( Api_Result::TRANSIENT_FAILURE, $this->client->get( '/v1/episodes/ep-1' )->type() );
	}

	public function test_network_error_is_transient_failure_and_never_echoes_the_key(): void {
		$this->http->fail( 'cURL error 28: timed out sending Authorization: Bearer ' . self::KEY );

		$result = $this->client->get_keyed( '/v1/me/sites/site-123/changes' );

		$this->assertSame( Api_Result::TRANSIENT_FAILURE, $result->type() );
		$this->assertSame( 0, $result->status() );
		$this->assertStringContainsString( 'timed out', $result->message() );
		$this->assertNoKeyIn( $result );
		$this->assertSame( Connection::STATE_CONNECTED, $this->connection->state(), 'A network error must not disconnect.' );
	}

	public function test_body_that_is_not_json_fails(): void {
		$this->http->respond( 200, '<html>' );

		$this->assertSame( Api_Result::FAILED, $this->client->get( '/v1/episodes/ep-1' )->type() );
	}

	public function test_paths_cannot_change_the_host(): void {
		foreach ( array( '//evil.example/x', 'https://evil.example/x', 'v1/episodes', "/v1/\nx" ) as $path ) {
			$result = $this->client->get_keyed( $path );
			$this->assertSame( Api_Result::FAILED, $result->type(), $path );
		}
		$this->assertSame( 0, $this->http->count() );
	}

	public function test_base_url_defaults_to_production(): void {
		$this->assertSame( 'https://api.show.fm', Api_Client::base_url() );
	}

	public function test_base_url_accepts_only_https_show_fm_api_hosts(): void {
		$this->assertSame( 'https://api.showfm.dev', Api_Client::sanitize_base_url( 'https://api.showfm.dev' ) );
		$this->assertSame( 'https://api.showfm.dev', Api_Client::sanitize_base_url( 'https://API.showfm.dev/' ) );
		$this->assertSame( 'https://api.show.fm', Api_Client::sanitize_base_url( 'https://api.show.fm' ) );
		foreach ( array( 'http://api.showfm.dev', 'https://evil.example', 'https://api.showfm.dev:8443', 'https://api.showfm.dev/v1', 'https://user:pass@api.showfm.dev', 42 ) as $bad ) {
			$this->assertSame( Api_Client::DEFAULT_BASE_URL, Api_Client::sanitize_base_url( $bad ) );
		}
	}

	public function test_the_key_is_not_in_dumps_of_the_connection(): void {
		$this->assertStringNotContainsString( self::KEY, print_r( $this->connection, true ) );
		ob_start();
		var_dump( $this->connection );
		$this->assertStringNotContainsString( self::KEY, (string) ob_get_clean() );
		$this->assertStringNotContainsString( self::KEY, print_r( $this->client, true ) );
	}

	/**
	 * Asserts the key appears nowhere in a result.
	 *
	 * @param Api_Result $result Result.
	 */
	private function assertNoKeyIn( Api_Result $result ): void {
		$this->assertStringNotContainsString( self::KEY, $result->message() );
		$this->assertStringNotContainsString( self::KEY, serialize( $result ) );
		$this->assertStringNotContainsString( self::KEY, print_r( $result, true ) );
		$this->assertStringNotContainsString( self::KEY, (string) wp_json_encode( $result->data() ) );
	}
}
