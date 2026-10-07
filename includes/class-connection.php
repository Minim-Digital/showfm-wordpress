<?php
/**
 * Encrypted storage for the site's connection to show.fm.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores the site key, ping secret, site id and key expiry, encrypted with libsodium
 * secretbox under a key derived from `wp_salt( 'auth' )`. A database dump alone does not
 * reveal them. If the salts change, decryption fails and the state becomes "reconnect
 * needed" without raising errors.
 *
 * Decrypted values are never kept on the object, so dumping it shows nothing secret.
 */
final class Connection {

	/** Option holding the encrypted credentials (autoload off). */
	const OPTION = 'showfm_connection';

	/** Option set when the connection needs reconnecting (autoload off). */
	const STATE_OPTION = 'showfm_connection_state';

	/** Durable sync retry and exhausted-row details for connection status. */
	const SYNC_STATUS_OPTION = 'showfm_connection_sync_status';

	/** When show.fm first refused the stored key (autoload off). */
	const REFUSED_AT_OPTION = 'showfm_connection_refused_at';

	/** When the credentials were last stored (autoload off). */
	const CONNECTED_AT_OPTION = 'showfm_connected_at';

	/** Since when show.fm has refused the site's reports because of the plan (autoload off). */
	const PAUSED_OPTION = 'showfm_plan_paused_at';

	/** The error code show.fm sends when no podcast on the key may sync to a site. */
	const PLAN_ERROR = 'plan_upgrade_required';

	/**
	 * The generation counter of earlier development builds. No longer used: `upgrade()` and
	 * uninstall delete it.
	 */
	const LEGACY_GENERATION_OPTION = 'showfm_connection_generation';

	/** `connection_id()` for credentials stored before connection ids existed. */
	const LEGACY_ID = 'legacy';

	/**
	 * The connection id this instance's last successful save() wrote, or null.
	 *
	 * @var string|null
	 */
	private $saved_id = null;

	/** No connection stored. */
	const STATE_DISCONNECTED = 'disconnected';

	/** Credentials decrypt and the key has not expired. */
	const STATE_CONNECTED = 'connected';

	/** The key was refused, has expired, or can no longer be decrypted. */
	const STATE_RECONNECT_NEEDED = 'reconnect_needed';

	/** Stored format version. */
	const FORMAT_VERSION = 1;

	/** Context for deriving the encryption key from the auth salt. */
	const KEY_CONTEXT = 'showfm-connection-v1';

	/** Longest value accepted for any credential. */
	const MAX_LENGTH = 512;

	/**
	 * Encrypts and stores the credentials, and clears any "reconnect needed" state.
	 *
	 * @param string $key         Site API key.
	 * @param string $ping_secret Secret for verifying wake-up pings.
	 * @param string $site_id     Connected site id at show.fm.
	 * @param int    $expires_at  Key expiry as a Unix timestamp, or 0 for none.
	 * @return bool Whether the credentials were stored.
	 */
	public function save( string $key, string $ping_secret, string $site_id, int $expires_at ): bool {
		// Only a save that succeeds now may name a connection id as its own.
		$this->saved_id = null;
		foreach ( array( $key, $ping_secret, $site_id ) as $value ) {
			if ( '' === $value || strlen( $value ) > self::MAX_LENGTH || preg_match( '/[\x00-\x20\x7f]/', $value ) ) {
				return false;
			}
		}
		if ( $expires_at < 0 || ! self::sodium_available() ) {
			return false;
		}

		$plain = wp_json_encode(
			array(
				'key'         => $key,
				'ping_secret' => $ping_secret,
				'site_id'     => $site_id,
				'expires_at'  => $expires_at,
			)
		);
		if ( false === $plain ) {
			return false;
		}

		try {
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $plain, $nonce, self::encryption_key() );
		} catch ( \Throwable $e ) {
			return false;
		}

