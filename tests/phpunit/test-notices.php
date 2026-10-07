<?php
/**
 * Admin notices: who sees them, where, which one, and dismissal.
 *
 * @package ShowFM
 */

use ShowFM\Connect;
use ShowFM\Connection;
use ShowFM\Notices;
use ShowFM\Plugin;

/**
 * Notice tests.
 */
class Test_Notices extends WP_UnitTestCase {

	const SITE_ID = '0b5d6f0e-1c2d-4e3f-8a9b-0c1d2e3f4a5b';

	/**
	 * The admin.
	 *
	 * @var int
	 */
	private $admin;

	/**
	 * Notices under test.
	 *
	 * @var Notices
	 */
	private $notices;

	public function set_up(): void {
		parent::set_up();
		Plugin::connect()->disconnect();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $this->admin );
		}
		wp_set_current_user( $this->admin );
		$this->notices = new Notices( Plugin::connection() );
	}

	public function tear_down(): void {
		Plugin::connect()->disconnect();
		delete_option( Connection::SYNC_STATUS_OPTION );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	public function test_nothing_while_not_connected(): void {
		$this->assertSame( array(), $this->notices->candidates() );
		$this->assertSame( '', $this->render_on( 'dashboard' ) );
	}

	public function test_nothing_for_a_healthy_connection(): void {
		$this->connect( time() + 200 * DAY_IN_SECONDS );

		$this->assertNull( $this->notices->current( $this->admin ) );
	}

	/**
	 * @dataProvider stages
	 *
	 * @param int    $days Days until expiry.
	 * @param string $kind Notice kind.
	 * @param string $text Notice text.
	 */
	public function test_expiry_stages( int $days, string $kind, string $text ): void {
		$expires = time() + $days * DAY_IN_SECONDS - HOUR_IN_SECONDS;
		$this->connect( $expires );

		$notice = $this->notices->current( $this->admin );

		$this->assertSame( $kind . ':' . $expires, $notice['key'] );
		$this->assertSame( 'warning', $notice['type'] );
		$this->assertSame( $text, $notice['text'] );
		$this->assertSame( 'Reconnect', $notice['action']['label'] );
		$this->assertSame( 'reconnect', $notice['action']['type'] );
		$this->assertSame( '', $notice['action']['url'], 'No nonce in a URL: Reconnect is a POST.' );
	}

	public function test_reconnect_is_a_post_form_with_a_nonce(): void {
		$this->connect( time() + 5 * DAY_IN_SECONDS );

		$html = $this->render_on( 'dashboard' );

		$this->assertMatchesRegularExpression( '#<form method="post" action="' . preg_quote( admin_url( 'admin-post.php' ), '#' ) . '">#', $html );
		$this->assertStringContainsString( 'name="action" value="showfm_connect"', $html );
		$this->assertMatchesRegularExpression( '/name="_wpnonce" value="([^"]+)"/', $html );
		preg_match( '/name="_wpnonce" value="([^"]+)"/', $html, $nonce );
		$this->assertSame( 1, wp_verify_nonce( $nonce[1], Connect::ACTION ) );
		$this->assertStringContainsString( '<button type="submit" class="button">Reconnect</button>', $html );
		$this->assertStringNotContainsString( '_wpnonce=', $html, 'The nonce is never in a URL.' );
		$this->assertStringNotContainsString( '<a class="button"', $html );
	}

	/**
	 * @return array<string,array{int,string,string}>
	 */
	public function stages(): array {
		return array(
			'30 days' => array( 30, 'expiry30', 'Your show.fm connection expires in 30 days.' ),
			'8 days'  => array( 8, 'expiry30', 'Your show.fm connection expires in 8 days.' ),
			'7 days'  => array( 7, 'expiry7', 'Your show.fm connection expires in 7 days.' ),
			'1 day'   => array( 1, 'expiry7', 'Your show.fm connection expires in 1 day.' ),
		);
	}

	public function test_refused_comes_first_and_is_an_error(): void {
		$this->connect( time() + 5 * DAY_IN_SECONDS );
		Plugin::connection()->mark_reconnect_needed();

		$notice = $this->notices->current( $this->admin );

		$this->assertSame( 'refused:' . Connection::refused_at(), $notice['key'] );
		$this->assertSame( 'error', $notice['type'] );
		$this->assertSame( 'Reconnect', $notice['action']['label'] );
		$this->assertCount( 1, $this->notices->candidates(), 'Nothing else applies while the key is refused.' );
	}

	public function test_expired_and_unreadable_connections_have_no_notice(): void {
		$this->connect( time() - DAY_IN_SECONDS );
		$this->assertSame( array(), $this->notices->candidates(), 'Expired.' );

		$this->connect( time() + 5 * DAY_IN_SECONDS );
		$stored      = get_option( Connection::OPTION );
		$stored['c'] = base64_encode( str_repeat( 'x', 80 ) );
		update_option( Connection::OPTION, $stored );
		$this->assertSame( array(), $this->notices->candidates(), 'Unreadable.' );
	}

	public function test_order_is_paused_then_sync_then_expiry(): void {
		$expires = time() + 5 * DAY_IN_SECONDS;
		$this->connect( $expires );
		update_option( Connection::PAUSED_OPTION, 1700000000 );
		$this->sync_problem( 'row_post_type' );

		$keys = wp_list_pluck( $this->notices->candidates(), 'key' );

		$this->assertSame( array( 'paused:1700000000', 'sync:row_post_type:' . self::SITE_ID, 'expiry7:' . $expires ), $keys );
		$paused = $this->notices->current( $this->admin );
		$this->assertSame( 'Check your plan', $paused['action']['label'] );
		$this->assertSame( 'https://my.show.fm/pricing', $paused['action']['url'] );
	}

	public function test_a_sync_problem_points_to_site_health(): void {
		$this->connect();
		$this->sync_problem( 'row_author' );

		$notice = $this->notices->current( $this->admin );

		$this->assertSame( admin_url( 'site-health.php' ), $notice['action']['url'] );
		$this->assertStringContainsString( 'author', $notice['text'] );
	}

	public function test_only_one_notice_is_printed_on_the_dashboard_and_plugins_screens(): void {
		$this->connect( time() + 5 * DAY_IN_SECONDS );
		update_option( Connection::PAUSED_OPTION, 1700000000 );

		foreach ( array( 'dashboard', 'plugins' ) as $screen ) {
			$html = $this->render_on( $screen );
			$this->assertSame( 1, substr_count( $html, 'class="notice ' ), $screen );
			$this->assertStringContainsString( 'data-showfm-notice="paused:1700000000"', $html );
			$this->assertStringContainsString( 'is-dismissible', $html );
			$this->assertStringContainsString( 'Auto-posting is paused.', $html );
			$this->assertSame( 1, substr_count( $html, 'class="button"' ), 'One action.' );
		}
		$this->assertTrue( wp_script_is( Notices::HANDLE, 'enqueued' ) || ! is_readable( SHOWFM_DIR . '/build/notices.asset.php' ) );
	}

	/**
	 * @dataProvider other_screens
	 *
	 * @param string $screen Screen id.
	 */
	public function test_not_printed_on_other_screens( string $screen ): void {
		$this->connect( time() + 5 * DAY_IN_SECONDS );

		$this->assertSame( '', $this->render_on( $screen ) );
	}

	/**
	 * @return array<string,array{string}>
	 */
	public function other_screens(): array {
		return array(
			'posts'                            => array( 'edit-post' ),
			'editor'                           => array( 'post' ),
			'the show.fm screen shows its own' => array( 'settings_page_showfm' ),
			'general settings'                 => array( 'options-general' ),
		);
	}

	public function test_the_dashboard_notice_reads_the_connection_once(): void {
		$this->connect( time() + 5 * DAY_IN_SECONDS );
		update_option( Connection::PAUSED_OPTION, 1700000000 );
		$reads = 0;
		$count = static function ( $value ) use ( &$reads ) {
			++$reads;
			return $value;
		};
		add_filter( 'option_' . Connection::OPTION, $count );

		$html = $this->render_on( 'dashboard' );

		remove_filter( 'option_' . Connection::OPTION, $count );
		$this->assertStringContainsString( 'paused:1700000000', $html );
		$this->assertSame( 1, $reads );
	}

	public function test_users_who_cannot_manage_options_see_nothing(): void {
		$this->connect( time() + 5 * DAY_IN_SECONDS );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( '', $this->render_on( 'dashboard' ) );
	}

	public function test_dismissing_hides_that_stage_for_that_user_only(): void {
		$expires = time() + 20 * DAY_IN_SECONDS;
		$this->connect( $expires );
		$other = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->assertTrue( Notices::dismiss( $this->admin, 'expiry30:' . $expires ) );

		$this->assertNull( $this->notices->current( $this->admin ) );
		$this->assertSame( 'expiry30:' . $expires, $this->notices->current( $other )['key'], 'Another admin still sees it.' );
	}

	public function test_the_seven_day_stage_shows_after_the_thirty_day_one_was_dismissed(): void {
		$expires = time() + 6 * DAY_IN_SECONDS;
		$this->connect( $expires );

		Notices::dismiss( $this->admin, 'expiry30:' . $expires );

		$this->assertSame( 'expiry7:' . $expires, $this->notices->current( $this->admin )['key'] );
	}

	public function test_a_new_key_brings_the_notice_back(): void {
		$expires = time() + 20 * DAY_IN_SECONDS;
		$this->connect( $expires );
		Notices::dismiss( $this->admin, 'expiry30:' . $expires );

		$this->connect( $expires + DAY_IN_SECONDS );

		$this->assertSame( 'expiry30:' . ( $expires + DAY_IN_SECONDS ), $this->notices->current( $this->admin )['key'] );
	}

	public function test_a_dismissed_higher_notice_lets_the_next_one_show(): void {
		$expires = time() + 5 * DAY_IN_SECONDS;
		$this->connect( $expires );
		update_option( Connection::PAUSED_OPTION, 1700000000 );

		Notices::dismiss( $this->admin, 'paused:1700000000' );

		$this->assertSame( 'expiry7:' . $expires, $this->notices->current( $this->admin )['key'] );
	}

	public function test_dismissals_are_bounded_and_validated(): void {
		$this->assertFalse( Notices::dismiss( $this->admin, 'bad key' ) );
		for ( $i = 0; $i < 60; $i++ ) {
			Notices::dismiss( $this->admin, 'expiry30:' . $i );
		}

		$stored = get_user_meta( $this->admin, Notices::META, true );
		$this->assertCount( Notices::MAX_DISMISSED, $stored );
		$this->assertSame( get_current_blog_id() . '|expiry30:59', end( $stored ) );
	}

	public function test_uninstall_removes_dismissals(): void {
		Notices::dismiss( $this->admin, 'expiry30:1' );

		ShowFM\Uninstaller::run();

		$this->assertSame( '', get_user_meta( $this->admin, Notices::META, true ) );
	}

	/**
	 * Stores a connection.
	 *
	 * @param int $expires Key expiry.
	 */
	private function connect( int $expires = 0 ): void {
		$this->assertTrue( Plugin::connection()->save( 'showfm_live_KEYabcdefghijklmnopqrst', str_repeat( 'a', 43 ), self::SITE_ID, $expires ? $expires : time() + 300 * DAY_IN_SECONDS ) );
	}

	/**
	 * Records a blocking sync configuration problem.
	 *
	 * @param string $code Reason code.
	 */
	private function sync_problem( string $code ): void {
		Plugin::connection()->save_sync_status(
			array(
				'site'    => self::SITE_ID,
				'retry'   => array(
					'seq'      => 3,
					'code'     => $code,
					'attempts' => 0,
					'at'       => time(),
				),
				'skipped' => array(),
			)
		);
	}

	/**
	 * The notice output on a screen.
	 *
	 * @param string $screen Screen id.
	 */
	private function render_on( string $screen ): string {
		set_current_screen( $screen );
		ob_start();
		$this->notices->render();
		return (string) ob_get_clean();
	}
}
