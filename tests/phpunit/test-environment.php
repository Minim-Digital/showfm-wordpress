<?php
/**
 * The show.fm environment: production, or one the site's own code selects.
 *
 * @package ShowFM
 */

use ShowFM\Admin;
use ShowFM\Admin_Status;
use ShowFM\Api_Client;
use ShowFM\Assets;
use ShowFM\Attributes;
use ShowFM\Connect;
use ShowFM\Connection;
use ShowFM\Editor_Api;
use ShowFM\Environment;
use ShowFM\Notices;
use ShowFM\Oembed;
use ShowFM\Plugin;

/** Every show.fm host comes from Environment, and only the site's own code changes it. */
class Test_Environment extends WP_UnitTestCase {

	public function test_production_is_built_in(): void {
		$this->assertTrue( Environment::is_production() );
		$this->assertSame( 'https://api.show.fm', Api_Client::base_url() );
		$this->assertSame( 'https://my.show.fm', Connect::app_url() );
		$this->assertSame( array( 'show.fm' ), Attributes::listen_roots() );
		$this->assertSame( array( 'm.cdn.media', 'media.podcasterplus.com' ), Attributes::audio_hosts() );
		$this->assertSame( array( 'embed.cdn.media' ), Environment::embed_hosts() );
		$this->assertSame( 'https://the-long-table.show.fm', Attributes::show_listen_url( 'the-long-table' ) );
	}

	public function test_the_filter_selects_every_host(): void {
		showfm_use_test_environment();

		$this->assertFalse( Environment::is_production() );
		$this->assertSame( 'https://api.example.test', Api_Client::base_url() );
		$this->assertSame( 'https://my.example.test', Connect::app_url() );
		$this->assertSame( array( 'my.example.test' ), Admin::allow_app_host( array() ) );
		$this->assertSame( 'https://the-long-table.example.test', Attributes::show_listen_url( 'the-long-table' ) );
		// show.fm's own listen, media and embed hosts stay recognised alongside.
		$this->assertSame( array( 'show.fm', 'example.test' ), Attributes::listen_roots() );
		$this->assertSame( array( 'm.cdn.media', 'media.podcasterplus.com', 'm.example.test' ), Attributes::audio_hosts() );
		$this->assertSame( array( 'embed.cdn.media', 'embed.example.test' ), Environment::embed_hosts() );

		$snapshot = Attributes::snapshot(
			array(
				'listenUrl' => 'https://the-long-table.example.test/e/x',
				'audioUrl'  => 'https://m.example.test/x.mp3',
			)
		);
		$this->assertSame( 'https://the-long-table.example.test/e/x', $snapshot['listenUrl'] );
		$this->assertSame( 'https://m.example.test/x.mp3', $snapshot['audioUrl'] );

		$settings = Editor_Api::settings();
		$this->assertSame( 'https://api.example.test', $settings['api'] );
		$this->assertSame( array( 'show.fm', 'example.test' ), $settings['listenRoots'] );
		$this->assertSame( array( 'm.cdn.media', 'media.podcasterplus.com', 'm.example.test' ), $settings['mediaHosts'] );
	}

	public function test_without_the_filter_the_example_hosts_are_nothing_special(): void {
		$this->assertArrayNotHasKey( 'listenUrl', Attributes::snapshot( array( 'listenUrl' => 'https://the-long-table.example.test/e/x' ) ) );
		$this->assertArrayNotHasKey( 'audioUrl', Attributes::snapshot( array( 'audioUrl' => 'https://m.example.test/x.mp3' ) ) );
		$this->assertFalse( Oembed::names_show_fm( '<iframe src="https://embed.example.test/ep/x"></iframe>' ) );
	}

	public function test_hosts_are_normalised(): void {
		$environment          = showfm_test_environment();
		$environment['api']   = 'https://API.Example.Test/';
		$environment['media'] = array( 'M.Example.Test', 'm.example.test' );

		$valid = Environment::validate( $environment );

		$this->assertSame( 'https://api.example.test', $valid['api'] );
		$this->assertSame( array( 'm.example.test' ), $valid['media'] );
	}

