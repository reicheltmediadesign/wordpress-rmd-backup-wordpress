<?php
/**
 * One backup run. The state lives in an option so the run can be split into
 * short steps (REST requests from the admin screen, cron events or loopback
 * requests) and survives time limits.
 *
 * Phases: database → scan → archive → encrypt → finalize → upload (encrypt
 * and upload only when configured). A finished or failed run is
 * written to the history and its state is removed.
 *
 * Only one step runs at a time (lock row in the options table). A step that
 * dies without an error (time or memory limit) is repeated from the last saved
 * state; after three attempts the run fails.
 *
 * @package RMD\BackupWordpress
 */

namespace RMD\BackupWordpress\Backup;

use DateTimeImmutable;
use RMD\BackupWordpress\Destination\Destination;
use RMD\BackupWordpress\Destination\Destinations;
use RMD\BackupWordpress\Destination\SftpSettings;
use RMD\BackupWordpress\Domain\ArchiveName;
use RMD\BackupWordpress\Domain\Profile;
use RMD\BackupWordpress\Domain\Retention;
use RMD\BackupWordpress\Log\Logger;
use RMD\BackupWordpress\Log\LogStore;
use RMD\BackupWordpress\Notifier;
use RMD\BackupWordpress\Settings;
use RMD\BackupWordpress\Storage;
use RuntimeException;
use Throwable;

defined( 'ABSPATH' ) || exit;

final class Job {

	public const OPTION = 'rmd_bwp_job';
	public const LOCK   = 'rmd_bwp_lock';
	public const CANCEL = 'rmd_bwp_cancel';

	public const TRIGGERS = [ 'manual', 'schedule', 'cli' ];

	/** A lock older than this belongs to a step that died. */
	private const LOCK_SECONDS = 10 * MINUTE_IN_SECONDS;

	private const MAX_ATTEMPTS = 3;

	/** A run without progress for this long is given up when a new one starts. */
	private const ABANDONED_SECONDS = DAY_IN_SECONDS;

	private const MAX_WARNINGS_IN_STATE = 20;

	/**
	 * @return array<string, mixed>|null
	 */
	public static function current(): ?array {
		$job = get_option( self::OPTION, null );
		return is_array( $job ) && isset( $job['id'], $job['phase'], $job['profile'] ) ? $job : null;
	}

