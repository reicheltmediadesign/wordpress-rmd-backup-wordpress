<?php
declare(strict_types=1);

namespace RMD\BackupWordpress\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RMD\BackupWordpress\Domain\Compression;

final class CompressionTest extends TestCase {

	public function test_compressed_formats_and_large_files_are_stored(): void {
		self::assertTrue( Compression::store_only( 'wp-content/uploads/photo.JPG', 1000 ) );
		self::assertTrue( Compression::store_only( 'database.sql.gz', 1000 ) );
		self::assertTrue( Compression::store_only( 'huge.sql', Compression::LARGE_FILE ) );
		self::assertFalse( Compression::store_only( 'wp-includes/functions.php', 1000 ) );
		self::assertFalse( Compression::store_only( 'README', 10 ) );
	}
}
