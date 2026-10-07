<?php
/**
 * Renewable options lease with an additional MySQL session lock when available.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * All backends contend on one unique options row and release only their own token. The sync
 * uses the default row; the connection state uses its own (see `Connection::mutate()`).
 */
final class Sync_Lock {
	const OPTION = 'showfm_sync_lock';
	const TTL    = 300;
	/**
	 * Database-scoped advisory lock name.
	 *
	 * @var string
	 */
	private $name;
	/**
	 * Options row holding the lease.
	 *
	 * @var string
	 */
	private $option;
	/**
	 * Lease length in seconds.
	 *
	 * @var int
	 */
	private $ttl;
	/**
	 * Exact options row owned by this instance.
	 *
	 * @var string
	 */
	private $value = '';
	/**
	 * Whether this instance also holds a named session lock.
	 *
	 * @var bool
	 */
	private $named = false;

	/**
	 * Isolate lock names by database, blog and lease row.
	 *
	 * @param string $option Options row holding the lease.
	 * @param int    $ttl    Lease length in seconds.
	 */
	public function __construct( string $option = self::OPTION, int $ttl = self::TTL ) {
		global $wpdb;
		$this->option = $option;
		$this->ttl    = $ttl;
		$this->name   = 'showfm_' . md5( $wpdb->dbname . ':' . $wpdb->prefix . ( self::OPTION === $option ? '' : ':' . $option ) );
	}

	/** Acquire without waiting, falling back on unsupported or multiplexed databases. */
	public function acquire(): bool {
		global $wpdb;
		if ( apply_filters( 'showfm_sync_use_named_lock', true ) ) {
			$hidden = $wpdb->suppress_errors();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Advisory locks bypass all caches.
			$named = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $this->name ) );
			$wpdb->suppress_errors( $hidden );
			if ( '0' === (string) $named ) {
				return false;
			}
			$this->named = '1' === (string) $named;
			if ( $this->named && ! $this->session_owned() ) {
				$this->release();
			}
		}
		$old   = $this->read();
		$parts = explode( '|', $old );
		// Winning the named lock proves that a former named owner has disconnected.
		$dead = $this->named && 'named' === ( $parts[2] ?? '' ) && ( $parts[3] ?? '' ) !== $this->session_id();
		if ( '' !== $old && (int) ( $parts[1] ?? 0 ) > time() && ! $dead ) {
			$this->release();
			return false;
		}
		$value = wp_generate_uuid4() . '|' . ( time() + $this->ttl ) . '|' . ( $this->named ? 'named' : 'options' ) . '|' . $this->session_id();
		if ( '' === $old ) {
			wp_cache_delete( $this->option, 'options' );
			$hidden = $wpdb->suppress_errors();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A plain INSERT must lose on duplicate, never overwrite another owner's lease.
			$claimed = 1 === $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $this->option, $value ) );
			$wpdb->suppress_errors( $hidden );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Compare-and-swap prevents two stale-lock claimants from winning.
			$claimed = 1 === $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, $this->option, $old ) );
		}
		wp_cache_delete( $this->option, 'options' );
		if ( ! $claimed ) {
			$this->release();
			return false;
		}
		$this->value = $value;
		return true;
	}

	/** Check and renew the lease before work, refusing an expired or superseded owner. */
	public function owned(): bool {
		if ( '' === $this->value || $this->read() !== $this->value || ( $this->named && ! $this->session_owned() ) ) {
			return false;
		}
		$parts = explode( '|', $this->value );
		if ( (int) $parts[1] <= time() ) {
			return false;
		}
		$next = $parts[0] . '|' . ( time() + $this->ttl ) . '|' . $parts[2] . '|' . $parts[3];
		if ( $next !== $this->value ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Renew only the exact token we still own.
			if ( 1 !== $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $next, $this->option, $this->value ) ) ) {
				return false;
			}
			$this->value = $next;
			wp_cache_delete( $this->option, 'options' );
		}
		return true;
	}

	/** Release only this owner's token, never a newer worker's lease. */
	public function release(): void {
		global $wpdb;
		if ( '' !== $this->value ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Conditional delete cannot remove a replacement lease.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $this->option, $this->value ) );
			wp_cache_delete( $this->option, 'options' );
			$this->value = '';
		}
		if ( $this->named ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Releases only this database connection's named lock.
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->name ) );
			$this->named = false;
		}
	}

	/** Read the authoritative options row, independent of object cache state. */
	private function read(): string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Lock correctness requires an uncached read.
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $this->option ) );
	}

	/** Identify re-entrant acquisitions on the same database connection. */
	private function session_id(): string {
		global $wpdb;
		if ( ! $this->named ) {
			return '';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Session IDs distinguish a dead holder from a re-entrant acquisition.
		return (string) $wpdb->get_var( 'SELECT CONNECTION_ID()' );
	}

	/** Detect both unsupported advisory locks and a lost database session. */
	private function session_owned(): bool {
		global $wpdb;
		$hidden = $wpdb->suppress_errors();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Session ownership cannot be cached.
		$result = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $this->name ) );
		$wpdb->suppress_errors( $hidden );
		return '1' === (string) $result;
	}
}
