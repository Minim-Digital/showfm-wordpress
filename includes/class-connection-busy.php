<?php
/**
 * Raised when another request holds the connection lock for longer than a short wait.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Another change to the connection is in progress. Nothing was changed; try again.
 */
final class Connection_Busy extends \RuntimeException {
}
