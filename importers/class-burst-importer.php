<?php

namespace VireoAnalytics\Importers;

defined( 'ABSPATH' ) || exit;

/**
 * Import history from Burst Statistics.
 *
 * Burst stores one row per hit with a unix timestamp and a visitor id, so both
 * pageviews and daily visitors can be derived directly. Burst's own readme
 * states its data cannot be imported from other tools; going the other way is
 * only a matter of reading its tables.
 *
 * Dates are bucketed in the site's timezone to match how Burst reports them,
 * rather than in UTC, which would file evening traffic under the wrong day for
 * anywhere east of Greenwich.
 */
final class Burst_Importer extends Importer_Base {

	public function slug(): string {
		return 'burst';
	}

	public function name(): string {
		return __( 'Burst Statistics', 'vireo-analytics' );
	}

	public function is_available(): bool {
		global $wpdb;
		return self::table_has_rows( $wpdb->prefix . 'burst_statistics' );
	}

	/**
	 * Offset in seconds between UTC and the site's timezone, for bucketing.
	 */
	private function tz_offset(): int {
		$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
		return (int) $tz->getOffset( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) );
	}

	public function count_records(): int {
		global $wpdb;
		if ( ! $this->is_available() ) {
			return 0;
		}
		$table  = $wpdb->prefix . 'burst_statistics';
		$offset = $this->tz_offset();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- import source
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(DISTINCT DATE(FROM_UNIXTIME(time + %d))) FROM %i',
				$offset,
				$table
			)
		);
	}

	/**
	 * @param array<string,mixed> $params
	 * @return array<string,mixed>
	 */
	public function import_chunk( array $params, int $offset, int $limit ): array {
		global $wpdb;

		$total = $this->count_records();
		if ( 0 === $total ) {
			return [
				'done'        => true,
				'processed'   => 0,
				'total'       => 0,
				'next_offset' => 0,
				'label'       => __( 'No Burst Statistics data found.', 'vireo-analytics' ),
			];
		}

		$table    = $wpdb->prefix . 'burst_statistics';
		$limit    = max( 1, min( 30, $limit ) );
		$tz       = $this->tz_offset();
		$sessions = self::column_exists( $wpdb->prefix . 'burst_sessions', 'browser_id' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- import source
		$dates = (array) $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT DATE(FROM_UNIXTIME(time + %d)) AS d FROM %i ORDER BY d ASC LIMIT %d OFFSET %d',
				$tz,
				$table,
				$limit,
				$offset
			)
		);

		if ( ! $dates ) {
			return [
				'done'        => true,
				'processed'   => $offset,
				'total'       => $total,
				'next_offset' => $offset,
				'label'       => __( 'Burst Statistics import complete.', 'vireo-analytics' ),
			];
		}

		foreach ( $dates as $date ) {
			$date = (string) $date;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- import source
			$totals = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT COUNT(*) AS pageviews, COUNT(DISTINCT uid_id) AS visitors
					 FROM %i WHERE DATE(FROM_UNIXTIME(time + %d)) = %s',
					$table,
					$tz,
					$date
				),
				ARRAY_A
			);

			$this->upsert_site_stats(
				$date,
				(int) ( $totals['visitors'] ?? 0 ),
				(int) ( $totals['pageviews'] ?? 0 )
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- import source
			$rows_raw = (array) $wpdb->get_results(
				$wpdb->prepare(
					'SELECT page_url, page_id, COUNT(*) AS pageviews, COUNT(DISTINCT uid_id) AS visitors
					 FROM %i WHERE DATE(FROM_UNIXTIME(time + %d)) = %s GROUP BY page_url, page_id',
					$table,
					$tz,
					$date
				),
				ARRAY_A
			);

			$rows = [];
			foreach ( $rows_raw as $row ) {
				$path = (string) $row['page_url'];
				if ( '' === $path ) {
					continue;
				}
				// Burst stores the path, but some setups record a full URL.
				if ( 0 === strpos( $path, 'http' ) ) {
					$path = (string) wp_parse_url( $path, PHP_URL_PATH );
				}
				if ( '' === $path || '/' !== $path[0] ) {
					continue;
				}
				$rows[] = [
					'date'      => $date,
					'path'      => $path,
					'post_id'   => (int) $row['page_id'],
					'pageviews' => (int) $row['pageviews'],
					'visitors'  => (int) $row['visitors'],
				];
			}
			if ( $rows ) {
				$this->upsert_post_stats_bulk( $rows );
			}

			$this->import_missing_pages( $date, $tz );
			if ( $sessions ) {
				$this->import_breakdowns( $date, $tz );
			}

			$this->assert_no_db_error();
		}

		$next = $offset + count( $dates );

		return [
			'done'        => $next >= $total,
			'processed'   => $next,
			'total'       => $total,
			'next_offset' => $next,
			/* translators: 1: days imported, 2: total days. */
			'label'       => sprintf( _n( 'Imported %1$d of %2$d day from Burst Statistics.', 'Imported %1$d of %2$d days from Burst Statistics.', $total, 'vireo-analytics' ), $next, $total ),
		];
	}

	public function carries(): string {
		return __( 'Brings across daily pageviews and visitors, your pages, referrers, which posts each referrer sent readers to, countries, browsers, operating systems, devices and missing pages.', 'vireo-analytics' );
	}

	/**
	 * Burst records a 404 as an ordinary hit with page_type '404'. It leaves
	 * them out of its own reports; Vireo counts the view and also lists the
	 * path under missing pages, so they stay in the totals above and are
	 * copied here as well. The referrer belongs to the session rather than
	 * the hit, so it can't be attached to the missing page honestly.
	 */
	private function import_missing_pages( string $date, int $tz ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- import source
		$raw = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT page_url, COUNT(*) AS hits FROM %i
				 WHERE DATE(FROM_UNIXTIME(time + %d)) = %s AND page_type = %s GROUP BY page_url',
				$wpdb->prefix . 'burst_statistics',
				$tz,
				$date,
				'404'
			),
			ARRAY_A
		);
		$this->upsert_404_stats_bulk( self::missing_rows( $date, $raw ) );
	}

	/**
	 * @param array<int, array{page_url:string, hits:int|string}> $raw
	 * @return array<int, array{date:string,path:string,referrer:string,internal_ref:string,hits:int}>
	 */
	public static function missing_rows( string $date, array $raw ): array {
		$out = [];
		foreach ( $raw as $r ) {
			$path = (string) $r['page_url'];
			if ( 0 === strpos( $path, 'http' ) ) {
				$path = (string) wp_parse_url( $path, PHP_URL_PATH );
			}
			if ( '' === $path || '/' !== $path[0] ) {
				continue;
			}
			$out[] = [
				'date'         => $date,
				'path'         => $path,
				'referrer'     => '',
				'internal_ref' => '',
				'hits'         => (int) $r['hits'],
			];
		}
		return self::merge_rows( $out, [ 'date', 'path' ], [ 'hits' ] );
	}

	/**
	 * Referrers, countries and devices. Burst keeps all three on the session
	 * (since 2.0; older installs upgrade on Burst's next load), so each hit is
	 * joined to its session for the country and device, and each session that
	 * arrived from elsewhere counts once for its referrer, on its landing page.
	 */
	private function import_breakdowns( string $date, int $tz ): void {
		global $wpdb;
		$hits      = $wpdb->prefix . 'burst_statistics';
		$sessions  = $wpdb->prefix . 'burst_sessions';
		$self_host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- import source
		$landings = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT s.referrer, s.uid_id,
				        (SELECT st.page_id FROM %i st WHERE st.session_id = s.ID ORDER BY st.ID ASC LIMIT 1) AS page_id
				 FROM %i s
				 WHERE DATE(FROM_UNIXTIME(s.start_time + %d)) = %s AND s.referrer IS NOT NULL AND s.referrer <> %s',
				$hits,
				$sessions,
				$tz,
				$date,
				''
			),
			ARRAY_A
		);
		$refs     = self::landing_referrer_rows( $date, $landings, $self_host );
		$this->upsert_referrer_stats_bulk( $refs['site'] );
		$this->upsert_post_referrer_stats_bulk( $refs['posts'] );

		if ( self::column_exists( $wpdb->prefix . 'burst_locations', 'country_code' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- import source
			$geo = (array) $wpdb->get_results(
				$wpdb->prepare(
					'SELECT l.country_code, COUNT(*) AS pageviews, COUNT(DISTINCT st.uid_id) AS visitors
					 FROM %i st
					 INNER JOIN %i s ON s.ID = st.session_id
					 INNER JOIN %i l ON l.city_code = s.city_code
					 WHERE DATE(FROM_UNIXTIME(st.time + %d)) = %s
					 GROUP BY l.country_code',
					$hits,
					$sessions,
					$wpdb->prefix . 'burst_locations',
					$tz,
					$date
				),
				ARRAY_A
			);
			foreach ( $geo as $r ) {
				$code = strtoupper( (string) $r['country_code'] );
				if ( 1 === preg_match( '/^[A-Z]{2}$/', $code ) ) {
					$this->upsert_geo_stats( $date, $code, (int) $r['visitors'], (int) $r['pageviews'] );
				}
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- import source
		$tech = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT b.name AS browser, p.name AS os, d.name AS device,
				        COUNT(*) AS pageviews, COUNT(DISTINCT st.uid_id) AS visitors
				 FROM %i st
				 INNER JOIN %i s ON s.ID = st.session_id
				 LEFT JOIN %i b ON b.ID = s.browser_id
				 LEFT JOIN %i p ON p.ID = s.platform_id
				 LEFT JOIN %i d ON d.ID = s.device_id
				 WHERE DATE(FROM_UNIXTIME(st.time + %d)) = %s
				 GROUP BY s.browser_id, s.platform_id, s.device_id',
				$hits,
				$sessions,
				$wpdb->prefix . 'burst_browsers',
				$wpdb->prefix . 'burst_platforms',
				$wpdb->prefix . 'burst_devices',
				$tz,
				$date
			),
			ARRAY_A
		);
		foreach ( self::tech_rows( $date, $tech ) as $r ) {
			$this->upsert_device_stats( $date, $r['browser'], $r['os'], $r['device_type'], $r['visitors'], $r['pageviews'] );
		}
	}
}
