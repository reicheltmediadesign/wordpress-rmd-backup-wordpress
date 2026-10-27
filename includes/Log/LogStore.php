<?php
/**
 * History of runs and the log files.
 *
 * The history keeps only the last runs in a small option (no large serialized
 * blobs that slow down the site); details live in the log files.
 *
 * @package RMD\BackupWordpress
 */

namespace RMD\BackupWordpress\Log;

use RMD\BackupWordpress\Domain\ArchiveName;
use RMD\BackupWordpress\Domain\Retention;
use RMD\BackupWordpress\Settings;
use RMD\BackupWordpress\Storage;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions

final class LogStore {

	public const RUNS_OPTION = 'rmd_bwp_runs';
	private const MAX_RUNS   = 50;

	public const STATUSES = [ 'success', 'warning', 'error', 'cancelled', 'skipped' ];

	/**
	 * @param array<string, mixed> $run Keys: id, profile, profile_name, trigger, started, finished, status, message, size, archive, warnings.
	 */
	public static function record( array $run ): void {
		$runs = array_values( array_filter( self::runs(), static fn( array $r ): bool => $r['id'] !== $run['id'] ) );
		array_unshift( $runs, $run );
		update_option( self::RUNS_OPTION, array_slice( $runs, 0, self::MAX_RUNS ), false );
	}

	/**
	 * Newest first.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function runs(): array {
		$stored = get_option( self::RUNS_OPTION, [] );
		$runs   = [];
		foreach ( is_array( $stored ) ? $stored : [] as $run ) {
			if ( is_array( $run ) && isset( $run['id'], $run['status'] ) && is_string( $run['id'] ) ) {
				$runs[] = $run;
			}
		}
		return $runs;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function run( string $id ): ?array {
		foreach ( self::runs() as $run ) {
			if ( $run['id'] === $id ) {
				return $run;
			}
		}
		return null;
	}

	/**
	 * Last run of a profile, optionally only of one trigger.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function last( string $profile, string $trigger = '' ): ?array {
		foreach ( self::runs() as $run ) {
			if ( ( $run['profile'] ?? '' ) === $profile && ( '' === $trigger || ( $run['trigger'] ?? '' ) === $trigger ) ) {
				return $run;
			}
		}
		return null;
	}

	/**
	 * Log files, newest first.
	 *
	 * @return list<array{name: string, id: string, profile: string, size: int, created: int}>
	 */
	public static function logs(): array {
		$dir = Storage::dir( Storage::LOGS );
		if ( ! is_dir( $dir ) ) {
			return [];
		}
		$logs = [];
		foreach ( (array) scandir( $dir ) as $name ) {
			$parsed = ArchiveName::parse( (string) $name );
			if ( null === $parsed || 'log' !== $parsed['type'] ) {
				continue;
			}
			$path   = $dir . '/' . $name;
			$logs[] = [
				'name'    => (string) $name,
				'id'      => $parsed['id'],
				'profile' => $parsed['profile'],
				'size'    => (int) filesize( $path ),
				'created' => (int) filemtime( $path ),
			];
		}
		usort( $logs, static fn( array $a, array $b ): int => strcmp( $b['name'], $a['name'] ) );
		return $logs;
	}

	/**
	 * Path of a log file, or null if the name is invalid or the file is missing.
	 */
	public static function path( string $name ): ?string {
		$parsed = ArchiveName::parse( $name );
		$path   = Storage::dir( Storage::LOGS ) . '/' . $name;
		return null !== $parsed && 'log' === $parsed['type'] && is_file( $path ) ? $path : null;
	}

	public static function delete( string $name ): void {
		$path = self::path( $name );
		if ( null !== $path && ! unlink( $path ) ) {
			throw new RuntimeException(
				esc_html(
					sprintf(
						/* translators: %s: file name */
						__( 'Could not delete %s.', 'rmd-backup-wordpress' ),
						$name
					)
				)
			);
		}
	}

	/**
	 * Applies the log retention from the settings.
	 *
	 * @param string $keep Log name that must never be deleted (the current run).
	 * @return list<string> Deleted log names.
	 */
	public static function cleanup( string $keep = '' ): array {
		$settings = Settings::get();
		$logs     = array_values( array_filter( self::logs(), static fn( array $log ): bool => $log['name'] !== $keep ) );
		$expired  = Retention::expired( $logs, $settings['log_keep_count'], $settings['log_keep_days'], time() );
		foreach ( $expired as $name ) {
			self::delete( $name );
		}
		return $expired;
	}
}
