<?php
/**
 * Shared migration service for WP-CLI and the future Migrate tab.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Explicit calls only. No frontend, cron or activation hooks. */
final class Migrator {
	/**
	 * Connection.
	 *
	 * @var Connection
	 */
	private $connection;
	/**
	 * Client.
	 *
	 * @var Api_Client
	 */
	private $api;

	/**
	 * Build without I/O.
	 *
	 * @param Connection $connection Connection.
	 * @param Api_Client $api        Client.
	 */
	public function __construct( Connection $connection, Api_Client $api ) {
		$this->connection = $connection;
		$this->api        = $api;
	}

	/**
	 * Refuse before any read or write, including CLI with no --user.
	 *
	 * @return true|\WP_Error
	 */
	public function access() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'showfm_forbidden', __( 'Use --user=<administrator> for a site administrator who can edit these posts.', 'showfm' ) );
		}
		if ( ! $this->connection->is_connected() ) {
			return new \WP_Error( 'showfm_not_connected', __( 'Connect this site to show.fm before migrating embeds.', 'showfm' ) );
		}
		return true;
	}

	/**
	 * Start a report only after all API pages have succeeded.
	 *
	 * @param int  $post_id Single-post restriction, or zero.
	 * @param bool $reset Discard pending acquisition and start afresh.
	 * @param int  $limit Most catalogue requests in this call, or zero for no limit.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function start_locked( int $post_id = 0, bool $reset = false, int $limit = 0 ) {
		$access = $this->access();
		if ( is_wp_error( $access ) ) {
			return $access;
		}
		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
			if ( ! $post || 'publish' !== $post->post_status || ! in_array( $post->post_type, array( 'post', 'page' ), true ) || ! current_user_can( 'edit_post', $post_id ) ) {
				return new \WP_Error( 'showfm_post_unavailable', __( 'Choose a published post or page you can edit.', 'showfm' ) );
			}
		}
		$pending = get_option( Migration_Catalogue::PENDING, array() );
		if ( $reset || ( $pending && ( $pending['connection'] ?? '' ) !== $this->fingerprint() ) ) {
			$this->reset_pending();
			$pending = array();
		}
		$run = $pending['run'] ?? wp_generate_uuid4();
		if ( $pending && ( $pending['post_id'] ?? 0 ) !== $post_id ) {
			return new \WP_Error( 'showfm_pending_scan', __( 'Resume the pending catalogue or use --dry-run --reset to change the post restriction.', 'showfm' ) );
		}
		if ( ! $pending ) {
			update_option(
				Migration_Catalogue::PENDING,
				array(
					'run'        => $run,
					'connection' => $this->fingerprint(),
					'post_id'    => $post_id,
				),
				false
			);
		}
		$pages = Migration_Catalogue::fetch( $this->api, $run, $limit );
		if ( is_wp_error( $pages ) ) {
			return $pages;
		}
		Migration_Store::clear( $run );
		$state = array(
			'run'        => $run,
			'connection' => $this->fingerprint(),
			'pages'      => $pages,
			'after'      => 0,
			'upper'      => Migration_Scanner::upper_bound( $post_id ),
			'post_id'    => $post_id,
			'complete'   => false,
			'dry_run'    => true,
			'created_at' => time(),
		);
		Migration_Store::save( $state );
		return $state;
	}

	/**
	 * Resume one batch and persist after each post, so interruption repeats at most one.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	private function batch_locked() {
		$access = $this->access();
		if ( is_wp_error( $access ) ) {
			return $access;
		}
		$state = Migration_Store::state();
		if ( ! $state || $state['connection'] !== $this->fingerprint() ) {
			return new \WP_Error( 'showfm_scan_required', __( 'Start a new scan for this connection.', 'showfm' ) );
		}
		$ids = Migration_Scanner::ids( $state['after'], $state['upper'], $state['post_id'] );
		foreach ( $ids as $id ) {
			$post = Migration_Scanner::fresh( $id );
			if ( $post ) {
				$report = Migration_Scanner::scan(
					$post,
					static function () use ( $state ) {
						return Migration_Catalogue::matcher( $state['run'], $state['pages'] );
					}
				);
				if ( $report['items'] || 'error' === $report['status'] ) {
					Migration_Store::put( $state['run'], $report );
				}
			}
			$state['after'] = $id;
			Migration_Store::save( $state );
			clean_post_cache( $id );
		}
		$state['complete'] = count( $ids ) < Migration_Scanner::BATCH_SIZE;
		Migration_Store::save( $state );
		return $state;
	}

	/**
	 * Apply one stored report with the same connection and local capabilities.
	 *
	 * @param int               $post_id Post ID.
	 * @param array<int,string> $choices Explicit ambiguous choices.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function swap_locked( int $post_id, array $choices = array() ) {
		$access = $this->access();
		if ( is_wp_error( $access ) ) {
			return $access;
		}
		$state = Migration_Store::state();
		// owns_now() reads the connection from the database for every post, so a reconnect to
		// another account part way through a step stops the swap at the next post.
		if ( empty( $state['dry_run'] ) || empty( $state['complete'] ) || ! $this->owns_now( $state ) ) {
			return new \WP_Error( 'showfm_scan_required', __( 'Complete a scan for this connection before swapping.', 'showfm' ) );
		}
		$report = Migration_Store::get( $state['run'], $post_id );
		if ( ! $report ) {
			return new \WP_Error( 'showfm_scan_required', __( 'This post has no migration report.', 'showfm' ) );
		}
		$result = Migration_Swap::apply( $this->connection, $report, $choices );
		if ( ! is_wp_error( $result ) ) {
			Migration_Store::put( $state['run'], $result );
		}
		clean_post_cache( $post_id );
		return $result;
	}

	/**
	 * Start or resume catalogue acquisition without replacing the active report on failure.
	 *
	 * @param int  $post_id Optional post restriction.
	 * @param bool $reset Discard pending acquisition and start afresh.
	 * @param int  $limit Most catalogue requests in this call, or zero for no limit. The
	 *                    Migrate tab passes a limit, so no request runs long; it then gets
	 *                    `showfm_catalogue_more` until the catalogue is complete.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function start( int $post_id = 0, bool $reset = false, int $limit = 0 ) {
		return Migration_Store::locked(
			function () use ( $post_id, $reset, $limit ) {
				return $this->start_locked( $post_id, $reset, $limit );
			}
		);
	}

	/**
	 * Forget pending acquisition without HTTP, even when the old key has been refused.
	 *
	 * @return true|\WP_Error
	 */
	public function reset() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'showfm_forbidden', __( 'Use --user=<administrator> to reset pending migration data.', 'showfm' ) );
		}
		return Migration_Store::locked(
			function () {
				$this->reset_pending();
				return true;
			}
		);
	}

	/** Discard only the abandoned pending run while holding the site lock. */
	private function reset_pending(): void {
		$pending = get_option( Migration_Catalogue::PENDING, array() );
		if ( isset( $pending['run'] ) && ( Migration_Store::state()['run'] ?? '' ) !== $pending['run'] ) {
			Migration_Store::discard( $pending['run'] );
		}
		delete_option( Migration_Catalogue::PENDING );
	}

	/**
	 * Advance a bound run under the per-site lock.
	 *
	 * @param string $run Expected run, or empty for the current run.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function batch( string $run = '' ) {
		return Migration_Store::locked(
			function () use ( $run ) {
				return $run && ( Migration_Store::state()['run'] ?? '' ) !== $run ? new \WP_Error( 'showfm_stale_run', __( 'The active scan changed.', 'showfm' ) ) : $this->batch_locked();
			}
		);
	}

	/**
	 * Apply only the specified reviewed run under the per-site lock.
	 *
	 * @param int               $post_id Post ID.
	 * @param array<int,string> $choices Explicit choices.
	 * @param string            $run Expected run.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function swap( int $post_id, array $choices = array(), string $run = '' ) {
		return Migration_Store::locked(
			function () use ( $post_id, $choices, $run ) {
				return $run && ( Migration_Store::state()['run'] ?? '' ) !== $run ? new \WP_Error( 'showfm_stale_run', __( 'The active scan changed.', 'showfm' ) ) : $this->swap_locked( $post_id, $choices );
			}
		);
	}

	/** Whether keyed calls may be made, so matching can run. */
	public function connected(): bool {
		return $this->connection->is_connected();
	}

	/**
	 * Whether a saved scan belongs to the stored connection, so its report can be applied.
	 * After a reconnect with another key the report needs a new scan.
	 *
	 * @param array<string,mixed> $state Saved scan state.
	 */
	public function owns( array $state ): bool {
		return $this->connection->is_connected() && is_string( $state['connection'] ?? null ) && hash_equals( $state['connection'], $this->fingerprint() );
	}

	/**
	 * Like owns(), but reads the connection from the database rather than this request's
	 * cache, for decisions made under the migration lock after waiting for it.
	 *
	 * @param array<string,mixed> $state Migration state.
	 */
	public function owns_now( array $state ): bool {
		$connection = $this->connection->pinned();
		return $connection->is_connected() && is_string( $state['connection'] ?? null ) && hash_equals( $state['connection'], hash( 'sha256', (string) $connection->key() ) );
	}

	/** Hash only; credentials never enter the report. */
	private function fingerprint(): string {
		return hash( 'sha256', (string) $this->connection->key() );
	}
}
