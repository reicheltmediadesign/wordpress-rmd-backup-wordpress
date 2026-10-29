<?php
/**
 * Connection settings of the SFTP destination (e.g. STRATO HiDrive). Every
 * value can also be set as a constant in wp-config.php, which takes
 * precedence and cannot be changed from the admin screen:
 *
 *   RMD_BWP_SFTP_HOST, RMD_BWP_SFTP_PORT, RMD_BWP_SFTP_USER,
 *   RMD_BWP_SFTP_AUTH ("key" or "password"), RMD_BWP_SFTP_PRIVATE_KEY,
 *   RMD_BWP_SFTP_PASSWORD, RMD_BWP_SFTP_PATH, RMD_BWP_SFTP_FOLDER,
 *   RMD_BWP_SFTP_FINGERPRINT
 *
 * The login uses an SSH key by default: the site creates its own ed25519 pair
 * and only the public key is added to the account. A password login remains
 * possible for servers without key support; with HiDrive 2FA only the key works.
 *
 * @package RMD\BackupWordpress
 */

namespace RMD\BackupWordpress\Destination;

use RMD\BackupWordpress\Domain\ArchiveName;
use RMD\BackupWordpress\Domain\SshKey;
use RMD\BackupWordpress\Secret;

defined( 'ABSPATH' ) || exit;

final class SftpSettings {

	public const OPTION = 'rmd_bwp_sftp';

	public const DEFAULT_HOST = 'sftp.hidrive.strato.com';

	/** Base folder in the account, so a sync tool can take exactly this folder. */
	public const DEFAULT_PATH = 'backups';

	public const AUTH_KEY      = 'key';
	public const AUTH_PASSWORD = 'password';

	private const FIELDS = [ 'host', 'port', 'user', 'auth', 'private_key', 'password', 'path', 'folder', 'fingerprint' ];

	/** Stored encrypted with Secret. */
	private const SEALED = [ 'private_key', 'password' ];

	/**
	 * Effective settings; secrets are decrypted ("" if they cannot be).
	 *
	 * @return array{host: string, port: int, user: string, auth: string, private_key: string, password: string, path: string, folder: string, fingerprint: string}
	 */
	public static function get(): array {
		$stored = self::stored();

		$values = [];
		foreach ( self::FIELDS as $field ) {
			$values[ $field ] = isset( $stored[ $field ] ) && is_scalar( $stored[ $field ] ) ? trim( (string) $stored[ $field ] ) : '';
		}
		foreach ( self::SEALED as $field ) {
			$values[ $field ] = (string) Secret::open( $values[ $field ] );
		}

		// Installations from 0.1.0 have a password but no key: keep using it.
		if ( ! in_array( $values['auth'], [ self::AUTH_KEY, self::AUTH_PASSWORD ], true ) ) {
			$values['auth'] = '' !== $values['password'] && '' === $values['private_key'] ? self::AUTH_PASSWORD : self::AUTH_KEY;
		}

		foreach ( self::FIELDS as $field ) {
			if ( self::locked( $field ) ) {
				$values[ $field ] = trim( (string) constant( self::constant( $field ) ) );
			}
		}
		if ( ! in_array( $values['auth'], [ self::AUTH_KEY, self::AUTH_PASSWORD ], true ) ) {
			$values['auth'] = self::AUTH_KEY;
		}

		$port = (int) $values['port'];
		return [
			'host'        => '' === $values['host'] ? self::DEFAULT_HOST : $values['host'],
			'port'        => $port > 0 && $port < 65536 ? $port : 22,
			'user'        => $values['user'],
			'auth'        => $values['auth'],
			'private_key' => $values['private_key'],
			'password'    => $values['password'],
			'path'        => '' === $values['path'] ? self::DEFAULT_PATH : $values['path'],
			'folder'      => '' === $values['folder'] ? ArchiveName::site( home_url() ) : $values['folder'],
			'fingerprint' => $values['fingerprint'],
		];
	}

	/**
	 * Whether a stored password exists but cannot be decrypted (salts changed).
	 */
	public static function password_unreadable(): bool {
		return self::unreadable( 'password' );
	}

	/**
	 * Whether a stored private key exists but cannot be decrypted (salts changed).
	 */
	public static function private_key_unreadable(): bool {
		return self::unreadable( 'private_key' );
	}

	public static function locked( string $field ): bool {
		$constant = self::constant( $field );
		return defined( $constant ) && is_scalar( constant( $constant ) ) && '' !== trim( (string) constant( $constant ) );
	}

	/**
	 * Comment of this site's public key, so it can be recognised in the account.
	 */
	public static function key_comment(): string {
		return 'rmd-backup ' . ArchiveName::site( home_url() );
	}

