<?php
/**
 * Embed migration command, without an admin interface.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Streams report output instead of collecting an entire site's results in memory. */
final class Migration_Cli {
	/**
	 * Engine.
	 *
	 * @var Migrator
	 */
	private $migrator;

	/**
	 * Whether a post in the last run failed to migrate.
	 *
	 * @var bool
	 */
	private $failed = false;

	/**
	 * Build command.
	 *
	 * @param Migrator $migrator Engine.
	 */
	public function __construct( Migrator $migrator ) {
		$this->migrator = $migrator;
	}

	/**
	 * Scan or replace podcast embeds in published posts and pages.
	 *
	 * A dry run stores a report for review. Use --resume --yes to apply that report;
	 * a post edited since scanning is refused. Revisions provide the undo. On multisite,
	 * use --url to select the site. --user must be a site administrator.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Scan and report without changing posts.
	 *
	 * [--yes]
	 * : Replace matched embeds and explicitly chosen ambiguous embeds.
	 *
	 * [--post=<id>]
	 * : Scan only this published post or page.
	 *
	 * [--choose=<choices>]
	 * : Comma-separated post:embed:episode UUID choices from ambiguous candidates.
	 *
	 * [--format=<format>]
	 * : Output table (default) or JSON.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * [--resume]
	 * : Continue the current scan or apply its completed report.
	 *
	 * [--reset]
	 * : Discard pending catalogue pages. Use alone, or with --dry-run to start afresh.
	 *
	 * [--batches=<number>]
	 * : Stop after this many batches of 50; continue with --resume.
	 *
	 * ## EXAMPLES
	 *
	 *     wp showfm migrate-embeds --dry-run --user=admin
	 *     wp showfm migrate-embeds --resume --yes --user=admin
	 *     wp showfm migrate-embeds --dry-run --post=42 --format=json --user=admin
	 *
	 * @param string[]                  $args       Positional arguments.
	 * @param array<string,string|bool> $assoc_args Named arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		if ( ! empty( $assoc_args['reset'] ) ) {
			if ( ! empty( $assoc_args['yes'] ) || ! empty( $assoc_args['resume'] ) || ! empty( $assoc_args['choose'] ) ) {
				\WP_CLI::error( __( 'Use --reset alone or with --dry-run; it cannot apply or resume an existing report.', 'showfm' ) );
				return;
			}
			if ( empty( $assoc_args['dry-run'] ) ) {
				// Without the capability, reset() refuses before anything changes.
				if ( current_user_can( 'manage_options' ) ) {
					$this->claim();
				}
				$reset = $this->migrator->reset();
				if ( is_wp_error( $reset ) ) {
					$this->fail( $reset->get_error_message() );
					return;
				}
				$this->release();
				\WP_CLI::success( __( 'Pending catalogue pages discarded. The active report, posts and revisions are unchanged.', 'showfm' ) );
				return;
			}
		}
		$access = $this->migrator->access();
		if ( is_wp_error( $access ) ) {
			\WP_CLI::error( self::terminal( $access->get_error_message() ) );
			return;
		}
		// The run is this command's, as a scan or swap in the Migrate tab is its admin's.
		$this->claim();
		$this->run( $assoc_args );
		$this->release();
		if ( $this->failed ) {
			\WP_CLI::error( __( 'Some posts could not be migrated. Review the report.', 'showfm' ) );
		}
	}

	/**
	 * Everything after access and the lease: validate, scan, apply and print.
	 *
	 * @param array<string,string|bool> $assoc_args Named arguments.
	 */
	private function run( array $assoc_args ): void {
		if ( empty( $assoc_args['dry-run'] ) && empty( $assoc_args['yes'] ) ) {
			$this->fail( __( 'Use --dry-run to review or --yes to replace matched embeds.', 'showfm' ) );
			return;
		}
		$format = $assoc_args['format'] ?? 'table';
		if ( ! in_array( $format, array( 'table', 'json' ), true ) ) {
			$this->fail( __( 'Use --format=table or --format=json.', 'showfm' ) );
			return;
		}
		foreach ( array( 'post', 'batches' ) as $key ) {
			if ( isset( $assoc_args[ $key ] ) && ( ! ctype_digit( (string) $assoc_args[ $key ] ) || (int) $assoc_args[ $key ] < 1 ) ) {
				$this->fail( __( 'Post and batch limits must be positive integers.', 'showfm' ) );
				return;
			}
		}
		$choices = self::choices( (string) ( $assoc_args['choose'] ?? '' ) );
		if ( is_wp_error( $choices ) ) {
			$this->fail( $choices->get_error_message() );
			return;
		}
		$applying = empty( $assoc_args['dry-run'] );
		$state    = Migration_Store::state();
		if ( $applying ) {
			if ( empty( $state['complete'] ) || empty( $state['dry_run'] ) ) {
				$this->fail( __( 'Complete a dry run before using --yes. Only that saved report can be applied.', 'showfm' ) );
				return;
			}
		} else {
			$pending = get_option( Migration_Catalogue::PENDING, array() );
			if ( empty( $assoc_args['resume'] ) || $pending ) {
				$post_id = (int) ( $assoc_args['post'] ?? ( ! empty( $assoc_args['resume'] ) ? ( $pending['post_id'] ?? 0 ) : 0 ) );
				$state   = $this->migrator->start( $post_id, ! empty( $assoc_args['reset'] ) );
			}
		}
		if ( is_wp_error( $state ) || ! $state ) {
			$this->fail( is_wp_error( $state ) ? self::terminal( $state->get_error_message() ) : __( 'There is no scan to resume.', 'showfm' ) );
			return;
		}
		if ( isset( $assoc_args['post'] ) && (int) $assoc_args['post'] !== $state['post_id'] ) {
			$this->fail( __( 'The post restriction differs from the saved scan. Start a new scan.', 'showfm' ) );
			return;
		}
		$limit = (int) ( $assoc_args['batches'] ?? PHP_INT_MAX );
		for ( $batch = 0; $batch < $limit && ! $state['complete']; ++$batch ) {
			$this->claim();
			$state = $this->migrator->batch( $state['run'] );
			if ( is_wp_error( $state ) ) {
				$this->fail( $state->get_error_message() );
				return;
			}
		}
		// Validate every explicit choice before changing any post.
		foreach ( $choices as $post_id => $selected ) {
			$report = Migration_Store::get( $state['run'], $post_id );
			foreach ( $selected as $embed => $episode ) {
				$valid = false;
				foreach ( $report['items'] ?? array() as $item ) {
					if ( ( $report['status'] ?? '' ) === 'scanned' && $item['embed'] === $embed && 'ambiguous' === $item['status'] && in_array( $episode, array_column( $item['candidates'], 'id' ), true ) ) {
						$valid = true;
					}
				}
				if ( ! $valid ) {
					$this->fail( __( 'Each choice must name an ambiguous embed and one of its reported candidates.', 'showfm' ) );
					return;
				}
			}
		}
		$apply = empty( $assoc_args['dry-run'] ) && $state['complete'];
		if ( 'json' === $format ) {
			\WP_CLI::line( '{"run":' . wp_json_encode( $state['run'] ) . ',"complete":' . ( $state['complete'] ? 'true' : 'false' ) . ',"reports":[' );
		} else {
			\WP_CLI::line( "POST\tEMBED\tHOST\tSTATUS\tMETHOD\tCANDIDATES\tREVISION" );
		}
		$first   = true;
		$failed  = false;
		$stopped = false;
		foreach ( Migration_Store::reports( $state['run'] ) as $report ) {
			if ( $apply && 'scanned' === $report['status'] ) {
				$this->claim();
				$result = $this->migrator->swap( $report['post_id'], $choices[ $report['post_id'] ] ?? array(), $state['run'] );
				if ( is_wp_error( $result ) ) {
					$report['error']  = $result->get_error_message();
					$report['status'] = 'error';
					$failed           = true;
					// A run-wide problem stops the swap here rather than failing every post after it.
					$stopped = in_array( $result->get_error_code(), array( 'showfm_reconnected', 'showfm_not_connected', 'showfm_stale_run' ), true );
				} else {
					$report = $result;
				}
			}
			$failed = $failed || 'error' === $report['status'];
			if ( 'json' === $format ) {
				\WP_CLI::line( ( $first ? '' : ',' ) . wp_json_encode( $report ) );
			} else {
				self::table( $report );
			}
			$first = false;
			if ( $stopped ) {
				break;
			}
		}
		if ( 'json' === $format ) {
			\WP_CLI::line( ']}' );
		} elseif ( ! $state['complete'] ) {
			\WP_CLI::line( __( 'Scan paused. Continue with --resume; no posts have been changed.', 'showfm' ) );
		}
		$this->failed = $failed;
	}

