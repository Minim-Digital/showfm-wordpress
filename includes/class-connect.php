<?php
/**
 * Connecting the site to show.fm: the browser flow, the code exchange and WP-CLI registration.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The plugin side of the connect protocol (plan 5.2.2).
 *
 * Browser flow:
 * 1. `start()` creates a state and a PKCE verifier, keeps them for 10 minutes and sends the
 *    admin to my.show.fm. No outbound HTTP.
 * 2. show.fm fetches the challenge for the state from `Challenge_Endpoint`, then sends the
 *    admin back to the settings page with `code` and `state`.
 * 3. `handle_return()` checks the state (single use), keeps the code server-side and
 *    redirects to the clean URL, so the code leaves the address bar at once.
 * 4. `complete_pending()` exchanges the code and verifier (in the POST body) for the site
 *    key, stores it encrypted and reports in with `verify()`.
 *
 * WP-CLI flow: `register_with_key()` sends a new site key's registration with the state
 * and challenge in one request; show.fm fetches the challenge back inside that request.
 *
 * A reconnect keeps the old credentials until the new ones are stored. Every failure leaves
 * a typed error for the settings screen. No key, code or verifier is logged, echoed or put
 * in a URL.
 */
final class Connect {

	/** Admin-post action and nonce action that start the browser flow. */
	const ACTION = 'showfm_connect';

	/** The settings page slug. The return address points here. */
	const PAGE = 'showfm';

	/**
	 * Query argument added to the return address, holding a random token kept with the flow.
	 * show.fm keeps it, and adds `code` and `state` only on approval, so a return with the
	 * flow's own token alone means the admin cancelled or show.fm could not connect the site.
	 */
	const RETURN_ARG = 'showfm_return';

	/** The show.fm app, where the admin approves the connection. */
	const DEFAULT_APP_URL = 'https://my.show.fm';

	/**
	 * Hosts `SHOWFM_APP_URL` may point at.
	 *
	 * @var string[]
	 */
	const ALLOWED_APP_HOSTS = array( 'my.show.fm', 'my.showfm.dev' );

	/** Path of the approval page on the app. */
	const APP_PATH = '/connect/wordpress';

	/** How long a started flow lives: 10 minutes. */
	const FLOW_TTL = 600;

	/** Per-user transient holding the flow in progress. */
	const FLOW_PREFIX = 'showfm_connect_';

	/** Transient holding the challenge for one state, keyed by the state's SHA-256. */
	const CHALLENGE_PREFIX = 'showfm_challenge_';

	/** Per-user transient holding the outcome for the settings screen. */
	const RESULT_PREFIX = 'showfm_connect_result_';

	/**
	 * How long an outcome is kept for the settings screen. It survives reloads and other
	 * tabs until the admin dismisses it, starts again or this time passes.
	 */
	const RESULT_TTL = 900;

	/** Option holding when the last challenge stored expires (autoload off). */
	const CHALLENGE_OPEN_OPTION = 'showfm_challenge_open_until';

	/** Option set while the site still has to report in with verify (autoload off). */
	const VERIFY_PENDING_OPTION = 'showfm_verify_pending';

	/**
	 * A disconnect whose teardown has not finished (autoload off): `{revoke, at}`. Written
	 * before the swap and removed after the last step, so a disconnect that loses its lease
	 * part way is finished later by `finish_teardown()`.
	 */
	const TEARDOWN_OPTION = 'showfm_disconnect_teardown';

	/** Exchange route (keyless; the one-time code is the credential). */
	const EXCHANGE_PATH = '/v1/sites/exchange';

	/** WP-CLI registration route. */
	const REGISTER_PATH = '/v1/me/sites';

	/** State: 32 random bytes as base64url, or anything the server accepts. */
	const STATE_PATTERN = '/^[A-Za-z0-9_-]{32,128}$/';

	/** The one-time code: 32 random bytes as base64url. */
	const CODE_PATTERN = '/^[A-Za-z0-9_-]{43}$/';

	/** A partner's tracking alias. */
	const PARTNER_PATTERN = '/^[a-z0-9][a-z0-9-]{1,39}$/';

	/** A site key as WP-CLI accepts it. */
	const KEY_PATTERN = '/^[A-Za-z0-9_-]{20,200}$/';

	/** A connected site id. */
	const SITE_ID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

	/** A ping secret. */
	const PING_SECRET_PATTERN = '/^[A-Za-z0-9_-]{32,256}$/';

	/** Outcome: connected. */
	const STATUS_CONNECTED = 'connected';

	/** Outcome: failed, with an error type. */
	const STATUS_FAILED = 'failed';

	/** The returning state does not match the flow this admin started. */
	const ERROR_STATE_MISMATCH = 'state_mismatch';

	/** No flow in progress: it expired after 10 minutes, or was already used. */
	const ERROR_EXPIRED = 'expired';

	/** The code was refused by show.fm (used, expired or not this site's). */
	const ERROR_EXCHANGE_REFUSED = 'exchange_refused';

	/** WP-CLI: the key is not a site key in the expected format. */
	const ERROR_BAD_KEY = 'bad_key';

	/** WP-CLI: the key was not accepted by show.fm. */
	const ERROR_KEY_REFUSED = 'key_refused';

	/** WP-CLI: the registration was refused by show.fm (for example, the challenge check failed). */
	const ERROR_REGISTRATION_REFUSED = 'registration_refused';

	/** Rate limited by show.fm. */
	const ERROR_RATE_LIMITED = 'rate_limited';

	/** The API could not be reached, or had a server error. */
	const ERROR_UNREACHABLE = 'unreachable';

