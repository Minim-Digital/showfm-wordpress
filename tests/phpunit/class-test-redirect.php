<?php
/**
 * Exception for capturing redirects in tests.
 *
 * @package ShowFM
 */

/**
 * Thrown by a `wp_redirect` filter so a test can see where a handler redirects, instead of
 * the handler reaching `exit`.
 */
class ShowFM_Test_Redirect extends RuntimeException {
}