	/**
	 * The public key (authorized_keys line) of the effective private key, or "".
	 */
	public static function public_key(): string {
		$private = self::get()['private_key'];
		if ( '' === $private ) {
			return '';
		}
		try {
			return SshKey::public_from_private( $private, self::key_comment() );
		} catch ( \InvalidArgumentException $e ) {
			return '';
		}
	}

	/**
	 * Creates a new key pair for this site and returns its public key. The
	 * previous key stops working here; remove it from the account.
	 */
	public static function generate_key(): string {
		$pair                  = SshKey::generate( self::key_comment() );
		$stored                = self::stored();
		$stored['private_key'] = Secret::seal( $pair['private'] );
		$stored['auth']        = self::AUTH_KEY;
		update_option( self::OPTION, $stored, false );
		return $pair['public'];
	}

	/**
	 * Saves the form. An empty password keeps the stored one. Changing host
	 * or port forgets the pinned host key. The private key is only changed by
	 * generate_key().
	 *
	 * @param array<mixed> $raw
	 * @return list<string> Error codes.
	 */
	public static function save( array $raw ): array {
		$stored = self::stored();
		$errors = [];
		$value  = static fn( string $key ): string => isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ? trim( (string) $raw[ $key ] ) : '';

		$host = strtolower( $value( 'host' ) );
		if ( '' !== $host && ! preg_match( '/^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?$/', $host ) ) {
			$errors[] = 'invalid_host';
			$host     = (string) ( $stored['host'] ?? '' );
		}
		$port = $value( 'port' );
		if ( '' !== $port && ( ! ctype_digit( $port ) || (int) $port < 1 || (int) $port > 65535 ) ) {
			$errors[] = 'invalid_port';
			$port     = (string) ( $stored['port'] ?? '' );
		}
		$user = $value( 'user' );
		if ( '' !== $user && ! preg_match( '/^[A-Za-z0-9._@-]{1,100}$/', $user ) ) {
			$errors[] = 'invalid_user';
			$user     = (string) ( $stored['user'] ?? '' );
		}
		$auth = $value( 'auth' );
		if ( ! in_array( $auth, [ self::AUTH_KEY, self::AUTH_PASSWORD ], true ) ) {
			$auth = (string) ( $stored['auth'] ?? '' );
		}
		$path = str_replace( '\\', '/', $value( 'path' ) );
		if ( str_contains( '/' . $path . '/', '/../' ) ) {
			$errors[] = 'invalid_path';
			$path     = (string) ( $stored['path'] ?? '' );
		}
		$folder = strtolower( $value( 'folder' ) );
		if ( '' !== $folder && ! preg_match( '/^[a-z0-9][a-z0-9._-]{0,79}$/', $folder ) ) {
			$errors[] = 'invalid_folder';
			$folder   = (string) ( $stored['folder'] ?? '' );
		}

		$password = isset( $raw['password'] ) && is_string( $raw['password'] ) ? $raw['password'] : '';
		$sealed   = '' === $password ? (string) ( $stored['password'] ?? '' ) : Secret::seal( $password );
		if ( ! empty( $raw['forget_password'] ) ) {
			$sealed = '';
		}

		$fingerprint = (string) ( $stored['fingerprint'] ?? '' );
		if ( (string) ( $stored['host'] ?? '' ) !== $host || (string) ( $stored['port'] ?? '' ) !== $port ) {
			$fingerprint = '';
		}

		update_option(
			self::OPTION,
			[
				'host'        => $host,
				'port'        => $port,
				'user'        => $user,
				'auth'        => $auth,
				'private_key' => (string) ( $stored['private_key'] ?? '' ),
				'password'    => $sealed,
				'path'        => rtrim( $path, '/' ),
				'folder'      => $folder,
				'fingerprint' => $fingerprint,
			],
			false
		);
		return $errors;
	}

	public static function save_fingerprint( string $fingerprint ): void {
		$stored                = self::stored();
		$stored['fingerprint'] = $fingerprint;
		update_option( self::OPTION, $stored, false );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function stored(): array {
		$stored = get_option( self::OPTION, [] );
		return is_array( $stored ) ? $stored : [];
	}

	private static function unreadable( string $field ): bool {
		$stored = self::stored();
		$sealed = isset( $stored[ $field ] ) && is_scalar( $stored[ $field ] ) ? (string) $stored[ $field ] : '';
		return '' !== $sealed && null === Secret::open( $sealed ) && ! self::locked( $field );
	}

	private static function constant( string $field ): string {
		return 'RMD_BWP_SFTP_' . strtoupper( $field );
	}
}
