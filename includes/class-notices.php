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
 * expires within 7 days, within 30 days. Each says how to fix the problem with one action:
 * Reconnect is a form that POSTs to admin-post.php with a nonce, the others are links.
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
		// One read of the connection answers every question the notice asks.
		$notice = ( new self( $this->connection->pinned() ) )->current( get_current_user_id() );
		if ( null === $notice ) {
			return;
		}

		self::enqueue_script();
		printf(
			'<div class="notice notice-%1$s is-dismissible showfm-notice" data-showfm-notice="%2$s"><p>%3$s</p>',
			esc_attr( $notice['type'] ),
			esc_attr( $notice['key'] ),
			esc_html( $notice['text'] )
		);
		if ( 'reconnect' === $notice['action']['type'] ) {
			// Starting the flow is a POST with a nonce, as on the settings screen.
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><p>';
			echo '<input type="hidden" name="action" value="' . esc_attr( Connect::ACTION ) . '" />';
			wp_nonce_field( Connect::ACTION, '_wpnonce', true );
			echo '<button type="submit" class="button">' . esc_html( $notice['action']['label'] ) . '</button></p></form>';
		} else {
			printf( '<p><a class="button" href="%1$s">%2$s</a></p>', esc_url( $notice['action']['url'] ), esc_html( $notice['action']['label'] ) );
		}
		echo '</div>';
	}

	/**
	 * The notice to show this user now, or null.
	 *
	 * @param int $user_id The user.
	 * @return array{key:string,type:string,text:string,action:array{type:string,label:string,url:string}}|null
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
	 * @return array<int,array{key:string,type:string,text:string,action:array{type:string,label:string,url:string}}>
	 */
	public function candidates(): array {
		$notices = array();
		// 1.0.2 replaced these constants with the showfm_environment filter.
		if ( defined( 'SHOWFM_API_URL' ) || defined( 'SHOWFM_APP_URL' ) ) {
			$notices[] = array(
				'key'    => 'constants:1',
				'type'   => 'warning',
				'text'   => __( 'SHOWFM_API_URL and SHOWFM_APP_URL no longer choose the show.fm environment. To use another one, return it from the showfm_environment filter.', 'showfm' ),
				'action' => array(
					'type'  => 'link',
					'label' => __( 'How to choose an environment', 'showfm' ),
					'url'   => 'https://github.com/ShowDotFM/showfm-wordpress#another-showfm-environment',
				),
			);
		}
		if ( Connection::STATE_DISCONNECTED === $this->connection->state() || $this->connection->is_unreadable() ) {
			return $notices;
		}

		$reconnect = array(
			'type'  => 'reconnect',
			'label' => __( 'Reconnect', 'showfm' ),
			'url'   => '',
		);
		$expires   = (int) $this->connection->expires_at();
		$expired   = $expires > 0 && $expires <= time();

		if ( ! $this->connection->is_connected() ) {
			if ( $this->connection->issued_elsewhere() ) {
				$notices[] = array(
					'key'    => 'environment:' . substr( md5( Api_Client::base_url() ), 0, 12 ),
					'type'   => 'error',
					'text'   => __( 'This site was connected with a different show.fm environment, so its key isn’t used here. Reconnect to post new episodes again.', 'showfm' ),
					'action' => $reconnect,
				);
				return $notices;
			}
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

		$paused = $this->connection->paused_since();
		if ( $paused > 0 ) {
			$notices[] = array(
				'key'    => 'paused:' . $paused,
				'type'   => 'warning',
				'text'   => __( 'Auto-posting is paused. The show’s plan doesn’t include connected sites, so new episodes aren’t posted here.', 'showfm' ),
				'action' => array(
					'type'  => 'link',
					'label' => __( 'Check your plan', 'showfm' ),
					'url'   => self::plan_url(),
				),
			);
		}

		$problem = Health::sync_problem( $this->connection );
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
				'action' => 'invalid_feed' === $problem ? array(
					'type'  => 'link',
					'label' => __( 'See what to fix', 'showfm' ),
					'url'   => admin_url( 'site-health.php' ),
				) : array(
					'type'  => 'link',
					'label' => __( 'Check the Publishing settings', 'showfm' ),
					'url'   => add_query_arg( 'tab', 'publishing', Connect::settings_url() ),
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
