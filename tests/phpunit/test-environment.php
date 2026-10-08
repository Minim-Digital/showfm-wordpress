<?php
/**
 * The show.fm environment: production, or one the site's own code selects.
 *
 * @package ShowFM
 */

use ShowFM\Admin;
use ShowFM\Api_Client;
use ShowFM\Attributes;
use ShowFM\Connect;
use ShowFM\Editor_Api;
use ShowFM\Environment;
use ShowFM\Oembed;

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
}
