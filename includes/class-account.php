<?php
/**
 * The connected account's name and shows, kept for the settings screen.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The show.fm API has no keyed route for a site's details, so the plugin asks `GET /v1/me` for the
 * account holder's name and `GET /v1/me/podcasts` for the shows the site key can read. It
 * does so right after connecting and with the daily health report, never while a page
 * renders. The answer is stored in one option (autoload off) for the settings screen.
 */
final class Account {

	/** Option holding the account details (autoload off). */
	const OPTION = 'showfm_account';

	/** Most shows kept. A site key covers the shows the admin chose when connecting. */
	const MAX_SHOWS = 50;

	/** Longest name or title kept. */
	const MAX_TEXT = 200;

	/**
	 * Connection store.
	 *
	 * @var Connection
	 */
	private $connection;

	/**
	 * API client.
	 *
	 * @var Api_Client
	 */
	private $api_client;

	/**
	 * Builds the store.
	 *
	 * @param Connection $connection Connection store.
	 * @param Api_Client $api_client API client.
	 */
	public function __construct( Connection $connection, Api_Client $api_client ) {
		$this->connection = $connection;
		$this->api_client = $api_client;
	}

	/**
	 * Fetches and stores the account name and shows. Keeps the stored copy when either call
	 * fails, so a brief outage does not empty the screen.
	 */
	public function refresh(): bool {
		$site_id = $this->connection->site_id();
		if ( null === $site_id || ! $this->connection->is_connected() ) {
			return false;
		}

		$me = $this->api_client->get_keyed( '/v1/me' );
		if ( ! $me->is( Api_Result::SUCCESS ) ) {
			return false;
		}
		$podcasts = $this->api_client->get_keyed( '/v1/me/podcasts?limit=' . self::MAX_SHOWS );
		if ( ! $podcasts->is( Api_Result::SUCCESS ) ) {
			return false;
		}

		$me_data   = $me->data();
		$user      = is_array( $me_data ) && is_array( $me_data['data']['user'] ?? null ) ? $me_data['data']['user'] : array();
		$list_data = $podcasts->data();
		$list      = is_array( $list_data ) && is_array( $list_data['data'] ?? null ) ? $list_data['data'] : array();

		$shows = array();
		foreach ( array_slice( $list, 0, self::MAX_SHOWS ) as $podcast ) {
			if ( ! is_array( $podcast ) || ! is_string( $podcast['id'] ?? null ) || ! preg_match( Connect::SITE_ID_PATTERN, strtolower( $podcast['id'] ) ) ) {
				continue;
			}
			$slug    = is_string( $podcast['slug'] ?? null ) && preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*\z/', $podcast['slug'] ) ? $podcast['slug'] : '';
			$shows[] = array(
				'id'    => strtolower( $podcast['id'] ),
				'title' => self::text( $podcast['title'] ?? null ),
				'slug'  => $slug,
			);
		}

		update_option(
			self::OPTION,
			array(
				'site'  => $site_id,
				'name'  => self::text( $user['name'] ?? null ),
				'shows' => $shows,
			),
			false
		);
		return true;
	}

	/**
	 * The stored details for the connected site, or empty ones.
	 *
	 * @return array{name:string,shows:array<int,array{id:string,title:string,slug:string}>}
	 */
	public function details(): array {
		$empty  = array(
			'name'  => '',
			'shows' => array(),
		);
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) || ( $stored['site'] ?? null ) !== $this->connection->site_id() ) {
			return $empty;
		}

		$shows = array();
		foreach ( is_array( $stored['shows'] ?? null ) ? $stored['shows'] : array() as $show ) {
			if ( is_array( $show ) && is_string( $show['id'] ?? null ) ) {
				$shows[] = array(
					'id'    => $show['id'],
					'title' => self::text( $show['title'] ?? null ),
					'slug'  => is_string( $show['slug'] ?? null ) ? $show['slug'] : '',
				);
			}
		}
		return array(
			'name'  => self::text( $stored['name'] ?? null ),
			'shows' => $shows,
		);
	}

	/**
	 * Plain text of at most 200 characters, or ''.
	 *
	 * @param mixed $value Value from the API.
	 */
	private static function text( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$text = trim( wp_strip_all_tags( $value ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::MAX_TEXT ) : substr( $text, 0, self::MAX_TEXT );
	}
}
