<?php
/**
 * Drives runs without a browser.
 *
 * - Each scheduled profile has one single WP-Cron event for its next run
 *   (rmd_bwp_scheduled); it plans the following one when it fires.
 * - A run in progress continues through a non-blocking loopback request to
 *   admin-ajax.php after every step, authorised by a secret key.
 * - If the loopback is blocked or the browser that drove a manual run is
 *   closed, the cron event rmd_bwp_continue (two minutes after the last step)
 *   picks the run up again.
 *
 * @package RMD\BackupWordpress
 */

namespace RMD\BackupWordpress\Backup;

use DateTimeImmutable;
use RMD\BackupWordpress\Domain\Profile;
use RMD\BackupWordpress\Domain\Schedule;
use RMD\BackupWordpress\Log\LogStore;
use RMD\BackupWordpress\Notifier;
use RMD\BackupWordpress\Settings;
use Throwable;

defined( 'ABSPATH' ) || exit;

final class Runner {

	public const RUN_HOOK      = 'rmd_bwp_scheduled';
	public const CONTINUE_HOOK = 'rmd_bwp_continue';
	public const AJAX_ACTION   = 'rmd_bwp_continue';

	private const FALLBACK_DELAY = 2 * MINUTE_IN_SECONDS;

	public static function init(): void {
		add_action( self::RUN_HOOK, [ self::class, 'run_scheduled' ] );
		add_action( self::CONTINUE_HOOK, [ self::class, 'work' ] );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, [ self::class, 'ajax_continue' ] );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, [ self::class, 'ajax_continue' ] );
		add_action( 'admin_init', [ self::class, 'repair_schedules' ] );
		add_action( 'update_option_timezone_string', [ self::class, 'schedule_all' ] );
		add_action( 'update_option_gmt_offset', [ self::class, 'schedule_all' ] );
	}

	/**
	 * Seconds of work per request, kept well below the server's time limit.
	 */
	public static function step_seconds(): float {
		$limit = (int) ini_get( 'max_execution_time' );
		return (float) ( $limit > 0 ? max( 5, min( 20, $limit - 10 ) ) : 20 );
	}

	public static function schedule_all(): void {
		wp_unschedule_hook( self::RUN_HOOK );
		foreach ( Settings::profiles() as $profile ) {
			self::sync( $profile );
		}
	}

	/**
	 * Plans the next run of a profile (or removes it for manual profiles).
	 */
	public static function sync( Profile $profile ): void {
		wp_clear_scheduled_hook( self::RUN_HOOK, [ $profile->id ] );
		$next = Schedule::next( $profile->schedule, $profile->time, $profile->weekday, $profile->monthday, new DateTimeImmutable( 'now', wp_timezone() ) );
		if ( null !== $next ) {
			wp_schedule_single_event( $next->getTimestamp(), self::RUN_HOOK, [ $profile->id ] );
		}
	}

	public static function unschedule( string $profile_id ): void {
		wp_clear_scheduled_hook( self::RUN_HOOK, [ $profile_id ] );
	}

	public static function next_run( string $profile_id ): ?int {
		$next = wp_next_scheduled( self::RUN_HOOK, [ $profile_id ] );
		return false === $next ? null : (int) $next;
	}

	/**
	 * Adds missing events, e.g. after the cron option was reset.
	 */
	public static function repair_schedules(): void {
		foreach ( Settings::profiles() as $profile ) {
			if ( $profile->is_scheduled() && null === self::next_run( $profile->id ) ) {
				self::sync( $profile );
			}
		}
	}

	/**
	 * @param mixed $profile_id
	 */
	public static function run_scheduled( $profile_id ): void {
		$profile = is_string( $profile_id ) ? Settings::profile( $profile_id ) : null;
		if ( null === $profile ) {
			return;
		}
		self::sync( $profile );

		try {
			Job::start( $profile, 'schedule' );
		} catch ( Throwable $e ) {
			$run = [
				'id'           => 'skipped-' . $profile->id . '-' . time(),
				'profile'      => $profile->id,
				'profile_name' => $profile->name,
				'trigger'      => 'schedule',
				'started'      => time(),
				'finished'     => time(),
				'status'       => 'skipped',
				'message'      => wp_specialchars_decode( $e->getMessage(), ENT_QUOTES ),
				'size'         => 0,
				'archive'      => '',
				'warnings'     => 0,
			];
			LogStore::record( $run );
			Notifier::send( $profile, $run, null );
			return;
		}
		self::work();
	}

	/**
	 * One step of the current run, then hand over to the next request.
	 */
	public static function work(): void {
		if ( null === Job::current() ) {
			return;
		}
		$status = Job::step( self::step_seconds() );
		if ( empty( $status['done'] ) && empty( $status['busy'] ) ) {
			self::spawn();
		}
	}

	public static function ajax_continue(): void {
		$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Authorised by the secret key; no user session involved.
		if ( '' === $key || ! hash_equals( Settings::key(), $key ) ) {
			wp_die( '', '', [ 'response' => 403 ] );
		}
		ignore_user_abort( true );
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}
		self::work();
		wp_die( '', '', [ 'response' => 200 ] );
	}

	public static function ensure_fallback(): void {
		wp_clear_scheduled_hook( self::CONTINUE_HOOK );
		wp_schedule_single_event( time() + self::FALLBACK_DELAY, self::CONTINUE_HOOK );
	}

	public static function clear_fallback(): void {
		wp_clear_scheduled_hook( self::CONTINUE_HOOK );
	}

	/**
	 * Non-blocking request to this site that runs the next step.
	 */
	private static function spawn(): void {
		wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			[
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter, as used by spawn_cron().
				'body'      => [
					'action' => self::AJAX_ACTION,
					'key'    => Settings::key(),
				],
			]
		);
	}
}
