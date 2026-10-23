<?php

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * Today is not a day yet.
 *
 * A range that ends today ends on a day still being written. Drawn like the
 * others, that day is a cliff at the end of every sparkline, and averaged in
 * like the others it drags every "per day" figure down until midnight. The
 * convention the other tools follow: draw the incomplete period differently
 * and average over the complete days only. The helpers here are pure so the
 * tiles, the charts and the widget all agree.
 */
final class Partial_Day {

	/** Whether a range ending on $end includes today, which is still being written. */
	public static function is_partial( string $end, ?string $today = null ): bool {
		$today = $today ?? vireo_bucket_date();
		return $end >= $today;
	}

	/**
	 * Pageviews per complete day. With $partial, the last day is left out of
	 * both the sum and the count; a range that is only today has no complete
	 * day, so today's own figure is returned, and the caller says "so far".
	 */
	public static function average( int $total, int $days, bool $partial, int $last_day ): int {
		if ( $days <= 0 ) {
			return 0;
		}
		if ( ! $partial ) {
			return (int) round( $total / $days );
		}
		if ( $days <= 1 ) {
			return $last_day;
		}
		return (int) round( ( $total - $last_day ) / ( $days - 1 ) );
	}

	/**
	 * Yesterday up to the same time of day, from the hourly rollups: the whole
	 * of every hour already past plus the share of the current hour that has
	 * elapsed. Exact where the widget used to assume the day's traffic was
	 * spread evenly, which read as -100% at nineteen minutes past midnight.
	 *
	 * @param array<int,array{pageviews:int,visitors:int}> $by_hour Keyed 0-23; missing hours are empty.
	 * @param int                                           $hour    The current hour, 0-23.
	 * @param float                                         $elapsed Share of the current hour gone, 0-1.
	 * @return array{pageviews:int,visitors:int}
	 */
	public static function to_same_time( array $by_hour, int $hour, float $elapsed ): array {
		$pv = 0;
		$vi = 0;
		for ( $h = 0; $h < $hour; $h++ ) {
			$pv += (int) ( $by_hour[ $h ]['pageviews'] ?? 0 );
			$vi += (int) ( $by_hour[ $h ]['visitors'] ?? 0 );
		}
		$elapsed = max( 0.0, min( 1.0, $elapsed ) );
		$pv     += (int) round( (int) ( $by_hour[ $hour ]['pageviews'] ?? 0 ) * $elapsed );
		$vi     += (int) round( (int) ( $by_hour[ $hour ]['visitors'] ?? 0 ) * $elapsed );
		return [ 'pageviews' => $pv, 'visitors' => $vi ];
	}
}
