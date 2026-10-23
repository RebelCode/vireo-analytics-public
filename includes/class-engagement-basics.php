<?php

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * Average time on page and bounce rate, read back from their daily rollups.
 *
 * Time on page is active time, the seconds a tab was visible, averaged over
 * the views that reported one. A view that never reported (closed before the
 * script ran, a browser that never fired pagehide) is left out of the average
 * rather than counted as zero seconds.
 *
 * The bounce rate is the share of a day's visitors who viewed exactly one
 * page. A visitor is the same daily hash the visitor total counts, so a
 * multi-day range sums days the way that total does.
 *
 * Both are site-wide or per path, and neither can be split by country, device
 * or source: the rollups don't carry those. Scroll depth and the per-page
 * breakdown live in Vireo Pro, which can read these numbers rather than
 * measure them again.
 */
final class Engagement_Basics {

	/**
	 * Site-wide average active time for a range.
	 *
	 * @return array{samples:int, seconds:int, avg_seconds:int}|null Null when no view reported a time.
	 */
	public static function time_summary( string $start, string $end ): ?array {
		global $wpdb;

		$table = Sites::table( 'page_time' );
		if ( ! Aggregator::table_ready( $table ) ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- callers cache the result
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT SUM(samples) AS samples, SUM(seconds) AS seconds FROM %i WHERE date BETWEEN %s AND %s',
				$table,
				$start,
				$end
			),
			ARRAY_A
		);

