<?php
/**
 * The SQL dump in tmp/, written over several steps. With zlib every step
 * appends one complete gzip member; concatenated members form a valid .gz
 * file (RFC 1952) that gunzip, 7-Zip and phpMyAdmin read as one. The file is
 * cut to the length stored in the job state first, so an interrupted step
 * leaves nothing behind.
 *
 * @package RMD\BackupWordpress
 */

namespace RMD\BackupWordpress\Backup;

use DeflateContext;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions

final class DumpFile {

	/** @var resource */
	private $handle;

	private ?DeflateContext $deflate;

	public function __construct( string $path, int $length ) {
		$handle = fopen( $path, 'c+b' );
		if ( false === $handle ) {
			throw new RuntimeException(
				esc_html(
					sprintf(
						/* translators: %s: file path */
						__( 'Could not open %s for writing.', 'rmd-backup-wordpress' ),
						$path
					)
				)
			);
		}
		ftruncate( $handle, $length );
		fseek( $handle, 0, SEEK_END );
		$this->handle  = $handle;
		$this->deflate = self::compressed() ? deflate_init( ZLIB_ENCODING_GZIP, [ 'level' => 6 ] ) : null;
	}

	/**
	 * Whether dumps are gzip-compressed on this server.
	 */
	public static function compressed(): bool {
		return function_exists( 'deflate_init' );
	}

	public static function name(): string {
		return self::compressed() ? 'database.sql.gz' : 'database.sql';
	}

	public function write( string $data ): void {
		$this->put( null === $this->deflate ? $data : (string) deflate_add( $this->deflate, $data, ZLIB_NO_FLUSH ) );
	}

	/**
	 * Ends the step's gzip member and returns the file length to store.
	 */
	public function close(): int {
		try {
			if ( null !== $this->deflate ) {
				$this->put( (string) deflate_add( $this->deflate, '', ZLIB_FINISH ) );
			}
			return (int) ftell( $this->handle );
		} finally {
			fclose( $this->handle );
		}
	}

	private function put( string $data ): void {
		if ( '' !== $data && fwrite( $this->handle, $data ) !== strlen( $data ) ) {
			throw new RuntimeException( esc_html__( 'Writing the database dump failed. Is the disk full?', 'rmd-backup-wordpress' ) );
		}
	}
}
