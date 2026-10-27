<?php
/**
 * Encryption in the age format (https://age-encryption.org/v1) for one X25519
 * recipient. Only the public key ("age1…") is needed here; backups are
 * decrypted with the age command line tool and the private key, which never
 * reaches the site:
 *
 *   age -d -i key.txt backup.zip.age > backup.zip
 *
 * The payload is encrypted in independent 64 KiB chunks (STREAM), so a
 * backup can be encrypted over several requests: the caller keeps the
 * payload key and the chunk counter in its state.
 *
 * @package RMD\BackupWordpress
 */

namespace RMD\BackupWordpress\Domain;

use InvalidArgumentException;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WordPress-free layer; messages are escaped where they are shown.

final class Age {

	public const CHUNK = 65536;

	private const VERSION     = 'age-encryption.org/v1';
	private const X25519_INFO = 'age-encryption.org/v1/X25519';

	/**
	 * Decodes and checks an "age1…" recipient. Returns the 32-byte public key.
	 */
	public static function recipient( string $recipient ): string {
		if ( str_starts_with( strtolower( trim( $recipient ) ), 'age1pq1' ) ) {
			throw new InvalidArgumentException( 'Post-quantum age keys (age1pq…) are not supported; PHP has no native ML-KEM. Create a key with age-keygen without -pq.' );
		}
		$decoded = Bech32::decode( trim( $recipient ) );
		if ( 'age' !== $decoded['hrp'] || 32 !== strlen( $decoded['data'] ) ) {
			throw new InvalidArgumentException( 'Not an age X25519 recipient (age1…).' );
		}
		return $decoded['data'];
	}

	public static function is_recipient( string $recipient ): bool {
		try {
			self::recipient( $recipient );
			return true;
		} catch ( InvalidArgumentException $e ) {
			return false;
		}
	}

	/**
	 * Starts a file: returns the bytes to write first (header and payload
	 * nonce) and the payload key for the chunks.
	 *
	 * @return array{header: string, payload_key: string}
	 */
	public static function begin( string $recipient ): array {
		$public   = self::recipient( $recipient );
		$file_key = random_bytes( 16 );

		$ephemeral_secret = random_bytes( 32 );
		$ephemeral_share  = sodium_crypto_scalarmult_base( $ephemeral_secret );
		$shared           = sodium_crypto_scalarmult( $ephemeral_secret, $public );
		if ( str_repeat( "\0", 32 ) === $shared ) {
			throw new InvalidArgumentException( 'Invalid age recipient.' );
		}
		$wrap_key = hash_hkdf( 'sha256', $shared, 32, self::X25519_INFO, $ephemeral_share . $public );
		$body     = sodium_crypto_aead_chacha20poly1305_ietf_encrypt( $file_key, '', str_repeat( "\0", 12 ), $wrap_key );

		$header  = self::VERSION . "\n";
		$header .= '-> X25519 ' . self::base64( $ephemeral_share ) . "\n";
		$header .= self::wrap( self::base64( $body ) );
		$header .= '---';
		$mac     = hash_hmac( 'sha256', $header, hash_hkdf( 'sha256', $file_key, 32, 'header', '' ), true );
		$header .= ' ' . self::base64( $mac ) . "\n";

		$nonce = random_bytes( 16 );
		return [
			'header'      => $header . $nonce,
			'payload_key' => hash_hkdf( 'sha256', $file_key, 32, 'payload', $nonce ),
		];
	}

	/**
	 * Encrypts one chunk of at most CHUNK bytes. Only the last chunk may be
	 * shorter; it must not be empty unless the whole file is empty.
	 */
	public static function chunk( string $plaintext, int $counter, bool $last, string $payload_key ): string {
		if ( strlen( $plaintext ) > self::CHUNK || ( ! $last && strlen( $plaintext ) !== self::CHUNK ) ) {
			throw new InvalidArgumentException( 'Invalid age chunk size.' );
		}
		$nonce = "\0\0\0" . pack( 'J', $counter ) . ( $last ? "\x01" : "\x00" );
		return sodium_crypto_aead_chacha20poly1305_ietf_encrypt( $plaintext, '', $nonce, $payload_key );
	}

	/**
	 * Size of the encrypted payload for a plaintext size (without header and nonce).
	 */
	public static function payload_size( int $size ): int {
		$chunks = max( 1, (int) ceil( $size / self::CHUNK ) );
		return $size + 16 * $chunks;
	}

	/**
	 * Canonical base64 without padding, as age uses it.
	 */
	public static function base64( string $data ): string {
		return rtrim( base64_encode( $data ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- age header format.
	}

	/**
	 * Stanza body lines of 64 columns; the last line is always shorter.
	 */
	private static function wrap( string $text ): string {
		$lines = str_split( $text, 64 );
		if ( '' === $text || 64 === strlen( (string) end( $lines ) ) ) {
			$lines[] = '';
		}
		return implode( "\n", $lines ) . "\n";
	}
}
