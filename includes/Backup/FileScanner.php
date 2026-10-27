<?php
/**
 * Collects the files of a run into a manifest in tmp/ (one line per entry), so
 * the archive step can work through it across requests:
 *
 *   F <tab> name in archive <tab> absolute path
 *   E <tab> name in archive <tab> absolute path   (empty folder)
 *
 * @package RMD\BackupWordpress
 */

namespace RMD\BackupWordpress\Backup;

use RMD\BackupWordpress\Domain\Layout;
use RMD\BackupWordpress\Domain\PathFilter;
use RMD\BackupWordpress\Domain\Profile;
use RMD\BackupWordpress\Storage;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions

final class FileScanner {

	public const MANIFEST = 'files.txt';

	public static function layout(): Layout {
		$root    = untrailingslashit( wp_normalize_path( ABSPATH ) );
		$uploads = wp_upload_dir( null, false );
		$config  = null;
		if ( file_exists( $root . '/wp-config.php' ) ) {
			$config = $root . '/wp-config.php';
		} elseif ( file_exists( dirname( $root ) . '/wp-config.php' ) && ! file_exists( dirname( $root ) . '/wp-settings.php' ) ) {
			$config = dirname( $root ) . '/wp-config.php';
		}

		return new Layout(
			$root,
			untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ),
			untrailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) ),
			untrailingslashit( wp_normalize_path( WPMU_PLUGIN_DIR ) ),
			untrailingslashit( wp_normalize_path( get_theme_root() ) ),
			untrailingslashit( wp_normalize_path( (string) $uploads['basedir'] ) ),
			$config
		);
	}

	/**
	 * Initial scan state of a job.
	 *
	 * @return array<string, mixed>
	 */
	public static function init( Profile $profile ): array {
		$stack = [];
		foreach ( self::layout()->starts( $profile->parts ) as $start ) {
			$stack[] = [ $start['path'], $start['part'], $start['dir'] ];
		}
		return [
			'stack'    => array_reverse( $stack ),
			'bytes'    => 0,
			'files'    => 0,
			'size'     => 0,
			'excluded' => 0,
		];
	}

	/**
	 * @param array<string, mixed> $job
	 */
	public static function scan( array &$job, Profile $profile, float $deadline ): void {
		$scan    = &$job['scan'];
		$layout  = self::layout();
		$filter  = PathFilter::from_lines( $profile->exclude_patterns );
		$storage = strtolower( Storage::root() );
		$handle  = self::open( Job::tmp( $job ) . '/' . self::MANIFEST, (int) $scan['bytes'] );

		try {
			while ( [] !== $scan['stack'] && microtime( true ) < $deadline ) {
				[ $path, $part, $is_dir ] = array_pop( $scan['stack'] );

				if ( ! $is_dir ) {
					if ( is_readable( $path ) ) {
						self::add_file( $handle, $scan, $layout->archive_path( $path ), $path );
					} else {
						Job::warn( $job, sprintf( 'File could not be read and was skipped: %s', $path ) );
					}
					continue;
				}

				$entries = @scandir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Reported as a warning below.
				if ( false === $entries ) {
					Job::warn( $job, sprintf( 'Folder could not be read and was skipped: %s', $path ) );
					continue;
				}

				$included = 0;
				foreach ( $entries as $name ) {
					if ( '.' === $name || '..' === $name ) {
						continue;
					}
					$abs = $path . '/' . $name;
					if ( strtolower( $abs ) === $storage ) {
						continue;
					}
					$archive = $layout->archive_path( $abs );

					if ( is_dir( $abs ) ) {
						$child = $layout->folder_part( $abs, $part );
						if ( ! $layout->needed( $abs, $child, $profile->parts ) ) {
							continue;
						}
						if ( $filter->matches( $archive, true ) ) {
							++$scan['excluded'];
							continue;
						}
						if ( self::is_loop( $abs ) ) {
							Job::warn( $job, sprintf( 'Linked folder points to one of its parents and was skipped: %s', $abs ) );
							continue;
						}
						$scan['stack'][] = [ $abs, $child, true ];
						++$included;
						continue;
					}

					if ( ! in_array( $layout->file_part( $abs, $part ), $profile->parts, true ) ) {
						continue;
					}
					if ( $filter->matches( $archive, false ) ) {
						++$scan['excluded'];
						continue;
					}
					if ( strpbrk( $name, "\t\r\n" ) !== false ) {
						Job::warn( $job, sprintf( 'File name contains control characters and was skipped: %s', $abs ) );
						continue;
					}
					if ( ! is_readable( $abs ) ) {
						Job::warn( $job, sprintf( 'File could not be read and was skipped: %s', $abs ) );
						continue;
					}
					self::add_file( $handle, $scan, $archive, $abs );
					++$included;
				}

				if ( 0 === $included && in_array( $part, $profile->parts, true ) && ! Layout::same( $path, $layout->root ) ) {
					self::write( $handle, "E\t" . $layout->archive_path( $path ) . "\t" . $path . "\n" );
				}
				$scan['bytes'] = (int) ftell( $handle );
			}
			$scan['bytes'] = (int) ftell( $handle );
		} finally {
			fclose( $handle );
		}
	}

	/**
	 * @param resource             $handle
	 * @param array<string, mixed> $scan
	 */
	private static function add_file( $handle, array &$scan, string $archive, string $path ): void {
		self::write( $handle, "F\t" . $archive . "\t" . $path . "\n" );
		++$scan['files'];
		$scan['size'] += (int) filesize( $path );
	}

	/**
	 * A symlink or junction that points to the folder itself or one of its parents.
	 */
	private static function is_loop( string $path ): bool {
		if ( ! is_link( $path ) ) {
			return false;
		}
		$target = realpath( $path );
		if ( false === $target ) {
			return true;
		}
		$target = strtolower( untrailingslashit( wp_normalize_path( $target ) ) );
		$parent = strtolower( wp_normalize_path( dirname( $path ) ) );
		return $parent === $target || str_starts_with( $parent . '/', $target . '/' );
	}

	/**
	 * @return resource
	 */
	private static function open( string $path, int $length ) {
		$handle = fopen( $path, 'c+b' );
		if ( false === $handle ) {
			throw new RuntimeException( esc_html__( 'The file list could not be written.', 'rmd-backup-wordpress' ) );
		}
		ftruncate( $handle, $length );
		fseek( $handle, 0, SEEK_END );
		return $handle;
	}

	/**
	 * @param resource $handle
	 */
	private static function write( $handle, string $data ): void {
		if ( false === fwrite( $handle, $data ) ) {
			throw new RuntimeException( esc_html__( 'Writing the file list failed. Is the disk full?', 'rmd-backup-wordpress' ) );
		}
	}
}
