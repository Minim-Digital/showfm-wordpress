<?php
/**
 * UTF-8 safe cutting, with and without mbstring.
 *
 * @package ShowFM
 */

use ShowFM\Account;
use ShowFM\Attributes;
use ShowFM\Text;

/**
 * Covers `Text::cut()` and the places that use it.
 */
class Test_Text extends WP_UnitTestCase {

	public function tear_down(): void {
		Text::$mbstring = null;
		parent::tear_down();
	}

	/**
	 * @dataProvider modes
	 *
	 * @param bool $mbstring Whether to use mbstring.
	 */
	public function test_cut_counts_characters_and_never_splits_one( bool $mbstring ): void {
		if ( $mbstring && ! function_exists( 'mb_substr' ) ) {
			$this->markTestSkipped( 'mbstring is not installed.' );
		}
		$this->assertSame( str_repeat( 'é', 5 ), Text::cut( str_repeat( 'é', 9 ), 5, $mbstring ) );
		$this->assertSame( 'abcd😀', Text::cut( 'abcd😀😀', 5, $mbstring ) );
		$this->assertSame( 'Short', Text::cut( 'Short', 200, $mbstring ) );
		$this->assertSame( '', Text::cut( '', 5, $mbstring ) );
		$cut = Text::cut( str_repeat( '日本', 150 ), 201, $mbstring );
		$this->assertSame( 1, preg_match( '//u', $cut ), 'Still valid UTF-8.' );
		$this->assertSame( 201, preg_match_all( '/./su', $cut ) );
	}

	/**
	 * Both modes.
	 *
	 * @return array<string,array{0:bool}>
	 */
	public function modes(): array {
		return array(
			'mbstring'    => array( true ),
			'no mbstring' => array( false ),
		);
	}

	public function test_without_mbstring_invalid_utf8_keeps_bytes_up_to_the_cap(): void {
		$this->assertSame( "ab\xff", Text::cut( "ab\xffcdef", 3, false ) );
	}

	public function test_the_override_reaches_every_caller(): void {
		Text::$mbstring = false;

		$this->assertSame( str_repeat( 'é', Attributes::MAX_TITLE ), Attributes::cap_title( str_repeat( 'é', Attributes::MAX_TITLE + 5 ) ) );

		$details = new ReflectionMethod( Account::class, 'text' );
		$details->setAccessible( true );
		$this->assertSame( str_repeat( 'ü', Account::MAX_TEXT ), $details->invoke( null, str_repeat( 'ü', Account::MAX_TEXT + 1 ) ) );
	}
}
