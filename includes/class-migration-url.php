<?php
/**
 * PHP port of developer-api/provenance.ts, contract version 1.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Normalisation never follows redirects and never returns credentials or URL queries. */
final class Migration_Url {
	/**
	 * Normalise an enclosure exactly before computing its matching fingerprint.
	 *
	 * @param string|null $value Original enclosure URL.
	 */
	public static function normalise( ?string $value ): ?string {
		$current  = self::guid( $value );
		$prefixes = array(
			'dts.podtrac.com'      => '~^/redirect\.[^/]++/~',
			'www.podtrac.com'      => '~^/pts/redirect\.[^/]++/~',
			'chrt.fm'              => '~^/track/[^/]++/~',
			'chtbl.com'            => '~^/track/[^/]++/~',
			'op3.dev'              => '~^/e(?:,pg=[^/,]++)?/~',
			'pdst.fm'              => '~^/e/~',
			'pfx.vpixl.com'        => '~^/[^/]++/~',
			'mgln.ai'              => '~^/e/[^/]++/~',
			'arttrk.com'           => '~^/p/[^/]++/~',
			'verifi.podscribe.com' => '~^/rss/p/~',
			'pscrb.fm'             => '~^/rss/p/~',
			'claritaspod.com'      => '~^/measure/~',
		);
		for ( $depth = 0; $depth <= 32 && null !== $current; ++$depth ) {
			if ( Migration_Tokens::match( '/[\x00-\x20\x7f\\\\]/', $current ) || ! Migration_Tokens::match( '~\A(https?)://([^/?#]++)([^?#]*+)~i', $current, $parts ) ) {
				return null;
			}
			$scheme    = strtolower( $parts[1] );
			$authority = self::authority( $scheme, $parts[2] );
			$path      = '' === $parts[3] ? '/' : $parts[3];
			if ( null === $authority || Migration_Tokens::match( '/%(?![a-f0-9]{2})/i', $path ) ) {
				return null;
			}
			list( $host, $port ) = $authority;
			if ( '' === $port && isset( $prefixes[ $host ] ) && Migration_Tokens::match( $prefixes[ $host ], $path, $prefix ) ) {
				$target = substr( $path, strlen( $prefix[0] ) );
				if ( 32 === $depth || '' === $target ) {
					return null;
				}
				$scheme  = 'op3.dev' === $host ? 'https' : $scheme;
				$current = Migration_Tokens::match( '~^[a-z][a-z0-9+.-]*://~i', $target ) ? $target : $scheme . ':' . ( 0 === strpos( $target, '//' ) ? '' : '//' ) . $target;
				continue;
			}
			$path = preg_replace_callback(
				'/%[a-f0-9]{2}/i',
				static function ( $escape ) {
					return strtoupper( $escape[0] );
				},
				$path
			);
			Migration_Tokens::check();
			return $scheme . '://' . $host . $port . $path;
		}
		return null;
	}

	/**
	 * ECMAScript trim, preserving all internal bytes and U+0085.
	 *
	 * @param string|null $value Source or public RSS GUID.
	 */
	public static function guid( ?string $value ): ?string {
		if ( null === $value ) {
			return null;
		}
		$ws    = '[\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]';
		$value = preg_replace( '/\A' . $ws . '++|' . $ws . '++\z/u', '', $value );
		Migration_Tokens::check();
		return null === $value || '' === $value ? null : $value;
	}

	/**
	 * SHA-256 of UTF-8 normalised bytes. Unknown values remain null.
	 *
	 * @param string|null $normalised Normalised value.
	 */
	public static function fingerprint( ?string $normalised ): ?string {
		return null === $normalised ? null : hash( 'sha256', $normalised );
	}

