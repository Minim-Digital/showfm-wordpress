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

	/** Environment variable that can hold the site key. */
	const KEY_ENV = 'SHOWFM_KEY';

	/** Most bytes read for a key from standard input. */
	const MAX_KEY_BYTES = 1024;

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
	 * Stream `--key=-` reads from.
	 *
	 * @var string
	 */
	private $stdin;

	/**
	 * Whether a person is at the terminal to be asked for the key.
	 *
	 * @var callable(): bool
	 */
	private $interactive;

	/**
	 * Builds the commands.
	 *
	 * @param Connect               $connect     Connect service.
	 * @param Connection            $connection  Connection store.
	 * @param string                $stdin       Stream `--key=-` reads from.
	 * @param callable(): bool|null $interactive Whether to prompt; defaults to "STDIN is a terminal".
	 */
	public function __construct( Connect $connect, Connection $connection, string $stdin = 'php://stdin', ?callable $interactive = null ) {
		$this->connect     = $connect;
		$this->connection  = $connection;
		$this->stdin       = $stdin;
		$this->interactive = $interactive ?? static function (): bool {
			return defined( 'STDIN' ) && function_exists( 'stream_isatty' ) && stream_isatty( STDIN );
		};
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
	 * The key is a live credential. Give it in the SHOWFM_KEY environment variable, pipe it
	 * in with `--key=-`, or run the command in a terminal without --key to be asked for it
	 * with the input hidden. `--key=<key>` still works, but the key then stays in your shell
	 * history and shows in the process list.
	 *
	 * ## OPTIONS
	 *
	 * [--key=<key>]
	 * : The site key, or `-` to read it from standard input. Prefer SHOWFM_KEY or `-`.
	 *
	 * ## EXAMPLES
	 *
	 *     # Uses SHOWFM_KEY if it is set, otherwise asks for the key (input hidden).
	 *     wp showfm connect
	 *
	 *     # Reads the key from standard input, for example from a password manager.
	 *     pass show showfm/site-key | wp showfm connect --key=-
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Named arguments.
	 */
	public function connect( array $args, array $assoc_args ): void {
		$key = $this->read_key( $assoc_args );
		if ( '' === $key ) {
			\WP_CLI::error( __( 'Give the site key from show.fm: set SHOWFM_KEY, pipe it in with --key=-, or run this in a terminal to be asked for it.', 'showfm' ) );
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
	 * The key from --key=-, --key=VALUE, SHOWFM_KEY or a hidden prompt, in that order.
	 *
	 * @param array<string,string|bool> $assoc_args Named arguments; a bare --key is true.
	 */
	private function read_key( array $assoc_args ): string {
		if ( array_key_exists( 'key', $assoc_args ) ) {
			$value = is_string( $assoc_args['key'] ) ? $assoc_args['key'] : '';
			if ( '-' === $value ) {
				return $this->read_stdin();
			}
			\WP_CLI::warning( __( 'A key given as --key=VALUE stays in your shell history and shows in the process list. Use SHOWFM_KEY or --key=- next time.', 'showfm' ) );
			return trim( $value );
		}

		$env = getenv( self::KEY_ENV );
		if ( is_string( $env ) && '' !== trim( $env ) ) {
			return trim( $env );
		}

		if ( ( $this->interactive )() ) {
			return trim( (string) \cli\prompt( __( 'Site key from show.fm (input hidden)', 'showfm' ), '', ': ', true ) );
		}
		return '';
	}

	/**
	 * The first line of standard input, trimmed.
	 */
	private function read_stdin(): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Reading standard input, not a file.
		$handle = fopen( $this->stdin, 'rb' );
		if ( false === $handle ) {
			return '';
		}
		$line = fgets( $handle, self::MAX_KEY_BYTES );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Pairs with the fopen above.
		fclose( $handle );
		return false === $line ? '' : trim( $line );
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
