<?php
/**
 * REST endpoints the admin screen uses to run a backup step by step.
 *
 *   GET    /rmd-backup-wordpress/v1/backup        status of the current run
 *   POST   /rmd-backup-wordpress/v1/backup        start a run { profile }
 *   POST   /rmd-backup-wordpress/v1/backup/step   do the next few seconds of work
 *   DELETE /rmd-backup-wordpress/v1/backup        cancel the current run
 *
 * @package RMD\BackupWordpress
 */

namespace RMD\BackupWordpress\Rest;

use RMD\BackupWordpress\Backup\Job;
use RMD\BackupWordpress\Backup\Runner;
use RMD\BackupWordpress\Plugin;
use RMD\BackupWordpress\Settings;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class BackupController {

	public const NAMESPACE = 'rmd-backup-wordpress/v1';

	public static function init(): void {
		add_action( 'rest_api_init', [ self::class, 'routes' ] );
	}

	public static function routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/backup',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ self::class, 'status' ],
					'permission_callback' => [ self::class, 'permission' ],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ self::class, 'start' ],
					'permission_callback' => [ self::class, 'permission' ],
					'args'                => [
						'profile' => [
							'type'     => 'string',
							'required' => true,
							'pattern'  => '^[a-z0-9-]{1,40}$',
						],
					],
				],
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ self::class, 'cancel' ],
					'permission_callback' => [ self::class, 'permission' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/backup/step',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ self::class, 'step' ],
				'permission_callback' => [ self::class, 'permission' ],
			]
		);
	}

	/**
	 * @return true|WP_Error
	 */
	public static function permission() {
		if ( ! current_user_can( Plugin::CAP ) ) {
			return new WP_Error( 'rest_forbidden', __( 'You are not allowed to create backups.', 'rmd-backup-wordpress' ), [ 'status' => rest_authorization_required_code() ] );
		}
		return true;
	}

	public static function status(): WP_REST_Response {
		$job = Job::current();
		return new WP_REST_Response( null === $job ? null : Job::status( $job ) );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function start( WP_REST_Request $request ) {
		$profile = Settings::profile( (string) $request->get_param( 'profile' ) );
		if ( null === $profile ) {
			return new WP_Error( 'rmd_bwp_unknown_profile', __( 'Unknown profile.', 'rmd-backup-wordpress' ), [ 'status' => 404 ] );
		}
		return self::guarded(
			static function () use ( $profile ): array {
				$job = Job::start( $profile, 'manual' );
				Runner::ensure_fallback();
				return Job::status( $job );
			}
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function step() {
		return self::guarded( static fn(): array => Job::step( Runner::step_seconds() ) );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function cancel() {
		return self::guarded( static fn(): array => [ 'cancelled' => Job::cancel() ] );
	}

	/**
	 * Turns exceptions into a REST error with the message, so the admin screen can show it.
	 *
	 * @param callable(): array<string, mixed> $callback
	 * @return WP_REST_Response|WP_Error
	 */
	private static function guarded( callable $callback ) {
		try {
			return new WP_REST_Response( $callback() );
		} catch ( Throwable $e ) {
			// Messages are escaped when thrown; the admin screen inserts them as text.
			return new WP_Error( 'rmd_bwp_backup_failed', wp_specialchars_decode( $e->getMessage(), ENT_QUOTES ), [ 'status' => 500 ] );
		}
	}
}
