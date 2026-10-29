<?php
declare(strict_types=1);

namespace RMD\BackupWordpress\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RMD\BackupWordpress\Domain\SshKey;

final class SshKeyTest extends TestCase {

	public function test_generates_an_ed25519_pair(): void {
		$pair = SshKey::generate( 'rmd-backup example.com' );

		self::assertStringStartsWith( '-----BEGIN OPENSSH PRIVATE KEY-----', $pair['private'] );
		self::assertMatchesRegularExpression( '#^ssh-ed25519 AAAAC3NzaC1lZDI1NTE5[A-Za-z0-9+/]+=* rmd-backup example\.com$#', $pair['public'] );
		self::assertSame( $pair['public'], SshKey::public_from_private( $pair['private'], 'rmd-backup example.com' ) );
	}

	public function test_every_pair_is_new(): void {
		self::assertNotSame( SshKey::generate( 'a' )['public'], SshKey::generate( 'a' )['public'] );
	}

	public function test_fingerprints(): void {
		$pair         = SshKey::generate( 'x' );
		$fingerprints = SshKey::fingerprints( $pair['public'] );

		self::assertMatchesRegularExpression( '#^SHA256:[A-Za-z0-9+/]{43}$#', $fingerprints['sha256'] );
		self::assertMatchesRegularExpression( '#^([0-9a-f]{2}:){15}[0-9a-f]{2}$#', $fingerprints['md5'] );
		self::assertSame( $fingerprints, SshKey::fingerprints( SshKey::public_from_private( $pair['private'], 'other comment' ) ) );
	}

	public function test_comment_is_sanitized(): void {
		$pair = SshKey::generate( "evil\ncomment\twith\0control" );
		self::assertStringNotContainsString( "\n", $pair['public'] );
		self::assertStringEndsWith( ' evilcommentwithcontrol', $pair['public'] );
	}

	public function test_rejects_public_key_as_private_key(): void {
		$this->expectException( InvalidArgumentException::class );
		SshKey::load( SshKey::generate( 'x' )['public'] );
	}

	public function test_rejects_garbage(): void {
		$this->expectException( InvalidArgumentException::class );
		SshKey::fingerprints( 'not a key' );
	}
}
