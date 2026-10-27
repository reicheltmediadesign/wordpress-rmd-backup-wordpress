<?php
/**
 * Connection settings of the SFTP destination (e.g. STRATO HiDrive). Every
 * value can also be set as a constant in wp-config.php, which takes
 * precedence and cannot be changed from the admin screen:
 *
 *   RMD_BWP_SFTP_HOST, RMD_BWP_SFTP_PORT, RMD_BWP_SFTP_USER,
 *   RMD_BWP_SFTP_PASSWORD, RMD_BWP_SFTP_PATH, RMD_BWP_SFTP_FOLDER,
 *   RMD_BWP_SFTP_FINGERPRINT
 *
 * @package RMD\BackupWordpress
 */

namespace RMD\BackupWordpress\Destination;

use RMD\BackupWordpress\Domain\ArchiveName;
use RMD\BackupWordpress\Secret;

defined( 'ABSPATH' ) || exit;

final class SftpSettings {

	public const OPTION = 'rmd_bwp_sftp';

	public const DEFAULT_HOST = 'sftp.hidrive.strato.com';

	private const FIELDS = [ 'host', 'port', 'user', 'password', 'path', 'folder', 'fingerprint' ];

	/**
	 * Effective settings; the password is decrypted ("" if it cannot be).
	 *
	 * @return array{host: string, port: int, user: string, password: string, path: string, folder: string, fingerprint: string}
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];

		$values = [];
		foreach ( self::FIELDS as $field ) {
			$values[ $field ] = isset( $stored[ $field ] ) && is_scalar( $stored[ $field ] ) ? trim( (string) $stored[ $field ] ) : '';
		}
		$values['password'] = (string) Secret::open( $values['password'] );

		foreach ( self::FIELDS as $field ) {
			if ( self::locked( $field ) ) {
				$values[ $field ] = trim( (string) constant( self::constant( $field ) ) );
			}
		}

		$port = (int) $values['port'];
		return [
			'host'        => '' === $values['host'] ? self::DEFAULT_HOST : $values['host'],
			'port'        => $port > 0 && $port < 65536 ? $port : 22,
			'user'        => $values['user'],
			'password'    => $values['password'],
			'path'        => $values['path'],
			'folder'      => '' === $values['folder'] ? ArchiveName::site( home_url() ) : $values['folder'],
			'fingerprint' => $values['fingerprint'],
		];
	}

	/**
	 * Whether a stored password exists but cannot be decrypted (salts changed).
	 */
	public static function password_unreadable(): bool {
		$stored = get_option( self::OPTION, [] );
		$sealed = is_array( $stored ) && isset( $stored['password'] ) ? (string) $stored['password'] : '';
		return '' !== $sealed && null === Secret::open( $sealed ) && ! self::locked( 'password' );
	}

	public static function locked( string $field ): bool {
		$constant = self::constant( $field );
		return defined( $constant ) && is_scalar( constant( $constant ) ) && '' !== trim( (string) constant( $constant ) );
	}

	/**
	 * Saves the form. An empty password keeps the stored one. Changing host
	 * or port forgets the pinned host key.
	 *
	 * @param array<mixed> $raw
	 * @return list<string> Error codes.
	 */
	public static function save( array $raw ): array {
		$stored = get_option( self::OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];
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
		$stored                = get_option( self::OPTION, [] );
		$stored                = is_array( $stored ) ? $stored : [];
		$stored['fingerprint'] = $fingerprint;
		update_option( self::OPTION, $stored, false );
	}

	private static function constant( string $field ): string {
		return 'RMD_BWP_SFTP_' . strtoupper( $field );
	}
}
