<?php
/**
 * The show.fm settings page: registration, assets and the connect flow's entry points.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings > show.fm. The screen is a React app (`build/admin.js`) built with
 * `@wordpress/components` from core. Its data comes from `Admin_Endpoint`, preloaded into
 * the page so it renders without a round trip. Everything here needs `manage_options`.
 *
 * The show.fm app links to `admin.php?page=showfm` (from its emails and its connected sites
 * list), so that address redirects here with the connect return arguments kept.
 */
final class Admin {

	/** Capability for every action on the page. */
	const CAPABILITY = 'manage_options';

	/** Hook suffix of the settings page. */
	const SCREEN = 'settings_page_' . Connect::PAGE;

	/** Script and style handle. */
	const HANDLE = 'showfm-settings';

	/** REST paths preloaded into the page. Must match the paths the app requests. */
	const PRELOAD = array(
		'/showfm/v1/admin/connection',
		'/wp/v2/settings?_fields=showfm_show_credit,showfm_load_on_click,showfm_json_ld,showfm_theme_styles',
	);

	/** Query arguments kept when the old address redirects. */
	const RETURN_ARGS = array( 'code', 'state', Connect::RETURN_ARG, 'tab' );

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
	 * Hooks the page, its assets, the connect action and the old address.
	 */
	public function boot(): void {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_page_access_denied', array( self::class, 'redirect_old_address' ) );
		add_action( 'admin_init', array( self::class, 'redirect_old_address' ), 1 );
		add_action( 'admin_post_' . Connect::ACTION, array( $this, 'start_connect' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SHOWFM_FILE ), array( self::class, 'action_links' ) );
		( new Notices( $this->connection ) )->boot();
	}

	/**
	 * Adds Settings > show.fm and hooks the return handling to its load.
	 */
	public function register_page(): void {
		$hook = add_options_page(
			__( 'show.fm', 'showfm' ),
			__( 'show.fm', 'showfm' ),
			self::CAPABILITY,
			Connect::PAGE,
			array( $this, 'render' )
		);
		if ( false !== $hook ) {
			add_action( 'load-' . $hook, array( $this, 'load' ) );
		}
	}

	/**
	 * Sends `admin.php?page=showfm` to Settings > show.fm, keeping the connect return.
	 */
	public static function redirect_old_address(): void {
		global $pagenow;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- A redirect only; the settings page checks the state.
		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'admin.php' !== $pagenow || Connect::PAGE !== $page || is_network_admin() ) {
			return;
		}
		$args = array();
		foreach ( self::RETURN_ARGS as $name ) {
			if ( isset( $_GET[ $name ] ) && is_string( $_GET[ $name ] ) ) {
				$args[ $name ] = rawurlencode( sanitize_text_field( wp_unslash( $_GET[ $name ] ) ) );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		wp_safe_redirect( add_query_arg( $args, Connect::settings_url() ), 301 );
		exit;
	}

	/**
	 * Adds "Settings" to the plugin's row on the Plugins screen.
	 *
	 * @param array<int|string,string> $links Action links.
	 * @return array<int|string,string>
	 */
	public static function action_links( array $links ): array {
		if ( current_user_can( self::CAPABILITY ) ) {
			array_unshift( $links, '<a href="' . esc_url( Connect::settings_url() ) . '">' . esc_html__( 'Settings', 'showfm' ) . '</a>' );
		}
		return $links;
	}

	/**
	 * Starts the browser flow: checks the capability and nonce, then sends the admin to
	 * my.show.fm. A site without https is stopped here, since show.fm would refuse it.
	 */
	public function start_connect(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to connect this site to show.fm.', 'showfm' ), 403 );
		}
		check_admin_referer( Connect::ACTION );

		if ( ! Connect::site_is_https() ) {
			$this->connect->fail( get_current_user_id(), Connect::ERROR_INSECURE );
			wp_safe_redirect( Connect::settings_url(), 303 );
			exit;
		}

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
		foreach ( array( 'code', 'state', Connect::RETURN_ARG ) as $name ) {
			if ( isset( $_GET[ $name ] ) && is_string( $_GET[ $name ] ) ) {
				$query[ $name ] = sanitize_text_field( wp_unslash( $_GET[ $name ] ) );
			}
		}
		$tab = isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$redirect = $this->connect->handle_return( $user_id, $query );
		if ( null !== $redirect ) {
			wp_safe_redirect( '' === $tab ? $redirect : add_query_arg( 'tab', $tab, $redirect ), 303 );
			exit;
		}
		$this->connect->complete_pending( $user_id );
	}

	/**
	 * Loads the app on the settings page, with its data preloaded.
	 *
	 * @param string $hook_suffix The current admin page.
	 */
	public static function enqueue( string $hook_suffix ): void {
		if ( self::SCREEN !== $hook_suffix || ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$asset = SHOWFM_DIR . '/build/admin.asset.php';
		if ( ! is_readable( $asset ) ) {
			return;
		}
		$asset = require $asset;
		wp_enqueue_script( self::HANDLE, plugins_url( 'build/admin.js', SHOWFM_FILE ), $asset['dependencies'], $asset['version'], true );
		wp_set_script_translations( self::HANDLE, 'showfm' );
		wp_enqueue_style( self::HANDLE, plugins_url( 'build/admin.css', SHOWFM_FILE ), array( 'wp-components' ), $asset['version'] );
		wp_style_add_data( self::HANDLE, 'rtl', 'replace' );

		$preload = array_reduce( self::PRELOAD, 'rest_preload_api_request', array() );
		wp_add_inline_script(
			'wp-api-fetch',
			sprintf( 'wp.apiFetch.use( wp.apiFetch.createPreloadingMiddleware( %s ) );', wp_json_encode( $preload ) ),
			'after'
		);
	}

	/**
	 * The page: a root for the app, and a message when scripts do not run.
	 */
	public function render(): void {
		echo '<div id="showfm-settings" class="showfm-settings"><noscript><div class="wrap"><h1>' . esc_html__( 'show.fm', 'showfm' ) . '</h1><p>' . esc_html__( 'The show.fm settings need JavaScript.', 'showfm' ) . '</p></div></noscript></div>';
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
