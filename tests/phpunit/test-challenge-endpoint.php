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

	public function test_no_open_flow_answers_404_without_counting(): void {
		update_option( Connect::CHALLENGE_OPEN_OPTION, time() - 1, false );

		for ( $i = 0; $i < Challenge_Endpoint::LIMIT + 5; $i++ ) {
			$this->assertSame( 404, $this->fetch( 'unknown-' . str_repeat( 'x', 40 ) )->get_status() );
		}
		$this->assertSame( 404, $this->fetch( $this->state )->get_status() );

		$this->assertSame( array(), $this->counter_rows(), 'Nothing is written.' );
	}

	public function test_starting_a_flow_opens_the_route_for_10_minutes(): void {
		$this->assertTrue( Connect::challenge_open() );
		$this->assertEqualsWithDelta( time() + Connect::FLOW_TTL, (int) get_option( Connect::CHALLENGE_OPEN_OPTION ), 5 );
	}

	public function test_wrong_states_share_one_global_budget(): void {
		for ( $i = 0; $i < Challenge_Endpoint::LIMIT; $i++ ) {
			$_SERVER['REMOTE_ADDR'] = '2001:db8::' . dechex( $i + 1 );
			$this->assertSame( 404, $this->fetch( 'unknown-' . str_repeat( 'x', 40 ) )->get_status() );
		}

		$_SERVER['REMOTE_ADDR'] = '198.51.100.9';
		$limited                = $this->fetch( 'unknown-' . str_repeat( 'y', 40 ) );
		$this->assertSame( 429, $limited->get_status(), 'A new address does not get a new budget.' );
		$this->assertArrayNotHasKey( 'code_challenge', $limited->get_data() );
		$retry = (int) $limited->get_headers()['Retry-After'];
		$this->assertGreaterThanOrEqual( 1, $retry );
		$this->assertLessThanOrEqual( Challenge_Endpoint::WINDOW, $retry );
		$this->assert_not_cacheable( $limited );
	}

	public function test_the_right_state_is_answered_even_when_the_budget_is_spent(): void {
		for ( $i = 0; $i <= Challenge_Endpoint::LIMIT; $i++ ) {
			$this->fetch( null );
		}
		$this->assertSame( 429, $this->fetch( null )->get_status() );

		$this->assertSame( 200, $this->fetch( $this->state )->get_status(), 'show.fm\'s own fetch is never held back.' );
	}

	public function test_rotating_addresses_add_no_rows(): void {
		global $wpdb;
		$this->fetch( null );
		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" );

		for ( $i = 0; $i < 40; $i++ ) {
			$_SERVER['REMOTE_ADDR'] = '2001:db8:' . dechex( $i + 1 ) . '::1';
			$this->fetch( null );
		}

		$this->assertSame( $before, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" ) );
	}

	public function test_a_new_window_starts_a_new_budget_and_drops_the_old_row(): void {
		$window = $this->window();
		$this->set_counter( $window - 1, Challenge_Endpoint::LIMIT );
		$this->set_counter( $window - 5, 3 );

		$this->assertSame( 404, $this->fetch( null )->get_status() );

		$this->assertSame( array( Challenge_Endpoint::COUNTER_PREFIX . $window => 1 ), $this->counter_rows() );
	}

	public function test_only_one_of_two_misses_takes_the_last_slot(): void {
		// Two requests racing for the last slot: the second sees the first one's UPDATE.
		$window = $this->window();
		$this->set_counter( $window, Challenge_Endpoint::LIMIT - 1 );
		// A stale cached copy must not matter: the count is never read through the options API.
		wp_cache_set( Challenge_Endpoint::COUNTER_PREFIX . $window, '0', 'options' );

		$statuses = array( $this->fetch( null )->get_status(), $this->fetch( null )->get_status() );

		$this->assertSame( array( 404, 429 ), $statuses );
		$this->assertSame( Challenge_Endpoint::LIMIT, $this->counter_rows()[ Challenge_Endpoint::COUNTER_PREFIX . $window ] );
	}

	public function test_the_count_never_passes_the_limit(): void {
		$window = $this->window();
		$this->set_counter( $window, Challenge_Endpoint::LIMIT );

		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertSame( 429, $this->fetch( null )->get_status() );
		}

		$this->assertSame( Challenge_Endpoint::LIMIT, $this->counter_rows()[ Challenge_Endpoint::COUNTER_PREFIX . $window ] );
	}

	public function test_a_failed_database_count_is_not_admitted(): void {
		$block = static function ( $query ) {
			return 0 === strpos( $query, 'UPDATE' ) && false !== strpos( $query, Challenge_Endpoint::COUNTER_PREFIX ) ? '' : $query;
		};
		add_filter( 'query', $block );

		$status = $this->fetch( null )->get_status();

		remove_filter( 'query', $block );
		$this->assertSame( 429, $status );
	}

	public function test_one_budget_across_the_window_with_or_without_an_object_cache(): void {
		$half = intdiv( Challenge_Endpoint::LIMIT, 2 );
		$this->set_counter( $this->window(), 0 );
		for ( $i = 0; $i < $half; $i++ ) {
			$this->assertSame( 404, $this->fetch( null )->get_status() );
		}

		$previous = wp_using_ext_object_cache( true );
		try {
			for ( $i = $half; $i < Challenge_Endpoint::LIMIT; $i++ ) {
				$this->assertSame( 404, $this->fetch( null )->get_status() );
			}
			$this->assertSame( 429, $this->fetch( null )->get_status(), 'An object cache does not start a second budget.' );
		} finally {
			wp_using_ext_object_cache( (bool) $previous );
		}

		$this->assertSame( 429, $this->fetch( null )->get_status() );
		$this->assertSame( Challenge_Endpoint::LIMIT, $this->counter_rows()[ Challenge_Endpoint::COUNTER_PREFIX . $this->window() ] );
	}

	public function test_a_delayed_cleanup_never_removes_a_newer_windows_row(): void {
		// This request computed window W just before a boundary; a request in W+1 has
		// already created and counted its row. Only rows older than W may go.
		$window = $this->window();
		$this->set_counter( $window + 1, 5 );
		$this->set_counter( $window - 2, 3 );

		$this->assertSame( 404, $this->fetch( null )->get_status() );

		$this->assertSame(
			array(
				Challenge_Endpoint::COUNTER_PREFIX . $window       => 1,
				Challenge_Endpoint::COUNTER_PREFIX . ( $window + 1 ) => 5,
			),
			$this->counter_rows()
		);
	}

	/**
	 * The current window number.
	 */
	private function window(): int {
		return (int) floor( time() / Challenge_Endpoint::WINDOW );
	}

	/**
	 * Writes a counter row straight to the database.
	 *
	 * @param int $window Window number.
	 * @param int $count  Misses.
	 */
	private function set_counter( int $window, int $count ): void {
		global $wpdb;
		$wpdb->replace(
			$wpdb->options,
			array(
				'option_name'  => Challenge_Endpoint::COUNTER_PREFIX . $window,
				'option_value' => (string) $count,
				'autoload'     => 'off',
			)
		);
	}

	/**
	 * Counter rows in the database, name => misses.
	 *
	 * @return array<string,int>
	 */
	private function counter_rows(): array {
		global $wpdb;
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name",
				$wpdb->esc_like( Challenge_Endpoint::COUNTER_PREFIX ) . '%'
			)
		);
		$counts = array();
		foreach ( $rows as $row ) {
			$counts[ $row->option_name ] = (int) $row->option_value;
		}
		return $counts;
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
