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
	 * @param int $post_id Single-post restriction, or zero.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function start( int $post_id = 0 ) {
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
		Migration_Store::clear();
		$run   = wp_generate_uuid4();
		$pages = Migration_Catalogue::fetch( $this->api, $run );
		if ( is_wp_error( $pages ) ) {
			return $pages;
		}
		$state = array(
			'run'        => $run,
			'connection' => $this->fingerprint(),
			'pages'      => $pages,
			'after'      => 0,
			'upper'      => Migration_Scanner::upper_bound( $post_id ),
			'post_id'    => $post_id,
			'complete'   => false,
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
	public function batch() {
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
			$post = get_post( $id );
			if ( $post ) {
				$report = Migration_Scanner::scan(
					$post,
					static function () use ( $state ) {
						return Migration_Catalogue::episodes( $state['run'], $state['pages'] );
					}
				);
				Migration_Store::put( $state['run'], $report );
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
	public function swap( int $post_id, array $choices = array() ) {
		$access = $this->access();
		if ( is_wp_error( $access ) ) {
			return $access;
		}
		$state = Migration_Store::state();
		if ( empty( $state['complete'] ) || $state['connection'] !== $this->fingerprint() ) {
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

	/** Hash only; credentials never enter the report. */
	private function fingerprint(): string {
		return hash( 'sha256', (string) $this->connection->key() );
	}
}
