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

	/** How long an outcome is kept for the settings screen. */
	const RESULT_TTL = 3600;

	/** Option holding when the last challenge stored expires (autoload off). */
	const CHALLENGE_OPEN_OPTION = 'showfm_challenge_open_until';

	/** Option set while the site still has to report in with verify (autoload off). */
	const VERIFY_PENDING_OPTION = 'showfm_verify_pending';

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
		return admin_url( 'admin.php?page=' . self::PAGE );
	}

	/**
	 * Starts the browser flow for a user and returns the my.show.fm address to send them to.
	 * Any flow the user had in progress is replaced. Makes no HTTP request.
	 *
	 * @param int $user_id The admin starting the flow.
	 */
	public function start( int $user_id ): string {
		$this->forget_flow( $user_id );

		$pkce = self::new_pkce();
		set_transient(
			self::FLOW_PREFIX . $user_id,
			array(
				'state'     => $pkce['state'],
				'verifier'  => $pkce['verifier'],
				'challenge' => $pkce['challenge'],
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
			'return'         => self::settings_url(),
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
		if ( ! isset( $query['code'] ) && ! isset( $query['state'] ) ) {
			return null;
		}

		$clean = remove_query_arg( array( 'code', 'state' ), self::settings_url() );
		$code  = is_string( $query['code'] ?? null ) ? $query['code'] : '';
		$state = is_string( $query['state'] ?? null ) ? $query['state'] : '';
		$flow  = get_transient( self::FLOW_PREFIX . $user_id );

		// A stray or crafted link neither cancels the flow in progress nor replaces a notice
		// the admin has not seen yet.
		if ( ! is_array( $flow ) || ! is_string( $flow['state'] ?? null ) || ! is_string( $flow['verifier'] ?? null ) ) {
			$this->fail_unless_noted( $user_id, self::ERROR_EXPIRED );
			return $clean;
		}
		if ( ! hash_equals( $flow['state'], $state ) ) {
			$this->fail_unless_noted( $user_id, self::ERROR_STATE_MISMATCH );
			return $clean;
		}

		// The state is single use from here on, whatever happens to the code.
		self::forget_challenge( $flow['state'] );
		if ( ! preg_match( self::CODE_PATTERN, $code ) ) {
			delete_transient( self::FLOW_PREFIX . $user_id );
			$this->fail( $user_id, self::ERROR_EXCHANGE_REFUSED );
			return $clean;
		}

		set_transient(
			self::FLOW_PREFIX . $user_id,
			array(
				'verifier' => $flow['verifier'],
				'code'     => $code,
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
			$this->fail( $user_id, self::error_for( $result, self::ERROR_EXCHANGE_REFUSED ), $result->retry_after() );
			return true;
		}

		$data = self::payload( $result );
		if (
			null === $data
			|| ! is_string( $data['api_key'] ?? null )
			|| ! preg_match( self::KEY_PATTERN, $data['api_key'] )
		) {
			$this->fail( $user_id, self::ERROR_BAD_RESPONSE );
			return true;
		}

		$this->record( $user_id, $this->store( $data['api_key'], $data ) );
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
			$body['site_name'] = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 200 ) : substr( $name, 0, 200 );
		}

		$result = $this->api_client->post_keyed( '/v1/me/sites/' . rawurlencode( $site_id ) . '/verify', $body );
		if ( $result->is( Api_Result::SUCCESS ) ) {
			delete_option( self::VERIFY_PENDING_OPTION );
		} else {
			update_option( self::VERIFY_PENDING_OPTION, 1, false );
		}
		return $result;
	}

	/**
	 * Whether the site still has to report in.
	 */
	public static function verify_pending(): bool {
		return (bool) get_option( self::VERIFY_PENDING_OPTION, false );
	}

	/**
	 * Removes the local connection: credentials, scheduled events and connection state.
	 * The key stays live at show.fm until it is revoked there.
	 */
	public function disconnect(): void {
		$this->connection->disconnect();
		Plugin::unschedule_events();
		delete_option( self::VERIFY_PENDING_OPTION );
		delete_option( Api_Client::RATE_LIMIT_OPTION );
		delete_option( Ping_Endpoint::LAST_PING_OPTION );
		Ping_Endpoint::forget_nonces();
	}

	/**
	 * The last outcome for the settings screen, or null.
	 *
	 * @param int $user_id The admin.
	 * @return array{status:string,error:string,retry_after:int,reason:string}|null
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

		if ( ! $this->connection->save( $key, $ping_secret, $site_id, $expires_at ) ) {
			return self::outcome( self::ERROR_STORAGE );
		}
		delete_option( Api_Client::RATE_LIMIT_OPTION );
		update_option( self::VERIFY_PENDING_OPTION, 1, false );
		Health::schedule();

		$verified = $this->verify();
		if ( ! $verified->is( Api_Result::SUCCESS ) ) {
			$outcome           = self::outcome( self::ERROR_VERIFY, $verified->retry_after() );
			$outcome['status'] = self::STATUS_CONNECTED;
			return $outcome;
		}
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
	 * @param int    $user_id     The admin.
	 * @param string $error       Error type.
	 * @param int    $retry_after Seconds to wait, for a rate limit.
	 */
	private function fail( int $user_id, string $error, int $retry_after = 0 ): void {
		$this->record( $user_id, self::outcome( $error, $retry_after ) );
	}

	/**
	 * Records a failure only when no outcome is waiting to be shown.
	 *
	 * @param int    $user_id The admin.
	 * @param string $error   Error type.
	 */
	private function fail_unless_noted( int $user_id, string $error ): void {
		if ( null === self::result( $user_id ) ) {
			$this->fail( $user_id, $error );
		}
	}

	/**
	 * Records an outcome for the settings screen.
	 *
	 * @param int                                                             $user_id The admin.
	 * @param array{status:string,error:string,retry_after:int,reason:string} $outcome Outcome.
	 */
	private function record( int $user_id, array $outcome ): void {
		set_transient( self::RESULT_PREFIX . $user_id, $outcome, self::RESULT_TTL );
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
