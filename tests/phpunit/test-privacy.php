<?php
/**
 * Privacy policy suggestion.
 *
 * @package ShowFM
 */

use ShowFM\Privacy;

/**
 * Privacy tests.
 */
class Test_Privacy extends WP_UnitTestCase {

	public function test_registers_the_suggested_text_on_admin_init(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-privacy-policy-content.php';
		set_current_screen( 'dashboard' );

		$this->assertNotFalse( has_action( 'admin_init', array( Privacy::class, 'register' ) ) );

		// wp_add_privacy_policy_content() must run during admin_init.
		$GLOBALS['wp_current_filter'][] = 'admin_init';
		try {
			Privacy::register();
		} finally {
			array_pop( $GLOBALS['wp_current_filter'] );
		}

		$property = new ReflectionProperty( WP_Privacy_Policy_Content::class, 'policy_content' );
		$property->setAccessible( true );
		$content = $property->getValue();
		$entries = array_filter(
			$content,
			static function ( $entry ) {
				return 'show.fm' === $entry['plugin_name'];
			}
		);
		$this->assertCount( 1, $entries );
		$text = current( $entries )['policy_text'];
		$this->assertStringContainsString( 'site key', $text );
		$this->assertStringContainsString( 'https://show.fm/privacy', $text );
		$this->assertStringNotContainsString( '<script', $text );

		set_current_screen( 'front' );
	}

	public function test_text_says_what_is_sent_when_connected(): void {
		$text = Privacy::text();

		foreach ( array( 'address', 'WordPress, PHP and plugin versions', 'site key', 'error counts', 'each post', 'account holder’s name', 'dismissed' ) as $phrase ) {
			$this->assertStringContainsString( $phrase, $text );
		}
	}

	public function test_browser_requests_match_the_readme_word_for_word(): void {
		$text = Privacy::text();
		$from = strpos( $text, 'The Player loads' );
		$to   = strpos( $text, ' show.fm then sees' );
		$this->assertNotFalse( $from );
		$this->assertNotFalse( $to );
		$timing = substr( $text, $from, $to - $from );

		foreach ( array( 'The Player', 'The Episode list', 'The Play button', 'The Transcript' ) as $block ) {
			$this->assertStringContainsString( $block, $timing );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the plugin's own readme.
		$this->assertStringContainsString( $timing, (string) file_get_contents( SHOWFM_DIR . '/readme.txt' ) );
	}
}
