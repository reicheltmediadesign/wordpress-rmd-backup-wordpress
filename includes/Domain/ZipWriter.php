<?php
/**
 * Appending zip writer that can be continued in a later request.
 *
 * ZipArchive rewrites the whole archive every time it is reopened, which makes
 * a backup split into many short steps slow for large sites. This writer only
 * appends: each entry's local header and data go to the archive file, its
 * central directory record to a side file. The caller stores state() after a
 * step; the next step opens both files cut to these lengths, so an interrupted
 * step leaves nothing behind. finish() appends the central directory.
 *
 * Supports Zip64 (files and archives over 4 GB, more than 65535 entries),
 * UTF-8 names and the extended timestamp field for exact modification times.
 *
 * @package RMD\BackupWordpress
 */

namespace RMD\BackupWordpress\Domain;

use RuntimeException;
use UnexpectedValueException;

// Plain file streams in the WordPress-free layer.
// phpcs:disable WordPress.WP.AlternativeFunctions
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Messages go to the log file, never into HTML unescaped.

final class ZipWriter {

	private const CHUNK          = 1024 * 1024;
	private const MAX_32         = 0xFFFFFFFF;
	private const MAX_16         = 0xFFFF;
	private const FLAG_UTF8      = 0x0800;
	private const METHOD_STORE   = 0;
	private const METHOD_DEFLATE = 8;
	private const VERSION        = 20;
	private const VERSION_ZIP64  = 45;

	/** @var resource */
	private $archive;

	/** @var resource */
	private $directory;

	/**
	 * @param int $zip64_limit Sizes and offsets from this value on use Zip64 fields (lower only in tests).
	 */
	public function __construct(
		private readonly string $archive_path,
		private readonly string $directory_path,
		int $archive_bytes = 0,
		int $directory_bytes = 0,
		private int $entries = 0,
		private readonly int $zip64_limit = self::MAX_32
	) {
		$this->archive   = self::open( $archive_path, $archive_bytes );
		$this->directory = self::open( $directory_path, $directory_bytes );
	}

	/**
	 * Lengths and entry count to pass to the constructor when continuing.
	 *
	 * @return array{bytes: int, directory_bytes: int, entries: int}
	 */
	public function state(): array {
		return [
			'bytes'           => (int) ftell( $this->archive ),
			'directory_bytes' => (int) ftell( $this->directory ),
			'entries'         => $this->entries,
		];
	}

	/**
	 * Adds a file and returns the number of bytes read from it.
	 */
	public function add_file( string $source, string $name, bool $store, int $mtime ): int {
		$size  = filesize( $source );
		$input = fopen( $source, 'rb' );
		if ( false === $size || false === $input ) {
			throw new UnexpectedValueException( 'File could not be read: ' . $source );
		}

		$zip64  = $size >= $this->zip64_limit;
		$method = $store || $zip64 ? self::METHOD_STORE : self::METHOD_DEFLATE;
		$offset = (int) ftell( $this->archive );
		$this->write( $this->archive, $this->local_header( $name, $method, $mtime, $zip64 ) );

		$crc     = hash_init( 'crc32b' );
		$deflate = self::METHOD_DEFLATE === $method ? deflate_init( ZLIB_ENCODING_RAW, [ 'level' => 6 ] ) : null;
		$read    = 0;
		$written = 0;
		try {
			while ( ! feof( $input ) ) {
				$chunk = fread( $input, self::CHUNK );
				if ( false === $chunk ) {
					throw new UnexpectedValueException( 'File could not be read: ' . $source );
				}
				if ( '' === $chunk ) {
					continue;
				}
				hash_update( $crc, $chunk );
				$read += strlen( $chunk );
				$out   = null === $deflate ? $chunk : (string) deflate_add( $deflate, $chunk, ZLIB_NO_FLUSH );
				$this->write( $this->archive, $out );
				$written += strlen( $out );
			}
			if ( null !== $deflate ) {
				$out = (string) deflate_add( $deflate, '', ZLIB_FINISH );
				$this->write( $this->archive, $out );
				$written += strlen( $out );
			}
			if ( ! $zip64 && ( $read >= $this->zip64_limit || $written >= $this->zip64_limit ) ) {
				throw new UnexpectedValueException( 'File grew beyond 4 GB while it was archived: ' . $source );
			}
		} catch ( RuntimeException $e ) {
			ftruncate( $this->archive, $offset );
			fseek( $this->archive, 0, SEEK_END );
			throw $e;
		} finally {
			fclose( $input );
		}

		$checksum = self::crc( $crc );
		$this->patch_local_header( $offset, $name, $checksum, $written, $read, $zip64 );
		$this->add_directory_record( $name, $method, $mtime, $checksum, $written, $read, $offset, false );
		return $read;
	}

