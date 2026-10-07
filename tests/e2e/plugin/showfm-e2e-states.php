<?php
/**
 * Plugin Name: show.fm E2E states
 * Description: Test only. Puts the show.fm connection into each state the settings screen shows. Never ships.
 *
 * @package ShowFM
 */

use ShowFM\Account;
use ShowFM\Connect;
use ShowFM\Connection;
use ShowFM\Health;
use ShowFM\Notices;
use ShowFM\Ping_Endpoint;
use ShowFM\Plugin;
use ShowFM\Sync;

add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'showfm-e2e/v1',
			'/state',
			array(
				'methods'             => 'POST',
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
				'callback'            => 'showfm_e2e_set_state',
			)
		);
	}
);

/**
 * Sets a state: `not_connected`, `connected`, `expiring30`, `expiring7`, `expired`,
 * `refused`, `paused`, `scheduled`, `unreadable`, `sync_problem`, plus an optional connect
 * `result` (an error code, or `success`).
 *
 * @param WP_REST_Request $request Request.
 */
function showfm_e2e_set_state( WP_REST_Request $request ) {
	$state   = (string) $request->get_param( 'state' );
	$result  = (string) $request->get_param( 'result' );
	$user_id = get_current_user_id();
	$day     = DAY_IN_SECONDS;

	Plugin::connect()->disconnect();
	delete_user_meta( $user_id, Notices::META );
	delete_option( Health::LAST_SYNC_OPTION );
	delete_option( Sync::OPTION );
	delete_option( Connection::SYNC_STATUS_OPTION );
	delete_transient( Connect::RESULT_PREFIX . $user_id );

	if ( 'not_connected' !== $state ) {
		$expires = array(
			'expiring30' => time() + 30 * $day - HOUR_IN_SECONDS,
			'expiring7'  => time() + 7 * $day - HOUR_IN_SECONDS,
			'expired'    => time() - 3 * $day,
		);
		$site    = '0f8fad5b-d9cb-469f-a165-70867728950e';
		Plugin::connection()->save( 'showfm_live_e2eexamplekey7f3a', str_repeat( 'a', 43 ), $site, $expires[ $state ] ?? time() + 362 * $day );
		update_option( Connection::CONNECTED_AT_OPTION, time() - 30 * $day, false );
		update_option( Health::LAST_SYNC_OPTION, time() - 180, false );
		update_option(
			Account::OPTION,
			array(
				'site'  => $site,
				'name'  => 'Maya Lindgren',
				'shows' => array(
					array(
						'id'    => '7c9e6679-7425-40de-944b-e07fc1f90ae7',
						'title' => 'The Long Table',
						'slug'  => 'the-long-table',
					),
					array(
						'id'    => '9b2c4e1a-5f3d-4a8b-9c7e-1d2f3a4b5c6d',
						'title' => 'Second Helpings',
						'slug'  => 'second-helpings',
					),
				),
			),
			false
		);
	}

	switch ( $state ) {
		case 'refused':
			Plugin::connection()->mark_reconnect_needed();
			update_option( Connection::REFUSED_AT_OPTION, time() - $day, false );
			break;
		case 'paused':
			update_option( Connection::PAUSED_OPTION, time() - $day, false );
			break;
		case 'scheduled':
			update_option( Ping_Endpoint::MISSED_OPTION, time() - HOUR_IN_SECONDS, false );
			wp_clear_scheduled_hook( Sync::POLL_HOOK );
			wp_schedule_event( time() + 12 * MINUTE_IN_SECONDS - 30, 'showfm_quarter_hour', Sync::POLL_HOOK );
			break;
		case 'unreadable':
			update_option(
				Connection::OPTION,
				array(
					'v' => 1,
					'n' => base64_encode( str_repeat( 'n', 24 ) ),
					'c' => base64_encode( str_repeat( 'c', 64 ) ),
				),
				false
			);
			break;
		case 'sync_problem':
			Plugin::connection()->save_sync_status(
				array(
					'site'    => Plugin::connection()->site_id(),
					'retry'   => array(
						'seq'      => 4,
						'code'     => 'row_author',
						'attempts' => 0,
						'at'       => time(),
					),
					'skipped' => array(),
				)
			);
			break;
	}

	if ( '' !== $result ) {
		set_transient(
			Connect::RESULT_PREFIX . $user_id,
			array(
				'status'      => 'success' === $result ? Connect::STATUS_CONNECTED : Connect::STATUS_FAILED,
				'error'       => 'success' === $result ? '' : $result,
				'retry_after' => 0,
				'reason'      => '',
				'at'          => time(),
			),
			Connect::RESULT_TTL
		);
	}
	return array( 'state' => $state );
}
