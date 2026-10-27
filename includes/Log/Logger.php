<?php
/**
 * Log file of one backup run: logs/<run id>.log, one line per event.
 *
 * @package RMD\BackupWordpress
 */

namespace RMD\BackupWordpress\Log;

use RMD\BackupWordpress\Storage;

defined( 'ABSPATH' ) || exit;

// Plain append-only text file in the storage folder.
// phpcs:disable WordPress.WP.AlternativeFunctions

final class Logger {

	public const INFO    = 'INFO';
	public const WARNING = 'WARNING';
	public const ERROR   = 'ERROR';

	private static bool $reported = false;

	public function __construct( private readonly string $path ) {}

	public static function for_run( string $id ): self {
		return new self( Storage::dir( Storage::LOGS ) . '/' . $id . '.log' );
	}

	public function path(): string {
		return $this->path;
	}

	public function info( string $message ): void {
		$this->write( self::INFO, $message );
	}

	public function warning( string $message ): void {
		$this->write( self::WARNING, $message );
	}

	public function error( string $message ): void {
		$this->write( self::ERROR, $message );
	}

	private function write( string $level, string $message ): void {
		$line = '[' . wp_date( 'Y-m-d H:i:s' ) . '] ' . str_pad( $level, 8 ) . str_replace( [ "\r\n", "\n" ], ' | ', $message ) . "\n";
		if ( false === file_put_contents( $this->path, $line, FILE_APPEND | LOCK_EX ) && ! self::$reported ) {
			self::$reported = true;
			error_log( 'RMD Backup WordPress: could not write the log file ' . $this->path . '. ' . $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Last resort when the log folder is not writable.
		}
	}
}