	/**
	 * Takes or renews the lease, or stops when another admin's run in the Migrate tab is live.
	 */
	private function claim(): void {
		$claim = Migration_Admin::claim();
		if ( is_wp_error( $claim ) ) {
			$this->fail( $claim->get_error_message() );
		}
	}

	/**
	 * Gives the lease back, and says so when it couldn't.
	 */
	private function release(): void {
		if ( ! Migration_Admin::release() ) {
			\WP_CLI::warning( __( 'Couldn’t give the run back because another request held the site lock. Other admins may wait up to two minutes.', 'showfm' ) );
		}
	}

	/**
	 * Gives the lease back, then stops with an error. WP-CLI exits on an error, so nothing
	 * after it, such as a finally block, would run.
	 *
	 * @param string $message Message.
	 */
	private function fail( string $message ): void {
		$this->release();
		\WP_CLI::error( self::terminal( $message ) );
	}

	/**
	 * Parse explicit ambiguous choices. Embed numbers are one-based within each post.
	 *
	 * @param string $input Comma-separated triples.
	 * @return array<int,array<int,string>>|\WP_Error
	 */
	public static function choices( string $input ) {
		$choices = array();
		foreach ( '' === $input ? array() : explode( ',', $input ) as $choice ) {
			$parts = explode( ':', $choice );
			if ( 3 !== count( $parts ) || ! ctype_digit( $parts[0] ) || ! ctype_digit( $parts[1] ) || (int) $parts[0] < 1 || (int) $parts[1] < 1 || '' === Attributes::uuid( $parts[2] ) || isset( $choices[ (int) $parts[0] ][ (int) $parts[1] ] ) ) {
				return new \WP_Error( 'showfm_invalid_choice', __( 'Use --choose=post:embed:episode-uuid, with one choice per ambiguous embed.', 'showfm' ) );
			}
			$choices[ (int) $parts[0] ][ (int) $parts[1] ] = strtolower( $parts[2] );
		}
		return $choices;
	}

