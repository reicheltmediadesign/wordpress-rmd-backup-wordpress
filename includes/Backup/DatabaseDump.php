<?php
/**
 * Writes the SQL dump table by table in small chunks (keyset paging on
 * integer primary keys, offset paging otherwise). Values are written as they
 * are; the dump restores the site exactly as it was.
 *
 * @package RMD\BackupWordpress
 */

namespace RMD\BackupWordpress\Backup;

use RMD\BackupWordpress\Domain\Profile;
use RMD\BackupWordpress\Domain\Sql;
use RMD\BackupWordpress\Log\Logger;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class DatabaseDump {

	private const ROWS_PER_QUERY  = 500;
	private const STATEMENT_BYTES = 512 * 1024;

	/**
	 * Initial database state of a job: the list of tables to dump.
	 *
	 * @return array<string, mixed>
	 */
	public static function init( Profile $profile, Logger $log ): array {
		global $wpdb;

		$rows    = $wpdb->get_results( 'SHOW FULL TABLES', ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$tables  = [];
		$views   = [];
		$skipped = [];

		foreach ( (array) $rows as $row ) {
			$name = (string) $row[0];
			if ( ! $profile->db_all_tables && ! str_starts_with( $name, $wpdb->prefix ) ) {
				continue;
			}
			if ( 'BASE TABLE' !== strtoupper( (string) ( $row[1] ?? '' ) ) ) {
				$views[] = $name;
				continue;
			}
			$base = str_starts_with( $name, $wpdb->prefix ) ? substr( $name, strlen( $wpdb->prefix ) ) : $name;
			if ( in_array( $name, $profile->db_exclude_tables, true ) || in_array( $base, $profile->db_exclude_tables, true ) ) {
				$skipped[] = $name;
				continue;
			}
			$tables[] = [
				'name' => $name,
				'base' => $base,
			];
		}

		if ( [] === $tables ) {
			throw new RuntimeException( esc_html__( 'No database tables to back up were found.', 'rmd-backup-wordpress' ) );
		}
		if ( [] !== $skipped ) {
			$log->info( 'Tables left out by the profile: ' . implode( ', ', $skipped ) );
		}
		if ( [] !== $views ) {
			$log->info( 'Views are not backed up: ' . implode( ', ', $views ) );
		}

		return [
			'state'  => 'pending',
			'tables' => $tables,
			'index'  => 0,
			'bytes'  => 0,
			'rows'   => 0,
			'table'  => null,
		];
	}

	/**
	 * @param array<string, mixed> $job
	 */
	public static function run( array &$job, Profile $profile, float $deadline, Logger $log ): void {
		global $wpdb;

		$db   = &$job['db'];
		$file = new DumpFile( Job::tmp( $job ) . '/' . DumpFile::name(), (int) $db['bytes'] );

		// Dump TIMESTAMP values in UTC; the header sets the importing session to UTC as well.
		$wpdb->query( "SET time_zone = '+00:00'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		try {
			if ( 'pending' === $db['state'] ) {
				$file->write( self::header() );
				$db['state'] = 'tables';
			}

			$total = count( $db['tables'] );
			while ( $db['index'] < $total && microtime( true ) < $deadline ) {
				$table = $db['tables'][ $db['index'] ];
				if ( null === $db['table'] ) {
					$db['table'] = self::start_table( $file, $table['name'] );
				}

				if ( self::export_rows( $db['table'], $file, $table, $profile, $deadline ) ) {
					$file->write( "\n" );
					$log->info( sprintf( 'Table %s: %s rows', $table['name'], number_format( (int) $db['table']['offset'], 0, '', ' ' ) ) );
					$db['rows'] += (int) $db['table']['offset'];
					$db['table'] = null;
					++$db['index'];
				}
			}

			if ( $db['index'] >= $total ) {
				$file->write( "SET FOREIGN_KEY_CHECKS = 1;\n" );
				$db['state'] = 'done';
			}
		} finally {
			$db['bytes'] = $file->close();
		}
	}

	private static function header(): string {
		return implode(
			"\n",
			[
				'-- RMD Backup WordPress ' . RMD_BWP_VERSION,
				'-- Site:    ' . home_url(),
				'-- Created: ' . wp_date( 'Y-m-d H:i:s T' ),
				'',
				'/*!40101 SET NAMES utf8mb4 */;',
				"SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';",
				"SET time_zone = '+00:00';",
				'SET FOREIGN_KEY_CHECKS = 0;',
				'',
				'',
			]
		);
	}

	/**
	 * Writes DROP/CREATE and returns the state for reading the rows.
	 *
	 * @return array<string, mixed>
	 */
	private static function start_table( DumpFile $file, string $name ): array {
		global $wpdb;

		$create = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $name ), ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! is_array( $create ) || ! isset( $create[1] ) ) {
			throw new RuntimeException( esc_html( sprintf( 'SHOW CREATE TABLE failed for %s: %s', $name, $wpdb->last_error ) ) );
		}

		$file->write( '-- Table ' . $name . "\n" );
		$file->write( 'DROP TABLE IF EXISTS ' . Sql::identifier( $name ) . ";\n" );
		$file->write( (string) $create[1] . ";\n" );

		$columns = [];
		$keys    = [];
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SHOW FULL COLUMNS FROM %i', $name ), ARRAY_A ) as $column ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( preg_match( '/\b(?:VIRTUAL|STORED|PERSISTENT) GENERATED\b/i', (string) $column['Extra'] ) ) {
				continue;
			}
			$columns[] = [
				'name' => (string) $column['Field'],
				'kind' => Sql::kind( (string) $column['Type'] ),
			];
			if ( 'PRI' === $column['Key'] ) {
				$keys[] = [
					'name' => (string) $column['Field'],
					'int'  => (bool) preg_match( '/^(?:tiny|small|medium|big)?int\b/i', (string) $column['Type'] ),
				];
			}
		}

		return [
			'columns' => $columns,
			'keys'    => array_column( $keys, 'name' ),
			'keyset'  => 1 === count( $keys ) && $keys[0]['int'] ? $keys[0]['name'] : '',
			'last'    => null,
			'offset'  => 0,
		];
	}

	/**
	 * Exports rows until the table is complete (true) or the time is up (false).
	 *
	 * @param array<string, mixed> $state
	 * @param array<string, mixed> $table
	 */
	private static function export_rows( array &$state, DumpFile $file, array $table, Profile $profile, float $deadline ): bool {
		global $wpdb;

		$columns = $state['columns'];
		$select  = implode( ', ', array_map( static fn( array $c ): string => Sql::identifier( $c['name'] ), $columns ) );
		$insert  = 'INSERT INTO ' . Sql::identifier( $table['name'] ) . ' (' . $select . ') VALUES ';
		$where   = self::filter( $table['name'], $profile );

		do {
			$conditions = $where;
			if ( '' !== $state['keyset'] && null !== $state['last'] ) {
				$conditions[] = $wpdb->prepare( '%i > %d', $state['keyset'], $state['last'] );
			}
			$sql = 'SELECT ' . $select . ' FROM ' . Sql::identifier( $table['name'] )
				. ( [] === $conditions ? '' : ' WHERE ' . implode( ' AND ', $conditions ) )
				. ( [] === $state['keys'] ? '' : ' ORDER BY ' . implode( ', ', array_map( [ Sql::class, 'identifier' ], $state['keys'] ) ) )
				. ' LIMIT ' . self::ROWS_PER_QUERY
				. ( '' === $state['keyset'] ? ' OFFSET ' . (int) $state['offset'] : '' );

			$rows = $wpdb->get_results( $sql, ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Identifiers are quoted, values prepared above.
			if ( '' !== $wpdb->last_error ) {
				throw new RuntimeException( esc_html( sprintf( 'Reading %s failed: %s', $table['name'], $wpdb->last_error ) ) );
			}

			$values = [];
			$length = 0;
			foreach ( (array) $rows as $row ) {
				$literals = [];
				foreach ( $columns as $i => $column ) {
					$literals[] = Sql::literal( $row[ $i ], $column['kind'] );
				}
				$values[] = '(' . implode( ',', $literals ) . ')';
				$length  += strlen( end( $values ) );
				if ( $length >= self::STATEMENT_BYTES ) {
					$file->write( $insert . "\n" . implode( ",\n", $values ) . ";\n" );
					$values = [];
					$length = 0;
				}
			}
			if ( [] !== $values ) {
				$file->write( $insert . "\n" . implode( ",\n", $values ) . ";\n" );
			}

			$count = count( (array) $rows );
			if ( $count > 0 && '' !== $state['keyset'] ) {
				$key_index     = (int) array_search( $state['keyset'], array_column( $columns, 'name' ), true );
				$state['last'] = (int) end( $rows )[ $key_index ];
			}
			$state['offset'] += $count;

			if ( $count < self::ROWS_PER_QUERY ) {
				return true;
			}
		} while ( microtime( true ) < $deadline );

		return false;
	}

	/**
	 * Leaves out the running job's own state and, if the profile says so, transients.
	 *
	 * @return list<string>
	 */
	private static function filter( string $table, Profile $profile ): array {
		global $wpdb;

		if ( $table !== $wpdb->options ) {
			return [];
		}
		$where = [ $wpdb->prepare( 'option_name NOT IN (%s, %s, %s)', Job::OPTION, Job::LOCK, Job::CANCEL ) ];
		if ( $profile->db_skip_transients ) {
			$where[] = $wpdb->prepare(
				'option_name NOT LIKE %s AND option_name NOT LIKE %s',
				$wpdb->esc_like( '_transient_' ) . '%',
				$wpdb->esc_like( '_site_transient_' ) . '%'
			);
		}
		return $where;
	}
}
