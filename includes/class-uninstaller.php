<?php
/**
 * Removes the plugin's own data on uninstall.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deletes the plugin's options, transients, scheduled events and `_showfm_*` post meta.
 * It never deletes posts, and touches nothing without the plugin's prefix.
 */
final class Uninstaller {

	/** Sites handled per query on a multisite network. */
	const SITES_PER_BATCH = 100;

	/**
	 * Cleans every site on a network, or the single site.
	 */
	public static function run(): void {
		if ( ! is_multisite() ) {
			self::clean_site();
			return;
		}

		$offset = 0;
		do {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => self::SITES_PER_BATCH,
					'offset' => $offset,
				)
			);
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::clean_site();
				restore_current_blog();
			}
			$offset    += self::SITES_PER_BATCH;
			$batch_size = count( $site_ids );
		} while ( self::SITES_PER_BATCH === $batch_size );
	}

	/**
	 * Cleans the current site.
	 */
	public static function clean_site(): void {
		global $wpdb;

		foreach ( Plugin::CRON_HOOKS as $hook ) {
			wp_unschedule_hook( $hook );
		}

		// Options and transients carry the `showfm_` prefix. Direct queries are the only way
		// to find transients whose names are hashed.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup.
		$options = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( 'showfm_' ) . '%',
				$wpdb->esc_like( '_transient_showfm_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_showfm_' ) . '%'
			)
		);
		foreach ( $options as $option ) {
			delete_option( $option );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup.
		$meta_keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT meta_key FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
				$wpdb->esc_like( '_showfm_' ) . '%'
			)
		);
		foreach ( $meta_keys as $meta_key ) {
			delete_post_meta_by_key( $meta_key );
		}
	}
}
