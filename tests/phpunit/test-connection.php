<?php
/**
 * Connection storage: encryption, salt changes, masking, autoload.
 *
 * @package ShowFM
 */

use ShowFM\Connection;

/**
 * Connection tests.
 */
class Test_Connection extends WP_UnitTestCase {

	const KEY    = 'showfm_live_abcdefghijklmnopqrstuvwxWXYZ';
	const SECRET = 'ping-secret-0123456789abcdef';

	/**
	 * Connection under test.
	 *
	 * @var Connection
	 */
	private $connection;

	public function set_up(): void {
		parent::set_up();
		$this->connection = new Connection();
		$this->connection->disconnect();
	}

	public function test_starts_disconnected(): void {
		$this->assertSame( Connection::STATE_DISCONNECTED, $this->connection->state() );
		$this->assertNull( $this->connection->key() );
		$this->assertSame( '', $this->connection->masked_key() );
	}

	public function test_round_trip(): void {
		$expires = time() + YEAR_IN_SECONDS;

		$this->assertTrue( $this->connection->save( self::KEY, self::SECRET, 'site-42', $expires ) );

		$fresh = new Connection();
		$this->assertSame( Connection::STATE_CONNECTED, $fresh->state() );
		$this->assertSame( self::KEY, $fresh->key() );
		$this->assertSame( self::SECRET, $fresh->ping_secret() );
		$this->assertSame( 'site-42', $fresh->site_id() );
		$this->assertSame( $expires, $fresh->expires_at() );
	}

	public function test_nothing_is_stored_in_plain_text(): void {
		$this->connection->save( self::KEY, self::SECRET, 'site-42', 0 );

		$stored = maybe_serialize( get_option( Connection::OPTION ) );

		$this->assertStringNotContainsString( self::KEY, $stored );
		$this->assertStringNotContainsString( self::SECRET, $stored );
		$this->assertStringNotContainsString( 'site-42', $stored );
	}

	public function test_each_save_uses_a_new_nonce(): void {
		$this->connection->save( self::KEY, self::SECRET, 'site-42', 0 );
		$first = get_option( Connection::OPTION );
		$this->connection->save( self::KEY, self::SECRET, 'site-42', 0 );

		$this->assertNotSame( $first['n'], get_option( Connection::OPTION )['n'] );
	}