		return self::time_row( (int) ( $row['samples'] ?? 0 ), (int) ( $row['seconds'] ?? 0 ) );
	}

	/**
	 * Average active time per path for a range, for the paths given.
	 *
	 * @param array<int,string> $paths
	 * @return array<string, array{samples:int, seconds:int, avg_seconds:int}> path => time
	 */
	public static function time_by_path( array $paths, string $start, string $end ): array {
		global $wpdb;

		$paths = array_values( array_unique( array_filter( array_map( 'strval', $paths ), 'strlen' ) ) );
		$table = Sites::table( 'page_time' );
		if ( [] === $paths || ! Aggregator::table_ready( $table ) ) {
			return [];
		}

		$in = implode( ',', array_fill( 0, count( $paths ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- callers cache the result
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in is a list of %s placeholders, one per path
				"SELECT p.path, SUM(t.samples) AS samples, SUM(t.seconds) AS seconds
				 FROM %i t
				 JOIN %i p ON p.id = t.path_id
				 WHERE t.date BETWEEN %s AND %s AND p.path IN ({$in})
				 GROUP BY p.path",
				array_merge( [ $table, Sites::table( 'paths' ), $start, $end ], $paths )
			),
			ARRAY_A
		);

		$out = [];
		foreach ( (array) $rows as $r ) {
			$time = self::time_row( (int) $r['samples'], (int) $r['seconds'] );
			if ( null !== $time ) {
				$out[ (string) $r['path'] ] = $time;
			}
		}
		return $out;
	}

	/**
	 * Bounce rate for a range.
	 *
	 * @return array{visitors:int, bounced:int, rate:float}|null Null before any day was counted.
	 */
	public static function bounce( string $start, string $end ): ?array {
		global $wpdb;

		$table = Sites::table( 'bounce_stats' );
		if ( ! Aggregator::table_ready( $table ) ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- callers cache the result
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT SUM(visitors) AS visitors, SUM(multi_page) AS multi_page FROM %i WHERE date BETWEEN %s AND %s',
				$table,
				$start,
				$end
			),
			ARRAY_A
		);

		return self::bounce_row( (int) ( $row['visitors'] ?? 0 ), (int) ( $row['multi_page'] ?? 0 ) );
	}

	/**
	 * One point per day that has data, for the hero sparklines. Days with
	 * nothing measured are left out rather than drawn as zero, so the line
	 * doesn't dive on the days before the update.
	 *
	 * @return array{time: array<int, array{date:string, value:int}>, bounce: array<int, array{date:string, value:float}>}
	 */
	public static function daily( string $start, string $end ): array {
		global $wpdb;

		$out = [
			'time'   => [],
			'bounce' => [],
		];

		$time = Sites::table( 'page_time' );
		if ( Aggregator::table_ready( $time ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- callers cache the result
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT date, SUM(samples) AS samples, SUM(seconds) AS seconds FROM %i WHERE date BETWEEN %s AND %s GROUP BY date ORDER BY date',
					$time,
					$start,
					$end
				),
				ARRAY_A
			);
			foreach ( (array) $rows as $r ) {
				$row = self::time_row( (int) $r['samples'], (int) $r['seconds'] );
				if ( null !== $row ) {
					$out['time'][] = [
						'date'  => (string) $r['date'],
						'value' => $row['avg_seconds'],
					];
				}
			}
		}

		$bounce = Sites::table( 'bounce_stats' );
		if ( Aggregator::table_ready( $bounce ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- callers cache the result
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT date, visitors, multi_page FROM %i WHERE date BETWEEN %s AND %s ORDER BY date',
					$bounce,
					$start,
					$end
				),
				ARRAY_A
			);
			foreach ( (array) $rows as $r ) {
				$row = self::bounce_row( (int) $r['visitors'], (int) $r['multi_page'] );
				if ( null !== $row ) {
					$out['bounce'][] = [
						'date'  => (string) $r['date'],
						'value' => $row['rate'],
					];
				}
			}
		}

		return $out;
	}

	/** @return array{samples:int, seconds:int, avg_seconds:int}|null */
	public static function time_row( int $samples, int $seconds ): ?array {
		if ( $samples < 1 ) {
			return null;
		}
		return [
			'samples'     => $samples,
			'seconds'     => $seconds,
			'avg_seconds' => (int) round( $seconds / $samples ),
		];
	}

	/**
	 * The rate as a percentage to one decimal place.
	 *
	 * multi_page can exceed visitors only if a day was counted part-way, which
	 * bounce_counted() exists to prevent; it is clamped anyway rather than
	 * showing a negative rate.
	 *
	 * @return array{visitors:int, bounced:int, rate:float}|null
	 */
	public static function bounce_row( int $visitors, int $multi_page ): ?array {
		if ( $visitors < 1 ) {
			return null;
		}
		$bounced = max( 0, $visitors - min( $visitors, $multi_page ) );
		return [
			'visitors' => $visitors,
			'bounced'  => $bounced,
			'rate'     => round( $bounced / $visitors * 100, 1 ),
		];
	}

	/**
	 * The first day any view reported a time, or null before one has.
	 *
	 * What the Time on page card points at when a range has nothing to
	 * average: the number has only existed since the update that added it.
	 */
	public static function first_timed_day(): ?string {
		global $wpdb;

		$table = Sites::table( 'page_time' );
		if ( ! Aggregator::table_ready( $table ) ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- callers cache the result
		$day = $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(date) FROM %i WHERE samples > 0', $table ) );

		return is_string( $day ) && '' !== $day ? $day : null;
	}

	/**
	 * What the Time on page card says when it has no average to show: why,
	 * and when it will. A dash with "no views timed yet" under it read as a
	 * broken feature on a site that had only just updated.
	 *
	 * @param string|null $first_day The first day with a timed view, null before any (first_timed_day()).
	 * @param string      $end       Last day of the range on screen, 'Y-m-d', or '' when unknown.
	 * @return array{sub:string, hint:string} The card's subtitle and its title-attribute explanation.
	 */
	public static function time_empty_state( ?string $first_day, string $end ): array {
		if ( null === $first_day ) {
			return [
				'sub'  => __( 'no views timed yet', 'vireo-analytics' ),
				'hint' => __( 'Time on page is recorded from this update on. A view reports its time when the reader leaves the page, and the next run of the collector records it, so the first figure appears within a few minutes of the first visit.', 'vireo-analytics' ),
			];
		}

		$day = vireo_format_day( $first_day, 'M j' );

		if ( '' !== $end && $end < $first_day ) {
			return [
				/* translators: %s: a date, such as "Oct 15". */
				'sub'  => sprintf( __( 'timed from %s', 'vireo-analytics' ), $day ),
				/* translators: %s: a date, such as "Oct 15". */
				'hint' => sprintf( __( 'Time on page has been recorded since %s. This range ends before that, so there is nothing to average.', 'vireo-analytics' ), $day ),
			];
		}

		return [
			'sub'  => __( 'no views timed in this range', 'vireo-analytics' ),
			/* translators: %s: a date, such as "Oct 15". */
			'hint' => sprintf( __( 'Time on page has been recorded since %s, but no view in this range has reported a time yet. Views report when the reader leaves the page.', 'vireo-analytics' ), $day ),
		];
	}

	/**
	 * What the Bounce rate card says when it has no rate to show.
	 *
	 * Counting starts the day after the update (Aggregator::bounce_counted()),
	 * so for a day the card is empty by design, and after that it stays empty
	 * until the first visit of the counted day has been flushed. Both read as
	 * "no visitors counted yet" before; now the card names the day.
	 *
	 * @param string $since The first counted day, 'Y-m-d', or '' before the installer has set one.
	 * @param string $today Today as a bucket date (vireo_bucket_date()).
	 * @param string $end   Last day of the range on screen, 'Y-m-d', or '' when unknown.
	 * @return array{sub:string, hint:string}
	 */
	public static function bounce_empty_state( string $since, string $today, string $end ): array {
		if ( '' === $since ) {
			return [
				'sub'  => __( 'not counted yet', 'vireo-analytics' ),
				'hint' => __( 'Bounce rate starts counting once the update has finished setting up, on the next scheduled run or the next visit to wp-admin.', 'vireo-analytics' ),
			];
		}

		$day = vireo_format_day( $since, 'M j' );

		if ( $since > $today ) {
			return [
				/* translators: %s: a date, such as "Oct 15". */
				'sub'  => sprintf( __( 'starts counting %s', 'vireo-analytics' ), $day ),
				/* translators: %s: a date, such as "Oct 15". */
				'hint' => sprintf( __( 'Visitors who arrived before this update were already counted, so bounce rate starts with the first full day, %s.', 'vireo-analytics' ), $day ),
			];
		}

		if ( '' !== $end && $end < $since ) {
			return [
				/* translators: %s: a date, such as "Oct 15". */
				'sub'  => sprintf( __( 'counted from %s', 'vireo-analytics' ), $day ),
				/* translators: %s: a date, such as "Oct 15". */
				'hint' => sprintf( __( 'Bounce rate has been counted since %s. This range ends before that.', 'vireo-analytics' ), $day ),
			];
		}

		return [
			/* translators: %s: a date, such as "Oct 15". */
			'sub'  => sprintf( __( 'counted from %s, no visitors yet', 'vireo-analytics' ), $day ),
			/* translators: %s: a date, such as "Oct 15". */
			'hint' => sprintf( __( 'Bounce rate has been counted since %s. It fills in with the first run of the collector after a visit, within a few minutes.', 'vireo-analytics' ), $day ),
		];
	}

	/** Seconds as "45s" or "3m 07s", the way the dashboard shows a duration. */
	public static function format_seconds( int $seconds ): string {
		$seconds = max( 0, $seconds );
		if ( $seconds < 60 ) {
			/* translators: %d: a number of seconds. */
			return sprintf( __( '%ds', 'vireo-analytics' ), $seconds );
		}
		/* translators: 1: minutes, 2: seconds, always two digits. */
		return sprintf( __( '%1$dm %2$02ds', 'vireo-analytics' ), intdiv( $seconds, 60 ), $seconds % 60 );
	}
}
