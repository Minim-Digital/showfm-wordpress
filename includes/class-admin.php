<?php
/**
 * The show.fm settings page: registration and the connect flow's entry points.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the settings page that show.fm returns to, and wires the connect flow to it.
 *
 * The screen itself is a minimal placeholder: the designed screens (Connection, Publishing,
 * Display and Migrate tabs) replace `render()`. Everything here needs `manage_options`.
 */
final class Admin {

	/** Capability for every action on the page. */
	const CAPABILITY = 'manage_options';

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
	 * Builds the page.
	 *
	 * @param Connect    $connect    Connect service.
	 * @param Connection $connection Connection store.
	 */
	public function __construct( Connect $connect, Connection $connection ) {
		$this->connect    = $connect;
		$this->connection = $connection;
	}

	/**
	 * Hooks the page and the connect action.
	 */
	public function boot(): void {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_post_' . Connect::ACTION, array( $this, 'start_connect' ) );
	}

	/**
	 * Adds the page and hooks the return handling to its load.
	 */
	public function register_page(): void {
		$hook = add_menu_page(
			__( 'show.fm', 'showfm' ),
			__( 'show.fm', 'showfm' ),
			self::CAPABILITY,
			Connect::PAGE,
			array( $this, 'render' ),
			'dashicons-microphone'
		);
		if ( '' !== $hook ) {
			add_action( 'load-' . $hook, array( $this, 'load' ) );
		}
	}

	/**
	 * Starts the browser flow: checks the capability and nonce, then sends the admin to
	 * my.show.fm.
	 */
	public function start_connect(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to connect this site to show.fm.', 'showfm' ), 403 );
		}
		check_admin_referer( Connect::ACTION );

		$url = $this->connect->start( get_current_user_id() );
		add_filter( 'allowed_redirect_hosts', array( self::class, 'allow_app_host' ) );
		wp_safe_redirect( $url, 303 );
		exit;
	}

	/**
	 * Settings page load, before any output. A return from show.fm is checked and the
	 * address cleaned at once; a code kept from that return is then exchanged.
	 */
	public function load(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$user_id = get_current_user_id();

		// The state is this request's CSRF check: it must match the flow this admin started.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$query = array();
		foreach ( array( 'code', 'state' ) as $name ) {
			if ( isset( $_GET[ $name ] ) ) {
				$query[ $name ] = sanitize_text_field( wp_unslash( $_GET[ $name ] ) );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$redirect = $this->connect->handle_return( $user_id, $query );
		if ( null !== $redirect ) {
			wp_safe_redirect( $redirect, 303 );
			exit;
		}
		$this->connect->complete_pending( $user_id );
	}

	/**
	 * Placeholder screen: the connection state, the last outcome and the Connect button.
	 */
	public function render(): void {
		$user_id = get_current_user_id();
		$result  = Connect::result( $user_id );
		Connect::clear_result( $user_id );
		$state = $this->connection->state();

		echo '<div class="wrap"><h1>' . esc_html__( 'show.fm', 'showfm' ) . '</h1>';

		if ( null !== $result ) {
			$class = '' === $result['error'] ? 'notice-success' : ( Connect::STATUS_CONNECTED === $result['status'] ? 'notice-warning' : 'notice-error' );
			$text  = '' === $result['error'] ? __( 'This site is connected to show.fm.', 'showfm' ) : Connect::message( $result['error'], $result['retry_after'] );
			echo '<div class="notice ' . esc_attr( $class ) . '"><p>' . esc_html( $text ) . '</p></div>';
		}

		if ( Connection::STATE_CONNECTED === $state ) {
			/* translators: %s: the site key with all but its last four characters hidden. */
			echo '<p>' . esc_html( sprintf( __( 'Connected with key %s.', 'showfm' ), $this->connection->masked_key() ) ) . '</p>';
		} elseif ( Connection::STATE_RECONNECT_NEEDED === $state ) {
			echo '<p>' . esc_html__( 'show.fm no longer accepts this site\'s key. Reconnect to keep publishing.', 'showfm' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'Connect this site to your show.fm account to publish episodes as posts.', 'showfm' ) . '</p>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( Connect::ACTION ) . '" />';
		wp_nonce_field( Connect::ACTION );
		submit_button( Connection::STATE_DISCONNECTED === $state ? __( 'Connect to show.fm', 'showfm' ) : __( 'Reconnect to show.fm', 'showfm' ), 'primary', 'submit', false );
		echo '</form></div>';
	}

	/**
	 * Lets `wp_safe_redirect()` send the admin to the show.fm app.
	 *
	 * @param string[] $hosts Allowed hosts.
	 * @return string[]
	 */
	public static function allow_app_host( array $hosts ): array {
		$host = wp_parse_url( Connect::app_url(), PHP_URL_HOST );
		if ( is_string( $host ) ) {
			$hosts[] = $host;
		}
		return $hosts;
	}
}