	/**
	 * A report table row per embed, without terminal control characters from post content.
	 *
	 * @param array<string,mixed> $report Post report.
	 */
	private static function table( array $report ): void {
		if ( empty( $report['items'] ) ) {
			\WP_CLI::line( $report['post_id'] . "\t-\t-\t" . __( 'Check by hand', 'showfm' ) );
		}
		foreach ( $report['items'] as $item ) {
			// The same words as the Migrate tab's report; JSON keeps the engine's values.
			$labels = Migration_Admin::labels( $report, $item );
			\WP_CLI::line( implode( "\t", array_map( array( self::class, 'terminal' ), array( $report['post_id'], $item['embed'], $labels['host'], $labels['status'], $labels['method'], implode( ',', array_column( $item['candidates'], 'id' ) ), $report['revision_id'] ? $report['revision_id'] : '-' ) ) ) );
		}
		if ( isset( $report['error'] ) ) {
			\WP_CLI::warning( self::terminal( $report['error'] ) );
		}
	}
	/**
	 * Strip terminal controls from diagnostic text and externally supplied identifiers.
	 *
	 * @param mixed $value Text.
	 */
	public static function terminal( $value ): string {
		return (string) preg_replace( '/[\x00-\x1f\x7f-\x9f]/u', '', (string) $value );
	}
}
