<?php
/**
 * Independent outbox for user trash and deletion receipts.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Each event has its own indexed option, so a concurrent pull cannot overwrite it. */
final class Sync_Local_Reports {
	const PREFIX = 'showfm_local_report_';
	const SCAN   = 'showfm_local_report_scan';

	/**
	 * Queue the latest local state without making HTTP in a WordPress editing request.
	 *
	 * @param string              $site Original connection.
	 * @param string              $episode Original episode.
	 * @param array<string,mixed> $body Saved report, independent of the deleted post.
	 * @throws \RuntimeException If the outbox cannot be saved.
	 */
	public static function queue( string $site, string $episode, array $body ): void {
		$key      = self::PREFIX . $site . '_' . $episode;
		$previous = get_option( $key );
		if ( is_array( $previous ) && $previous['body'] === $body ) {
			return;
		}
		$entry = array(
			'episode'  => $episode,
			'body'     => $body,
			'token'    => wp_generate_uuid4(),
			'attempts' => 0,
			'next'     => 0,
		);
		if ( ! update_option( $key, $entry, false ) && get_option( $key ) !== $entry ) {
			throw new \RuntimeException( 'Local post report could not be saved.' );
		}
		Sync::wake( time() + 1 );
	}

	/**
	 * Drain a bounded batch under the pull lock, with the normal report response policy.
	 *
	 * @param string $site Current connection.
	 */
	public static function send( string $site ): ?Api_Result {
		$prefix = self::PREFIX . $site . '_';
		$after  = (string) get_option( self::SCAN, '' );
		// Rotate the scan, including future retries, so no batch can starve later entries.
		$keys = self::keys( $prefix, $after );
		if ( ! $keys && '' !== $after ) {
			$keys = self::keys( $prefix, '' );
		}
		foreach ( $keys as $key ) {
			Sync::require_lock();
			$entry = get_option( $key );
			if ( ! is_array( $entry ) ) {
				continue;
			}
			if ( $entry['next'] > time() ) {
				Sync::wake( $entry['next'] );
				continue;
			}
			if ( null === Sync::validate_post_url( $entry['body']['post_url'] ) ) {
				Sync_Log::record( 'report_invalid_url' );
				self::acknowledge( $key, $entry );
				continue;
			}
			$response = Plugin::api_client()->post_keyed( '/v1/me/sites/' . rawurlencode( $site ) . '/episodes/' . rawurlencode( $entry['episode'] ) . '/post', $entry['body'] );
			Sync::require_lock();
			if ( $response->is( Api_Result::UNAUTHORISED ) || $response->is( Api_Result::RATE_LIMITED ) ) {
				return $response;
			}
			if ( $response->is( Api_Result::TRANSIENT_FAILURE ) ) {
				$previous = $entry;
				++$entry['attempts'];
				$entry['next'] = time() + min( 3600, 30 * ( 2 ** min( 7, $entry['attempts'] - 1 ) ) );
				self::acknowledge( $key, $previous, $entry );
				Sync_Log::record( 'report_retry' );
				Sync::wake( $entry['next'] );
			} else {
				if ( ! $response->is( Api_Result::SUCCESS ) ) {
					$code = in_array( $response->status(), array( 400, 403, 404 ), true ) ? 'report_' . $response->status() : 'report_terminal';
					Sync_Log::record( $code );
				}
				self::acknowledge( $key, $entry );
			}
		}
		if ( $keys ) {
			update_option( self::SCAN, end( $keys ), false );
			if ( count( $keys ) === Sync::PAGE_SIZE ) {
				Sync::wake( time() + 15 );
			}
		}
		return null;
	}
	/**
	 * Acknowledge only the snapshot sent, preserving a concurrent restore or trash.
	 *
	 * @param string                   $key Indexed outbox key.
	 * @param array<string,mixed>      $sent Snapshot sent.
	 * @param array<string,mixed>|null $retry Updated retry or null to remove.
	 * @throws \RuntimeException If storage fails.
	 */
	private static function acknowledge( string $key, array $sent, ?array $retry = null ): void {
		global $wpdb;
		if ( null === $retry ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Compare and delete protects a newer local state.
			$result = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $key, maybe_serialize( $sent ) ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Compare and update protects a newer local state.
			$result = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", maybe_serialize( $retry ), $key, maybe_serialize( $sent ) ) );
		}
		wp_cache_delete( $key, 'options' );
		if ( false === $result ) {
			throw new \RuntimeException( 'Local report acknowledgement failed.' );
		}
		if ( 0 === $result ) {
			Sync::wake( time() + 1 );
		}
	}

	/**
	 * Read one indexed range of pending reports for this connection.
	 *
	 * @param string $prefix Connection-specific option prefix.
	 * @param string $after Last key scanned.
	 * @return string[]
	 * @throws \RuntimeException If the outbox lookup fails.
	 */
	private static function keys( string $prefix, string $after ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Indexed prefix and keyset pagination avoid shared option updates.
		$keys = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name > %s ORDER BY option_name LIMIT %d", $wpdb->esc_like( $prefix ) . '%', $after, Sync::PAGE_SIZE ) );
		if ( $wpdb->last_error ) {
			throw new \RuntimeException( 'Local reports lookup failed.' );
		}
		return $keys;
	}
}
