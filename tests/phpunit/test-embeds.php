<?php
/**
 * Server integration, contracts and privacy guarantees.
 *
 * @package ShowFM
 */

use ShowFM\Assets;
use ShowFM\Attributes;
use ShowFM\Bindings;
use ShowFM\Cache;
use ShowFM\Embed;
use ShowFM\Fallback;
use ShowFM\Theme;

/** Real WordPress rendering, on single site and multisite. */
class Test_Embeds extends WP_UnitTestCase {
	const ID   = '11111111-2222-4333-8444-555555555555';
	const SHOW = '22222222-2222-4333-8444-555555555555';

	/** @var ShowFM_Http_Mock */
	private $http;

	public function set_up(): void {
		parent::set_up();
		$this->http = new ShowFM_Http_Mock();
		unregister_block_bindings_source( 'showfm/episode' );
		Bindings::register();
		wp_dequeue_script( Assets::HANDLE );
		wp_dequeue_style( Assets::HANDLE );
		wp_styles()->registered[ Assets::HANDLE ]->extra = array();
		_set_cron_array( array() );
	}

	public function tear_down(): void {
		$this->assertSame( 0, $this->http->count(), 'Render and bindings must never make HTTP requests (pre_http_request spy).' );
		$this->http->detach();
		parent::tear_down();
	}

	private function episode(): array {
		return array(
			'id'             => self::ID,
			'title'          => 'Cached <title> & sound',
			'description'    => '<script>bad</script>',
			'published_at'   => '2020-01-01T12:00:00Z',
			'season_number'  => 2,
			'episode_number' => 3,
			'links'          => array( 'listen' => 'https://test.show.fm/e/first' ),
			'audio'          => array( 'url' => 'https://m.cdn.media/a.mp3' ),
		);
	}

	private function snapshot(): array {
		return array(
			'title'     => 'Snapshot <title>',
			'listenUrl' => 'https://test.show.fm/e/first?a=1&b=2',
			'audioUrl'  => 'https://m.cdn.media/old.mp3',
		);
	}

	private function cache( string $path, $data, string $state = Cache::STATE_OK, int $age = 0 ): void {
		set_transient(
			Cache::key( $path ),
			array(
				'state'        => $state,
				'data'         => $data,
				'fetched_at'   => time() - $age,
				'backoff'      => 0,
				'next_attempt' => 0,
				'etag'         => null,
			),
			Cache::STALE_FOR
		);
	}

