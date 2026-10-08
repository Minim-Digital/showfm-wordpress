<?php
/**
 * Script translations: every plugin script that uses wp-i18n loads the showfm strings.
 *
 * @package ShowFM
 */

use ShowFM\Admin;
use ShowFM\Notices;

/**
 * Script translation tests.
 */
class Test_I18n extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function test_every_plugin_script_with_strings_sets_its_translations(): void {
		// The block editor script is registered on init; the other two load on their screens.
		Admin::enqueue( Admin::SCREEN );
		Notices::enqueue_script();

		$base    = plugins_url( '', SHOWFM_FILE ) . '/';
		$checked = array();
		foreach ( wp_scripts()->registered as $handle => $script ) {
			if ( ! is_string( $script->src ) || 0 !== strpos( $script->src, $base ) ) {
				continue;
			}
			$checked[] = $handle;
			$file      = SHOWFM_DIR . '/' . substr( $script->src, strlen( $base ) );
			$this->assertFileExists( $file );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local build file.
			$uses_i18n = in_array( 'wp-i18n', $script->deps, true ) || false !== strpos( (string) file_get_contents( $file ), 'wp.i18n' );
			if ( $uses_i18n ) {
				$this->assertSame( 'showfm', $script->textdomain, "$handle uses wp-i18n but loads no translations." );
			}
		}

		$this->assertContains( 'showfm-block-editor', $checked );
		$this->assertContains( Admin::HANDLE, $checked );
		$this->assertContains( Notices::HANDLE, $checked );
		$this->assertSame( 'showfm', wp_scripts()->registered['showfm-block-editor']->textdomain );
		$this->assertSame( 'showfm', wp_scripts()->registered[ Admin::HANDLE ]->textdomain );
	}
}
