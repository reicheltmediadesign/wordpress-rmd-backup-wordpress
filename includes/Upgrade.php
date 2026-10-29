<?php
/**
 * One-time data changes after a plugin update. The installed version is kept
 * in an option; every step runs once when updating from an older version.
 *
 * @package RMD\BackupWordpress
 */

namespace RMD\BackupWordpress;

use RMD\BackupWordpress\Domain\Profile;

defined( 'ABSPATH' ) || exit;

final class Upgrade {

	public const OPTION = 'rmd_bwp_version';

	public static function run(): void {
		$installed = (string) get_option( self::OPTION, '' );
		if ( '' !== $installed && version_compare( $installed, RMD_BWP_VERSION, '>=' ) ) {
			return;
		}

		if ( '' === $installed || version_compare( $installed, '0.3.0', '<' ) ) {
			self::lower_default_retention();
		}

		update_option( self::OPTION, RMD_BWP_VERSION, true );
	}

	/**
	 * 0.3.0: sites keep 3 backups per profile instead of 7. The remote copy
	 * gets its own rule (7 by default), so it keeps as many as before.
	 * Profiles with another number were set on purpose and stay as they are.
	 */
	private static function lower_default_retention(): void {
		foreach ( Settings::profiles() as $profile ) {
			if ( Profile::LEGACY_KEEP !== $profile->keep_count ) {
				continue;
			}
			$lowered = Profile::parse( [ 'keep_count' => Profile::DEFAULT_KEEP ] + $profile->to_array() )['profile'];
			Settings::save_profile( $lowered, false );
		}
	}
}
