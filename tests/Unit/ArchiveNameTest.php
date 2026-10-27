<?php
declare(strict_types=1);

namespace RMD\BackupWordpress\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RMD\BackupWordpress\Domain\ArchiveName;

final class ArchiveNameTest extends TestCase {

	public function test_id_and_parse(): void {
		$id = ArchiveName::id( new DateTimeImmutable( '2026-10-27 03:00:05' ), 'full', 'A1-b2C3x', 'example.com' );
		self::assertSame( '2026-10-27_030005_example.com_full_a1b2c3', $id );
		self::assertTrue( ArchiveName::is_id( $id ) );

		self::assertSame(
			[
				'id'      => $id,
				'site'    => 'example.com',
				'profile' => 'full',
				'date'    => '2026-10-27',
				'time'    => '03:00:05',
				'type'    => 'zip',
			],
			ArchiveName::parse( $id . '.zip' )
		);
		self::assertSame( 'log', ArchiveName::parse( $id . '.log' )['type'] ?? '' );
	}

	public function test_without_site(): void {
		$id = ArchiveName::id( new DateTimeImmutable( '2026-10-27 03:00:05' ), 'full', 'a1b2c3' );
		self::assertSame( '2026-10-27_030005_full_a1b2c3', $id );
		$parsed = ArchiveName::parse( $id . '.zip' );
		self::assertSame( '', $parsed['site'] ?? null );
		self::assertSame( 'full', $parsed['profile'] ?? null );
	}

	public function test_site(): void {
		self::assertSame( 'example.com', ArchiveName::site( 'https://www.Example.com' ) );
		self::assertSame( 'marleninflow.de', ArchiveName::site( 'https://marleninflow.de/' ) );
		self::assertSame( 'localhost-testwordpress', ArchiveName::site( 'http://localhost/testwordpress' ) );
		self::assertSame( 'xn--mller-kva.de-shop', ArchiveName::site( 'https://xn--mller-kva.de/shop/' ) );
		self::assertSame( '', ArchiveName::site( 'not a url' ) );
	}

	public function test_encrypted_archives(): void {
		$parsed = ArchiveName::parse( '2026-10-27_030005_example.com_full_a1b2c3.zip.age' );
		self::assertSame( '2026-10-27_030005_example.com_full_a1b2c3', $parsed['id'] ?? null );
		self::assertSame( ArchiveName::ENCRYPTED, $parsed['type'] ?? null );
		self::assertTrue( ArchiveName::is_archive( '2026-10-27_030005_example.com_full_a1b2c3.zip.age' ) );
		self::assertTrue( ArchiveName::is_archive( '2026-10-27_030005_example.com_full_a1b2c3.zip' ) );
		self::assertFalse( ArchiveName::is_archive( '2026-10-27_030005_example.com_full_a1b2c3.log' ) );
		self::assertNull( ArchiveName::parse( '2026-10-27_030005_example.com_full_a1b2c3.age' ) );
	}

	public function test_site_and_profile_with_hyphens(): void {
		$parsed = ArchiveName::parse( '2026-10-27_030005_localhost-testwordpress_content-only_a1b2c3.log' );
		self::assertSame( 'localhost-testwordpress', $parsed['site'] ?? null );
		self::assertSame( 'content-only', $parsed['profile'] ?? null );
	}

	public function test_rejects_everything_else(): void {
		$names = [
			'../2026-10-27_030005_full_a1b2c3.zip',
			'2026-10-27_030005_full_a1b2c3.php',
			'2026-10-27_030005_Full_a1b2c3.zip',
			'2026-10-27_030005_full_a1b2c.zip',
			'example.com_2026-10-27_030005_full_a1b2c3.zip',
			'2026-10-27_030005_exa_mple.com_full_a1b2c3.zip',
			'.htaccess',
			'index.php',
		];
		foreach ( $names as $name ) {
			self::assertNull( ArchiveName::parse( $name ), $name );
		}
	}
}
