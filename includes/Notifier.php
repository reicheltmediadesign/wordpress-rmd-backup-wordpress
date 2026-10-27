<?php
/**
 * Email after a run, depending on the profile: never, only for runs with
 * warnings or errors, or always. The log file is attached.
 *
 * @package RMD\BackupWordpress
 */

namespace RMD\BackupWordpress;

use RMD\BackupWordpress\Domain\Profile;

defined( 'ABSPATH' ) || exit;

final class Notifier {

	private const MAX_ATTACHMENT = 5 * 1024 * 1024;

	/**
	 * @param array<string, mixed> $run
	 * @return bool|null Null when no email is due, otherwise the result of wp_mail().
	 */
	public static function send( Profile $profile, array $run, ?string $log ): ?bool {
		$problem = in_array( $run['status'], [ 'warning', 'error', 'skipped' ], true );
		if ( 'off' === $profile->notify || ( 'problems' === $profile->notify && ! $problem ) ) {
			return null;
		}

		$to = $profile->notify_email;
		if ( '' === $to ) {
			$to = Settings::get()['notify_email'];
		}
		if ( '' === $to ) {
			$to = (string) get_option( 'admin_email' );
		}

		$site    = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
		$subject = sprintf(
			/* translators: 1: site name, 2: status, 3: profile name */
			__( '[%1$s] Backup %2$s: %3$s', 'rmd-backup-wordpress' ),
			$site,
			self::status_label( (string) $run['status'] ),
			$profile->name
		);

		$lines = [
			/* translators: %s: site address */
			sprintf( __( 'Site: %s', 'rmd-backup-wordpress' ), home_url() ),
			/* translators: %s: profile name */
			sprintf( __( 'Profile: %s', 'rmd-backup-wordpress' ), $profile->name ),
			/* translators: %s: status */
			sprintf( __( 'Status: %s', 'rmd-backup-wordpress' ), self::status_label( (string) $run['status'] ) ),
			/* translators: %s: date and time */
			sprintf( __( 'Started: %s', 'rmd-backup-wordpress' ), wp_date( 'Y-m-d H:i:s', (int) $run['started'] ) ),
			/* translators: %s: date and time */
			sprintf( __( 'Finished: %s', 'rmd-backup-wordpress' ), wp_date( 'Y-m-d H:i:s', (int) $run['finished'] ) ),
		];
		if ( '' !== $run['archive'] ) {
			/* translators: 1: file name, 2: size */
			$lines[] = sprintf( __( 'Archive: %1$s (%2$s)', 'rmd-backup-wordpress' ), $run['archive'], size_format( (int) $run['size'] ) );
		}
		if ( (int) $run['warnings'] > 0 ) {
			/* translators: %d: number of warnings */
			$lines[] = sprintf( __( 'Warnings: %d', 'rmd-backup-wordpress' ), (int) $run['warnings'] );
		}
		if ( '' !== $run['message'] ) {
			$lines[] = '';
			$lines[] = (string) $run['message'];
		}

		$attachments = [];
		if ( null !== $log && is_readable( $log ) && filesize( $log ) <= self::MAX_ATTACHMENT ) {
			$attachments[] = $log;
			$lines[]       = '';
			$lines[]       = __( 'The log of this run is attached.', 'rmd-backup-wordpress' );
		}

		return wp_mail( $to, $subject, implode( "\n", $lines ), '', $attachments );
	}

	public static function status_label( string $status ): string {
		return match ( $status ) {
			'success'   => __( 'successful', 'rmd-backup-wordpress' ),
			'warning'   => __( 'finished with warnings', 'rmd-backup-wordpress' ),
			'error'     => __( 'failed', 'rmd-backup-wordpress' ),
			'cancelled' => __( 'cancelled', 'rmd-backup-wordpress' ),
			'skipped'   => __( 'skipped', 'rmd-backup-wordpress' ),
			default     => __( 'running', 'rmd-backup-wordpress' ),
		};
	}
}
