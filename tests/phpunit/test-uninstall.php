<?php
/**
 * Uninstall and deactivation remove only the plugin's own data.
 *
 * @package ShowFM
 */

use ShowFM\Api_Client;
use ShowFM\Cache;
use ShowFM\Connection;
use ShowFM\Plugin;

/**
 * Uninstall tests.
 */
class Test_Uninstall extends WP_UnitTestCase {

	/**
	 * Post that must survive.
	 *
	 * @var int
	 */
	private $post_id;

	public function set_up(): void {
		parent::set_up();

		( new Connection() )->save( 'showfm_live_uninstalltestkey000000001', 'ping-secret-uninstall', 'site-1', 0 );
		Cache::ensure_version();
		set_transient( Cache::key( '/v1/episodes/a' ), array( 'state' => 'ok' ), HOUR_IN_SECONDS );
		wp_schedule_single_event( time() + 60, Cache::REFRESH_HOOK, array( '/v1/episodes/a' ) );

		$this->post_id = self::factory()->post->create( array( 'post_title' => 'An episode post' ) );
		update_post_meta( $this->post_id, '_showfm_episode_id', 'ep-1' );
		update_post_meta( $this->post_id, '_showfm_content_hash', 'abc' );
		update_post_meta( $this->post_id, 'unrelated_meta', 'keep me' );
		update_post_meta( $this->post_id, 'showfm_without_underscore', 'not ours' );

		update_option( 'unrelated_option', 'keep me' );
		update_option( 'notshowfm_option', 'keep me' );
		set_transient( 'unrelated_transient', 'keep me', HOUR_IN_SECONDS );
		wp_schedule_single_event( time() + 60, 'unrelated_event' );
	}

	public function test_uninstall_removes_only_the_plugins_data(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', plugin_basename( SHOWFM_FILE ) );
		}

		include SHOWFM_DIR . '/uninstall.php';

		// The plugin's data is gone.
		$this->assertFalse( get_option( Connection::OPTION ) );
		$this->assertFalse( get_option( Cache::VERSION_OPTION ) );
		$this->assertFalse( get_option( '_transient_' . Cache::key( '/v1/episodes/a' ) ) );
		$this->assertFalse( get_option( '_transient_timeout_' . Cache::key( '/v1/episodes/a' ) ) );
		$this->assertFalse( wp_next_scheduled( Cache::REFRESH_HOOK, array( '/v1/episodes/a' ) ) );
		$this->assertSame( '', get_post_meta( $this->post_id, '_showfm_episode_id', true ) );
		$this->assertSame( '', get_post_meta( $this->post_id, '_showfm_content_hash', true ) );

		// The post and everything that is not the plugin's survive.
		$post = get_post( $this->post_id );
		$this->assertInstanceOf( WP_Post::class, $post );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( 'An episode post', $post->post_title );
		$this->assertSame( 'keep me', get_post_meta( $this->post_id, 'unrelated_meta', true ) );
		$this->assertSame( 'not ours', get_post_meta( $this->post_id, 'showfm_without_underscore', true ) );
		$this->assertSame( 'keep me', get_option( 'unrelated_option' ) );
		$this->assertSame( 'keep me', get_option( 'notshowfm_option' ) );
		$this->assertSame( 'keep me', get_transient( 'unrelated_transient' ) );
		$this->assertIsInt( wp_next_scheduled( 'unrelated_event' ) );
	}

	public function test_uninstall_cleans_every_site_on_a_network(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Runs with phpunit-multisite.xml.dist.' );
		}

		$blog_id = self::factory()->blog->create();
		switch_to_blog( $blog_id );
		( new Connection() )->save( 'showfm_live_secondsitekey00000000001', 'ping-secret-second', 'site-2', 0 );
		$second_post = self::factory()->post->create();
		update_post_meta( $second_post, '_showfm_episode_id', 'ep-2' );
		restore_current_blog();

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', plugin_basename( SHOWFM_FILE ) );
		}
		include SHOWFM_DIR . '/uninstall.php';

		$this->assertFalse( get_option( Connection::OPTION ) );
		switch_to_blog( $blog_id );
		$this->assertFalse( get_option( Connection::OPTION ) );
		$this->assertSame( '', get_post_meta( $second_post, '_showfm_episode_id', true ) );
		$this->assertInstanceOf( WP_Post::class, get_post( $second_post ) );
		restore_current_blog();
	}

	public function test_deactivation_only_unschedules_events(): void {
		Plugin::deactivate();

		$this->assertFalse( wp_next_scheduled( Cache::REFRESH_HOOK, array( '/v1/episodes/a' ) ) );
		$this->assertIsInt( wp_next_scheduled( 'unrelated_event' ) );
		$this->assertSame( Connection::STATE_CONNECTED, ( new Connection() )->state() );
		$this->assertSame( 'ep-1', get_post_meta( $this->post_id, '_showfm_episode_id', true ) );
		$this->assertIsArray( get_transient( Cache::key( '/v1/episodes/a' ) ) );
		$this->assertInstanceOf( Api_Client::class, Plugin::api_client() );
	}
}
