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

	/**
	 * Option naming the state that needs reconnecting (autoload off): its state id, so the
	 * flag can only ever apply to that state. Earlier builds stored `reconnect_needed`.
	 */
	const STATE_OPTION = 'showfm_connection_state';

	/** Durable sync retry and exhausted-row details for connection status. */
	const SYNC_STATUS_OPTION = 'showfm_connection_sync_status';

	/** When show.fm first refused the stored key (autoload off). */
	const REFUSED_AT_OPTION = 'showfm_connection_refused_at';

	/** When the credentials were last stored (autoload off). */
	const CONNECTED_AT_OPTION = 'showfm_connected_at';

	/**
	 * Which state the plan has paused, and since when (autoload off): `{id, at}`. Earlier
	 * builds stored the time alone.
	 */
	const PAUSED_OPTION = 'showfm_plan_paused_at';

	/** Options row of the per-site lock that serialises every change to the connection state. */
	const LOCK_OPTION = 'showfm_connection_lock';

	/** How long a lock holder may keep the lock before it counts as stale, in seconds. */
	const LOCK_TTL = 30;

	/** How long a change waits for the lock before giving up as busy, in milliseconds. */
	const LOCK_WAIT_MS = 3000;

	/**
	 * Lock depth per site in this request, so a change made inside another one runs at once.
	 *
	 * @var array<int,int>
	 */
	private static $depth = array();

	/** The error code show.fm sends when no podcast on the key may sync to a site. */
	const PLAN_ERROR = 'plan_upgrade_required';

	/**
	 * The generation counter of earlier development builds. No longer used: `upgrade()` and
	 * uninstall delete it.
	 */
	const LEGACY_GENERATION_OPTION = 'showfm_connection_generation';

	/** `state_id()` for credentials stored before state ids existed, or an unreadable value. */
	const LEGACY_ID = 'legacy';

	/**
	 * The state id this instance's last successful save() wrote, or null.
	 *
	 * @var string|null
	 */
	private $saved_id = null;

	/**
	 * Whether this instance reads one fixed value instead of the option (see `pinned()`).
	 *
	 * @var bool
	 */
	private $is_pinned = false;

	/**
	 * The value a pinned instance reads.
	 *
	 * @var mixed
	 */
	private $pinned_value = false;

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
		// Only a save that succeeds now may name a state id as its own.
		$this->saved_id  = null;
		$this->is_pinned = false;
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

		// A random id for this state, written with the credentials. Nothing is counted or
		// ordered: a connect outcome matches a state by this id only.
		$id     = wp_generate_uuid4();
		$stored = array(
			'v' => self::FORMAT_VERSION,
			'n' => base64_encode( $nonce ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary ciphertext stored as text.
			'c' => base64_encode( $cipher ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary ciphertext stored as text.
			'i' => $id,
		);

		// Under the connection lock, one UPDATE (or INSERT when nothing is stored) writes the
		// credentials and their state id together, and the flags of the old state go with
		// them. If the write fails, the old credentials are still there, so a failed reconnect
		// never leaves the site with nothing. Throws Connection_Busy when another change holds
		// the lock for too long.
		$saved = self::mutate(
			static function () use ( $stored ): bool {
				if ( ! update_option( self::OPTION, $stored, false ) ) {
					return false;
				}
				delete_option( self::STATE_OPTION );
				delete_option( self::REFUSED_AT_OPTION );
				delete_option( self::PAUSED_OPTION );
				update_option( self::CONNECTED_AT_OPTION, time(), false );
				return true;
			}
		);
		if ( $saved ) {
			$this->saved_id = $id;
		}
		return (bool) $saved;
	}

	/**
	 * Runs one change to the connection state under the per-site connection lock, with the
	 * state read fresh inside the lock. Every write of the connection state goes through here,
	 * so read-check-write sequences from two requests never interleave: each change decides on
	 * what is stored now and nothing can change it until the change is done.
	 *
	 * The lock is the sync's lease (an options row with a TTL and compare-and-swap, plus a
	 * MySQL named lock where available), on its own row. A stale holder is replaced after
	 * `LOCK_TTL`. A change that cannot get the lock within `LOCK_WAIT_MS` changes nothing and
	 * throws Connection_Busy. A change made inside another one in the same request runs at once.
	 *
	 * @param callable(Connection):mixed $change Gets a copy pinned to the fresh state.
	 * @return mixed What the change returns.
	 * @throws Connection_Busy When another change holds the lock for longer than the wait.
	 */
	public static function mutate( callable $change ) {
		$blog = get_current_blog_id();
		// A test can turn re-entry off to stand in for a second request.
		if ( ! empty( self::$depth[ $blog ] ) && apply_filters( 'showfm_connection_lock_reentrant', true ) ) {
			return $change( ( new self() )->pinned() );
		}

		$lock     = new Sync_Lock( self::LOCK_OPTION, self::LOCK_TTL );
		$deadline = microtime( true ) + max( 0, (int) apply_filters( 'showfm_connection_lock_wait_ms', self::LOCK_WAIT_MS ) ) / 1000;
		while ( ! $lock->acquire() ) {
			if ( microtime( true ) >= $deadline ) {
				throw new Connection_Busy( 'Another change to the show.fm connection is in progress.' );
			}
			do_action( 'showfm_connection_lock_waiting' );
			usleep( 50000 );
		}

		self::$depth[ $blog ] = ( self::$depth[ $blog ] ?? 0 ) + 1;
		try {
			return $change( ( new self() )->pinned() );
		} finally {
			--self::$depth[ $blog ];
			$lock->release();
		}
	}

	/**
	 * Whether this request is inside a connection change (holds the connection lock).
	 */
	public static function in_mutation(): bool {
		return ! empty( self::$depth[ get_current_blog_id() ] );
	}

	/**
	 * An option read from the database, not from this request's cache, so a change made
	 * under the lock decides on what another request may just have written.
	 *
	 * @param string $name     Option name.
	 * @param mixed  $fallback Value when the option is missing.
	 * @return mixed
	 */
	public static function fresh_option( string $name, $fallback = false ) {
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return get_option( $name, $fallback );
	}

	/**
	 * Current state: disconnected, connected or reconnect needed.
	 */
	public function state(): string {
		if ( ! self::stores_credentials( $this->stored() ) ) {
			return self::STATE_DISCONNECTED;
		}
		if ( $this->flagged( get_option( self::STATE_OPTION ) ) ) {
			return self::STATE_RECONNECT_NEEDED;
		}

		// Reading the state never writes: unreadable credentials need reconnecting, and that
		// follows from the credentials themselves.
		$credentials = $this->credentials();
		if ( null === $credentials ) {
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
		return self::stores_credentials( $this->stored() ) && null === $this->credentials();
	}

	/**
	 * A copy of this connection that reads the option once, now, and from then on answers
	 * every question (state, expiry, key, state id) from that one value. A flow takes one at
	 * its start, so a save or disconnect by another request part way through can never mix
	 * two states into one answer. Writing through the copy unpins it.
	 */
	public function pinned(): self {
		$copy               = new self();
		$copy->is_pinned    = true;
		$copy->pinned_value = self::fresh_option( self::OPTION );
		return $copy;
	}

	/**
	 * The state as a flow carries it: whether credentials were stored, and the state id.
	 * Small enough to keep in the flow's transient; it never holds the credentials.
	 *
	 * @return array{credentials:bool,id:string}
	 */
	public function snapshot(): array {
		$stored = $this->stored();
		return array(
			'credentials' => self::stores_credentials( $stored ),
			'id'          => self::id_of( $stored ),
		);
	}

	/**
	 * The stored value: the pinned one, or the option.
	 *
	 * @return mixed
	 */
	private function stored() {
		return $this->is_pinned ? $this->pinned_value : get_option( self::OPTION, false );
	}

	/**
	 * Whether a stored value holds credentials, rather than nothing or only a state id.
	 *
	 * @param mixed $stored Stored option value.
	 */
	private static function stores_credentials( $stored ): bool {
		if ( false === $stored ) {
			return false;
		}
		return ! ( is_array( $stored ) && array( 'i' ) === array_keys( $stored ) );
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
	 * Since when auto-posting has been paused by the plan for the state stored now, or 0.
	 */
	public static function paused_at(): int {
		return ( new self() )->paused_since();
	}

	/**
	 * Since when the plan has paused this connection's state (pinned or stored), or 0. A pause
	 * recorded for another state does not count.
	 */
	public function paused_since(): int {
		$paused = get_option( self::PAUSED_OPTION, 0 );
		if ( is_array( $paused ) ) {
			return $this->flagged( $paused['id'] ?? null ) ? (int) ( $paused['at'] ?? 0 ) : 0;
		}
		return (int) $paused;
	}

	/**
	 * Records whether show.fm accepted a verify or health report. A 403 with
	 * `plan_upgrade_required` means no podcast on the key may sync to a site, so auto-posting
	 * is paused; a success lifts the pause. Other outcomes change nothing.
	 *
	 * The result names the state whose key it used. Under the connection lock, the pause is
	 * set or cleared only while that is still the stored state, and it names that state, so a
	 * report from a connection that has since been replaced or disconnected never pauses or
	 * unpauses the new one. Returns false when the state moved on or the lock was busy.
	 *
	 * @param Api_Result $result Result of a verify or health report.
	 * @return bool Whether the result still applied to the stored state.
	 */
	public static function note_report( Api_Result $result ): bool {
		$id = $result->state_id();
		if ( null === $id || '' === $id ) {
			return false;
		}
		try {
			return (bool) self::mutate(
				static function ( Connection $fresh ) use ( $id, $result ): bool {
					if ( $fresh->snapshot()['id'] !== $id ) {
						return false;
					}
					// Decided on the pause as stored now, under the lock: a pause another
					// state recorded is never cleared, and this state's is never doubled.
					$paused = self::fresh_option( self::PAUSED_OPTION, 0 );
					$mine   = is_array( $paused ) ? ( $paused['id'] ?? null ) === $id : (int) $paused > 0;
					if ( $result->is( Api_Result::SUCCESS ) ) {
						if ( $mine ) {
							delete_option( self::PAUSED_OPTION );
						}
					} elseif ( 403 === $result->status() && self::PLAN_ERROR === $result->error_code() && ! $mine ) {
						update_option(
							self::PAUSED_OPTION,
							array(
								'id' => $id,
								'at' => time(),
							),
							false
						);
					}
					return true;
				}
			);
		} catch ( Connection_Busy $busy ) {
			return false;
		}
	}

	/**
	 * Whether a stored flag names this connection's state (pinned or stored). Earlier builds
	 * stored `reconnect_needed`, which applies to whatever credentials are stored.
	 *
	 * @param mixed $flag Flag value.
	 */
	private function flagged( $flag ): bool {
		if ( self::STATE_RECONNECT_NEEDED === $flag ) {
			return true;
		}
		$id = self::id_of( $this->stored() );
		return is_string( $flag ) && '' !== $id && $flag === $id;
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
	 * Stops all keyed calls for this connection's state (pinned, or the stored one) until the
	 * admin reconnects.
	 *
	 * Under the connection lock, the flag is written only while that state is still the
	 * stored one, read fresh, and it names the state. A refusal for an old key therefore
	 * never flags, or unflags, a connection saved since.
	 *
	 * @return bool Whether the flag applies to the stored state; false when the state moved on
	 *              or the lock was busy.
	 */
	public function mark_reconnect_needed(): bool {
		$stored   = $this->stored();
		$expected = self::id_of( $stored );
		if ( ! self::stores_credentials( $stored ) ) {
			return false;
		}
		try {
			return (bool) self::mutate(
				static function ( Connection $fresh ) use ( $expected ): bool {
					$now = $fresh->snapshot();
					if ( ! $now['credentials'] || $now['id'] !== $expected ) {
						return false;
					}
					if ( self::fresh_option( self::STATE_OPTION ) !== $expected ) {
						update_option( self::STATE_OPTION, $expected, false );
						update_option( self::REFUSED_AT_OPTION, time(), false );
					}
					return true;
				}
			);
		} catch ( Connection_Busy $busy ) {
			return false;
		}
	}

	/**
	 * Removes the credentials of this connection's state (pinned, or whatever is stored for an
	 * unpinned copy). The option keeps only a fresh random state id, written in the same write
	 * that removes the credentials, so every disconnected spell is its own state.
	 *
	 * Under the connection lock: a pinned copy disconnects only if its state is still the
	 * stored one, so a reconnect by another request in between is never undone.
	 *
	 * @return bool Whether the state was disconnected; false when it moved on.
	 * @throws Connection_Busy When another change holds the lock for longer than the wait.
	 */
	public function disconnect(): bool {
		$expected        = $this->is_pinned ? self::id_of( $this->pinned_value ) : null;
		$this->is_pinned = false;
		$this->saved_id  = null;
		return (bool) self::mutate(
			static function ( Connection $fresh ) use ( $expected ): bool {
				if ( null !== $expected && $fresh->snapshot()['id'] !== $expected ) {
					return false;
				}
				update_option( self::OPTION, array( 'i' => wp_generate_uuid4() ), false );
				delete_option( self::STATE_OPTION );
				delete_option( self::REFUSED_AT_OPTION );
				delete_option( self::CONNECTED_AT_OPTION );
				delete_option( self::PAUSED_OPTION );
				return true;
			}
		);
	}

	/**
	 * The id of the state stored now: the random id written with the credentials, or after a
	 * disconnect on its own; '' before anything was ever stored; `LEGACY_ID` for credentials
	 * stored before state ids existed. Only ever compared for equality.
	 */
	public static function state_id(): string {
		return self::id_of( get_option( self::OPTION, false ) );
	}

	/**
	 * The state id held in a stored value.
	 *
	 * @param mixed $stored Stored option value.
	 */
	private static function id_of( $stored ): string {
		if ( false === $stored ) {
			return '';
		}
		return is_array( $stored ) && is_string( $stored['i'] ?? null ) && wp_is_uuid( $stored['i'], 4 ) ? $stored['i'] : self::LEGACY_ID;
	}

	/**
	 * The state id this instance's last successful save() wrote with the credentials, or null.
	 * A save from another tab or WP-CLI never counts as this one.
	 */
	public function saved_state_id(): ?string {
		return $this->saved_id;
	}

	/**
	 * The state a failed connect attempt belongs to, from the snapshot its flow took at the
	 * start, decided under the connection lock.
	 *
	 * - Credentials were stored (a failed reconnect keeps them): the snapshot's state id. If
	 *   another request has saved or disconnected since, that id no longer matches, so the
	 *   failure is never shown against the new state.
	 * - No connection: a fresh state of its own, but only if the stored state is still the
	 *   snapshot's, so it can never remove credentials another request has saved. Otherwise
	 *   null, which matches nothing.
	 *
	 * @param array{credentials:bool,id:string} $snapshot The flow's snapshot.
	 * @throws Connection_Busy When another change holds the lock for longer than the wait.
	 */
	public static function failure_state_id( array $snapshot ): ?string {
		if ( $snapshot['credentials'] ) {
			return $snapshot['id'];
		}
		$id = self::mutate(
			static function ( Connection $fresh ) use ( $snapshot ): ?string {
				$now = $fresh->snapshot();
				if ( $now['credentials'] || $now['id'] !== $snapshot['id'] ) {
					return null;
				}
				$id = wp_generate_uuid4();
				update_option( self::OPTION, array( 'i' => $id ), false );
				return $id;
			}
		);
		return is_string( $id ) ? $id : null;
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
		$stored = $this->stored();
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
