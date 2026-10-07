<?php
/**
 * A small stand-in for WP-CLI. PHPStan reads it for the signatures, and PHPUnit uses it to
 * run the commands without WP-CLI: output is recorded, and `error()` and a declined
 * `confirm()` throw instead of exiting.
 *
 * @package ShowFM
 */

if ( ! class_exists( 'ShowFM_Cli_Halt' ) ) {
	/**
	 * Thrown where WP-CLI would exit.
	 */
	class ShowFM_Cli_Halt extends RuntimeException {
	}
}

if ( ! class_exists( 'WP_CLI' ) ) {
	/**
	 * Records what the commands print.
	 */
	class WP_CLI {

		/**
		 * Lines printed, each as [type, message].
		 *
		 * @var array<int,array{0:string,1:string}>
		 */
		public static $output = array();

		/**
		 * Commands registered.
		 *
		 * @var array<string,mixed>
		 */
		public static $commands = array();

		/**
		 * Registers a command.
		 *
		 * @param string              $name     Command name.
		 * @param callable|object|string $callable Command.
		 * @param array<string,mixed> $args     Options.
		 */
		public static function add_command( $name, $callable, $args = array() ): bool {
			self::$commands[ $name ] = $callable;
			return true;
		}

		/**
		 * Prints a line.
		 *
		 * @param string $message Message.
		 */
		public static function line( $message = '' ): void {
			self::$output[] = array( 'line', $message );
		}

		/**
		 * Prints a log line.
		 *
		 * @param string $message Message.
		 */
		public static function log( $message ): void {
			self::$output[] = array( 'log', $message );
		}

		/**
		 * Prints a success line.
		 *
		 * @param string $message Message.
		 */
		public static function success( $message ): void {
			self::$output[] = array( 'success', $message );
		}

		/**
		 * Prints a warning.
		 *
		 * @param string $message Message.
		 */
		public static function warning( $message ): void {
			self::$output[] = array( 'warning', $message );
		}

		/**
		 * Prints an error and halts.
		 *
		 * @param string    $message Message.
		 * @param bool|int  $halt    Whether to halt.
		 * @return void
		 * @throws ShowFM_Cli_Halt Always, where WP-CLI would exit.
		 */
		public static function error( $message, $halt = true ) {
			self::$output[] = array( 'error', $message );
			throw new ShowFM_Cli_Halt( $message );
		}

		/**
		 * Asks for confirmation; halts unless --yes was passed.
		 *
		 * @param string              $question   Question.
		 * @param array<string,mixed> $assoc_args Named arguments.
		 * @throws ShowFM_Cli_Halt When not confirmed.
		 */
		public static function confirm( $question, $assoc_args = array() ): void {
			self::$output[] = array( 'confirm', $question );
			if ( empty( $assoc_args['yes'] ) ) {
				throw new ShowFM_Cli_Halt( 'Not confirmed.' );
			}
		}
	}
}
