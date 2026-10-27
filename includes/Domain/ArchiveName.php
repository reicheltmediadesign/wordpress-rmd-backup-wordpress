<?php
/**
 * Names of backup runs. A run id looks like
 * example.com_2026-10-27_030000_full_a1b2c3: site (optional), local start
 * time, profile id and a random part so archive URLs cannot be guessed on
 * servers that ignore .htaccess. The archive is <id>.zip, the log <id>.log.
 * Every name coming from a request is checked with parse().
 *
 * @package RMD\BackupWordpress
 */

namespace RMD\BackupWordpress\Domain;

use DateTimeImmutable;

// WordPress-free layer: wp_parse_url() is not available here.
// phpcs:disable WordPress.WP.AlternativeFunctions.parse_url_parse_url

final class ArchiveName {

	private const PATTERN = '/^(?:([a-z0-9.-]{1,80})_)?(\d{4}-\d{2}-\d{2})_(\d{6})_([a-z0-9-]{1,40})_([a-z0-9]{6})\.(zip|log)$/';

	public static function id( DateTimeImmutable $time, string $profile_id, string $random, string $site = '' ): string {
		$random = substr( strtolower( (string) preg_replace( '/[^A-Za-z0-9]/', '', $random ) ) . '000000', 0, 6 );
		$id     = $time->format( 'Y-m-d_His' ) . '_' . $profile_id . '_' . $random;
		return '' === $site ? $id : $site . '_' . $id;
	}

	/**
	 * File-name part for a site address: host and path, e.g. "example.com" or
	 * "localhost-testwordpress".
	 */
	public static function site( string $url ): string {
		$host = (string) parse_url( $url, PHP_URL_HOST );
		if ( '' === $host ) {
			return '';
		}
		$path = (string) parse_url( $url, PHP_URL_PATH );
		$slug = strtolower( $host . $path );
		$slug = (string) preg_replace( '/[^a-z0-9.-]+/', '-', $slug );
		$slug = trim( (string) preg_replace( '/^www\./', '', $slug ), '-.' );
		return substr( $slug, 0, 80 );
	}

	/**
	 * @return array{id: string, site: string, profile: string, date: string, time: string, type: string}|null
	 */
	public static function parse( string $file ): ?array {
		if ( ! preg_match( self::PATTERN, $file, $match ) ) {
			return null;
		}
		return [
			'id'      => substr( $file, 0, -4 ),
			'site'    => $match[1],
			'profile' => $match[4],
			'date'    => $match[2],
			'time'    => substr( $match[3], 0, 2 ) . ':' . substr( $match[3], 2, 2 ) . ':' . substr( $match[3], 4, 2 ),
			'type'    => $match[6],
		];
	}

	public static function is_id( string $id ): bool {
		return null !== self::parse( $id . '.zip' );
	}
}
