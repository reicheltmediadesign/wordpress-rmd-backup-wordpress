<?php
/**
 * Encrypts the finished archive in tmp/ with age for the recipient from the
 * settings, a few seconds per step. The payload key lives in the job state
 * only while the run is in progress.
 *
 * @package RMD\BackupWordpress
 */

namespace RMD\BackupWordpress\Backup;

use RMD\BackupWordpress\Domain\Age;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions

final class Encryptor {

	public const FILE = Archiver::ARCHIVE . '.age';

	/**
	 * The PHP extension is required: the pure-PHP fallback bundled with
	 * WordPress encrypts at well under 1 MB/s.
	 */
	public static function available(): bool {
		return extension_loaded( 'sodium' );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function init(): array {
		return [
			'in'      => 0,
			'out'     => 0,
			'counter' => 0,
			'key'     => '',
		];
	}

	/**
	 * Returns true when the encrypted file is complete. The plain archive stays in
	 * tmp/ until the run is finalized, so a repeated step can still read it.
	 *
	 * @param array<string, mixed> $job
	 */
	public static function run( array &$job, float $deadline ): bool {
		$state  = &$job['encrypt'];
		$source = Job::tmp( $job ) . '/' . Archiver::ARCHIVE;
		$target = Job::tmp( $job ) . '/' . self::FILE;
		$size   = filesize( $source );
		if ( false === $size || 0 === $size ) {
			throw new RuntimeException( esc_html__( 'The archive to encrypt is missing.', 'rmd-backup-wordpress' ) );
		}
		$state['size'] = $size;

		$in  = fopen( $source, 'rb' );
		$out = fopen( $target, 'c+b' );
		if ( false === $in || false === $out ) {
			throw new RuntimeException( esc_html__( 'The archive could not be opened for encryption.', 'rmd-backup-wordpress' ) );
		}

		try {
			ftruncate( $out, (int) $state['out'] );
			fseek( $out, 0, SEEK_END );

			if ( '' === $state['key'] ) {
				$file = Age::begin( (string) $job['recipient'] );
				self::write( $out, $file['header'] );
				$state['key'] = base64_encode( $file['payload_key'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary key in the job state.
			}
			$key = (string) base64_decode( (string) $state['key'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

			fseek( $in, (int) $state['in'] );
			while ( (int) $state['in'] < $size && microtime( true ) < $deadline ) {
				$chunk = fread( $in, Age::CHUNK );
				if ( false === $chunk || '' === $chunk ) {
					throw new RuntimeException( esc_html__( 'The archive could not be read for encryption.', 'rmd-backup-wordpress' ) );
				}
				$last = (int) $state['in'] + strlen( $chunk ) >= $size;
				self::write( $out, Age::chunk( $chunk, (int) $state['counter'], $last, $key ) );
				$state['in'] += strlen( $chunk );
				++$state['counter'];
			}
			$state['out'] = (int) ftell( $out );
		} finally {
			fclose( $in );
			fclose( $out );
		}

		if ( (int) $state['in'] < $size ) {
			return false;
		}
		$state['key'] = '';
		return true;
	}

	/**
	 * @param resource $handle
	 */
	private static function write( $handle, string $data ): void {
		if ( fwrite( $handle, $data ) !== strlen( $data ) ) {
			throw new RuntimeException( esc_html__( 'Writing the encrypted archive failed. Is the disk full?', 'rmd-backup-wordpress' ) );
		}
	}
}
