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
use ShowFM\Embed_Settings;
use ShowFM\Fallback;
use ShowFM\Oembed;
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
		wp_dequeue_script( Assets::CLICK_HANDLE );
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
		$this->assertSame( '1.6.2', $script->ver );
		$this->assertStringEndsWith( '/assets/showfm-embed/v1.js', $script->src );
		$this->assertNotContains( 'module', $script->extra );
		$this->block( 'play', array( 'episode' => self::ID ) );
		$this->assertCount( 1, wp_styles()->get_data( Assets::HANDLE, 'after' ) );
		$this->assertNotFalse( has_action( 'enqueue_block_assets', array( Assets::class, 'editor_assets' ) ) );
	}

	public function test_load_on_click_enqueues_the_local_click_loader_instead_of_v1(): void {
		update_option( Embed_Settings::LOAD_ON_CLICK, true );
		Assets::register();
		$html = $this->block(
			'transcript',
			array(
				'episode'  => self::ID,
				'snapshot' => $this->snapshot(),
			)
		);
		$this->assertStringContainsString( 'load="click"', $html );
		$this->assertTrue( wp_script_is( Assets::CLICK_HANDLE, 'enqueued' ) );
		$this->assertFalse( wp_script_is( Assets::HANDLE, 'enqueued' ) );
		$this->assertStringEndsWith( '/assets/showfm-embed/click-loader-local.js', wp_scripts()->registered[ Assets::CLICK_HANDLE ]->src );
		$v1 = plugins_url( 'assets/showfm-embed/v1.js', SHOWFM_FILE ) . '?ver=1.6.2';
		$this->assertSame( $v1, Assets::versioned_script_url() );

		// Registering again adds no second global.
		Assets::register();
		// WordPress keeps a leading false in the list; count the scripts.
		$this->assertCount( 1, array_filter( (array) wp_scripts()->get_data( Assets::CLICK_HANDLE, 'before' ) ) );

		// First the inline script that sets the loader's first source, then the loader with
		// data-src, its second. Only the loader's own tag gets data-src.
		$tag       = get_echo( array( wp_scripts(), 'do_item' ), array( Assets::CLICK_HANDLE ) );
		$processor = new WP_HTML_Tag_Processor( $tag );
		$this->assertTrue( $processor->next_tag( array( 'tag_name' => 'script' ) ) );
		$this->assertSame( Assets::CLICK_HANDLE . '-js-before', $processor->get_attribute( 'id' ) );
		$this->assertNull( $processor->get_attribute( 'data-src' ) );
		$this->assertStringContainsString( 'window.showfmEmbedSrc = ' . wp_json_encode( $v1 ) . ';', $tag );
		$this->assertTrue( $processor->next_tag( array( 'tag_name' => 'script' ) ) );
		$this->assertSame( Assets::CLICK_HANDLE . '-js', $processor->get_attribute( 'id' ) );
		$this->assertStringEndsWith( '/assets/showfm-embed/click-loader-local.js?ver=1.6.2', (string) $processor->get_attribute( 'src' ) );
		$this->assertSame( $v1, $processor->get_attribute( 'data-src' ) );
		$this->assertFalse( $processor->next_tag( array( 'tag_name' => 'script' ) ) );
		$this->assertStringNotContainsString( 'embed.cdn.media', $tag );

		// A tag string without the loader's tag (an optimiser's rewrite) is left as it is:
		// the global still points the loader at v1.js.
		$rewritten = '<script id="other-js" src="bundle.js"></script>'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- A tag string for the filter, never printed.
		$this->assertSame( $rewritten, Assets::loader_tag( $rewritten, Assets::CLICK_HANDLE ) );

		// The loader the plugin ships names no host.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a bundled file.
		$loader = (string) file_get_contents( SHOWFM_DIR . '/assets/showfm-embed/click-loader-local.js' );
		$this->assertDoesNotMatchRegularExpression( '~https?://~', $loader );
		// Other scripts are left alone.
		$other = '<script src="x"></script>'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- A tag string for the filter, never printed.
		$this->assertSame( $other, Assets::loader_tag( $other, Assets::HANDLE ) );

		// In the editor the real element always previews.
		set_current_screen( 'post' );
		wp_dequeue_script( Assets::CLICK_HANDLE );
		Assets::enqueue();
		$this->assertTrue( wp_script_is( Assets::HANDLE, 'enqueued' ) );
		$this->assertFalse( wp_script_is( Assets::CLICK_HANDLE, 'enqueued' ) );
		set_current_screen( 'front' );
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
			if ( 'platform' === $attr['name'] ) {
				// Set by the plugin on every element, never by a block or shortcode.
				$this->assertNotContains( 'platform', Attributes::names( 'player' ) );
				continue;
			}
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

	public function test_every_element_carries_platform_wordpress_and_credit_off_by_default(): void {
		$episode = array(
			'episode'  => self::ID,
			'snapshot' => $this->snapshot(),
		);
		$blocks  = array(
			'player'     => $episode,
			'episodes'   => array(
				'podcast'  => self::SHOW,
				'snapshot' => $this->snapshot(),
			),
			'play'       => $episode,
			'transcript' => $episode,
		);
		foreach ( array( false, true ) as $credit ) {
			update_option( 'showfm_show_credit', $credit );
			foreach ( $blocks as $type => $attrs ) {
				$html = $this->block( $type, $attrs );
				$this->assertMatchesRegularExpression( '~<showfm-' . $type . '\\b[^>]* platform="wordpress"~', $html, $type );
				$this->assertStringContainsString( $credit ? 'credit="on"' : 'credit="off"', $html, $type );
			}
			// Shortcodes and local oEmbed players go through the same attributes.
			$this->assertStringContainsString( 'platform="wordpress"', do_shortcode( '[showfm episode="' . self::ID . '" platform="other"]' ) );
			$this->assertStringNotContainsString( 'platform="other"', do_shortcode( '[showfm episode="' . self::ID . '" platform="other"]' ) );
		}
	}

	public function test_player_mini_player_and_its_corner_are_rendered_and_validated(): void {
		$block = WP_Block_Type_Registry::get_instance()->get_registered( 'showfm/player' );
		$this->assertArrayHasKey( 'mini-player', $block->attributes );
		$this->assertArrayHasKey( 'mini-player-position', $block->attributes );

		$player = function ( array $attrs ): string {
			return $this->block(
				'player',
				array_merge(
					array(
						'episode'  => self::ID,
						'snapshot' => $this->snapshot(),
					),
					$attrs
				)
			);
		};
		$html   = $player( array() );
		$this->assertStringNotContainsString( 'mini-player', $html );

		$html = $player(
			array(
				'mini-player'          => 'on',
				'mini-player-position' => 'left',
			)
		);
		$this->assertStringContainsString( 'mini-player="on"', $html );
		$this->assertStringContainsString( 'mini-player-position="left"', $html );

		$html = $player(
			array(
				'mini-player'          => 'sometimes',
				'mini-player-position' => 'top" onclick="x',
			)
		);
		$this->assertStringNotContainsString( 'mini-player', $html );
		$this->assertStringNotContainsString( 'onclick', $html );

		$html = do_shortcode( '[showfm episode="' . self::ID . '" mini-player="on" mini-player-position="right"]' );
		$this->assertStringContainsString( 'mini-player="on"', $html );
		$this->assertStringContainsString( 'mini-player-position="right"', $html );
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

	public function test_a_transcript_with_none_renders_nothing_in_click_mode(): void {
		$attrs   = array(
			'episode'  => self::ID,
			'snapshot' => $this->snapshot(),
		);
		$episode = $this->episode();
		$this->cache( '/v1/episodes/' . self::ID, array( 'data' => array_merge( $episode, array( 'transcript' => null ) ) ) );

		// Without click mode the element collapses itself, so it is still output.
		$this->assertStringContainsString( '<showfm-transcript', $this->block( 'transcript', $attrs ) );

		update_option( 'showfm_load_on_click', true );
		$this->assertSame( '', $this->block( 'transcript', $attrs ), 'No 377px "Load transcript" box for nothing.' );

		// With a transcript, or before the cache knows, the facade still shows.
		$this->cache( '/v1/episodes/' . self::ID, array( 'data' => array_merge( $episode, array( 'transcript' => array( 'url' => 'https://m.cdn.media/t.vtt' ) ) ) ) );
		$this->assertStringContainsString( '<showfm-transcript', $this->block( 'transcript', $attrs ) );
		ShowFM\Plugin::cache()->flush();
		$this->assertStringContainsString( '<showfm-transcript', $this->block( 'transcript', $attrs ) );
	}

	public function test_a_transcript_reserves_its_own_height(): void {
		$attrs = array(
			'episode'  => self::ID,
			'snapshot' => $this->snapshot(),
		);
		$this->assertStringNotContainsString( '--showfm-height', $this->block( 'transcript', $attrs ), 'The default, 377px, needs nothing.' );
		$this->assertStringContainsString( 'style="--showfm-height:177px"', $this->block( 'transcript', array_merge( $attrs, array( 'height' => '120' ) ) ) );
		$this->assertStringNotContainsString( '--showfm-height', $this->block( 'player', array_merge( $attrs, array( 'height' => '120' ) ) ) );
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

	public function test_oembed_never_passes_a_show_fm_embed_through(): void {
		// The environment's embed host counts as show.fm's too.
		showfm_use_test_environment();
		$url    = 'https://test.show.fm/e/first';
		$iframe = static function ( string $src ): string {
			return '<iframe src="' . $src . '" title="Episode &amp; title" width="100%" height="200"></iframe>';
		};
		$player = array(
			'https://embed.cdn.media/ep/' . self::ID,
			'https://embed.cdn.media/ep/' . self::ID . '/',
			'https://embed.cdn.media/ep/' . self::ID . '?size=compact&theme=dark',
			'https://embed.cdn.media/ep/' . self::ID . '/?size=compact',
			'https://EMBED.CDN.MEDIA/ep/' . self::ID,
			'//embed.cdn.media/ep/' . self::ID,
			'https://embed.example.test/ep/' . self::ID,
			'https://embed.cdn.media/latest/my-show',
			'https://embed.cdn.media/latest/my-show/?size=compact',
		);
		$link   = array(
			'https://embed.cdn.media/',
			'https://embed.cdn.media/player/v1.js',
			'https://embed.cdn.media/ep/not-a-uuid',
			'https://embed.cdn.media/ep/' . self::ID . '/extra',
			'https://embed.cdn.media/latest/Not_A_Slug',
			'https://embed.cdn.media/show/my-show?size=compact',
			'https://embed.example.test/anything',
		);
		foreach ( $player as $src ) {
			$html = apply_filters( 'embed_oembed_html', $iframe( $src ), $url );
			$this->assertStringContainsString( '<showfm-player', $html, $src );
			$this->assertStringNotContainsString( '<iframe', $html, $src );
		}
		$html = apply_filters( 'embed_oembed_html', $iframe( 'https://embed.cdn.media/ep/' . self::ID . '/?size=compact' ), $url );
		$this->assertStringContainsString( 'size="compact"', $html );
		$this->assertStringContainsString( 'podcast="my-show"', apply_filters( 'embed_oembed_html', $iframe( 'https://embed.cdn.media/latest/my-show/' ), $url ) );

		foreach ( $link as $src ) {
			$this->assertSame( '<p class="showfm-oembed-link"><a href="https://test.show.fm/e/first">Episode &amp; title</a></p>', apply_filters( 'embed_oembed_html', $iframe( $src ), $url ), $src );
		}
		// Any show.fm embed markup, not only an iframe, and whatever URL was pasted.
		$script = '<div><script src="https://embed.cdn.media/player/v1.js"></script></div>'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Cached oEmbed markup under test, never printed.
		$this->assertSame( '<p class="showfm-oembed-link"><a href="https://example.com/post">Listen on show.fm</a></p>', apply_filters( 'embed_oembed_html', $script, 'https://example.com/post' ) );
		$this->assertSame( '', apply_filters( 'embed_oembed_html', $iframe( 'https://embed.cdn.media/unknown' ), 'javascript:alert(1)' ) );

		// The invariant: no show.fm script or iframe host in any output.
		foreach ( array_merge( $player, $link ) as $src ) {
			$html = apply_filters( 'embed_oembed_html', $iframe( $src ), $url );
			$this->assertStringNotContainsStringIgnoringCase( 'embed.cdn.media', $html, $src );
			$this->assertStringNotContainsStringIgnoringCase( 'embed.example.test', $html, $src );
		}

		// Other sites' embeds are left exactly as they are.
		$other = '<iframe src="https://www.youtube.com/embed/abc?feature=oembed" title="Video"></iframe>';
		$this->assertSame( $other, apply_filters( 'embed_oembed_html', $other, 'https://www.youtube.com/watch?v=abc' ) );
		$this->assertSame( '<blockquote>No iframe</blockquote>', apply_filters( 'embed_oembed_html', '<blockquote>No iframe</blockquote>', $url ) );
	}

	public function test_oembed_catches_every_spelling_of_a_show_fm_embed_host(): void {
		// The environment's embed host counts as show.fm's too.
		showfm_use_test_environment();
		$url = 'https://test.show.fm/e/first';
		$ep  = '/ep/' . self::ID;
		$tab = "\t";
		$nl  = "\n";
		// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript, WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Cached oEmbed markup under test, never printed.
		$disguised = array(
			'trailing dot'      => '<iframe src="https://embed.cdn.media.' . $ep . '"></iframe>',
			'two trailing dots' => '<iframe src="https://embed.cdn.media..' . $ep . '"></iframe>',
			'backslashes'       => '<iframe src="https:\\\\embed.cdn.media\\ep\\' . self::ID . '"></iframe>',
			'percent dot'       => '<iframe src="https://embed%2Ecdn.media' . $ep . '"></iframe>',
			'double percent'    => '<iframe src="https://embed%252Ecdn%252Emedia' . $ep . '"></iframe>',
			'entity dot'        => '<iframe src="https://embed&#46;cdn&period;media' . $ep . '"></iframe>',
			'double entity'     => '<iframe src="https://embed&amp;#46;cdn.media' . $ep . '"></iframe>',
			'upper case'        => '<iframe src="HTTPS://EMBED.CDN.MEDIA' . strtoupper( $ep ) . '"></iframe>',
			'tab in host'       => '<iframe src="https://embed.cdn' . $tab . '.media' . $ep . '"></iframe>',
			'newline in host'   => '<iframe src="https://embed.' . $nl . 'cdn.media' . $ep . '"></iframe>',
			'entity tab'        => '<iframe src="https://embed&#9;.cdn.media' . $ep . '"></iframe>',
			'ideographic dot'   => '<iframe src="https://embed' . "\u{3002}" . 'cdn.media' . $ep . '"></iframe>',
			'fullwidth dot'     => '<iframe src="https://embed' . "\u{FF0E}" . 'cdn.media' . $ep . '"></iframe>',
			'halfwidth dot'     => '<iframe src="https://embed' . "\u{FF61}" . 'cdn.media' . $ep . '"></iframe>',
			'environment host'  => '<iframe src="https://embed.example.test.' . $ep . '"></iframe>',
			'srcdoc'            => '<iframe srcdoc="&lt;script src=&quot;https://embed.cdn.media/player/v1.js&quot;&gt;&lt;/script&gt;"></iframe>',
			'inline import'     => '<script>import("https://embed.cdn.media/player/v1.js")</script>',
			'object data'       => '<object data="https://embed.cdn.media' . $ep . '"></object>',
			'link preload'      => '<link rel="preload" as="script" href="https://embed.cdn.media/player/v1.js">',
			'svg script href'   => '<svg><script href="https://embed.cdn.media/player/v1.js"></script></svg>',
			'img srcset'        => '<img srcset="https://embed.cdn.media/art.png 1x, https://embed.cdn.media/art2.png 2x" alt="">',
			'meta refresh'      => '<meta http-equiv="refresh" content="0;url=https://embed.cdn.media' . $ep . '">',
			'subdomain'         => '<iframe src="https://a.embed.cdn.media' . $ep . '"></iframe>',
			'with a port'       => '<iframe src="https://embed.cdn.media:443' . $ep . '"></iframe>',
		);
		// phpcs:enable
		foreach ( $disguised as $form => $html ) {
			$this->assertTrue( Oembed::names_show_fm( $html ), $form );
			$out = apply_filters( 'embed_oembed_html', $html, $url );
			$this->assertFalse( Oembed::names_show_fm( $out ), "$form: a show.fm host survived in $out" );
			$this->assertStringNotContainsString( '<iframe', $out, $form );
		}
		// A trailing dot is the same host: the canonical player still comes out.
		$this->assertStringContainsString( '<showfm-player', apply_filters( 'embed_oembed_html', $disguised['trailing dot'], $url ) );
		$this->assertStringContainsString( '<showfm-player', apply_filters( 'embed_oembed_html', $disguised['backslashes'], $url ) );
		// A show.fm embed host as the pasted URL is never linked to.
		$this->assertSame( '', apply_filters( 'embed_oembed_html', $disguised['srcdoc'], 'https://embed.cdn.media/ep/' . self::ID ) );

		// Look-alikes and other sites are left exactly as they are.
		$untouched = array(
			'<iframe src="https://embed.cdn.media.evil.test' . $ep . '"></iframe>',
			'<iframe src="https://notembed.cdn.media' . $ep . '"></iframe>',
			'<iframe src="https://embed-cdn.media' . $ep . '"></iframe>',
			'<iframe src="https://embed.cdn.mediaplayer.test' . $ep . '"></iframe>',
			'<iframe src="https://www.youtube.com/embed/abc?feature=oembed" title="Video"></iframe>',
			// Other non-ASCII breaks a label, never joins two: these are other hosts.
			'<iframe src="https://emb' . "\u{00E9}" . 'ed.cdn.media' . $ep . '"></iframe>',
			'<iframe src="https://embed.cdn' . "\u{00E9}" . '.media' . $ep . '"></iframe>',
			'<iframe src="https://embed.cdn.media' . "\u{00E9}" . $ep . '"></iframe>',
			'<iframe src="https://embed.cdn.medi' . "\u{03B1}" . 'a' . $ep . '"></iframe>',
			'<iframe src="https://embed' . "\xFF" . '.cdn.media' . $ep . '"></iframe>',
			'<blockquote>embed' . "\u{65E5}" . '.cdn' . "\u{65E5}" . '.media</blockquote>',
		);
		foreach ( $untouched as $html ) {
			$this->assertFalse( Oembed::names_show_fm( $html ), $html );
			$this->assertSame( $html, apply_filters( 'embed_oembed_html', $html, $url ) );
		}
	}

	public function test_oembed_catches_fullwidth_and_compatibility_forms(): void {
		// The environment's embed host counts as show.fm's too.
		showfm_use_test_environment();
		$url   = 'https://test.show.fm/e/first';
		$ep    = '/ep/' . self::ID;
		$wide  = static function ( string $ascii ): string {
			$out = '';
			foreach ( str_split( $ascii ) as $char ) {
				$code = ord( $char );
				$out .= $code >= 0x21 && $code <= 0x7E ? html_entity_decode( '&#' . ( $code + 0xFEE0 ) . ';', ENT_QUOTES, 'UTF-8' ) : $char;
			}
			return $out;
		};
		$forms = array(
			'fullwidth host'          => '<iframe src="https://' . $wide( 'embed.cdn.media' ) . $ep . '"></iframe>',
			'fullwidth whole URL'     => '<iframe src="' . $wide( 'https://embed.cdn.media' . $ep ) . '"></iframe>',
			'fullwidth upper case'    => '<iframe src="https://' . $wide( 'EMBED.CDN.MEDIA' ) . $ep . '"></iframe>',
			'fullwidth and ASCII mix' => '<iframe src="https://em' . $wide( 'bed' ) . '.cdn' . $wide( '.' ) . 'media' . $ep . '"></iframe>',
			'fullwidth env host'      => '<iframe src="https://' . $wide( 'embed.example.test' ) . $ep . '"></iframe>',
			'fullwidth, percent'      => '<iframe src="https://' . rawurlencode( $wide( 'embed' ) ) . '%2Ecdn.media' . $ep . '"></iframe>',
			'fullwidth, entity'       => '<iframe src="https://&#xFF45;mbed&#46;cdn.media' . $ep . '"></iframe>',
			'fullwidth, tab, dot'     => '<iframe src="https://' . $wide( 'embed' ) . "\t\u{3002}" . $wide( 'cdn' ) . '.media.' . $ep . '"></iframe>',
			'fullwidth, backslashes'  => '<iframe src="https:\\\\' . $wide( 'embed.cdn.media' ) . '\\ep\\' . self::ID . '"></iframe>',
			'fullwidth, srcdoc'       => '<iframe srcdoc="&lt;script src=&quot;https://' . $wide( 'embed.cdn.media' ) . '/v1.js&quot;&gt;"></iframe>',
		);
		if ( class_exists( 'Normalizer' ) ) {
			// Forms only compatibility normalisation folds: the small full stop U+FE52 and
			// mathematical letters.
			$forms['small full stop'] = '<iframe src="https://embed' . "\u{FE52}" . 'cdn.media' . $ep . '"></iframe>';
			$forms['math letters']    = '<iframe src="https://' . "\u{1D41E}" . 'mbed.cdn.media' . $ep . '"></iframe>';
		}
		foreach ( $forms as $form => $html ) {
			$this->assertTrue( Oembed::names_show_fm( $html ), $form );
			$out = apply_filters( 'embed_oembed_html', $html, $url );
			$this->assertFalse( Oembed::names_show_fm( $out ), "$form: a show.fm host survived in $out" );
			$this->assertStringNotContainsString( '<iframe', $out, $form );
		}
		// A fullwidth spelling of a recognised iframe still becomes the local player.
		$this->assertStringContainsString( '<showfm-player', apply_filters( 'embed_oembed_html', $forms['fullwidth host'], $url ) );

		// Fullwidth look-alikes stay untouched.
		foreach ( array( $wide( 'notembed.cdn.media' ), $wide( 'embed.cdn.media.evil.test' ) ) as $host ) {
			$html = '<iframe src="https://' . $host . $ep . '"></iframe>';
			$this->assertFalse( Oembed::names_show_fm( $html ), $host );
			$this->assertSame( $html, apply_filters( 'embed_oembed_html', $html, $url ) );
		}
	}

	public function test_oembed_catches_invisible_and_compatibility_characters(): void {
		$url  = 'https://test.show.fm/e/first';
		$ep   = '/ep/' . self::ID;
		$host = static function ( string $inside ): string {
			return '<iframe src="https://' . $inside . '/ep/11111111-2222-4333-8444-555555555555"></iframe>';
		};
		// Spellings headless Chromium resolves to embed.cdn.media.
		$invisible     = array(
			'soft hyphen'        => $host( "em\u{00AD}bed.cdn.media" ),
			'soft hyphen entity' => $host( 'em&shy;bed.cdn.media' ),
			'zero-width space'   => $host( "embed\u{200B}.cdn.media" ),
			'word joiner'        => $host( "embed.cdn\u{2060}.media" ),
			'shorthand format'   => $host( "emb\u{1BCA0}ed.cdn.media" ),
			'shorthand at end'   => $host( "embed.cdn.media\u{1BCA3}" ),
			'variation selector' => $host( "embed.cdn.media\u{FE0F}" ),
			'fullwidth letters'  => $host( "\u{FF45}\u{FF4D}\u{FF42}\u{FF45}\u{FF44}.cdn.media" ),
		);
		$compatibility = array(
			'mathematical bold' => $host( "\u{1D41E}mbed.cdn.media" ),
			'circled letter'    => $host( "\u{24D4}mbed.cdn.media" ),
			'superscript a'     => $host( "embed.cdn.medi\u{00AA}" ),
		);
		$all           = array_merge( $invisible, $compatibility );
		if ( ! class_exists( 'Normalizer' ) ) {
			$all = $invisible;
		}
		foreach ( $all as $form => $html ) {
			$this->assertTrue( Oembed::names_show_fm( $html ), $form );
			$out = apply_filters( 'embed_oembed_html', $html, $url );
			$this->assertFalse( Oembed::names_show_fm( $out ), "$form: a show.fm host survived in $out" );
			$this->assertStringNotContainsString( '<iframe', $out, $form );
			$this->assertStringContainsString( '<showfm-player', $out, $form );
		}
		// Without intl the fallback still catches fullwidth letters and invisible characters.
		foreach ( $invisible as $form => $html ) {
			$this->assertTrue( Oembed::names_show_fm( $html, false ), "$form without intl" );
		}
		foreach ( $compatibility as $form => $html ) {
			$this->assertFalse( Oembed::names_show_fm( $html, false ), "$form needs intl" );
		}
		// Deleting non-ASCII never makes a look-alike match.
		$this->assertFalse( Oembed::names_show_fm( $host( "embed\u{00AD}.cdn.media.evil.test" ) ) );
		$this->assertFalse( Oembed::names_show_fm( $host( "not\u{200B}embed.cdn.media" ) ) );
	}

	/**
	 * A separate process, so the normaliser's patterns compile after JIT is off: a pattern
	 * already compiled with JIT ignores the backtrack limit.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_oembed_fails_closed_and_never_prints_an_empty_link(): void {
		$url = 'https://test.show.fm/e/first';
		// A non-ASCII title makes the normaliser's PCRE step run out of backtracking below.
		$youtube = '<iframe src="https://www.youtube.com/embed/abc?feature=oembed" title="Vidéo"></iframe>';
		$jit     = ini_get( 'pcre.jit' );
		$limit   = ini_get( 'pcre.backtrack_limit' );
		// phpcs:disable WordPress.PHP.IniSet.Risky -- Forces a PCRE error, then restores.
		ini_set( 'pcre.jit', '0' );
		ini_set( 'pcre.backtrack_limit', '1' );
		try {
			$this->assertTrue( Oembed::names_show_fm( $youtube ) );
			$out = apply_filters( 'embed_oembed_html', $youtube, $url );
		} finally {
			ini_set( 'pcre.jit', (string) $jit );
			ini_set( 'pcre.backtrack_limit', (string) $limit );
		}
		// phpcs:enable
		// A regex error is never a reason to pass markup through.
		$this->assertStringNotContainsString( '<iframe', $out );

		// An embed title that isn't valid UTF-8 escapes to nothing: the link says something.
		$html = '<iframe src="https://embed.cdn.media/unknown" title="' . "\xFF\xFE" . '"></iframe>';
		$this->assertSame( '<p class="showfm-oembed-link"><a href="https://test.show.fm/e/first">Listen on show.fm</a></p>', apply_filters( 'embed_oembed_html', $html, $url ) );
	}

	public function test_fullwidth_folding_works_without_intl(): void {
		// The fallback the normaliser always applies, so hosts without intl are covered.
		$wide = html_entity_decode( '&#xFF45;&#xFF4D;&#xFF42;&#xFF45;&#xFF44;&#xFF0E;&#xFF43;&#xFF44;&#xFF4E;&#xFF0E;&#xFF4D;&#xFF45;&#xFF44;&#xFF49;&#xFF41;', ENT_QUOTES, 'UTF-8' );
		$this->assertSame( 'embed.cdn.media', Oembed::fold_fullwidth( $wide ) );
		$this->assertSame( '!~AZaz09', Oembed::fold_fullwidth( html_entity_decode( '&#xFF01;&#xFF5E;&#xFF21;&#xFF3A;&#xFF41;&#xFF5A;&#xFF10;&#xFF19;', ENT_QUOTES, 'UTF-8' ) ) );
		$this->assertSame( 'embed', Oembed::fold_fullwidth( 'em' . html_entity_decode( '&#xFF42;&#xFF45;&#xFF44;', ENT_QUOTES, 'UTF-8' ) ) );
		// Outside the block is left alone. Invalid UTF-8 is an error, so the caller fails closed.
		$this->assertSame( "\u{FF00}\u{FF5F}\u{3002}", Oembed::fold_fullwidth( "\u{FF00}\u{FF5F}\u{3002}" ) );
		$this->assertNull( Oembed::fold_fullwidth( "\xFF\xFE" ) );
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
