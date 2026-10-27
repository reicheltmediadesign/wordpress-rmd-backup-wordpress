<?php
/**
 * Builds the zip in tmp/ from the dump and the manifest, a few seconds per
 * step. The ZipWriter state and the manifest position are saved together with
 * the job, so a step that dies is simply repeated.
 *
 * @package RMD\BackupWordpress
 */

namespace RMD\BackupWordpress\Backup;

use RMD\BackupWordpress\Domain\Compression;
use RMD\BackupWordpress\Domain\ZipWriter;
use RuntimeException;
use UnexpectedValueException;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions

final class Archiver {

	public const ARCHIVE   = 'archive.zip';
	public const DIRECTORY = 'archive.dir';

	/**
	 * @return array<string, mixed>
	 */
	public static function init(): array {
		return [
			'offset'          => 0,
			'bytes'           => 0,
			'directory_bytes' => 0,
			'entries'         => 0,
			'files'           => 0,
			'read'            => 0,
			'database'        => false,
		];
	}

	/**
	 * Returns true when the archive is complete.
	 *
	 * @param array<string, mixed> $job
	 * @param callable(): string   $info Builds backup-info.json, called at the end.
	 */
	public static function run( array &$job, float $deadline, callable $info ): bool {
		$state  = &$job['archive'];
		$tmp    = Job::tmp( $job );
		$writer = new ZipWriter( $tmp . '/' . self::ARCHIVE, $tmp . '/' . self::DIRECTORY, (int) $state['bytes'], (int) $state['directory_bytes'], (int) $state['entries'] );

		$finished = false;
		try {
			if ( isset( $job['db'] ) && ! $state['database'] ) {
				$dump = $tmp . '/' . DumpFile::name();
				$writer->add_file( $dump, DumpFile::name(), DumpFile::compressed(), time() );
				$state['database'] = true;
			}

			if ( isset( $job['scan'] ) && ! self::add_files( $job, $writer, $deadline ) ) {
				return false;
			}

			$writer->add_string( 'backup-info.json', $info(), time() );
			$writer->finish();
			$finished = true;
			return true;
		} finally {
			if ( ! $finished ) {
				$saved = $writer->state();
				$writer->close();
				$state['bytes']           = $saved['bytes'];
				$state['directory_bytes'] = $saved['directory_bytes'];
				$state['entries']         = $saved['entries'];
			}
		}
	}

	/**
	 * Adds manifest entries until the time is up. Returns true when all are done.
	 *
	 * @param array<string, mixed> $job
	 */
	private static function add_files( array &$job, ZipWriter $writer, float $deadline ): bool {
		$state    = &$job['archive'];
		$manifest = fopen( Job::tmp( $job ) . '/' . FileScanner::MANIFEST, 'rb' );
		if ( false === $manifest ) {
			throw new RuntimeException( esc_html__( 'The file list of this backup is missing.', 'rmd-backup-wordpress' ) );
		}

		try {
			fseek( $manifest, (int) $state['offset'] );
			while ( microtime( true ) < $deadline ) {
				$line = fgets( $manifest );
				if ( false === $line ) {
					return true;
				}
				$parts = explode( "\t", rtrim( $line, "\n" ), 3 );
				if ( 3 === count( $parts ) ) {
					[ $type, $name, $path ] = $parts;
					if ( 'E' === $type ) {
						$writer->add_folder( $name, (int) @filemtime( $path ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A missing folder only loses its time.
					} elseif ( 'F' === $type ) {
						self::add_file( $job, $writer, $name, $path );
					}
				}
				$state['offset'] = (int) ftell( $manifest );
			}
			return false;
		} finally {
			fclose( $manifest );
		}
	}

	/**
	 * @param array<string, mixed> $job
	 */
	private static function add_file( array &$job, ZipWriter $writer, string $name, string $path ): void {
		clearstatcache( true, $path );
		$size  = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Reported as a warning below.
		$mtime = @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $size || false === $mtime || ! is_readable( $path ) ) {
			Job::warn( $job, sprintf( 'File disappeared or became unreadable during the backup: %s', $path ) );
			return;
		}

		try {
			$job['archive']['read'] += $writer->add_file( $path, $name, Compression::store_only( $name, $size ), $mtime );
			++$job['archive']['files'];
		} catch ( UnexpectedValueException $e ) {
			Job::warn( $job, $e->getMessage() );
		}
	}
}