	public function test_options_are_not_autoloaded(): void {
		global $wpdb;
		$this->connection->save( self::KEY, self::SECRET, 'site-42', 0 );
		$this->connection->mark_reconnect_needed();

		foreach ( array( Connection::OPTION, Connection::STATE_OPTION ) as $option ) {
			$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $option ) );
			$this->assertContains( $autoload, array( 'off', 'no' ), $option );
		}
	}

	public function test_salt_change_gives_reconnect_needed_without_errors(): void {
		$this->connection->save( self::KEY, self::SECRET, 'site-42', 0 );

		add_filter(
			'salt',
			static function () {
				return 'a-completely-different-salt-after-a-rotation';
			}
		);

		$this->assertSame( Connection::STATE_RECONNECT_NEEDED, $this->connection->state() );
		$this->assertNull( $this->connection->key() );
		$this->assertNull( $this->connection->ping_secret() );
		$this->assertNull( $this->connection->site_id() );
		$this->assertSame( '', $this->connection->masked_key() );
		$this->assertSame( Connection::STATE_RECONNECT_NEEDED, get_option( Connection::STATE_OPTION ) );
	}

	public function test_tampered_ciphertext_gives_reconnect_needed(): void {
		$this->connection->save( self::KEY, self::SECRET, 'site-42', 0 );
		$stored      = get_option( Connection::OPTION );
		$stored['c'] = base64_encode( str_repeat( 'x', 64 ) );
		update_option( Connection::OPTION, $stored, false );

		$this->assertSame( Connection::STATE_RECONNECT_NEEDED, $this->connection->state() );
		$this->assertNull( $this->connection->key() );
	}

	public function test_garbage_option_gives_reconnect_needed(): void {
		update_option( Connection::OPTION, 'not an array', false );

		$this->assertSame( Connection::STATE_RECONNECT_NEEDED, $this->connection->state() );
	}

	public function test_expired_key_needs_reconnecting(): void {
		$this->connection->save( self::KEY, self::SECRET, 'site-42', time() - 1 );

		$this->assertSame( Connection::STATE_RECONNECT_NEEDED, $this->connection->state() );
		$this->assertNull( $this->connection->key() );
		$this->assertSame( 'site-42', $this->connection->site_id(), 'The admin screen can still say which site it was.' );
	}

	public function test_mark_reconnect_needed_then_save_reconnects(): void {
		$this->connection->save( self::KEY, self::SECRET, 'site-42', 0 );
		$this->connection->mark_reconnect_needed();
		$this->assertNull( $this->connection->key() );

		$this->connection->save( 'showfm_live_newkey000000000000000001', self::SECRET, 'site-42', 0 );

		$this->assertSame( Connection::STATE_CONNECTED, $this->connection->state() );
		$this->assertSame( 'showfm_live_newkey000000000000000001', $this->connection->key() );
	}

	public function test_a_failed_write_keeps_the_old_credentials(): void {
		$this->connection->save( self::KEY, self::SECRET, 'site-42', 0 );
		$this->connection->mark_reconnect_needed();
		$block = static function ( $query ) {
			return preg_match( '/^\s*(INSERT|UPDATE)\b/i', $query ) && false !== strpos( $query, "'" . Connection::OPTION . "'" ) ? '' : $query;
		};
		add_filter( 'query', $block );

		$saved = $this->connection->save( 'showfm_live_newkey000000000000000001', self::SECRET, 'site-42', 0 );

		remove_filter( 'query', $block );
		wp_cache_flush();
		$this->assertFalse( $saved );
		$this->assertSame( Connection::STATE_RECONNECT_NEEDED, $this->connection->state(), 'The state is unchanged too.' );
		delete_option( Connection::STATE_OPTION );
		$this->assertSame( self::KEY, $this->connection->key(), 'The old row is still in the database.' );
	}

	public function test_masked_display_shows_only_the_last_four_characters(): void {
		$this->connection->save( self::KEY, self::SECRET, 'site-42', 0 );

		$this->assertSame( "\u{2022}\u{2022}\u{2022}\u{2022}WXYZ", $this->connection->masked_key() );
		$this->assertSame( "\u{2022}\u{2022}\u{2022}\u{2022}", Connection::mask( 'short' ) );
		$this->assertSame( "\u{2022}\u{2022}\u{2022}\u{2022}", Connection::mask( '12345678' ) );
		$this->assertSame( "\u{2022}\u{2022}\u{2022}\u{2022}6789", Connection::mask( '123456789' ) );
	}

	public function test_rejects_invalid_values(): void {
		$this->assertFalse( $this->connection->save( '', self::SECRET, 'site-42', 0 ) );
		$this->assertFalse( $this->connection->save( self::KEY, '', 'site-42', 0 ) );
		$this->assertFalse( $this->connection->save( self::KEY, self::SECRET, '', 0 ) );
		$this->assertFalse( $this->connection->save( "key with\nnewline", self::SECRET, 'site-42', 0 ) );
		$this->assertFalse( $this->connection->save( str_repeat( 'k', 513 ), self::SECRET, 'site-42', 0 ) );
		$this->assertFalse( $this->connection->save( self::KEY, self::SECRET, 'site-42', -1 ) );
		$this->assertSame( Connection::STATE_DISCONNECTED, $this->connection->state() );
	}

	public function test_disconnect_removes_everything(): void {
		$this->connection->save( self::KEY, self::SECRET, 'site-42', 0 );
		$this->connection->mark_reconnect_needed();

		$this->connection->disconnect();

		$this->assertSame( array( 'i' ), array_keys( get_option( Connection::OPTION ) ), 'Only a fresh state id is left.' );
		$this->assertNull( $this->connection->key() );
		$this->assertFalse( get_option( Connection::STATE_OPTION ) );
		$this->assertSame( Connection::STATE_DISCONNECTED, $this->connection->state() );
	}
}
