<?php
/**
 * Suggested text for the site's privacy policy.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a section to Settings, Privacy, Policy Guide that says what the plugin sends to
 * show.fm. Runs on `admin_init`.
 */
final class Privacy {

	/**
	 * Registers the suggested text.
	 */
	public static function register(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content( __( 'show.fm', 'showfm' ), wp_kses_post( wpautop( self::text(), false ) ) );
	}

	/**
	 * The suggested text, plain.
	 */
	public static function text(): string {
		$paragraphs = array(
			__( 'This site uses the show.fm plugin to show podcast episodes. show.fm is a podcast hosting service run by show.fm Ltd.', 'showfm' ),
			__( 'When a podcast player loads, the visitor\'s browser requests public episode details from api.show.fm and may load artwork from its image host. It loads audio and transcript files from show.fm (m.cdn.media) only when the visitor plays an episode or opens a transcript. show.fm then sees the visitor\'s IP address and browser details, as with any audio file on the web. This site\'s server sends no visitor data to show.fm.', 'showfm' ),
			__( 'When this site is connected to a show.fm account, the site\'s server sends show.fm: the site\'s address, its name, the WordPress, PHP and plugin versions, the site key show.fm issued, sync times and error counts, the feed sequence number, and the address, ID, publication state and source content hash of each post synced from show.fm. It never uploads the WordPress post text. When featured images are enabled, the server downloads public artwork into the media library; the image host sees the server IP address, but never the site key. It does not send visitor data. show.fm sends this site signed wake-up requests that carry no personal data.', 'showfm' ),
			__( 'show.fm\'s privacy policy is at https://show.fm/privacy.', 'showfm' ),
		);
		return implode( "\n\n", $paragraphs );
	}
}
