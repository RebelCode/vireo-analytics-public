<?php
/**
 * Reads what the bot filter rejected, for the dashboard.
 */

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

final class Bot_Stats {

	/**
	 * Totals per category for a date range, highest first.
	 *
	 * @return array{total:int, by_category:array<int,array{category:string,label:string,hits:int}>}
	 */
	public static function summary( string $start, string $end ): array {
		global $wpdb;

		$table = Sites::table( 'bot_stats' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- reporting read
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT category, SUM(hits) AS hits FROM %i WHERE date BETWEEN %s AND %s GROUP BY category ORDER BY hits DESC',
				$table,
				$start,
				$end
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return [
				'total'       => 0,
				'by_category' => [],
			];
		}

		$labels = Bot_Filter::categories();
		$total  = 0;
		$out    = [];

		foreach ( $rows as $row ) {
			$key  = (string) ( $row['category'] ?? 'other' );
			$hits = (int) ( $row['hits'] ?? 0 );
			$total += $hits;
			$out[]  = [
				'category' => $key,
				'label'    => $labels[ $key ] ?? $key,
				'hits'     => $hits,
			];
		}

		return [
			'total'       => $total,
			'by_category' => $out,
		];
	}

	/**
	 * The share of all requests that were filtered, as a percentage of
	 * filtered plus counted. Returns null when there's nothing to divide by,
	 * so the caller can omit the figure rather than print a misleading zero.
	 */
	public static function filtered_share( int $filtered, int $counted ): ?float {
		$total = $filtered + $counted;
		if ( $total <= 0 ) {
			return null;
		}
		return round( ( $filtered / $total ) * 100, 1 );
	}

}
