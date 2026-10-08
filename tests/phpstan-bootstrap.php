<?php
/**
 * Constants PHPStan needs to analyse the plugin outside WordPress.
 *
 * @package ShowFM
 */

define( 'SHOWFM_VERSION', '1.0.1' );
define( 'SHOWFM_FILE', dirname( __DIR__ ) . '/showfm.php' );
define( 'SHOWFM_DIR', dirname( __DIR__ ) );
define( 'WPINC', 'wp-includes' );

require_once __DIR__ . '/stubs/wp-cli.php';
require_once __DIR__ . '/stubs/cli-prompt.php';
