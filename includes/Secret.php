<?php
/**
 * Encrypts small secrets (the SFTP password) before they are stored in the
 * database, with a key derived from the salts in wp-config.php. This protects
 * against leaks of the database alone (SQL injection, database dumps); whoever
 * can read wp-config.php can decrypt it. For the strongest setup define the
 * credentials as constants in wp-config.php instead.
 *
 * @package RMD\BackupWordpress
 */

namespace RMD\BackupWordpress;

use SodiumException;

defined( 'ABSPATH' ) || exit;

final class Secret {

	private const PREFIX = 'sb1:';

	public static function seal( string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return self::PREFIX . base64_encode( $nonce . sodium_crypto_secretbox( $plaintext, $nonce, self::key() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary ciphertext in an option.
	}

	/**
	 * @return string|null Null when the value cannot be decrypted (e.g. the salts changed).
	 */
	public static function open( string $sealed ): ?string {
		if ( '' === $sealed ) {
			return '';
		}
		if ( ! str_starts_with( $sealed, self::PREFIX ) ) {
			return null;
		}
		$raw = base64_decode( substr( $sealed, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		try {
			$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::key() );
		} catch ( SodiumException $e ) {
			return null;
		}
		return false === $plain ? null : $plain;
	}

	private static function key(): string {
		return hash( 'sha256', 'rmd-bwp-secret|' . wp_salt( 'secure_auth' ), true );
	}
}
