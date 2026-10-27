<?php
declare(strict_types=1);

namespace RMD\BackupWordpress\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RMD\BackupWordpress\Domain\Schedule;

final class ScheduleTest extends TestCase {

	private static function at( string $time ): DateTimeImmutable {
		return new DateTimeImmutable( $time, new DateTimeZone( 'Europe/Berlin' ) );
	}

	private static function next( string $type, string $now, string $time = '03:00', int $weekday = 1, int $monthday = 1 ): string {
		$next = Schedule::next( $type, $time, $weekday, $monthday, self::at( $now ) );
		return null === $next ? '' : $next->format( 'Y-m-d H:i' );
	}

	public function test_manual_has_no_next_run(): void {
		self::assertNull( Schedule::next( 'manual', '03:00', 1, 1, self::at( '2026-10-27 10:00' ) ) );
	}

	public function test_hourly(): void {
		self::assertSame( '2026-10-27 10:15', self::next( 'hourly', '2026-10-27 10:00', '00:15' ) );
		self::assertSame( '2026-10-27 11:15', self::next( 'hourly', '2026-10-27 10:15', '00:15' ) );
		self::assertSame( '2026-10-28 00:15', self::next( 'hourly', '2026-10-27 23:30', '00:15' ) );
	}

	public function test_daily(): void {
		self::assertSame( '2026-10-27 03:00', self::next( 'daily', '2026-10-27 02:59' ) );
		self::assertSame( '2026-10-28 03:00', self::next( 'daily', '2026-10-27 03:00' ) );
		self::assertSame( '2026-11-01 03:00', self::next( 'daily', '2026-10-31 12:00' ) );
	}

	public function test_daily_across_daylight_saving_change(): void {
		$next = Schedule::next( 'daily', '03:00', 1, 1, self::at( '2026-10-24 12:00' ) );
		self::assertNotNull( $next );
		self::assertSame( '2026-10-25 03:00 +01:00', $next->format( 'Y-m-d H:i P' ) );
	}

	public function test_weekly(): void {
		// 2026-10-27 is a Tuesday.
		self::assertSame( '2026-11-02 03:00', self::next( 'weekly', '2026-10-27 10:00', '03:00', 1 ) );
		self::assertSame( '2026-10-27 22:00', self::next( 'weekly', '2026-10-27 10:00', '22:00', 2 ) );
		self::assertSame( '2026-11-03 03:00', self::next( 'weekly', '2026-10-27 10:00', '03:00', 2 ) );
		self::assertSame( '2026-11-01 03:00', self::next( 'weekly', '2026-10-27 10:00', '03:00', 7 ) );
	}

	public function test_monthly(): void {
		self::assertSame( '2026-10-28 03:00', self::next( 'monthly', '2026-10-27 10:00', '03:00', 1, 28 ) );
		self::assertSame( '2026-11-01 03:00', self::next( 'monthly', '2026-10-27 10:00', '03:00', 1, 1 ) );
		self::assertSame( '2027-01-15 03:00', self::next( 'monthly', '2026-12-15 03:00', '03:00', 1, 15 ) );
		self::assertSame( '2027-02-28 03:00', self::next( 'monthly', '2027-01-31 10:00', '03:00', 1, 28 ) );
	}
}
