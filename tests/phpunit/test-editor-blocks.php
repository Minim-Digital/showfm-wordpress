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
		foreach ( array( '1starts-with-digit', 'has space', '"><script>', str_repeat( 'a', 65 ) ) as $bad ) {
			$this->assertArrayNotHasKey( 'id', Attributes::clean( 'episodes', array( 'id' => $bad ) ) );
		}
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

	public function test_content_filter_still_cleans_snapshots_with_new_attributes(): void {
		$content = '<!-- wp:showfm/player {"id":"showfm-player-1","episode":"' . self::ID . '","snapshot":{"title":"T","audioUrl":"http://x.example/a.mp3"}} /-->';
		$clean   = wp_unslash( Blocks::sanitize_content( wp_slash( $content ) ) );
		$this->assertStringContainsString( '"id":"showfm-player-1"', $clean );
		$this->assertStringNotContainsString( 'http://x.example', $clean );
	}
}
