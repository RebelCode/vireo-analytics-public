<?php

namespace VireoAnalytics\Importers;

defined( 'ABSPATH' ) || exit;

/**
 * Import history from SlimStat Analytics.
 *
 * One row per pageview, with a unix timestamp in `dt`, the path in `resource`,
 * the post id in `content_id`, the referrer in `referer`, and a two-letter
 * country code. Visitors are counted per day by distinct `ip`, which is how
 * SlimStat's own daily reports count them.
 *
 * Not every row is a pageview. SlimStat stores a download as its own row with
 * content_type 'download', and, unless told to ignore bots, crawlers and feed
 * readers as rows with browser_type 1 and 3. Those are left out of the traffic
 * and come across as download events and filtered bots instead. An outbound
 * click is written onto the row of the page it was clicked from.
 *
 * Timestamps are bucketed in the site's timezone rather than UTC, so evening
 * traffic is not filed under the following day anywhere east of Greenwich.
 */
final class SlimStat_Importer extends Importer_Base {

	public function slug(): string {
		return 'slimstat';
	}

	public function name(): string {
		return __( 'SlimStat Analytics', 'vireo-analytics' );
	}

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'slim_stats';
	}

	private function tz_offset(): int {
		$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
		return (int) $tz->getOffset( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) );
	}

	public function is_available(): bool {
		return self::table_has_rows( $this->table() );
	}

	public function count_records(): int {
		global $wpdb;
		if ( ! $this->is_available() ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- import source
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(DISTINCT DATE(FROM_UNIXTIME(dt + %d))) FROM %i',
				$this->tz_offset(),
				$this->table()
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
				'label'       => __( 'No SlimStat data found.', 'vireo-analytics' ),
			];
		}

		$table = $this->table();
		$limit = max( 1, min( 30, $limit ) );
		$tz    = $this->tz_offset();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- import source
		$dates = (array) $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT DATE(FROM_UNIXTIME(dt + %d)) AS d FROM %i ORDER BY d ASC LIMIT %d OFFSET %d',
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
				'label'       => __( 'SlimStat import complete.', 'vireo-analytics' ),
			];
		}

		$site_host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );

		foreach ( $dates as $date ) {
			$date = (string) $date;
			$day  = $wpdb->prepare( 'DATE(FROM_UNIXTIME(dt + %d)) = %s', $tz, $date );
			// Rows that are a person viewing a page: not a crawler or feed
			// reader, and not a download.
			$human = $wpdb->prepare( 'browser_type NOT IN (%d, %d) AND (content_type IS NULL OR content_type <> %s)', 1, 3, 'download' );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- import source; $day and $human are prepared above
			$totals = $wpdb->get_row(
				$wpdb->prepare( "SELECT COUNT(*) AS pageviews, COUNT(DISTINCT ip) AS visitors FROM %i WHERE {$day} AND {$human}", $table ),
				ARRAY_A
			);

			$this->upsert_site_stats(
				$date,
				(int) ( $totals['visitors'] ?? 0 ),
				(int) ( $totals['pageviews'] ?? 0 )
			);

			$rows_raw = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT resource, content_id, COUNT(*) AS pageviews, COUNT(DISTINCT ip) AS visitors
					 FROM %i WHERE {$day} AND {$human} AND resource <> %s
					 GROUP BY resource, content_id",
					$table,
					''
				),
				ARRAY_A
			);

			$rows = [];
			foreach ( $rows_raw as $row ) {
				$path = self::path_of( (string) $row['resource'] );
				if ( '' === $path ) {
					continue;
				}
				$rows[] = [
					'date'      => $date,
					'path'      => $path,
					'post_id'   => (int) $row['content_id'],
					'pageviews' => (int) $row['pageviews'],
					'visitors'  => (int) $row['visitors'],
				];
			}
			if ( $rows ) {
				$this->upsert_post_stats_bulk( $rows );
			}

			// Referrers, external only, overall and per post.
			$refs = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT referer, content_id, COUNT(*) AS pageviews, COUNT(DISTINCT ip) AS visitors
					 FROM %i WHERE {$day} AND {$human} AND referer <> %s
					 GROUP BY referer, content_id",
					$table,
					''
				),
				ARRAY_A
			);

			$ref_rows  = [];
			$post_rows = [];
			foreach ( $refs as $row ) {
				$host = self::referrer_host( (string) $row['referer'], $site_host );
				if ( '' === $host ) {
					continue;
				}
				$ref_rows[]  = [
					'date'      => $date,
					'host'      => $host,
					'pageviews' => (int) $row['pageviews'],
					'visitors'  => (int) $row['visitors'],
				];
				$post_rows[] = [
					'date'      => $date,
					'post_id'   => (int) $row['content_id'],
					'host'      => $host,
					'pageviews' => (int) $row['pageviews'],
					'visitors'  => (int) $row['visitors'],
				];
			}
			$this->upsert_referrer_stats_bulk( self::merge_rows( $ref_rows, [ 'host' ], [ 'pageviews', 'visitors' ] ) );
			$this->upsert_post_referrer_stats_bulk( self::merge_rows( $post_rows, [ 'post_id', 'host' ], [ 'pageviews', 'visitors' ] ) );

			// Countries.
			$geo = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT country, COUNT(*) AS pageviews, COUNT(DISTINCT ip) AS visitors
					 FROM %i WHERE {$day} AND {$human} AND country <> %s
					 GROUP BY country",
					$table,
					''
				),
				ARRAY_A
			);
			foreach ( $geo as $row ) {
				$code = strtoupper( substr( (string) $row['country'], 0, 2 ) );
				if ( 2 !== strlen( $code ) || 'XX' === $code ) {
					continue;
				}
				$this->upsert_geo_stats( $date, $code, (int) $row['visitors'], (int) $row['pageviews'] );
			}

			$tech = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT browser, platform AS os, browser_type, COUNT(*) AS pageviews, COUNT(DISTINCT ip) AS visitors
					 FROM %i WHERE {$day} AND {$human}
					 GROUP BY browser, platform, browser_type",
					$table
				),
				ARRAY_A
			);
			foreach ( self::tech_rows( $date, self::with_device( $tech ) ) as $r ) {
				$this->upsert_device_stats( $date, $r['browser'], $r['os'], $r['device_type'], $r['visitors'], $r['pageviews'] );
			}

			$missing = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT resource, referer, COUNT(*) AS hits
					 FROM %i WHERE {$day} AND {$human} AND content_type = %s
					 GROUP BY resource, referer",
					$table,
					'404'
				),
				ARRAY_A
			);
			$this->upsert_404_stats_bulk( self::missing_rows( $date, $missing, $site_host ) );

			$bots = (array) $wpdb->get_results(
				$wpdb->prepare( "SELECT user_agent, COUNT(*) AS hits FROM %i WHERE {$day} AND browser_type IN (%d, %d) GROUP BY user_agent", $table, 1, 3 ),
				ARRAY_A
			);

			$downloads = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT resource, COUNT(*) AS hits, COUNT(DISTINCT ip) AS visitors
					 FROM %i WHERE {$day} AND browser_type NOT IN (%d, %d) AND content_type = %s
					 GROUP BY resource",
					$table,
					1,
					3,
					'download'
				),
				ARRAY_A
			);

			$outbound = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT outbound_resource, ip FROM %i WHERE {$day} AND {$human} AND outbound_resource <> %s",
					$table,
					''
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			$this->upsert_bot_stats_bulk( self::bot_rows( $date, $bots ) );
			$this->upsert_event_stats_bulk( self::event_rows( $date, $downloads, $outbound, $site_host ) );

			$this->assert_no_db_error();
		}

		$next = $offset + count( $dates );

		return [
			'done'        => $next >= $total,
			'processed'   => $next,
			'total'       => $total,
			'next_offset' => $next,
			/* translators: 1: days imported, 2: total days. */
			'label'       => sprintf( _n( 'Imported %1$d of %2$d day from SlimStat.', 'Imported %1$d of %2$d days from SlimStat.', $total, 'vireo-analytics' ), $next, $total ),
		];
	}

	public function carries(): string {
		return __( 'Brings across daily pageviews and visitors, your pages, referrers, which posts each referrer sent readers to, countries, browsers, operating systems, devices, missing pages, downloads and outbound clicks, and the crawlers SlimStat recorded, as filtered bots rather than visits.', 'vireo-analytics' );
	}

	private static function path_of( string $raw ): string {
		$path = $raw;
		if ( 0 === strpos( $path, 'http' ) ) {
			$path = (string) wp_parse_url( $path, PHP_URL_PATH );
		}
		return '' === $path || '/' !== $path[0] ? '' : $path;
	}

	/**
	 * SlimStat records no device type, only browser_type: 2 for a mobile
	 * browser, 0 for anything else. Tablets report as one or the other
	 * depending on the browser, so there is no Tablet here.
	 *
	 * @param array<int, array<string,mixed>> $tech
	 * @return array<int, array<string,mixed>>
	 */
	public static function with_device( array $tech ): array {
		foreach ( $tech as &$t ) {
			$t['device'] = 2 === (int) $t['browser_type'] ? 'mobile' : 'desktop';
		}
		return $tech;
	}

	/**
	 * @param array<int, array{resource:string, referer:string, hits:int|string}> $raw
	 * @return array<int, array{date:string,path:string,referrer:string,internal_ref:string,hits:int}>
	 */
	public static function missing_rows( string $date, array $raw, string $self_host ): array {
		$out = [];
		foreach ( $raw as $m ) {
			$path = self::path_of( (string) $m['resource'] );
			if ( '' === $path ) {
				continue;
			}
			$referer  = (string) $m['referer'];
			$external = self::referrer_host( $referer, $self_host );
			$internal = '';
			if ( '' === $external && '' !== $referer ) {
				$internal = (string) wp_parse_url( $referer, PHP_URL_PATH );
				$internal = '' === $internal ? '/' : $internal;
			}
			$out[] = [
				'date'         => $date,
				'path'         => $path,
				'referrer'     => $external,
				'internal_ref' => $internal === $path ? '' : $internal,
				'hits'         => (int) $m['hits'],
			];
		}
		return self::merge_rows( $out, [ 'path', 'referrer', 'internal_ref' ], [ 'hits' ] );
	}

	/**
	 * Crawler and feed reader rows, sorted into the bot filter's categories
	 * by their user agent, so they read the same as bots Vireo filters itself.
	 *
	 * @param array<int, array{user_agent:string, hits:int|string}> $raw
	 * @return array<int, array{date:string,category:string,hits:int}>
	 */
	public static function bot_rows( string $date, array $raw ): array {
		$out = [];
		foreach ( $raw as $b ) {
			$category = class_exists( \VireoAnalytics\Bot_Filter::class )
				? \VireoAnalytics\Bot_Filter::classify( (string) $b['user_agent'], [] )
				: '';
			$out[]    = [
				'date'     => $date,
				'category' => '' === $category ? 'other' : $category,
				'hits'     => (int) $b['hits'],
			];
		}
		return self::merge_rows( $out, [ 'category' ], [ 'hits' ] );
	}

	/**
	 * Downloads by file name, and outbound clicks by host. One row can carry
	 * several outbound clicks, joined with ';;;'.
	 *
	 * @param array<int, array{resource:string, hits:int|string, visitors:int|string}> $downloads
	 * @param array<int, array{outbound_resource:string, ip:string}>                   $outbound
	 * @return array<int, array{date:string,name:string,value:string,count:int,unique_count:int}>
	 */
	public static function event_rows( string $date, array $downloads, array $outbound, string $self_host ): array {
		$out = [];
		foreach ( $downloads as $d ) {
			$name = self::download_name( (string) $d['resource'] );
			if ( '' === $name ) {
				continue;
			}
			$out[] = [
				'date'         => $date,
				'name'         => 'download',
				'value'        => $name,
				'count'        => (int) $d['hits'],
				'unique_count' => (int) $d['visitors'],
			];
		}
		$out = self::merge_rows( $out, [ 'value' ], [ 'count', 'unique_count' ] );

		$hosts = [];
		foreach ( $outbound as $o ) {
			foreach ( explode( ';;;', (string) $o['outbound_resource'] ) as $url ) {
				$host = self::outbound_host( $url, $self_host );
				if ( '' === $host ) {
					continue;
				}
				$hosts[ $host ]['count']                    = ( $hosts[ $host ]['count'] ?? 0 ) + 1;
				$hosts[ $host ]['ips'][ (string) $o['ip'] ] = true;
			}
		}
		foreach ( $hosts as $host => $h ) {
			$out[] = [
				'date'         => $date,
				'name'         => 'outbound',
				'value'        => (string) $host,
				'count'        => $h['count'],
				'unique_count' => count( $h['ips'] ),
			];
		}
		return $out;
	}
}
