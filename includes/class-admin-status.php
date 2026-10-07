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
	 * Builds the view.
	 *
	 * @param Connection $connection Connection store.
	 */
	public function __construct( Connection $connection ) {
		$this->connection = $connection;
	}

	/**
	 * The current state's name, from one read of the connection.
	 */
	public function state(): string {
		return self::state_of( $this->connection->pinned() );
	}

	/**
	 * The state's name for a connection the caller has pinned.
	 *
	 * @param Connection $connection Pinned connection.
	 */
	private static function state_of( Connection $connection ): string {
		if ( Connection::STATE_DISCONNECTED === $connection->state() ) {
			return 'not_connected';
		}
		if ( $connection->is_unreadable() ) {
			return 'unreadable';
		}
		$expires = (int) $connection->expires_at();
		if ( $expires > 0 && $expires <= time() ) {
			return 'expired';
		}
		if ( ! $connection->is_connected() ) {
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
	 * The connection is read once, and every part of the answer (state, key, account, the
	 * outcome's match and the notice) comes from that one read.
	 *
	 * @param int $user_id The admin.
	 * @return array<string,mixed>
	 */
	public function view( int $user_id ): array {
		$pinned  = $this->connection->pinned();
		$state   = self::state_of( $pinned );
		$result  = self::current_result( Connect::result( $user_id ), $state, $pinned->snapshot()['id'] );
		$details = 'not_connected' === $state || 'unreadable' === $state ? array(
			'name'  => '',
			'shows' => array(),
		) : Account::details_for( $pinned->site_id() );
		$expires = 'unreadable' === $state ? 0 : (int) $pinned->expires_at();
		$checked = (int) get_option( Health::LAST_SYNC_OPTION, 0 );
		$next    = wp_next_scheduled( Sync::POLL_HOOK );

		return array(
			'state'       => $state,
			'daysLeft'    => Notices::days_left( $expires ),
			'site'        => home_url(),
			'account'     => $details['name'],
			'shows'       => array_map( array( self::class, 'show' ), $details['shows'] ),
			'key'         => array(
				'masked'    => 'not_connected' === $state ? '' : $pinned->masked_key(),
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
			'notice'      => ( new Notices( $pinned ) )->current( $user_id ),
		);
	}

	/**
	 * The stored connect outcome, only while the live connection still agrees with it. The
	 * live state always wins:
	 *
	 * - Every outcome records the state it belongs to: a random id written with the
	 *   credentials, or on its own after a disconnect or a failed connect. It shows only while
	 *   that id is still the stored one. Every connect, reconnect and disconnect, from another
	 *   tab, another admin or WP-CLI, writes a new id, so an outcome never comes back.
	 * - "Connected" also shows only while the stored key works: not after an expiry, a
	 *   refusal such as a password change, or a salt change.
	 *
	 * @param array{status:string,error:string,retry_after:int,reason:string,state_id:string|null}|null $result   Stored outcome.
	 * @param string                                                                                    $state    Live state.
	 * @param string                                                                                    $state_id The state id read with it.
	 * @return array{status:string,error:string,retry_after:int,reason:string,state_id:string|null}|null
	 */
	public static function current_result( ?array $result, string $state, string $state_id ): ?array {
		if ( null === $result || null === $result['state_id'] || $state_id !== $result['state_id'] ) {
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
		return self::sites_url( Account::details_for( $this->connection->pinned()->site_id() )['shows'] );
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
	 * @param array{status:string,error:string,retry_after:int,reason:string,state_id:string|null} $result Outcome.
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
