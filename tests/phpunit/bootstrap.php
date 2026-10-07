<?php
/**
 * PHPUnit bootstrap: loads the WordPress test suite and the plugin.
 *
 * Every HTTP request in the suite goes through a final `pre_http_request` filter that
 * records it and blocks it, so no test can reach the network. Requests made while the
 * plugin loads and WordPress runs `init` are kept for `Test_No_Outbound_Http`. The core
 * test bootstrap defines DISABLE_WP_CRON, so core's cron spawner adds no loopback calls.
 *
 * @package ShowFM
 */

$showfm_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $showfm_tests_dir ) {
	$showfm_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $showfm_tests_dir . '/includes/functions.php' ) ) {
	echo 'Could not find the WordPress test suite in ' . $showfm_tests_dir . '. Run the tests through wp-env: npm run test:php' . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills' );

require_once $showfm_tests_dir . '/includes/functions.php';

$GLOBALS['showfm_bootstrap_http'] = array();

tests_add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( false !== $pre ) {
			return $pre;
		}
		$GLOBALS['showfm_bootstrap_http'][] = $url;
		return new WP_Error( 'showfm_test_blocked', 'Outbound HTTP is blocked in tests.' );
	},
	PHP_INT_MAX,
	3
);

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__, 2 ) . '/showfm.php';
	}
);

tests_add_filter(
	'init',
	static function () {
		$GLOBALS['showfm_http_after_init'] = $GLOBALS['showfm_bootstrap_http'];
	},
	PHP_INT_MAX
);

require $showfm_tests_dir . '/includes/bootstrap.php';
require __DIR__ . '/class-http-mock.php';
