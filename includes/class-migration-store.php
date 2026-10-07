<?php
/**
 * Per-site migration reports for the CLI and future Migrate screen.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Each post report and catalogue page is separate and never autoloaded. */
final class Migration_Store {
	const STATE = 'showfm_migration_state';

	/**
	 * Current job, without fetching report bodies.
	 *
	 * @return array<string,mixed>
	 */
	public static function state(): array {
		$value = get_option( self::STATE, array() );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Save the resumable cursor.
	 *
	 * @param array<string,mixed> $state Job state.
	 */
	public static function save( array $state ): void {
		update_option( self::STATE, $state, false );
	}

	/**
	 * Write one report. It includes byte locations, candidates and the undo revision.
	 *
	 * @param string              $run    Run UUID.
	 * @param array<string,mixed> $report Post report.
	 */
	public static function put( string $run, array $report ): void {
		$key = self::key( $run, $report['post_id'] );
		update_option( $key, $report, false );
		wp_cache_delete( $key, 'options' );
	}

	/**
	 * Read one post's report for the future admin screen.
	 *
	 * @param string $run     Run UUID.
	 * @param int    $post_id Post ID.
	 * @return array<string,mixed>
	 */
	public static function get( string $run, int $post_id ): array {
		$key   = self::key( $run, $post_id );
		$value = get_option( $key, array() );
		wp_cache_delete( $key, 'options' );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Enumerate reports in bounded pages, even when source posts have since been deleted.
	 *
	 * @param string $run Run UUID.
	 * @return \Generator<int,array<string,mixed>>
	 */
	public static function reports( string $run ): \Generator {
		global $wpdb;
		$after = '';
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Keyset enumeration of non-autoloaded report options.
			$keys = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name > %s ORDER BY option_name LIMIT 50", $wpdb->esc_like( 'showfm_migration_' . $run . '_post_' ) . '%', $after ) );
			foreach ( $keys as $key ) {
				$report = get_option( $key, array() );
				if ( is_array( $report ) ) {
					yield $report;
				}
				wp_cache_delete( $key, 'options' );
				$after = $key;
			}
			$size = count( $keys );
		} while ( 50 === $size );
	}

	/**
	 * Forget the previous run in bounded batches before starting another.
	 */
	public static function clear(): void {
		global $wpdb;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Bounded cleanup of this plugin's report namespace.
			$keys = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 50", $wpdb->esc_like( 'showfm_migration_' ) . '%' ) );
			foreach ( $keys as $key ) {
				delete_option( $key );
			}
			$size = count( $keys );
		} while ( 50 === $size );
	}

	/**
	 * Option name, scoped to the current blog by WordPress.
	 *
	 * @param string $run     Run UUID.
	 * @param int    $post_id Post ID.
	 */
	private static function key( string $run, int $post_id ): string {
		return 'showfm_migration_' . $run . '_post_' . $post_id;
	}
}
