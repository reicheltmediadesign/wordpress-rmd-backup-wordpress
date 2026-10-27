<?php
/**
 * A destination on another server. Uploads run over several steps: upload()
 * is called repeatedly with the same state until it returns true.
 *
 * @package RMD\BackupWordpress
 */

namespace RMD\BackupWordpress\Destination;

use RMD\BackupWordpress\Log\Logger;

defined( 'ABSPATH' ) || exit;

interface RemoteDestination extends Destination {

	/**
	 * Whether all settings needed for an upload are present.
	 */
	public function is_configured(): bool;

	/**
	 * Uploads until the time is up. Returns true when the file is complete.
	 *
	 * @param array<string, mixed> $state Kept by the caller between steps; empty at the start.
	 */
	public function upload( array &$state, string $file, string $name, float $deadline, Logger $log ): bool;
}
