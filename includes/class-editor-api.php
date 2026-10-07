<?php
/**
 * REST proxy the block editor reads shows and episodes through.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only routes under `showfm/v1/editor/` for people who can edit posts.
 *
 * - Unconnected sites read the public API. Connected sites also read the keyed API, so the
 *   editor sees the account's shows and scheduled episodes. The site key stays on the server:
 *   responses carry only the normalised fields below, never a key, secret or raw body.
 * - Requests need `edit_posts` and, from the browser, the `wp_rest` nonce (core cookie auth).
 * - Answers are cached in transients: 5 minutes for data, 1 minute for "not there".
 *   Errors are not cached. A 429 from the public API holds every editor call until its
 *   Retry-After has passed; keyed calls already wait in `Api_Client`. A 401 on a keyed
 *   call marks the connection for reconnecting, and the editor falls back to public data.
 * - Every answer is a 200 with a `state`, so the editor shows the matching message.
 */
final class Editor_Api {

	/** REST namespace. */
	const NAMESPACE = 'showfm/v1';

	/** Seconds a successful answer is reused. */
	const OK_TTL = 300;

	/** Seconds a 403 or 404 is reused. */
	const MISS_TTL = 60;

	/** Transient holding the time until which public editor calls wait after a 429. */
	const HOLD = 'showfm_editor_hold';

	/** Most shows enriched with public details in one answer. */
	const MAX_SHOWS = 20;

