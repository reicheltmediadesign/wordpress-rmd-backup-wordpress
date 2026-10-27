<?php
declare(strict_types=1);

namespace RMD\BackupWordpress\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RMD\BackupWordpress\Domain\Sql;

final class SqlTest extends TestCase {

	public function test_quote_escapes_like_mysqldump(): void {
		self::assertSame( "'it\\'s \\\\ \\n\\r\\0\\Z \"ok\"'", Sql::quote( "it's \\ \n\r\0\x1a \"ok\"" ) );
	}

	public function test_literals(): void {
		self::assertSame( 'NULL', Sql::literal( null, Sql::KIND_TEXT ) );
		self::assertSame( '0x00ff', Sql::literal( "\x00\xff", Sql::KIND_BINARY ) );
		self::assertSame( "''", Sql::literal( '', Sql::KIND_BINARY ) );
		self::assertSame( "'42'", Sql::literal( '42', Sql::KIND_OTHER ) );
	}

	public function test_kind(): void {
		self::assertSame( Sql::KIND_TEXT, Sql::kind( 'longtext' ) );
		self::assertSame( Sql::KIND_TEXT, Sql::kind( 'varchar(191)' ) );
		self::assertSame( Sql::KIND_TEXT, Sql::kind( "enum('a','b')" ) );
		self::assertSame( Sql::KIND_BINARY, Sql::kind( 'longblob' ) );
		self::assertSame( Sql::KIND_BINARY, Sql::kind( 'bit(1)' ) );
		self::assertSame( Sql::KIND_OTHER, Sql::kind( 'bigint(20) unsigned' ) );
		self::assertSame( Sql::KIND_OTHER, Sql::kind( 'datetime' ) );
	}

	public function test_identifier(): void {
		self::assertSame( '`a``b`', Sql::identifier( 'a`b' ) );
	}

	public function test_rewrite_create_renames_tables_and_references(): void {
		$create = "CREATE TABLE `old_posts` (\n  `ID` bigint(20),\n  CONSTRAINT `fk` FOREIGN KEY (`ID`) REFERENCES `old_users` (`ID`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci";

		$result = Sql::rewrite_create(
			$create,
			[
				'old_posts' => 'new_posts',
				'old_users' => 'new_users',
			],
			true
		);

		self::assertStringContainsString( 'CREATE TABLE `new_posts`', $result );
		self::assertStringContainsString( 'REFERENCES `new_users`', $result );
		self::assertStringContainsString( 'COLLATE=utf8mb4_unicode_520_ci', $result );
		self::assertStringContainsString( '`ID` bigint', $result );
	}

	public function test_portable_collations(): void {
		self::assertSame(
			'CHARSET=utf8mb4 COLLATE utf8mb4_unicode_520_ci, utf8mb4_unicode_520_ci, utf8_general_ci, CHARSET=utf8, utf8mb4_unicode_ci',
			Sql::portable_collations( 'CHARSET=utf8mb4 COLLATE utf8mb4_0900_ai_ci, utf8mb4_uca1400_ai_ci, utf8mb3_general_ci, CHARSET=utf8mb3, utf8mb4_unicode_ci' )
		);
	}

	public function test_rewrite_create_without_changes(): void {
		$create = 'CREATE TABLE `t` (`a` int) COLLATE=utf8mb4_0900_ai_ci';
		self::assertSame( $create, Sql::rewrite_create( $create, [], false ) );
	}
}