	/**
	 * @return array<string,array{string,mixed}>
	 */
	public function invalid_parts(): array {
		return array(
			'no api'               => array( 'api', null ),
			'http api'             => array( 'api', 'http://api.example.test' ),
			'api with a port'      => array( 'api', 'https://api.example.test:8443' ),
			'api with a path'      => array( 'api', 'https://api.example.test/v1' ),
			'api with a query'     => array( 'api', 'https://api.example.test/?a=1' ),
			'api with credentials' => array( 'api', 'https://user:pass@api.example.test' ),
			'api on an IP address' => array( 'api', 'https://192.0.2.1' ),
			'api on one label'     => array( 'api', 'https://localhost' ),
			'wildcard api'         => array( 'api', 'https://*.example.test' ),
			'app not a string'     => array( 'app', array( 'https://my.example.test' ) ),
			'wildcard listen'      => array( 'listen', '*.example.test' ),
			'listen as a URL'      => array( 'listen', 'https://example.test' ),
			'listen with a dot'    => array( 'listen', '.example.test' ),
			'no media'             => array( 'media', array() ),
			'media not a list'     => array( 'media', 'm.example.test' ),
			'wildcard media'       => array( 'media', array( '*.example.test' ) ),
			'media with a port'    => array( 'media', array( 'm.example.test:443' ) ),
			'too many embed hosts' => array( 'embed', array_fill( 0, 11, 'embed.example.test' ) ),
			'embed not a string'   => array( 'embed', array( 42 ) ),
		);
	}

	/**
	 * @dataProvider invalid_parts
	 *
	 * @param string $part  Part to break.
	 * @param mixed  $value Its invalid value.
	 */
	public function test_an_invalid_part_rejects_the_whole_environment( string $part, $value ): void {
		$environment = showfm_test_environment();
		if ( null === $value ) {
			unset( $environment[ $part ] );
		} else {
			$environment[ $part ] = $value;
		}
		$this->assertNull( Environment::validate( $environment ) );
	}

	public function test_an_invalid_environment_falls_back_to_production_and_says_so(): void {
		$warned = new ReflectionProperty( Environment::class, 'warned' );
		$warned->setAccessible( true );
		$warned->setValue( null, false );
		$this->setExpectedIncorrectUsage( 'showfm_environment' );
		add_filter(
			'showfm_environment',
			static function (): array {
				return array( 'api' => 'https://api.example.test' );
			}
		);

		$this->assertSame( 'https://api.show.fm', Api_Client::base_url() );
		$this->assertSame( 'https://my.show.fm', Connect::app_url() );
		$this->assertTrue( Environment::is_production() );
	}

	public function test_oembed_registers_the_environments_listen_domain(): void {
		showfm_use_test_environment();
		$http = new ShowFM_Http_Mock();
		Oembed::register();
		$oembed = _wp_oembed_get_object();
		try {
			foreach ( array( 'https://example.test/my-show', 'https://example.test/my-show/e/my-episode', 'https://my-show.example.test', 'https://my-show.example.test/e/my-episode' ) as $url ) {
				$this->assertSame( 'https://api.example.test/v1/oembed', $oembed->get_provider( $url, array( 'discover' => false ) ), $url );
			}
			$this->assertSame( 'https://api.example.test/v1/oembed', $oembed->get_provider( 'https://my-show.show.fm', array( 'discover' => false ) ) );
			$this->assertFalse( $oembed->get_provider( 'https://example.test.evil.test/my-show', array( 'discover' => false ) ) );
			$this->assertSame( 0, $http->count() );
		} finally {
			$http->detach();
			foreach ( array( 'https://*.example.test/*', 'https://example.test/*', '~^https://[a-z0-9]+(?:-[a-z0-9]+)*\.example\.test/?(?:[?#].*)?\z~i' ) as $format ) {
				wp_oembed_remove_provider( $format );
			}
			// Production's providers back on production's endpoint.
			remove_filter( 'showfm_environment', 'showfm_test_environment' );
			Oembed::register();
		}
	}

