<?php
/**
 * Archives in the backups/ folder of the storage folder.
 *
 * @package RMD\BackupWordpress
 */

namespace RMD\BackupWordpress\Destination;

use RMD\BackupWordpress\Domain\ArchiveName;
use RMD\BackupWordpress\Log\Logger;
use RMD\BackupWordpress\Storage;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions

final class LocalDestination implements Destination {

	public function label(): string {
		return __( 'Local folder', 'rmd-backup-wordpress' );
	}

	public function store( string $file, string $name, Logger $log ): void {
		$target = Storage::dir( Storage::BACKUPS ) . '/' . $name;
		if ( ! rename( $file, $target ) && ! ( copy( $file, $target ) && unlink( $file ) ) ) {
			throw new RuntimeException(
				esc_html(
					sprintf(
						/* translators: %s: folder path */
						__( 'The archive could not be moved to %s.', 'rmd-backup-wordpress' ),
						$target
					)
				)
			);
		}
		$log->info( sprintf( 'Archive saved: %s', wp_normalize_path( $target ) ) );
	}

	public function backups(): array {
		$dir = Storage::dir( Storage::BACKUPS );
		if ( ! is_dir( $dir ) ) {
			return [];
		}
		$backups = [];
		foreach ( (array) scandir( $dir ) as $name ) {
			$parsed = ArchiveName::parse( (string) $name );
			if ( null === $parsed || ! ArchiveName::is_archive( (string) $name ) ) {
				continue;
			}
			$path      = $dir . '/' . $name;
			$backups[] = [
				'name'    => (string) $name,
				'id'      => $parsed['id'],
				'profile' => $parsed['profile'],
				'size'    => (int) filesize( $path ),
				'created' => (int) filemtime( $path ),
			];
		}
		usort( $backups, static fn( array $a, array $b ): int => strcmp( $b['name'], $a['name'] ) );
		return $backups;
	}

	public function delete( string $name ): void {
		$path = $this->path( $name );
		if ( null !== $path && ! unlink( $path ) ) {
			throw new RuntimeException(
				esc_html(
					sprintf(
						/* translators: %s: file name */
						__( 'Could not delete %s.', 'rmd-backup-wordpress' ),
						$name
					)
				)
			);
		}
	}

	/**
	 * Path of an archive, or null if the name is invalid or the file is missing.
	 */
	public function path( string $name ): ?string {
		$path = Storage::dir( Storage::BACKUPS ) . '/' . $name;
		return ArchiveName::is_archive( $name ) && is_file( $path ) ? $path : null;
	}
}
