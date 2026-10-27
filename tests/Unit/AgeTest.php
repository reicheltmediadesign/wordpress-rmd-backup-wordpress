<?php
declare(strict_types=1);

namespace RMD\BackupWordpress\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use RMD\BackupWordpress\Domain\Age;
use RMD\BackupWordpress\Domain\Bech32;
use RuntimeException;

#[RequiresPhpExtension( 'sodium' )]
final class AgeTest extends TestCase {

	public function test_bech32_reference_vectors(): void {
		self::assertSame( 'a', Bech32::decode( 'A12UEL5L' )['hrp'] );
		self::assertSame( '', Bech32::decode( 'A12UEL5L' )['data'] );
		self::assertSame( 'abcdef', Bech32::decode( 'abcdef1qpzry9x8gf2tvdw0s3jn54khce6mua7lmqqqxw' )['hrp'] );
		self::assertSame( 'A12UEL5L', strtoupper( Bech32::encode( 'a', '' ) ) );

		$this->expectException( InvalidArgumentException::class );
		Bech32::decode( 'A12UEL5M' );
	}

	public function test_recipient_round_trip(): void {
		[ , $public, $recipient ] = self::keypair();
		self::assertStringStartsWith( 'age1', $recipient );
		self::assertSame( $public, Age::recipient( $recipient ) );
		self::assertTrue( Age::is_recipient( "  $recipient\n" ) );
		self::assertFalse( Age::is_recipient( 'age1notakey' ) );
		self::assertFalse( Age::is_recipient( Bech32::encode( 'age', random_bytes( 31 ) ) ) );
		self::assertFalse( Age::is_recipient( Bech32::encode( 'ssh', $public ) ) );
		self::assertFalse( Age::is_recipient( Bech32::encode( 'age1pq', random_bytes( 64 ) ) ) );
	}

	/**
	 * @return iterable<string, array{int}>
	 */
	public static function sizes(): iterable {
		yield 'one byte' => [ 1 ];
		yield 'below one chunk' => [ 1000 ];
		yield 'exactly one chunk' => [ Age::CHUNK ];
		yield 'one chunk and a byte' => [ Age::CHUNK + 1 ];
		yield 'three full chunks' => [ 3 * Age::CHUNK ];
	}

	/**
	 * @dataProvider sizes
	 */
	public function test_encrypts_in_chunks( int $size ): void {
		[ $secret, , $recipient ] = self::keypair();
		$plaintext                = random_bytes( $size );

		$file      = Age::begin( $recipient );
		$encrypted = $file['header'];
		$chunks    = str_split( $plaintext, Age::CHUNK );
		foreach ( $chunks as $i => $chunk ) {
			$encrypted .= Age::chunk( $chunk, $i, count( $chunks ) - 1 === $i, $file['payload_key'] );
		}

		self::assertSame( strlen( $file['header'] ) + Age::payload_size( $size ), strlen( $encrypted ) );
		self::assertSame( $plaintext, self::decrypt( $encrypted, $secret ) );
	}

	public function test_tampering_is_detected(): void {
		[ $secret, , $recipient ] = self::keypair();
		$file                     = Age::begin( $recipient );
		$encrypted                = $file['header'] . Age::chunk( 'secret backup', 0, true, $file['payload_key'] );
		$encrypted[ strlen( $encrypted ) - 3 ] = chr( ord( $encrypted[ strlen( $encrypted ) - 3 ] ) ^ 1 );

		$this->expectException( RuntimeException::class );
		self::decrypt( $encrypted, $secret );
	}

	public function test_non_final_chunks_must_be_full(): void {
		$this->expectException( InvalidArgumentException::class );
		Age::chunk( 'short', 0, false, str_repeat( 'k', 32 ) );
	}

	/**
	 * @return array{string, string, string} Secret key, public key, recipient.
	 */
	private static function keypair(): array {
		$secret = random_bytes( 32 );
		$public = sodium_crypto_scalarmult_base( $secret );
		return [ $secret, $public, Bech32::encode( 'age', $public ) ];
	}

	/**
	 * Independent decryption following the age specification.
	 */
	private static function decrypt( string $file, string $secret ): string {
		$end = strpos( $file, "\n---" );
		if ( false === $end ) {
			throw new RuntimeException( 'No header end.' );
		}
		$mac_end = strpos( $file, "\n", $end + 1 );
		$header  = substr( $file, 0, $end + 4 );
		$mac     = base64_decode( substr( $file, $end + 5, $mac_end - $end - 5 ), true );
		$lines   = explode( "\n", $header );
		self::assertSame( 'age-encryption.org/v1', $lines[0] );
		self::assertSame( 'X25519', explode( ' ', $lines[1] )[1] );

		$share    = (string) base64_decode( explode( ' ', $lines[1] )[2], true );
		$body     = (string) base64_decode( $lines[2], true );
		$public   = sodium_crypto_scalarmult_base( $secret );
		$wrap_key = hash_hkdf( 'sha256', sodium_crypto_scalarmult( $secret, $share ), 32, 'age-encryption.org/v1/X25519', $share . $public );
		$file_key = sodium_crypto_aead_chacha20poly1305_ietf_decrypt( $body, '', str_repeat( "\0", 12 ), $wrap_key );
		if ( false === $file_key || ! hash_equals( hash_hmac( 'sha256', $header, hash_hkdf( 'sha256', $file_key, 32, 'header', '' ), true ), (string) $mac ) ) {
			throw new RuntimeException( 'Header could not be verified.' );
		}

		$payload = substr( $file, $mac_end + 1 );
		$key     = hash_hkdf( 'sha256', $file_key, 32, 'payload', substr( $payload, 0, 16 ) );
		$chunks  = str_split( substr( $payload, 16 ), Age::CHUNK + 16 );
		$out     = '';
		foreach ( $chunks as $i => $chunk ) {
			$nonce = "\0\0\0" . pack( 'J', $i ) . ( count( $chunks ) - 1 === $i ? "\x01" : "\x00" );
			$plain = sodium_crypto_aead_chacha20poly1305_ietf_decrypt( $chunk, '', $nonce, $key );
			if ( false === $plain ) {
				throw new RuntimeException( 'Chunk ' . $i . ' could not be decrypted.' );
			}
			$out .= $plain;
		}
		return $out;
	}
}
