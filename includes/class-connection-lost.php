<?php
/**
 * Raised when a change to the connection loses its lock part way through.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The change took longer than its lease, and the lock expired or another request took it
 * over. The change stopped before its next write; what it wrote before that stays.
 */
final class Connection_Lost extends Connection_Busy {
}
