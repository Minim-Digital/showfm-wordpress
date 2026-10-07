<?php
/**
 * WP-CLI commands: connect, status and disconnect.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Connects this site to show.fm and reports on the connection.
 *
 * Registered as `wp showfm` only when WP-CLI is running. On a multisite network, pass
 * `--url=` to pick the site: each site connects separately.
 */
final class Cli {

	/**
	 * Connect service.
	 *
	 * @var Connect
	 */
	private $connect;

	/**
	 * Connection store.
	 *
	 * @var Connection
	 */
	private $connection;

	/**
	 * Builds the commands.
	 *
	 * @param Connect    $connect    Connect service.
	 * @param Connection $connection Connection store.
	 */
	public function __construct( Connect $connect, Connection $connection ) {
		$this->connect    = $connect;
		$this->connection = $connection;
	}

	/**
	 * Registers `wp showfm`.
	 *
	 * @param Cli $cli Commands.
	 */
	public static function register( Cli $cli ): void {
		\WP_CLI::add_command( 'showfm', $cli );
	}

	/**
	 * Connects this site to show.fm with a site key.
	 *
	 * Create the key in show.fm under Connected sites, then Add a site with WP-CLI. show.fm
	 * checks that this site answers for the connection before it accepts the key, so the
	 * site must be public, use https and have the plugin active. An existing connection is
	 * kept until the new one succeeds.
	 *
	 * ## OPTIONS
	 *
	 * --key=<key>
	 * : The site key from show.fm.
	 *
	 * ## EXAMPLES
	 *
	 *     wp showfm connect --key=showfm_live_...
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Named arguments.
	 */
	public function connect( array $args, array $assoc_args ): void {
		$key = isset( $assoc_args['key'] ) ? trim( (string) $assoc_args['key'] ) : '';
		if ( '' === $key ) {
			\WP_CLI::error( __( 'Pass the site key from show.fm with --key=.', 'showfm' ) );
			return;
		}

		$outcome = $this->connect->register_with_key( $key );
		if ( Connect::STATUS_CONNECTED !== $outcome['status'] ) {
			$message = Connect::message( $outcome['error'], $outcome['retry_after'] );
			if ( '' !== $outcome['reason'] ) {
				/* translators: %s: reason code from show.fm, for example "mismatch". */
				$message .= ' ' . sprintf( __( '(show.fm reason: %s)', 'showfm' ), $outcome['reason'] );
			}
			\WP_CLI::error( $message );
			return;
		}

		if ( '' !== $outcome['error'] ) {
			\WP_CLI::warning( Connect::message( $outcome['error'] ) );
		}
		\WP_CLI::success(
			sprintf(
				/* translators: %s: the site key with all but its last four characters hidden. */
				__( 'Connected to show.fm with key %s.', 'showfm' ),
				$this->connection->masked_key()
			)
		);
	}

	/**
	 * Shows the connection: state, masked key, expiry, last sync and last ping.
	 *
	 * ## EXAMPLES
	 *
	 *     wp showfm status
	 */
	public function status(): void {
		$state = $this->connection->state();
		$never = __( 'never', 'showfm' );

		$labels = array(
			Connection::STATE_CONNECTED        => __( 'connected', 'showfm' ),
			Connection::STATE_DISCONNECTED     => __( 'not connected', 'showfm' ),
			Connection::STATE_RECONNECT_NEEDED => __( 'reconnect needed', 'showfm' ),
		);

		/* translators: %s: connection state. */
		\WP_CLI::line( sprintf( __( 'State: %s', 'showfm' ), $labels[ $state ] ?? $state ) );
		if ( Connection::STATE_DISCONNECTED === $state ) {
			return;
		}

		$expires = $this->connection->expires_at();
		$key     = $this->connection->masked_key();

		/* translators: %s: site id at show.fm. */
		\WP_CLI::line( sprintf( __( 'Site ID: %s', 'showfm' ), (string) $this->connection->site_id() ) );
		/* translators: %s: masked site key. */
		\WP_CLI::line( sprintf( __( 'Key: %s', 'showfm' ), '' === $key ? __( 'unreadable', 'showfm' ) : $key ) );
		/* translators: %s: date and time. */
		\WP_CLI::line( sprintf( __( 'Key expires: %s', 'showfm' ), null === $expires ? __( 'unknown', 'showfm' ) : ( 0 === $expires ? $never : self::time( $expires ) ) ) );
		/* translators: %s: date and time. */
		\WP_CLI::line( sprintf( __( 'Last sync: %s', 'showfm' ), self::time_or( (int) get_option( Health::LAST_SYNC_OPTION, 0 ), $never ) ) );
		/* translators: %s: date and time. */
		\WP_CLI::line( sprintf( __( 'Last ping: %s', 'showfm' ), self::time_or( (int) get_option( Ping_Endpoint::LAST_PING_OPTION, 0 ), $never ) ) );
		if ( Connect::verify_pending() ) {
			\WP_CLI::line( __( 'Reported in: not yet (retried by the daily health check)', 'showfm' ) );
		}
		$waiting = Api_Client::rate_limit_remaining();
		if ( $waiting > 0 ) {
			/* translators: %d: seconds. */
			\WP_CLI::line( sprintf( __( 'Rate limited: waiting %d more seconds', 'showfm' ), $waiting ) );
		}
	}

	/**
	 * Removes this site's show.fm credentials and stops its scheduled syncs.
	 *
	 * The key stays live in show.fm until you revoke it there, under Connected sites.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp showfm disconnect --yes
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Named arguments.
	 */
	public function disconnect( array $args, array $assoc_args ): void {
		if ( Connection::STATE_DISCONNECTED === $this->connection->state() ) {
			\WP_CLI::success( __( 'This site is not connected to show.fm.', 'showfm' ) );
			return;
		}
		\WP_CLI::confirm( __( 'Disconnect this site from show.fm? Posts already created stay.', 'showfm' ), $assoc_args );

		$this->connect->disconnect();
		\WP_CLI::success( __( 'Disconnected. Revoke the key in show.fm under Connected sites if you no longer need it.', 'showfm' ) );
	}

	/**
	 * A UTC date and time.
	 *
	 * @param int $timestamp Unix time.
	 */
	private static function time( int $timestamp ): string {
		return gmdate( 'Y-m-d H:i', $timestamp ) . ' UTC';
	}

	/**
	 * A UTC date and time, or a fallback for 0.
	 *
	 * @param int    $timestamp Unix time, or 0.
	 * @param string $fallback  Text for 0.
	 */
	private static function time_or( int $timestamp, string $fallback ): string {
		return $timestamp > 0 ? self::time( $timestamp ) : $fallback;
	}
}
