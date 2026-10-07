<?php
/**
 * Offline embed detectors. Locations are byte offsets into the original content.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Recognises player syntax without running shortcodes, scripts, oEmbed or HTTP. */
final class Migration_Detectors {
	/**
	 * Detect embeds, then metadata-only players that have no content location.
	 *
	 * @param \WP_Post $post Published source post.
	 * @return array<int,array<string,mixed>>
	 */
	public static function detect( \WP_Post $post ): array {
		$content = $post->post_content;
		$items   = array();
		// Ignore literal examples and ordinary comments, including escaped shortcodes.
		$ignored = array();
		preg_match_all( '~<(pre|code)\b[^>]*>.*?</\1\s*>|<!--(?!\s*/?wp:).*?-->|\[\[.*?\]\]~is', $content, $matches, PREG_OFFSET_CAPTURE );
		foreach ( $matches[0] as $match ) {
			$ignored[] = array(
				'offset' => $match[1],
				'length' => strlen( $match[0] ),
			);
		}
		$comments = array();
		preg_match_all( '~<!--\s*/?wp:.*?-->~s', $content, $tokens, PREG_OFFSET_CAPTURE );
		foreach ( $tokens[0] as $token ) {
			$comments[] = array(
				'offset' => $token[1],
				'length' => strlen( $token[0] ),
			);
		}
		$patterns = array(
			'~<!-- wp:(?:seriously-simple-podcasting|ssp|castos)/[a-z-]+\b.*?(?:/-->|-->.*?<!-- /wp:(?:seriously-simple-podcasting|ssp|castos)/[a-z-]+\s*-->)~is',
			'~<div\b[^>]*\bid=["\x27]buzzsprout[^"\x27]*["\x27][^>]*>\s*</div>\s*<script\b[^>]*>.*?</script\s*>~is',
			'~<(iframe|script)\b[^>]*>.*?</\1\s*>~is',
			'~\[(buzzsprout|libsyn|libsyn_podcast|captivate|transistor|spotify|podbean|powerpress|ss_player|ss_podcast|podcast_episode|podcast_playlist|embed)\b[^\]]*\](?:[^\[]*\[/\1\])?~is',
			'~^[\t ]*(?:https?:)?//[^\s<>]+[\t ]*$~im',
		);
		foreach ( $patterns as $kind => $pattern ) {
			preg_match_all( $pattern, $content, $matches, PREG_OFFSET_CAPTURE );
			foreach ( $matches[0] as $match ) {
				$raw    = $match[0];
				$offset = $match[1];
				if ( self::overlaps( $offset, strlen( $raw ), array_merge( $ignored, $items, 0 === $kind ? array() : $comments ) ) ) {
					continue;
				}
				$item = self::identify( $raw, $post );
				if ( null === $item ) {
					continue;
				}
				$item['offset'] = $offset;
				$item['length'] = strlen( $raw );
				$item['source'] = 'content';
				// Consume a core wrapper only when it contains exactly this player.
				$before = substr( $content, 0, $offset );
				$after  = substr( $content, $offset + strlen( $raw ) );
				if ( preg_match( '~<!-- wp:(html|shortcode|embed|paragraph)\b[^>]*-->\s*(?:<[^>]+>\s*)*$~', $before, $open, PREG_OFFSET_CAPTURE ) && preg_match( '~^\s*(?:</[^>]+>\s*)*<!-- /wp:' . $open[1][0] . '\s*-->~', $after, $close ) ) {
					$item['offset'] = $open[0][1];
					$item['length'] = $offset + strlen( $raw ) + strlen( $close[0] ) - $item['offset'];
				}
				$items[] = $item;
			}
		}
		foreach ( array(
			'enclosure'  => 'powerpress',
			'audio_file' => 'ssp',
		) as $key => $host ) {
			foreach ( get_post_meta( $post->ID, $key, false ) as $value ) {
				$url = is_string( $value ) ? strtok( $value, "\r\n" ) : '';
				if ( ! is_string( $url ) || '' === Migration_Url::normalise( $url ) ) {
					continue;
				}
				$duplicate = false;
				foreach ( $items as $item ) {
					if ( Migration_Url::normalise( $item['audio_url'] ?? '' ) === Migration_Url::normalise( $url ) || ( $host === $item['host'] && 'content' === $item['source'] ) ) {
						$duplicate = true;
					}
				}
				if ( ! $duplicate ) {
					$items[] = array_merge(
						self::hints( $post ),
						array(
							'host'      => $host,
							'source'    => $key,
							'offset'    => strlen( $content ),
							'length'    => 0,
							'audio_url' => $url,
						)
					);
				}
			}
		}
		usort(
			$items,
			static function ( $a, $b ) {
				return $a['offset'] <=> $b['offset'];
			}
		);
		foreach ( $items as $index => &$item ) {
			$item['embed'] = $index + 1;
			if ( 1 === count( $items ) && empty( $item['show_only'] ) && empty( $item['no_post_hints'] ) ) {
				$item += self::hints( $post );
			}
		}
		return $items;
	}

