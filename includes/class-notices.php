<?php
/**
 * Admin notices about the connection.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * At most one notice at a time (WordPress.org guideline 11), in this order: show.fm stopped
 * accepting the key, auto-posting paused by the plan, a sync configuration problem, the key
 * expires within 7 days, within 30 days. Each says how to fix the problem with one action.
 *
 * Notices show on the Dashboard and Plugins screens to users who can manage options. The
 * show.fm settings screen shows the same notice itself on every tab except Connection,
 * which has its own message. Dismissing hides a notice for that user until the next stage:
 * each notice has an instance key that changes with the stage (a new expiry, a new refusal).
 */
final class Notices {

	/** User meta holding the dismissed instance keys. */
	const META = 'showfm_dismissed_notices';

	/** Most dismissed keys kept per user. */
	const MAX_DISMISSED = 50;

	/** An instance key: a kind, a colon and a stage. */
	const KEY_PATTERN = '/^[a-z0-9]{1,20}:[A-Za-z0-9_:-]{1,100}$/';

	/** Screens that show notices (besides the show.fm screen, which renders its own). */
	const SCREENS = array( 'dashboard', 'plugins' );

	/** Script handle for dismissal. */
	const HANDLE = 'showfm-notices';

	/**
	 * Connection store.
	 *
	 * @var Connection
	 */
	private $connection;

	/**
	 * Builds the notices.
	 *
	 * @param Connection $connection Connection store.
	 */
	public function __construct( Connection $connection ) {
		$this->connection = $connection;
	}

	/**
	 * Hooks the notice output.
	 */
	public function boot(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	/**
	 * Prints the current notice on the Dashboard and Plugins screens.
	 */
	public function render(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen || ! in_array( $screen->id, self::SCREENS, true ) || ! current_user_can( Admin::CAPABILITY ) ) {
			return;
		}
		$notice = $this->current( get_current_user_id() );
		if ( null === $notice ) {
			return;
		}

		self::enqueue_script();
		printf(
			'<div class="notice notice-%1$s is-dismissible showfm-notice" data-showfm-notice="%2$s"><p>%3$s</p><p><a class="button" href="%4$s">%5$s</a></p></div>',
			esc_attr( $notice['type'] ),
			esc_attr( $notice['key'] ),
			esc_html( $notice['text'] ),
			esc_url( $notice['action']['url'] ),
			esc_html( $notice['action']['label'] )
		);
	}

	/**
	 * The notice to show this user now, or null.
	 *
	 * @param int $user_id The user.
	 * @return array{key:string,type:string,text:string,action:array{label:string,url:string}}|null
	 */
	public function current( int $user_id ): ?array {
		$dismissed = self::dismissed( $user_id );
		foreach ( $this->candidates() as $notice ) {
			if ( ! in_array( self::scoped( $notice['key'] ), $dismissed, true ) ) {
				return $notice;
			}
		}
		return null;
	}

	/**
	 * Hides a notice instance for a user.
	 *
	 * @param int    $user_id The user.
	 * @param string $key     Instance key.
	 */
	public static function dismiss( int $user_id, string $key ): bool {
		if ( ! preg_match( self::KEY_PATTERN, $key ) ) {
			return false;
		}
		$dismissed = self::dismissed( $user_id );
		$scoped    = self::scoped( $key );
		if ( ! in_array( $scoped, $dismissed, true ) ) {
			$dismissed[] = $scoped;
		}
		update_user_meta( $user_id, self::META, array_slice( $dismissed, -self::MAX_DISMISSED ) );
		return true;
	}