	private function block( string $type, array $attrs ): string {
		return render_block(
			array(
				'blockName'    => 'showfm/' . $type,
				'attrs'        => $attrs,
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
	}

	public function test_all_block_callbacks_use_snapshot_then_cache_and_hide_unavailable(): void {
		foreach ( array( 'player', 'episodes', 'play', 'transcript' ) as $type ) {
			$attrs = array(
				'episode'  => self::ID,
				'podcast'  => self::SHOW,
				'snapshot' => $this->snapshot(),
			);
			$path  = Embed::path( $type, Attributes::clean( $type, $attrs, true ) );
			delete_transient( Cache::key( $path ) );
			$html = $this->block( $type, $attrs );
			$this->assertStringContainsString( '<showfm-' . $type, $html );
			$this->assertStringContainsString( 'Snapshot &lt;title&gt;', $html );
			$this->assertStringNotContainsString( 'application/ld+json', $html );
			$this->assertNotFalse( wp_next_scheduled( Cache::REFRESH_HOOK, array( $path ) ) );
			$this->cache( $path, array( 'data' => 'episodes' === $type ? array( $this->episode() ) : $this->episode() ) );
			$html = $this->block( $type, $attrs );
			$this->assertStringContainsString( 'Cached &lt;title&gt; &amp; sound', $html );
			$this->assertStringNotContainsString( 'Snapshot', $html );
			$this->cache( $path, null, Cache::STATE_UNAVAILABLE );
			$this->assertSame( '', $this->block( $type, $attrs ) );
			delete_transient( Cache::key( $path ) );
		}
	}

	public function test_stale_cache_schedules_one_jittered_event_and_keeps_fallback(): void {
		$path = '/v1/episodes/' . self::ID;
		$this->cache( $path, array( 'data' => $this->episode() ), Cache::STATE_OK, Cache::FRESH_FOR + 1 );
		foreach ( range( 1, 3 ) as $unused ) {
			$this->assertStringContainsString( 'Cached', $this->block( 'player', array( 'episode' => self::ID ) ) );
		}
		$event = wp_get_scheduled_event( Cache::REFRESH_HOOK, array( $path ) );
		$this->assertGreaterThanOrEqual( time() - 1, $event->timestamp );
		$this->assertLessThanOrEqual( time() + Cache::MAX_JITTER, $event->timestamp );
		$this->assertFalse( $event->schedule );
	}

	public function test_episode_marker_also_hides_stale_list_and_latest_audio(): void {
		$this->cache( '/v1/episodes/' . self::ID, null, Cache::STATE_UNAVAILABLE );
		$this->cache( '/v1/podcasts/' . self::SHOW . '/episodes?limit=10', array( 'data' => array( $this->episode() ) ), Cache::STATE_OK, Cache::FRESH_FOR + 1 );
		$this->cache( '/v1/podcasts/' . self::SHOW . '/episodes/latest', array( 'data' => $this->episode() ), Cache::STATE_OK, Cache::FRESH_FOR + 1 );
		$this->assertStringNotContainsString( 'Cached', $this->block( 'episodes', array( 'podcast' => self::SHOW ) ) );
		$this->assertSame( '', $this->block( 'player', array( 'podcast' => self::SHOW ) ) );
	}

	/**
	 * @dataProvider marker_render_cases
	 * @param string $type Block type (list or latest player).
	 * @param int    $marker_age Age of the episode marker.
	 * @param int    $source_age Age of the public response.
	 * @param bool   $visible Whether newer fresh evidence permits rendering.
	 */
	public function test_list_and_latest_marker_refresh_and_precedence( string $type, int $marker_age, int $source_age, bool $visible ): void {
		$path         = Embed::path( $type, array( 'podcast' => self::SHOW ) );
		$episode_path = '/v1/episodes/' . self::ID;
		$this->cache( $episode_path, null, Cache::STATE_UNAVAILABLE, $marker_age );
		$this->cache( $path, array( 'data' => 'episodes' === $type ? array( $this->episode() ) : $this->episode() ), Cache::STATE_OK, $source_age );
		$marker = get_transient( Cache::key( $episode_path ) );
		// Fix timestamps against the same reference time, including the tie case.
		$source               = get_transient( Cache::key( $path ) );
		$source['fetched_at'] = $marker['fetched_at'] + $marker_age - $source_age;
		set_transient( Cache::key( $path ), $source, Cache::STALE_FOR );
		for ( $i = 0; $i < 3; ++$i ) {
			$html = $this->block(
				$type,
				array(
					'podcast'  => self::SHOW,
					'snapshot' => $this->snapshot(),
				)
			);
			if ( $visible ) {
				$this->assertStringContainsString( 'Cached &lt;title&gt;', $html );
			} else {
				$this->assertStringNotContainsString( 'Cached', $html );
				$this->assertStringNotContainsString( 'a.mp3', $html );
				if ( 'player' === $type ) {
					$this->assertSame( '', $html );
				}
			}
		}
		$event = wp_get_scheduled_event( Cache::REFRESH_HOOK, array( $episode_path ) );
		if ( $marker_age >= Cache::FRESH_FOR ) {
			$this->assertNotFalse( $event );
			$this->assertFalse( $event->schedule );
			$this->assertGreaterThanOrEqual( time() - 2, $event->timestamp );
			$this->assertLessThanOrEqual( time() + Cache::MAX_JITTER, $event->timestamp );
			$count = 0;
			foreach ( _get_cron_array() as $events ) {
				foreach ( $events[ Cache::REFRESH_HOOK ] ?? array() as $scheduled ) {
					if ( array( $episode_path ) === $scheduled['args'] ) {
						++$count;
					}
				}
			}
			$this->assertSame( 1, $count );
		} else {
			$this->assertFalse( $event );
		}
		$this->assertSame( $marker, get_transient( Cache::key( $episode_path ) ), 'Rendering must not overwrite a marker with a partial list payload.' );
	}

	public static function marker_render_cases(): array {
		$cases = array();
		foreach ( array( 'episodes', 'player' ) as $type ) {
			$cases[ $type . ' stale marker, old stale source' ]   = array( $type, 1000, 1100, false );
			$cases[ $type . ' stale marker, newer stale source' ] = array( $type, 1100, 1000, false );
			$cases[ $type . ' republished, stale marker' ]        = array( $type, 1000, 10, true );
			$cases[ $type . ' republished, fresh marker' ]        = array( $type, 100, 10, true );
			$cases[ $type . ' newer unavailable marker' ]         = array( $type, 10, 100, false );
			$cases[ $type . ' tied timestamps' ]                  = array( $type, 100, 100, false );
		}
		return $cases;
	}

	public function test_marker_refresh_honours_backoff_without_scheduling_for_missing_markers(): void {
		$episode_path = '/v1/episodes/' . self::ID;
		$this->cache( $episode_path, null, Cache::STATE_UNAVAILABLE, 1000 );
		$marker                 = get_transient( Cache::key( $episode_path ) );
		$marker['next_attempt'] = time() + 600;
		set_transient( Cache::key( $episode_path ), $marker, Cache::STALE_FOR );
		foreach ( array( 'episodes', 'player' ) as $type ) {
			$path = Embed::path( $type, array( 'podcast' => self::SHOW ) );
			$this->cache( $path, array( 'data' => 'episodes' === $type ? array( $this->episode() ) : $this->episode() ) );
			$this->assertStringContainsString( 'Cached', $this->block( $type, array( 'podcast' => self::SHOW ) ) );
			$event = wp_get_scheduled_event( Cache::REFRESH_HOOK, array( $episode_path ) );
			$this->assertGreaterThanOrEqual( $marker['next_attempt'], $event->timestamp );
			$this->assertLessThanOrEqual( $marker['next_attempt'] + Cache::MAX_JITTER, $event->timestamp );
		}
		delete_transient( Cache::key( $episode_path ) );
		wp_clear_scheduled_hook( Cache::REFRESH_HOOK, array( $episode_path ) );
		foreach ( array( 'episodes', 'player' ) as $type ) {
			$this->block( $type, array( 'podcast' => self::SHOW ) );
		}
		$this->assertFalse( wp_next_scheduled( Cache::REFRESH_HOOK, array( $episode_path ) ) );
	}

	public function test_empty_and_invalid_blocks_enqueue_nothing(): void {
		$this->assertFalse( wp_script_is( Assets::HANDLE, 'enqueued' ) );
		$this->assertSame( '', $this->block( 'player', array() ) );
		$this->assertSame( '', $this->block( 'player', array( 'episode' => 'not-a-uuid' ) ) );
		$this->assertSame( '', $this->block( 'episodes', array( 'podcast' => 'a-slug' ) ) );
		$this->assertFalse( wp_script_is( Assets::HANDLE, 'enqueued' ) );
	}

	public function test_assets_only_enqueue_when_output_and_use_classic_local_version(): void {
		Assets::register();
		$this->assertFalse( wp_script_is( Assets::HANDLE, 'enqueued' ) );
		$this->block(
			'player',
			array(
				'episode'  => self::ID,
				'snapshot' => $this->snapshot(),
			)
		);
		$this->assertTrue( wp_script_is( Assets::HANDLE, 'enqueued' ) );
		$script = wp_scripts()->registered[ Assets::HANDLE ];
		$this->assertSame( '1.1.0', $script->ver );
		$this->assertStringEndsWith( '/assets/showfm-embed/v1.js', $script->src );
		$this->assertNotContains( 'module', $script->extra );
		$this->block( 'play', array( 'episode' => self::ID ) );
		$this->assertCount( 1, wp_styles()->get_data( Assets::HANDLE, 'after' ) );
		$this->assertNotFalse( has_action( 'enqueue_block_assets', array( Assets::class, 'editor_assets' ) ) );
	}

	public function test_json_ld_is_public_cache_only_and_setting_is_respected(): void {
		$attrs = array(
			'episode'  => self::ID,
			'snapshot' => $this->snapshot(),
		);
		$this->assertStringNotContainsString( 'application/ld+json', $this->block( 'player', $attrs ) );
		$episode          = $this->episode();
		$episode['title'] = '</script><script>alert(1)</script>';
		$this->cache( '/v1/episodes/' . self::ID, array( 'data' => $episode ) );
		$html = $this->block( 'player', $attrs );
		$this->assertStringContainsString( 'application/ld+json', $html );
		$this->assertStringNotContainsString( '<script>alert', $html );
		$this->assertStringContainsString( '\u003c/script\u003e', $html );
		update_option( 'showfm_json_ld', false );
		$this->assertStringNotContainsString( 'application/ld+json', $this->block( 'player', $attrs ) );
	}

	public function test_shortcode_filters_unknown_attributes_and_escapes_hostile_cache(): void {
		$episode                    = $this->episode();
		$episode['links']['listen'] = 'javascript:alert(1)';
		$episode['audio']['url']    = 'https://m.cdn.media/" onerror="bad';
		$this->cache( '/v1/episodes/' . self::ID, array( 'data' => $episode ) );
		$html = do_shortcode( '[showfm episode="' . self::ID . '" onload="bad" theme="dark" credit="on" size="compact" heading-level="3" unknown="bad"]' );
		$this->assertStringContainsString( 'theme="dark"', $html );
		$this->assertStringContainsString( 'heading-level="3"', $html );
		$this->assertStringContainsString( 'credit="off"', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
		$this->assertStringNotContainsString( ' onerror="', $html );
		$this->assertStringNotContainsString( ' onload=', $html );
		$this->assertStringNotContainsString( ' unknown=', $html );
		$this->assertSame( '', do_shortcode( '[showfm type="script" episode="' . self::ID . '"]' ) );
		foreach ( array( 'episodes', 'play', 'transcript' ) as $type ) {
			$this->assertStringContainsString( '<showfm-' . $type, do_shortcode( '[showfm type="' . $type . '" podcast="test" episode="' . self::ID . '"]' ) );
		}
	}

	public function test_every_pinned_player_attribute_has_a_block_and_shortcode_contract(): void {
		$manifest = json_decode( file_get_contents( SHOWFM_DIR . '/node_modules/@showfm/embed/custom-elements.json' ), true );
		$block    = WP_Block_Type_Registry::get_instance()->get_registered( 'showfm/player' );
		foreach ( $manifest['modules'][0]['declarations'][0]['attributes'] as $attr ) {
			$this->assertArrayHasKey( $attr['name'], $block->attributes );
			$this->assertContains( $attr['name'], Attributes::names( 'player' ) );
		}
		$html = ShowFM\Shortcode::render(
			array(
				'episode' => self::ID,
				'strings' => '{"play":"<Play>"}',
			)
		);
		$this->assertStringContainsString( 'strings="{&quot;play&quot;:&quot;&lt;Play&gt;&quot;}"', $html );
		$html = ShowFM\Shortcode::render(
			array(
				'episode' => self::ID,
				'strings' => 'not json',
			)
		);
		$this->assertStringNotContainsString( 'strings=', $html );
	}

	public function test_list_attributes_and_cache_filters_are_one_to_one(): void {
		$html = do_shortcode( '[showfm type="episodes" podcast="test" variant="minimal" layout="grid" count="4" season="2" hide="trailer,bonus" descriptions="off" mini-player="off" style="card"]' );
		$this->assertStringContainsString( 'variant="minimal"', $html );
		$this->assertStringNotContainsString( 'style="card"', $html );
		$this->assertNotFalse( wp_next_scheduled( Cache::REFRESH_HOOK, array( '/v1/podcasts/test/episodes?limit=4&season=2&type=full' ) ) );
	}

	public function test_settings_defaults_and_credit_opt_in(): void {
		$settings = get_registered_settings();
		$this->assertFalse( $settings['showfm_show_credit']['default'] );
		$this->assertTrue( $settings['showfm_json_ld']['default'] );
		$this->assertFalse( sanitize_option( 'showfm_show_credit', 'false' ) );
		update_option( 'showfm_show_credit', true );
		$this->assertStringContainsString( 'credit="on"', $this->block( 'player', array( 'episode' => self::ID ) ) );
	}

	public function test_display_settings_are_registered_for_rest_with_their_defaults(): void {
		$settings = get_registered_settings();
		$expected = array(
			'showfm_show_credit'   => false,
			'showfm_load_on_click' => false,
			'showfm_json_ld'       => true,
			'showfm_theme_styles'  => true,
		);
		foreach ( $expected as $name => $default ) {
			$this->assertSame( $default, $settings[ $name ]['default'], $name );
			$this->assertTrue( $settings[ $name ]['show_in_rest'], $name );
			$this->assertSame( 'boolean', $settings[ $name ]['type'], $name );
		}
	}

	public function test_load_on_click_applies_to_every_embed_when_on(): void {
		$this->assertStringNotContainsString( 'load="click"', $this->block( 'player', array( 'episode' => self::ID ) ) );

		update_option( 'showfm_load_on_click', true );

		$this->assertStringContainsString( 'load="click"', $this->block( 'player', array( 'episode' => self::ID ) ) );
		$this->assertStringContainsString( 'load="click"', do_shortcode( '[showfm type="episodes" podcast="test"]' ) );
	}

	public function test_theme_styles_can_be_turned_off(): void {
		$styles = wp_styles();
		$this->block( 'player', array( 'episode' => self::ID ) );
		$this->assertNotEmpty( $styles->get_data( Assets::HANDLE, 'after' ), 'Theme colours and fonts by default.' );

		wp_dequeue_style( Assets::HANDLE );
		$styles->remove( Assets::HANDLE );
		update_option( 'showfm_theme_styles', false );
		$this->block( 'player', array( 'episode' => self::ID ) );

		$this->assertEmpty( $styles->get_data( Assets::HANDLE, 'after' ), 'show.fm defaults only.' );
	}

	public function test_bindings_use_context_or_authorised_sanitised_post_meta(): void {
		$id         = self::factory()->post->create();
		$registered = get_registered_meta_keys( 'post' )['_showfm_episode_id'];
		$this->assertTrue( $registered['show_in_rest'] );
		$this->assertSame( '', call_user_func( $registered['sanitize_callback'], 'bad' ) );
		$this->assertFalse( call_user_func( $registered['auth_callback'], false, '_showfm_episode_id', $id ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( call_user_func( $registered['auth_callback'], false, '_showfm_episode_id', $id ) );
		update_post_meta( $id, '_showfm_episode_id', self::ID );
		$this->cache( '/v1/episodes/' . self::ID, array( 'data' => $this->episode() ) );
		$block          = new WP_Block(
			array(
				'blockName' => 'core/paragraph',
				'attrs'     => array(),
			)
		);
		$block->context = array( 'postId' => $id );
		$this->assertSame( 'Cached &lt;title&gt; &amp; sound', Bindings::value( array( 'key' => 'title' ), $block ) );
		$this->assertSame( '&lt;script&gt;bad&lt;/script&gt;', Bindings::value( array( 'key' => 'description' ), $block ) );
		$this->assertSame( 'Season 2, Episode 3', Bindings::value( array( 'key' => 'season_episode' ), $block ) );
		$this->assertNotEmpty( Bindings::value( array( 'key' => 'published_date' ), $block ) );
		$this->assertNull( Bindings::value( array( 'key' => 'audio' ), $block ) );
		$block->context = array( 'showfm/episode' => self::ID );
		$this->assertStringContainsString( 'Cached', Bindings::value( array( 'key' => 'title' ), $block ) );
		$this->cache( '/v1/episodes/' . self::ID, null, Cache::STATE_UNAVAILABLE );
		$this->assertSame( '', Bindings::value( array( 'key' => 'title' ), $block ) );
		$this->assertContains( 'showfm/episode', get_block_bindings_source( 'showfm/episode' )->uses_context );
	}

	public function test_oembed_provider_handles_host_and_path_forms_without_http(): void {
		$oembed = _wp_oembed_get_object();
		foreach ( array( 'https://test.show.fm', 'https://test.show.fm/', 'https://test.show.fm/e/first', 'https://show.fm/test', 'https://show.fm/test/e/first' ) as $url ) {
			$this->assertSame( ShowFM\Api_Client::base_url() . '/v1/oembed', $oembed->get_provider( $url, array( 'discover' => false ) ) );
		}
		$this->assertFalse( $oembed->get_provider( 'https://test.show.fm.evil.test/e/first', array( 'discover' => false ) ) );
		$this->assertFalse( wp_script_is( Assets::HANDLE, 'enqueued' ) );
		$html = apply_filters( 'embed_oembed_html', '<iframe src="https://embed.cdn.media/ep/' . self::ID . '?size=compact" title="Episode &amp; title"></iframe>', 'https://test.show.fm/e/first' );
		$this->assertStringContainsString( '<showfm-player', $html );
		$this->assertStringContainsString( 'credit="off"', $html );
		$this->assertStringContainsString( 'size="compact"', $html );
		$this->assertStringNotContainsString( '<iframe', $html );
		$this->assertTrue( wp_script_is( Assets::HANDLE, 'enqueued' ) );
	}

	public function test_theme_global_defaults_and_block_overrides(): void {
		$filter = static function ( $data ) {
			return $data->update_with(
				array(
					'version'  => 3,
					'settings' => array(
						'color'      => array(
							'palette' => array(
								array(
									'slug'  => 'primary',
									'color' => '#123456',
									'name'  => 'Primary',
								),
							),
						),
						'typography' => array(
							'fontFamilies' => array(
								array(
									'slug'       => 'body',
									'fontFamily' => 'Georgia, serif',
									'name'       => 'Body',
								),
							),
						),
					),
					'styles'   => array( 'typography' => array( 'fontFamily' => 'var:preset|font-family|body' ) ),
				)
			);
		};
		add_filter( 'wp_theme_json_data_theme', $filter );
		WP_Theme_JSON_Resolver::clean_cached_data();
		try {
			$css = Theme::global_css();
			$this->assertStringContainsString( '--showfm-accent:#123456', $css );
			$this->assertStringContainsString( '--showfm-font:var(--wp--preset--font-family--body)', $css );
		} finally {
			remove_filter( 'wp_theme_json_data_theme', $filter );
			WP_Theme_JSON_Resolver::clean_cached_data();
		}
		$style = Theme::block_style(
			array(
				'accent'     => '#abcdef',
				'fontFamily' => 'body',
				'style'      => array(
					'color'   => array( 'background' => '#ffffff' ),
					'spacing' => array( 'padding' => array( 'top' => '2rem' ) ),
				),
			)
		);
		$this->assertStringContainsString( '--showfm-accent:#abcdef', $style );
		$this->assertStringContainsString( '--showfm-padding-top:2rem', $style );
		$this->assertStringContainsString( '--showfm-background:#ffffff', $style );
		$this->assertStringNotContainsString( 'evil', Theme::block_style( array( 'style' => array( 'typography' => array( 'fontFamily' => 'evil;</style><script>bad</script>' ) ) ) ) );
	}

	public function test_multisite_cache_and_settings_are_site_local(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite suite only.' );
		}
		$blog = self::factory()->blog->create();
		$this->cache( '/v1/episodes/' . self::ID, array( 'data' => $this->episode() ) );
		update_option( 'showfm_show_credit', true );
		switch_to_blog( $blog );
		try {
			$html = $this->block(
				'player',
				array(
					'episode'  => self::ID,
					'snapshot' => $this->snapshot(),
				)
			);
			$this->assertStringContainsString( 'Snapshot', $html );
			$this->assertStringContainsString( 'credit="off"', $html );
			$this->assertStringNotContainsString( 'Cached', $html );
		} finally {
			restore_current_blog();
		}
		$this->assertStringContainsString( 'Cached', $this->block( 'player', array( 'episode' => self::ID ) ) );
	}

	public function test_validation_rejects_trailing_whitespace_without_scheduling_requests(): void {
		foreach ( array( "\n", "\r\n", ' ', "\t", "\u{00a0}" ) as $suffix ) {
			$this->assertSame( '', Attributes::uuid( self::ID . $suffix ) );
			$this->assertSame( '', $this->block( 'player', array( 'episode' => self::ID . $suffix ) ) );
			$this->assertSame( '', $this->block( 'episodes', array( 'podcast' => self::SHOW . $suffix ) ) );
			$this->assertSame( '', ShowFM\Shortcode::render( array( 'podcast' => 'my-show' . $suffix ) ) );
			$attrs = Attributes::clean(
				'transcript',
				array(
					'for'    => 'player-one' . $suffix,
					'lang'   => 'en-GB' . $suffix,
					'accent' => '#aabbcc' . $suffix,
				)
			);
			foreach ( array( 'for', 'lang', 'accent' ) as $name ) {
				$this->assertArrayNotHasKey( $name, $attrs );
			}
			$this->assertSame(
				'',
				Theme::block_style(
					array(
						'fontFamily' => 'body' . $suffix,
						'accent'     => '#aabbcc' . $suffix,
					)
				)
			);
		}
		$this->assertSame( array(), _get_cron_array() );
		$this->assertSame( self::ID, Attributes::uuid( strtoupper( self::ID ) ) );
		$this->assertSame( 'my-show', Attributes::clean( 'player', array( 'podcast' => 'my-show' ) )['podcast'] );
		$oembed = _wp_oembed_get_object();
		$this->assertFalse( $oembed->get_provider( "https://my-show.show.fm\n", array( 'discover' => false ) ) );
	}

	/**
	 * @dataProvider non_string_api_values
	 * @param mixed $value A malformed API field.
	 */
	public function test_non_string_api_fields_are_missing_in_fallbacks_and_bindings( $value ): void {
		$episode                 = $this->episode();
		$episode['id']           = $value;
		$episode['title']        = $value;
		$episode['published_at'] = $value;
		$episode['podcast']      = array( 'title' => $value );
		$this->assertFalse( ShowFM\Plugin::cache()->is_episode_unavailable( $value, '/v1/test' ) );
		foreach ( array( 'player', 'episodes', 'play', 'transcript' ) as $type ) {
			$attrs = array(
				'podcast' => self::SHOW,
				'episode' => self::ID,
			);
			$path  = Embed::path( $type, Attributes::clean( $type, $attrs, true ) );
			$this->cache( $path, array( 'data' => 'episodes' === $type ? array( $episode ) : $episode ) );
			$html = $this->block( $type, $attrs );
			$this->assertStringContainsString( '<a href="https://test.show.fm/e/first"></a>', $html );
			$this->assertStringNotContainsString( 'datePublished', $html );
		}
		// Exercise the podcast/latest route too, without an explicit episode attribute.
		$this->cache( '/v1/podcasts/' . self::SHOW . '/episodes/latest', array( 'data' => $episode ) );
		$this->assertStringContainsString( '<showfm-player', $this->block( 'player', array( 'podcast' => self::SHOW ) ) );
		$block          = new WP_Block(
			array(
				'blockName' => 'core/paragraph',
				'attrs'     => array(),
			)
		);
		$block->context = array( 'showfm/episode' => self::ID );
		$this->assertSame( '', Bindings::value( array( 'key' => 'title' ), $block ) );
		$this->assertSame( '', Bindings::value( array( 'key' => 'published_date' ), $block ) );
		$json = json_decode( Fallback::json_ld( $episode ), true );
		$this->assertSame( '', $json['name'] );
		$this->assertArrayNotHasKey( 'datePublished', $json );
		$this->assertArrayNotHasKey( 'partOfSeries', $json );
	}

	public static function non_string_api_values(): array {
		return array(
			'null'    => array( null ),
			'boolean' => array( false ),
			'integer' => array( 42 ),
			'float'   => array( 1.5 ),
			'array'   => array( array( 'bad' ) ),
			'object'  => array( (object) array( 'bad' => true ) ),
		);
	}

	public function test_core_content_bindings_render_text_once_escaped(): void {
		$title            = 'Rock & Roll "live" \'now\' <script>alert(1)</script>';
		$episode          = $this->episode();
		$episode['title'] = $title;
		$this->cache( '/v1/episodes/' . self::ID, array( 'data' => $episode ) );
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_showfm_episode_id', self::ID );
		$expected = 'Rock &amp; Roll &quot;live&quot; &#039;now&#039; &lt;script&gt;alert(1)&lt;/script&gt;';
		foreach ( array(
			'paragraph' => 'p',
			'heading'   => 'h2',
		) as $type => $tag ) {
			$attrs  = array(
				'metadata' => array(
					'bindings' => array(
						'content' => array(
							'source' => 'showfm/episode',
							'args'   => array( 'key' => 'title' ),
						),
					),
				),
			);
			$markup = '<!-- wp:' . $type . ' ' . wp_json_encode( $attrs ) . ' --><' . $tag . '>Stored text</' . $tag . '><!-- /wp:' . $type . ' -->';
			$parsed = parse_blocks( $markup )[0];
			foreach ( array( array( 'postId' => $post_id ), array( 'showfm/episode' => self::ID ) ) as $context ) {
				// Call the real WP_Block::render path, including core's binding replacement.
				$block = new WP_Block( $parsed, $context );
				$html  = $block->render();
				$this->assertStringContainsString( '>' . $expected . '</' . $tag . '>', $html );
				$this->assertSame( $title, html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) );
				$this->assertStringNotContainsString( '&amp;amp;', $html );
				$this->assertStringNotContainsString( '<script>', $html );
			}
		}
	}

	public function test_snapshot_urls_require_https_on_save_and_render(): void {
		foreach ( array( 'player', 'episodes', 'play', 'transcript' ) as $type ) {
			$attrs = array(
				'episode'  => self::ID,
				'podcast'  => self::SHOW,
				'snapshot' => array(
					'title'     => 'Snapshot "quoted"',
					'listenUrl' => 'http://test.show.fm/e/first',
					'audioUrl'  => 'http://m.cdn.media/old.mp3',
				),
			);
			$block = '<!-- wp:showfm/' . $type . ' ' . wp_json_encode( $attrs ) . ' /-->';
			// Legacy content bypassing the save filter still cannot render HTTP snapshot URLs.
			$html = do_blocks( $block );
			$this->assertStringNotContainsString( 'href=', $html );
			$this->assertStringNotContainsString( '<audio', $html );
			$content  = '<!-- wp:group --><div class="wp-block-group">' . $block . '</div><!-- /wp:group -->';
			$post_id  = wp_insert_post(
				wp_slash(
					array(
						'post_title'   => 'Snapshot test',
						'post_content' => $content,
					)
				)
			);
			$saved    = get_post_field( 'post_content', $post_id );
			$snapshot = parse_blocks( $saved )[0]['innerBlocks'][0]['attrs']['snapshot'];
			$this->assertArrayNotHasKey( 'audioUrl', $snapshot );
			$this->assertArrayNotHasKey( 'listenUrl', $snapshot );
			$this->assertSame( 'Snapshot "quoted"', $snapshot['title'] );
			$attrs['snapshot'] = $this->snapshot();
			$valid             = '<!-- wp:showfm/' . $type . ' ' . wp_json_encode( $attrs ) . ' /-->';
			$this->assertSame( wp_slash( $valid ), ShowFM\Blocks::sanitize_content( wp_slash( $valid ) ) );
			$html = do_blocks( $valid );
			$this->assertStringContainsString( 'href="https://test.show.fm/e/first?a=1&amp;b=2"', $html );
			if ( in_array( $type, array( 'player', 'play' ), true ) ) {
				$this->assertStringContainsString( 'src="https://m.cdn.media/old.mp3"', $html );
			}
		}
		foreach ( array( null, array(), 'HTTP://example.test/', '//example.test/', "https://example.test/\n", 'https://' ) as $url ) {
			$this->assertSame(
				array(),
				Attributes::snapshot(
					array(
						'listenUrl' => $url,
						'audioUrl'  => $url,
					)
				)
			);
		}
		$unrelated = '<!-- wp:paragraph --><p>A \'quote\' and http://example.test</p><!-- /wp:paragraph -->';
		$this->assertSame( wp_slash( $unrelated ), ShowFM\Blocks::sanitize_content( wp_slash( $unrelated ) ) );
		// Snapshot policy must not change the pinned server renderer's API URL contract.
		$this->assertSame(
			'<a href="http://example.test">Public</a>',
			Fallback::episode(
				array(
					'title' => 'Public',
					'links' => array( 'listen' => 'http://example.test' ),
				)
			)
		);
	}

	public function test_package_parity_for_every_shared_fixture(): void {
		$fixtures = json_decode( file_get_contents( dirname( __DIR__ ) . '/fixtures/parity.json' ), true );
		$methods  = array(
			'renderEpisodeHTML'     => 'episode',
			'renderEpisodeListHTML' => 'episode_list',
			'episodeJsonLd'         => 'json_ld',
		);
		foreach ( $fixtures as $fixture ) {
			$this->assertSame( $fixture['expected'], call_user_func_array( array( Fallback::class, $methods[ $fixture['function'] ] ), $fixture['args'] ), $fixture['name'] );
		}
	}
}
