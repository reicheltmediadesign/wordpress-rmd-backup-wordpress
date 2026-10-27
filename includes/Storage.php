<?php
/**
 * The storage folder wp-content/rmd-backup-wordpress with backups/, logs/ and
 * tmp/, protected against web access. RMD_BWP_STORAGE_DIR in wp-config.php
 * moves it, e.g. outside the web root.
 *
 * @package RMD\BackupWordpress
 */

namespace RMD\BackupWordpress;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

// Archives can be several gigabytes and are streamed in chunks; WP_Filesystem is not suited.
// phpcs:disable WordPress.WP.AlternativeFunctions

final class Storage {

	public const BACKUPS = 'backups';
	public const LOGS    = 'logs';
	public const TMP     = 'tmp';

	public static function root(): string {
		$dir = defined( 'RMD_BWP_STORAGE_DIR' ) && is_string( RMD_BWP_STORAGE_DIR ) && '' !== trim( RMD_BWP_STORAGE_DIR )
			? RMD_BWP_STORAGE_DIR
			: WP_CONTENT_DIR . '/rmd-backup-wordpress';
		return untrailingslashit( wp_normalize_path( $dir ) );
	}

	public static function dir( string $sub ): string {
		return self::root() . '/' . $sub;
	}

	/**
	 * Creates the folders with deny rules for Apache and IIS. On nginx only the
	 * random part of the file names protects the archives.
	 */
	public static function prepare(): void {
		$root = self::root();
		foreach ( [ $root, self::dir( self::BACKUPS ), self::dir( self::LOGS ), self::dir( self::TMP ) ] as $dir ) {
			if ( ! wp_mkdir_p( $dir ) || ! wp_is_writable( $dir ) ) {
				throw new RuntimeException(
					esc_html(
						sprintf(
							/* translators: %s: folder path */
							__( 'The folder %s could not be created or is not writable.', 'rmd-backup-wordpress' ),
							$dir
						)
					)
				);
			}
			self::put_once( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
		}
		self::put_once( $root . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
		self::put_once( $root . '/web.config', "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n\t<system.webServer>\n\t\t<authorization>\n\t\t\t<deny users=\"*\" />\n\t\t</authorization>\n\t</system.webServer>\n</configuration>\n" );
	}

	/**
	 * Deletes a folder below the storage folder with everything in it.
	 */
	public static function delete_tree( string $path ): void {
		$path = untrailingslashit( wp_normalize_path( $path ) );
		if ( ! str_starts_with( strtolower( $path ), strtolower( self::root() ) . '/' ) ) {
			throw new RuntimeException( esc_html( 'Refusing to delete outside the storage folder: ' . $path ) );
		}
		if ( ! is_dir( $path ) ) {
			return;
		}

		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			/** @var \SplFileInfo $item */
			$ok = $item->isDir() && ! $item->isLink() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
			if ( ! $ok ) {
				throw new RuntimeException(
					esc_html(
						sprintf(
							/* translators: %s: file path */
							__( 'Could not delete %s.', 'rmd-backup-wordpress' ),
							$item->getPathname()
						)
					)
				);
			}
		}
		rmdir( $path );
	}

	public static function free_space(): ?int {
		if ( ! function_exists( 'disk_free_space' ) ) {
			return null;
		}
		$free = @disk_free_space( is_dir( self::root() ) ? self::root() : WP_CONTENT_DIR ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Often disabled or restricted by open_basedir; null is handled.
		return false === $free ? null : (int) $free;
	}

	/**
	 * Sends a file to the browser in chunks and ends the request.
	 */
	public static function send( string $path, string $type ): never {
		$size   = filesize( $path );
		$handle = fopen( $path, 'rb' );
		if ( false === $size || false === $handle ) {
			wp_die( esc_html__( 'The file could not be read.', 'rmd-backup-wordpress' ), 500 );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 );
		}
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: ' . $type );
		header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
		header( 'Content-Length: ' . $size );
		header( 'X-Content-Type-Options: nosniff' );

		while ( ! feof( $handle ) ) {
			echo fread( $handle, 1024 * 1024 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- File download.
			flush();
		}
		fclose( $handle );
		exit;
	}

	private static function put_once( string $path, string $content ): void {
		if ( ! file_exists( $path ) && false === file_put_contents( $path, $content ) ) {
			throw new RuntimeException(
				esc_html(
					sprintf(
						/* translators: %s: file path */
						__( 'Could not write %s.', 'rmd-backup-wordpress' ),
						$path
					)
				)
			);
		}
	}
}
