<?php
/**
 * Koko Analytics importer.
 *
 * Reads from the source plugin's tables on the same database and upserts
 * into our wp_la_* tables. Safe to run multiple times (additive), never
 * modifies Koko's data.
 *
 * Source tables:
 *   wp_koko_analytics_site_stats      (date, visitors, pageviews)
 *   wp_koko_analytics_post_stats      (date, post_id, path_id, visitors, pageviews)
 *   wp_koko_analytics_paths           (id, path)
 *   wp_koko_analytics_referrer_stats  (date, id, unique_hits, hits)     <- note: Koko's column names
 *   wp_koko_analytics_referrer_labels (id, value)                        <- 'value' = the URL/host
 *
 * Countries, devices and events are not imported. Koko keeps them in its paid
 * Pro add-on, in koko_analytics_{country,browser,os,device}_stats and its
 * event tables (names from Koko's own bin/db-reset). Pro's source isn't
 * public, so their columns are unverified, and this importer used to read
 * wp_kap_* tables that no Koko build creates. Map them once a real Pro schema
 * has been checked.
 *
 * Chunking: we walk the distinct dates that have site_stats rows in 30-day
 * windows, newest first, so the user sees recent numbers populate immediately.
 */

namespace VireoAnalytics\Importers;

use DateTimeImmutable;

defined( 'ABSPATH' ) || exit;

final class Koko_Importer extends Importer_Base {

	private const CHUNK_DAYS = 30;

	public function slug(): string {
		return 'koko';
	}

	public function name(): string {
		return __( 'Koko Analytics', 'vireo-analytics' );
	}

	public function is_available(): bool {
		global $wpdb;
		return self::table_has_rows( $wpdb->prefix . 'koko_analytics_site_stats' );
	}