	/**
	 * Identify one syntactic player.
	 *
	 * @param string   $raw  Original embed.
	 * @param \WP_Post $post Source post.
	 * @return array<string,mixed>|null
	 */
	private static function identify( string $raw, \WP_Post $post ): ?array {
		$attrs = array();
		if ( preg_match( '~^\[([a-z_]+)\b([^\]]*)\]~i', $raw, $shortcode ) ) {
			$attrs = shortcode_parse_atts( $shortcode[2] );
			$tag   = strtolower( $shortcode[1] );
			$tag   = 'libsyn_podcast' === $tag ? 'libsyn' : $tag;
		} else {
			$tag = '';
			preg_match_all( '~\b([a-z_-]+)\s*=\s*(["\x27])(.*?)\2~is', $raw, $attributes, PREG_SET_ORDER );
			foreach ( $attributes as $attr ) {
				$attrs[ strtolower( $attr[1] ) ] = html_entity_decode( $attr[3], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
		}
		if ( in_array( $tag, array( 'powerpress', 'ss_player', 'ss_podcast', 'podcast_episode', 'podcast_playlist' ), true ) || preg_match( '~^<!-- wp:(?:seriously-simple-podcasting|ssp|castos)/~', $raw ) ) {
			$host = 'powerpress' === $tag ? 'powerpress' : 'ssp';
			if ( preg_match( '~^<!-- wp:[^ ]+\s+(\{.*?\})\s*/?-->~s', $raw, $block ) ) {
				$decoded = json_decode( $block[1], true );
				$attrs   = is_array( $decoded ) ? $decoded : array();
			}
			// Collection blocks/shortcodes cannot safely become a single episode player.
			if ( in_array( $tag, array( 'ss_podcast', 'podcast_playlist' ), true ) || ( 'podcast_episode' === $tag && false === strpos( $attrs['content'] ?? 'title,player,details', 'player' ) ) || ( '' === $tag && ! preg_match( '~/(?:castos-player|castos-html-player|podcast-player|audio-player)\b~', $raw ) ) ) {
				return array(
					'host'      => $host,
					'show_only' => true,
				);
			}
			if ( 'powerpress' === $tag && empty( $attrs['url'] ) && ! in_array( $attrs['channel'] ?? $attrs['feed'] ?? 'podcast', array( '', 'podcast' ), true ) ) {
				return array(
					'host'      => $host,
					'show_only' => true,
					'show_id'   => $attrs['channel'] ?? $attrs['feed'],
				);
			}
			$id     = (int) ( $attrs['episodeId'] ?? $attrs['episode'] ?? $attrs['episode_id'] ?? $attrs['post_id'] ?? $attrs['id'] ?? $post->ID );
			$id     = 'ss_player' === $tag ? $post->ID : $id;
			$source = get_post( $id );
			$item   = array(
				'host'          => $host,
				'episode_id'    => (string) $id,
				'no_post_hints' => $id !== $post->ID,
			);
			if ( $source && 'publish' === $source->post_status && current_user_can( 'read_post', $source->ID ) ) {
				$item             += self::hints( $source );
				$meta              = get_post_meta( $id, 'powerpress' === $host ? 'enclosure' : 'audio_file', true );
				$item['audio_url'] = is_string( $meta ) ? (string) strtok( $meta, "\r\n" ) : '';
			}
			foreach ( array( 'url', 'src', 'audio_file', 'audioUrl', 'file' ) as $key ) {
				if ( 'ss_player' !== $tag && isset( $attrs[ $key ] ) && is_string( $attrs[ $key ] ) ) {
					$item['audio_url'] = $attrs[ $key ];
					unset( $item['title'], $item['published_at'] );
				}
			}
			return $item;
		}
		$url = $attrs['src'] ?? $attrs['url'] ?? '';
		if ( '' === $url && preg_match( '~(?:https?:)?//[^\s<>"\x27\[\]]+~i', $raw, $link ) ) {
			$url = $link[0];
		}
		$url    = html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$parts  = wp_parse_url( 0 === strpos( $url, '//' ) ? 'https:' . $url : $url );
		$domain = strtolower( $parts['host'] ?? '' );
		$host   = '';
		foreach ( array( 'buzzsprout', 'libsyn', 'captivate', 'transistor', 'spotify', 'podbean' ) as $provider ) {
			$tld = in_array( $provider, array( 'captivate', 'transistor' ), true ) ? 'fm' : 'com';
			if ( preg_match( '~(?:^|\.)' . $provider . '\.' . $tld . '$~', $domain ) || $tag === $provider ) {
				$host = $provider;
				break;
			}
		}
		if ( '' === $host ) {
			return null;
		}
		$item = array( 'host' => $host );
		if ( '' !== $tag && isset( $attrs['id'] ) ) {
			$item['episode_id'] = (string) $attrs['id'];
		}
		$path  = $parts['path'] ?? '';
		$query = array();
		parse_str( $parts['query'] ?? '', $query );
		$patterns = array(
			'buzzsprout' => '~/(?:\d+/episodes/|\d+/|episodes/)(\d+)(?:[./-]|$)~',
			'libsyn'     => '~/(?:embed/)?episode/id/(\d+)~',
			'captivate'  => '~/(?:episode/)?([a-f0-9-]{36})(?:/|$)~i',
			'transistor' => '~/(?:e|s)/([a-zA-Z0-9]+)(?:/|$)~',
			'spotify'    => '~/(?:embed(?:-podcast)?/)?episode/([a-zA-Z0-9]+)~',
			'podbean'    => '~/(?:e|ew|media/player)/([a-zA-Z0-9-]+)~',
		);
		if ( preg_match( $patterns[ $host ], $path, $id ) ) {
			$item['episode_id'] = $id[1];
		}
		foreach ( array( 'episode_id', 'episode', 'i', 'data-episode-id' ) as $key ) {
			$value = $attrs[ $key ] ?? $query[ $key ] ?? null;
			if ( is_string( $value ) && '' !== $value ) {
				$item['episode_id'] = $value;
			}
		}
		if ( 'podbean' === $host && isset( $attrs['resource'] ) ) {
			$resource = array();
			parse_str( $attrs['resource'], $resource );
			if ( is_string( $resource['episode'] ?? null ) ) {
				$item['episode_id'] = $resource['episode'];
			}
		}
		foreach ( array( 'show_id', 'podcast_id', 'podcast', 'show' ) as $key ) {
			if ( isset( $attrs[ $key ] ) && is_string( $attrs[ $key ] ) ) {
				$item['show_id'] = $attrs[ $key ];
			}
		}
		if ( 'buzzsprout' === $host && preg_match( '~^/(\d+)~', $path, $show ) ) {
			$item['show_id'] = $show[1];
		}
		if ( preg_match( '~/(?:show|podcast|playlist)/(?:id/)?([^/]+)~', $path, $show ) ) {
			$item['show_id'] = $show[1];
		}
		foreach ( array(
			'guid'               => 'guid',
			'data-guid'          => 'guid',
			'data-audio-url'     => 'audio_url',
			'audio_url'          => 'audio_url',
			'data-episode-title' => 'title',
			'data-title'         => 'title',
			'data-published-at'  => 'published_at',
		) as $key => $field ) {
			if ( isset( $attrs[ $key ] ) && is_string( $attrs[ $key ] ) ) {
				$item[ $field ] = $attrs[ $key ];
			}
		}
		if ( ! isset( $item['episode_id'] ) || preg_match( '~/(?:show|playlist)(?:/|$)~', $path ) ) {
			$item['show_only'] = true;
		}
		return $item;
	}

	/**
	 * Local episode hints, not provider identifiers.
	 *
	 * @param \WP_Post $post Episode post.
	 * @return array<string,string>
	 */
	private static function hints( \WP_Post $post ): array {
		return array(
			'title'        => $post->post_title,
			'published_at' => (string) get_post_time( 'c', true, $post ),
		);
	}

	/**
	 * Avoid duplicate detection inside a larger player or literal example.
	 *
	 * @param int                            $offset Start byte.
	 * @param int                            $length Byte length.
	 * @param array<int,array<string,mixed>> $items  Occupied ranges.
	 */
	private static function overlaps( int $offset, int $length, array $items ): bool {
		foreach ( $items as $item ) {
			if ( $offset < $item['offset'] + $item['length'] && $offset + $length > $item['offset'] ) {
				return true;
			}
		}
		return false;
	}
}