	/**
	 * Adds a file from a string (small generated files such as backup-info.json).
	 */
	public function add_string( string $name, string $data, int $mtime ): void {
		$compressed = (string) gzdeflate( $data, 6 );
		$offset     = (int) ftell( $this->archive );
		$this->write( $this->archive, $this->local_header( $name, self::METHOD_DEFLATE, $mtime, false ) );
		$this->write( $this->archive, $compressed );
		$checksum = (int) crc32( $data );
		$this->patch_local_header( $offset, $name, $checksum, strlen( $compressed ), strlen( $data ), false );
		$this->add_directory_record( $name, self::METHOD_DEFLATE, $mtime, $checksum, strlen( $compressed ), strlen( $data ), $offset, false );
	}

	/**
	 * Adds a folder entry, used for empty folders so they are restored too.
	 */
	public function add_folder( string $name, int $mtime ): void {
		$name   = rtrim( $name, '/' ) . '/';
		$offset = (int) ftell( $this->archive );
		$this->write( $this->archive, $this->local_header( $name, self::METHOD_STORE, $mtime, false ) );
		$this->add_directory_record( $name, self::METHOD_STORE, $mtime, 0, 0, 0, $offset, true );
	}

	/**
	 * Closes the files without finishing the archive (end of a step).
	 */
	public function close(): void {
		fclose( $this->archive );
		fclose( $this->directory );
	}

	/**
	 * Appends the central directory and the end records, then removes the side file.
	 */
	public function finish(): void {
		$directory_offset = (int) ftell( $this->archive );
		fseek( $this->directory, 0 );
		while ( ! feof( $this->directory ) ) {
			$chunk = fread( $this->directory, self::CHUNK );
			if ( false === $chunk ) {
				throw new RuntimeException( 'The zip directory could not be read.' );
			}
			$this->write( $this->archive, $chunk );
		}
		$directory_size = (int) ftell( $this->archive ) - $directory_offset;

		$zip64 = $this->entries >= self::MAX_16 || $directory_offset >= $this->zip64_limit || $directory_size >= $this->zip64_limit;
		if ( $zip64 ) {
			$record_offset = (int) ftell( $this->archive );
			$this->write(
				$this->archive,
				pack( 'VPvvVVPPPP', 0x06064b50, 44, self::VERSION_ZIP64, self::VERSION_ZIP64, 0, 0, $this->entries, $this->entries, $directory_size, $directory_offset )
				. pack( 'VVPV', 0x07064b50, 0, $record_offset, 1 )
			);
		}

		$this->write(
			$this->archive,
			pack(
				'VvvvvVVv',
				0x06054b50,
				0,
				0,
				min( $this->entries, self::MAX_16 ),
				min( $this->entries, self::MAX_16 ),
				$zip64 ? self::MAX_32 : $directory_size,
				$zip64 ? self::MAX_32 : $directory_offset,
				0
			)
		);

		$this->close();
		unlink( $this->directory_path );
	}

	private function local_header( string $name, int $method, int $mtime, bool $zip64 ): string {
		[ $time, $date ] = self::dos_time( $mtime );
		$extra           = pack( 'vvCV', 0x5455, 5, 1, self::unix_time( $mtime ) );
		if ( $zip64 ) {
			$extra .= pack( 'vvPP', 0x0001, 16, 0, 0 );
		}
		return pack(
			'VvvvvvVVVvv',
			0x04034b50,
			$zip64 ? self::VERSION_ZIP64 : self::VERSION,
			self::flags( $name ),
			$method,
			$time,
			$date,
			0,
			$zip64 ? self::MAX_32 : 0,
			$zip64 ? self::MAX_32 : 0,
			strlen( $name ),
			strlen( $extra )
		) . $name . $extra;
	}

