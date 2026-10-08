<?php
/**
 * Plugin Name: Test fixtures for show.fm
 * Description: Test only. Answers show.fm API requests from fixtures and sets up a connection and a synced post for the Playwright tests. Never shipped.
 * Version: 1.0.0
 * License: GPL-2.0-or-later
 *
 * @package ShowFM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SHOWFM_E2E_PODCAST = '33333333-3333-4333-8333-333333333333';
const SHOWFM_E2E_EPISODE = '22222222-2222-4222-8222-222222222222';
const SHOWFM_E2E_KNIVES  = '55555555-5555-4555-8555-555555555555';
const SHOWFM_E2E_PUDDING = '66666666-6666-4666-8666-666666666666';
const SHOWFM_E2E_SITE    = '11111111-1111-4111-8111-111111111111';

/**
 * Fixture responses by API path.
 *
 * @return array<string,array{0:int,1:array<string,mixed>}>
 */
function showfm_e2e_responses(): array {
	$podcast   = array(
		'id'            => SHOWFM_E2E_PODCAST,
		'slug'          => 'the-long-table',
		'title'         => 'The Long Table',
		'artwork'       => array( 'url' => null ),
		'episode_count' => 2,
		'brand_color'   => '#C8553D',
		'links'         => array( 'listen' => 'https://the-long-table.show.fm' ),
		'branding'      => array( 'show_powered_by' => false ),
	);
	$episode   = static function ( string $id, string $slug, string $title, int $number ) use ( $podcast ): array {
		return array(
			'id'             => $id,
			'slug'           => $slug,
			'title'          => $title,
			'description'    => 'An episode of The Long Table.',
			'season_number'  => 2,
			'episode_number' => $number,
			'episode_type'   => 'full',
			'published_at'   => '2026-09-24T09:00:00.000Z',
			'audio'          => array(
				'url'              => 'https://m.cdn.media/e2e/' . $slug . '.mp3',
				'content_type'     => 'audio/mpeg',
				'duration_seconds' => 3120,
			),
			'artwork'        => array( 'url' => null ),
			'links'          => array( 'listen' => 'https://the-long-table.show.fm/e/' . $slug ),
			'transcript'     => 'sourdough' === $slug ? array(
				'url'  => 'https://m.cdn.media/e2e/sourdough.vtt',
				'type' => 'text/vtt',
			) : null,
			'podcast'        => $podcast,
		);
	};
	$sourdough = $episode( SHOWFM_E2E_EPISODE, 'sourdough', 'Sourdough, salt and the slow return of the village bakery', 4 );
	$knives    = $episode( SHOWFM_E2E_KNIVES, 'knives', 'Knives', 2 );
	$list      = array(
		'data'       => array( $sourdough, $knives ),
		'podcast'    => $podcast,
		'pagination' => array( 'next_cursor' => null ),
	);
	return array(
		'/v1/podcasts/the-long-table'                   => array( 200, array( 'data' => $podcast ) ),
		'/v1/podcasts/' . SHOWFM_E2E_PODCAST            => array( 200, array( 'data' => $podcast ) ),
		'/v1/podcasts/the-long-table/episodes?limit=50' => array( 200, $list ),
		'/v1/podcasts/' . SHOWFM_E2E_PODCAST . '/episodes?limit=50' => array( 200, $list ),
		'/v1/episodes/' . SHOWFM_E2E_EPISODE            => array( 200, array( 'data' => $sourdough ) ),
		'/v1/episodes/' . SHOWFM_E2E_KNIVES             => array( 200, array( 'data' => $knives ) ),
		'/v1/me/podcasts?limit=50'                      => array(
			200,
			array(
				'data' => array(
					array(
						'id'           => SHOWFM_E2E_PODCAST,
						'slug'         => 'the-long-table',
						'title'        => 'The Long Table',
						'hosting_type' => 'showfm',
					),
				),
			),
		),
		'/v1/me/podcasts/' . SHOWFM_E2E_PODCAST . '/episodes?limit=100' => array(
			200,
			array(
				'data' => array(
					array(
						'id'             => SHOWFM_E2E_PUDDING,
						'podcast_id'     => SHOWFM_E2E_PODCAST,
						'slug'           => 'pudding',
						'title'          => 'Bread and butter pudding',
						'status'         => 'scheduled',
						'scheduled_for'  => '2030-10-14T09:00:00.000Z',
						'season_number'  => 2,
						'episode_number' => 5,
					),
					array(
						'id'               => SHOWFM_E2E_EPISODE,
						'podcast_id'       => SHOWFM_E2E_PODCAST,
						'slug'             => 'sourdough',
						'title'            => 'Sourdough, salt and the slow return of the village bakery',
						'status'           => 'published',
						'published_at'     => '2026-09-24T09:00:00.000Z',
						'season_number'    => 2,
						'episode_number'   => 4,
						'duration_seconds' => 3120,
					),
				),
			),
		),
		'/v1/me/episodes/' . SHOWFM_E2E_PUDDING         => array(
			200,
			array(
				'data' => array(
					'id'             => SHOWFM_E2E_PUDDING,
					'podcast_id'     => SHOWFM_E2E_PODCAST,
					'slug'           => 'pudding',
					'title'          => 'Bread and butter pudding',
					'status'         => 'scheduled',
					'scheduled_for'  => '2030-10-14T09:00:00.000Z',
					'season_number'  => 2,
					'episode_number' => 5,
				),
			),
		),
	);
}

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		foreach ( array_unique( array( 'https://api.show.fm', \ShowFM\Api_Client::base_url() ) ) as $base ) {
			if ( 0 === strpos( $url, $base . '/' ) ) {
				$path      = substr( $url, strlen( $base ) );
				$responses = showfm_e2e_responses();
				// Any public list query (the cache refresh adds count, season and type).
				if ( preg_match( '~\A/v1/podcasts/([a-z0-9-]+)/episodes\?~', $path, $match ) ) {
					$path = '/v1/podcasts/' . $match[1] . '/episodes?limit=50';
				}
				list( $status, $body ) = $responses[ $path ] ?? array( 404, array( 'error' => array( 'code' => 'not_found' ) ) );
				return array(
					'headers'  => array( 'content-type' => 'application/json' ),
					'body'     => wp_json_encode( $body ),
					'response' => array(
						'code'    => $status,
						'message' => get_status_header_desc( $status ),
					),
					'cookies'  => array(),
					'filename' => null,
				);
			}
		}
		return $pre;
	},
	10,
	3
);

