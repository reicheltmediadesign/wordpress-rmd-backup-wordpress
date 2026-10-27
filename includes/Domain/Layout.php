<?php
/**
 * Where the parts of a site live and how they are named in the archive.
 *
 * The scan walks the tree from the WordPress folder. Every folder belongs to a
 * part: the WordPress folder to "core", wp-content and mu-plugins to "content",
 * and the plugin, theme and upload folders to their own parts. Files are backed
 * up when their part is selected; folders are entered when their part is
 * selected or a selected part lies below them. Part folders outside the
 * WordPress folder (moved wp-content, wp-config.php one level up) are scanned
 * separately and stored under their usual names in the archive.
 *
 * All paths are absolute with forward slashes and without trailing slash.
 * Comparisons ignore case because Windows file systems do.
 *
 * @package RMD\BackupWordpress
 */

namespace RMD\BackupWordpress\Domain;

final class Layout {

	/** @var list<array{path: string, part: string, alias: string}> Part folders, most specific first. */
	private array $folders;

	public function __construct(
		public readonly string $root,
		string $content,
		string $plugins,
		string $mu_plugins,
		string $themes,
		string $uploads,
		public readonly ?string $config
	) {
		$folders = [
			[
				'path'  => $content,
				'part'  => 'content',
				'alias' => 'wp-content',
			],
			[
				'path'  => $mu_plugins,
				'part'  => 'content',
				'alias' => 'wp-content/mu-plugins',
			],
			[
				'path'  => $plugins,
				'part'  => 'plugins',
				'alias' => 'wp-content/plugins',
			],
			[
				'path'  => $themes,
				'part'  => 'themes',
				'alias' => 'wp-content/themes',
			],
			[
				'path'  => $uploads,
				'part'  => 'uploads',
				'alias' => 'wp-content/uploads',
			],
		];
		usort( $folders, static fn( array $a, array $b ): int => strlen( $b['path'] ) <=> strlen( $a['path'] ) );
		$this->folders = $folders;
	}

	/**
	 * Where the scan starts: the WordPress folder, every outermost part folder
	 * outside it and the config file when it lives outside.
	 *
	 * @param list<string> $selected
	 * @return list<array{path: string, part: string, dir: bool}>
	 */
	public function starts( array $selected ): array {
		$starts = [];
		if ( $this->needed( $this->root, 'core', $selected ) ) {
			$starts[] = [
				'path' => $this->root,
				'part' => 'core',
				'dir'  => true,
			];
		}

		$outside = array_filter( $this->folders, fn( array $f ): bool => ! self::is_below( $f['path'], $this->root ) );
		foreach ( $outside as $folder ) {
			foreach ( $outside as $other ) {
				if ( self::is_below( $folder['path'], $other['path'] ) ) {
					continue 2;
				}
			}
			if ( $this->needed( $folder['path'], $folder['part'], $selected ) ) {
				$starts[] = [
					'path' => $folder['path'],
					'part' => $folder['part'],
					'dir'  => true,
				];
			}
		}

		if ( null !== $this->config && in_array( 'config', $selected, true ) && ! self::is_below( $this->config, $this->root ) ) {
			$starts[] = [
				'path' => $this->config,
				'part' => 'config',
				'dir'  => false,
			];
		}

		return $starts;
	}

	/**
	 * Part of a folder, given the part of its parent.
	 */
	public function folder_part( string $path, string $parent_part ): string {
		foreach ( $this->folders as $folder ) {
			if ( self::same( $path, $folder['path'] ) ) {
				return $folder['part'];
			}
		}
		return $parent_part;
	}

	/**
	 * Part of a file, given the part of its folder.
	 */
	public function file_part( string $path, string $folder_part ): string {
		return null !== $this->config && self::same( $path, $this->config ) ? 'config' : $folder_part;
	}

	/**
	 * Whether a folder has to be entered: its own part is selected or a
	 * selected part (or the config file) lies below it.
	 *
	 * @param list<string> $selected
	 */
	public function needed( string $path, string $part, array $selected ): bool {
		if ( in_array( $part, $selected, true ) ) {
			return true;
		}
		foreach ( $this->folders as $folder ) {
			if ( in_array( $folder['part'], $selected, true ) && self::is_below( $folder['path'], $path ) ) {
				return true;
			}
		}
		return null !== $this->config && in_array( 'config', $selected, true ) && self::is_below( $this->config, $path );
	}

	/**
	 * Name in the archive: relative to the WordPress folder, or below the usual
	 * name of the part folder that contains it.
	 */
	public function archive_path( string $path ): string {
		if ( self::is_below( $path, $this->root ) ) {
			return substr( $path, strlen( $this->root ) + 1 );
		}
		if ( null !== $this->config && self::same( $path, $this->config ) ) {
			return 'wp-config.php';
		}
		foreach ( $this->folders as $folder ) {
			if ( self::same( $path, $folder['path'] ) ) {
				return $folder['alias'];
			}
			if ( self::is_below( $path, $folder['path'] ) ) {
				return $folder['alias'] . substr( $path, strlen( $folder['path'] ) );
			}
		}
		return ltrim( $path, '/' );
	}

	public static function is_below( string $path, string $folder ): bool {
		return str_starts_with( strtolower( $path ), strtolower( $folder ) . '/' );
	}

	public static function same( string $a, string $b ): bool {
		return strtolower( $a ) === strtolower( $b );
	}
}