	/**
	 * Writes CRC and sizes into the local header once the data is known.
	 */
	private function patch_local_header( int $offset, string $name, int $crc, int $compressed, int $size, bool $zip64 ): void {
		$end = (int) ftell( $this->archive );
		fseek( $this->archive, $offset + 14 );
		$this->write( $this->archive, $zip64 ? pack( 'V', $crc ) : pack( 'VVV', $crc, $compressed, $size ) );
		if ( $zip64 ) {
			fseek( $this->archive, $offset + 30 + strlen( $name ) + 9 + 4 );
			$this->write( $this->archive, pack( 'PP', $size, $compressed ) );
		}
		fseek( $this->archive, $end );
	}

	private function add_directory_record( string $name, int $method, int $mtime, int $crc, int $compressed, int $size, int $offset, bool $folder ): void {
		[ $time, $date ] = self::dos_time( $mtime );

		$zip64_fields = '';
		if ( $size >= $this->zip64_limit ) {
			$zip64_fields .= pack( 'P', $size );
		}
		if ( $compressed >= $this->zip64_limit ) {
			$zip64_fields .= pack( 'P', $compressed );
		}
		if ( $offset >= $this->zip64_limit ) {
			$zip64_fields .= pack( 'P', $offset );
		}

		$extra = pack( 'vvCV', 0x5455, 5, 1, self::unix_time( $mtime ) );
		if ( '' !== $zip64_fields ) {
			$extra .= pack( 'vv', 0x0001, strlen( $zip64_fields ) ) . $zip64_fields;
		}
		$version = '' === $zip64_fields ? self::VERSION : self::VERSION_ZIP64;

		$this->write(
			$this->directory,
			pack(
				'VvvvvvvVVVvvvvvVV',
				0x02014b50,
				self::VERSION_ZIP64,
				$version,
				self::flags( $name ),
				$method,
				$time,
				$date,
				$crc,
				$compressed >= $this->zip64_limit ? self::MAX_32 : $compressed,
				$size >= $this->zip64_limit ? self::MAX_32 : $size,
				strlen( $name ),
				strlen( $extra ),
				0,
				0,
				0,
				$folder ? 0x10 : 0x20,
				$offset >= $this->zip64_limit ? self::MAX_32 : $offset
			) . $name . $extra
		);
		++$this->entries;
	}

	private static function flags( string $name ): int {
		return preg_match( '//u', $name ) ? self::FLAG_UTF8 : 0;
	}

	/**
	 * @return array{0: int, 1: int} MS-DOS time and date (UTC; exact time is in the extended timestamp).
	 */
	private static function dos_time( int $mtime ): array {
		[ $year, $month, $day, $hour, $minute, $second ] = array_map( 'intval', explode( ' ', gmdate( 'Y n j G i s', max( $mtime, 315532800 ) ) ) );
		return [
			( $hour << 11 ) | ( $minute << 5 ) | intdiv( $second, 2 ),
			( ( $year - 1980 ) << 9 ) | ( $month << 5 ) | $day,
		];
	}

	private static function unix_time( int $mtime ): int {
		return max( 0, min( $mtime, self::MAX_32 ) );
	}

	/**
	 * @param \HashContext $context
	 */
	private static function crc( $context ): int {
		$unpacked = unpack( 'N', hash_final( $context, true ) );
		return is_array( $unpacked ) ? (int) $unpacked[1] : 0;
	}

	/**
	 * @return resource
	 */
	private static function open( string $path, int $length ) {
		$handle = fopen( $path, 'c+b' );
		if ( false === $handle ) {
			throw new RuntimeException( 'Could not open ' . $path . ' for writing.' );
		}
		ftruncate( $handle, $length );
		fseek( $handle, 0, SEEK_END );
		return $handle;
	}

	/**
	 * @param resource $handle
	 */
	private function write( $handle, string $data ): void {
		if ( '' !== $data && fwrite( $handle, $data ) !== strlen( $data ) ) {
			throw new RuntimeException( 'Writing the archive failed. Is the disk full?' );
		}
	}
}
