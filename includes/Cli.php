<?php
/**
 * WP-CLI commands. A run started here works in one process without the step
 * time limit, which is the most reliable way for large sites:
 *
 *   wp rmd-backup run full
 *   wp rmd-backup list
 *
 * @package RMD\BackupWordpress
 */

namespace RMD\BackupWordpress;

use RMD\BackupWordpress\Backup\Job;
use RMD\BackupWordpress\Destination\Destinations;
use Throwable;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

final class Cli {

	/**
	 * Runs a backup with a profile.
	 *
	 * ## OPTIONS
	 *
	 * <profile>
	 * : Profile id, see `wp rmd-backup profiles`.
	 *
	 * @param list<string> $args
	 */
	public function run( array $args ): void {
		$profile = Settings::profile( $args[0] ?? '' );
		if ( null === $profile ) {
			WP_CLI::error( 'Unknown profile. See: wp rmd-backup profiles' );
		}
		try {
			$status = Job::status( Job::start( $profile, 'cli' ) );
			WP_CLI::log( 'Backup ' . $status['id'] );
		} catch ( Throwable $e ) {
			WP_CLI::error( wp_specialchars_decode( $e->getMessage(), ENT_QUOTES ) );
		}
		$this->drive();
	}

	/**
	 * Continues the current run, e.g. one started from the admin screen.
	 */
	public function resume(): void {
		if ( null === Job::current() ) {
			WP_CLI::error( 'There is no backup running.' );
		}
		$this->drive();
	}

	/**
	 * Cancels the current run.
	 */
	public function cancel(): void {
		WP_CLI::success( Job::cancel() ? 'Backup cancelled.' : 'The backup stops after the current step.' );
	}

	/**
	 * Lists the profiles.
	 *
	 * @subcommand profiles
	 */
	public function profiles(): void {
		$rows = [];
		foreach ( Settings::profiles() as $profile ) {
			$rows[] = [
				'id'       => $profile->id,
				'name'     => $profile->name,
				'parts'    => implode( ',', $profile->parts ),
				'schedule' => $profile->schedule,
				'keep'     => $profile->keep_count,
			];
		}
		WP_CLI\Utils\format_items( 'table', $rows, [ 'id', 'name', 'parts', 'schedule', 'keep' ] );
	}

	/**
	 * Lists the stored backups.
	 *
	 * @subcommand list
	 */
	public function list_(): void {
		$rows = [];
		foreach ( Destinations::local()->backups() as $backup ) {
			$rows[] = [
				'name'    => $backup['name'],
				'profile' => $backup['profile'],
				'size'    => size_format( $backup['size'] ),
			];
		}
		WP_CLI\Utils\format_items( 'table', $rows, [ 'name', 'profile', 'size' ] );
	}

	private function drive(): void {
		$last = '';
		do {
			try {
				$status = Job::step( 60 );
			} catch ( Throwable $e ) {
				WP_CLI::error( wp_specialchars_decode( $e->getMessage(), ENT_QUOTES ) );
			}
			if ( ! empty( $status['busy'] ) ) {
				WP_CLI::log( 'Another request is working on this backup, waiting …' );
				sleep( 5 );
				continue;
			}
			if ( $status['message'] !== $last && ! $status['done'] ) {
				$last = (string) $status['message'];
				WP_CLI::log( $last );
			}
		} while ( empty( $status['done'] ) );

		$log = Storage::dir( Storage::LOGS ) . '/' . $status['log'];
		if ( 'error' === $status['status'] ) {
			WP_CLI::error( $status['message'] . ' Log: ' . $log );
		}
		if ( 'cancelled' === $status['status'] ) {
			WP_CLI::warning( 'Backup cancelled.' );
			return;
		}
		$message = sprintf( '%s (%s). Log: %s', $status['archive'], size_format( (int) $status['size'] ), $log );
		if ( 'warning' === $status['status'] ) {
			WP_CLI::warning( 'Finished with warnings: ' . $message );
			return;
		}
		WP_CLI::success( $message );
	}
}
