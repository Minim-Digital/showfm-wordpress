<?php
/**
 * Bounded lexical ranges without whole-document lazy regular expressions.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Byte offsets are preserved. Malformed or oversized input fails closed. */
final class Migration_Tokens {
	const MAX_BYTES  = 2097152;
	const MAX_TAG    = 16384;
	const MAX_TOKENS = 10000;

	/**
	 * Read a bounded post once, pairing tags/comments with explicit delimiter searches.
	 *
	 * @param string $content Original content.
	 * @return array{ranges:array<int,array<string,mixed>>,wrappers:array<int,array<string,mixed>>}
	 * @throws \RuntimeException For malformed syntax, resource bounds or PCRE failure.
	 */
	public static function read( string $content ): array {
		$size = strlen( $content );
		if ( $size > self::MAX_BYTES ) {
			throw new \RuntimeException( 'Post exceeds the scan limit.' );
		}
		$ranges    = array();
		$ignored   = array();
		$wrappers  = array();
		$stack     = array();
		$empty_div = null;
		$cursor    = 0;
		$count     = 0;
		while ( $cursor < $size ) {
			$cursor += strcspn( $content, '<[', $cursor );
			if ( $cursor >= $size ) {
				break;
			}
			if ( ++$count > self::MAX_TOKENS ) {
				throw new \RuntimeException( 'Too many tokens.' );
			}
			$start = $cursor;
			if ( '<!--' === substr( $content, $cursor, 4 ) ) {
				$close = strpos( $content, '-->', $cursor + 4 );
				if ( false === $close || $close - $cursor > self::MAX_TAG ) {
					throw new \RuntimeException( 'Unclosed or oversized comment.' );
				}
				$cursor    = $close + 3;
				$raw       = substr( $content, $start, $cursor - $start );
				$ignored[] = array(
					'offset' => $start,
					'length' => $cursor - $start,
				);
				if ( ! self::match( '~\A<!--\s*+(/?)wp:([a-z0-9_/-]++)(?=[\s/])~i', $raw, $block ) ) {
					continue;
				}
				$name         = $block[2];
				$self_closing = '/-->' === substr( rtrim( $raw ), -4 );
				if ( '' === $block[1] && self::match( '~\A(?:showfm|seriously-simple-podcasting|ssp|castos)/~', $name ) ) {
					if ( ! $self_closing ) {
						$end    = stripos( $content, '<!-- /wp:' . $name . ' ', $cursor );
						$finish = false === $end ? false : strpos( $content, '-->', $end );
						if ( false === $end || false === $finish ) {
							throw new \RuntimeException( 'Unclosed player block.' );
						}
						$cursor = $finish + 3;
					}
					$ranges[] = array(
						'offset' => $start,
						'length' => $cursor - $start,
					);
					continue;
				}
				if ( '' === $block[1] && ! $self_closing ) {
					$stack[] = array(
						'name'   => $name,
						'offset' => $start,
						'body'   => $cursor,
					);
				} elseif ( '/' === $block[1] ) {
					$open = array_pop( $stack );
					if ( ! $open || $open['name'] !== $name ) {
						throw new \RuntimeException( 'Unbalanced block wrapper.' );
					}
					if ( in_array( $name, array( 'html', 'shortcode', 'embed', 'paragraph' ), true ) ) {
						$wrappers[] = $open + array(
							'body_end' => $start,
							'end'      => $cursor,
						);
					}
				}
				continue;
			}
			if ( '<' === $content[ $start ] ) {
				$end    = self::tag_end( $content, $start );
				$raw    = substr( $content, $start, $end - $start );
				$cursor = $end;
				if ( ! self::match( '~\A<([a-z][a-z0-9]*+)(?=[\s>/])~i', $raw, $tag ) ) {
					continue;
				}
				$name_tag = strtolower( $tag[1] );
				if ( in_array( $name_tag, array( 'pre', 'code', 'a', 'iframe', 'script' ), true ) ) {
					$close = stripos( $content, '</' . $name_tag, $cursor );
					if ( false === $close ) {
						throw new \RuntimeException( 'Unclosed player or literal tag.' );
					}
					$cursor = self::tag_end( $content, $close );
					if ( ! self::match( '~\A</' . $name_tag . '\s*+>\z~i', substr( $content, $close, $cursor - $close ) ) ) {
						throw new \RuntimeException( 'Invalid closing tag.' );
					}
					$range = array(
						'offset' => $start,
						'length' => $cursor - $start,
					);
					if ( in_array( $name_tag, array( 'iframe', 'script' ), true ) ) {
						if ( 'script' === $name_tag && $empty_div && '' === trim( substr( $content, $empty_div['end'], $start - $empty_div['end'] ) ) && false !== strpos( $raw, 'buzzsprout.com/' ) ) {
							$range = array(
								'offset' => $empty_div['offset'],
								'length' => $cursor - $empty_div['offset'],
							);
						}
						$ranges[] = $range;
					} else {
						$ignored[] = $range;
					}
				} elseif ( 'div' === $name_tag && self::match( '~\bid=["\x27]buzzsprout[^"\x27]{0,200}["\x27]~i', $raw ) && self::match( '~\A\s{0,1000}</div\s{0,10}>~i', substr( $content, $cursor, 1020 ), $closing_div ) ) {
					$cursor   += strlen( $closing_div[0] );
					$empty_div = array(
						'offset' => $start,
						'end'    => $cursor,
					);
				}
				continue;
			}
			$relative_end = strpos( substr( $content, $start, self::MAX_TAG ), ']' );
			$end          = false === $relative_end ? false : $start + $relative_end;
			if ( false === $end || $end - $start > self::MAX_TAG ) {
				++$cursor;
				continue;
			}
			$raw = substr( $content, $start, $end + 1 - $start );
			if ( self::match( '~\A\[\[[a-z_-]++[^\]]*+\]\z~i', $raw ) && ']' === substr( $content, $end + 1, 1 ) ) {
				$cursor    = $end + 2;
				$ignored[] = array(
					'offset' => $start,
					'length' => $cursor - $start,
				);
				continue;
			}
			if ( ! self::match( '~\A\[(buzzsprout|libsyn|libsyn_podcast|captivate|transistor|spotify|podbean|powerpress|ss_player|ss_podcast|podcast_episode|podcast_playlist|embed)(?=[\s\]/])~i', $raw, $shortcode ) ) {
				++$cursor;
				continue;
			}
			$cursor = $end + 1;
			if ( 'embed' === strtolower( $shortcode[1] ) ) {
				$close = strpos( $content, '[/embed]', $cursor );
				if ( false === $close || $close - $cursor > self::MAX_TAG ) {
					throw new \RuntimeException( 'Unclosed embed shortcode.' );
				}
				$cursor = $close + 8;
			} else {
				$tail         = substr( $content, $cursor, self::MAX_TAG );
				$closing      = '[/' . $shortcode[1] . ']';
				$next_bracket = strpos( $tail, '[' );
				if ( false !== $next_bracket && 0 === strcasecmp( substr( $tail, $next_bracket, strlen( $closing ) ), $closing ) && false === strpos( substr( $tail, 0, $next_bracket ), '<' ) ) {
					$cursor += $next_bracket + strlen( $closing );
				}
			}
			$ranges[] = array(
				'offset' => $start,
				'length' => $cursor - $start,
			);
		}
		if ( $stack ) {
			throw new \RuntimeException( 'Unclosed block wrapper.' );
		}
		self::all( '~^[\t ]{0,1000}(?:https?:)?//[^\s<>]{1,8192}[\t ]{0,1000}$~im', $content, $urls, PREG_OFFSET_CAPTURE );
		foreach ( $urls[0] as $url ) {
			$range = array(
				'offset' => $url[1],
				'length' => strlen( $url[0] ),
			);
			if ( ! self::overlap( $range, array_merge( $ranges, $ignored ) ) ) {
				$ranges[] = $range;
			}
		}
		return array(
			'ranges'   => $ranges,
			'wrappers' => $wrappers,
		);
	}

