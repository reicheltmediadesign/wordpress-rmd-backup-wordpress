<?php
/**
 * Global settings and profile storage.
 *
 * @package RMD\BackupWordpress
 */

namespace RMD\BackupWordpress;

use RMD\BackupWordpress\Domain\Age;
use RMD\BackupWordpress\Domain\Profile;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION          = 'rmd_bwp_settings';
	public const PROFILES_OPTION = 'rmd_bwp_profiles';
	public const KEY_OPTION      = 'rmd_bwp_key';

	/**
	 * @return array{filename_site: bool, log_keep_days: int, log_keep_count: int, notify_email: string, age_recipient: string}
	 */
	public static function defaults(): array {
		return [
			'filename_site'  => true,
			'log_keep_days'  => 90,
			'log_keep_count' => 100,
			'notify_email'   => '',
			'age_recipient'  => '',
		];
	}

	/**
	 * @return array{filename_site: bool, log_keep_days: int, log_keep_count: int, notify_email: string, age_recipient: string}
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION, [] );
		return self::parse( is_array( $stored ) ? $stored : [] )['settings'];
	}

	/**
	 * @param array<mixed> $raw
	 * @return array{settings: array{filename_site: bool, log_keep_days: int, log_keep_count: int, notify_email: string, age_recipient: string}, errors: list<string>}
	 */
	public static function parse( array $raw ): array {
		$defaults = self::defaults();
		$errors   = [];

		$email = isset( $raw['notify_email'] ) && is_string( $raw['notify_email'] ) ? trim( $raw['notify_email'] ) : '';
		if ( '' !== $email && ! is_email( $email ) ) {
			$errors[] = 'invalid_email';
			$email    = '';
		}

		$recipient = isset( $raw['age_recipient'] ) && is_string( $raw['age_recipient'] ) ? trim( $raw['age_recipient'] ) : '';
		if ( '' !== $recipient && ! Age::is_recipient( $recipient ) ) {
			$errors[]  = str_starts_with( strtolower( $recipient ), 'age1pq1' ) ? 'pq_recipient' : 'invalid_recipient';
			$recipient = '';
		}

		return [
			'settings' => [
				'filename_site'  => array_key_exists( 'filename_site', $raw ) ? in_array( $raw['filename_site'], [ true, 1, '1', 'on' ], true ) : $defaults['filename_site'],
				'log_keep_days'  => self::int( $raw['log_keep_days'] ?? null, $defaults['log_keep_days'], 0, 3650 ),
				'log_keep_count' => self::int( $raw['log_keep_count'] ?? null, $defaults['log_keep_count'], 0, 10000 ),
				'notify_email'   => $email,
				'age_recipient'  => $recipient,
			],
			'errors'   => $errors,
		];
	}

	/**
	 * @param array<mixed> $raw
	 * @return list<string> Error codes; empty when saved.
	 */
	public static function save( array $raw ): array {
		$result = self::parse( $raw );
		if ( [] === $result['errors'] ) {
			update_option( self::OPTION, $result['settings'], false );
		}
		return $result['errors'];
	}

	/**
	 * @return array<string, Profile>
	 */
	public static function profiles(): array {
		$stored   = get_option( self::PROFILES_OPTION, [] );
		$profiles = [];
		foreach ( is_array( $stored ) ? $stored : [] as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$profile = Profile::parse( $raw )['profile'];
			if ( $profile->is_complete() ) {
				$profiles[ $profile->id ] = $profile;
			}
		}
		return $profiles;
	}

	public static function profile( string $id ): ?Profile {
		return self::profiles()[ $id ] ?? null;
	}

	/**
	 * Saves a profile. A new profile gets a unique id derived from its name.
	 */
	public static function save_profile( Profile $profile, bool $is_new ): Profile {
		$profiles = self::profiles();

		if ( $is_new ) {
			$base = Profile::slug( $profile->name );
			$id   = $base;
			for ( $i = 2; isset( $profiles[ $id ] ); $i++ ) {
				$id = substr( $base, 0, 36 ) . '-' . $i;
			}
			$profile = Profile::parse( [ 'id' => $id ] + $profile->to_array() )['profile'];
		}

		$profiles[ $profile->id ] = $profile;
		self::store( $profiles );
		return $profile;
	}

	public static function delete_profile( string $id ): void {
		$profiles = self::profiles();
		unset( $profiles[ $id ] );
		self::store( $profiles );
	}

	/**
	 * Creates the prepared profiles on first activation.
	 */
	public static function install_presets(): void {
		if ( false !== get_option( self::PROFILES_OPTION ) ) {
			return;
		}
		$presets  = Profile::presets(
			[
				'full'     => __( 'Full backup', 'rmd-backup-wordpress' ),
				'database' => __( 'Database only', 'rmd-backup-wordpress' ),
				'files'    => __( 'Files only', 'rmd-backup-wordpress' ),
				'content'  => __( 'Content only (wp-content)', 'rmd-backup-wordpress' ),
				'uploads'  => __( 'Uploads only', 'rmd-backup-wordpress' ),
			]
		);
		$profiles = [];
		foreach ( $presets as $raw ) {
			$profile                  = Profile::parse( $raw )['profile'];
			$profiles[ $profile->id ] = $profile;
		}
		self::store( $profiles );
	}

	/**
	 * age recipient (public key) all backups are encrypted for, or "" for none.
	 * RMD_BWP_AGE_RECIPIENT in wp-config.php takes precedence, so it cannot be
	 * changed from the admin screen.
	 */
	public static function recipient(): string {
		if ( self::recipient_locked() ) {
			return trim( (string) RMD_BWP_AGE_RECIPIENT );
		}
		return self::get()['age_recipient'];
	}

	public static function recipient_locked(): bool {
		return defined( 'RMD_BWP_AGE_RECIPIENT' ) && is_string( RMD_BWP_AGE_RECIPIENT ) && '' !== trim( RMD_BWP_AGE_RECIPIENT );
	}

	/**
	 * Secret for the background loopback requests that continue a backup.
	 */
	public static function key(): string {
		$key = get_option( self::KEY_OPTION, '' );
		if ( ! is_string( $key ) || strlen( $key ) < 32 ) {
			$key = wp_generate_password( 40, false );
			update_option( self::KEY_OPTION, $key, false );
		}
		return $key;
	}

	/**
	 * @param array<string, Profile> $profiles
	 */
	private static function store( array $profiles ): void {
		update_option( self::PROFILES_OPTION, array_values( array_map( static fn( Profile $p ): array => $p->to_array(), $profiles ) ), false );
	}

	/**
	 * @param mixed $value
	 */
	private static function int( $value, int $fallback, int $min, int $max ): int {
		if ( ! is_int( $value ) && ! ( is_string( $value ) && preg_match( '/^\d+$/', trim( $value ) ) ) ) {
			return $fallback;
		}
		return max( $min, min( $max, (int) $value ) );
	}
}
