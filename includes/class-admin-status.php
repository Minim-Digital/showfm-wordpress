<?php
/**
 * What the Connection tab shows, built from local state only.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the Connection tab's data. It reads options and the cache, and never makes a
 * request. It never includes the key, the ping secret or any code: the key appears only as
 * its last four characters.
 *
 * States, highest first: not connected, unreadable (the salts changed), expired, refused
 * (show.fm stopped accepting the key), paused (by the plan), expiring (within 30 days),
 * scheduled (pings are not arriving), connected.
 */
final class Admin_Status {

	/** Days before expiry when the screen starts warning. */
	const EXPIRY_WARNING_DAYS = 30;

	/**
	 * Connection store.
	 *
	 * @var Connection
	 */
	private $connection;

	/**
	 * Account details.
	 *
	 * @var Account
	 */
	private $account;

	/**
	 * Notices, for the other tabs.
	 *
	 * @var Notices
	 */
	private $notices;

	/**
	 * Builds the view.
	 *
	 * @param Connection $connection Connection store.
	 * @param Account    $account    Account details.
	 * @param Notices    $notices    Notices.
	 */
	public function __construct( Connection $connection, Account $account, Notices $notices ) {
		$this->connection = $connection;
		$this->account    = $account;
		$this->notices    = $notices;
	}

	/**
	 * The current state's name.
	 */
	public function state(): string {
		if ( Connection::STATE_DISCONNECTED === $this->connection->state() ) {
			return 'not_connected';
		}
		if ( $this->connection->is_unreadable() ) {
			return 'unreadable';
		}
		$expires = (int) $this->connection->expires_at();
		if ( $expires > 0 && $expires <= time() ) {
			return 'expired';
		}
		if ( ! $this->connection->is_connected() ) {
			return 'refused';
		}
		if ( Connection::paused_at() > 0 ) {
			return 'paused';
		}
		$days = Notices::days_left( $expires );
		if ( null !== $days && $days <= self::EXPIRY_WARNING_DAYS ) {
			return 'expiring';
		}
		if ( Ping_Endpoint::missed_at() > 0 ) {
			return 'scheduled';
		}
		return 'connected';
	}

	/**
	 * The Connection tab's data for a user, including the outcome of their last connect
	 * attempt. Reading has no side effect: the outcome stays until the admin dismisses it,
	 * starts again, or `Connect::RESULT_TTL` passes, so a reload or another tab still shows it.
	 *
	 * @param int $user_id The admin.
	 * @return array<string,mixed>
	 */
	public function view( int $user_id ): array {
		$state   = $this->state();
		$result  = self::current_result( Connect::result( $user_id ), $state );
		$details = 'not_connected' === $state || 'unreadable' === $state ? array(
			'name'  => '',
			'shows' => array(),
		) : $this->account->details();
		$expires = 'unreadable' === $state ? 0 : (int) $this->connection->expires_at();
		$checked = (int) get_option( Health::LAST_SYNC_OPTION, 0 );
		$next    = wp_next_scheduled( Sync::POLL_HOOK );

		return array(
			'state'       => $state,
			'daysLeft'    => Notices::days_left( $expires ),
			'site'        => home_url(),
			'account'     => $details['name'],
			'shows'       => array_map( array( self::class, 'show' ), $details['shows'] ),
			'key'         => array(
				'masked'    => 'not_connected' === $state ? '' : $this->connection->masked_key(),
				'expiresOn' => $expires > 0 ? self::date( $expires ) : '',
				'refusedOn' => Connection::refused_at() > 0 ? self::date( Connection::refused_at() ) : '',
			),
			'lastChecked' => $checked > 0 ? self::ago( $checked ) : '',
			'nextCheck'   => false !== $next && 'scheduled' === $state ? max( 1, (int) ceil( ( $next - time() ) / MINUTE_IN_SECONDS ) ) : 0,
			'result'      => null === $result ? null : self::result( $result ),
			'connect'     => array(
				'url'    => admin_url( 'admin-post.php' ),
				'action' => Connect::ACTION,
				'nonce'  => wp_create_nonce( Connect::ACTION ),
			),
			'planUrl'     => Notices::plan_url(),
			'sitesUrl'    => self::sites_url( $details['shows'] ),
			'notice'      => $this->notices->current( $user_id ),
		);
	}

	/**
	 * The stored connect outcome, only while the live connection still agrees with it. The
	 * live state always wins:
	 *
	 * - Every outcome belongs to a connection generation. Any later connect, reconnect or
	 *   disconnect, from another tab or WP-CLI, moves the generation on and hides it, even in
	 *   the same second.
	 * - "Connected" also shows only while the stored key works: not after an expiry, a
	 *   refusal such as a password change, or a salt change.
	 *
	 * @param array{status:string,error:string,retry_after:int,reason:string,generation:int}|null $result Stored outcome.
	 * @param string                                                                              $state  Live state.
	 * @return array{status:string,error:string,retry_after:int,reason:string,generation:int}|null
	 */
	public static function current_result( ?array $result, string $state ): ?array {
		if ( null === $result || Connection::generation() !== $result['generation'] ) {
			return null;
		}
		$working = in_array( $state, array( 'connected', 'expiring', 'paused', 'scheduled' ), true );
		if ( Connect::STATUS_CONNECTED === $result['status'] && ! $working ) {
			return null;
		}
		return $result;
	}

