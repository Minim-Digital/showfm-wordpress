<?php
/**
 * REST routes for the settings screen.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `showfm/v1/admin/*`, for users who can manage options. In the browser the REST API's
 * cookie check needs the `wp_rest` nonce, which `@wordpress/api-fetch` sends: without it the
 * request runs as a logged-out user and is refused. No response carries the key, the ping
 * secret or a connect code.
 *
 * - `GET admin/connection`: the Connection tab's data. Reading changes nothing.
 * - `POST admin/connection/dismiss-result`: clears the current user's connect outcome.
 * - `POST admin/disconnect`: removes the local connection, then answers like the GET, with
 *   `disconnected` saying where to disconnect the site at show.fm. The show.fm API has no
 *   route for a site key to revoke itself, so the key stays valid there until then.
 * - `POST admin/notices/dismiss`: hides a notice instance for the current user.
 */
final class Admin_Endpoint {

	/** Route namespace. */
	const NAMESPACE = 'showfm/v1';

	/**
	 * Connect service.
	 *
	 * @var Connect
	 */
	private $connect;

	/**
	 * Connection tab data.
	 *
	 * @var Admin_Status
	 */
	private $status;

	/**
	 * Builds the routes.
	 *
	 * @param Connect      $connect Connect service.
	 * @param Admin_Status $status  Connection tab data.
	 */
	public function __construct( Connect $connect, Admin_Status $status ) {
		$this->connect = $connect;
		$this->status  = $status;
	}

	/**
	 * Registers the routes. Runs on `rest_api_init`.
	 */
	public function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/admin/connection',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'connection' ),
				'permission_callback' => array( self::class, 'can_manage' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/admin/connection/dismiss-result',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'dismiss_result' ),
				'permission_callback' => array( self::class, 'can_manage' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/admin/disconnect',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'disconnect' ),
				'permission_callback' => array( self::class, 'can_manage' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/admin/notices/dismiss',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'dismiss' ),
				'permission_callback' => array( self::class, 'can_manage' ),
				'args'                => array(
					'key' => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => trim( Notices::KEY_PATTERN, '/' ),
					),
				),
			)
		);
	}

	/**
	 * Only users who can manage options.
	 */
	public static function can_manage(): bool {
		return current_user_can( Admin::CAPABILITY );
	}

	/**
	 * The Connection tab's data.
	 */
	public function connection(): \WP_REST_Response {
		return self::private_response( $this->status->view( get_current_user_id() ) );
	}

	/**
	 * Clears the current user's connect outcome once they dismiss it.
	 */
	public static function dismiss_result(): \WP_REST_Response {
		Connect::clear_result( get_current_user_id() );
		return self::private_response( array( 'dismissed' => true ) );
	}

	/**
	 * Removes the local connection. The key stays valid at show.fm until the site is
	 * disconnected there, so the answer says where.
	 */
	public function disconnect(): \WP_REST_Response {
		$sites = $this->status->current_sites_url();
		$this->connect->disconnect();
		$view                 = $this->status->view( get_current_user_id() );
		$view['disconnected'] = array(
			'keyRevoked' => false,
			'sitesUrl'   => $sites,
		);
		return self::private_response( $view );
	}

	/**
	 * Hides a notice instance for the current user.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function dismiss( \WP_REST_Request $request ) {
		if ( ! Notices::dismiss( get_current_user_id(), (string) $request->get_param( 'key' ) ) ) {
			return new \WP_Error( 'showfm_bad_notice', __( 'That notice can’t be dismissed.', 'showfm' ), array( 'status' => 400 ) );
		}
		return self::private_response( array( 'dismissed' => true ) );
	}

	/**
	 * A response no cache keeps.
	 *
	 * @param array<string,mixed> $data Body.
	 */
	private static function private_response( array $data ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}
}
