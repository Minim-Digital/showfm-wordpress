<?php
/**
 * Server pieces the block editor relies on: element ids, the play button's sizes, a
 * transcript that follows a player, and the element script inside the editor.
 *
 * @package ShowFM
 */

use ShowFM\Assets;
use ShowFM\Attributes;
use ShowFM\Blocks;

/** Block attributes and editor assets. */
class Test_Editor_Blocks extends WP_UnitTestCase {
	const ID   = '11111111-2222-4333-8444-555555555555';
	const SHOW = '22222222-2222-4333-8444-555555555555';

	/**
	 * HTTP spy: rendering never fetches.
	 *
	 * @var ShowFM_Http_Mock
	 */
	private $http;

	public function set_up(): void {
		parent::set_up();
		$this->http = new ShowFM_Http_Mock();
		wp_dequeue_script( Assets::HANDLE );
		wp_dequeue_style( Assets::HANDLE );
	}

	public function tear_down(): void {
		$this->assertSame( 0, $this->http->count() );
		$this->http->detach();
		set_current_screen( 'front' );
		parent::tear_down();
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

	public function test_block_metadata_matches_the_editor(): void {
		$registry = WP_Block_Type_Registry::get_instance();
		$this->assertSame( 'show.fm Episode list', $registry->get_registered( 'showfm/episodes' )->title );
		$this->assertArrayHasKey( 'id', $registry->get_registered( 'showfm/player' )->attributes );
		$this->assertArrayHasKey( 'id', $registry->get_registered( 'showfm/episodes' )->attributes );
		$this->assertArrayHasKey( 'podcast', $registry->get_registered( 'showfm/play' )->attributes );
		foreach ( array( 'player', 'episodes', 'play', 'transcript' ) as $type ) {
			$block = $registry->get_registered( 'showfm/' . $type );
			$this->assertContains( 'showfm-block-editor', $block->editor_script_handles );
			$this->assertContains( 'showfm-block-editor', $block->editor_style_handles );
		}
		$this->assertTrue( wp_style_is( 'showfm-block-editor', 'registered' ) );
	}

	public function test_play_sizes_and_latest_follow_the_element(): void {
		$this->assertSame( 'lg', Attributes::clean( 'play', array( 'size' => 'lg' ) )['size'] );
		$this->assertArrayNotHasKey( 'size', Attributes::clean( 'play', array( 'size' => 'large' ) ) );
		$this->assertSame( self::SHOW, Attributes::clean( 'play', array( 'podcast' => self::SHOW ), true )['podcast'] );
	}

	public function test_element_ids_are_validated_and_rendered(): void {
		$this->assertSame( 'showfm-player-abc123', Attributes::clean( 'player', array( 'id' => 'showfm-player-abc123' ) )['id'] );
		foreach ( array( '1starts-with-digit', 'has space', '"><script>', 'wpadminbar', 'comments', 'showfm-', 'showfm-' . str_repeat( 'a', 58 ) ) as $bad ) {
			$this->assertArrayNotHasKey( 'id', Attributes::clean( 'episodes', array( 'id' => $bad ) ) );
			$this->assertArrayNotHasKey( 'for', Attributes::clean( 'transcript', array( 'for' => $bad ) ) );
		}
		$this->assertSame( 'showfm-player-1', Attributes::clean( 'transcript', array( 'for' => 'showfm-player-1' ) )['for'] );
		$this->assertArrayNotHasKey( 'id', Attributes::clean( 'play', array( 'id' => 'showfm-play' ) ) );
		$html = $this->block(
			'player',
			array(
				'id'       => 'showfm-player-abc123',
				'episode'  => self::ID,
				'snapshot' => array( 'title' => 'Snapshot' ),
			)
		);
		$this->assertStringContainsString( '<showfm-player id="showfm-player-abc123"', $html );
	}

	public function test_a_transcript_following_a_latest_episode_player_renders_the_bare_element(): void {
		$this->assertSame( '', $this->block( 'transcript', array( 'for' => 'wpadminbar' ) ), 'A follow target needs the showfm- prefix.' );
		$html = $this->block( 'transcript', array( 'for' => 'showfm-player-abc123' ) );
		$this->assertStringContainsString( '<showfm-transcript for="showfm-player-abc123"', $html );
		$this->assertStringContainsString( '></showfm-transcript>', $html );
		$this->assertTrue( wp_script_is( Assets::HANDLE, 'enqueued' ) );
		$this->assertSame( '', $this->block( 'transcript', array() ), 'Nothing to show or follow renders nothing.' );
		$this->assertSame( '', $this->block( 'transcript', array( 'for' => '"><x' ) ) );
	}

	public function test_the_editor_iframe_always_loads_the_elements_but_the_front_end_does_not(): void {
		set_current_screen( 'front' );
		Assets::editor_assets();
		$this->assertFalse( wp_script_is( Assets::HANDLE, 'enqueued' ), 'enqueue_block_assets also runs on the front end.' );
		set_current_screen( 'post' );
		Assets::editor_assets();
		$this->assertTrue( wp_script_is( Assets::HANDLE, 'enqueued' ), 'A new post previews the real element.' );
	}

	public function test_snapshot_links_are_limited_to_show_fm_hosts_and_titles_are_capped(): void {
		$allowed = array(
			'title'     => 'Fine',
			'listenUrl' => 'https://the-long-table.show.fm/e/knives',
			'audioUrl'  => 'https://m.cdn.media/k.mp3',
		);
		$this->assertSame( $allowed, Attributes::snapshot( $allowed ) );
		$this->assertSame( 'https://show.fm/the-long-table', Attributes::snapshot( array( 'listenUrl' => 'https://show.fm/the-long-table' ) )['listenUrl'] );
		$this->assertSame( 'https://media.podcasterplus.com/a.mp3', Attributes::snapshot( array( 'audioUrl' => 'https://media.podcasterplus.com/a.mp3' ) )['audioUrl'] );
		foreach (
			array(
				'https://evil.example/e/knives',
				'https://show.fm.evil.example/e/x',
				'https://evilshow.fm/e/x',
				'https://user:pass@the-long-table.show.fm/e/x',
				'https://the-long-table.show.fm:8443/e/x',
				'http://the-long-table.show.fm/e/x',
				'https://the-long-table.showfm.dev/e/x',
			) as $url
		) {
			$this->assertArrayNotHasKey( 'listenUrl', Attributes::snapshot( array( 'listenUrl' => $url ) ), $url );
		}
		foreach ( array( 'https://evil.example/a.mp3', 'https://m.cdn.media.evil.example/a.mp3', 'https://m.showfm.dev/a.mp3', 'http://m.cdn.media/a.mp3' ) as $url ) {
			$this->assertArrayNotHasKey( 'audioUrl', Attributes::snapshot( array( 'audioUrl' => $url ) ), $url );
		}
		$this->assertSame( 300, mb_strlen( Attributes::snapshot( array( 'title' => str_repeat( 'é', 400 ) ) )['title'] ) );
		$this->assertSame( str_repeat( 'é', 300 ), Attributes::cap_title( str_repeat( 'é', 400 ), false ) );
		$this->assertSame( str_repeat( 'a', 299 ) . '😀', Attributes::cap_title( str_repeat( 'a', 299 ) . '😀😀', false ) );
		$this->assertSame( 'Short title', Attributes::cap_title( 'Short title', false ) );
		$this->assertSame( array(), Attributes::snapshot( array( 'title' => array( 'not text' ) ) ) );
		$this->assertSame( array( 'legacyHost' => 'libsyn' ), Attributes::snapshot( array( 'legacyHost' => 'libsyn' ) ), 'The migrator\'s keys stay.' );
		$this->assertSame( 'https://the-long-table.show.fm', Attributes::show_listen_url( 'the-long-table' ) );
		$this->assertNull( Attributes::show_listen_url( 'Not a slug' ) );

		$content = '<!-- wp:showfm/player {"episode":"' . self::ID . '","snapshot":{"title":"T","listenUrl":"https://evil.example/e","audioUrl":"https://evil.example/a.mp3"}} /-->';
		$saved   = wp_unslash( Blocks::sanitize_content( wp_slash( $content ) ) );
		$this->assertStringNotContainsString( 'evil.example', $saved );
		$html = $this->block(
			'player',
			array(
				'episode'  => self::ID,
				'snapshot' => array(
					'title'     => 'T',
					'listenUrl' => 'https://evil.example/e',
					'audioUrl'  => 'https://evil.example/a.mp3',
				),
			)
		);
		$this->assertStringNotContainsString( 'evil.example', $html, 'Render applies the same policy.' );
	}

	public function test_an_episode_never_confirmed_public_renders_no_content_and_schedules_the_refresh(): void {
		_set_cron_array( array() );
		foreach ( array( array(), array( 'snapshot' => array() ), array( 'snapshot' => array( 'title' => '' ) ) ) as $extra ) {
			$html = $this->block( 'player', array_merge( array( 'episode' => self::ID ), $extra ) );
			$this->assertMatchesRegularExpression( '~<showfm-player episode="' . self::ID . '"[^>]*></showfm-player>~', $html, 'The element only, with no fallback content.' );
			$this->assertStringNotContainsString( '<a ', $html );
			$this->assertStringNotContainsString( '<audio', $html );
			$this->assertStringNotContainsString( 'ld+json', $html );
		}
		$this->assertNotFalse( wp_next_scheduled( ShowFM\Cache::REFRESH_HOOK, array( '/v1/episodes/' . self::ID ) ), 'The first view asks for the refresh that can confirm it.' );

		set_transient(
			ShowFM\Cache::key( '/v1/episodes/' . self::ID ),
			array(
				'state'        => ShowFM\Cache::STATE_OK,
				'data'         => array(
					'data' => array(
						'id'    => self::ID,
						'title' => 'Now public',
						'links' => array( 'listen' => 'https://the-long-table.show.fm/e/x' ),
					),
				),
				'fetched_at'   => time(),
				'backoff'      => 0,
				'next_attempt' => 0,
				'etag'         => null,
			),
			HOUR_IN_SECONDS
		);
		$this->assertStringContainsString( 'Now public', $this->block( 'player', array( 'episode' => self::ID ) ) );
	}

	public function test_content_filter_still_cleans_snapshots_with_new_attributes(): void {
		$content = '<!-- wp:showfm/player {"id":"showfm-player-1","episode":"' . self::ID . '","snapshot":{"title":"T","audioUrl":"http://x.example/a.mp3"}} /-->';
		$clean   = wp_unslash( Blocks::sanitize_content( wp_slash( $content ) ) );
		$this->assertStringContainsString( '"id":"showfm-player-1"', $clean );
		$this->assertStringNotContainsString( 'http://x.example', $clean );
	}
}
