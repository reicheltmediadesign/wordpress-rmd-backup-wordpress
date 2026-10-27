<?php
/**
 * A backup profile: what to back up, when and how long to keep it. The only
 * place that defines the stored shape; everything read from the database or a
 * form goes through Profile::parse().
 *
 * @package RMD\BackupWordpress
 */

namespace RMD\BackupWordpress\Domain;

final class Profile {

	/** Parts of a site that can be backed up; see Layout for the folders behind them. */
	public const PARTS = [ 'database', 'core', 'config', 'plugins', 'themes', 'uploads', 'content' ];

	public const FILE_PARTS = [ 'core', 'config', 'plugins', 'themes', 'uploads', 'content' ];

	public const SCHEDULES = [ 'manual', 'hourly', 'daily', 'weekly', 'monthly' ];

	/** Notification: none, only when a run ended with warnings or errors, after every run. */
	public const NOTIFY_MODES = [ 'off', 'problems', 'always' ];

	public const DEFAULT_EXCLUDES = [
		'.git/',
		'.svn/',
		'node_modules/',
		'/wp-content/cache/',
		'/wp-content/upgrade/',
		'/wp-content/upgrade-temp-backup/',
		'/wp-content/ai1wm-backups/',
		'/wp-content/updraft/',
		'/wp-content/backups-dup-lite/',
		'/wp-content/wpvividbackups/',
		'/wp-content/uploads/backwpup-*/',
		'*.log',
		'.DS_Store',
		'Thumbs.db',
	];

	/**
	 * @param list<string> $parts
	 * @param list<string> $exclude_patterns
	 * @param list<string> $db_exclude_tables
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $name,
		public readonly array $parts,
		public readonly array $exclude_patterns,
		public readonly bool $db_all_tables,
		public readonly array $db_exclude_tables,
		public readonly bool $db_skip_transients,
		public readonly string $schedule,
		public readonly string $time,
		public readonly int $weekday,
		public readonly int $monthday,
		public readonly int $keep_count,
		public readonly int $keep_days,
		public readonly string $notify,
		public readonly string $notify_email
	) {}

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return [
			'id'                 => '',
			'name'               => '',
			'parts'              => self::PARTS,
			'exclude_patterns'   => self::DEFAULT_EXCLUDES,
			'db_all_tables'      => false,
			'db_exclude_tables'  => [],
			'db_skip_transients' => true,
			'schedule'           => 'manual',
			'time'               => '03:00',
			'weekday'            => 1,
			'monthday'           => 1,
			'keep_count'         => 7,
			'keep_days'          => 0,
			'notify'             => 'problems',
			'notify_email'       => '',
		];
	}

	/**
	 * Profiles created on activation. Names are passed in so they can be translated.
	 *
	 * @param array<string, string> $names Keys: full, database, files, content, uploads.
	 * @return list<array<string, mixed>>
	 */
	public static function presets( array $names ): array {
		$parts = [
			'full'     => self::PARTS,
			'database' => [ 'database' ],
			'files'    => self::FILE_PARTS,
			'content'  => [ 'plugins', 'themes', 'uploads', 'content' ],
			'uploads'  => [ 'uploads' ],
		];

		$presets = [];
		foreach ( $parts as $key => $selected ) {
			$presets[] = [
				'id'    => $key,
				'name'  => $names[ $key ] ?? $key,
				'parts' => $selected,
			] + self::defaults();
		}
		return $presets;
	}

