<?php
/**
 * Which files are stored without compression: formats that are compressed
 * already gain nothing from deflate, and large files are stored so that one
 * file never takes longer than a step.
 *
 * @package RMD\BackupWordpress
 */

namespace RMD\BackupWordpress\Domain;

final class Compression {

	public const LARGE_FILE = 64 * 1024 * 1024;

	private const COMPRESSED = [
		'7z',
		'avif',
		'br',
		'bz2',
		'docx',
		'flac',
		'gif',
		'gz',
		'heic',
		'jpeg',
		'jpg',
		'm4a',
		'm4v',
		'mkv',
		'mov',
		'mp3',
		'mp4',
		'odt',
		'ogg',
		'pdf',
		'png',
		'pptx',
		'rar',
		'tgz',
		'webm',
		'webp',
		'woff',
		'woff2',
		'xlsx',
		'xz',
		'zip',
	];

	public static function store_only( string $path, int $size ): bool {
		if ( $size >= self::LARGE_FILE ) {
			return true;
		}
		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		return in_array( $extension, self::COMPRESSED, true );
	}
}
