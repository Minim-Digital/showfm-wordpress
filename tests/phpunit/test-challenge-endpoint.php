<?php
/**
 * GET /wp-json/showfm/v1/challenge.
 *
 * @package ShowFM
 */

use ShowFM\Api_Client;
use ShowFM\Challenge_Endpoint;
use ShowFM\Connect;
use ShowFM\Connection;

/**
 * Challenge route tests.
 */
class Test_Challenge_Endpoint extends WP_UnitTestCase {

	/**
	 * State of the flow started in set_up.
	 *
	 * @var string
	 */
	private $state;

	/**
	 * Challenge of the flow started in set_up.
	 *
	 * @var string
	 */
	private $challenge;

	/**
	 * Verifier of the flow started in set_up.
	 *
	 * @var string
	 */
	private $verifier;

	public function set_up(): void {
		parent::set_up();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';

		$user_id    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$connection = new Connection();
		$connect    = new Connect( $connection, new Api_Client( $connection ) );
		parse_str( (string) wp_parse_url( $connect->start( $user_id ), PHP_URL_QUERY ), $query );
		$this->state     = $query['state'];
		$this->challenge = $query['code_challenge'];
		$this->verifier  = get_transient( Connect::FLOW_PREFIX . $user_id )['verifier'];

		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		parent::tear_down();
	}

	public function test_the_route_is_registered_as_public_get(): void {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/showfm/v1/challenge', $routes );
		$this->assertSame( array( 'GET' => true ), $routes['/showfm/v1/challenge'][0]['methods'] );
	}

	public function test_right_state_returns_only_the_challenge(): void {
		$response = $this->fetch( $this->state );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'code_challenge' => $this->challenge ), $response->get_data() );
		$encoded = (string) wp_json_encode( $response->get_data() );
		$this->assertStringNotContainsString( $this->verifier, $encoded );
		$this->assertStringNotContainsString( $this->state, $encoded );
		$this->assert_not_cacheable( $response );
	}

	public function test_the_challenge_can_be_fetched_again_while_the_flow_lives(): void {
		$this->assertSame( 200, $this->fetch( $this->state )->get_status() );
		$this->assertSame( 200, $this->fetch( $this->state )->get_status() );
	}

	/**
	 * @dataProvider wrong_states
	 *
	 * @param string|null $state State sent.
	 */
	public function test_wrong_or_missing_state_is_404( ?string $state ): void {
		$response = $this->fetch( $state );

		$this->assertSame( 404, $response->get_status() );
		$this->assertArrayNotHasKey( 'code_challenge', $response->get_data() );
		$this->assert_not_cacheable( $response );
	}

	/**
	 * @return array<string,array{0:string|null}>
	 */
	public function wrong_states(): array {
		return array(
			'missing'   => array( null ),
			'empty'     => array( '' ),
			'unknown'   => array( str_repeat( 'A', 43 ) ),
			'too short' => array( 'abc' ),
			'bad chars' => array( str_repeat( '%', 43 ) ),
		);
	}

	public function test_a_near_miss_of_the_state_is_404(): void {
		$near = substr( $this->state, 0, -1 ) . ( 'A' === substr( $this->state, -1 ) ? 'B' : 'A' );

		$this->assertSame( 404, $this->fetch( $near )->get_status() );
	}

	public function test_expired_state_is_404(): void {
		delete_transient( Connect::CHALLENGE_PREFIX . hash( 'sha256', $this->state ) );

		$this->assertSame( 404, $this->fetch( $this->state )->get_status() );
	}

	public function test_a_tampered_entry_is_404(): void {
		set_transient(
			Connect::CHALLENGE_PREFIX . hash( 'sha256', $this->state ),
			array(
				'hash'      => hash( 'sha256', 'something else' ),
				'challenge' => $this->challenge,
			),
			60
		);

		$this->assertSame( 404, $this->fetch( $this->state )->get_status() );
	}

	public function test_rate_limited_per_ip(): void {
		for ( $i = 0; $i < Challenge_Endpoint::LIMIT; $i++ ) {
			$this->assertSame( 404, $this->fetch( 'unknown-' . str_repeat( 'x', 40 ) )->get_status() );
		}

		$limited = $this->fetch( $this->state );
		$this->assertSame( 429, $limited->get_status(), 'Even the right state waits once the budget is spent.' );
		$this->assertArrayNotHasKey( 'code_challenge', $limited->get_data() );
		$this->assertSame( (string) Challenge_Endpoint::WINDOW, $limited->get_headers()['Retry-After'] );
		$this->assert_not_cacheable( $limited );

		$_SERVER['REMOTE_ADDR']          = '198.51.100.9';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.7';
		$this->assertSame( 200, $this->fetch( $this->state )->get_status(), 'Another address has its own budget.' );
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
	}

	public function test_forwarded_for_does_not_reset_the_budget(): void {
		for ( $i = 0; $i < Challenge_Endpoint::LIMIT; $i++ ) {
			$this->fetch( null );
		}
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '192.0.2.1';

		$this->assertSame( 429, $this->fetch( $this->state )->get_status() );
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
	}

	/**
	 * Dispatches the route.
	 *
	 * @param string|null $state State, or null to leave it out.
	 */
	private function fetch( ?string $state ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/showfm/v1/challenge' );
		if ( null !== $state ) {
			$request->set_query_params( array( 'state' => $state ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Asserts no proxy may keep the response.
	 *
	 * @param WP_REST_Response $response Response.
	 */
	private function assert_not_cacheable( WP_REST_Response $response ): void {
		$headers = $response->get_headers();
		$this->assertStringContainsString( 'no-store', $headers['Cache-Control'] );
		$this->assertStringContainsString( 'private', $headers['Cache-Control'] );
		$this->assertSame( 'no-cache', $headers['Pragma'] );
	}
}
