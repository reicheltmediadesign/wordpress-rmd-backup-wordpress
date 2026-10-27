<?php
/**
 * Decides which backups (or logs) to delete: everything beyond the newest
 * $keep_count and everything older than $keep_days. The newest item is always
 * kept, so a misconfigured rule never removes the last backup.
 *
 * @package RMD\BackupWordpress
 */

namespace RMD\BackupWordpress\Domain;

final class Retention {

	/**
	 * @param list<array{name: string, created: int}> $items
	 * @param int                                     $keep_count 0 = no limit.
	 * @param int                                     $keep_days  0 = no limit.
	 * @return list<string> Names to delete.
	 */
	public static function expired( array $items, int $keep_count, int $keep_days, int $now ): array {
		usort(
			$items,
			static fn( array $a, array $b ): int => [ $b['created'], $b['name'] ] <=> [ $a['created'], $a['name'] ]
		);

		$limit  = $now - $keep_days * 86400;
		$delete = [];
		foreach ( $items as $index => $item ) {
			if ( 0 === $index ) {
				continue;
			}
			if ( ( $keep_count > 0 && $index >= $keep_count ) || ( $keep_days > 0 && $item['created'] < $limit ) ) {
				$delete[] = $item['name'];
			}
		}
		return $delete;
	}
}
