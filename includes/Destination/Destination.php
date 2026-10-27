<?php
/**
 * Where finished archives are kept. Only the local folder exists so far; a
 * remote target (SFTP, S3, …) implements the same interface and is added to
 * Destinations::all().
 *
 * @package RMD\BackupWordpress
 */

namespace RMD\BackupWordpress\Destination;

use RMD\BackupWordpress\Log\Logger;

defined( 'ABSPATH' ) || exit;

interface Destination {

	/**
	 * Short name for logs and the admin screen.
	 */
	public function label(): string;

	/**
	 * Takes over a finished archive. The local file may be moved; callers must
	 * not use $file afterwards.
	 */
	public function store( string $file, string $name, Logger $log ): void;

	/**
	 * Archives kept here, newest first.
	 *
	 * @return list<array{name: string, id: string, profile: string, size: int, created: int}>
	 */
	public function backups(): array;

	public function delete( string $name ): void;
}
