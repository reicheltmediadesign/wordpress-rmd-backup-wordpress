<?php
/**
 * Registry of destinations: the local folder always receives the archive;
 * a configured remote destination gets a copy when the profile asks for it.
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
	 * The remote destination, also when it is not (fully) configured.
	 */
	public static function remote(): RemoteDestination {
		return SftpDestination::from_settings();
	}
}
