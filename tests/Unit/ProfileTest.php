<?php
declare(strict_types=1);

namespace RMD\BackupWordpress\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RMD\BackupWordpress\Domain\Profile;

final class ProfileTest extends TestCase {

	public function test_defaults_back_up_everything_manually(): void {
		$profile = Profile::parse( [ 'name' => 'Full backup' ] )['profile'];

		self::assertSame( 'full-backup', $profile->id );
		self::assertSame( Profile::PARTS, $profile->parts );
		self::assertSame( 'manual', $profile->schedule );
		self::assertFalse( $profile->is_scheduled() );
		self::assertTrue( $profile->has_files() );
		self::assertSame( 3, $profile->keep_count );
		self::assertFalse( $profile->remote );
		self::assertSame( 7, $profile->remote_keep_count );
		self::assertSame( 0, $profile->remote_keep_days );
	}

	public function test_remote_retention_is_separate(): void {
		$profile = Profile::parse(
			[
				'name'              => 'Nightly',
				'remote'            => '1',
				'keep_count'        => '2',
				'remote_keep_count' => '14',
				'remote_keep_days'  => '60',
			]
		)['profile'];

		self::assertTrue( $profile->remote );
		self::assertSame( 2, $profile->keep_count );
		self::assertSame( 14, $profile->remote_keep_count );
		self::assertSame( 60, $profile->remote_keep_days );
	}

	public function test_profiles_from_0_2_get_remote_defaults(): void {
		$stored = Profile::parse( [ 'name' => 'Old' ] )['profile']->to_array();
		unset( $stored['remote_keep_count'], $stored['remote_keep_days'] );
		$stored['keep_count'] = 7;

		$profile = Profile::parse( $stored )['profile'];
		self::assertSame( 7, $profile->keep_count );
		self::assertSame( 7, $profile->remote_keep_count );
	}

	public function test_invalid_values_fall_back_and_are_reported(): void {
		$result  = Profile::parse(
			[
				'name'         => '',
				'parts'        => [ 'nonsense' ],
				'schedule'     => 'yearly',
				'time'         => '25:00',
				'keep_count'   => '-3',
				'weekday'      => '9',
				'notify'       => 'loud',
				'notify_email' => 'not-an-address',
			]
		);
		$profile = $result['profile'];

		self::assertSame( [ 'name_required', 'parts_required', 'invalid_time', 'invalid_email' ], $result['errors'] );
		self::assertSame( [], $profile->parts );
		self::assertSame( 'manual', $profile->schedule );
		self::assertSame( '03:00', $profile->time );
		self::assertSame( 3, $profile->keep_count );
		self::assertSame( 7, $profile->weekday );
		self::assertSame( 'problems', $profile->notify );
		self::assertSame( '', $profile->notify_email );
		self::assertFalse( $profile->is_complete() );
	}

	public function test_form_values(): void {
		$profile = Profile::parse(
			[
				'id'                 => 'nightly',
				'name'               => 'Nightly',
				'parts'              => [ '', 'uploads', 'database' ],
				'exclude_patterns'   => "*.tmp\n\n/wp-content/cache/\n*.tmp",
				'db_exclude_tables'  => "wp_big_log\nbad name;",
				'db_skip_transients' => '0',
				'schedule'           => 'weekly',
				'time'               => '4:05',
				'weekday'            => '7',
				'keep_days'          => '30',
				'notify'             => 'always',
				'notify_email'       => 'ops@example.com',
			]
		)['profile'];

		self::assertSame( 'nightly', $profile->id );
		self::assertSame( [ 'database', 'uploads' ], $profile->parts );
		self::assertSame( [ '*.tmp', '/wp-content/cache/' ], $profile->exclude_patterns );
		self::assertSame( [ 'wp_big_log' ], $profile->db_exclude_tables );
		self::assertFalse( $profile->db_skip_transients );
		self::assertSame( '04:05', $profile->time );
		self::assertSame( 7, $profile->weekday );
		self::assertSame( 30, $profile->keep_days );
		self::assertSame( 'always', $profile->notify );
		self::assertTrue( $profile->is_scheduled() );
	}

	public function test_database_only_has_no_files(): void {
		$profile = Profile::parse(
			[
				'name'  => 'DB',
				'parts' => [ 'database' ],
			]
		)['profile'];
		self::assertFalse( $profile->has_files() );
		self::assertTrue( $profile->has( 'database' ) );
	}

	public function test_round_trip(): void {
		$profile = Profile::parse(
			[
				'name'     => 'Weekly uploads',
				'parts'    => [ 'uploads' ],
				'schedule' => 'weekly',
			]
		)['profile'];
		self::assertEquals( $profile, Profile::parse( $profile->to_array() )['profile'] );
	}

	public function test_presets(): void {
		$presets = Profile::presets(
			[
				'full'     => 'Full backup',
				'database' => 'Database only',
				'files'    => 'Files only',
				'content'  => 'Content only',
				'uploads'  => 'Uploads only',
			]
		);
		$parsed  = array_map( static fn( array $raw ): Profile => Profile::parse( $raw )['profile'], $presets );

		self::assertSame( [ 'full', 'database', 'files', 'content', 'uploads' ], array_map( static fn( Profile $p ): string => $p->id, $parsed ) );
		self::assertSame( [ 'database' ], $parsed[1]->parts );
		self::assertNotContains( 'database', $parsed[2]->parts );
		self::assertSame( [ 'plugins', 'themes', 'uploads', 'content' ], $parsed[3]->parts );
	}
}
