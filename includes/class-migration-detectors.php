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
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	public static function detect( \WP_Post $post ) {
		try {
			return self::read( $post );
		} catch ( \RuntimeException $error ) {
			return new \WP_Error( 'showfm_scan_error', __( 'The content could not be scanned safely. Review the malformed or oversized embed markup.', 'showfm' ) );
		}
	}

	/**
	 * Read bounded tokens without executing third-party content.
	 *
	 * @param \WP_Post $post Source post.
	 * @return array<int,array<string,mixed>>
	 */
	private static function read( \WP_Post $post ): array {
		$content = $post->post_content;
		$tokens  = Migration_Tokens::read( $content );
		$items   = array();
		foreach ( $tokens['ranges'] as $range ) {
			$raw  = substr( $content, $range['offset'], $range['length'] );
			$item = Migration_Tokens::match( '~^<!--\s+wp:showfm/~', $raw ) ? array(
				'host'    => 'showfm',
				'already' => true,
			) : self::identify( $raw, $post );
			if ( null !== $item ) {
				$items[] = $item + $range + array( 'source' => 'content' );
			}
		}
		foreach ( $tokens['wrappers'] as $wrapper ) {
			$inside = array();
			foreach ( $items as $index => $item ) {
				if ( $item['offset'] >= $wrapper['body'] && $item['offset'] + $item['length'] <= $wrapper['body_end'] ) {
					$inside[] = $index;
				}
			}
			if ( count( $inside ) > 1 ) {
				foreach ( $inside as $index ) {
					$items[ $index ]['range_ambiguous'] = true;
				}
			} elseif ( 1 === count( $inside ) ) {
				$index  = $inside[0];
				$item   = $items[ $index ];
				$before = substr( $content, $wrapper['body'], $item['offset'] - $wrapper['body'] );
				$after  = substr( $content, $item['offset'] + $item['length'], $wrapper['body_end'] - $item['offset'] - $item['length'] );
				if ( Migration_Tokens::match( '~\A(?:\s|<(?:div|figure|p|span)(?:\s[^<>]{0,8192})?>)*+\z~i', $before ) && Migration_Tokens::match( '~\A(?:\s|</(?:div|figure|p|span)\s*>)*+\z~i', $after ) ) {
					$items[ $index ]['offset'] = $wrapper['offset'];
					$items[ $index ]['length'] = $wrapper['end'] - $wrapper['offset'];
				}
			}
		}
		foreach ( array(
			'enclosure'  => 'powerpress',
			'audio_file' => 'ssp',
		) as $key => $host ) {
			$covered = Migration_Legacy::coverage( $post, $host );
			foreach ( Migration_Scanner::meta( $post->ID, $key ) as $value ) {
				$url    = is_string( $value ) ? (string) strtok( $value, "\r\n" ) : '';
				$normal = Migration_Url::normalise( $url );
				if ( null === $normal || in_array( Migration_Url::fingerprint( $normal ), $covered, true ) ) {
					continue;
				}
				$duplicate = false;
				foreach ( $items as $item ) {
					if ( Migration_Url::normalise( $item['audio_url'] ?? '' ) === $normal || ( $host === $item['host'] && 'content' === $item['source'] && empty( $item['no_post_hints'] ) ) ) {
						$duplicate = true;
					}
				}
				if ( ! $duplicate ) {
					$items[] = self::hints( $post ) + array(
						'host'      => $host,
						'source'    => $key,
						'offset'    => strlen( $content ),
						'length'    => 0,
						'audio_url' => $url,
					);
				}
			}
		}
		// Explicit tie-breaks preserve metadata order on PHP 7.4 as well as newer versions.
		foreach ( $items as $index => &$item ) {
			$item['ordinal'] = $index;
		}
		unset( $item );
		usort(
			$items,
			static function ( $a, $b ) {
				$order = $a['offset'] <=> $b['offset'];
				return 0 !== $order ? $order : $a['ordinal'] <=> $b['ordinal'];
			}
		);
		$legacy = count(
			array_filter(
				$items,
				static function ( $item ) {
					return empty( $item['already'] );
				}
			)
		);
		foreach ( $items as $index => &$item ) {
			$item['embed'] = $index + 1;
			unset( $item['ordinal'] );
			$item['range_hash'] = hash( 'sha256', substr( $content, $item['offset'], $item['length'] ) );
			if ( 1 === $legacy && empty( $item['already'] ) && empty( $item['show_only'] ) && empty( $item['no_post_hints'] ) ) {
				$item += self::hints( $post );
			}
		}
		unset( $item );
		$occupied = array();
		foreach ( $items as $index => $item ) {
			if ( Migration_Tokens::overlap( $item, $occupied ) ) {
				$items[ $index ]['range_ambiguous'] = true;
			}
			$occupied[] = $item;
		}
		Migration_Tokens::check();
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
		if ( Migration_Tokens::match( '~^\[([a-z_]+)(?=\s|\])([^\]]*)\]~i', $raw, $shortcode ) ) {
			$attrs = shortcode_parse_atts( $shortcode[2] );
			Migration_Tokens::check();
			$tag = strtolower( $shortcode[1] );
			$tag = 'libsyn_podcast' === $tag ? 'libsyn' : $tag;
		} else {
			$tag = '';
			Migration_Tokens::all( '~\b([a-z_-]+)\s*=\s*("[^"]*+"|\x27[^\x27]*+\x27)~is', substr( $raw, 0, Migration_Tokens::MAX_TAG ), $attributes, PREG_SET_ORDER );
			foreach ( $attributes as $attr ) {
				$attrs[ strtolower( $attr[1] ) ] = html_entity_decode( substr( $attr[2], 1, -1 ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
		}
		if ( in_array( $tag, array( 'powerpress', 'ss_player', 'ss_podcast', 'podcast_episode', 'podcast_playlist' ), true ) || Migration_Tokens::match( '~^<!-- wp:(?:seriously-simple-podcasting|ssp|castos)/~', $raw ) ) {
			$host = 'powerpress' === $tag ? 'powerpress' : 'ssp';
			if ( 0 === strpos( $raw, '<!--' ) ) {
				$comment = strstr( $raw, '-->', true );
				$begin   = strpos( (string) $comment, '{' );
				$end     = strrpos( (string) $comment, '}' );
				$decoded = false !== $begin && false !== $end ? json_decode( substr( $comment, $begin, $end - $begin + 1 ), true ) : array();
				$attrs   = is_array( $decoded ) ? array_filter( $decoded, 'is_scalar' ) : array();
			}
			// Collection blocks/shortcodes cannot safely become a single episode player.
			if ( in_array( $tag, array( 'ss_podcast', 'podcast_playlist' ), true ) || ( 'podcast_episode' === $tag && false === strpos( $attrs['content'] ?? 'title,player,details', 'player' ) ) || ( '' === $tag && ! Migration_Tokens::match( '~/(?:castos-player|castos-html-player|podcast-player|audio-player)\b~', $raw ) ) ) {
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
			$given = $attrs['episodeId'] ?? $attrs['episode'] ?? $attrs['episode_id'] ?? $attrs['post_id'] ?? $attrs['id'] ?? $post->ID;
			if ( ! ctype_digit( (string) $given ) ) {
				return array(
					'host'      => $host,
					'show_only' => true,
				);
			}
			$id     = 'ss_player' === $tag || 0 === (int) $given ? $post->ID : (int) $given;
			$source = Migration_Scanner::fresh( $id );
			$item   = array(
				'host'          => $host,
				'episode_id'    => (string) $id,
				'no_post_hints' => $id !== $post->ID,
			);
			if ( $source && 'publish' === $source->post_status && current_user_can( 'read_post', $source->ID ) ) {
				$item             += self::hints( $source );
				$meta              = ( Migration_Scanner::meta( $id, 'powerpress' === $host ? 'enclosure' : 'audio_file' )[0] ?? '' );
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
		if ( '' === $url && Migration_Tokens::match( '~(?:https?:)?//[^\s<>"\x27\[\]]+~i', $raw, $link ) ) {
			$url = $link[0];
		}
		$url    = html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$parts  = wp_parse_url( 0 === strpos( $url, '//' ) ? 'https:' . $url : $url );
		$domain = strtolower( $parts['host'] ?? '' );
		$host   = '';
		foreach ( array( 'buzzsprout', 'libsyn', 'captivate', 'transistor', 'spotify', 'podbean' ) as $provider ) {
			$tld = in_array( $provider, array( 'captivate', 'transistor' ), true ) ? 'fm' : 'com';
			if ( Migration_Tokens::match( '~(?:^|\.)' . $provider . '\.' . $tld . '$~', $domain ) || $tag === $provider ) {
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
		if ( Migration_Tokens::match( $patterns[ $host ], $path, $id ) ) {
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
		if ( 'buzzsprout' === $host && Migration_Tokens::match( '~^/(\d+)~', $path, $show ) ) {
			$item['show_id'] = $show[1];
		}
		if ( Migration_Tokens::match( '~/(?:show|podcast|playlist)/(?:id/)?([^/]+)~', $path, $show ) ) {
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
		if ( ! isset( $item['episode_id'] ) || Migration_Tokens::match( '~/(?:show|playlist)(?:/|$)~', $path ) ) {
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
}
