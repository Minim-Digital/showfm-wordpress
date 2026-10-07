<?php
/**
 * Ordered, evidence-only episode matching.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Does not treat a hosting provider's episode id as a show.fm UUID or GUID. */
final class Migration_Matcher {
	/**
	 * Match against an iterable so the catalogue need not fit in memory.
	 *
	 * @param array<string,mixed> $embed    Extracted identifiers.
	 * @param iterable            $episodes Available episodes.
	 * @phpstan-param iterable<array<string,mixed>> $episodes
	 * @return array<string,mixed>
	 */
	public static function match( array $embed, iterable $episodes ): array {
		$best = 0;
		$hits = array();
		foreach ( $episodes as $episode ) {
			$rank = self::rank( $embed, $episode );
			if ( $rank > $best ) {
				$best = $rank;
				$hits = array();
			}
			if ( $rank > 0 && $rank === $best ) {
				$hits[ $episode['id'] ] = $episode;
			}
		}
		return array(
			'status'     => count( $hits ) > 1 ? 'ambiguous' : ( $hits ? 'matched' : 'unmatched' ),
			'method'     => array( '', 'title_date', 'guid', 'enclosure' )[ $best ],
			'candidates' => array_values( $hits ),
		);
	}

	/**
	 * Strongest evidence shared by an embed and episode.
	 *
	 * @param array<string,mixed> $embed   Embed.
	 * @param array<string,mixed> $episode Episode.
	 */
	private static function rank( array $embed, array $episode ): int {
		$url = Migration_Url::normalise( $embed['audio_url'] ?? '' );
		if ( '' !== $url && Migration_Url::normalise( $episode['audio_url'] ?? '' ) === $url ) {
			return 3;
		}
		if ( ! empty( $embed['guid'] ) && ( $episode['guid'] ?? '' ) === $embed['guid'] ) {
			return 2;
		}
		$title = self::title( $embed['title'] ?? '' );
		$date  = strtotime( $embed['published_at'] ?? '' );
		$other = strtotime( $episode['published_at'] ?? '' );
		if ( '' !== $title && self::title( $episode['title'] ?? '' ) === $title && false !== $date && false !== $other && abs( $date - $other ) <= DAY_IN_SECONDS ) {
			return 1;
		}
		return 0;
	}

	/**
	 * Compare plain titles without punctuation guessing or fuzzy matching.
	 *
	 * @param string $title Title.
	 */
	private static function title( string $title ): string {
		$title = trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $title ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $title, 'UTF-8' ) : strtolower( $title );
	}
}