	/**
	 * Starts a run. A run that has not moved for a day is given up first.
	 *
	 * @return array<string, mixed>
	 */
	public static function start( Profile $profile, string $trigger ): array {
		if ( is_multisite() ) {
			throw new RuntimeException( esc_html__( 'Multisite networks are not supported.', 'rmd-backup-wordpress' ) );
		}

		$running = self::current();
		if ( null !== $running && (int) $running['heartbeat'] < time() - self::ABANDONED_SECONDS && self::lock() ) {
			try {
				self::fail( $running, 'The run made no progress for a day and was given up.' );
			} finally {
				self::unlock();
			}
			$running = null;
		}
		if ( null !== $running ) {
			throw new RuntimeException( esc_html__( 'Another backup is still running. Wait for it or cancel it first.', 'rmd-backup-wordpress' ) );
		}

		$recipient = Settings::recipient();
		if ( $profile->remote ) {
			if ( '' === $recipient ) {
				throw new RuntimeException( esc_html__( 'Uploads to the SFTP server require encryption. Enter an age public key in the settings.', 'rmd-backup-wordpress' ) );
			}
			if ( ! Destinations::remote()->is_configured() ) {
				throw new RuntimeException( esc_html__( 'The SFTP connection is not set up.', 'rmd-backup-wordpress' ) );
			}
		}

		if ( '' !== $recipient && ! Encryptor::available() ) {
			throw new RuntimeException( esc_html__( 'Encryption needs the PHP extension sodium, which is not active on this server. Ask the host to enable it, or remove the public key in the settings.', 'rmd-backup-wordpress' ) );
		}

		Storage::prepare();
		$site = Settings::get()['filename_site'] ? ArchiveName::site( home_url() ) : '';
		$id   = ArchiveName::id( new DateTimeImmutable( 'now', wp_timezone() ), $profile->id, bin2hex( random_bytes( 3 ) ), $site );
		$log  = Logger::for_run( $id );

		$job = [
			'id'        => $id,
			'profile'   => $profile->to_array(),
			'trigger'   => in_array( $trigger, self::TRIGGERS, true ) ? $trigger : 'manual',
			'phase'     => $profile->has( 'database' ) ? 'database' : 'scan',
			'started'   => time(),
			'heartbeat' => time(),
			'attempts'  => 0,
			'warnings'  => 0,
			'messages'  => [],
			'recipient' => $recipient,
		];
		if ( '' !== $recipient ) {
			$job['encrypt'] = Encryptor::init();
		}
		if ( $profile->remote ) {
			$job['upload'] = [ 'errors' => 0 ];
		}

		if ( ! wp_mkdir_p( self::tmp( $job ) ) ) {
			throw new RuntimeException( esc_html__( 'The temporary folder could not be created.', 'rmd-backup-wordpress' ) );
		}

		$log->info( sprintf( 'Backup started: profile "%s" (%s), trigger: %s', $profile->name, $profile->id, $job['trigger'] ) );
		$log->info( sprintf( 'Parts: %s', implode( ', ', $profile->parts ) ) );
		$log->info( '' === $recipient ? 'Encryption: off' : sprintf( 'Encryption: age, recipient %s', $recipient ) );
		if ( isset( $job['upload'] ) ) {
			$log->info( sprintf( 'Upload: %s, folder for this site: %s', Destinations::remote()->label(), SftpSettings::get()['folder'] ) );
		}
		$log->info(
			sprintf(
				'RMD Backup WordPress %s, WordPress %s, PHP %s, memory_limit %s, max_execution_time %s, %s',
				RMD_BWP_VERSION,
				get_bloginfo( 'version' ),
				PHP_VERSION,
				(string) ini_get( 'memory_limit' ),
				(string) ini_get( 'max_execution_time' ),
				empty( $_SERVER['SERVER_SOFTWARE'] ) ? PHP_SAPI : sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) )
			)
		);

		try {
			if ( $profile->has( 'database' ) ) {
				$job['db'] = DatabaseDump::init( $profile, $log );
			}
			if ( $profile->has_files() ) {
				$job['scan'] = FileScanner::init( $profile );
			}
			$job['archive'] = Archiver::init();
		} catch ( Throwable $e ) {
			$log->error( $e->getMessage() );
			Storage::delete_tree( self::tmp( $job ) );
			throw $e;
		}

		delete_option( self::CANCEL );
		update_option( self::OPTION, $job, false );
		return $job;
	}

	/**
	 * Works on the current run for about $seconds and returns its status. When
	 * another request holds the lock, the status is returned with busy = true.
	 *
	 * @return array<string, mixed>
	 */
	public static function step( float $seconds ): array {
		$job = self::current();
		if ( null === $job ) {
			throw new RuntimeException( esc_html__( 'There is no backup running.', 'rmd-backup-wordpress' ) );
		}
		if ( ! self::lock() ) {
			return [ 'busy' => true ] + self::status( $job );
		}

		try {
			wp_cache_delete( self::OPTION, 'options' );
			$job = self::current();
			if ( null === $job ) {
				throw new RuntimeException( esc_html__( 'There is no backup running.', 'rmd-backup-wordpress' ) );
			}
			if ( self::cancel_requested() ) {
				return self::finish_cancelled( $job );
			}
			if ( (int) $job['attempts'] >= self::MAX_ATTEMPTS ) {
				return self::fail(
					$job,
					sprintf( 'The %s step was interrupted %d times, probably by a time or memory limit of the server.', $job['phase'], self::MAX_ATTEMPTS )
				);
			}

			++$job['attempts'];
			update_option( self::OPTION, $job, false );

			if ( function_exists( 'set_time_limit' ) ) {
				set_time_limit( 'cli' === PHP_SAPI ? 0 : 5 * MINUTE_IN_SECONDS );
			}
			$deadline = microtime( true ) + $seconds;

			try {
				self::work( $job, $deadline );
			} catch ( Throwable $e ) {
				return self::fail( $job, $e->getMessage() );
			}

			$job['attempts']  = 0;
			$job['heartbeat'] = time();
			if ( 'done' === $job['phase'] ) {
				return self::complete( $job );
			}
			update_option( self::OPTION, $job, false );

			if ( self::cancel_requested() ) {
				return self::finish_cancelled( $job );
			}
			Runner::ensure_fallback();
			return self::status( $job );
		} finally {
			self::unlock();
		}
	}

	/**
	 * Cancels the current run. If a step is busy, it stops after that step.
	 *
	 * @return bool True when cancelled right away.
	 */
	public static function cancel(): bool {
		$job = self::current();
		if ( null === $job ) {
			return true;
		}
		if ( ! self::lock() ) {
			update_option( self::CANCEL, 1, false );
			return false;
		}
		try {
			self::finish_cancelled( $job );
		} finally {
			self::unlock();
		}
		return true;
	}

	/**
	 * @param array<string, mixed> $job
	 */
	public static function tmp( array $job ): string {
		return Storage::dir( Storage::TMP ) . '/' . $job['id'];
	}

	/**
	 * @param array<string, mixed> $job
	 */
	public static function profile( array $job ): Profile {
		return Profile::parse( (array) $job['profile'] )['profile'];
	}

	/**
	 * Logs a warning and counts it (the last few are kept for the admin screen).
	 *
	 * @param array<string, mixed> $job
	 */
	public static function warn( array &$job, string $message ): void {
		Logger::for_run( (string) $job['id'] )->warning( $message );
		++$job['warnings'];
		if ( count( $job['messages'] ) < self::MAX_WARNINGS_IN_STATE ) {
			$job['messages'][] = $message;
		}
	}

	/**
	 * Data for the admin screen.
	 *
	 * @param array<string, mixed> $job
	 * @return array<string, mixed>
	 */
	public static function status( array $job ): array {
		$profile = self::profile( $job );
		$db      = (array) ( $job['db'] ?? [] );
		$scan    = (array) ( $job['scan'] ?? [] );
		$archive = (array) $job['archive'];
		$tables  = max( 1, count( (array) ( $db['tables'] ?? [] ) ) );

		$db_share   = isset( $job['db'] ) ? ( isset( $job['scan'] ) ? 0.2 : 0.8 ) : 0;
		$scan_share = isset( $job['scan'] ) ? 0.05 : 0;
		$total_size = (int) ( $scan['size'] ?? 0 );
		$archived   = $total_size > 0 ? min( 1, (int) $archive['read'] / $total_size ) : 0;

		$end      = 0.95 - ( isset( $job['encrypt'] ) ? 0.1 : 0 ) - ( isset( $job['upload'] ) ? 0.25 : 0 );
		$encrypt  = (array) ( $job['encrypt'] ?? [] );
		$upload   = (array) ( $job['upload'] ?? [] );
		$size     = max( 1, (int) ( $job['size'] ?? 1 ) );
		$progress = match ( $job['phase'] ) {
			'database' => $db_share * min( 1, (int) $db['index'] / $tables ),
			'scan'     => $db_share + $scan_share / 2,
			'archive'  => $db_share + $scan_share + ( $end - $db_share - $scan_share ) * $archived,
			'encrypt'  => $end + 0.1 * min( 1, (int) ( $encrypt['in'] ?? 0 ) / max( 1, (int) ( $encrypt['size'] ?? 1 ) ) ),
			'upload'   => 0.7 + 0.28 * min( 1, (int) ( $upload['offset'] ?? 0 ) / $size ),
			default    => 0.97,
		};

		$message = match ( $job['phase'] ) {
			'database' => sprintf(
				/* translators: 1: table name, 2: number of the table, 3: number of tables */
				__( 'Backing up table %1$s (%2$d of %3$d) …', 'rmd-backup-wordpress' ),
				(string) ( $db['tables'][ $db['index'] ]['name'] ?? '' ),
				min( (int) $db['index'] + 1, $tables ),
				$tables
			),
			'scan'     => sprintf(
				/* translators: %s: number of files */
				__( 'Collecting files … %s found', 'rmd-backup-wordpress' ),
				number_format_i18n( (int) $scan['files'] )
			),
			'archive'  => isset( $job['scan'] )
				? sprintf(
					/* translators: 1: archived files, 2: all files, 3: archived size */
					__( 'Adding files to the archive … %1$s of %2$s (%3$s)', 'rmd-backup-wordpress' ),
					number_format_i18n( (int) $archive['files'] ),
					number_format_i18n( (int) $scan['files'] ),
					size_format( (int) $archive['read'] )
				)
				: __( 'Creating the archive …', 'rmd-backup-wordpress' ),
			'encrypt'  => __( 'Encrypting the archive …', 'rmd-backup-wordpress' ),
			'upload'   => sprintf(
				/* translators: 1: uploaded size, 2: total size */
				__( 'Uploading … %1$s of %2$s', 'rmd-backup-wordpress' ),
				size_format( (int) ( $upload['offset'] ?? 0 ) ),
				size_format( $size )
			),
			default    => __( 'Saving the archive …', 'rmd-backup-wordpress' ),
		};

		return [
			'id'       => (string) $job['id'],
			'profile'  => $profile->name,
			'phase'    => (string) $job['phase'],
			'done'     => false,
			'status'   => 'running',
			'progress' => round( $progress, 3 ),
			'message'  => $message,
			'warnings' => array_values( (array) $job['messages'] ),
			'log'      => $job['id'] . '.log',
		];
	}

	/**
	 * Runs phases until the time is up or the run is done.
	 *
	 * @param array<string, mixed> $job
	 */
	private static function work( array &$job, float $deadline ): void {
		$profile = self::profile( $job );
		$log     = Logger::for_run( (string) $job['id'] );

		while ( 'done' !== $job['phase'] && microtime( true ) < $deadline ) {
			switch ( $job['phase'] ) {
				case 'database':
					DatabaseDump::run( $job, $profile, $deadline, $log );
					if ( 'done' === $job['db']['state'] ) {
						$log->info(
							sprintf(
								'Database finished: %d tables, %s rows, %s',
								count( $job['db']['tables'] ),
								number_format( (int) $job['db']['rows'], 0, '', ' ' ),
								size_format( (int) $job['db']['bytes'] )
							)
						);
						$job['phase'] = isset( $job['scan'] ) ? 'scan' : 'archive';
					}
					break;

				case 'scan':
					FileScanner::scan( $job, $profile, $deadline );
					if ( [] === $job['scan']['stack'] ) {
						$log->info(
							sprintf(
								'Files found: %s (%s), %s left out by exclusion rules',
								number_format( (int) $job['scan']['files'], 0, '', ' ' ),
								size_format( (int) $job['scan']['size'] ),
								number_format( (int) $job['scan']['excluded'], 0, '', ' ' )
							)
						);
						$job['phase'] = 'archive';
					}
					break;

				case 'archive':
					$info = static function () use ( &$job, $profile ): string {
						return self::info( $job, $profile );
					};
					if ( Archiver::run( $job, $deadline, $info ) ) {
						$log->info( isset( $job['scan'] ) ? sprintf( 'Archive complete: %s files', number_format( (int) $job['archive']['files'], 0, '', ' ' ) ) : 'Archive complete.' );
						$job['phase'] = isset( $job['encrypt'] ) ? 'encrypt' : 'finalize';
					}
					break;

				case 'encrypt':
					if ( Encryptor::run( $job, $deadline ) ) {
						$log->info( 'Archive encrypted.' );
						$job['phase'] = 'finalize';
					}
					break;

				case 'finalize':
					self::finalize( $job, $profile, $log );
					$job['phase'] = isset( $job['upload'] ) ? 'upload' : 'done';
					break;

				case 'upload':
					if ( self::upload( $job, $profile, $deadline, $log ) ) {
						$job['phase'] = 'done';
					}
					break;

				default:
					throw new RuntimeException( esc_html( 'Unknown phase ' . $job['phase'] ) );
			}
		}
	}

	/**
	 * Moves the archive into the backup folder and applies the retention rules.
	 * Safe to repeat when a step died after the move.
	 *
	 * @param array<string, mixed> $job
	 */
	private static function finalize( array &$job, Profile $profile, Logger $log ): void {
		$encrypted       = isset( $job['encrypt'] );
		$archive         = self::tmp( $job ) . '/' . ( $encrypted ? Encryptor::FILE : Archiver::ARCHIVE );
		$job['filename'] = $job['id'] . '.' . ( $encrypted ? ArchiveName::ENCRYPTED : ArchiveName::ZIP );

		$local  = Destinations::local();
		$stored = $local->path( $job['filename'] );
		if ( null === $stored || is_file( $archive ) ) {
			$local->store( $archive, $job['filename'], $log );
			$stored = (string) $local->path( $job['filename'] );
		}
		$job['size'] = (int) filesize( $stored );

		self::apply_retention( $job, $local, $profile->id, $profile->keep_count, $profile->keep_days, $log );
		Storage::delete_tree( self::tmp( $job ) );
	}

	/**
	 * Uploads the stored archive; network errors are retried in the next steps.
	 *
	 * @param array<string, mixed> $job
	 */
	private static function upload( array &$job, Profile $profile, float $deadline, Logger $log ): bool {
		$remote = Destinations::remote();
		$file   = (string) Destinations::local()->path( (string) $job['filename'] );

		try {
			if ( ! $remote->upload( $job['upload'], $file, (string) $job['filename'], $deadline, $log ) ) {
				return false;
			}
		} catch ( Throwable $e ) {
			++$job['upload']['errors'];
			if ( $job['upload']['errors'] >= self::MAX_ATTEMPTS ) {
				throw $e;
			}
			$log->warning( sprintf( 'Upload attempt %d failed, trying again: %s', $job['upload']['errors'], wp_specialchars_decode( $e->getMessage(), ENT_QUOTES ) ) );
			return false;
		}

		$job['uploaded'] = true;
		self::apply_retention( $job, $remote, $profile->id, $profile->remote_keep_count, $profile->remote_keep_days, $log );
		return true;
	}

	/**
	 * Deletes backups of the profile that the retention rules no longer keep.
	 *
	 * @param array<string, mixed> $job
	 */
	private static function apply_retention( array &$job, Destination $target, string $profile_id, int $keep_count, int $keep_days, Logger $log ): void {
		try {
			$own = array_values( array_filter( $target->backups(), static fn( array $b ): bool => $b['profile'] === $profile_id ) );
		} catch ( Throwable $e ) {
			self::warn( $job, sprintf( 'Old backups could not be listed (%s): %s', $target->label(), wp_specialchars_decode( $e->getMessage(), ENT_QUOTES ) ) );
			return;
		}
		foreach ( Retention::expired( $own, $keep_count, $keep_days, time() ) as $name ) {
			try {
				$target->delete( $name );
				$log->info( sprintf( 'Old backup deleted (%s): %s', $target->label(), $name ) );
			} catch ( Throwable $e ) {
				self::warn( $job, wp_specialchars_decode( $e->getMessage(), ENT_QUOTES ) );
			}
		}
	}

	/**
	 * @param array<string, mixed> $job
	 */
	private static function info( array $job, Profile $profile ): string {
		global $wpdb;

		$info = [
			'generator' => 'RMD Backup WordPress ' . RMD_BWP_VERSION,
			'created'   => wp_date( 'c' ),
			'site'      => [
				'home'      => home_url(),
				'siteurl'   => site_url(),
				'name'      => get_bloginfo( 'name' ),
				'wordpress' => get_bloginfo( 'version' ),
				'php'       => PHP_VERSION,
				'database'  => $wpdb->db_server_info(),
				'prefix'    => $wpdb->prefix,
				'charset'   => $wpdb->charset,
				'abspath'   => wp_normalize_path( ABSPATH ),
			],
			'profile'   => [
				'id'      => $profile->id,
				'name'    => $profile->name,
				'parts'   => $profile->parts,
				'trigger' => $job['trigger'],
			],
		];
		if ( isset( $job['db'] ) ) {
			$info['database'] = [
				'file'   => DumpFile::name(),
				'tables' => array_column( (array) $job['db']['tables'], 'name' ),
				'rows'   => (int) $job['db']['rows'],
			];
		}
		if ( isset( $job['scan'] ) ) {
			$info['files'] = [
				'count' => (int) $job['archive']['files'],
				'size'  => (int) $job['archive']['read'],
			];
		}
		return (string) wp_json_encode( $info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * @param array<string, mixed> $job
	 * @return array<string, mixed>
	 */
	private static function complete( array $job ): array {
		$log      = Logger::for_run( (string) $job['id'] );
		$status   = $job['warnings'] > 0 ? 'warning' : 'success';
		$duration = time() - (int) $job['started'];
		$log->info(
			sprintf(
				'Backup finished in %s with %d warnings: %s (%s)',
				self::duration( $duration ),
				(int) $job['warnings'],
				(string) $job['filename'],
				size_format( (int) $job['size'] )
			)
		);
		return self::end( $job, $status, '' );
	}

	/**
	 * @param array<string, mixed> $job
	 * @return array<string, mixed>
	 */
	private static function fail( array $job, string $message ): array {
		$log = Logger::for_run( (string) $job['id'] );
		$log->error( wp_specialchars_decode( $message, ENT_QUOTES ) );
		self::remove_tmp( $job, $log );
		$log->error( 'Backup failed.' );
		return self::end( $job, 'error', wp_specialchars_decode( $message, ENT_QUOTES ) );
	}

	/**
	 * @param array<string, mixed> $job
	 * @return array<string, mixed>
	 */
	private static function finish_cancelled( array $job ): array {
		$log = Logger::for_run( (string) $job['id'] );
		self::remove_tmp( $job, $log );
		$log->warning( 'Backup cancelled.' );
		return self::end( $job, 'cancelled', __( 'Cancelled.', 'rmd-backup-wordpress' ) );
	}

	/**
	 * Records the run, sends the notification and removes the state.
	 *
	 * @param array<string, mixed> $job
	 * @return array<string, mixed>
	 */
	private static function end( array $job, string $status, string $message ): array {
		$profile = self::profile( $job );
		$log     = Logger::for_run( (string) $job['id'] );
		$run     = [
			'id'           => (string) $job['id'],
			'profile'      => $profile->id,
			'profile_name' => $profile->name,
			'trigger'      => (string) $job['trigger'],
			'started'      => (int) $job['started'],
			'finished'     => time(),
			'status'       => $status,
			'message'      => $message,
			'size'         => (int) ( $job['size'] ?? 0 ),
			'archive'      => isset( $job['filename'] ) && null !== Destinations::local()->path( (string) $job['filename'] ) ? (string) $job['filename'] : '',
			'remote'       => isset( $job['upload'] ) ? ( empty( $job['uploaded'] ) ? 'failed' : 'uploaded' ) : '',
			'warnings'     => (int) $job['warnings'],
		];

		delete_option( self::OPTION );
		delete_option( self::CANCEL );
		Runner::clear_fallback();
		LogStore::record( $run );

		if ( 'cancelled' !== $status ) {
			$sent = Notifier::send( $profile, $run, $log->path() );
			if ( false === $sent ) {
				$log->warning( 'The notification email could not be sent.' );
			}
		}

		try {
			foreach ( LogStore::cleanup( basename( $log->path() ) ) as $deleted ) {
				$log->info( sprintf( 'Old log deleted: %s', $deleted ) );
			}
		} catch ( Throwable $e ) {
			$log->warning( $e->getMessage() );
		}

		return [
			'id'       => $run['id'],
			'profile'  => $profile->name,
			'phase'    => 'done',
			'done'     => true,
			'status'   => $status,
			'progress' => 1,
			'message'  => $message,
			'warnings' => array_values( (array) $job['messages'] ),
			'archive'  => $run['archive'],
			'size'     => $run['size'],
			'log'      => $run['id'] . '.log',
		];
	}

	/**
	 * @param array<string, mixed> $job
	 */
	private static function remove_tmp( array $job, Logger $log ): void {
		try {
			Storage::delete_tree( self::tmp( $job ) );
		} catch ( Throwable $e ) {
			$log->warning( $e->getMessage() );
		}
	}

	private static function duration( int $seconds ): string {
		return $seconds >= 60 ? sprintf( '%d min %d s', intdiv( $seconds, 60 ), $seconds % 60 ) : $seconds . ' s';
	}

	private static function cancel_requested(): bool {
		wp_cache_delete( self::CANCEL, 'options' );
		return (bool) get_option( self::CANCEL, false );
	}

	/**
	 * Atomic lock: a row in the options table that only one request can insert.
	 * add_option() is not atomic, so the row is written directly.
	 */
	private static function lock(): bool {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d", self::LOCK, time() - self::LOCK_SECONDS ) );
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", self::LOCK, (string) time() ) );
		// phpcs:enable
		return 1 === $inserted;
	}

	private static function unlock(): void {
		global $wpdb;
		$wpdb->delete( $wpdb->options, [ 'option_name' => self::LOCK ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
