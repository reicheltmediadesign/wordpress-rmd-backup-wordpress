<?php
declare(strict_types=1);

namespace RMD\BackupWordpress\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RMD\BackupWordpress\Domain\Layout;

final class LayoutTest extends TestCase {

	private static function standard( ?string $config = '/srv/www/wp-config.php' ): Layout {
		return new Layout(
			'/srv/www',
			'/srv/www/wp-content',
			'/srv/www/wp-content/plugins',
			'/srv/www/wp-content/mu-plugins',
			'/srv/www/wp-content/themes',
			'/srv/www/wp-content/uploads',
			$config
		);
	}

	public function test_parts_of_folders_and_files(): void {
		$layout = self::standard();
		self::assertSame( 'content', $layout->folder_part( '/srv/www/wp-content', 'core' ) );
		self::assertSame( 'plugins', $layout->folder_part( '/srv/www/wp-content/plugins', 'content' ) );
		self::assertSame( 'content', $layout->folder_part( '/srv/www/wp-content/mu-plugins', 'content' ) );
		self::assertSame( 'uploads', $layout->folder_part( '/SRV/www/wp-content/Uploads', 'content' ) );
		self::assertSame( 'core', $layout->folder_part( '/srv/www/wp-admin', 'core' ) );
		self::assertSame( 'plugins', $layout->folder_part( '/srv/www/wp-content/plugins/akismet', 'plugins' ) );
		self::assertSame( 'config', $layout->file_part( '/srv/www/wp-config.php', 'core' ) );
		self::assertSame( 'core', $layout->file_part( '/srv/www/index.php', 'core' ) );
	}

	public function test_only_needed_folders_are_entered(): void {
		$layout   = self::standard();
		$selected = [ 'uploads' ];
		self::assertTrue( $layout->needed( '/srv/www', 'core', $selected ) );
		self::assertTrue( $layout->needed( '/srv/www/wp-content', 'content', $selected ) );
		self::assertFalse( $layout->needed( '/srv/www/wp-admin', 'core', $selected ) );
		self::assertFalse( $layout->needed( '/srv/www/wp-content/plugins', 'plugins', $selected ) );
		self::assertTrue( $layout->needed( '/srv/www/wp-content/uploads', 'uploads', $selected ) );

		self::assertTrue( $layout->needed( '/srv/www', 'core', [ 'config' ] ) );
		self::assertFalse( $layout->needed( '/srv/www/wp-content', 'content', [ 'config' ] ) );
	}

	public function test_starts_for_standard_install(): void {
		$layout = self::standard();
		self::assertSame(
			[
				[
					'path' => '/srv/www',
					'part' => 'core',
					'dir'  => true,
				],
			],
			$layout->starts( [ 'database', 'uploads' ] )
		);
		self::assertSame( [], $layout->starts( [ 'database' ] ) );
	}

	public function test_moved_content_and_config_outside_root(): void {
		$layout = new Layout(
			'/srv/app/wp',
			'/srv/app/content',
			'/srv/app/content/plugins',
			'/srv/app/content/mu-plugins',
			'/srv/app/content/themes',
			'/srv/app/content/uploads',
			'/srv/app/wp-config.php'
		);

		self::assertSame(
			[
				[
					'path' => '/srv/app/content',
					'part' => 'content',
					'dir'  => true,
				],
				[
					'path' => '/srv/app/wp-config.php',
					'part' => 'config',
					'dir'  => false,
				],
			],
			$layout->starts( [ 'themes', 'config' ] )
		);

		self::assertSame( 'wp-content/themes/twentyfive/style.css', $layout->archive_path( '/srv/app/content/themes/twentyfive/style.css' ) );
		self::assertSame( 'wp-content/index.php', $layout->archive_path( '/srv/app/content/index.php' ) );
		self::assertSame( 'wp-config.php', $layout->archive_path( '/srv/app/wp-config.php' ) );
		self::assertSame( 'wp-admin/index.php', $layout->archive_path( '/srv/app/wp/wp-admin/index.php' ) );
	}
}
