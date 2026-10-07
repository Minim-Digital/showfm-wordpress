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
use ShowFM\Uninstaller;

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
		$old_key = Cache::key( '/v1/episodes/a' );
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', plugin_basename( SHOWFM_FILE ) );
		}

		include SHOWFM_DIR . '/uninstall.php';

		// The plugin's data is gone.
		$this->assertFalse( get_option( Connection::OPTION ) );
		$this->assertFalse( get_option( Cache::VERSION_OPTION ) );
		$this->assertFalse( get_option( Cache::NAMESPACE_OPTION ) );
		$this->assertFalse( get_option( '_transient_' . $old_key ) );
		$this->assertFalse( get_option( '_transient_timeout_' . $old_key ) );
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

	/**
	 * @dataProvider cache_backends
	 * @param bool $external_cache Whether transients use the object cache.
	 */
	public function test_reinstall_cannot_read_entries_from_the_previous_install( bool $external_cache ): void {
		$previous_backend = wp_using_ext_object_cache();
		$http             = new ShowFM_Http_Mock();
		$path             = '/v1/episodes/before-uninstall';

		// Use WordPress's object cache as a drop-in double, retaining its entries across
		// uninstall/reinstall so the external-cache transient path behaves persistently.
		wp_using_ext_object_cache( $external_cache );
		try {
			$cache = new Cache( new Api_Client( new Connection() ) );
			$http->respond( 200, '{"title":"Before uninstall"}' );
			$cache->refresh( $path );
			$old_key       = Cache::key( $path );
			$old_namespace = get_option( Cache::NAMESPACE_OPTION );
			$old_entry     = get_transient( $old_key );
			$this->assertSame( array( 'title' => 'Before uninstall' ), $cache->get( $path ) );
			$this->assertSame( 1, Cache::version() );
			set_transient( 'unrelated_reinstall', 'keep me', HOUR_IN_SECONDS );

			Uninstaller::run();

			$this->assertFalse( get_option( Cache::NAMESPACE_OPTION ) );
			$this->assertFalse( get_option( '_transient_' . $old_key ) );
			$this->assertFalse( get_option( '_transient_timeout_' . $old_key ) );
			if ( $external_cache ) {
				$this->assertSame( $old_entry, wp_cache_get( $old_key, 'transient' ), 'The backend still holds the old entry.' );
			} else {
				$this->assertFalse( get_transient( $old_key ) );
			}

			Plugin::reset();
			Plugin::activate();
			$this->assertFalse( get_option( Cache::NAMESPACE_OPTION ), 'The namespace is created on first use.' );
			$this->assertNull( Plugin::cache()->get( $path ) );
			$this->assertSame( 1, Cache::version(), 'Reinstall resets the version.' );
			$this->assertNotSame( $old_namespace, get_option( Cache::NAMESPACE_OPTION ) );
			$this->assertNotSame( $old_key, Cache::key( $path ) );
			$this->assertSame( 'keep me', get_transient( 'unrelated_reinstall' ) );

			$http->respond( 200, '{"title":"After reinstall"}' );
			Plugin::cache()->refresh( $path );
			$this->assertSame( array( 'title' => 'After reinstall' ), Plugin::cache()->get( $path ) );
		} finally {
			wp_using_ext_object_cache( $previous_backend );
			$http->detach();
			Plugin::reset();
		}
	}

	public static function cache_backends(): array {
		return array(
			'options table'         => array( false ),
			'persistent cache path' => array( true ),
		);
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

	/**
	 * @dataProvider deactivation_scopes
	 * @param bool $network_wide Whether to deactivate across the network.
	 */
	public function test_deactivation_respects_network_scope( bool $network_wide ): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Runs with phpunit-multisite.xml.dist.' );
		}

		$original_blog = get_current_blog_id();
		$site_ids      = self::factory()->blog->create_many( 2 );
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );
			try {
				Cache::ensure_version();
				foreach ( Plugin::CRON_HOOKS as $hook ) {
					foreach ( array( '/v1/episodes/a', '/v1/episodes/b' ) as $path ) {
						$this->assertTrue( wp_schedule_single_event( time() + 60, $hook, array( $path ) ) );
					}
				}
				$this->assertTrue( wp_schedule_single_event( time() + 60, 'unrelated_event' ) );
			} finally {
				restore_current_blog();
			}
		}

		do_action( 'deactivate_' . plugin_basename( SHOWFM_FILE ), $network_wide );

		$this->assertSame( $original_blog, get_current_blog_id() );
		$this->assertFalse( ms_is_switched() );
		$this->assertFalse( wp_next_scheduled( Cache::REFRESH_HOOK, array( '/v1/episodes/a' ) ) );
		$this->assertSame( Connection::STATE_CONNECTED, ( new Connection() )->state() );
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );
			try {
				foreach ( Plugin::CRON_HOOKS as $hook ) {
					foreach ( array( '/v1/episodes/a', '/v1/episodes/b' ) as $path ) {
						$scheduled = wp_next_scheduled( $hook, array( $path ) );
						if ( $network_wide ) {
							$this->assertFalse( $scheduled );
						} else {
							$this->assertIsInt( $scheduled );
						}
					}
				}
				$this->assertIsInt( wp_next_scheduled( 'unrelated_event' ) );
				$this->assertSame( 1, Cache::version() );
			} finally {
				restore_current_blog();
			}
		}
	}

	public function test_network_deactivation_preserves_events_on_other_networks(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Runs with phpunit-multisite.xml.dist.' );
		}

		$original_blog   = get_current_blog_id();
		$current_network = get_current_network_id();
		$other_network   = self::factory()->network->create();
		$this->assertIsInt( $other_network );
		$this->assertNotSame( $current_network, $other_network );
		$current_site = self::factory()->blog->create( array( 'network_id' => $current_network ) );
		$other_site   = self::factory()->blog->create( array( 'network_id' => $other_network ) );
		$this->assertIsInt( $current_site );
		$this->assertIsInt( $other_site );
		$timestamp = time() + 60;
		$args      = array( '/v1/episodes/network-scope' );

		foreach ( array( $current_site, $other_site ) as $site_id ) {
			switch_to_blog( $site_id );
			try {
				foreach ( Plugin::CRON_HOOKS as $hook ) {
					$this->assertTrue( wp_schedule_single_event( $timestamp, $hook, $args ) );
				}
				$this->assertTrue( wp_schedule_single_event( $timestamp, 'unrelated_event' ) );
			} finally {
				restore_current_blog();
			}
		}

		do_action( 'deactivate_' . plugin_basename( SHOWFM_FILE ), true );

		$this->assertSame( $original_blog, get_current_blog_id() );
		$this->assertSame( $current_network, get_current_network_id() );
		$this->assertFalse( ms_is_switched() );
		$this->assertFalse( wp_next_scheduled( Cache::REFRESH_HOOK, array( '/v1/episodes/a' ) ) );
		foreach ( array( $current_site, $other_site ) as $site_id ) {
			switch_to_blog( $site_id );
			try {
				foreach ( Plugin::CRON_HOOKS as $hook ) {
					$this->assertSame( $current_site === $site_id ? false : $timestamp, wp_next_scheduled( $hook, $args ) );
				}
				$this->assertSame( $timestamp, wp_next_scheduled( 'unrelated_event' ) );
			} finally {
				restore_current_blog();
			}
		}
	}

	public static function deactivation_scopes(): array {
		return array(
			'network deactivation' => array( true ),
			'site deactivation'    => array( false ),
		);
	}
}