	/**
	 * WHATWG HTTP authority: IDNA, IPv6, IPv4 numbers and scheme-specific default ports.
	 *
	 * @param string $scheme Lowercase scheme.
	 * @param string $authority Raw authority, including optional userinfo.
	 * @return array{string,string}|null
	 */
	private static function authority( string $scheme, string $authority ): ?array {
		$at        = strrpos( $authority, '@' );
		$authority = false === $at ? $authority : substr( $authority, $at + 1 );
		if ( ! Migration_Tokens::match( '/\A(\[[^\]]++\]|[^:]++)(?::([0-9]*+))?\z/', $authority, $parts ) ) {
			return null;
		}
		$host = $parts[1];
		$port = $parts[2] ?? '';
		if ( '' !== $port && (float) $port > 65535 ) {
			return null;
		}
		$port = '' === $port || ( 'http' === $scheme ? 80 : 443 ) === (int) $port ? '' : ':' . (int) $port;
		if ( '[' === $host[0] ) {
			$binary = inet_pton( substr( $host, 1, -1 ) );
			if ( false === $binary || 16 !== strlen( $binary ) ) {
				return null;
			}
			$words  = array_values( unpack( 'n8', $binary ) );
			$best   = -1;
			$length = 1;
			for ( $i = 0; $i < 8; ++$i ) {
				if ( 0 !== $words[ $i ] ) {
					continue;
				}
				$end = $i;
				while ( $end < 8 && 0 === $words[ $end ] ) {
					++$end;
				}
				if ( $end - $i > $length ) {
					$best   = $i;
					$length = $end - $i;
				}
				$i = $end - 1;
			}
			$words = array_map( 'dechex', $words );
			$host  = $best < 0 ? implode( ':', $words ) : implode( ':', array_slice( $words, 0, $best ) ) . '::' . implode( ':', array_slice( $words, $best + $length ) );
			return array( '[' . $host . ']', $port );
		}
		$host = rawurldecode( $host );
		if ( Migration_Tokens::match( '/[\x00-\x20\x7f#%\/:<>?@\[\]\\\\^|]/', $host ) ) {
			return null;
		}
		if ( function_exists( 'idn_to_ascii' ) ) {
			idn_to_ascii( $host, IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ, INTL_IDNA_VARIANT_UTS46, $info );
			// WHATWG uses non-strict UTS46: no hyphen or DNS-length checks.
			$ignored = IDNA_ERROR_EMPTY_LABEL | IDNA_ERROR_LABEL_TOO_LONG | IDNA_ERROR_DOMAIN_NAME_TOO_LONG | IDNA_ERROR_LEADING_HYPHEN | IDNA_ERROR_TRAILING_HYPHEN | IDNA_ERROR_HYPHEN_3_4;
			$host    = 0 === ( $info['errors'] & ~$ignored ) ? $info['result'] : false;
		} elseif ( Migration_Tokens::match( '/[^\x00-\x7f]/', $host ) ) {
			// Without UTS46 support, refuse IDNs rather than generating a different fingerprint.
			return null;
		}
		if ( ! is_string( $host ) || '' === $host ) {
			return null;
		}
		$host   = strtolower( $host );
		$labels = explode( '.', $host );
		if ( '' === end( $labels ) ) {
			array_pop( $labels );
		}
		$last = end( $labels );
		if ( Migration_Tokens::match( '/\A(?:[0-9]++|0x[0-9a-f]*+)\z/', $last ) ) {
			if ( count( $labels ) > 4 ) {
				return null;
			}
			$numbers = array();
			foreach ( $labels as $label ) {
				$base = 10;
				if ( 0 === strpos( $label, '0x' ) ) {
					$base  = 16;
					$label = substr( $label, 2 );
				} elseif ( strlen( $label ) > 1 && '0' === $label[0] ) {
					$base  = 8;
					$label = substr( $label, 1 );
				}
				$valid = array(
					8  => '/\A[0-7]*+\z/',
					10 => '/\A[0-9]++\z/',
					16 => '/\A[0-9a-f]*+\z/',
				);
				if ( ! Migration_Tokens::match( $valid[ $base ], $label ) ) {
					return null;
				}
				$numbers[] = '' === $label ? 0 : intval( $label, $base );
			}
			$last = array_pop( $numbers );
			if ( $last >= pow( 256, 4 - count( $numbers ) ) ) {
				return null;
			}
			$address = $last;
			foreach ( $numbers as $index => $number ) {
				if ( $number > 255 ) {
					return null;
				}
				$address += $number * pow( 256, 3 - $index );
			}
			$host = long2ip( (int) $address );
		}
		return array( $host, $port );
	}
}
