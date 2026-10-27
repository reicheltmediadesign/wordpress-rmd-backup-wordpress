<?php
/**
 * Registry of destinations. The first one receives the archive from the job;
 * further (remote) destinations would get a copy before it.
 *
 * @package RMD\BackupWordpress
 */

namespace RMD\BackupWordpress\Destination;

defined( 'ABSPATH' ) || exit;

final class Destinations {

	public static function local(): LocalDestination {
		return new LocalDestination();
	}

	/**
	 * @return list<Destination>
	 */
	public static function all(): array {
		return [ self::local() ];
	}
}
