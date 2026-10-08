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
		// The hosts in use: production's, or those of the environment the site selected.
		$environment = Environment::get();
		$api         = (string) wp_parse_url( $environment['api'], PHP_URL_HOST );
		$media       = Environment::is_production()
			? __( 'm.cdn.media, or media.podcasterplus.com for some older episodes', 'showfm' )
			: wp_sprintf_l( '%l', $environment['media'] );
		$paragraphs  = array(
			__( 'This site uses the show.fm plugin to show podcast episodes. show.fm is a podcast hosting service run by show.fm Ltd.', 'showfm' ),
			sprintf(
				/* translators: 1: show.fm API host, such as api.show.fm. 2: show.fm media hosts, such as "m.cdn.media, or media.podcasterplus.com for some older episodes". */
				__( 'This site\'s show.fm blocks ask the visitor\'s browser to request files from show.fm. The Player loads the episode\'s details from %1$s and its artwork as soon as it appears, the audio when the visitor plays it, and the transcript file when the visitor opens the transcript (or as soon as it appears, if the transcript is set to open). The Episode list loads the show\'s episode details from %1$s and each episode\'s artwork as soon as it appears, the audio when the visitor plays an episode, and that episode\'s transcript file when the visitor opens its transcript. The Play button loads the episode\'s details from %1$s as soon as it appears, and the audio and artwork when the visitor plays it. The Transcript loads the episode\'s details from %1$s and the transcript file as soon as it appears. Artwork, audio and transcript files come from %2$s. With "Load players only after a visitor clicks" turned on, each block first shows a button ("Play podcast episode", "Load episodes" or "Load transcript"), and nothing is requested from show.fm until the visitor presses it. The block then makes the requests above. show.fm then sees the visitor\'s IP address and browser details, as with any file on the web. This site\'s server sends no visitor data to show.fm.', 'showfm' ),
				$api,
				$media
			),
			__( 'When this site is connected to a show.fm account, the site\'s server sends show.fm: the site\'s address, its name, the WordPress, PHP and plugin versions, the site key show.fm issued, sync times and error counts, the feed sequence number, and the address, ID, publication state and source content hash of each post synced from show.fm. It never uploads the WordPress post text. When featured images are enabled, the server downloads public artwork into the media library; the image host sees the server IP address, but never the site key. It does not send visitor data. show.fm sends this site signed wake-up requests that carry no personal data.', 'showfm' ),
			__( 'While connected, the site\'s server also asks show.fm for the account holder’s name and the names and addresses of the shows the site posts for, right after connecting and once a day. It keeps them in the WordPress database to show on the plugin\'s settings screen, and deletes them when the site disconnects or the plugin is uninstalled. When an administrator disconnects the site, the site\'s server asks show.fm to revoke the site\'s key. The plugin keeps a list of the last 20 changes it made to posts (the episode title, the show and the post), which only administrators see. For each administrator, the plugin also remembers which of its admin notices they dismissed.', 'showfm' ),
			__( 'show.fm\'s privacy policy is at https://show.fm/privacy.', 'showfm' ),
		);
		return implode( "\n\n", $paragraphs );
	}
}
