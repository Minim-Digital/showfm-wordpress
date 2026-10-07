<?php
/**
 * Indexed, ordered provenance matching.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** One index per catalogue run, reused for every embed and scanner batch. */
final class Migration_Matcher {
	/**
	 * Index by evidence type, value and episode UUID.
	 *
	 * @var array<string,array<string,array<string,array<string,mixed>>>>
	 */
	private $index = array();
	/**
	 * Verified provider identity to connected podcast IDs. Never inferred from titles.
	 *
	 * @var array<string,string>
	 */
	private $shows;

	/**
	 * Build an immutable index once.
	 *
	 * @param iterable             $episodes Episodes.
	 * @param array<string,string> $shows Verified host:show-id or host:feed to podcast UUID mapping.
	 * @phpstan-param iterable<array<string,mixed>> $episodes
	 */
	public function __construct( iterable $episodes, array $shows = array() ) {
		$this->shows = $shows;
		foreach ( $episodes as $episode ) {
			$keys = array(
				'enclosure'  => $episode['source']['enclosure_sha256'] ?? null,
				'guid'       => $episode['source']['guid_sha256'] ?? null,
				'rss_guid'   => Migration_Url::fingerprint( Migration_Url::guid( $episode['rss_guid'] ?? null ) ),
				'title_date' => self::title( $episode['title'] ?? '' ),
			);
			foreach ( $keys as $type => $key ) {
				if ( is_string( $key ) && '' !== $key ) {
					$this->index[ $type ][ $key ][ $episode['id'] ] = $episode;
				}
			}
		}
	}

	/**
	 * Convenience entry point for callers with a small standalone catalogue.
	 *
	 * @param array<string,mixed>  $embed Identifiers.
	 * @param iterable             $episodes Episodes.
	 * @param array<string,string> $shows Verified show identity mapping.
	 * @phpstan-param iterable<array<string,mixed>> $episodes
	 * @return array<string,mixed>
	 */
	public static function match( array $embed, iterable $episodes, array $shows = array() ): array {
		return ( new self( $episodes, $shows ) )->find( $embed );
	}

	/**
	 * Never weaken ambiguous provenance to a title match.
	 *
	 * @param array<string,mixed> $embed Identifiers.
	 * @return array<string,mixed>
	 */
	public function find( array $embed ): array {
		if ( ! empty( $embed['already'] ) ) {
			return array(
				'status'     => 'already_showfm',
				'method'     => '',
				'candidates' => array(),
			);
		}
		if ( ! empty( $embed['show_only'] ) ) {
			return array(
				'status'     => 'unmatched',
				'method'     => '',
				'candidates' => array(),
			);
		}
		$url    = Migration_Url::fingerprint( Migration_Url::normalise( $embed['audio_url'] ?? null ) );
		$guid   = Migration_Url::fingerprint( Migration_Url::guid( $embed['guid'] ?? null ) );
		$hits   = null === $url ? array() : ( $this->index['enclosure'][ $url ] ?? array() );
		$method = 'enclosure';
		if ( ! $hits ) {
			$method = 'guid';
			$hits   = null === $guid ? array() : array_merge( $this->index['guid'][ $guid ] ?? array(), $this->index['rss_guid'][ $guid ] ?? array() );
		}
		if ( ! $hits ) {
			$method = 'title_date';
			$date   = strtotime( $embed['published_at'] ?? '' );
			foreach ( $this->index['title_date'][ self::title( $embed['title'] ?? '' ) ] ?? array() as $id => $episode ) {
				$other = strtotime( $episode['published_at'] ?? '' );
				if ( false !== $date && false !== $other && abs( $date - $other ) <= DAY_IN_SECONDS ) {
					$hits[ $id ] = $episode;
				}
			}
		}
		$candidates = array_values( $hits );
		$status     = $hits ? 'matched' : 'unmatched';
		if ( count( $hits ) > 1 || ( $hits && 'title_date' === $method && ! $this->same_show( $embed, $candidates[0]['podcast_id'] ) ) ) {
			$status = 'ambiguous';
		}
		return array(
			'status'     => $status,
			'method'     => $hits ? $method : '',
			'candidates' => $candidates,
		);
	}

	/**
	 * Require identity supplied by the embed and an independently verified connected show.
	 *
	 * @param array<string,mixed> $embed Embed.
	 * @param string              $podcast Podcast UUID.
	 */
	private function same_show( array $embed, string $podcast ): bool {
		foreach ( array( 'show_id', 'feed' ) as $field ) {
			if ( ! empty( $embed[ $field ] ) && ( $this->shows[ ( $embed['host'] ?? '' ) . ':' . $embed[ $field ] ] ?? '' ) === $podcast ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Plain case-folded title key, without fuzzy matching.
	 *
	 * @param string $title Title.
	 */
	private static function title( string $title ): string {
		$title = trim( (string) preg_replace( '/\s++/u', ' ', html_entity_decode( wp_strip_all_tags( $title ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
		Migration_Tokens::check();
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $title, 'UTF-8' ) : strtolower( $title );
	}
}
