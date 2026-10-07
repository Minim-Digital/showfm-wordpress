<?php
/**
 * Site-wide display settings read by the renderers and edited on the Display tab.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Site-local rendering preferences. */
final class Embed_Settings {

	/** "Show 'Powered by show.fm'". Off by default (WordPress.org guideline 10). */
	const CREDIT = 'showfm_show_credit';

	/** "Load players only after a visitor clicks". Off by default. */
	const LOAD_ON_CLICK = 'showfm_load_on_click';

	/** "Add episode structured data for search engines". On by default. */
	const JSON_LD = 'showfm_json_ld';

	/** "Use my theme's colours and fonts". On by default. */
	const THEME_STYLES = 'showfm_theme_styles';

	/**
	 * Each setting and its default.
	 *
	 * @var array<string,bool>
	 */
	const DEFAULTS = array(
		self::CREDIT        => false,
		self::LOAD_ON_CLICK => false,
		self::JSON_LD       => true,
		self::THEME_STYLES  => true,
	);

	/**
	 * Registers the settings, shown in the REST API to users who can manage options. No
	 * option is written and no request is made.
	 */
	public static function register(): void {
		$labels = array(
			self::CREDIT        => __( 'Show “Powered by show.fm”', 'showfm' ),
			self::LOAD_ON_CLICK => __( 'Load players only after a visitor clicks', 'showfm' ),
			self::JSON_LD       => __( 'Add episode structured data for search engines', 'showfm' ),
			self::THEME_STYLES  => __( 'Use my theme’s colours and fonts', 'showfm' ),
		);
		foreach ( self::DEFAULTS as $name => $default ) {
			register_setting(
				'showfm',
				$name,
				array(
					'type'              => 'boolean',
					'default'           => $default,
					'sanitize_callback' => 'rest_sanitize_boolean',
					'description'       => $labels[ $name ],
					'show_in_rest'      => true,
				)
			);
		}
	}

	/**
	 * A setting's value, or its default when it was never saved.
	 *
	 * @param string $name One of the setting constants.
	 */
	public static function enabled( string $name ): bool {
		return (bool) get_option( $name, self::DEFAULTS[ $name ] ?? false );
	}
}
