<?php
declare(strict_types=1);

namespace RMD\BackupWordpress\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RMD\BackupWordpress\Domain\Retention;

final class RetentionTest extends TestCase {

	private const NOW = 1800000000;

	/**
	 * @return list<array{name: string, created: int}>
	 */
	private static function items( int ...$days_ago ): array {
		return array_map(
			static fn( int $days ): array => [
				'name'    => 'b' . $days,
				'created' => self::NOW - $days * 86400,
			],
			$days_ago
		);
	}

	public function test_keeps_newest_count(): void {
		self::assertSame( [ 'b3', 'b4' ], Retention::expired( self::items( 4, 0, 2, 1, 3 ), 3, 0, self::NOW ) );
	}

	public function test_keeps_by_age(): void {
		self::assertSame( [ 'b10', 'b40' ], Retention::expired( self::items( 1, 10, 5, 40 ), 0, 7, self::NOW ) );
	}

	public function test_both_rules_combine(): void {
		self::assertSame( [ 'b2', 'b20' ], Retention::expired( self::items( 0, 1, 2, 20 ), 2, 10, self::NOW ) );
	}

	public function test_newest_is_always_kept(): void {
		self::assertSame( [ 'b60' ], Retention::expired( self::items( 50, 60 ), 1, 7, self::NOW ) );
		self::assertSame( [], Retention::expired( self::items( 50 ), 1, 7, self::NOW ) );
	}

	public function test_no_limits_keep_everything(): void {
		self::assertSame( [], Retention::expired( self::items( 1, 100, 1000 ), 0, 0, self::NOW ) );
	}
}
