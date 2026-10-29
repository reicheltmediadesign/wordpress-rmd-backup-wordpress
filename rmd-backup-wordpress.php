<?php
/**
 * Plugin Name:       RMD Backup WordPress
 * Plugin URI:        https://github.com/reicheltmediadesign/wordpress-rmd-backup-wordpress
 * Description:       Lightweight backups: files and database in one zip, profiles for what to back up, scheduled runs, automatic cleanup of old backups and a log for every run.
 * Version:           0.3.0
 * Requires at least: 6.8
 * Requires PHP:      8.1
 * Author:            Philipp Reichelt, reichelt media.design
 * Author URI:        https://reicheltmedia.design
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       rmd-backup-wordpress
 * Domain Path:       /languages
 * Update URI:        https://github.com/reicheltmediadesign/wordpress-rmd-backup-wordpress
 *
 * @package RMD\BackupWordpress
 */

// This file must stay parseable by PHP 7.x so the version notice below can be shown instead of a fatal error.

defined( 'ABSPATH' ) || exit;

define( 'RMD_BWP_VERSION', '0.3.0' );
define( 'RMD_BWP_FILE', __FILE__ );
define( 'RMD_BWP_DIR', plugin_dir_path( __FILE__ ) );
define( 'RMD_BWP_URL', plugin_dir_url( __FILE__ ) );
define( 'RMD_BWP_BASENAME', plugin_basename( __FILE__ ) );

if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html(
				sprintf(
					/* translators: 1: required PHP version, 2: current PHP version */
					__( 'RMD Backup WordPress requires PHP %1$s or newer. This site runs PHP %2$s, so the plugin stays inactive.', 'rmd-backup-wordpress' ),
					'8.1',
					PHP_VERSION
				)
			);
			echo '</p></div>';
		}
	);
	return;
}

spl_autoload_register(
	function ( $class_name ) {
		$prefix = 'RMD\\BackupWordpress\\';
		if ( 0 !== strncmp( $class_name, $prefix, strlen( $prefix ) ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = RMD_BWP_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

if ( is_readable( RMD_BWP_DIR . 'vendor/autoload.php' ) ) {
	require RMD_BWP_DIR . 'vendor/autoload.php';
}

register_activation_hook( __FILE__, [ RMD\BackupWordpress\Plugin::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ RMD\BackupWordpress\Plugin::class, 'deactivate' ] );

RMD\BackupWordpress\Plugin::boot();
