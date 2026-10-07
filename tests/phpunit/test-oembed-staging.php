<?php
/**
 * Staging configuration is isolated because constants cannot be reset between tests.
 *
 * @package ShowFM
 */

/** Staging oEmbed host-form and legacy path-form registration. */
class Test_Oembed_Staging extends WP_UnitTestCase {
	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_staging_constant_registers_root_path_form_and_subdomain_urls(): void {
		define( 'SHOWFM_API_URL', 'https://api.showfm.dev' );
		$http = new ShowFM_Http_Mock();
		try {
			ShowFM\Oembed::register();
			$oembed = _wp_oembed_get_object();
			foreach ( array( 'https://showfm.dev/my-show', 'https://showfm.dev/my-show/e/my-episode', 'https://my-show.showfm.dev', 'https://my-show.showfm.dev/e/my-episode' ) as $url ) {
				$this->assertSame( 'https://api.showfm.dev/v1/oembed', $oembed->get_provider( $url, array( 'discover' => false ) ) );
			}
			$this->assertFalse( $oembed->get_provider( 'https://showfm.dev.evil.test/my-show', array( 'discover' => false ) ) );
			$this->assertSame( 0, $http->count() );
		} finally {
			$http->detach();
		}
	}
}