	/**
	 * Find a quote-aware tag end with a fixed per-token byte budget.
	 *
	 * @param string $content Content.
	 * @param int    $start Start byte.
	 * @throws \RuntimeException On an unterminated/oversized tag.
	 */
	private static function tag_end( string $content, int $start ): int {
		$quote = '';
		$limit = min( strlen( $content ), $start + self::MAX_TAG );
		for ( $i = $start + 1; $i < $limit; ++$i ) {
			$char = $content[ $i ];
			if ( '' !== $quote ) {
				if ( $char === $quote ) {
					$quote = '';
				}
			} elseif ( '"' === $char || "'" === $char ) {
				$quote = $char;
			} elseif ( '>' === $char ) {
				return $i + 1;
			} elseif ( '<' === $char ) {
				break;
			}
		}
		throw new \RuntimeException( 'Unclosed or oversized HTML tag.' );
	}

	/**
	 * Checked PCRE operation, including errors caused by low process limits.
	 *
	 * @param string                       $pattern Pattern.
	 * @param string                       $subject Subject.
	 * @param array<int|string,mixed>|null $matches Matches.
	 * @throws \RuntimeException On PCRE error.
	 * @param-out array<int|string,mixed> $matches
	 */
	public static function match( string $pattern, string $subject, ?array &$matches = null ): bool {
		$result = preg_match( $pattern, $subject, $matches );
		self::check();
		return 1 === $result;
	}

	/**
	 * Checked global PCRE operation.
	 *
	 * @param string                       $pattern Pattern.
	 * @param string                       $subject Subject.
	 * @param array<int|string,mixed>|null $matches Matches.
	 * @param int                          $flags Capture flags.
	 * @throws \RuntimeException On PCRE error.
	 * @param-out array<int|string,mixed> $matches
	 */
	public static function all( string $pattern, string $subject, ?array &$matches = null, int $flags = PREG_PATTERN_ORDER ): void {
		preg_match_all( $pattern, $subject, $matches, $flags );
		self::check();
	}

	/**
	 * Fail closed after a core parsing helper that uses PCRE internally.
	 *
	 * @throws \RuntimeException On PCRE error.
	 */
	public static function check(): void {
		if ( PREG_NO_ERROR !== preg_last_error() ) {
			throw new \RuntimeException( 'Regular expression failed.' );
		}
	}

	/**
	 * Check a candidate against already occupied spans.
	 *
	 * @param array<string,mixed>            $range Candidate.
	 * @param array<int,array<string,mixed>> $others Existing spans.
	 */
	public static function overlap( array $range, array $others ): bool {
		foreach ( $others as $other ) {
			if ( $range['offset'] < $other['offset'] + $other['length'] && $range['offset'] + $range['length'] > $other['offset'] ) {
				return true;
			}
		}
		return false;
	}
}
