<?php
/**
 * Site Health tests: storage folder and scheduled backups.
 *
 * @package RMD\BackupWordpress
 */

namespace RMD\BackupWordpress;

use RMD\BackupWordpress\Backup\Encryptor;
use RMD\BackupWordpress\Backup\Runner;
use RMD\BackupWordpress\Log\LogStore;

defined( 'ABSPATH' ) || exit;

final class SiteHealth {

	private const LOW_SPACE = 1024 * 1024 * 1024;

	public static function init(): void {
		add_filter( 'site_status_tests', [ self::class, 'register' ] );
	}

	/**
	 * @param array<string, mixed> $tests
	 * @return array<string, mixed>
	 */
	public static function register( array $tests ): array {
		$tests['direct']['rmd_bwp_storage']  = [
			'label' => __( 'Backup folder', 'rmd-backup-wordpress' ),
			'test'  => [ self::class, 'storage' ],
		];
		$tests['direct']['rmd_bwp_schedule'] = [
			'label' => __( 'Scheduled backups', 'rmd-backup-wordpress' ),
			'test'  => [ self::class, 'schedule' ],
		];
		return $tests;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function storage(): array {
		$root = Storage::root();
		$free = Storage::free_space();

		if ( ! is_dir( $root ) || ! wp_is_writable( $root ) ) {
			return self::result(
				'rmd_bwp_storage',
				'critical',
				__( 'The backup folder is not writable', 'rmd-backup-wordpress' ),
				/* translators: %s: folder path */
				sprintf( __( 'Backups are written to %s. Create the folder or fix its permissions.', 'rmd-backup-wordpress' ), $root )
			);
		}
		if ( '' !== Settings::recipient() && ! Encryptor::available() ) {
			return self::result(
				'rmd_bwp_storage',
				'critical',
				__( 'Backups cannot be encrypted', 'rmd-backup-wordpress' ),
				__( 'An encryption key is set, but the PHP extension sodium is not active. Every backup fails until the host enables it.', 'rmd-backup-wordpress' )
			);
		}
		if ( null !== $free && $free < self::LOW_SPACE ) {
			return self::result(
				'rmd_bwp_storage',
				'recommended',
				__( 'Little disk space left for backups', 'rmd-backup-wordpress' ),
				/* translators: %s: free disk space */
				sprintf( __( 'Only %s are free. Delete old backups or keep fewer of them.', 'rmd-backup-wordpress' ), size_format( $free ) )
			);
		}
		return self::result(
			'rmd_bwp_storage',
			'good',
			__( 'The backup folder is writable', 'rmd-backup-wordpress' ),
			/* translators: %s: folder path */
			sprintf( __( 'Backups are written to %s.', 'rmd-backup-wordpress' ), $root )
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function schedule(): array {
		$problems = [];
		foreach ( Settings::profiles() as $profile ) {
			if ( ! $profile->is_scheduled() ) {
				continue;
			}
			$next = Runner::next_run( $profile->id );
			if ( null !== $next && $next < time() - HOUR_IN_SECONDS ) {
				/* translators: %s: profile name */
				$problems[] = sprintf( __( '%s: the scheduled run is overdue. WP-Cron only runs when the site has visitors; set up a server cron job for wp-cron.php.', 'rmd-backup-wordpress' ), $profile->name );
			}
			$last = LogStore::last( $profile->id, 'schedule' );
			if ( null !== $last && in_array( $last['status'], [ 'error', 'skipped' ], true ) ) {
				/* translators: 1: profile name, 2: error message */
				$problems[] = sprintf( __( '%1$s: the last scheduled run failed: %2$s', 'rmd-backup-wordpress' ), $profile->name, (string) $last['message'] );
			}
		}

		if ( [] === $problems ) {
			return self::result(
				'rmd_bwp_schedule',
				'good',
				__( 'Scheduled backups run as planned', 'rmd-backup-wordpress' ),
				__( 'No overdue or failed scheduled backups.', 'rmd-backup-wordpress' )
			);
		}
		return self::result(
			'rmd_bwp_schedule',
			'critical',
			__( 'Scheduled backups have problems', 'rmd-backup-wordpress' ),
			implode( ' ', $problems )
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function result( string $test, string $status, string $label, string $description ): array {
		return [
			'label'       => $label,
			'status'      => $status,
			'badge'       => [
				'label' => __( 'Backups', 'rmd-backup-wordpress' ),
				'color' => 'good' === $status ? 'blue' : 'red',
			],
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => sprintf( '<p><a href="%s">%s</a></p>', esc_url( Admin\Page::url() ), esc_html__( 'Open backups', 'rmd-backup-wordpress' ) ),
			'test'        => $test,
		];
	}
}
