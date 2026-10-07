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
	 * Prevent re-entrant operations on the same database session.
	 *
	 * @var array<string,bool>
	 */
	private static $held = array();

	/**
	 * Current job, without fetching report bodies.
	 *
	 * @return array<string,mixed>
	 */
	public static function state(): array {
		global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A CLI process must see state changed by another request after acquiring the lock.
		$raw   = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::STATE ) );
		$value = maybe_unserialize( $raw );
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
	 * The next few reports in post ID order, so a resumable swap can keep a numeric cursor.
	 *
	 * @param string $run   Run UUID.
	 * @param int    $after Last post ID already handled.
	 * @param int    $limit Most reports to return.
	 * @return array<int,array<string,mixed>>
	 */
	public static function after( string $run, int $after, int $limit ): array {
		global $wpdb;
		$prefix = 'showfm_migration_' . $run . '_post_';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Keyset page of non-autoloaded report options, by numeric post ID.
		$keys    = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(SUBSTRING(option_name, %d) AS UNSIGNED) > %d ORDER BY CAST(SUBSTRING(option_name, %d) AS UNSIGNED) LIMIT %d", $wpdb->esc_like( $prefix ) . '%', strlen( $prefix ) + 1, $after, strlen( $prefix ) + 1, $limit ) );
		$reports = array();
		foreach ( $keys as $key ) {
			$report = get_option( $key, array() );
			wp_cache_delete( $key, 'options' );
			if ( is_array( $report ) ) {
				$reports[] = $report;
			}
		}
		return $reports;
	}

	/**
	 * Forget old runs only after the replacement catalogue has succeeded.
	 *
	 * @param string $keep Run to retain, or empty to remove everything.
	 */
	public static function clear( string $keep = '' ): void {
		global $wpdb;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Bounded cleanup of this plugin's report namespace.
			$keys = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s LIMIT 50", $wpdb->esc_like( 'showfm_migration_' ) . '%', $keep ? $wpdb->esc_like( 'showfm_migration_' . $keep . '_' ) . '%' : '' ) );
			foreach ( $keys as $key ) {
				delete_option( $key );
			}
			$size = count( $keys );
		} while ( 50 === $size );
	}

	/**
	 * Discard only a pending run's pages and rows, keeping the active report intact.
	 *
	 * @param string $run Pending run UUID.
	 */
	public static function discard( string $run ): void {
		if ( '' === $run ) {
			return;
		}
		global $wpdb;
		do {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Bounded cleanup of one abandoned run, with a literal escaped prefix.
			$keys = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 50", $wpdb->esc_like( 'showfm_migration_' . $run . '_' ) . '%' ) );
			foreach ( $keys as $key ) {
				delete_option( $key );
			}
			$size = count( $keys );
		} while ( 50 === $size );
	}

	/**
	 * Serialise report state transitions on this site, including CLI requests.
	 *
	 * @param callable $operation Operation.
	 * @param int      $wait      Seconds to wait for another request's lock, zero by default.
	 * @return mixed
	 */
	public static function locked( callable $operation, int $wait = 0 ) {
		global $wpdb;
		$name = 'showfm_migrate_' . hash( 'sha256', $wpdb->dbname . $wpdb->options );
		$name = substr( $name, 0, 64 );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Connection-owned advisory lock, released in finally.
		if ( isset( self::$held[ $name ] ) || '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, max( 0, $wait ) ) ) ) {
			return new \WP_Error( 'showfm_busy', __( 'Another migration request is active. Resume when it finishes.', 'showfm' ) );
		}
		self::$held[ $name ] = true;
		try {
			return $operation();
		} finally {
			unset( self::$held[ $name ] );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Release the same connection-owned lock.
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		}
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
