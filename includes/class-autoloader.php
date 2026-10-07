<?php
/**
 * Class autoloader.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads `ShowFM\Some_Class` from `includes/class-some-class.php`.
 */
final class Autoloader {

	/**
	 * Registers the autoloader once.
	 */
	public static function register(): void {
		spl_autoload_register( array( self::class, 'load' ) );
	}

	/**
	 * Loads one class from the plugin's namespace. Other classes are ignored.
	 *
	 * @param string $class_name Fully qualified class name.
	 */
	public static function load( string $class_name ): void {
		$prefix = __NAMESPACE__ . '\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		if ( '' === $relative || false !== strpos( $relative, '\\' ) ) {
			return;
		}

		$file = __DIR__ . '/class-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
