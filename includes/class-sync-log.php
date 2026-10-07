<?php
/**
 * Bounded, content-free sync diagnostics.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Stores only plugin-owned reason codes, never remote error text or credentials. */
final class Sync_Log {
	const OPTION = 'showfm_sync_log';
	const CODES  = array( 'row_invalid', 'row_apply_failed', 'row_post_type', 'row_author', 'row_write_failed', 'row_attempts_exhausted', 'report_missing_post', 'report_invalid_url', 'report_detached', 'report_400', 'report_403', 'report_404', 'report_terminal', 'report_retry', 'artwork_terminal', 'artwork_retry', 'artwork_exhausted', 'author_invalid', 'internal_error' );

	/**
	 * Record a reason and optional local row sequence. Keep at most 50 entries.
	 *
	 * @param string $code Own reason code.
	 * @param int    $seq Feed sequence, or zero.
	 */
	public static function record( string $code, int $seq = 0 ): void {
		$code  = in_array( $code, self::CODES, true ) ? $code : 'internal_error';
		$log   = (array) get_option( self::OPTION, array() );
		$log[] = array(
			'code' => $code,
			'seq'  => $seq,
			'at'   => time(),
		);
		update_option( self::OPTION, array_slice( $log, -50 ), false );
		update_option( Health::SYNC_ERRORS_OPTION, min( 1000000, 1 + (int) get_option( Health::SYNC_ERRORS_OPTION, 0 ) ), false );
	}
}
