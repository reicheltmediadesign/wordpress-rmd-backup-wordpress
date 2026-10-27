<?php
declare(strict_types=1);

namespace RMD\BackupWordpress\Tests\Unit;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use RMD\BackupWordpress\Domain\ZipWriter;
use ZipArchive;

// phpcs:disable WordPress.WP.AlternativeFunctions

#[RequiresPhpExtension( 'zip' )]
final class ZipWriterTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/rmd-bwp-zip-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->dir );
	}

	protected function tearDown(): void {
		foreach ( (array) glob( $this->dir . '/*' ) as $file ) {
			unlink( (string) $file );
		}
		rmdir( $this->dir );
	}

	private function source( string $name, string $content ): string {
		$path = $this->dir . '/' . $name;
		file_put_contents( $path, $content );
		touch( $path, 1700000000 );
		return $path;
	}

	/**
	 * @return array<string, string>
	 */
	private static function contents( string $archive ): array {
		$zip = new ZipArchive();
		self::assertTrue( $zip->open( $archive, ZipArchive::CHECKCONS ) );
		$entries = [];
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name             = (string) $zip->getNameIndex( $i );
			$entries[ $name ] = (string) $zip->getFromIndex( $i );
		}
		$zip->close();
		return $entries;
	}

	public function test_writes_a_valid_archive(): void {
		$text   = str_repeat( "Hello WordPress\n", 5000 );
		$binary = random_bytes( 3000 );
		$writer = new ZipWriter( $this->dir . '/a.zip', $this->dir . '/a.dir' );
		$writer->add_file( $this->source( 'text.txt', $text ), 'wp-content/text.txt', false, 1700000000 );
		$writer->add_file( $this->source( 'photo.jpg', $binary ), 'wp-content/uploads/Grüße.jpg', true, 1700000000 );
		$writer->add_file( $this->source( 'empty.txt', '' ), 'empty.txt', false, 1700000000 );
		$writer->add_folder( 'wp-content/languages', 1700000000 );
		$writer->add_string( 'backup-info.json', '{"ok":true}', 1700000000 );
		$writer->finish();

		self::assertFileDoesNotExist( $this->dir . '/a.dir' );
		self::assertSame(
			[
				'wp-content/text.txt'         => $text,
				'wp-content/uploads/Grüße.jpg' => $binary,
				'empty.txt'                   => '',
				'wp-content/languages/'       => '',
				'backup-info.json'            => '{"ok":true}',
			],
			self::contents( $this->dir . '/a.zip' )
		);
		self::assertLessThan( strlen( $text ) / 10, filesize( $this->dir . '/a.zip' ) - 3000 );
		self::assertStringContainsString( pack( 'vvCV', 0x5455, 5, 1, 1700000000 ), (string) file_get_contents( $this->dir . '/a.zip' ) );
	}

	public function test_continues_after_an_interrupted_step(): void {
		$writer = new ZipWriter( $this->dir . '/b.zip', $this->dir . '/b.dir' );
		$writer->add_file( $this->source( 'one.txt', 'one' ), 'one.txt', false, 1700000000 );
		$state = $writer->state();
		// Work of a step that died before its state was saved.
		$writer->add_file( $this->source( 'lost.txt', 'lost' ), 'lost.txt', false, 1700000000 );
		$writer->close();

		$writer = new ZipWriter( $this->dir . '/b.zip', $this->dir . '/b.dir', $state['bytes'], $state['directory_bytes'], $state['entries'] );
		$writer->add_file( $this->source( 'two.txt', 'two' ), 'two.txt', false, 1700000000 );
		$writer->finish();

		self::assertSame(
			[
				'one.txt' => 'one',
				'two.txt' => 'two',
			],
			self::contents( $this->dir . '/b.zip' )
		);
	}

	public function test_zip64_structures_are_readable(): void {
		$content = str_repeat( 'zip64 ', 1000 );
		$writer  = new ZipWriter( $this->dir . '/c.zip', $this->dir . '/c.dir', 0, 0, 0, 100 );
		$writer->add_file( $this->source( 'small.txt', 'small' ), 'small.txt', false, 1700000000 );
		$writer->add_file( $this->source( 'big.txt', $content ), 'big.txt', false, 1700000000 );
		$writer->add_file( $this->source( 'late.txt', 'late' ), 'late.txt', false, 1700000000 );
		$writer->finish();

		self::assertSame(
			[
				'small.txt' => 'small',
				'big.txt'   => $content,
				'late.txt'  => 'late',
			],
			self::contents( $this->dir . '/c.zip' )
		);
	}
}