	public function test_the_environments_embed_host_is_never_passed_through(): void {
		showfm_use_test_environment();
		$this->assertTrue( Oembed::names_show_fm( '<iframe src="https://embed.example.test/ep/x"></iframe>' ) );
		$this->assertTrue( Oembed::names_show_fm( '<iframe src="https://embed.cdn.media/ep/x"></iframe>' ) );
		$this->assertFalse( Oembed::names_show_fm( '<iframe src="https://notembed.example.test/ep/x"></iframe>' ) );
	}

	const KEY = 'showfm_live_abcdefghijklmnopqrstuvwxWXYZ';

	public function tear_down(): void {
		Plugin::connect()->disconnect();
		delete_option( Oembed::SEEN_OPTION );
		delete_option( Connection::ISSUER_PINNED_OPTION );
		parent::tear_down();
	}

	/**
	 * The issuing API saved in the stored credentials, or null when none is saved.
	 */
	private function saved_issuer(): ?string {
		$decrypted = new ReflectionMethod( Connection::class, 'decrypted' );
		$decrypted->setAccessible( true );
		$data = $decrypted->invoke( new Connection() );
		return is_array( $data ) && is_string( $data['api'] ?? null ) ? $data['api'] : null;
	}

	/**
	 * Stores credentials as 1.0.1 saved them, without the issuing API.
	 */
	private function save_legacy_credentials(): void {
		$key = new ReflectionMethod( Connection::class, 'encryption_key' );
		$key->setAccessible( true );
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain = wp_json_encode(
			array(
				'key'         => self::KEY,
				'ping_secret' => str_repeat( 'a', 64 ),
				'site_id'     => 'site-42',
				'expires_at'  => 0,
			)
		);
		update_option(
			Connection::OPTION,
			array(
				'v' => 1,
				'n' => base64_encode( $nonce ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- As Connection stores it.
				'c' => base64_encode( sodium_crypto_secretbox( (string) $plain, $nonce, $key->invoke( null ) ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- As Connection stores it.
				'i' => wp_generate_uuid4(),
			),
			false
		);
	}

	public function test_a_key_is_never_sent_to_another_environments_api(): void {
		$http = new ShowFM_Http_Mock();
		try {
			$this->assertTrue( Plugin::connection()->save( self::KEY, str_repeat( 'a', 64 ), 'site-42', 0 ) );
			$this->assertTrue( Plugin::connection()->is_connected() );

			// Production's key, with a test environment selected: kept, never sent.
			showfm_use_test_environment();
			$connection = new Connection();
			$this->assertSame( Connection::STATE_RECONNECT_NEEDED, $connection->state() );
			$this->assertTrue( $connection->issued_elsewhere() );
			$this->assertNull( $connection->key() );
			$this->assertSame( 'other_environment', ( new Admin_Status( $connection ) )->view( get_current_user_id() )['state'] );
			$this->assertSame( 'environment', strtok( ( new Notices( $connection ) )->candidates()[0]['key'], ':' ) );
			( new Api_Client( $connection ) )->get_keyed( '/v1/me' );
			$this->assertSame( 0, $http->count() );
			// Disconnect can't revoke it from here, and says it may still work.
			$this->assertSame( Connect::REVOKE_FAILED, Plugin::connect()->revoke( $connection->pinned() ) );
			$this->assertStringContainsString( 'Connected sites', Connect::revoke_message( Connect::REVOKE_FAILED ) );
			$this->assertSame( 0, $http->count() );

			// Reconnecting there gives a key for that API, which production never gets.
			$this->assertTrue( $connection->save( self::KEY, str_repeat( 'a', 64 ), 'site-43', 0 ) );
			$this->assertTrue( ( new Connection() )->is_connected() );
			remove_filter( 'showfm_environment', 'showfm_test_environment' );
			$connection = new Connection();
			$this->assertFalse( $connection->is_connected() );
			$this->assertTrue( $connection->issued_elsewhere() );
			( new Api_Client( $connection ) )->get_keyed( '/v1/me' );
			$this->assertSame( Connect::REVOKE_FAILED, Plugin::connect()->revoke( $connection->pinned() ) );
			$this->assertSame( 0, $http->count() );
		} finally {
			$http->detach();
		}
	}

	public function test_the_issuer_of_credentials_from_before_1_0_2_is_saved_once(): void {
		$this->save_legacy_credentials();
		$this->assertNull( $this->saved_issuer() );
		$state = ( new Connection() )->snapshot()['id'];

		Connection::pin_issuer();

		$this->assertSame( 'https://api.show.fm', $this->saved_issuer() );
		$this->assertSame( $state, get_option( Connection::ISSUER_PINNED_OPTION ) );
		$this->assertSame( $state, ( new Connection() )->snapshot()['id'], 'The same state.' );
		$this->assertSame( self::KEY, ( new Connection() )->key() );

		// A downgrade to 1.0.1 and a reconnect there: a new state, pinned again.
		$this->save_legacy_credentials();
		$this->assertNull( $this->saved_issuer() );
		Connection::pin_issuer();
		$this->assertSame( 'https://api.show.fm', $this->saved_issuer() );
	}

	public function test_after_the_first_check_a_request_does_no_lock_work(): void {
		$this->save_legacy_credentials();
		// Unreadable now, as after the salts changed: nothing to pin, and no retrying.
		$stored      = get_option( Connection::OPTION );
		$stored['c'] = base64_encode( 'not the ciphertext' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- As Connection stores it.
		update_option( Connection::OPTION, $stored, false );
		Connection::pin_issuer();
		$this->assertSame( $stored['i'], get_option( Connection::ISSUER_PINNED_OPTION ) );

		$queries = array();
		$record  = static function ( string $query ) use ( &$queries ): string {
			$queries[] = $query;
			return $query;
		};
		add_filter( 'query', $record );
		Connection::pin_issuer();
		remove_filter( 'query', $record );
		$this->assertSame( array(), $queries, 'No lock, no reads, no writes.' );
	}

	/**
	 * The old constant cannot be undefined, so this runs in its own process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_constant_defined_after_the_issuer_is_saved_changes_nothing(): void {
		$this->save_legacy_credentials();
		Connection::pin_issuer();

		define( 'SHOWFM_API_URL', 'https://api.example.test' );
		showfm_use_test_environment();
		$connection = new Connection();
		$this->assertTrue( $connection->issued_elsewhere() );
		$this->assertFalse( $connection->is_connected() );
		$this->assertSame( 'https://api.show.fm', $this->saved_issuer() );
	}

	public function test_credentials_from_before_1_0_2_belong_to_production_without_the_old_constant(): void {
		$this->save_legacy_credentials();
		$this->assertTrue( ( new Connection() )->is_connected() );
		$this->assertSame( self::KEY, ( new Connection() )->key() );

		showfm_use_test_environment();
		$this->assertFalse( ( new Connection() )->is_connected() );
	}

	/**
	 * The old constant cannot be undefined, so this runs in its own process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_credentials_from_a_1_0_1_test_site_never_reach_production(): void {
		define( 'SHOWFM_API_URL', 'https://api.example.test' );
		$http = new ShowFM_Http_Mock();
		try {
			$this->save_legacy_credentials();

			// The first request pins the API the constant names, whatever the environment.
			Connection::pin_issuer();
			$this->assertSame( 'https://api.example.test', $this->saved_issuer() );

			// On production that key is another environment's: kept, never sent. Removing
			// the constant, as the notice asks, changes nothing: the issuer is saved.
			$connection = new Connection();
			$this->assertTrue( $connection->issued_elsewhere() );
			$this->assertNull( $connection->key() );
			( new Api_Client( $connection ) )->get_keyed( '/v1/me' );
			$this->assertSame( 0, $http->count() );

			// The Reconnect error comes before the warning about the old constant.
			$keys = wp_list_pluck( ( new Notices( $connection ) )->candidates(), 'key' );
			$this->assertSame( 'environment', strtok( $keys[0], ':' ) );
			$this->assertSame( 'constants:1', $keys[1] );

			// WP-CLI says why.
			WP_CLI::$output = array();
			( new ShowFM\Cli( new Connect( $connection, new Api_Client( $connection ) ), $connection ) )->status();
			$this->assertContains( 'State: reconnect needed (connected to a different show.fm environment)', array_column( WP_CLI::$output, 1 ) );

			// The filter selects that API: connected, with no reconnect.
			showfm_use_test_environment();
			$this->assertTrue( ( new Connection() )->is_connected() );
			$this->assertSame( array( 'constants:1' ), wp_list_pluck( ( new Notices( new Connection() ) )->candidates(), 'key' ) );
		} finally {
			$http->detach();
		}
	}

	public function test_no_notice_without_the_old_constants(): void {
		$this->assertSame( array(), ( new Notices( new Connection() ) )->candidates() );
	}

	public function test_the_player_gets_the_environments_media_hosts_before_it_runs(): void {
		$before = static function ( string $handle ): string {
			wp_deregister_script( Assets::HANDLE );
			wp_deregister_script( Assets::CLICK_HANDLE );
			Assets::register();
			return implode( "\n", array_filter( (array) wp_scripts()->get_data( $handle, 'before' ) ) );
		};
		foreach ( array( Assets::HANDLE, Assets::CLICK_HANDLE ) as $handle ) {
			$this->assertStringNotContainsString( 'showfmMediaHosts', $before( $handle ), 'Production: built in.' );
		}

		add_filter(
			'showfm_environment',
			static function (): array {
				$environment          = showfm_test_environment();
				$environment['media'] = array( 'm.example.test', 'media.example.test' );
				return $environment;
			}
		);
		foreach ( array( Assets::HANDLE, Assets::CLICK_HANDLE ) as $handle ) {
			$script = $before( $handle );
			$this->assertStringContainsString( 'window.showfmMediaHosts = ["m.example.test","media.example.test"];', $script, $handle );
			// Before the click loader's own source, so v1.js sees it whenever it loads.
			if ( Assets::CLICK_HANDLE === $handle ) {
				$this->assertLessThan( strpos( $script, 'showfmEmbedSrc' ), strpos( $script, 'showfmMediaHosts' ) );
			}
		}
		$tag = get_echo( array( wp_scripts(), 'do_item' ), array( Assets::HANDLE ) );
		$this->assertLessThan( strpos( $tag, 'v1.js' ), strpos( $tag, 'showfmMediaHosts' ) );

		remove_all_filters( 'showfm_environment' );
		wp_deregister_script( Assets::HANDLE );
		wp_deregister_script( Assets::CLICK_HANDLE );
		Assets::register();
	}

	public function test_markup_from_an_environment_used_before_is_never_passed_through(): void {
		showfm_use_test_environment();
		Oembed::register();
		$this->assertSame( array( 'embed.example.test' ), get_option( Oembed::SEEN_OPTION ) );

		// Back on production, markup cached from the test environment is still show.fm's.
		remove_filter( 'showfm_environment', 'showfm_test_environment' );
		$iframe = '<iframe src="https://embed.example.test/ep/2f1c9a1e-0000-4000-8000-000000000001" title="Episode"></iframe>';
		$this->assertTrue( Oembed::names_show_fm( $iframe ) );
		$html = Oembed::output( $iframe, 'https://my-show.example.test/e/x' );
		$this->assertStringNotContainsString( 'embed.example.test', $html );
		$this->assertStringNotContainsString( '<iframe', $html );

		// Only valid host names are read back.
		update_option( Oembed::SEEN_OPTION, array( 'embed.example.test', '*.evil.test', 42 ) );
		$this->assertSame( array( 'embed.cdn.media', 'embed.example.test' ), Oembed::cdn_hosts() );
		foreach ( array( 'https://*.example.test/*', 'https://example.test/*', '~^https://[a-z0-9]+(?:-[a-z0-9]+)*\.example\.test/?(?:[?#].*)?\z~i' ) as $format ) {
			wp_oembed_remove_provider( $format );
		}
		Oembed::register();
	}
}
