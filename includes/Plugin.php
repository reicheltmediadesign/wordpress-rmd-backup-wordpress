<?php
/**
 * Plugin bootstrap: wires all services to WordPress hooks.
 *
 * @package RMD\BackupWordpress
 */

namespace RMD\BackupWordpress;

use RMD\BackupWordpress\Backup\Runner;
use Throwable;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/** Backups contain the whole site, including credentials in wp-config.php. */
	public const CAP = 'manage_options';

	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		add_action( 'init', [ self::class, 'load_textdomain' ], 1 );
		add_action( 'init', [ Upgrade::class, 'run' ], 5 );

		Updater::init();
		Runner::init();
		Rest\BackupController::init();
		SiteHealth::init();

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'rmd-backup', Cli::class );
		}

		if ( is_admin() ) {
			Admin\Page::init();
		}
	}

	public static function load_textdomain(): void {
		load_plugin_textdomain( 'rmd-backup-wordpress', false, dirname( RMD_BWP_BASENAME ) . '/languages' );
	}

	public static function activate(): void {
		Settings::install_presets();
		Upgrade::run();
		Runner::schedule_all();
		try {
			Storage::prepare();
		} catch ( Throwable $e ) {
			// The admin screen and Site Health report the folder problem; activation itself must not fail.
			error_log( 'RMD Backup WordPress: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	public static function deactivate(): void {
		wp_unschedule_hook( Runner::RUN_HOOK );
		wp_unschedule_hook( Runner::CONTINUE_HOOK );
	}
}
