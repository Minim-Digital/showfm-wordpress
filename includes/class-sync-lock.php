<?php
/**
 * Database session lock for a single site's pull.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** A database lock cannot expire underneath a slow live worker and releases on crash. */
final class Sync_Lock {
	/**
	 * Database-scoped lock name.
	 *
	 * @var string
	 */
	private $name;

	/** Build a lock isolated by database and blog, including multisite. */
	public function __construct() {
		global $wpdb;
		$this->name = 'showfm_' . md5( $wpdb->dbname . ':' . $wpdb->prefix );
	}

	/** Claim without waiting. Never use an object-cache lock for correctness. */
	public function acquire(): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- MySQL session locks must bypass caches.
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $this->name ) );
	}

	/** Whether the current database session still owns this lock after a reconnect. */
	public function owned(): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Detect loss of the database session, never trust a cached claim.
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $this->name ) );
	}

	/** Release only this database session's lock. A killed connection releases it too. */
	public function release(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- MySQL session locks must bypass caches.
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->name ) );
	}
}
