<?php
/**
 * Next run of a profile's schedule. Works in the time zone of the given time,
 * so callers pass "now" in the site's time zone.
 *
 * @package RMD\BackupWordpress
 */

namespace RMD\BackupWordpress\Domain;

use DateInterval;
use DateTimeImmutable;

final class Schedule {

	/** Window for start times picked at random: 02:00 up to (not including) 04:00. */
	public const SPREAD_FROM_HOUR = 2;
	public const SPREAD_MINUTES   = 120;

	/**
	 * Start time inside the window. Callers pass a random offset, so sites that
	 * share one backup server do not all start at the same minute.
	 *
	 * @param int $offset Minutes after the start of the window; wraps around.
	 * @return string HH:MM
	 */
	public static function spread_time( int $offset ): string {
		$minutes = self::SPREAD_FROM_HOUR * 60 + ( ( $offset % self::SPREAD_MINUTES ) + self::SPREAD_MINUTES ) % self::SPREAD_MINUTES;
		return sprintf( '%02d:%02d', intdiv( $minutes, 60 ), $minutes % 60 );
	}

	/**
	 * First run strictly after $after, or null for manual profiles.
	 *
	 * @param string $time    HH:MM; for hourly schedules only the minute counts.
	 * @param int    $weekday ISO day of the week, 1 = Monday … 7 = Sunday.
	 * @param int    $monthday Day of the month, 1–28.
	 */
	public static function next( string $type, string $time, int $weekday, int $monthday, DateTimeImmutable $after ): ?DateTimeImmutable {
		[ $hour, $minute ] = array_map( 'intval', explode( ':', $time . ':0' ) );

		switch ( $type ) {
			case 'hourly':
				$candidate = $after->setTime( (int) $after->format( 'G' ), $minute );
				return $candidate > $after ? $candidate : $candidate->add( new DateInterval( 'PT1H' ) );

			case 'daily':
				$candidate = $after->setTime( $hour, $minute );
				return $candidate > $after ? $candidate : $candidate->modify( '+1 day' )->setTime( $hour, $minute );

			case 'weekly':
				$days      = ( $weekday - (int) $after->format( 'N' ) + 7 ) % 7;
				$candidate = $after->modify( '+' . $days . ' days' )->setTime( $hour, $minute );
				return $candidate > $after ? $candidate : $candidate->modify( '+7 days' )->setTime( $hour, $minute );

			case 'monthly':
				$candidate = $after->setDate( (int) $after->format( 'Y' ), (int) $after->format( 'n' ), $monthday )->setTime( $hour, $minute );
				if ( $candidate > $after ) {
					return $candidate;
				}
				$next = $after->modify( 'first day of next month' );
				return $next->setDate( (int) $next->format( 'Y' ), (int) $next->format( 'n' ), $monthday )->setTime( $hour, $minute );
		}

		return null;
	}
}