	/** Registers the routes on `rest_api_init`. */
	public static function register(): void {
		$routes = array(
			'shows'    => array(),
			'show'     => array( 'ref' => array( self::class, 'is_ref' ) ),
			'episodes' => array( 'podcast' => array( self::class, 'is_ref' ) ),
			'episode'  => array(
				'id'      => array( self::class, 'is_uuid' ),
				'podcast' => array( self::class, 'is_ref' ),
			),
		);
		foreach ( $routes as $route => $params ) {
			$args = array();
			foreach ( $params as $name => $validate ) {
				$args[ $name ] = array(
					'type'              => 'string',
					'required'          => 'podcast' !== $name || 'episode' !== $route,
					'validate_callback' => $validate,
				);
			}
			register_rest_route(
				self::NAMESPACE,
				'/editor/' . $route,
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, $route ),
					'permission_callback' => array( self::class, 'can_edit' ),
					'args'                => $args,
				)
			);
		}
	}

	/** Only people who can write posts may look shows up through the site. */
	public static function can_edit(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * A podcast slug or UUID.
	 *
	 * @param mixed $value Parameter.
	 */
	public static function is_ref( $value ): bool {
		return is_string( $value ) && ( '' !== Attributes::uuid( $value ) || 1 === preg_match( '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $value ) ) && strlen( $value ) <= 100;
	}

	/**
	 * An episode UUID.
	 *
	 * @param mixed $value Parameter.
	 */
	public static function is_uuid( $value ): bool {
		return '' !== Attributes::uuid( $value );
	}

	/**
	 * Settings the editor script starts with. Nothing secret: no key, no site id.
	 *
	 * @return array<string,mixed>
	 */
	public static function settings(): array {
		$state = Plugin::connection()->state();
		return array(
			'connected'  => Connection::STATE_CONNECTED === $state,
			'reconnect'  => Connection::STATE_RECONNECT_NEEDED === $state,
			'canConnect' => current_user_can( Admin::CAPABILITY ),
			'connectUrl' => admin_url( 'admin.php?page=' . Connect::PAGE ),
			'appUrl'     => Connect::app_url(),
			'api'        => Api_Client::base_url(),
		);
	}

	/**
	 * The connected account's shows, or none when the site is not connected.
	 *
	 * @return array<string,mixed>
	 */
	public static function shows(): array {
		$keyed = self::keyed_shows();
		if ( null === $keyed['shows'] ) {
			return array(
				'state'     => $keyed['state'],
				'connected' => $keyed['connected'],
				'shows'     => array(),
			);
		}
		$shows = array();
		foreach ( array_slice( $keyed['shows'], 0, self::MAX_SHOWS ) as $show ) {
			if ( 'external' !== $show['hosting'] ) {
				$public = self::fetch( '/v1/podcasts/' . $show['id'], false );
				if ( Api_Result::SUCCESS === $public['type'] && is_array( $public['data']['data'] ?? null ) ) {
					$show = array_merge( self::show_payload( $public['data']['data'] ), array( 'hosting' => $show['hosting'] ) );
				}
			}
			$shows[] = $show;
		}
		return array(
			'state'     => 'ok',
			'connected' => true,
			'shows'     => $shows,
		);
	}

	/**
	 * One show by address or slug.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>
	 */
	public static function show( \WP_REST_Request $request ): array {
		$ref  = strtolower( (string) $request['ref'] );
		$mine = self::my_show( $ref );
		if ( null !== $mine && 'external' === $mine['hosting'] ) {
			return array(
				'state' => 'external',
				'show'  => $mine,
			);
		}
		$result = self::fetch( '/v1/podcasts/' . rawurlencode( $ref ), false );
		if ( Api_Result::SUCCESS === $result['type'] && is_array( $result['data']['data'] ?? null ) ) {
			return array(
				'state' => 'ok',
				'show'  => self::show_payload( $result['data']['data'] ),
			);
		}
		return self::failure( $result );
	}

	/**
	 * A show's episodes: scheduled and published from the keyed API when the show is the
	 * connected account's, otherwise the public first page.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>
	 */
	public static function episodes( \WP_REST_Request $request ): array {
		$ref  = strtolower( (string) $request['podcast'] );
		$mine = self::my_show( $ref );
		if ( null !== $mine && 'external' === $mine['hosting'] ) {
			return array(
				'state'    => 'external',
				'episodes' => array(),
			);
		}
		if ( null !== $mine ) {
			$result = self::fetch( '/v1/me/podcasts/' . $mine['id'] . '/episodes?limit=100', true );
			if ( Api_Result::SUCCESS === $result['type'] && is_array( $result['data']['data'] ?? null ) ) {
				$episodes = array();
				foreach ( $result['data']['data'] as $item ) {
					if ( is_array( $item ) && in_array( $item['status'] ?? '', array( 'scheduled', 'published' ), true ) ) {
						$episodes[] = self::episode_item( $item, true );
					}
				}
				return array(
					'state'    => 'ok',
					'keyed'    => true,
					'episodes' => $episodes,
				);
			}
		}
		$result = self::fetch( '/v1/podcasts/' . rawurlencode( $ref ) . '/episodes?limit=50', false );
		if ( Api_Result::SUCCESS === $result['type'] && is_array( $result['data']['data'] ?? null ) ) {
			return array(
				'state'    => 'ok',
				'keyed'    => false,
				'episodes' => array_values(
					array_map(
						static function ( array $item ): array {
							return self::episode_item( $item );
						},
						array_filter( $result['data']['data'], 'is_array' )
					)
				),
			);
		}
		return self::failure( $result );
	}

	/**
	 * One episode and the state the editor should show for it.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>
	 */
	public static function episode( \WP_REST_Request $request ): array {
		$id     = Attributes::uuid( $request['id'] );
		$result = self::fetch( '/v1/episodes/' . $id, false );
		if ( Api_Result::SUCCESS === $result['type'] && is_array( $result['data']['data'] ?? null ) ) {
			$episode = self::episode_payload( $result['data']['data'] );
			$mine    = self::my_show( (string) ( $episode['podcast']['id'] ?? '' ) );
			if ( null !== $mine && '' !== $episode['slug'] ) {
				$episode['appUrl'] = Connect::app_url() . '/p/' . rawurlencode( $mine['slug'] ) . '/e/' . rawurlencode( $episode['slug'] );
			}
			return array(
				'state'   => 'ok',
				'episode' => $episode,
			);
		}
		if ( Api_Result::UNAVAILABLE !== $result['type'] || 404 !== $result['status'] ) {
			return self::failure( $result );
		}

		// Only a keyed read can tell a scheduled episode from a deleted one.
		$podcast = is_string( $request['podcast'] ) ? strtolower( $request['podcast'] ) : '';
		$mine    = '' === $podcast ? null : self::my_show( $podcast );
		if ( null === $mine ) {
			return array( 'state' => Plugin::connection()->is_connected() ? 'not_found' : 'not_public' );
		}
		$keyed = self::fetch( '/v1/me/episodes/' . $id, true );
		if ( Api_Result::UNAVAILABLE === $keyed['type'] ) {
			return array( 'state' => 'deleted' );
		}
		if ( Api_Result::SUCCESS !== $keyed['type'] || ! is_array( $keyed['data']['data'] ?? null ) ) {
			return array( 'state' => 'not_public' );
		}
		$episode            = self::episode_item( $keyed['data']['data'], true );
		$episode['podcast'] = array(
			'id'    => $mine['id'],
			'title' => $mine['title'],
		);
		if ( '' !== $episode['slug'] ) {
			$episode['appUrl'] = Connect::app_url() . '/p/' . rawurlencode( $mine['slug'] ) . '/e/' . rawurlencode( $episode['slug'] );
		}
		$status = $keyed['data']['data']['status'] ?? '';
		if ( $episode['scheduled'] ) {
			$state = 'scheduled';
		} elseif ( 'archived' === $status ) {
			$state = 'archived';
		} elseif ( 'published' === $status ) {
			// Published but not public yet: the public cache has not caught up.
			$state = 'not_public';
		} else {
			$state = 'unpublished';
		}
		return array(
			'state'   => $state,
			'episode' => $episode,
		);
	}

	/**
	 * The connected account's shows from the keyed API, or null with the reason.
	 *
	 * @return array{state:string,connected:bool,shows:array<int,array<string,mixed>>|null}
	 */
	private static function keyed_shows(): array {
		if ( ! Plugin::connection()->is_connected() ) {
			return array(
				'state'     => 'ok',
				'connected' => false,
				'shows'     => null,
			);
		}
		$result = self::fetch( '/v1/me/podcasts?limit=50', true );
		if ( Api_Result::SUCCESS !== $result['type'] || ! is_array( $result['data']['data'] ?? null ) ) {
			$connected = Api_Result::UNAUTHORISED !== $result['type'];
			return array(
				'state'     => $connected ? self::failure( $result )['state'] : 'ok',
				'connected' => $connected,
				'shows'     => null,
			);
		}
		$shows = array();
		foreach ( $result['data']['data'] as $item ) {
			if ( is_array( $item ) && '' !== Attributes::uuid( $item['id'] ?? '' ) ) {
				$shows[] = array(
					'id'       => Attributes::uuid( $item['id'] ),
					'slug'     => self::text( $item['slug'] ?? '' ),
					'title'    => self::text( $item['title'] ?? '' ),
					'hosting'  => 'external' === ( $item['hosting_type'] ?? '' ) ? 'external' : 'showfm',
					'artwork'  => null,
					'episodes' => null,
					'listen'   => null,
				);
			}
		}
		return array(
			'state'     => 'ok',
			'connected' => true,
			'shows'     => $shows,
		);
	}

	/**
	 * The connected account's show with this id or slug, if any.
	 *
	 * @param string $ref Podcast UUID or slug.
	 * @return array<string,mixed>|null
	 */
	private static function my_show( string $ref ): ?array {
		if ( '' === $ref ) {
			return null;
		}
		$keyed = self::keyed_shows();
		foreach ( $keyed['shows'] ?? array() as $show ) {
			if ( $ref === $show['id'] || $ref === $show['slug'] ) {
				return $show;
			}
		}
		return null;
	}

	/**
	 * One API read through the editor cache.
	 *
	 * @param string $path  API path.
	 * @param bool   $keyed Whether to send the site key.
	 * @return array{type:string,status:int,data:mixed,retry:int}
	 */
	private static function fetch( string $path, bool $keyed ): array {
		$scope = $keyed ? 'keyed:' . Plugin::connection()->site_id() : 'public';
		$key   = Cache::key( 'editor:' . $scope . ':' . $path );
		$hit   = get_transient( $key );
		if ( is_array( $hit ) && isset( $hit['type'], $hit['status'], $hit['retry'] ) && array_key_exists( 'data', $hit ) ) {
			return $hit;
		}
		$hold = (int) get_transient( self::HOLD );
		if ( ! $keyed && $hold > time() ) {
			return array(
				'type'   => Api_Result::RATE_LIMITED,
				'status' => 0,
				'data'   => null,
				'retry'  => $hold - time(),
			);
		}
		$client = Plugin::api_client();
		$result = $keyed ? $client->get_keyed( $path ) : $client->get( $path );
		$answer = array(
			'type'   => $result->type(),
			'status' => $result->status(),
			'data'   => $result->data(),
			'retry'  => $result->retry_after(),
		);
		if ( Api_Result::SUCCESS === $answer['type'] ) {
			set_transient( $key, $answer, self::OK_TTL );
		} elseif ( Api_Result::UNAVAILABLE === $answer['type'] ) {
			set_transient( $key, $answer, self::MISS_TTL );
		} elseif ( Api_Result::RATE_LIMITED === $answer['type'] && ! $keyed ) {
			set_transient( self::HOLD, time() + $answer['retry'], $answer['retry'] );
		}
		return $answer;
	}

	/**
	 * The editor state for an answer that carried no data.
	 *
	 * @param array{type:string,status:int,data:mixed,retry:int} $result Answer.
	 * @return array<string,mixed>
	 */
	private static function failure( array $result ): array {
		if ( Api_Result::UNAVAILABLE === $result['type'] ) {
			return array( 'state' => 403 === $result['status'] ? 'paused' : 'not_found' );
		}
		if ( Api_Result::RATE_LIMITED === $result['type'] ) {
			return array(
				'state'      => 'rate_limited',
				'retryAfter' => $result['retry'],
			);
		}
		return array( 'state' => 'error' );
	}

	/**
	 * Public show fields the editor uses.
	 *
	 * @param array<string,mixed> $show Public podcast payload.
	 * @return array<string,mixed>
	 */
	private static function show_payload( array $show ): array {
		return array(
			'id'       => Attributes::uuid( $show['id'] ?? '' ),
			'slug'     => self::text( $show['slug'] ?? '' ),
			'title'    => self::text( $show['title'] ?? '' ),
			'hosting'  => 'showfm',
			'artwork'  => self::url( $show['artwork']['url'] ?? null ),
			'episodes' => is_int( $show['episode_count'] ?? null ) ? $show['episode_count'] : null,
			'listen'   => self::url( $show['links']['listen'] ?? null ),
		);
	}

	/**
	 * Picker fields for one list item (public or keyed).
	 *
	 * @param array<string,mixed> $item  Episode list item.
	 * @param bool                $keyed Whether it came from the keyed API.
	 * @return array<string,mixed>
	 */
	private static function episode_item( array $item, bool $keyed = false ): array {
		$scheduled = $keyed && 'scheduled' === ( $item['status'] ?? '' );
		$date      = $scheduled ? ( $item['scheduled_for'] ?? null ) : ( $item['published_at'] ?? null );
		if ( $keyed && ! $scheduled && is_string( $date ) && strtotime( $date ) > time() ) {
			$scheduled = true;
		}
		$duration = $keyed ? ( $item['duration_seconds'] ?? null ) : ( $item['audio']['duration_seconds'] ?? null );
		return array(
			'id'        => Attributes::uuid( $item['id'] ?? '' ),
			'slug'      => self::text( $item['slug'] ?? '' ),
			'title'     => self::text( $item['title'] ?? '' ),
			'season'    => is_int( $item['season_number'] ?? null ) ? $item['season_number'] : null,
			'number'    => is_int( $item['episode_number'] ?? null ) ? $item['episode_number'] : null,
			'type'      => in_array( $item['episode_type'] ?? '', array( 'trailer', 'bonus' ), true ) ? $item['episode_type'] : 'full',
			'scheduled' => $scheduled,
			'date'      => is_string( $date ) && false !== strtotime( $date ) ? gmdate( 'c', (int) strtotime( $date ) ) : null,
			'duration'  => is_int( $duration ) ? $duration : null,
			'artwork'   => $keyed ? null : self::url( $item['artwork']['url'] ?? null ),
		);
	}

	/**
	 * Everything the block needs from a public episode, including its insert-time snapshot.
	 *
	 * @param array<string,mixed> $episode Public episode payload.
	 * @return array<string,mixed>
	 */
	private static function episode_payload( array $episode ): array {
		$podcast = is_array( $episode['podcast'] ?? null ) ? $episode['podcast'] : array();
		return array_merge(
			self::episode_item( $episode ),
			array(
				'listen'     => self::url( $episode['links']['listen'] ?? null ),
				'audio'      => self::url( $episode['audio']['url'] ?? null ),
				'transcript' => is_array( $episode['transcript'] ?? null ),
				'podcast'    => array(
					'id'     => Attributes::uuid( $podcast['id'] ?? '' ),
					'slug'   => self::text( $podcast['slug'] ?? '' ),
					'title'  => self::text( $podcast['title'] ?? '' ),
					'listen' => self::url( $podcast['links']['listen'] ?? null ),
				),
			)
		);
	}

	/**
	 * A plain string, or ''.
	 *
	 * @param mixed $value Value.
	 */
	private static function text( $value ): string {
		return is_string( $value ) ? wp_strip_all_tags( $value ) : '';
	}

	/**
	 * An https URL, or null. The snapshot keeps only these.
	 *
	 * @param mixed $value Value.
	 */
	private static function url( $value ): ?string {
		return is_string( $value ) && preg_match( '~\Ahttps://[^\s]+\z~i', $value ) && wp_parse_url( $value, PHP_URL_HOST ) ? esc_url_raw( $value ) : null;
	}
}
