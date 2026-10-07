<?php
/**
 * The shipped code has no logging or dump calls, so no key, code or secret can reach a log.
 *
 * @package ShowFM
 */

/**
 * Source scan (plan section 10: keys are never logged).
 */
class Test_No_Logging extends WP_UnitTestCase {

	public function test_plugin_code_has_no_log_or_dump_calls(): void {
		$files = array_merge( glob( SHOWFM_DIR . '/includes/*.php' ), array( SHOWFM_FILE, SHOWFM_DIR . '/uninstall.php' ) );
		$this->assertNotEmpty( $files );

		$banned = array( 'error_log', 'trigger_error', 'user_error', 'var_dump', 'var_export', 'print_r', 'debug_print_backtrace', 'syslog' );
		$found  = array();
		foreach ( $files as $file ) {
			foreach ( token_get_all( (string) file_get_contents( $file ) ) as $token ) {
				if ( is_array( $token ) && T_STRING === $token[0] && in_array( strtolower( $token[1] ), $banned, true ) ) {
					$found[] = basename( $file ) . ':' . $token[2] . ' ' . $token[1];
				}
			}
		}
		$this->assertSame( array(), $found );
	}
}