	/** The API answered with something the plugin cannot use. */
	const ERROR_BAD_RESPONSE = 'bad_response';

	/** The admin came back from show.fm without approving the connection. */
	const ERROR_CANCELLED = 'cancelled';

	/** The site's address does not use https, which show.fm requires. */
	const ERROR_INSECURE = 'https_required';

	/** Another change to the connection held the lock for too long; nothing was changed. */
	const ERROR_BUSY = 'busy';

	/** A change outlived its lock part way through; it stopped before its next write. */
	const ERROR_LOST = 'lock_lost';

	/** The credentials could not be stored (for example, no libsodium). */
	const ERROR_STORAGE = 'storage_failed';

	/** Connected, but reporting in failed. It is retried by the health check. */
	const ERROR_VERIFY = 'verify_failed';

	/**
	 * Connection store.
	 *
	 * @var Connection
	 */
	private $connection;

	/**
	 * API client.
	 *
	 * @var Api_Client
	 */
	private $api_client;

	/**
	 * Builds the service.
	 *
	 * @param Connection $connection Connection store.
	 * @param Api_Client $api_client API client.
	 */
	public function __construct( Connection $connection, Api_Client $api_client ) {
		$this->connection = $connection;
		$this->api_client = $api_client;
	}

	/**
	 * The app's base URL. `SHOWFM_APP_URL` can point it at staging (`https://my.showfm.dev`).
	 * Anything other than https on an allowed host with no path falls back to production.
	 */
	public static function app_url(): string {
		return defined( 'SHOWFM_APP_URL' ) ? self::sanitize_app_url( constant( 'SHOWFM_APP_URL' ) ) : self::DEFAULT_APP_URL;
	}

	/**
	 * Returns the URL's origin if it is https on an allowed host with no port, credentials,
	 * path, query or fragment, otherwise the production app.
	 *
	 * @param mixed $url Candidate app URL.
	 */
	public static function sanitize_app_url( $url ): string {
		$parts = is_string( $url ) ? wp_parse_url( $url ) : false;
		if (
			! is_array( $parts )
			|| ! isset( $parts['scheme'], $parts['host'] )
			|| 'https' !== strtolower( $parts['scheme'] )
			|| ! in_array( strtolower( $parts['host'] ), self::ALLOWED_APP_HOSTS, true )
			|| isset( $parts['port'] ) || isset( $parts['user'] ) || isset( $parts['pass'] )
			|| isset( $parts['query'] ) || isset( $parts['fragment'] )
			|| ( isset( $parts['path'] ) && '/' !== $parts['path'] )
		) {
			return self::DEFAULT_APP_URL;
		}
		return 'https://' . strtolower( $parts['host'] );
	}

	/**
	 * The partner code from `SHOWFM_PARTNER`, or null when unset or malformed.
	 */
	public static function partner(): ?string {
		return defined( 'SHOWFM_PARTNER' ) ? self::sanitize_partner( constant( 'SHOWFM_PARTNER' ) ) : null;
	}

	/**
	 * A partner code, lowercased, or null when it is not one.
	 *
	 * @param mixed $partner Candidate partner code.
	 */
	public static function sanitize_partner( $partner ): ?string {
		if ( ! is_string( $partner ) ) {
			return null;
		}
		$partner = strtolower( trim( $partner ) );
		return preg_match( self::PARTNER_PATTERN, $partner ) ? $partner : null;
	}

	/**
	 * The settings page, where show.fm sends the admin back.
	 */
	public static function settings_url(): string {
		return admin_url( 'options-general.php?page=' . self::PAGE );
	}

