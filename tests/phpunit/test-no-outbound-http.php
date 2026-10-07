<?php
/**
 * The plugin makes no outbound HTTP until a show is entered or a connection is made.
 *
 * @package ShowFM
 */

use ShowFM\Cache;
use ShowFM\Plugin;

/**
 * Zero-request guarantees (WordPress.org guideline 7).
 */
class Test_No_Outbound_Http extends WP_UnitTestCase {

	/**
	 * Spy.
	 *
	 * @var ShowFM_Http_Mock
	 */
	private $http;

	public function set_up(): void {
		parent::set_up();
		$this->http = new ShowFM_Http_Mock();
	}

	public function tear_down(): void {
		$this->http->detach();
		parent::tear_down();
	}

	public function test_loading_the_plugin_and_running_init_makes_no_requests(): void {
		$this->assertGreaterThan( 0, did_action( 'init' ) );
		$this->assertTrue( class_exists( Plugin::class, false ), 'The plugin should be loaded.' );
		$this->assertSame( array(), $GLOBALS['showfm_http_after_init'] );
	}

	public function test_activation_makes_no_requests_and_schedules_nothing(): void {
		delete_option( Cache::VERSION_OPTION );

		do_action( 'activate_' . plugin_basename( SHOWFM_FILE ) );

		$this->assertSame( 0, $this->http->count() );
		$this->assertSame( array(), $this->scheduled_plugin_events() );
		$this->assertSame( 1, Cache::version() );
	}

	public function test_front_end_page_with_no_show_configured_makes_no_requests(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'An ordinary post',
				'post_content' => '<!-- wp:paragraph --><p>Hello.</p><!-- /wp:paragraph -->',
			)
		);

		$this->go_to( home_url( '/' ) );
		$this->go_to( get_permalink( $post_id ) );
		do_action( 'template_redirect' );
		get_echo( 'wp_head' );
		apply_filters( 'the_content', get_post_field( 'post_content', $post_id ) );
		get_echo( 'wp_footer' );

		$this->assertSame( 0, $this->http->count() );
		$this->assertSame( array(), $GLOBALS['showfm_bootstrap_http'] );
		$this->assertSame( array(), $this->scheduled_plugin_events() );
	}

	/**
	 * Hooks of the plugin's scheduled events.
	 *
	 * @return string[]
	 */
	private function scheduled_plugin_events(): array {
		$hooks = array();
		foreach ( _get_cron_array() as $events ) {
			foreach ( array_keys( $events ) as $hook ) {
				if ( 0 === strpos( $hook, 'showfm_' ) ) {
					$hooks[] = $hook;
				}
			}
		}
		return $hooks;
	}
}
