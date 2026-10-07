<?php
/**
 * Byte-compatible port of @showfm/embed 1.1.0 src/lib/fallback.ts (MIT).
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Pure fallback HTML and JSON-LD. Keep in step with the pinned parity fixtures. */
final class Fallback {
	/**
	 * Escape like the package, including already encoded entities.
	 *
	 * @param string $value Text or a validated URL.
	 */
	public static function escape( string $value ): string {
		return strtr(
			$value,
			array(
				'&' => '&amp;',
				'<' => '&lt;',
				'>' => '&gt;',
				'"' => '&quot;',
				"'" => '&#39;',
			)
		);
	}

	/**
	 * Accept only absolute HTTP(S) URLs, matching the package contract.
	 *
	 * @param mixed $value Candidate URL.
	 */
	public static function safe_url( $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = trim( $value );
		return preg_match( '~^https?://~i', $value ) ? $value : null;
	}

	/**
	 * A title link, or plain text for an unsafe URL.
	 *
	 * @param mixed $href URL.
	 * @param mixed $text Title.
	 */
	private static function link( $href, $text ): string {
		$text = is_string( $text ) ? $text : '';
		$url  = self::safe_url( $href );
		return null !== $url ? '<a href="' . self::escape( $url ) . '">' . self::escape( $text ) . '</a>' : self::escape( $text );
	}

	/**
	 * Player and play-button fallback.
	 *
	 * @param array<string,mixed> $episode Public payload or insertion snapshot.
	 * @param array<string,mixed> $options Renderer options.
	 */
	public static function episode( array $episode, array $options = array() ): string {
		$html = self::link( $episode['links']['listen'] ?? null, $episode['title'] ?? '' );
		$url  = self::safe_url( $episode['audio']['url'] ?? null );
		if ( null !== $url ) {
			if ( ! empty( $options['sourceTag'] ) ) {
				// encodeURIComponent leaves these five characters unescaped.
				$tag  = strtr(
					rawurlencode( $options['sourceTag'] ),
					array(
						'%21' => '!',
						'%27' => "'",
						'%28' => '(',
						'%29' => ')',
						'%2A' => '*',
					)
				);
				$url .= ( false === strpos( $url, '?' ) ? '?' : '&' ) . 'src=' . $tag;
			}
			$html .= '<audio controls preload="none" src="' . self::escape( $url ) . '"></audio>';
		}
		return $html;
	}

	/**
	 * List fallback.
	 *
	 * @param array<string,mixed>        $podcast Show payload or snapshot.
	 * @param array<array<string,mixed>> $episodes Episodes.
	 * @param array<string,mixed>        $options Renderer options.
	 */
	public static function episode_list( array $podcast, array $episodes, array $options = array() ): string {
		$shown = array_slice( $episodes, 0, max( 0, (int) ( $options['limit'] ?? count( $episodes ) ) ) );
		if ( ! $shown ) {
			return self::link( $podcast['links']['listen'] ?? null, $podcast['title'] ?? '' );
		}
		$html = '<ul>';
		foreach ( $shown as $episode ) {
			$html .= '<li>' . self::link( $episode['links']['listen'] ?? null, $episode['title'] ?? '' ) . '</li>';
		}
		return $html . '</ul>';
	}

	/**
	 * Serialised PodcastEpisode JSON-LD, safe inside a script element.
	 *
	 * @param array<string,mixed> $episode Public episode.
	 */
	public static function json_ld( array $episode ): string {
		$data = array(
			'@context' => 'https://schema.org',
			'@type'    => 'PodcastEpisode',
			'name'     => is_string( $episode['title'] ?? null ) ? $episode['title'] : '',
		);
		$url  = self::safe_url( $episode['links']['listen'] ?? null );
		if ( $url ) {
			$data['url'] = $url;
		}
		if ( is_string( $episode['published_at'] ?? null ) && '' !== $episode['published_at'] ) {
			try {
				$date = new \DateTimeImmutable( $episode['published_at'], new \DateTimeZone( 'UTC' ) );
			} catch ( \Exception $exception ) {
				// Invalid dates are omitted, as in the package.
				$date = null;
			}
			if ( null !== $date ) {
				$data['datePublished'] = $date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s.v\Z' );
			}
		}
		if ( ! empty( $episode['description'] ) ) {
			$data['description'] = $episode['description'];
		}
		$duration = $episode['audio']['duration_seconds'] ?? null;
		if ( ( is_int( $duration ) || is_float( $duration ) ) && is_finite( (float) $duration ) && $duration > 0 ) {
			$seconds          = (int) round( $duration );
			$hours            = intdiv( $seconds, 3600 );
			$minutes          = intdiv( $seconds % 3600, 60 );
			$rest             = $seconds % 60;
			$data['duration'] = 'PT' . ( $hours ? $hours . 'H' : '' ) . ( $minutes ? $minutes . 'M' : '' ) . ( $rest || 0 === $seconds ? $rest . 'S' : '' );
		}
		$image = self::safe_url( $episode['artwork']['url'] ?? null );
		if ( $image ) {
			$data['image'] = $image;
		}
		if ( isset( $episode['episode_number'] ) && ( is_int( $episode['episode_number'] ) || is_float( $episode['episode_number'] ) ) ) {
			$data['episodeNumber'] = $episode['episode_number'];
		}
		if ( isset( $episode['season_number'] ) && ( is_int( $episode['season_number'] ) || is_float( $episode['season_number'] ) ) ) {
			$data['partOfSeason'] = array(
				'@type'        => 'PodcastSeason',
				'seasonNumber' => $episode['season_number'],
			);
		}
		if ( is_string( $episode['podcast']['title'] ?? null ) && '' !== $episode['podcast']['title'] ) {
			$series = array(
				'@type' => 'PodcastSeries',
				'name'  => $episode['podcast']['title'],
			);
			$url    = self::safe_url( $episode['podcast']['links']['listen'] ?? null );
			if ( $url ) {
				$series['url'] = $url;
			}
			$data['partOfSeries'] = $series;
		}
		$audio = self::safe_url( $episode['audio']['url'] ?? null );
		if ( $audio ) {
			$media = array(
				'@type'      => 'MediaObject',
				'contentUrl' => $audio,
			);
			if ( ! empty( $episode['audio']['content_type'] ) ) {
				$media['encodingFormat'] = $episode['audio']['content_type'];
			}
			$data['associatedMedia'] = $media;
		}
		return str_replace( array( '<', '>', '&' ), array( '\u003c', '\u003e', '\u0026' ), (string) wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}
}
