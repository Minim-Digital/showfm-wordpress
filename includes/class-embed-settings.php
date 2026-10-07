<?php
/**
 * Settings consumed by renderers; no admin screen in WP-2a.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Site-local rendering preferences. */
final class Embed_Settings {
	/** Register defaults without writing options or making HTTP requests. */
	public static function register(): void {
		register_setting(
			'showfm',
			'showfm_show_credit',
			array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'description'       => __( "Show 'Powered by show.fm'", 'showfm' ),
			)
		);
		register_setting(
			'showfm',
			'showfm_json_ld',
			array(
				'type'              => 'boolean',
				'default'           => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
			)
		);
	}
}
