<?php
/**
 * REST routes for the Migrate tab.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `showfm/v1/admin/migrate`, for users who can manage options. In the browser the REST API's
 * cookie check needs the `wp_rest` nonce, which `@wordpress/api-fetch` sends: without it the
 * request runs as a logged-out user and is refused. No response carries the key, byte
 * offsets, provenance fingerprints or legacy audio URLs.
 *
 * - `GET admin/migrate`: the tab's view (see `Migration_Admin::view()`). Changes nothing.
 * - `POST admin/migrate/scan`: one bounded scan step. `restart` starts a new scan; `run` is
 *   the run the screen shows, so a step for a run that changed is refused with the fresh view.
 * - `POST admin/migrate/stop`: pauses the scan and gives the run back.
 * - `GET admin/migrate/rows`: a page of report rows for one group, or of the results.
 * - `POST admin/migrate/choice`: stores or clears a pick for one ambiguous embed. The episode
 *   must be one of that embed's stored candidates. Answers with the view and `choice`, the
 *   pick as stored. Refused once the swap has started.
 * - `POST admin/migrate/swap`: one bounded swap step of the reviewed run. `confirm` says the
 *   admin confirmed in the dialog; it is needed to start a swap, or to carry on one another
 *   admin started.
 *
 * Steps answer with the view. A refused step is an error with `data.reason` (see
 * `Migration_Admin::explain()`) and `data.view`, the fresh view to show. Only one admin can
 * run a scan or swap at a time: another admin's step is a 409 with reason `busy`.
 */
final class Migrate_Endpoint {

	/** Route base. */
	const ROUTE = '/admin/migrate';

	/** Matches a run or episode UUID, or nothing. */
	const UUID = '^(?:[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})?$';

	/** Matches a run UUID. */
	const RUN = '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$';

	/** Rows per page at most. */
	const MAX_ROWS = 100;

	/**
	 * Tab service.
	 *
	 * @var Migration_Admin
	 */
	private $admin;

	/**
	 * Builds the routes.
	 *
	 * @param Migration_Admin $admin Tab service.
	 */
	public function __construct( Migration_Admin $admin ) {
		$this->admin = $admin;
	}

	/**
	 * Registers the routes. Runs on `rest_api_init`.
	 */
	public function register(): void {
		$manage = array( Admin_Endpoint::class, 'can_manage' );
		$run    = array(
			'type'    => 'string',
			'pattern' => self::UUID,
			'default' => '',
		);
		$needed = array(
			'type'     => 'string',
			'pattern'  => self::RUN,
			'required' => true,
		);
		register_rest_route(
			Admin_Endpoint::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'read' ),
				'permission_callback' => $manage,
			)
		);
		register_rest_route(
			Admin_Endpoint::NAMESPACE,
			self::ROUTE . '/scan',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'scan' ),
				'permission_callback' => $manage,
				'args'                => array(
					'run'     => $run,
					'restart' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
		register_rest_route(
			Admin_Endpoint::NAMESPACE,
			self::ROUTE . '/stop',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'stop' ),
				'permission_callback' => $manage,
			)
		);
		register_rest_route(
			Admin_Endpoint::NAMESPACE,
			self::ROUTE . '/rows',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rows' ),
				'permission_callback' => $manage,
				'args'                => array(
					'run'    => $needed,
					'group'  => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array_merge( Migration_Admin::GROUPS, array( 'changed', 'failed' ) ),
					),
					'offset' => array(
						'type'    => 'integer',
						'minimum' => 0,
						'default' => 0,
					),
					'limit'  => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => self::MAX_ROWS,
						'default' => 25,
					),
				),
			)
		);
		register_rest_route(
			Admin_Endpoint::NAMESPACE,
			self::ROUTE . '/choice',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'choice' ),
				'permission_callback' => $manage,
				'args'                => array(
					'run'     => $needed,
					'post'    => array(
						'type'     => 'integer',
						'minimum'  => 1,
						'required' => true,
					),
					'embed'   => array(
						'type'     => 'integer',
						'minimum'  => 1,
						'required' => true,
					),
					'episode' => array(
						'type'     => 'string',
						'pattern'  => self::UUID,
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			Admin_Endpoint::NAMESPACE,
			self::ROUTE . '/swap',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'swap' ),
				'permission_callback' => $manage,
				'args'                => array(
					'run'     => $needed,
					'confirm' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
	}

	/**
	 * The tab's view.
	 */
	public function read(): \WP_REST_Response {
		return self::response( $this->admin->view() );
	}

	/**
	 * One scan step.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function scan( \WP_REST_Request $request ) {
		$running = Migration_Admin::running();
		return $this->answer( $this->admin->scan( (bool) $request['restart'], strtolower( (string) $request['run'] ) ), $running );
	}

	/**
	 * Pauses the scan.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function stop() {
		if ( ! Migration_Admin::stop() ) {
			return $this->refuse( new \WP_Error( 'showfm_busy', '' ), false );
		}
		return self::response( $this->admin->view() );
	}

	/**
	 * One swap step.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function swap( \WP_REST_Request $request ) {
		return $this->answer( $this->admin->swap( strtolower( (string) $request['run'] ), (bool) $request['confirm'] ), true );
	}

	/**
	 * Stores or clears one pick.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function choice( \WP_REST_Request $request ) {
		$stored = $this->admin->choose( strtolower( (string) $request['run'] ), (int) $request['post'], (int) $request['embed'], strtolower( (string) $request['episode'] ) );
		if ( is_wp_error( $stored ) ) {
			return $this->refuse( $stored, false );
		}
		// The pick as stored, so the tab shows what the swap will use.
		return self::response( array( 'choice' => $stored ) + $this->admin->view() );
	}

	/**
	 * A page of rows.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rows( \WP_REST_Request $request ) {
		$rows = $this->admin->rows( strtolower( (string) $request['run'] ), (string) $request['group'], (int) $request['offset'], (int) $request['limit'] );
		if ( is_wp_error( $rows ) ) {
			return $this->refuse( $rows, false );
		}
		return self::response( $rows );
	}

	/**
	 * The view after a step, or the step's error with the view.
	 *
	 * @param true|\WP_Error $result  Step result.
	 * @param bool           $running Whether a scan or swap was under way before the step.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function answer( $result, bool $running ) {
		if ( is_wp_error( $result ) ) {
			return $this->refuse( $result, $running );
		}
		return self::response( $this->admin->view() );
	}

	/**
	 * An explained error, carrying the fresh view.
	 *
	 * @param \WP_Error $error   Error.
	 * @param bool      $running Whether a scan or swap was under way.
	 */
	private function refuse( \WP_Error $error, bool $running ): \WP_Error {
		$error        = Migration_Admin::explain( $error, $running );
		$data         = (array) $error->get_error_data();
		$data['view'] = $this->admin->view();
		$error->add_data( $data );
		return $error;
	}

	/**
	 * A response no cache keeps.
	 *
	 * @param array<string,mixed> $data Body.
	 */
	private static function response( array $data ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}
}