	/**
	 * Normalizes raw data (stored option or submitted form). Invalid values fall
	 * back to the default and are reported as error codes.
	 *
	 * @param array<mixed> $raw
	 * @return array{profile: self, errors: list<string>}
	 */
	public static function parse( array $raw ): array {
		$defaults = self::defaults();
		$errors   = [];

		$name = trim( self::string( $raw['name'] ?? '' ) );
		if ( '' === $name ) {
			$errors[] = 'name_required';
		}

		$parts = array_key_exists( 'parts', $raw ) ? self::lines( $raw['parts'] ) : $defaults['parts'];
		$parts = array_values( array_intersect( self::PARTS, $parts ) );
		if ( [] === $parts ) {
			$errors[] = 'parts_required';
		}

		$schedule = self::choice( $raw, 'schedule', self::SCHEDULES );

		$time = trim( self::string( $raw['time'] ?? $defaults['time'] ) );
		if ( ! preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', $time, $match ) ) {
			$errors[] = 'invalid_time';
			$time     = $defaults['time'];
		} else {
			$time = sprintf( '%02d:%s', (int) $match[1], $match[2] );
		}

		$email = trim( self::string( $raw['notify_email'] ?? '' ) );
		if ( '' !== $email && false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			$errors[] = 'invalid_email';
			$email    = '';
		}

		$id = self::string( $raw['id'] ?? '' );
		if ( ! preg_match( '/^[a-z0-9-]{1,40}$/', $id ) ) {
			$id = self::slug( $name );
		}

		$profile = new self(
			$id,
			$name,
			$parts,
			self::lines( $raw['exclude_patterns'] ?? $defaults['exclude_patterns'] ),
			self::bool( $raw, 'db_all_tables' ),
			array_values( array_filter( self::lines( $raw['db_exclude_tables'] ?? [] ), static fn( string $t ): bool => (bool) preg_match( '/^[A-Za-z0-9_$-]+$/', $t ) ) ),
			self::bool( $raw, 'db_skip_transients' ),
			$schedule,
			$time,
			self::int( $raw, 'weekday', 1, 7 ),
			self::int( $raw, 'monthday', 1, 28 ),
			self::int( $raw, 'keep_count', 0, 1000 ),
			self::int( $raw, 'keep_days', 0, 3650 ),
			self::choice( $raw, 'notify', self::NOTIFY_MODES ),
			$email
		);

		return [
			'profile' => $profile,
			'errors'  => $errors,
		];
	}

	public function has( string $part ): bool {
		return in_array( $part, $this->parts, true );
	}

	public function has_files(): bool {
		return [] !== array_intersect( self::FILE_PARTS, $this->parts );
	}

	public function is_complete(): bool {
		return '' !== $this->name && [] !== $this->parts;
	}

	public function is_scheduled(): bool {
		return 'manual' !== $this->schedule;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return get_object_vars( $this );
	}

	public static function slug( string $name ): string {
		$slug = strtolower( (string) preg_replace( '/[^A-Za-z0-9]+/', '-', $name ) );
		$slug = trim( substr( $slug, 0, 40 ), '-' );
		return '' === $slug ? 'profile' : $slug;
	}

	/**
	 * @param mixed $value
	 */
	private static function string( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * @param array<mixed> $raw
	 */
	private static function bool( array $raw, string $key ): bool {
		if ( ! array_key_exists( $key, $raw ) ) {
			return (bool) self::defaults()[ $key ];
		}
		return in_array( $raw[ $key ], [ true, 1, '1', 'yes', 'on' ], true );
	}

	/**
	 * @param array<mixed> $raw
	 */
	private static function int( array $raw, string $key, int $min, int $max ): int {
		$value = $raw[ $key ] ?? null;
		if ( ! is_int( $value ) && ! ( is_string( $value ) && preg_match( '/^\d+$/', trim( $value ) ) ) ) {
			return (int) self::defaults()[ $key ];
		}
		return max( $min, min( $max, (int) $value ) );
	}

	/**
	 * @param array<mixed> $raw
	 * @param list<string> $choices
	 */
	private static function choice( array $raw, string $key, array $choices ): string {
		$value = self::string( $raw[ $key ] ?? '' );
		return in_array( $value, $choices, true ) ? $value : (string) self::defaults()[ $key ];
	}

	/**
	 * @param mixed $value List or text with one entry per line.
	 * @return list<string>
	 */
	private static function lines( $value ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/\R/', $value ) ?: [];
		}
		if ( ! is_array( $value ) ) {
			return [];
		}
		$lines = [];
		foreach ( $value as $line ) {
			$line = trim( self::string( $line ) );
			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}
		return array_values( array_unique( $lines ) );
	}
}
