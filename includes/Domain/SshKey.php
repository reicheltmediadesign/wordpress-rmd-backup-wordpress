<?php
/**
 * SSH key pairs for the SFTP login. Every site gets its own ed25519 pair: the
 * private key stays on the site, the public key is added to the SFTP account
 * (HiDrive: Settings → Account → Open-SSH). A compromised site is locked out by
 * deleting only its public key there.
 *
 * @package RMD\BackupWordpress
 */

namespace RMD\BackupWordpress\Domain;

use InvalidArgumentException;
use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\PublicKeyLoader;
use Throwable;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WordPress-free layer; messages are escaped where they are shown.

final class SshKey {

	/**
	 * @return array{private: string, public: string} Private key in OpenSSH format, public key as one authorized_keys line.
	 */
	public static function generate( string $comment ): array {
		$key = EC::createKey( 'Ed25519' );
		return [
			'private' => $key->toString( 'OpenSSH' ),
			'public'  => self::public_line( $key, $comment ),
		];
	}

	/**
	 * Loads a private key (OpenSSH, PKCS#8 or PuTTY format, without passphrase).
	 */
	public static function load( string $private_key ): PrivateKey {
		try {
			$key = PublicKeyLoader::load( $private_key );
		} catch ( Throwable $e ) {
			throw new InvalidArgumentException( 'Not a valid SSH private key: ' . $e->getMessage() );
		}
		if ( ! $key instanceof PrivateKey ) {
			throw new InvalidArgumentException( 'Not an SSH private key.' );
		}
		return $key;
	}

	/**
	 * The authorized_keys line ("ssh-ed25519 AAAA… comment") for a private key.
	 */
	public static function public_from_private( string $private_key, string $comment ): string {
		return self::public_line( self::load( $private_key ), $comment );
	}

	/**
	 * Fingerprints of a public key as ssh-keygen -l shows them (SHA256) and as
	 * HiDrive lists keys (MD5 with colons).
	 *
	 * @return array{sha256: string, md5: string}
	 */
	public static function fingerprints( string $public_key ): array {
		try {
			$key = PublicKeyLoader::loadPublicKey( $public_key );
		} catch ( Throwable $e ) {
			throw new InvalidArgumentException( 'Not a valid SSH public key: ' . $e->getMessage() );
		}
		return [
			'sha256' => 'SHA256:' . $key->getFingerprint( 'sha256' ),
			'md5'    => (string) $key->getFingerprint( 'md5' ),
		];
	}

	private static function public_line( PrivateKey $key, string $comment ): string {
		$comment = trim( (string) preg_replace( '/[^\x21-\x7e ]+/', '', $comment ) );
		return trim( $key->getPublicKey()->toString( 'OpenSSH', [ 'comment' => $comment ] ) );
	}
}