	/**
	 * Where the account disconnects the site at show.fm: the Connected sites page of the
	 * first connected show, or the show.fm dashboard when no show is known. A site key
	 * cannot revoke itself, so disconnecting here leaves it valid until it is removed there.
	 *
	 * @param array<int,array{id:string,title:string,slug:string}> $shows Connected shows.
	 */
	public static function sites_url( array $shows ): string {
		foreach ( $shows as $show ) {
			if ( '' !== $show['slug'] ) {
				return Connect::app_url() . '/p/' . rawurlencode( $show['slug'] ) . '/settings/sites';
			}
		}
		return Connect::app_url() . '/dashboard';
	}

	/**
	 * The Connected sites link for the stored connection, read before it is removed.
	 */
	public function current_sites_url(): string {
		return self::sites_url( $this->account->details()['shows'] );
	}

	/**
	 * One connected show, with artwork from the public cache when it is there.
	 *
	 * @param array{id:string,title:string,slug:string} $show Show.
	 * @return array{id:string,title:string,address:string,artwork:string}
	 */
	private static function show( array $show ): array {
		$data    = Plugin::cache()->get( '/v1/podcasts/' . $show['id'] );
		$artwork = is_array( $data ) && is_array( $data['data']['artwork'] ?? null ) ? ( $data['data']['artwork']['url'] ?? null ) : null;
		return array(
			'id'      => $show['id'],
			'title'   => $show['title'],
			'address' => '' === $show['slug'] ? '' : $show['slug'] . '.show.fm',
			'artwork' => is_string( $artwork ) && 0 === strpos( $artwork, 'https://' ) ? esc_url_raw( $artwork ) : '',
		);
	}

	/**
	 * The connect outcome as the screen shows it: the message and its one action.
	 *
	 * @param array{status:string,error:string,retry_after:int,reason:string,generation:int} $result Outcome.
	 * @return array{status:string,error:string,message:string,action:string}
	 */
	private static function result( array $result ): array {
		$error   = $result['error'];
		$retry   = 'retry';
		$message = Connect::message( $error, $result['retry_after'] );
		switch ( $error ) {
			case '':
				$message = '';
				$retry   = 'none';
				break;
			case Connect::ERROR_EXPIRED:
			case Connect::ERROR_EXCHANGE_REFUSED:
				$message = __( 'The connection request expired. Try again.', 'showfm' );
				break;
			case Connect::ERROR_STATE_MISMATCH:
				$message = __( 'The connection request didn’t start in this browser. Try again.', 'showfm' );
				break;
			case Connect::ERROR_UNREACHABLE:
				$message = __( 'show.fm couldn’t be reached. Try again in a few minutes.', 'showfm' );
				break;
			case Connect::ERROR_INSECURE:
			case Connect::ERROR_STORAGE:
			case Connect::ERROR_VERIFY:
				$retry = 'none';
				break;
		}
		return array(
			'status'  => $result['status'],
			'error'   => $error,
			'message' => $message,
			'action'  => $retry,
		);
	}

	/**
	 * A date in the site's date format and time zone.
	 *
	 * @param int $timestamp Unix time.
	 */
	public static function date( int $timestamp ): string {
		return (string) wp_date( (string) get_option( 'date_format' ), $timestamp );
	}

	/**
	 * How long ago, in words: "just now", "3 minutes ago", "yesterday".
	 *
	 * @param int $timestamp Unix time.
	 */
	public static function ago( int $timestamp ): string {
		$seconds = max( 0, time() - $timestamp );
		if ( $seconds < MINUTE_IN_SECONDS ) {
			return __( 'just now', 'showfm' );
		}
		if ( $seconds < HOUR_IN_SECONDS ) {
			$minutes = (int) floor( $seconds / MINUTE_IN_SECONDS );
			/* translators: %d: number of minutes. */
			return sprintf( _n( '%d minute ago', '%d minutes ago', $minutes, 'showfm' ), $minutes );
		}
		if ( $seconds < DAY_IN_SECONDS ) {
			$hours = (int) floor( $seconds / HOUR_IN_SECONDS );
			/* translators: %d: number of hours. */
			return sprintf( _n( '%d hour ago', '%d hours ago', $hours, 'showfm' ), $hours );
		}
		$days = (int) floor( $seconds / DAY_IN_SECONDS );
		if ( 1 === $days ) {
			return __( 'yesterday', 'showfm' );
		}
		/* translators: %d: number of days. */
		return sprintf( _n( '%d day ago', '%d days ago', $days, 'showfm' ), $days );
	}
}
