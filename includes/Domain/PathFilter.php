<?php
/**
 * Exclusion rules of a profile in a subset of the .gitignore syntax, matched
 * against paths as they are named in the archive:
 *
 * - `name` matches a file or folder with that name at any depth,
 * - `/name` or `dir/name` is anchored at the root,
 * - a trailing `/` only matches folders,
 * - `*` and `?` stay within one path segment, `**` spans segments,
 * - empty lines and lines starting with `#` are ignored; negation (`!`) is not supported.
 *
 * Matching is case-insensitive because Windows file systems are.
 *
 * @package RMD\BackupWordpress
 */

namespace RMD\BackupWordpress\Domain;

final class PathFilter {

	/**
	 * @param list<array{regex: string, dir_only: bool}> $rules
	 */
	private function __construct( private readonly array $rules ) {}

	/**
	 * @param string|list<string> $lines Rules, one per line.
	 */
	public static function from_lines( string|array $lines ): self {
		if ( is_string( $lines ) ) {
			$lines = preg_split( '/\R/', $lines ) ?: [];
		}

		$rules = [];
		foreach ( $lines as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line || str_starts_with( $line, '#' ) || str_starts_with( $line, '!' ) ) {
				continue;
			}
			$dir_only = str_ends_with( $line, '/' );
			$pattern  = rtrim( $line, '/' );
			$anchored = str_contains( $pattern, '/' );
			$pattern  = ltrim( $pattern, '/' );
			if ( '' === $pattern ) {
				continue;
			}
			$rules[] = [
				'regex'    => '#' . ( $anchored ? '^' : '(?:^|/)' ) . self::glob_to_regex( $pattern ) . '$#i',
				'dir_only' => $dir_only,
			];
		}

		return new self( $rules );
	}

	/**
	 * @param string $path Path relative to the root, with forward slashes.
	 */
	public function matches( string $path, bool $is_dir ): bool {
		foreach ( $this->rules as $rule ) {
			if ( $rule['dir_only'] && ! $is_dir ) {
				continue;
			}
			if ( preg_match( $rule['regex'], $path ) ) {
				return true;
			}
		}
		return false;
	}

	public function is_empty(): bool {
		return [] === $this->rules;
	}

	private static function glob_to_regex( string $glob ): string {
		$regex  = '';
		$length = strlen( $glob );
		for ( $i = 0; $i < $length; $i++ ) {
			$char = $glob[ $i ];
			if ( '*' === $char && '*' === ( $glob[ $i + 1 ] ?? '' ) ) {
				if ( '/' === ( $glob[ $i + 2 ] ?? '' ) ) {
					$regex .= '(?:.*/)?';
					$i     += 2;
				} else {
					$regex .= '.*';
					++$i;
				}
			} elseif ( '*' === $char ) {
				$regex .= '[^/]*';
			} elseif ( '?' === $char ) {
				$regex .= '[^/]';
			} else {
				$regex .= preg_quote( $char, '#' );
			}
		}
		return $regex;
	}
}
