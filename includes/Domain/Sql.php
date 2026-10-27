<?php
/**
 * SQL text helpers for writing a dump that phpMyAdmin (MySQL/MariaDB) can import.
 *
 * @package RMD\BackupWordpress
 */

namespace RMD\BackupWordpress\Domain;

final class Sql {

	/** Collation that exists on MySQL 5.6+ and all MariaDB versions. */
	public const PORTABLE_COLLATION = 'utf8mb4_unicode_520_ci';

	/** Column value kinds, decided from the column type. */
	public const KIND_TEXT   = 'text';
	public const KIND_BINARY = 'binary';
	public const KIND_OTHER  = 'other';

	/**
	 * Single-quoted string literal, escaped like mysqldump does.
	 */
	public static function quote( string $value ): string {
		return "'" . strtr(
			$value,
			[
				'\\'   => '\\\\',
				"\0"   => '\\0',
				"\n"   => '\\n',
				"\r"   => '\\r',
				"'"    => "\\'",
				"\x1a" => '\\Z',
			]
		) . "'";
	}

	public static function hex( string $value ): string {
		return '' === $value ? "''" : '0x' . bin2hex( $value );
	}

	public static function identifier( string $name ): string {
		return '`' . str_replace( '`', '``', $name ) . '`';
	}

	public static function literal( ?string $value, string $kind ): string {
		if ( null === $value ) {
			return 'NULL';
		}
		return self::KIND_BINARY === $kind ? self::hex( $value ) : self::quote( $value );
	}

	/**
	 * Column kind from a type as reported by SHOW COLUMNS (e.g. "varchar(255)", "longblob", "bigint(20) unsigned").
	 */
	public static function kind( string $type ): string {
		$type = strtolower( $type );
		if ( preg_match( '/^(?:binary|varbinary|tinyblob|blob|mediumblob|longblob|bit|geometry|point|linestring|polygon|multipoint|multilinestring|multipolygon|geometrycollection)\b/', $type ) ) {
			return self::KIND_BINARY;
		}
		if ( preg_match( '/^(?:char|varchar|tinytext|text|mediumtext|longtext|enum|set|json)\b/', $type ) ) {
			return self::KIND_TEXT;
		}
		return self::KIND_OTHER;
	}

	/**
	 * Renames tables in a CREATE TABLE statement (including foreign key references)
	 * and optionally replaces collations that older servers do not know.
	 *
	 * @param array<string, string> $table_map Local table name => name in the dump.
	 */
	public static function rewrite_create( string $sql, array $table_map, bool $portable_collations ): string {
		if ( [] !== $table_map ) {
			$sql = (string) preg_replace_callback(
				'/`((?:[^`]|``)+)`/',
				static function ( array $found ) use ( $table_map ): string {
					$name = str_replace( '``', '`', $found[1] );
					return isset( $table_map[ $name ] ) ? self::identifier( $table_map[ $name ] ) : $found[0];
				},
				$sql
			);
		}

		return $portable_collations ? self::portable_collations( $sql ) : $sql;
	}

	/**
	 * MySQL 8 (utf8mb4_0900_*) and MariaDB 10.10+ (utf8mb4_uca1400_*) collations
	 * fail on older servers; utf8mb3 is spelled utf8 there.
	 */
	public static function portable_collations( string $sql ): string {
		$sql = (string) preg_replace( '/\butf8mb4_\w*?(?:0900|uca1400)\w*\b/i', self::PORTABLE_COLLATION, $sql );
		return (string) preg_replace( '/\butf8mb3(?=\b|_)/i', 'utf8', $sql );
	}
}
