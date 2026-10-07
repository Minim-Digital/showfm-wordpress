<?php
/**
 * UTF-8 safe text helpers.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cuts text to a number of characters without splitting one. mbstring is optional in
 * WordPress, so without it this counts code points with a UTF-8 aware regular expression,
 * and keeps bytes only when the text is not valid UTF-8.
 */
final class Text {

	/**
	 * Whether to use mbstring: null detects it. Tests set false for the fallback.
	 *
	 * @var bool|null
	 */
	public static $mbstring = null;

	/**
	 * At most `$max` characters of `$text`.
	 *
	 * @param string    $text     Text.
	 * @param int       $max      Most characters kept (1 to 65535).
	 * @param bool|null $mbstring Whether to use mbstring; null uses `$mbstring`, then detects it.
	 */
	public static function cut( string $text, int $max, ?bool $mbstring = null ): string {
		$max = max( 1, min( 65535, $max ) );
		if ( $mbstring ?? self::$mbstring ?? function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, 0, $max, 'UTF-8' );
		}
		if ( preg_match( '/\A.{0,' . $max . '}/su', $text, $match ) ) {
			return $match[0];
		}
		// Invalid UTF-8: keep the bytes up to the cap rather than fail.
		return substr( $text, 0, $max );
	}
}
