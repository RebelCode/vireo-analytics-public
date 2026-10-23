<?php

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * How much of a range has a country at all.
 *
 * An empty country code is a visit whose country was never resolved, not a
 * place: imported history carries totals only, and a lookup can fail. Ranked
 * as a country it came first in jeangalea.com's year in review, with 21,168
 * "visitors". It is never ranked; instead the cards say what share of the
 * range's visitors have a country, and from which day coverage is complete,
 * both read from the data.
 */
final class Country_Coverage {

	/**
	 * Pure: coverage from visitors per day and visitors-with-a-country per day.
	 *
	 * @param array<string,int> $site_by_date date => visitors (site_stats).
	 * @param array<string,int> $geo_by_date  date => visitors with a country.
	 * A day counts as a gap when fewer than nine in ten of its visitors have
	 * a country: a month where half the visits were resolved is not covered.
	 *
	 * @return array{known:int,total:int,share:float|null,from:string}
	 */
	public static function from_days( array $site_by_date, array $geo_by_date ): array {
		$total    = 0;
		$known    = 0;
		$last_gap = '';
		ksort( $site_by_date );
		foreach ( $site_by_date as $date => $visitors ) {
			$visitors = (int) $visitors;
			$geo      = min( $visitors, (int) ( $geo_by_date[ $date ] ?? 0 ) );
			$total   += $visitors;
			$known   += $geo;
			if ( $visitors > 0 && $geo < $visitors * 0.9 ) {
				$last_gap = (string) $date;
			}
		}

		$share = $total > 0 ? round( min( 100.0, $known / $total * 100 ), 1 ) : null;
		$from  = '';
		if ( '' !== $last_gap && $known > 0 ) {
			$from = gmdate( 'Y-m-d', strtotime( $last_gap . ' +1 day UTC' ) );
		}

		return [
			'known' => $known,
			'total' => $total,
			'share' => $share,
			'from'  => $from,
		];
	}

	/**
	 * Rows with a real country code, in the order given.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows carrying 'code'.
	 * @return array<int,array<string,mixed>>
	 */
	public static function rankable( array $rows ): array {
		return array_values(
			array_filter(
				$rows,
				static function ( array $row ): bool {
					$code = strtoupper( trim( (string) ( $row['code'] ?? '' ) ) );
					return '' !== $code && 'XX' !== $code;
				}
			)
		);
	}

	/**
	 * Coverage over a range, from the rollups. Cached for the dashboard's
	 * half minute.
	 *
	 * @return array{known:int,total:int,share:float|null,from:string}
	 */
	public static function for_range( string $start, string $end ): array {
		$key   = 'vireo_geo_cov_' . md5( Sites::current() . '|' . $start . '|' . $end );
		$found = get_transient( $key );
		if ( is_array( $found ) ) {
			return $found;
		}

		global $wpdb;
		$site_by_date = [];
		$geo_by_date  = [];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- cached above
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT date, visitors FROM %i WHERE date BETWEEN %s AND %s', Sites::table( 'site_stats' ), $start, $end ), ARRAY_A ) as $row ) {
			$site_by_date[ (string) $row['date'] ] = (int) $row['visitors'];
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- cached above
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT date, SUM(visitors) AS visitors FROM %i WHERE date BETWEEN %s AND %s AND country_code <> %s AND country_code <> %s GROUP BY date', Sites::table( 'geo_stats' ), $start, $end, '', 'XX' ), ARRAY_A ) as $row ) {
			$geo_by_date[ (string) $row['date'] ] = (int) $row['visitors'];
		}

		$out = self::from_days( $site_by_date, $geo_by_date );
		set_transient( $key, $out, 30 );
		return $out;
	}
}