add_action(
	'rest_api_init',
	static function () {
		$admin = static function () {
			return current_user_can( 'manage_options' );
		};
		register_rest_route(
			'showfm-e2e/v1',
			'/connection',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'callback'            => static function ( WP_REST_Request $request ) {
					if ( $request['connected'] ) {
						\ShowFM\Plugin::connection()->save( 'showfm_live_E2Efixturekeyabcdefghijkl', str_repeat( 'e', 64 ), SHOWFM_E2E_SITE, 0 );
					} else {
						\ShowFM\Plugin::connection()->disconnect();
					}
					return array( 'connected' => \ShowFM\Plugin::connection()->is_connected() );
				},
			)
		);
		register_rest_route(
			'showfm-e2e/v1',
			'/reset',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'callback'            => static function () {
					\ShowFM\Plugin::cache()->flush();
					delete_transient( \ShowFM\Editor_Api::HOLD );
					return array( 'reset' => true );
				},
			)
		);
		register_rest_route(
			'showfm-e2e/v1',
			'/synced-post',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'callback'            => static function ( WP_REST_Request $request ) {
					$id = wp_insert_post(
						array(
							'post_title'   => 'Sourdough, salt and the slow return of the village bakery',
							'post_status'  => 'publish',
							'post_content' => '<!-- wp:paragraph --><p>Synced.</p><!-- /wp:paragraph -->',
						)
					);
					update_post_meta( $id, '_showfm_episode_id', SHOWFM_E2E_EPISODE );
					update_post_meta( $id, '_showfm_site_id', SHOWFM_E2E_SITE );
					update_post_meta( $id, '_showfm_sync_state', (string) $request['state'] );
					update_post_meta( $id, '_showfm_synced_at', time() );
					if ( $request['edited'] ) {
						update_post_meta( $id, '_showfm_edited', 1 );
					}
					return array( 'id' => $id );
				},
			)
		);
	}
);