	/**
	 * The notices that apply now, highest first.
	 *
	 * @return array<int,array{key:string,type:string,text:string,action:array{label:string,url:string}}>
	 */
	public function candidates(): array {
		if ( Connection::STATE_DISCONNECTED === $this->connection->state() || $this->connection->is_unreadable() ) {
			return array();
		}

		$notices   = array();
		$reconnect = array(
			'label' => __( 'Reconnect', 'showfm' ),
			'url'   => self::reconnect_url(),
		);
		$expires   = (int) $this->connection->expires_at();
		$expired   = $expires > 0 && $expires <= time();

		if ( ! $this->connection->is_connected() ) {
			if ( ! $expired && Connection::refused_at() > 0 ) {
				$notices[] = array(
					'key'    => 'refused:' . Connection::refused_at(),
					'type'   => 'error',
					'text'   => __( 'show.fm disconnected this site, for example because the account password changed.', 'showfm' ),
					'action' => $reconnect,
				);
			}
			return $notices;
		}

		if ( Connection::paused_at() > 0 ) {
			$notices[] = array(
				'key'    => 'paused:' . Connection::paused_at(),
				'type'   => 'warning',
				'text'   => __( 'Auto-posting is paused. The show’s plan doesn’t include connected sites, so new episodes aren’t posted here.', 'showfm' ),
				'action' => array(
					'label' => __( 'Check your plan', 'showfm' ),
					'url'   => self::plan_url(),
				),
			);
		}

		$problem = Health::sync_problem();
		if ( '' !== $problem ) {
			$texts     = array(
				'row_post_type' => __( 'New episodes aren’t being posted here because the chosen post type isn’t available.', 'showfm' ),
				'row_author'    => __( 'New episodes aren’t being posted here because the chosen author can’t publish posts.', 'showfm' ),
				'invalid_feed'  => __( 'New episodes aren’t being posted here because show.fm sent changes the plugin couldn’t read. It keeps trying.', 'showfm' ),
			);
			$notices[] = array(
				'key'    => 'sync:' . $problem . ':' . $this->connection->site_id(),
				'type'   => 'warning',
				'text'   => $texts[ $problem ],
				'action' => array(
					'label' => __( 'See what to fix', 'showfm' ),
					'url'   => admin_url( 'site-health.php' ),
				),
			);
		}

		$days = self::days_left( $expires );
		if ( null !== $days && $days <= 30 ) {
			$notices[] = array(
				'key'    => ( $days <= 7 ? 'expiry7:' : 'expiry30:' ) . $expires,
				'type'   => 'warning',
				/* translators: %d: number of days. */
				'text'   => sprintf( _n( 'Your show.fm connection expires in %d day.', 'Your show.fm connection expires in %d days.', $days, 'showfm' ), $days ),
				'action' => $reconnect,
			);
		}
		return $notices;
	}

	/**
	 * Whole days until the key expires, rounded up, or null when it never expires or has
	 * already expired.
	 *
	 * @param int $expires Expiry as a Unix timestamp, or 0.
	 */
	public static function days_left( int $expires ): ?int {
		if ( $expires <= time() ) {
			return null;
		}
		return (int) ceil( ( $expires - time() ) / DAY_IN_SECONDS );
	}

	/**
	 * Starts the connect flow straight away (the nonce makes it a deliberate click).
	 */
	public static function reconnect_url(): string {
		// Not wp_nonce_url(): its `&amp;` would reach the React screen as text.
		return add_query_arg(
			array(
				'action'   => Connect::ACTION,
				'_wpnonce' => wp_create_nonce( Connect::ACTION ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Where to check or change a show's plan.
	 */
	public static function plan_url(): string {
		return Connect::app_url() . '/pricing';
	}

	/**
	 * Loads the dismissal script.
	 */
	public static function enqueue_script(): void {
		$asset = SHOWFM_DIR . '/build/notices.asset.php';
		if ( ! is_readable( $asset ) ) {
			return;
		}
		$asset = require $asset;
		wp_enqueue_script( self::HANDLE, plugins_url( 'build/notices.js', SHOWFM_FILE ), $asset['dependencies'], $asset['version'], true );
	}

	/**
	 * The user's dismissed keys.
	 *
	 * @param int $user_id The user.
	 * @return string[]
	 */
	private static function dismissed( int $user_id ): array {
		$keys = get_user_meta( $user_id, self::META, true );
		return is_array( $keys ) ? array_values( array_filter( $keys, 'is_string' ) ) : array();
	}

	/**
	 * A key scoped to this site, since user meta is shared across a network.
	 *
	 * @param string $key Instance key.
	 */
	private static function scoped( string $key ): string {
		return get_current_blog_id() . '|' . $key;
	}
}
