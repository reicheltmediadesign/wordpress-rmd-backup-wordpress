<?php
/**
 * Bech32 (BIP 173) as used by age for keys ("age1…" recipients,
 * "AGE-SECRET-KEY-1…" identities). age allows longer strings than the
 * 90 characters of BIP 173.
 *
 * @package RMD\BackupWordpress
 */

namespace RMD\BackupWordpress\Domain;

use InvalidArgumentException;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WordPress-free layer; messages are escaped where they are shown.

final class Bech32 {

	private const CHARSET   = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
	private const GENERATOR = [ 0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3 ];

	/**
	 * @return array{hrp: string, data: string} Human-readable part (lower case) and decoded bytes.
	 */
	public static function decode( string $text ): array {
		if ( strtolower( $text ) !== $text && strtoupper( $text ) !== $text ) {
			throw new InvalidArgumentException( 'Mixed case in Bech32 string.' );
		}
		$text = strtolower( trim( $text ) );
		$pos  = strrpos( $text, '1' );
		if ( false === $pos || $pos < 1 || strlen( $text ) - $pos - 1 < 6 ) {
			throw new InvalidArgumentException( 'Not a Bech32 string.' );
		}

		$hrp    = substr( $text, 0, $pos );
		$values = [];
		foreach ( str_split( substr( $text, $pos + 1 ) ) as $char ) {
			$value = strpos( self::CHARSET, $char );
			if ( false === $value ) {
				throw new InvalidArgumentException( 'Invalid character in Bech32 string.' );
			}
			$values[] = $value;
		}
		if ( 1 !== self::polymod( array_merge( self::expand( $hrp ), $values ) ) ) {
			throw new InvalidArgumentException( 'Bech32 checksum mismatch.' );
		}

		return [
			'hrp'  => $hrp,
			'data' => self::convert( array_slice( $values, 0, -6 ), 5, 8, false ),
		];
	}

	public static function encode( string $hrp, string $data ): string {
		$hrp    = strtolower( $hrp );
		$values = array_values( (array) unpack( 'C*', self::convert( array_values( (array) unpack( 'C*', $data ) ), 8, 5, true ) ) );
		$mod    = self::polymod( array_merge( self::expand( $hrp ), $values, [ 0, 0, 0, 0, 0, 0 ] ) ) ^ 1;
		for ( $i = 0; $i < 6; $i++ ) {
			$values[] = ( $mod >> ( 5 * ( 5 - $i ) ) ) & 31;
		}
		return $hrp . '1' . implode( '', array_map( static fn( int $v ): string => self::CHARSET[ $v ], $values ) );
	}

	/**
	 * @param list<int> $values
	 */
	private static function polymod( array $values ): int {
		$chk = 1;
		foreach ( $values as $value ) {
			$top = $chk >> 25;
			$chk = ( ( $chk & 0x1ffffff ) << 5 ) ^ $value;
			for ( $i = 0; $i < 5; $i++ ) {
				if ( ( $top >> $i ) & 1 ) {
					$chk ^= self::GENERATOR[ $i ];
				}
			}
		}
		return $chk;
	}

	/**
	 * @return list<int>
	 */
	private static function expand( string $hrp ): array {
		$high = [];
		$low  = [];
		foreach ( str_split( $hrp ) as $char ) {
			$high[] = ord( $char ) >> 5;
			$low[]  = ord( $char ) & 31;
		}
		return array_merge( $high, [ 0 ], $low );
	}

	/**
	 * Regroups bits; returns the result as a byte string of group values.
	 *
	 * @param list<int> $values
	 */
	private static function convert( array $values, int $from, int $to, bool $pad ): string {
		$acc    = 0;
		$bits   = 0;
		$result = '';
		$max    = ( 1 << $to ) - 1;
		foreach ( $values as $value ) {
			$acc   = ( $acc << $from ) | $value;
			$bits += $from;
			while ( $bits >= $to ) {
				$bits   -= $to;
				$result .= chr( ( $acc >> $bits ) & $max );
			}
		}
		if ( $pad && $bits > 0 ) {
			$result .= chr( ( $acc << ( $to - $bits ) ) & $max );
		} elseif ( ! $pad && ( $bits >= $from || ( ( $acc << ( $to - $bits ) ) & $max ) ) ) {
			throw new InvalidArgumentException( 'Invalid padding in Bech32 string.' );
		}
		return $result;
	}
}
