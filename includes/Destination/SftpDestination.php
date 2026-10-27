<?php
/**
 * Archives on an SFTP server (e.g. STRATO HiDrive), in <path>/<folder>/.
 *
 * - The server's host key is pinned: the first connection test stores its
 *   SHA-256 fingerprint, and every later connection must present the same key.
 * - Uploads go to <name>.part in chunks, so they continue after a time limit,
 *   and are renamed when complete; a sync tool never sees half a file.
 * - Only encrypted archives (.zip.age) are uploaded; the job enforces that.
 *
 * @package RMD\BackupWordpress
 */

namespace RMD\BackupWordpress\Destination;

use phpseclib3\Net\SFTP;
use RMD\BackupWordpress\Domain\ArchiveName;
use RMD\BackupWordpress\Log\Logger;
use RuntimeException;
use Throwable;

defined( 'ABSPATH' ) || exit;

// Streams archives from the local backup folder.
// phpcs:disable WordPress.WP.AlternativeFunctions

final class SftpDestination implements RemoteDestination {

	private const CHUNK   = 4 * 1024 * 1024;
	private const TIMEOUT = 30;

	private ?SFTP $connection = null;

	/**
	 * @param array{host: string, port: int, user: string, password: string, path: string, folder: string, fingerprint: string} $settings
	 */
	public function __construct( private readonly array $settings ) {}

	public static function from_settings(): self {
		return new self( SftpSettings::get() );
	}

	public function label(): string {
		return 'SFTP ' . $this->settings['host'];
	}

	public function is_configured(): bool {
		return '' !== $this->settings['host'] && '' !== $this->settings['user'] && '' !== $this->settings['password'];
	}

	/**
	 * Folder for this site's archives: <base>/<folder>. The base is the
	 * configured path (relative to the login folder unless absolute). Without
	 * one, the account's own folder is used: HiDrive logs in at "/", where only
	 * /users/<name> is writable, so that folder wins when it exists.
	 */
	public function directory(): string {
		$path = rtrim( $this->settings['path'], '/' );
		if ( '' === $path && null !== $this->connection && $this->connection->is_dir( '/users/' . $this->settings['user'] ) ) {
			$path = '/users/' . $this->settings['user'];
		}
		return ( '' === $path ? '' : $path . '/' ) . $this->settings['folder'];
	}

	/**
	 * Connects without a pinned key, checks login and write access and returns
	 * the server's host key fingerprint. Used by "Test connection".
	 *
	 * @return array{fingerprint: string, directory: string, backups: int}
	 */
	public function test(): array {
		$sftp = $this->connect( false );
		$dir  = $this->ensure_directory( $sftp );
		$file = $dir . '/.rmd-backup-test-' . bin2hex( random_bytes( 4 ) );
		if ( ! $sftp->put( $file, 'test' ) || ! $sftp->delete( $file, false ) ) {
			throw new RuntimeException( esc_html( self::error( $sftp, __( 'The test file could not be written and deleted.', 'rmd-backup-wordpress' ) ) ) );
		}
		return [
			'fingerprint' => self::fingerprint( $sftp ),
			'directory'   => $dir,
			'backups'     => count( $this->backups() ),
		];
	}

	public function upload( array &$state, string $file, string $name, float $deadline, Logger $log ): bool {
		$size = filesize( $file );
		if ( false === $size ) {
			throw new RuntimeException( esc_html( 'The archive to upload is missing: ' . $file ) );
		}

		$sftp   = $this->connect( true );
		$dir    = $this->ensure_directory( $sftp );
		$part   = $dir . '/' . $name . '.part';
		$target = $dir . '/' . $name;

		$offset = $sftp->filesize( $part );
		$offset = false === $offset ? 0 : (int) $offset;
		if ( $offset > $size ) {
			$sftp->delete( $part, false );
			$offset = 0;
		}
		if ( 0 === (int) ( $state['started'] ?? 0 ) ) {
			$state['started'] = time();
			$log->info( sprintf( 'Upload to %s:%s started (%s)', $this->settings['host'], $target, size_format( $size ) ) );
		} elseif ( $offset > 0 ) {
			$log->info( sprintf( 'Upload continues at %s', size_format( $offset ) ) );
		}

		$handle = fopen( $file, 'rb' );
		if ( false === $handle ) {
			throw new RuntimeException( esc_html( 'The archive could not be read: ' . $file ) );
		}
		try {
			fseek( $handle, $offset );
			while ( $offset < $size && microtime( true ) < $deadline ) {
				$chunk = fread( $handle, self::CHUNK );
				if ( false === $chunk || '' === $chunk ) {
					throw new RuntimeException( esc_html( 'The archive could not be read: ' . $file ) );
				}
				if ( ! $sftp->put( $part, $chunk, SFTP::SOURCE_STRING, $offset ) ) {
					throw new RuntimeException( esc_html( self::error( $sftp, 'Writing to the SFTP server failed.' ) ) );
				}
				$offset         += strlen( $chunk );
				$state['offset'] = $offset;
			}
		} finally {
			fclose( $handle );
		}

		if ( $offset < $size ) {
			return false;
		}

		$sftp->clearStatCache();
		$remote = $sftp->filesize( $part );
		if ( (int) $remote !== $size ) {
			$sftp->delete( $part, false );
			throw new RuntimeException( esc_html( sprintf( 'The uploaded file has %d instead of %d bytes and was removed.', (int) $remote, $size ) ) );
		}
		if ( $sftp->file_exists( $target ) ) {
			$sftp->delete( $target, false );
		}
		if ( ! $sftp->rename( $part, $target ) ) {
			throw new RuntimeException( esc_html( self::error( $sftp, 'The uploaded file could not be renamed.' ) ) );
		}
		$log->info( sprintf( 'Upload complete: %s:%s', $this->settings['host'], $target ) );
		return true;
	}

