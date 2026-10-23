<?php

namespace VireoAnalytics\Importers;

defined( 'ABSPATH' ) || exit;

/**
 * Import history from WP Statistics.
 *
 * Pageviews per path come from the pages table, which is already aggregated by
 * day. Visitors come from the visitor table, which holds one row per visitor
 * per day, so a daily count is a straight row count and needs no dedupe.
 *
 * Both tools scope a visitor to a single day, so the numbers carry over
 * without being reinterpreted. The visitor row also holds the browser, OS,
 * device, country and referrer, and `hits` is how many pages that visitor
 * viewed that day, which is what the breakdowns count as pageviews.
 */
final class WP_Statistics_Importer extends Importer_Base {

	public function slug(): string {
		return 'wp-statistics';
	}

	public function name(): string {
		return __( 'WP Statistics', 'vireo-analytics' );
	}

	public function is_available(): bool {
		global $wpdb;
		return self::table_has_rows( $wpdb->prefix . 'statistics_pages' );
	}

	public function count_records(): int {
		global $wpdb;
		if ( ! $this->is_available() ) {
			return 0;
		}
		$table = $wpdb->prefix . 'statistics_pages';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- import source
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT date) FROM %i', $table ) );
	}

	private function visitor_table_exists(): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'statistics_visitor';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- existence probe
		return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
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
				'label'       => __( 'No WP Statistics data found.', 'vireo-analytics' ),
			];
		}

		$pages    = $wpdb->prefix . 'statistics_pages';
		$visitors = $wpdb->prefix . 'statistics_visitor';
		$limit    = max( 1, min( 30, $limit ) );
		$has_v    = $this->visitor_table_exists();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- import source
		$dates = (array) $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT date FROM %i ORDER BY date ASC LIMIT %d OFFSET %d',
				$pages,
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
				'label'       => __( 'WP Statistics import complete.', 'vireo-analytics' ),
			];
		}

		foreach ( $dates as $date ) {
			$date = (string) $date;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- import source
			$pageviews = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT IFNULL(SUM(count),0) FROM %i WHERE date = %s', $pages, $date )
			);

			$day_visitors = 0;
			if ( $has_v ) {
				// One row per visitor per day in WP Statistics, so this is already
				// a unique daily count.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- import source
				$day_visitors = (int) $wpdb->get_var(
					$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE last_counter = %s', $visitors, $date )
				);
			}

			$this->upsert_site_stats( $date, $day_visitors, $pageviews );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- import source
			$rows_raw = (array) $wpdb->get_results(
				$wpdb->prepare(
					'SELECT uri, id, SUM(count) AS views FROM %i WHERE date = %s GROUP BY uri, id',
					$pages,
					$date
				),
				ARRAY_A
			);

			$rows = [];
			foreach ( $rows_raw as $row ) {
				$path = (string) $row['uri'];
				if ( '' === $path ) {
					continue;
				}
				if ( '/' !== $path[0] ) {
					$path = '/' . $path;
				}
				$rows[] = [
					'date'      => $date,
					'path'      => $path,
					// WP Statistics stores the post id alongside the uri where it
					// knows one; fall back to resolving the path.
					'post_id'   => (int) $row['id'] > 0 ? (int) $row['id'] : (int) url_to_postid( home_url( $path ) ),
					'pageviews' => (int) $row['views'],
					'visitors'  => 0,
				];
			}
			if ( $rows ) {
				$this->upsert_post_stats_bulk( $rows );
			}

			// Referrers, from the visitor table. Internal ones are dropped.
			if ( $has_v ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- import source
				$refs = (array) $wpdb->get_results(
					$wpdb->prepare(
						'SELECT referred, COUNT(*) AS hits FROM %i WHERE last_counter = %s AND referred <> %s GROUP BY referred',
						$visitors,
						$date,
						''
					),
					ARRAY_A
				);

				$site_host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
				$ref_rows  = [];
				foreach ( $refs as $row ) {
					$host = (string) wp_parse_url( (string) $row['referred'], PHP_URL_HOST );
					if ( '' === $host || strtolower( $host ) === strtolower( $site_host ) ) {
						continue;
					}
					$ref_rows[] = [
						'date'      => $date,
						'host'      => $host,
						'pageviews' => (int) $row['hits'],
						'visitors'  => (int) $row['hits'],
					];
				}
				if ( $ref_rows ) {
					$this->upsert_referrer_stats_bulk( $ref_rows );
				}

				// Countries. WP Statistics stores a two-letter code in `location`.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- import source
				$geo = (array) $wpdb->get_results(
					$wpdb->prepare(
						'SELECT location, COUNT(*) AS hits, SUM(GREATEST(hits, 1)) AS views FROM %i WHERE last_counter = %s AND location <> %s GROUP BY location',
						$visitors,
						$date,
						''
					),
					ARRAY_A
				);
				foreach ( $geo as $row ) {
					$code = strtoupper( substr( (string) $row['location'], 0, 2 ) );
					if ( 2 !== strlen( $code ) ) {
						continue;
					}
					$this->upsert_geo_stats( $date, $code, (int) $row['hits'], (int) $row['views'] );
				}

				$this->import_visitor_breakdowns( $date );
			}

			$this->import_page_breakdowns( $date );

			$this->assert_no_db_error();
		}

		$next = $offset + count( $dates );

		return [
			'done'        => $next >= $total,
			'processed'   => $next,
			'total'       => $total,
			'next_offset' => $next,
			/* translators: 1: days imported, 2: total days. */
			'label'       => sprintf( _n( 'Imported %1$d of %2$d day from WP Statistics.', 'Imported %1$d of %2$d days from WP Statistics.', $total, 'vireo-analytics' ), $next, $total ),
		];
	}

	/** Exclusion reasons that mean automated traffic, and the bot category each one is. */
	private const BOT_REASONS = [
		'robot'           => 'other',
		'headless'        => 'automation',
		'prefetch'        => 'prefetch',
		'robot_threshold' => 'repeat',
	];

	/**
	 * Names WP Statistics reserves for its own tracking. Only `click`,
	 * `mouseup` and `file_download` are ever recorded; the rest can't be
	 * custom events, so a row under one of them isn't imported as one.
	 */
	private const RESERVED_EVENTS = [ 'page_view', 'video_start', 'custom_event', 'user_login', 'session_start', 'video_progress', 'analytics', 'view_search_results', 'user_engagement', 'video_complete', 'reserved_event', 'user_logout', 'scroll', 'add_to_cart', 'tracking_event', 'form_submit', 'purchase', 'system_event', 'notification_open', 'error_event' ];

	public function carries(): string {
		return __( 'Brings across daily pageviews and visitors, your pages, referrers, which posts each referrer sent readers to, countries, browsers, operating systems, devices, missing pages, link clicks, downloads and custom events, and, if WP Statistics recorded exclusions, the bots it filtered out.', 'vireo-analytics' );
	}

	private function import_visitor_breakdowns( string $date ): void {
		global $wpdb;
		$visitors  = $wpdb->prefix . 'statistics_visitor';
		$pages     = $wpdb->prefix . 'statistics_pages';
		$self_host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- import source
		$tech = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT agent AS browser, platform AS os, device, COUNT(*) AS visitors, SUM(GREATEST(hits, 1)) AS pageviews
				 FROM %i WHERE last_counter = %s GROUP BY agent, platform, device',
				$visitors,
				$date
			),
			ARRAY_A
		);
		foreach ( self::tech_rows( $date, $tech ) as $r ) {
			$this->upsert_device_stats( $date, $r['browser'], $r['os'], $r['device_type'], $r['visitors'], $r['pageviews'] );
		}

		// The page a visitor first landed on. Older WP Statistics versions
		// don't record it, and their rows simply have no landing page.
		if ( self::column_exists( $visitors, 'first_page' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- import source
			$landings = (array) $wpdb->get_results(
				$wpdb->prepare(
					'SELECT v.referred AS referrer, v.ID AS uid_id, p.id AS page_id
					 FROM %i v INNER JOIN %i p ON p.page_id = v.first_page
					 WHERE v.last_counter = %s AND v.referred <> %s',
					$visitors,
					$pages,
					$date,
					''
				),
				ARRAY_A
			);
			$this->upsert_post_referrer_stats_bulk( self::landing_referrer_rows( $date, $landings, $self_host )['posts'] );
		}
	}

	private function import_page_breakdowns( string $date ): void {
		global $wpdb;
		$self_host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- import source
		$missing = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT uri, SUM(count) AS hits FROM %i WHERE date = %s AND type = %s GROUP BY uri',
				$wpdb->prefix . 'statistics_pages',
				$date,
				'404'
			),
			ARRAY_A
		);
		$rows    = [];
		foreach ( $missing as $m ) {
			$path   = '/' . ltrim( (string) wp_parse_url( (string) $m['uri'], PHP_URL_PATH ), '/' );
			$rows[] = [
				'date'         => $date,
				'path'         => $path,
				'referrer'     => '',
				'internal_ref' => '',
				'hits'         => (int) $m['hits'],
			];
		}
		$this->upsert_404_stats_bulk( self::merge_rows( $rows, [ 'path' ], [ 'hits' ] ) );

		if ( self::column_exists( $wpdb->prefix . 'statistics_exclusions', 'reason' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- import source
			$excluded = (array) $wpdb->get_results(
				$wpdb->prepare(
					'SELECT reason, SUM(count) AS hits FROM %i WHERE date = %s GROUP BY reason',
					$wpdb->prefix . 'statistics_exclusions',
					$date
				),
				ARRAY_A
			);
			$this->upsert_bot_stats_bulk( self::bot_rows( $date, $excluded ) );
		}

		if ( self::column_exists( $wpdb->prefix . 'statistics_events', 'event_name' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- import source
			$events = (array) $wpdb->get_results(
				$wpdb->prepare(
					'SELECT event_name, event_data, visitor_id FROM %i WHERE DATE(date) = %s',
					$wpdb->prefix . 'statistics_events',
					$date
				),
				ARRAY_A
			);
			$this->upsert_event_stats_bulk( self::event_rows( $date, $events, $self_host ) );
		}
	}

	/**
	 * Only the reasons that mean a bot. The rest (logged-in users, excluded
	 * IPs, admin pages, feeds) are traffic the site chose not to count, not
	 * automated traffic, and Vireo has no report for them.
	 *
	 * @param array<int, array{reason:string, hits:int|string}> $raw
	 * @return array<int, array{date:string,category:string,hits:int}>
	 */
	public static function bot_rows( string $date, array $raw ): array {
		$out = [];
		foreach ( $raw as $r ) {
			$reason = strtolower( (string) $r['reason'] );
			if ( ! isset( self::BOT_REASONS[ $reason ] ) ) {
				continue;
			}
			$out[] = [
				'date'     => $date,
				'category' => self::BOT_REASONS[ $reason ],
				'hits'     => (int) $r['hits'],
			];
		}
		return self::merge_rows( $out, [ 'category' ], [ 'hits' ] );
	}

	/**
	 * Link clicks (`click`, or `mouseup` for a middle click) to another site
	 * become `outbound` by host, `file_download` becomes `download` by file
	 * name, and a custom event keeps its name.
	 *
	 * @param array<int, array{event_name:string, event_data:string, visitor_id:int|string|null}> $raw
	 * @return array<int, array{date:string,name:string,value:string,count:int,unique_count:int}>
	 */
	public static function event_rows( string $date, array $raw, string $self_host ): array {
		$agg = [];
		foreach ( $raw as $e ) {
			$name = (string) $e['event_name'];
			$data = json_decode( (string) $e['event_data'], true );
			$url  = is_array( $data ) ? (string) ( $data['target_url'] ?? '' ) : '';
			if ( in_array( $name, [ 'click', 'mouseup' ], true ) ) {
				$value = self::outbound_host( $url, $self_host );
				$name  = 'outbound';
			} elseif ( 'file_download' === $name ) {
				$value = self::download_name( $url );
				$name  = 'download';
			} elseif ( in_array( $name, self::RESERVED_EVENTS, true ) ) {
				continue;
			} else {
				$value = '';
			}
			if ( '' === $name || ( in_array( $name, [ 'outbound', 'download' ], true ) && '' === $value ) ) {
				continue;
			}
			$k                  = $name . "\0" . $value;
			$agg[ $k ]['name']  = $name;
			$agg[ $k ]['value'] = $value;
			$agg[ $k ]['count'] = ( $agg[ $k ]['count'] ?? 0 ) + 1;
			$agg[ $k ]['visitors'][ (string) $e['visitor_id'] ] = true;
		}

		$out = [];
		foreach ( $agg as $a ) {
			$out[] = [
				'date'         => $date,
				'name'         => $a['name'],
				'value'        => $a['value'],
				'count'        => $a['count'],
				'unique_count' => count( $a['visitors'] ),
			];
		}
		return $out;
	}
}
