<?php
/**
 * Daily health report to show.fm.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Once a day a connected site posts `/v1/me/sites/{id}/health`: the plugin, WordPress and
 * PHP versions, the last sync time and the sync error count. After a success it refreshes
 * the account name and shows for the settings screen. It finishes a verify that did
 * not go through at connect time first. It runs only while connected: after a 401 the
 * connection needs reconnecting and the client sends nothing, and after a 429 the client
 * waits for Retry-After.
 */
final class Health {

	/** Daily WP-Cron hook. */
	const HOOK = 'showfm_health';

	/** Delay before the first report after connecting, in seconds. */
	const FIRST_RUN_DELAY = 300;

	/** Option holding the time of the last successful sync (written by the sync). */
	const LAST_SYNC_OPTION = 'showfm_last_sync_at';

	/** Option holding the sync error count (written by the sync). */
	const SYNC_ERRORS_OPTION = 'showfm_sync_error_count';

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
	 * Connect service, for a pending verify.
	 *
	 * @var Connect
	 */
	private $connect;

	/**
	 * Builds the reporter.
	 *
	 * @param Connection $connection Connection store.
	 * @param Api_Client $api_client API client.
	 * @param Connect    $connect    Connect service.
	 */
	public function __construct( Connection $connection, Api_Client $api_client, Connect $connect ) {
		$this->connection = $connection;
		$this->api_client = $api_client;
		$this->connect    = $connect;
	}

	/**
	 * Register a local Site Health test; viewing health never sends HTTP.
	 *
	 * @param array<string,mixed> $tests WordPress tests.
	 * @return array<string,mixed>
	 */
	public static function site_tests( array $tests ): array {
		$tests['direct']['showfm_sync'] = array(
			'label' => __( 'show.fm sync', 'showfm' ),
			'test'  => array( self::class, 'test_sync' ),
		);
		return $tests;
	}

	/**
	 * Explain actionable sync failures without changing the remote health contract.
	 *
	 * @return array<string,mixed>
	 */
	public static function test_sync(): array {
		$code     = self::sync_problem();
		$messages = array(
			'row_post_type' => __( 'The selected post type is unavailable or is not public. Enable it or choose an available public post type. The episode is kept for retry and syncing resumes automatically after the setting is fixed, with retries at most an hour apart.', 'showfm' ),
			'row_author'    => __( 'Sync needs an existing author who can publish the selected post type. Choose a publishing author for this site. The episode is kept for retry and syncing resumes automatically after the setting is fixed, with retries at most an hour apart.', 'showfm' ),
			'invalid_feed'  => __( 'The show.fm change feed has a server contract problem. The cursor is kept before the invalid page and the plugin retries automatically. Contact show.fm support if this persists.', 'showfm' ),
		);
		$problem  = isset( $messages[ $code ] );
		return array(
			'label'       => $problem ? __( 'show.fm sync needs attention', 'showfm' ) : __( 'show.fm has no blocking configuration or contract problem', 'showfm' ),
			'status'      => $problem ? 'critical' : 'good',
			'badge'       => array(
				'label' => 'show.fm',
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html( $messages[ $code ] ?? __( 'No blocking configuration or server contract problem is recorded for this connection.', 'showfm' ) ) . '</p>',
			'actions'     => '',
			'test'        => 'showfm_sync',
		);
	}

	/**
	 * The blocking sync problem recorded for this connection: `row_post_type`, `row_author`,
	 * `invalid_feed`, or '' for none. Reads local state only.
	 *
	 * @param Connection|null $connection The connection to ask, such as a caller's pinned
	 *                                    copy; the shared one when not given.
	 */
	public static function sync_problem( ?Connection $connection = null ): string {
		$connection = $connection ?? Plugin::connection();
		if ( ! $connection->is_connected() ) {
			return '';
		}
		$state = Sync::state();
		if ( $state['site'] === $connection->site_id() && 'invalid_feed' === $state['error'] ) {
			return 'invalid_feed';
		}
		$code = $connection->sync_status()['retry']['code'] ?? '';
		return in_array( $code, array( 'row_post_type', 'row_author' ), true ) ? $code : '';
	}

	/**
	 * Schedules the daily report unless it is already scheduled.
	 */
	public static function schedule(): void {
		if ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + self::FIRST_RUN_DELAY, 'daily', self::HOOK );
		}
	}

	/**
	 * Puts the daily report back if a connected site lost it (for example, after the plugin
	 * was deactivated and activated again). Runs on `admin_init`; makes no request.
	 */
	public function ensure_scheduled(): void {
		if ( $this->connection->is_connected() ) {
			self::schedule();
		}
	}

	/**
	 * WP-Cron callback.
	 */
	public function run(): void {
		$this->report();
	}

	/**
	 * Sends the report. Returns the last call's result, or null when not connected.
	 */
	public function report(): ?Api_Result {
		$site_id = $this->connection->site_id();
		if ( null === $site_id || ! $this->connection->is_connected() ) {
			return null;
		}

		if ( Connect::verify_pending() ) {
			$verified = $this->connect->verify();
			if ( ! $verified->is( Api_Result::SUCCESS ) ) {
				return $verified;
			}
		}

		$result = $this->api_client->post_keyed( '/v1/me/sites/' . rawurlencode( $site_id ) . '/health', self::payload() );
		Connection::note_report( $result );
		if ( $result->is( Api_Result::SUCCESS ) ) {
			( new Account( $this->connection, $this->api_client ) )->refresh();
		}
		return $result;
	}

	/**
	 * The health report body.
	 *
	 * @return array<string,mixed>
	 */
	public static function payload(): array {
		$last_sync = (int) get_option( self::LAST_SYNC_OPTION, 0 );

		$payload                     = self::versions();
		$payload['last_sync_at']     = $last_sync > 0 ? gmdate( 'Y-m-d\TH:i:s\Z', $last_sync ) : null;
		$payload['sync_error_count'] = max( 0, (int) get_option( self::SYNC_ERRORS_OPTION, 0 ) );
		return $payload;
	}

	/**
	 * Plugin, WordPress and PHP versions, in the form show.fm accepts.
	 *
	 * @return array{plugin_version:string,wp_version:string,php_version:string}
	 */
	public static function versions(): array {
		return array(
			'plugin_version' => self::version_string( SHOWFM_VERSION ),
			'wp_version'     => self::version_string( (string) get_bloginfo( 'version' ) ),
			'php_version'    => self::version_string( PHP_VERSION ),
		);
	}

	/**
	 * A version string of at most 32 letters, digits, dots, pluses, underscores, spaces and dashes.
	 *
	 * @param string $version Raw version.
	 */
	private static function version_string( string $version ): string {
		$clean = trim( substr( (string) preg_replace( '/[^0-9A-Za-z.+_ -]/', '', $version ), 0, 32 ) );
		return '' === $clean ? 'unknown' : $clean;
	}
}