	/**
	 * Summary for the confirmation screen.
	 *
	 * @return array{days:int, pageviews:int, visitors:int, min_date:?string, max_date:?string}
	 */
	public function get_summary(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'koko_analytics_site_stats';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- aggregator output
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COUNT(*) AS days,
				        COALESCE(SUM(pageviews),0) AS pageviews,
				        COALESCE(SUM(visitors),0)  AS visitors,
				        MIN(date) AS min_date,
				        MAX(date) AS max_date
				 FROM %i',
				$table
			),
			ARRAY_A
		);
		return [
			'days'      => (int) ( $row['days'] ?? 0 ),
			'pageviews' => (int) ( $row['pageviews'] ?? 0 ),
			'visitors'  => (int) ( $row['visitors'] ?? 0 ),
			'min_date'  => $row['min_date'] ?? null,
			'max_date'  => $row['max_date'] ?? null,
		];
	}

	/**
	 * Units of work = distinct dates in the source site_stats.
	 */
	public function count_records(): int {
		global $wpdb;
		$table = $wpdb->prefix . 'koko_analytics_site_stats';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- aggregator output
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
	}

	/**
	 * Import one CHUNK_DAYS window. Caller treats this as a day-offset counter.
	 * We translate it to actual dates against the min/max range in source data.
	 */
	public function import_chunk( array $params, int $offset, int $limit ): array {
		$total = $this->count_records();
		if ( 0 === $total ) {
			return [
				'done'        => true,
				'processed'   => 0,
				'total'       => 0,
				'next_offset' => 0,
				'label'       => __( 'No Koko Analytics data found.', 'vireo-analytics' ),
			];
		}

		$bounds = $this->date_bounds();
		if ( null === $bounds ) {
			return [
				'done'        => true,
				'processed'   => 0,
				'total'       => $total,
				'next_offset' => $total,
				'label'       => __( 'No Koko Analytics data found.', 'vireo-analytics' ),
			];
		}

		// Work backwards from the newest date. Offset counts days already
		// processed (not DB rows — calendar days from the newest side).
		$window_end   = $bounds['max_date']->modify( '-' . $offset . ' days' );
		$window_start = $window_end->modify( '-' . ( self::CHUNK_DAYS - 1 ) . ' days' );

		// Clamp lower bound to min_date.
		if ( $window_start < $bounds['min_date'] ) {
			$window_start = $bounds['min_date'];
		}

		$start_str = $window_start->format( 'Y-m-d' );
		$end_str   = $window_end->format( 'Y-m-d' );

		$processed = 0;
		$processed += $this->import_site_stats_range( $start_str, $end_str );
		$processed += $this->import_post_stats_range( $start_str, $end_str );
		$processed += $this->import_referrer_stats_range( $start_str, $end_str );

		$next_offset   = $offset + self::CHUNK_DAYS;
		$total_span    = (int) $bounds['min_date']->diff( $bounds['max_date'] )->days + 1;
		$done          = $window_start <= $bounds['min_date'];
		$displayed_off = min( $next_offset, $total_span );

		return [
			'done'        => $done,
			'processed'   => $processed,
			'total'       => $total_span,
			'next_offset' => $done ? $total_span : $next_offset,
			'label'       => sprintf(
				/* translators: 1: chunk start date, 2: chunk end date */
				__( 'Importing %1$s — %2$s', 'vireo-analytics' ),
				$start_str,
				$end_str
			),
		];
	}

	// -------------------------------------------------------------------------
	// Per-table importers
	// -------------------------------------------------------------------------

	private function import_site_stats_range( string $start, string $end ): int {
		global $wpdb;
		$src = $wpdb->prefix . 'koko_analytics_site_stats';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- aggregator output
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT date, visitors, pageviews FROM %i WHERE date BETWEEN %s AND %s',
				$src,
				$start,
				$end
			),
			ARRAY_A
		);

		$count = 0;
		foreach ( (array) $rows as $r ) {
			$this->upsert_site_stats( (string) $r['date'], (int) $r['visitors'], (int) $r['pageviews'] );
			++$count;
		}
		return $count;
	}

	private function import_post_stats_range( string $start, string $end ): int {
		global $wpdb;
		$src_stats = $wpdb->prefix . 'koko_analytics_post_stats';
		$src_paths = $wpdb->prefix . 'koko_analytics_paths';

		// Join in Koko's paths table so we carry the actual path string across —
		// Koko's path_id is not equivalent to ours.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off import, not hot-path
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT s.date, s.post_id, p.path, s.visitors, s.pageviews
				 FROM %i s
				 JOIN %i p ON p.id = s.path_id
				 WHERE s.date BETWEEN %s AND %s',
				$src_stats,
				$src_paths,
				$start,
				$end
			),
			ARRAY_A
		);

		$normalized = [];
		foreach ( (array) $rows as $r ) {
			$path = trim( (string) $r['path'] );
			if ( '' === $path ) {
				continue;
			}
			$normalized[] = [
				'date'      => (string) $r['date'],
				'post_id'   => (int) $r['post_id'],
				'path'      => $path,
				'visitors'  => (int) $r['visitors'],
				'pageviews' => (int) $r['pageviews'],
			];
		}

		$this->upsert_post_stats_bulk( $normalized );
		return count( $normalized );
	}

	private function import_referrer_stats_range( string $start, string $end ): int {
		global $wpdb;
		$src_stats  = $wpdb->prefix . 'koko_analytics_referrer_stats';
		$src_labels = $wpdb->prefix . 'koko_analytics_referrer_labels';

		// Note Koko's columns: `hits` = pageviews, `unique_hits` = visitors.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off import, not hot-path
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT s.date, l.value AS host, s.unique_hits AS visitors, s.hits AS pageviews
				 FROM %i s
				 JOIN %i l ON l.id = s.id
				 WHERE s.date BETWEEN %s AND %s',
				$src_stats,
				$src_labels,
				$start,
				$end
			),
			ARRAY_A
		);

		$normalized = [];
		foreach ( (array) $rows as $r ) {
			$host = self::normalize_host( (string) $r['host'] );
			if ( '' === $host ) {
				continue;
			}
			$normalized[] = [
				'date'      => (string) $r['date'],
				'host'      => $host,
				'visitors'  => (int) $r['visitors'],
				'pageviews' => (int) $r['pageviews'],
			];
		}

		$this->upsert_referrer_stats_bulk( $normalized );
		return count( $normalized );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * @return array{min_date:DateTimeImmutable, max_date:DateTimeImmutable}|null
	 */
	private function date_bounds(): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'koko_analytics_site_stats';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- aggregator output
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT MIN(date) AS min_date, MAX(date) AS max_date FROM %i', $table ) );
		if ( ! $row || empty( $row->min_date ) || empty( $row->max_date ) ) {
			return null;
		}
		try {
			return [
				'min_date' => new DateTimeImmutable( $row->min_date ),
				'max_date' => new DateTimeImmutable( $row->max_date ),
			];
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/**
	 * Koko stores referrer labels as full URLs. We store hosts. Reduce to host
	 * (strip scheme, www., path) — matches our aggregator's normalize_referrer.
	 */
	private static function normalize_host( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}

		// If it already looks like a bare host (no scheme, no slash), use as-is.
		if ( false === strpos( $value, '/' ) && false === strpos( $value, ':' ) ) {
			$host = strtolower( $value );
		} else {
			$parts = wp_parse_url( $value );
			if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
				// Best effort: if the value has a slash but no scheme, prepend http://.
				if ( false === strpos( $value, '://' ) ) {
					$parts = wp_parse_url( 'http://' . $value );
				}
				if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
					return '';
				}
			}
			$host = strtolower( (string) $parts['host'] );
		}

		if ( str_starts_with( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}
		return $host;
	}
}