	public function backups(): array {
		$sftp    = $this->connect( true );
		$list    = $sftp->rawlist( $this->directory() );
		$backups = [];
		foreach ( is_array( $list ) ? $list : [] as $name => $attributes ) {
			$name   = (string) $name;
			$parsed = ArchiveName::parse( $name );
			if ( null === $parsed || ! ArchiveName::is_archive( $name ) || ! is_array( $attributes ) ) {
				continue;
			}
			$backups[] = [
				'name'    => $name,
				'id'      => $parsed['id'],
				'profile' => $parsed['profile'],
				'size'    => (int) ( $attributes['size'] ?? 0 ),
				'created' => (int) ( $attributes['mtime'] ?? 0 ),
			];
		}
		usort( $backups, static fn( array $a, array $b ): int => strcmp( $b['name'], $a['name'] ) );
		return $backups;
	}

	public function delete( string $name ): void {
		if ( ! ArchiveName::is_archive( $name ) ) {
			throw new RuntimeException( esc_html__( 'Invalid file name.', 'rmd-backup-wordpress' ) );
		}
		$sftp = $this->connect( true );
		if ( ! $sftp->delete( $this->directory() . '/' . $name, false ) ) {
			throw new RuntimeException( esc_html( self::error( $sftp, 'Deleting ' . $name . ' on the SFTP server failed.' ) ) );
		}
	}

	/**
	 * @param bool $pinned Require the stored host key fingerprint.
	 */
	private function connect( bool $pinned ): SFTP {
		if ( null !== $this->connection ) {
			return $this->connection;
		}
		if ( ! $this->is_configured() ) {
			throw new RuntimeException( esc_html__( 'The SFTP connection is not set up.', 'rmd-backup-wordpress' ) );
		}
		if ( $pinned && '' === $this->settings['fingerprint'] ) {
			throw new RuntimeException( esc_html__( 'The host key of the SFTP server is not known yet. Run "Test connection" in the settings first.', 'rmd-backup-wordpress' ) );
		}

		try {
			$sftp = new SFTP( $this->settings['host'], $this->settings['port'], self::TIMEOUT );
			$key  = self::fingerprint( $sftp );
		} catch ( Throwable $e ) {
			throw new RuntimeException(
				esc_html(
					sprintf(
						/* translators: 1: host, 2: error message */
						__( 'No connection to %1$s: %2$s', 'rmd-backup-wordpress' ),
						$this->settings['host'],
						$e->getMessage()
					)
				)
			);
		}

		if ( '' !== $this->settings['fingerprint'] && ! hash_equals( $this->settings['fingerprint'], $key ) ) {
			throw new RuntimeException(
				esc_html(
					sprintf(
						/* translators: 1: expected fingerprint, 2: received fingerprint */
						__( 'The host key of the SFTP server has changed (expected %1$s, received %2$s). The upload was stopped. If the provider changed its key, reset the host key in the settings.', 'rmd-backup-wordpress' ),
						$this->settings['fingerprint'],
						$key
					)
				)
			);
		}

		if ( ! $sftp->login( $this->settings['user'], $this->settings['password'] ) ) {
			throw new RuntimeException( esc_html( self::error( $sftp, __( 'The SFTP login failed. Check user name and password.', 'rmd-backup-wordpress' ) ) ) );
		}

		$this->connection = $sftp;
		return $sftp;
	}

	private function ensure_directory( SFTP $sftp ): string {
		$dir = $this->directory();
		if ( ! $sftp->is_dir( $dir ) && ! $sftp->mkdir( $dir, -1, true ) ) {
			throw new RuntimeException(
				esc_html(
					self::error(
						$sftp,
						sprintf(
							/* translators: %s: folder path */
							__( 'The folder %s could not be created on the SFTP server.', 'rmd-backup-wordpress' ),
							$dir
						)
					)
				)
			);
		}
		return $dir;
	}

	/**
	 * SHA-256 fingerprint of the server's host key, as "ssh-keygen -l" shows it.
	 */
	private static function fingerprint( SFTP $sftp ): string {
		$key   = $sftp->getServerPublicHostKey();
		$parts = is_string( $key ) ? explode( ' ', $key ) : [];
		$blob  = isset( $parts[1] ) ? base64_decode( $parts[1], true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- SSH key format.
		if ( false === $blob || '' === $blob ) {
			throw new RuntimeException( 'The SFTP server did not send a host key.' );
		}
		return $parts[0] . ' SHA256:' . rtrim( base64_encode( hash( 'sha256', $blob, true ) ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	private static function error( SFTP $sftp, string $message ): string {
		$detail = $sftp->getLastSFTPError() ?: $sftp->getLastError();
		return '' === (string) $detail ? $message : $message . ' (' . $detail . ')';
	}
}
