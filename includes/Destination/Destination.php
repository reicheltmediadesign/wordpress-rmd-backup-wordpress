<?php
/**
 * Where finished archives are kept: the local backup folder
 * (LocalDestination) and remote targets (RemoteDestination, e.g. SFTP).
 *
 * @package RMD\BackupWordpress
 */

namespace RMD\BackupWordpress\Destination;

defined( 'ABSPATH' ) || exit;

interface Destination {

	/**
	 * Short name for logs and the admin screen.
	 */
	public function label(): string;

	/**
	 * Archives kept here, newest first.
	 *
	 * @return list<array{name: string, id: string, profile: string, size: int, created: int}>
	 */
	public function backups(): array;

	public function delete( string $name ): void;
}
