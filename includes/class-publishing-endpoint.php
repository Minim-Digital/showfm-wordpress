<?php
/**
 * REST routes for the Publishing tab.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `showfm/v1/admin/publishing`, for users who can manage options. In the browser the REST
 * API's cookie check needs the `wp_rest` nonce, which `@wordpress/api-fetch` sends: without
 * it the request runs as a logged-out user and is refused.
 *
 * - `GET`: the settings, the choices for each field, the connected shows, any sync
 *   configuration problem and the recent activity. Reading changes nothing.
 * - `POST`: checks and stores the settings, then answers like the GET with `saved: true`.
 *   A refused setting is a 400 naming the field (`data.field`). Saving changes new posts
 *   only. When the sync was stuck on the post type or author, it tries again straight away.
 */
final class Publishing_Endpoint {

	/** Route. */
	const ROUTE = '/admin/publishing';

	/** Sync problems the Publishing tab can fix, with the field to change. */
	const FIXABLE = array(
		'row_post_type' => 'postType',
		'row_author'    => 'author',
	);

	/**
	 * Connection store.
	 *
	 * @var Connection
	 */
	private $connection;

	/**
	 * Builds the routes.
	 *
	 * @param Connection $connection Connection store.
	 */
	public function __construct( Connection $connection ) {
		$this->connection = $connection;
	}

	/**
	 * Registers the routes. Runs on `rest_api_init`.
	 */
	public function register(): void {
		register_rest_route(
			Admin_Endpoint::NAMESPACE,
			self::ROUTE,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'read' ),
					'permission_callback' => array( Admin_Endpoint::class, 'can_manage' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save' ),
					'permission_callback' => array( Admin_Endpoint::class, 'can_manage' ),
					'args'                => self::args(),
				),
			)
		);
	}

	/**
	 * The POST fields. Types are checked and values sanitised by the REST API before the
	 * callback runs; `Publishing::validate()` then checks them against the site.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function args(): array {
		return array(
			'autoPost'      => array( 'type' => 'boolean' ),
			'postType'      => array(
				'type'              => 'string',
				'maxLength'         => 20,
				'sanitize_callback' => 'sanitize_key',
			),
			'category'      => array(
				'type'    => 'integer',
				'minimum' => 0,
			),
			'author'        => array(
				'type'    => 'integer',
				'minimum' => 0,
			),
			'template'      => array(
				'type'              => 'string',
				'maxLength'         => 200,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'transcript'    => array( 'type' => 'boolean' ),
			'featuredImage' => array( 'type' => 'boolean' ),
		);
	}

	/**
	 * The Publishing tab's data.
	 */
	public function read(): \WP_REST_Response {
		Account::ask_if_unknown( $this->connection->pinned() );
		return self::response( $this->view() );
	}

	/**
	 * Stores the settings.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save( \WP_REST_Request $request ) {
		$names = array(
			'autoPost'      => 'auto_post',
			'postType'      => 'post_type',
			'category'      => 'category',
			'author'        => 'author',
			'template'      => 'template',
			'transcript'    => 'transcript',
			'featuredImage' => 'featured_image',
		);
		$input = array();
		foreach ( $names as $param => $setting ) {
			if ( null !== $request->get_param( $param ) ) {
				$input[ $setting ] = $request->get_param( $param );
			}
		}
		$stuck = isset( self::FIXABLE[ Health::sync_problem( $this->connection->pinned() ) ] );
		$saved = Publishing::save( $input );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		$view = $this->view();
		if ( $stuck ) {
			// The held row is tried again with the new settings, now rather than after the
			// back-off. If it still fails, the problem shows again after the next pull.
			Sync::retry_now();
			$view['problem'] = null;
		}
		$view['saved'] = true;
		return self::response( $view );
	}

	/**
	 * Everything the tab shows, from one read of the connection.
	 *
	 * @return array<string,mixed>
	 */
	public function view(): array {
		$pinned   = $this->connection->pinned();
		$settings = Publishing::settings();
		$types    = Publishing::post_types();
		$authors  = array();
		$choices  = array();
		foreach ( $types as $type ) {
			$authors[ $type['value'] ] = Publishing::authors( $type['value'] );
			$choices[ $type['value'] ] = Publishing::templates( $type['value'] );
		}
		$shows = array();
		foreach ( Account::details_of( $pinned )['shows'] as $show ) {
			$shows[ $show['id'] ] = $show['title'];
		}
		// A stored author who can no longer publish shows as the default, as the select would.
		$author = Publishing::can_author( $settings['author'], $settings['post_type'] ) ? $settings['author'] : Publishing::default_author( $settings['post_type'] );
		$listed = $authors[ $settings['post_type'] ] ?? null;
		if ( null !== $listed && $author > 0 && ! in_array( $author, wp_list_pluck( $listed, 'value' ), true ) ) {
			// Valid but beyond the first MAX_AUTHORS names: offer them too.
			$user = get_userdata( $author );
			if ( false !== $user ) {
				$authors[ $settings['post_type'] ][] = array(
					'value' => $author,
					'label' => $user->display_name,
				);
			}
		}

		$approved = Account::details_of( $pinned )['transcripts'];
		return array(
			// How the connection was approved: "Include transcripts in posts" on or off, or
			// unknown until show.fm tells this site (connections made before 1.0.1).
			'transcriptApproval' => null === $approved ? 'unknown' : ( $approved ? 'on' : 'off' ),
			'settings'           => array(
				'autoPost'      => $settings['auto_post'],
				'postType'      => $settings['post_type'],
				'category'      => $settings['category'],
				'author'        => $author,
				'template'      => $settings['template'],
				'transcript'    => $settings['transcript'],
				'featuredImage' => $settings['featured_image'],
			),
			'postTypes'          => $types,
			'categories'         => Publishing::categories(),
			'authors'            => $authors,
			'templates'          => $choices,
			'shows'              => array_values(
				array_filter(
					$shows,
					static function ( string $title ): bool {
						return '' !== $title;
					}
				)
			),
			'problem'            => self::problem( Health::sync_problem( $pinned ) ),
			'activity'           => Sync_Activity::view( $shows ),
		);
	}

	/**
	 * A sync configuration problem the tab can fix, or null.
	 *
	 * @param string $code `Health::sync_problem()` code.
	 * @return array{code:string,field:string,message:string}|null
	 */
	private static function problem( string $code ): ?array {
		if ( ! isset( self::FIXABLE[ $code ] ) ) {
			return null;
		}
		$messages = array(
			'row_post_type' => __( 'New episodes aren’t being posted here because the chosen post type isn’t available any more. Choose another post type, then save.', 'showfm' ),
			'row_author'    => __( 'New episodes aren’t being posted here because nobody on this site can publish the chosen post type. Choose another post type or author, then save.', 'showfm' ),
		);
		return array(
			'code'    => $code,
			'field'   => self::FIXABLE[ $code ],
			'message' => $messages[ $code ],
		);
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
