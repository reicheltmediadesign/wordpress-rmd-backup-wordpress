<?php
/**
 * Removes the plugin's options and cron events. Backups and logs in
 * wp-content/rmd-backup-wordpress are kept; delete the folder in the file
 * system if they are no longer needed.
 *
 * @package RMD\BackupWordpress
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( [ 'rmd_bwp_settings', 'rmd_bwp_profiles', 'rmd_bwp_key', 'rmd_bwp_job', 'rmd_bwp_lock', 'rmd_bwp_cancel', 'rmd_bwp_runs', 'rmd_bwp_sftp', 'external_updates-rmd-backup-wordpress' ] as $rmd_bwp_option ) {
	delete_option( $rmd_bwp_option );
}

wp_unschedule_hook( 'rmd_bwp_scheduled' );
wp_unschedule_hook( 'rmd_bwp_continue' );