		// A random id for this connection, written with the credentials. Nothing is counted
		// or ordered: a connect outcome matches a connection by this id only.
		$id     = wp_generate_uuid4();
		$stored = array(
			'v' => self::FORMAT_VERSION,
			'n' => base64_encode( $nonce ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary ciphertext stored as text.
			'c' => base64_encode( $cipher ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary ciphertext stored as text.
			'i' => $id,
		);

		// One UPDATE (or INSERT when nothing is stored) writes the credentials and their id
		// together. If it fails, the old credentials are still there, so a failed reconnect
		// never leaves the site with nothing.
		if ( ! update_option( self::OPTION, $stored, false ) ) {
			return false;
		}
		delete_option( self::STATE_OPTION );
		delete_option( self::REFUSED_AT_OPTION );
		delete_option( self::PAUSED_OPTION );
		update_option( self::CONNECTED_AT_OPTION, time(), false );
		$this->saved_id = $id;
		return true;
	}

	/**
	 * Current state: disconnected, connected or reconnect needed.
	 */
	public function state(): string {
		if ( false === get_option( self::OPTION, false ) ) {
			return self::STATE_DISCONNECTED;
		}
		if ( self::STATE_RECONNECT_NEEDED === get_option( self::STATE_OPTION ) ) {
			return self::STATE_RECONNECT_NEEDED;
		}

		$credentials = $this->credentials();
		if ( null === $credentials ) {
			$this->mark_reconnect_needed();
			return self::STATE_RECONNECT_NEEDED;
		}
		if ( $credentials['expires_at'] > 0 && $credentials['expires_at'] <= time() ) {
			return self::STATE_RECONNECT_NEEDED;
		}
		return self::STATE_CONNECTED;
	}

	/**
	 * Whether credentials are stored but cannot be decrypted, for example after the salts
	 * changed.
	 */
	public function is_unreadable(): bool {
		return false !== get_option( self::OPTION, false ) && null === $this->credentials();
	}

	/**
	 * When show.fm first refused the key, or 0.
	 */
	public static function refused_at(): int {
		return (int) get_option( self::REFUSED_AT_OPTION, 0 );
	}

	/**
	 * When the credentials were last stored, or 0.
	 */
	public static function connected_at(): int {
		return (int) get_option( self::CONNECTED_AT_OPTION, 0 );
	}

	/**
	 * Since when auto-posting has been paused by the plan, or 0 when it is not.
	 */
	public static function paused_at(): int {
		return (int) get_option( self::PAUSED_OPTION, 0 );
	}

	/**
	 * Records whether show.fm accepted a verify or health report. A 403 with
	 * `plan_upgrade_required` means no podcast on the key may sync to a site, so auto-posting
	 * is paused; a success lifts the pause. Other outcomes change nothing.
	 *
	 * @param Api_Result $result Result of a verify or health report.
	 */
	public static function note_report( Api_Result $result ): void {
		if ( $result->is( Api_Result::SUCCESS ) ) {
			delete_option( self::PAUSED_OPTION );
		} elseif ( 403 === $result->status() && self::PLAN_ERROR === $result->error_code() && 0 === self::paused_at() ) {
			update_option( self::PAUSED_OPTION, time(), false );
		}
	}

	/**
	 * Per-connection sync diagnostics for CLI and the future connection screen.
	 *
	 * @return array<string,mixed>
	 */
	public function sync_status(): array {
		$status = (array) get_option( self::SYNC_STATUS_OPTION, array() );
		$site   = (string) $this->site_id();
		return ( $status['site'] ?? '' ) === $site ? $status : array(
			'site'    => $site,
			'retry'   => array(),
			'skipped' => array(),
		);
	}

	/**
	 * Persist own reason codes and sequence numbers, never external error details.
	 *
	 * @param array<string,mixed> $status Sync diagnostics.
	 * @throws \RuntimeException If the attempt receipt cannot be saved.
	 */
	public function save_sync_status( array $status ): void {
		if ( ! update_option( self::SYNC_STATUS_OPTION, $status, false ) && get_option( self::SYNC_STATUS_OPTION ) !== $status ) {
			throw new \RuntimeException( 'Sync diagnostic receipt could not be saved.' );
		}
	}

	/**
	 * Whether keyed calls may be made.
	 */
	public function is_connected(): bool {
		return self::STATE_CONNECTED === $this->state();
	}

	/**
	 * The site key, only while connected. Never log, echo or send it to the browser.
	 */
	public function key(): ?string {
		return $this->connected_value( 'key' );
	}

	/**
	 * The ping secret, only while connected. Never log, echo or send it to the browser.
	 */
	public function ping_secret(): ?string {
		return $this->connected_value( 'ping_secret' );
	}

	/**
	 * The connected site's id at show.fm, if the credentials decrypt.
	 */
	public function site_id(): ?string {
		$credentials = $this->credentials();
		return null === $credentials ? null : $credentials['site_id'];
	}

	/**
	 * Key expiry as a Unix timestamp (0 for none), if the credentials decrypt.
	 */
	public function expires_at(): ?int {
		$credentials = $this->credentials();
		return null === $credentials ? null : $credentials['expires_at'];
	}

	/**
	 * The stored key for display: its last four characters only, or '' when unreadable.
	 */
	public function masked_key(): string {
		$credentials = $this->credentials();
		return null === $credentials ? '' : self::mask( $credentials['key'] );
	}

	/**
	 * Masks a key down to its last four characters. Keys of eight characters or fewer
	 * show nothing, so the visible part is never most of the key.
	 *
	 * @param string $key Key to mask.
	 */
	public static function mask( string $key ): string {
		$dots = "\u{2022}\u{2022}\u{2022}\u{2022}";
		if ( strlen( $key ) <= 8 ) {
			return $dots;
		}
		return $dots . substr( $key, -4 );
	}

	/**
	 * Stops all keyed calls until the admin reconnects.
	 */
	public function mark_reconnect_needed(): void {
		if ( self::STATE_RECONNECT_NEEDED === get_option( self::STATE_OPTION ) ) {
			return;
		}
		delete_option( self::STATE_OPTION );
		add_option( self::STATE_OPTION, self::STATE_RECONNECT_NEEDED, '', false );
		if ( 0 === self::refused_at() ) {
			update_option( self::REFUSED_AT_OPTION, time(), false );
		}
	}

	/**
	 * Removes the credentials and state.
	 */
	public function disconnect(): void {
		// The credentials and their connection id go in the same delete.
		delete_option( self::OPTION );
		delete_option( self::STATE_OPTION );
		delete_option( self::REFUSED_AT_OPTION );
		delete_option( self::CONNECTED_AT_OPTION );
		delete_option( self::PAUSED_OPTION );
		$this->saved_id = null;
	}

	/**
	 * The id of the connection stored now: the random id written with the credentials,
	 * `LEGACY_ID` for credentials stored before ids existed, or '' when nothing is stored.
	 * Only ever compared for equality.
	 */
	public static function connection_id(): string {
		$stored = get_option( self::OPTION, false );
		if ( ! is_array( $stored ) || ! isset( $stored['c'] ) ) {
			return '';
		}
		return is_string( $stored['i'] ?? null ) && wp_is_uuid( $stored['i'], 4 ) ? $stored['i'] : self::LEGACY_ID;
	}

	/**
	 * The connection id this instance's last successful save() wrote with the credentials, or
	 * null. A save from another tab or WP-CLI never counts as this one.
	 */
	public function saved_connection_id(): ?string {
		return $this->saved_id;
	}

	/**
	 * Removes the generation counter left by earlier development builds. Runs on
	 * `admin_init`; one option read when there is nothing to remove.
	 */
	public static function upgrade(): void {
		if ( false !== get_option( self::LEGACY_GENERATION_OPTION, false ) ) {
			delete_option( self::LEGACY_GENERATION_OPTION );
		}
	}

	/**
	 * Keeps secrets out of var_dump() and print_r().
	 *
	 * @return array<string,string>
	 */
	public function __debugInfo(): array {
		return array( 'state' => $this->state() );
	}

	/**
	 * One decrypted value, only while connected.
	 *
	 * @param string $field Field name.
	 */
	private function connected_value( string $field ): ?string {
		if ( ! $this->is_connected() ) {
			return null;
		}
		$credentials = $this->credentials();
		if ( null === $credentials || ! is_string( $credentials[ $field ] ) ) {
			return null;
		}
		return $credentials[ $field ];
	}

	/**
	 * Decrypts the stored credentials.
	 *
	 * @return array{key:string,ping_secret:string,site_id:string,expires_at:int}|null Null when missing or unreadable.
	 */
	private function credentials(): ?array {
		$stored = get_option( self::OPTION, false );
		if (
			! is_array( $stored )
			|| self::FORMAT_VERSION !== ( $stored['v'] ?? null )
			|| ! is_string( $stored['n'] ?? null )
			|| ! is_string( $stored['c'] ?? null )
			|| ! self::sodium_available()
		) {
			return null;
		}

		$nonce  = base64_decode( $stored['n'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Binary ciphertext stored as text.
		$cipher = base64_decode( $stored['c'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Binary ciphertext stored as text.
		if ( false === $nonce || false === $cipher || SODIUM_CRYPTO_SECRETBOX_NONCEBYTES !== strlen( $nonce ) ) {
			return null;
		}

		try {
			$plain = sodium_crypto_secretbox_open( $cipher, $nonce, self::encryption_key() );
		} catch ( \Throwable $e ) {
			return null;
		}
		if ( false === $plain ) {
			return null;
		}

		$data = json_decode( $plain, true );
		if (
			! is_array( $data )
			|| ! is_string( $data['key'] ?? null )
			|| ! is_string( $data['ping_secret'] ?? null )
			|| ! is_string( $data['site_id'] ?? null )
			|| ! is_int( $data['expires_at'] ?? null )
		) {
			return null;
		}

		return array(
			'key'         => $data['key'],
			'ping_secret' => $data['ping_secret'],
			'site_id'     => $data['site_id'],
			'expires_at'  => $data['expires_at'],
		);
	}

	/**
	 * Secretbox key derived from the auth salt. Changing the salts makes old data unreadable.
	 */
	private static function encryption_key(): string {
		return hash_hkdf( 'sha256', wp_salt( 'auth' ), SODIUM_CRYPTO_SECRETBOX_KEYBYTES, self::KEY_CONTEXT );
	}

	/**
	 * Whether secretbox is available, loading core's sodium_compat if the extension is missing.
	 */
	private static function sodium_available(): bool {
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			return true;
		}
		$compat = ABSPATH . WPINC . '/sodium_compat/autoload.php';
		if ( is_readable( $compat ) ) {
			require_once $compat;
		}
		return function_exists( 'sodium_crypto_secretbox' );
	}
}
