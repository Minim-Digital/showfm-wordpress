<?php
/**
 * Cache-only rendering shared by blocks and the shortcode.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Emits custom elements with escaped, readable light-DOM fallbacks. */
final class Embed {
	/**
	 * The cache path for the exact element selection.
	 *
	 * @param string               $type Element type.
	 * @param array<string,string> $attrs Validated attributes.
	 */
	public static function path( string $type, array $attrs ): string {
		if ( 'episodes' !== $type && ! empty( $attrs['episode'] ) ) {
			return '/v1/episodes/' . $attrs['episode'];
		}
		if ( empty( $attrs['podcast'] ) ) {
			return '';
		}
		$path = '/v1/podcasts/' . $attrs['podcast'] . '/episodes';
		if ( 'episodes' !== $type ) {
			return $path . '/latest';
		}
		$query = array( 'limit' => $attrs['count'] ?? '10' );
		if ( isset( $attrs['season'] ) ) {
			$query['season'] = $attrs['season'];
		}
		if ( ! empty( $attrs['hide'] ) ) {
			$query['type'] = implode( ',', array_diff( array( 'full', 'trailer', 'bonus' ), explode( ',', $attrs['hide'] ) ) );
		}
		return $path . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Render without ever fetching. Null/failed cache uses the insertion snapshot.
	 *
	 * @param string              $type Element type.
	 * @param array<string,mixed> $input Block or shortcode attributes.
	 * @param bool                $block Whether this is a block.
	 */
	public static function render( string $type, array $input, bool $block = false ): string {
		if ( ! in_array( $type, array( 'player', 'episodes', 'play', 'transcript' ), true ) ) {
			return '';
		}
		$attrs = Attributes::clean( $type, $input, $block );
		$path  = self::path( $type, $attrs );
		if ( '' === $path ) {
			return '';
		}
		$cache = Plugin::cache();
		$data  = $cache->get( $path );
		if ( $cache->is_unavailable( $path ) ) {
			return '';
		}
		$snapshot = isset( $input['snapshot'] ) && is_array( $input['snapshot'] ) ? Attributes::snapshot( $input['snapshot'] ) : array();
		$fallback = array(
			'title' => is_string( $snapshot['title'] ?? null ) ? $snapshot['title'] : '',
			'links' => array( 'listen' => $snapshot['listenUrl'] ?? null ),
			'audio' => array( 'url' => $snapshot['audioUrl'] ?? null ),
		);
		$public   = is_array( $data ) && isset( $data['data'] ) && is_array( $data['data'] );
		$episode  = $public ? $data['data'] : $fallback;
		$json     = '';
		if ( 'episodes' === $type ) {
			$episodes = $public ? $data['data'] : array();
			// Refresh stale markers; only newer, fresh public data can supersede them.
			$episodes = array_values(
				array_filter(
					$episodes,
					static function ( $item ) use ( $cache, $path ) {
						return is_array( $item ) && ! $cache->is_episode_unavailable( $item['id'] ?? '', $path );
					}
				)
			);
			$html     = Fallback::episode_list( $data['podcast'] ?? $fallback, $episodes, array( 'limit' => (int) ( $attrs['count'] ?? 10 ) ) );
		} else {
			if ( isset( $episode['id'] ) && $cache->is_episode_unavailable( $episode['id'], $path ) ) {
				return '';
			}
			// A standalone transcript links to the listen page; it never fetches VTT in PHP.
			$html = 'transcript' === $type ? Fallback::episode_list( $episode, array() ) : Fallback::episode( $episode );
			if ( $public && get_option( 'showfm_json_ld', true ) ) {
				$json = '<script type="application/ld+json">' . Fallback::json_ld( $episode ) . '</script>';
			}
		}
		$element = '<showfm-' . $type;
		foreach ( $attrs as $name => $value ) {
			$element .= ' ' . $name . '="' . esc_attr( $value ) . '"';
		}
		$style = Theme::block_style( $input );
		if ( '' !== $style ) {
			$element .= ' style="' . esc_attr( $style ) . '"';
		}
		Assets::enqueue();
		$element .= '>' . $html . '</showfm-' . $type . '>' . $json;
		return $block ? '<div ' . get_block_wrapper_attributes() . '>' . $element . '</div>' : $element;
	}
}