	/**
	 * Whether the site's address uses https. show.fm connects only https sites.
	 */
	public static function site_is_https(): bool {
		return 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME );
	}

	/**
	 * Starts the browser flow for a user and returns the my.show.fm address to send them to.
	 * Any flow the user had in progress is replaced. Makes no HTTP request.
	 *
	 * @param int                                    $user_id  The admin starting the flow.
	 * @param array{credentials:bool,id:string}|null $snapshot The state read at the start of the request; read once now when not given.
	 */
	public function start( int $user_id, ?array $snapshot = null ): string {
		$this->forget_flow( $user_id );

		$pkce  = self::new_pkce();
		$token = self::base64url( random_bytes( 16 ) );
		set_transient(
			self::FLOW_PREFIX . $user_id,
			array(
				'state'     => $pkce['state'],
				'verifier'  => $pkce['verifier'],
				'challenge' => $pkce['challenge'],
				'return'    => $token,
				// The state this flow started from, read once: every outcome it records
				// belongs to this state, never to one read later.
				'snapshot'  => $snapshot ?? $this->connection->pinned()->snapshot(),
			),
			self::FLOW_TTL
		);
		self::store_challenge( $pkce['state'], $pkce['challenge'] );
		delete_transient( self::RESULT_PREFIX . $user_id );

		$query   = array(
			'site_url'       => home_url(),
			'rest_root'      => rest_url(),
			'state'          => $pkce['state'],
			'code_challenge' => $pkce['challenge'],
			'return'         => add_query_arg( self::RETURN_ARG, $token, self::settings_url() ),
		);
		$partner = self::partner();
		if ( null !== $partner ) {
			$query['partner'] = $partner;
		}

		return self::app_url() . self::APP_PATH . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * The challenge stored for a state, or null for an unknown or expired state. The state's
	 * SHA-256 names the transient, and the stored hash is compared in constant time.
	 *
	 * @param string $state State from the request.
	 */
	public static function challenge_for_state( string $state ): ?string {
		if ( ! preg_match( self::STATE_PATTERN, $state ) ) {
			return null;
		}
		$hash  = hash( 'sha256', $state );
		$entry = get_transient( self::CHALLENGE_PREFIX . $hash );
		if (
			! is_array( $entry )
			|| ! is_string( $entry['hash'] ?? null )
			|| ! is_string( $entry['challenge'] ?? null )
			|| ! hash_equals( $entry['hash'], $hash )
		) {
			return null;
		}
		return $entry['challenge'];
	}

	/**
	 * Whether any connection was started in the last 10 minutes, so a challenge may exist.
	 * One option read; the challenge route answers 404 without a lookup otherwise.
	 */
	public static function challenge_open(): bool {
		return (int) get_option( self::CHALLENGE_OPEN_OPTION, 0 ) > time();
	}

	/**
	 * Handles the browser coming back with `code` and `state`. Returns the clean settings URL
	 * to redirect to, or null when the request carries neither (nothing to do).
	 *
	 * On a matching state the code is kept with the verifier for `complete_pending()`, and the
	 * state is spent. On any other outcome a typed error is recorded. Either way the caller
	 * redirects, so the code never stays in the address bar or the browser history.
	 *
	 * @param int                 $user_id The admin viewing the page.
	 * @param array<string,mixed> $query   The request's query arguments.
	 */
	public function handle_return( int $user_id, array $query ): ?string {
		$clean = self::settings_url();
		if ( ! isset( $query['code'] ) && ! isset( $query['state'] ) && ! isset( $query[ self::RETURN_ARG ] ) ) {
			return null;
		}
		// Read once for this request; the flow's own outcomes use the state it started from.
		$here = $this->connection->pinned();
		$flow = get_transient( self::FLOW_PREFIX . $user_id );
		$from = self::flow_snapshot( $flow ) ?? $here->snapshot();
		if ( ! isset( $query['code'] ) && ! isset( $query['state'] ) ) {
			// Back from show.fm without approval. Only the flow's own token, while the flow
			// still waits for its code, counts as cancelled: a stale or crafted link changes
			// nothing.
			$token = is_string( $query[ self::RETURN_ARG ] ) ? $query[ self::RETURN_ARG ] : '';
			if (
				is_array( $flow )
				&& is_string( $flow['state'] ?? null )
				&& ! isset( $flow['code'] )
				&& is_string( $flow['return'] ?? null )
				&& hash_equals( $flow['return'], $token )
			) {
				$this->forget_flow( $user_id );
				$this->fail( $user_id, self::ERROR_CANCELLED, 0, $from );
			}
			return $clean;
		}

		$code  = is_string( $query['code'] ?? null ) ? $query['code'] : '';
		$state = is_string( $query['state'] ?? null ) ? $query['state'] : '';

		// A stray or crafted link neither cancels the flow in progress nor replaces a notice
		// the admin has not seen yet.
		if ( ! is_array( $flow ) || ! is_string( $flow['state'] ?? null ) || ! is_string( $flow['verifier'] ?? null ) ) {
			$this->fail_unless_noted( $user_id, self::ERROR_EXPIRED, $here );
			return $clean;
		}
		if ( ! hash_equals( $flow['state'], $state ) ) {
			$this->fail_unless_noted( $user_id, self::ERROR_STATE_MISMATCH, $here );
			return $clean;
		}

		// The state is single use from here on, whatever happens to the code.
		self::forget_challenge( $flow['state'] );
		if ( ! preg_match( self::CODE_PATTERN, $code ) ) {
			delete_transient( self::FLOW_PREFIX . $user_id );
			$this->fail( $user_id, self::ERROR_EXCHANGE_REFUSED, 0, $from );
			return $clean;
		}

		set_transient(
			self::FLOW_PREFIX . $user_id,
			array(
				'verifier' => $flow['verifier'],
				'code'     => $code,
				'snapshot' => $from,
			),
			self::FLOW_TTL
		);
		return $clean;
	}

	/**
	 * Exchanges a code kept by `handle_return()`, stores the credentials and reports in.
	 * Returns false when there was nothing to exchange.
	 *
	 * @param int $user_id The admin viewing the page.
	 */
	public function complete_pending( int $user_id ): bool {
		$flow = get_transient( self::FLOW_PREFIX . $user_id );
		if ( ! is_array( $flow ) || ! is_string( $flow['code'] ?? null ) || ! is_string( $flow['verifier'] ?? null ) ) {
			return false;
		}
		// Taken before the request, so a reload cannot send the code twice.
		delete_transient( self::FLOW_PREFIX . $user_id );
		$from = self::flow_snapshot( $flow ) ?? $this->connection->pinned()->snapshot();

		$code     = $flow['code'];
		$verifier = $flow['verifier'];
		$result   = $this->api_client->post(
			self::EXCHANGE_PATH,
			array(
				'code'          => $code,
				'code_verifier' => $verifier,
			),
			array( $code, $verifier )
		);

		if ( ! $result->is( Api_Result::SUCCESS ) ) {
			$this->fail( $user_id, self::error_for( $result, self::ERROR_EXCHANGE_REFUSED ), $result->retry_after(), $from );
			return true;
		}

		$data = self::payload( $result );
		if (
			null === $data
			|| ! is_string( $data['api_key'] ?? null )
			|| ! preg_match( self::KEY_PATTERN, $data['api_key'] )
		) {
			$this->fail( $user_id, self::ERROR_BAD_RESPONSE, 0, $from );
			return true;
		}

		$this->record( $user_id, $this->store( $data['api_key'], $data ), $from );
		return true;
	}

	/**
	 * Registers a new site key from WP-CLI. The state and challenge go in one request and
	 * show.fm fetches the challenge back before it binds the key.
	 *
	 * @param string $key The site key created in show.fm.
	 * @return array{status:string,error:string,retry_after:int,reason:string}
	 */
	public function register_with_key( string $key ): array {
		if ( ! preg_match( self::KEY_PATTERN, $key ) ) {
			return self::outcome( self::ERROR_BAD_KEY );
		}

		$pkce = self::new_pkce();
		self::store_challenge( $pkce['state'], $pkce['challenge'] );
		try {
			$result = $this->api_client->post_with_key(
				self::REGISTER_PATH,
				array(
					'site_url'       => home_url(),
					'rest_root'      => rest_url(),
					'state'          => $pkce['state'],
					'code_challenge' => $pkce['challenge'],
				),
				$key
			);
		} finally {
			self::forget_challenge( $pkce['state'] );
		}

		if ( $result->is( Api_Result::UNAUTHORISED ) || $result->is( Api_Result::UNAVAILABLE ) ) {
			// 401: not a live key. 403 or 404: not a site key that may register, or a plan without the API.
			return self::outcome( self::ERROR_KEY_REFUSED );
		}
		if ( ! $result->is( Api_Result::SUCCESS ) ) {
			// Only a 400 is show.fm refusing the registration itself (for example, the challenge check).
			$refused           = 400 === $result->status() ? self::ERROR_REGISTRATION_REFUSED : self::ERROR_BAD_RESPONSE;
			$outcome           = self::outcome( self::error_for( $result, $refused ), $result->retry_after() );
			$outcome['reason'] = $result->error_reason();
			return $outcome;
		}

		$data = self::payload( $result );
		if ( null === $data ) {
			return self::outcome( self::ERROR_BAD_RESPONSE );
		}
		return $this->store( $key, $data );
	}

	/**
	 * Reports in to show.fm with the versions and site name. The site becomes active.
	 */
	public function verify(): Api_Result {
		$site_id = $this->connection->site_id();
		if ( null === $site_id || ! $this->connection->is_connected() ) {
			return new Api_Result( Api_Result::UNAUTHORISED, 0, null, null, 0, 'This site is not connected to show.fm.' );
		}

		$body = Health::versions();
		$name = trim( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) );
		if ( '' !== $name ) {
			$body['site_name'] = Text::cut( $name, 200 );
		}

		$result = $this->api_client->post_keyed( '/v1/me/sites/' . rawurlencode( $site_id ) . '/verify', $body );
		self::settle_verify( $result );
		return $result;
	}

	/**
	 * Records a verify's outcome under the connection lock, for the state whose key it used
	 * only: the plan pause, and whether the site still has to report in. A verify for a
	 * connection that has since been replaced or disconnected changes nothing.
	 *
	 * @param Api_Result $result Verify result.
	 */
	private static function settle_verify( Api_Result $result ): void {
		$id = $result->state_id();
		if ( null === $id || '' === $id ) {
			return;
		}
		try {
			Connection::mutate(
				static function ( Connection $fresh ) use ( $id, $result ): void {
					if ( $fresh->snapshot()['id'] !== $id ) {
						return;
					}
					Connection::note_report( $result );
					if ( $result->is( Api_Result::SUCCESS ) ) {
						Connection::remove( self::VERIFY_PENDING_OPTION );
					} else {
						Connection::write( self::VERIFY_PENDING_OPTION, 1 );
					}
				}
			);
		} catch ( Connection_Busy $busy ) {
			// The marker stays as it was; the next sync or health run verifies again.
			return;
		}
	}

	/**
	 * Whether the site still has to report in.
	 */
	public static function verify_pending(): bool {
		return (bool) get_option( self::VERIFY_PENDING_OPTION, false );
	}

	/** Disconnect revoked the key at show.fm. */
	const REVOKE_DONE = 'revoked';

	/** Already refused by show.fm (revoked or expired), so nothing was left to revoke. */
	const REVOKE_REFUSED = 'refused';

	/** The key could not be revoked from here: it must be revoked at show.fm. */
	const REVOKE_FAILED = 'not_revoked';

	/**
	 * Asks show.fm to revoke the key of the state the caller pinned, before Disconnect clears
	 * it locally. Best effort: one request with a short timeout, made outside the connection
	 * lock, and Disconnect goes ahead whatever the answer.
	 *
	 * - Success: the key is revoked.
	 * - A 401 now, or a key show.fm already refused or that has expired: show.fm no longer
	 *   accepts it, so there is nothing to revoke. A 401 is never retried.
	 * - Anything else (no answer, a server error, a rate limit, or a key that can't be read
	 *   after the salts changed): the key may still work, so it must be revoked at show.fm.
	 *
	 * @param Connection $pinned The connection, pinned when the caller read it.
	 * @return string One of the REVOKE_ constants.
	 */
	public function revoke( Connection $pinned ): string {
		$site = $pinned->site_id();
		if ( null === $site || $pinned->is_unreadable() ) {
			return self::REVOKE_FAILED;
		}
		$key = $pinned->key();
		if ( null === $key ) {
			// Stored and readable but not usable: refused or expired at show.fm already.
			return self::REVOKE_REFUSED;
		}
		$result = $this->api_client->disconnect_site( $site, $key );
		if ( $result->is( Api_Result::SUCCESS ) ) {
			return self::REVOKE_DONE;
		}
		return $result->is( Api_Result::UNAUTHORISED ) ? self::REVOKE_REFUSED : self::REVOKE_FAILED;
	}

	/**
	 * What Disconnect did with the key at show.fm, in one sentence.
	 *
	 * @param string $outcome One of the REVOKE_ constants.
	 */
	public static function revoke_message( string $outcome ): string {
		switch ( $outcome ) {
			case self::REVOKE_DONE:
				return __( 'This site’s key was revoked at show.fm.', 'showfm' );
			case self::REVOKE_REFUSED:
				return __( 'show.fm had already stopped accepting this site’s key, so there was nothing left to revoke.', 'showfm' );
		}
		return __( 'show.fm didn’t confirm the key was revoked, so it may still work. Revoke it in show.fm under Connected sites.', 'showfm' );
	}

	/**
	 * The message when the local clear could not finish (the lock was busy or lost).
	 *
	 * - The swap was made, then the lease was lost: the site is disconnected and only the
	 *   clean-up is left, which `finish_teardown()` does on the next admin page or Disconnect.
	 * - Nothing was swapped, after a revoke that succeeded: it says so, since the stored key no
	 *   longer works. Running Disconnect again gets a 401, which counts as nothing left to
	 *   revoke, and finishes.
	 *
	 * @param string $outcome One of the REVOKE_ constants.
	 * @param string $error   `ERROR_BUSY` or `ERROR_LOST`.
	 * @param bool   $cli     Whether the retry is the WP-CLI command.
	 */
	public static function unfinished_message( string $outcome, string $error, bool $cli = false ): string {
		if ( self::teardown_pending() ) {
			// The swap stands: the site is disconnected, and only the clean-up is left.
			$revoked = '' === $outcome ? '' : self::revoke_message( $outcome ) . ' ';
			return $revoked . ( $cli
				? __( 'This site is disconnected, but another change took over before its clean-up finished. It finishes the next time an admin page loads, or run wp showfm disconnect again.', 'showfm' )
				: __( 'This site is disconnected, but another change took over before its clean-up finished. It finishes the next time an admin page loads, or select Disconnect again.', 'showfm' ) );
		}
		if ( self::REVOKE_DONE !== $outcome ) {
			return self::message( $error );
		}
		return $cli
			? __( 'This site’s key was revoked at show.fm, but another change to the connection was in progress, so this site hasn’t finished disconnecting. Run wp showfm disconnect again to finish.', 'showfm' )
			: __( 'This site’s key was revoked at show.fm, but another change to the connection was in progress, so this site hasn’t finished disconnecting. Select Disconnect again to finish.', 'showfm' );
	}

	/**
	 * What Disconnect says after finishing an earlier disconnect's clean-up.
	 *
	 * @param string $outcome The REVOKE_ constant that disconnect recorded, or ''.
	 */
	public static function finished_message( string $outcome ): string {
		$finished = __( 'This site finished disconnecting.', 'showfm' );
		return '' === $outcome ? $finished : $finished . ' ' . self::revoke_message( $outcome );
	}

	/**
	 * Removes the local connection: credentials, scheduled events and connection state.
	 * Callers ask show.fm to revoke the key first (see `revoke()`) and pass its outcome, which
	 * is kept with the teardown marker until the teardown finishes.
	 *
	 * The swap and the whole teardown run in one change under the connection lock, on the
	 * state read fresh inside it: only the state the caller read is disconnected, and a
	 * reconnect by another request can only come before (then nothing changes) or after (then
	 * its jobs, marker and account details are its own).
	 *
	 * @param Connection|null $pinned  The caller's pinned connection; read once now when not given.
	 * @param int             $user_id The admin whose connect outcome is cleared too, or 0.
	 * @param string          $revoke  The outcome of the revoke made first, or ''.
	 * @return bool Whether that state was disconnected; false when it moved on.
	 * @throws Connection_Busy When another change holds the lock for longer than the wait.
	 */
	public function disconnect( ?Connection $pinned = null, int $user_id = 0, string $revoke = '' ): bool {
		$expected = ( $pinned ?? $this->connection->pinned() )->snapshot()['id'];
		return (bool) Connection::mutate(
			static function ( Connection $fresh ) use ( $expected, $user_id, $revoke ): bool {
				if ( $fresh->snapshot()['id'] !== $expected ) {
					return false;
				}
				// The marker comes before the swap: if the lease is lost anywhere after it, the
				// next run finishes the teardown without needing this state's id.
				Connection::write(
					self::TEARDOWN_OPTION,
					array(
						'revoke' => $revoke,
						'at'     => time(),
					)
				);
				if ( ! $fresh->disconnect() ) {
					Connection::remove( self::TEARDOWN_OPTION );
					return false;
				}
				self::teardown();
				if ( $user_id > 0 ) {
					Connection::guarded(
						static function () use ( $user_id ): void {
							self::clear_result( $user_id );
						}
					);
				}
				Connection::remove( self::TEARDOWN_OPTION );
				return true;
			}
		);
	}

	/**
	 * Everything a disconnect clears after the swap. Each step is idempotent and checks the
	 * lease first, so a change that outlived its lease stops before touching whatever a later
	 * change has set up, and a later run can repeat the lot.
	 */
	private static function teardown(): void {
		foreach ( array( Connection::STATE_OPTION, Connection::REFUSED_AT_OPTION, Connection::CONNECTED_AT_OPTION, Connection::PAUSED_OPTION ) as $option ) {
			Connection::remove( $option );
		}
		Connection::guarded( array( Plugin::class, 'unschedule_events' ) );
		Connection::remove( self::VERIFY_PENDING_OPTION );
		Connection::remove( Api_Client::RATE_LIMIT_OPTION );
		Connection::remove( Ping_Endpoint::LAST_PING_OPTION );
		Connection::remove( Ping_Endpoint::MISSED_OPTION );
		// The old connection's sync health: a new connection shows "never" until it syncs.
		Connection::remove( Health::LAST_SYNC_OPTION );
		Connection::remove( Health::SYNC_ERRORS_OPTION );
		Connection::remove( Sync_Log::OPTION );
		Connection::remove( Account::OPTION );
		Connection::guarded( array( Ping_Endpoint::class, 'forget_nonces' ) );
	}

	/**
	 * Whether a disconnect swapped the credentials out but did not finish its teardown.
	 */
	public static function teardown_pending(): bool {
		return is_array( Connection::fresh_option( self::TEARDOWN_OPTION ) ) && ! ( new Connection() )->pinned()->snapshot()['credentials'];
	}

	/**
	 * Finishes a disconnect that lost its lease part way, under the connection lock. Runs on
	 * `admin_init`, at the start of a sync, and first in every Disconnect. It runs the
	 * teardown only while no credentials are stored: a marker left before the swap, or by a
	 * disconnect a reconnect has since replaced, is only removed, so a new connection's jobs
	 * are never touched. One option read when there is nothing to do.
	 *
	 * @return array{revoke:string}|null What the finished disconnect recorded, or null when
	 *                                   nothing was finished (nothing pending, or busy).
	 */
	public static function finish_teardown(): ?array {
		if ( ! is_array( get_option( self::TEARDOWN_OPTION, false ) ) ) {
			return null;
		}
		try {
			$finished = Connection::mutate(
				static function ( Connection $fresh ): ?array {
					$marker = Connection::fresh_option( self::TEARDOWN_OPTION, false );
					if ( ! is_array( $marker ) ) {
						return null;
					}
					if ( ! $fresh->snapshot()['credentials'] ) {
						self::teardown();
					}
					$done = ! $fresh->snapshot()['credentials'];
					Connection::remove( self::TEARDOWN_OPTION );
					return $done ? array( 'revoke' => is_string( $marker['revoke'] ?? null ) ? $marker['revoke'] : '' ) : null;
				}
			);
		} catch ( Connection_Busy | Connection_Lost $later ) {
			// The marker stays; the next run finishes.
			return null;
		}
		return is_array( $finished ) ? $finished : null;
	}

	/**
	 * Runs `finish_teardown()` from a hook, ignoring what it returns.
	 */
	public static function finish_teardown_quietly(): void {
		self::finish_teardown();
	}

	/**
	 * The last outcome for the settings screen, or null.
	 *
	 * @param int $user_id The admin.
	 * @return array{status:string,error:string,retry_after:int,reason:string,state_id:string|null}|null
	 */
	public static function result( int $user_id ): ?array {
		$result = get_transient( self::RESULT_PREFIX . $user_id );
		if ( ! is_array( $result ) || ! is_string( $result['status'] ?? null ) ) {
			return null;
		}
		return array(
			'status'      => $result['status'],
			'error'       => is_string( $result['error'] ?? null ) ? $result['error'] : '',
			'retry_after' => (int) ( $result['retry_after'] ?? 0 ),
			'reason'      => is_string( $result['reason'] ?? null ) ? $result['reason'] : '',
			'state_id'    => is_string( $result['state_id'] ?? null ) ? $result['state_id'] : null,
		);
	}

	/**
	 * Clears the outcome once the settings screen has shown it.
	 *
	 * @param int $user_id The admin.
	 */
	public static function clear_result( int $user_id ): void {
		delete_transient( self::RESULT_PREFIX . $user_id );
	}

	/**
	 * A sentence for each error type, saying what to do next.
	 *
	 * @param string $error       One of the error constants.
	 * @param int    $retry_after Seconds to wait, for a rate limit.
	 */
	public static function message( string $error, int $retry_after = 0 ): string {
		switch ( $error ) {
			case self::ERROR_STATE_MISMATCH:
				return __( 'This connection request did not come from this browser session. Start again with Connect to show.fm.', 'showfm' );
			case self::ERROR_EXPIRED:
				return __( 'The connection request expired or was already used. Start again with Connect to show.fm.', 'showfm' );
			case self::ERROR_EXCHANGE_REFUSED:
				return __( 'show.fm did not accept the connection. Codes last five minutes and work once. Start again with Connect to show.fm.', 'showfm' );
			case self::ERROR_BAD_KEY:
				return __( 'That is not a show.fm site key. Copy the key from Connected sites in show.fm.', 'showfm' );
			case self::ERROR_KEY_REFUSED:
				return __( 'show.fm did not accept that key. Site keys must be registered within 24 hours. Create a new one in show.fm under Connected sites.', 'showfm' );
			case self::ERROR_REGISTRATION_REFUSED:
				return __( 'show.fm could not confirm that this site belongs to you. Check that the site is public, uses https and has the show.fm plugin active, then try again.', 'showfm' );
			case self::ERROR_RATE_LIMITED:
				return sprintf(
					/* translators: %d: number of seconds. */
					_n( 'show.fm asked this site to wait. Try again in %d second.', 'show.fm asked this site to wait. Try again in %d seconds.', max( 1, $retry_after ), 'showfm' ),
					max( 1, $retry_after )
				);
			case self::ERROR_UNREACHABLE:
				return __( 'show.fm could not be reached. Try again in a few minutes.', 'showfm' );
			case self::ERROR_BAD_RESPONSE:
				return __( 'show.fm sent an answer this plugin does not understand. Update the plugin, then try again.', 'showfm' );
			case self::ERROR_CANCELLED:
				return __( 'The connection was cancelled.', 'showfm' );
			case self::ERROR_INSECURE:
				return __( 'This site’s address must use https.', 'showfm' );
			case self::ERROR_LOST:
				return __( 'The change took too long and another change took over part way through. Reload to see the connection as it is now.', 'showfm' );
			case self::ERROR_BUSY:
				return __( 'Another change to this site’s show.fm connection was in progress. Try again in a moment.', 'showfm' );
			case self::ERROR_STORAGE:
				return __( 'The connection could not be saved. Your server needs the PHP sodium extension, or WordPress 6.6 or later. Then try again.', 'showfm' );
			case self::ERROR_VERIFY:
				return __( 'Connected, but this site could not report in to show.fm yet. It tries again automatically.', 'showfm' );
		}
		return __( 'Something went wrong. Start again with Connect to show.fm.', 'showfm' );
	}

	/**
	 * Stores credentials from an exchange or a registration, schedules the health check and
	 * reports in. The old credentials are only replaced here, after a success.
	 *
	 * @param string              $key  Site key.
	 * @param array<string,mixed> $data The response's `data` object.
	 * @return array{status:string,error:string,retry_after:int,reason:string}
	 */
	private function store( string $key, array $data ): array {
		$site_id     = is_string( $data['site_id'] ?? null ) ? strtolower( $data['site_id'] ) : '';
		$ping_secret = is_string( $data['ping_secret'] ?? null ) ? $data['ping_secret'] : '';
		$expires_at  = self::timestamp( $data['expires_at'] ?? null );
		if (
			! preg_match( self::SITE_ID_PATTERN, $site_id )
			|| ! preg_match( self::PING_SECRET_PATTERN, $ping_secret )
			|| null === $expires_at
		) {
			return self::outcome( self::ERROR_BAD_RESPONSE );
		}

		// The credentials, the verify marker and the scheduled jobs change together, under
		// the connection lock, so a disconnect can never tear down half of a new connection.
		try {
			$saved = Connection::mutate(
				function () use ( $key, $ping_secret, $site_id, $expires_at ): bool {
					if ( ! $this->connection->save( $key, $ping_secret, $site_id, $expires_at ) ) {
						return false;
					}
					Connection::remove( Api_Client::RATE_LIMIT_OPTION );
					Connection::write( self::VERIFY_PENDING_OPTION, 1 );
					Connection::guarded( array( Health::class, 'schedule' ) );
					Connection::guarded( array( Sync::class, 'schedule' ) );
					return true;
				}
			);
		} catch ( Connection_Lost $lost ) {
			return self::outcome( self::ERROR_LOST );
		} catch ( Connection_Busy $busy ) {
			return self::outcome( self::ERROR_BUSY );
		}
		if ( ! $saved ) {
			return self::outcome( self::ERROR_STORAGE );
		}

		$verified = $this->verify();
		if ( ! $verified->is( Api_Result::SUCCESS ) ) {
			$outcome           = self::outcome( self::ERROR_VERIFY, $verified->retry_after() );
			$outcome['status'] = self::STATUS_CONNECTED;
			return $outcome;
		}
		( new Account( $this->connection, $this->api_client ) )->refresh();
		return array(
			'status'      => self::STATUS_CONNECTED,
			'error'       => '',
			'retry_after' => 0,
			'reason'      => '',
		);
	}

	/**
	 * The `data` object of a success, or null.
	 *
	 * @param Api_Result $result Successful result.
	 * @return array<string,mixed>|null
	 */
	private static function payload( Api_Result $result ): ?array {
		$body = $result->data();
		if ( ! is_array( $body ) || ! is_array( $body['data'] ?? null ) ) {
			return null;
		}
		return $body['data'];
	}

	/**
	 * Unix time from an ISO 8601 expiry; 0 for no expiry; null when unreadable.
	 *
	 * @param mixed $value Value from the response.
	 */
	private static function timestamp( $value ): ?int {
		if ( null === $value ) {
			return 0;
		}
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}
		$time = strtotime( $value );
		return false === $time || $time <= 0 ? null : $time;
	}

	/**
	 * The error type for a failed call.
	 *
	 * @param Api_Result $result  Failed result.
	 * @param string     $refused Error type for a refusal.
	 */
	private static function error_for( Api_Result $result, string $refused ): string {
		switch ( $result->type() ) {
			case Api_Result::RATE_LIMITED:
				return self::ERROR_RATE_LIMITED;
			case Api_Result::TRANSIENT_FAILURE:
				return self::ERROR_UNREACHABLE;
		}
		if ( $result->is( Api_Result::FAILED ) && $result->status() >= 200 && $result->status() < 300 ) {
			return self::ERROR_BAD_RESPONSE;
		}
		return $refused;
	}

	/**
	 * A failed outcome.
	 *
	 * @param string $error       Error type.
	 * @param int    $retry_after Seconds to wait, for a rate limit.
	 * @return array{status:string,error:string,retry_after:int,reason:string}
	 */
	private static function outcome( string $error, int $retry_after = 0 ): array {
		return array(
			'status'      => self::STATUS_FAILED,
			'error'       => $error,
			'retry_after' => self::ERROR_RATE_LIMITED === $error ? $retry_after : 0,
			'reason'      => '',
		);
	}

	/**
	 * Records an error for the settings screen.
	 *
	 * @param int                                    $user_id     The admin.
	 * @param string                                 $error       Error type.
	 * @param int                                    $retry_after Seconds to wait, for a rate limit.
	 * @param array{credentials:bool,id:string}|null $snapshot    The state the attempt started from; read once now when not given.
	 */
	public function fail( int $user_id, string $error, int $retry_after = 0, ?array $snapshot = null ): void {
		$this->record( $user_id, self::outcome( $error, $retry_after ), $snapshot ?? $this->connection->pinned()->snapshot() );
	}

	/**
	 * The snapshot a flow carries, if it is well formed.
	 *
	 * @param mixed $flow Flow transient.
	 * @return array{credentials:bool,id:string}|null
	 */
	private static function flow_snapshot( $flow ): ?array {
		$snapshot = is_array( $flow ) ? ( $flow['snapshot'] ?? null ) : null;
		if ( ! is_array( $snapshot ) || ! is_bool( $snapshot['credentials'] ?? null ) || ! is_string( $snapshot['id'] ?? null ) ) {
			return null;
		}
		return array(
			'credentials' => $snapshot['credentials'],
			'id'          => $snapshot['id'],
		);
	}

	/**
	 * Records a failure only when no outcome is waiting to be shown. An outcome the screen
	 * would hide (another connection, or a "Connected" whose key no longer works) is not
	 * waiting, so it never keeps a new failure from being seen.
	 *
	 * @param int        $user_id The admin.
	 * @param string     $error   Error type.
	 * @param Connection $here    This request's pinned connection, read once.
	 */
	private function fail_unless_noted( int $user_id, string $error, Connection $here ): void {
		$snapshot = $here->snapshot();
		$result   = self::result( $user_id );
		$waiting  = null !== $result
			&& $snapshot['id'] === $result['state_id']
			&& ( self::STATUS_CONNECTED !== $result['status'] || $here->is_connected() );
		if ( ! $waiting ) {
			$this->fail( $user_id, $error, 0, $snapshot );
		}
	}

	/**
	 * Records an outcome for the settings screen.
	 *
	 * @param int                                                             $user_id  The admin.
	 * @param array{status:string,error:string,retry_after:int,reason:string} $outcome  Outcome.
	 * @param array{credentials:bool,id:string}                               $snapshot The state the attempt started from.
	 */
	private function record( int $user_id, array $outcome, array $snapshot ): void {
		// The state the outcome belongs to: the id this connect wrote with its credentials,
		// or for a failure the state it leaves (see Connection::failure_state_id()). Decided
		// and written under the connection lock, so no change to the connection can land
		// between them. The settings screen shows the outcome only while that id is still the
		// stored one. A "Connected" with no save of its own in this request gets null, which
		// matches nothing.
		$saved = $this->connection->saved_state_id();
		try {
			Connection::mutate(
				static function () use ( $user_id, $outcome, $snapshot, $saved ): void {
					$outcome['state_id'] = self::STATUS_CONNECTED === $outcome['status'] ? $saved : Connection::failure_state_id( $snapshot );
					Connection::guarded(
						static function () use ( $user_id, $outcome ): bool {
							return set_transient( self::RESULT_PREFIX . $user_id, $outcome, self::RESULT_TTL );
						}
					);
				}
			);
		} catch ( Connection_Busy $busy ) {
			// Nothing is recorded: an outcome with no state would match nothing anyway.
			return;
		}
	}

	/**
	 * Drops the user's flow in progress and its challenge.
	 *
	 * @param int $user_id The admin.
	 */
	private function forget_flow( int $user_id ): void {
		$flow = get_transient( self::FLOW_PREFIX . $user_id );
		if ( is_array( $flow ) && is_string( $flow['state'] ?? null ) ) {
			self::forget_challenge( $flow['state'] );
		}
		delete_transient( self::FLOW_PREFIX . $user_id );
	}

	/**
	 * Keeps the challenge for a state where the challenge endpoint can find it.
	 *
	 * @param string $state     State.
	 * @param string $challenge S256 challenge.
	 */
	private static function store_challenge( string $state, string $challenge ): void {
		$until = time() + self::FLOW_TTL;
		if ( (int) get_option( self::CHALLENGE_OPEN_OPTION, 0 ) < $until ) {
			update_option( self::CHALLENGE_OPEN_OPTION, $until, false );
		}

		$hash = hash( 'sha256', $state );
		set_transient(
			self::CHALLENGE_PREFIX . $hash,
			array(
				'hash'      => $hash,
				'challenge' => $challenge,
			),
			self::FLOW_TTL
		);
	}

	/**
	 * Removes the challenge for a state.
	 *
	 * @param string $state State.
	 */
	private static function forget_challenge( string $state ): void {
		delete_transient( self::CHALLENGE_PREFIX . hash( 'sha256', $state ) );
	}

	/**
	 * A new state, verifier and S256 challenge (RFC 7636).
	 *
	 * @return array{state:string,verifier:string,challenge:string}
	 */
	public static function new_pkce(): array {
		$verifier = self::base64url( random_bytes( 48 ) );
		return array(
			'state'     => self::base64url( random_bytes( 32 ) ),
			'verifier'  => $verifier,
			'challenge' => self::s256( $verifier ),
		);
	}

	/**
	 * The S256 challenge for a verifier: base64url(SHA-256(verifier)).
	 *
	 * @param string $verifier Code verifier.
	 */
	public static function s256( string $verifier ): string {
		return self::base64url( hash( 'sha256', $verifier, true ) );
	}

	/**
	 * Base64url without padding.
	 *
	 * @param string $bytes Raw bytes.
	 */
	private static function base64url( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PKCE encoding (RFC 7636).
	}
}
