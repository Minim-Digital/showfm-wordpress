<?php
/**
 * A stand-in for WP-CLI's `\cli\prompt()`. PHPStan reads it for the signature, and PHPUnit
 * uses it to answer prompts: it returns ShowFM_Cli_Prompt::$answer and records each call.
 *
 * @package ShowFM
 */

namespace {
	if ( ! class_exists( 'ShowFM_Cli_Prompt' ) ) {
		/**
		 * Prompt answers and calls.
		 */
		class ShowFM_Cli_Prompt {

			/**
			 * What the next prompt returns.
			 *
			 * @var string
			 */
			public static $answer = '';

			/**
			 * Each call as [question, hide].
			 *
			 * @var array<int,array{0:string,1:bool}>
			 */
			public static $calls = array();
		}
	}
}

namespace cli {
	if ( ! function_exists( 'cli\prompt' ) ) {
		/**
		 * Asks a question.
		 *
		 * @param string            $question Question.
		 * @param string|false|null $default  Answer for empty input.
		 * @param string            $marker   Text after the question.
		 * @param bool              $hide     Whether to hide the input.
		 * @return string
		 */
		function prompt( $question, $default = null, $marker = ': ', $hide = false ) {
			\ShowFM_Cli_Prompt::$calls[] = array( $question, $hide );
			return \ShowFM_Cli_Prompt::$answer;
		}
	}
}
