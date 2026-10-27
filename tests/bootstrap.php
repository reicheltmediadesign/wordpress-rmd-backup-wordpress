<?php
/**
 * PHPUnit bootstrap. The domain layer has no WordPress dependency, so the tests
 * run with plain PHP; only the PSR-4 autoloader is needed.
 *
 * @package RMD\BackupWordpress
 */

declare(strict_types=1);

$autoload = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( is_readable( $autoload ) ) {
	require $autoload;
}

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'RMD\\BackupWordpress\\';
		if ( 0 !== strncmp( $class_name, $prefix, strlen( $prefix ) ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = dirname( __DIR__ ) . '/includes/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

date_default_timezone_set( 'UTC' );
