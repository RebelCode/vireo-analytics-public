<?php

namespace VireoAnalytics\Importers;

defined( 'ABSPATH' ) || exit;

/**
 * Import history from Independent Analytics.
 *
 * Views carry a datetime and a resource id. The resource table holds what was
 * viewed: `resource` is its kind ('singular', 'home', '404', ...), not a path,
 * so the path comes from the URL Independent Analytics caches for it, and the
 * post id from `singular_id`. Sessions carry the visitor id, the referrer, the
 * country and the device, so those breakdowns join each view to its session.
 *
 * Note on visitor counts: Independent Analytics identifies a visitor with a
 * hash that, on a default install, never rotates, so its own reports can
 * recognise someone across months. Vireo scopes a visitor to a day. Importing
 * per-day distinct visitors is the closest honest translation; monthly totals
 * will read higher here than they did there, and that is the difference in
 * definition rather than a fault in the import.
 */
final class Independent_Analytics_Importer extends Importer_Base {

	public function slug(): string {
		return 'independent-analytics';
	}

	public function name(): string {
		return __( 'Independent Analytics', 'vireo-analytics' );
	}

	private function views_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'independent_analytics_views';
	}

	public function is_available(): bool {
		return self::table_has_rows( $this->views_table() );
	}

	public function count_records(): int {
		global $wpdb;
		if ( ! $this->is_available() ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- import source
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(DISTINCT DATE(viewed_at)) FROM %i', $this->views_table() )
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
				'label'       => __( 'No Independent Analytics data found.', 'vireo-analytics' ),
			];
		}

		$views     = $this->views_table();
		$resources = $wpdb->prefix . 'independent_analytics_resources';
		$sessions  = $wpdb->prefix . 'independent_analytics_sessions';
		$limit     = max( 1, min( 30, $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- import source
		$dates = (array) $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT DATE(viewed_at) AS d FROM %i ORDER BY d ASC LIMIT %d OFFSET %d',
				$views,
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
				'label'       => __( 'Independent Analytics import complete.', 'vireo-analytics' ),
			];
		}

		foreach ( $dates as $date ) {
			$date = (string) $date;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- import source
			$pageviews = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE DATE(viewed_at) = %s', $views, $date )
			);

			// Distinct visitors whose views land on this day, via the session row.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- import source
			$visitors = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(DISTINCT s.visitor_id) FROM %i v
					 INNER JOIN %i s ON s.session_id = v.session_id
					 WHERE DATE(v.viewed_at) = %s',
					$views,
					$sessions,
					$date
				)
			);

			$this->upsert_site_stats( $date, $visitors, $pageviews );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- import source
			$rows_raw = (array) $wpdb->get_results(
				$wpdb->prepare(
					'SELECT r.resource, r.singular_id, r.cached_url, r.not_found_url,
					        COUNT(*) AS pageviews, COUNT(DISTINCT s.visitor_id) AS visitors
					 FROM %i v
					 INNER JOIN %i r ON r.id = v.resource_id
					 LEFT JOIN %i s ON s.session_id = v.session_id
					 WHERE DATE(v.viewed_at) = %s
					 GROUP BY r.id',
					$views,
					$resources,
					$sessions,
					$date
				),
				ARRAY_A
			);

			$rows = [];
			foreach ( $rows_raw as $row ) {
				$path = $this->resource_path( $row );
				if ( '' === $path ) {
					continue;
				}
				$rows[] = [
					'date'      => $date,
					'path'      => $path,
					'post_id'   => 'singular' === $row['resource'] ? (int) $row['singular_id'] : 0,
					'pageviews' => (int) $row['pageviews'],
					'visitors'  => (int) $row['visitors'],
				];
			}
			if ( $rows ) {
				$this->upsert_post_stats_bulk( self::merge_rows( $rows, [ 'path', 'post_id' ], [ 'pageviews', 'visitors' ] ) );
			}

			$this->import_breakdowns( $date );

			$this->assert_no_db_error();
		}

		$next = $offset + count( $dates );

		return [
			'done'        => $next >= $total,
			'processed'   => $next,
			'total'       => $total,
			'next_offset' => $next,
			/* translators: 1: days imported, 2: total days. */
			'label'       => sprintf( _n( 'Imported %1$d of %2$d day from Independent Analytics.', 'Imported %1$d of %2$d days from Independent Analytics.', $total, 'vireo-analytics' ), $next, $total ),
		];
	}

	public function carries(): string {
		return __( 'Brings across daily pageviews and visitors, your pages, referrers, which posts each referrer sent readers to, countries, browsers, operating systems, devices, missing pages, and tracked link clicks and form submissions as events.', 'vireo-analytics' );
	}

	/**
	 * The path a resource row was viewed at, or '' when there is none to be had.
	 *
	 * @param array<string,mixed> $row
	 */
	private function resource_path( array $row ): string {
		$path = self::path_of_resource( $row );
		if ( '' === $path && 'singular' === (string) $row['resource'] && (int) $row['singular_id'] > 0 ) {
			$url  = get_permalink( (int) $row['singular_id'] );
			$path = is_string( $url ) ? (string) wp_parse_url( $url, PHP_URL_PATH ) : '';
		}
		return $path;
	}

	/**
	 * @param array{resource:string, cached_url:?string, not_found_url:?string} $row
	 */
	public static function path_of_resource( array $row ): string {
		$path = '';
		if ( '404' === (string) $row['resource'] && '' !== (string) $row['not_found_url'] ) {
			$path = '/' . ltrim( (string) wp_parse_url( (string) $row['not_found_url'], PHP_URL_PATH ), '/' );
		} elseif ( '' !== (string) $row['cached_url'] ) {
			$path = (string) wp_parse_url( (string) $row['cached_url'], PHP_URL_PATH );
		} elseif ( 'home' === (string) $row['resource'] ) {
			$path = '/';
		}
		if ( '' === $path ) {
			return '';
		}
		if ( '/' !== $path[0] ) {
			$path = '/' . $path;
		}
		return \VireoAnalytics\Collector::clip( $path, 500 );
	}

	private function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'independent_analytics_' . $name;
	}

	/**
	 * Referrers, countries, devices, missing pages, clicks and forms for one
	 * day. Each block checks for its own tables: older installs, and features
	 * Independent Analytics only fills in its paid edition, may not have them.
	 */
	private function import_breakdowns( string $date ): void {
		global $wpdb;
		$views     = $this->table( 'views' );
		$sessions  = $this->table( 'sessions' );
		$resources = $this->table( 'resources' );
		$self_host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );

		if ( self::column_exists( $this->table( 'referrers' ), 'domain' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- import source
			$landings = (array) $wpdb->get_results(
				$wpdb->prepare(
					'SELECT ref.domain AS referrer, s.visitor_id AS uid_id,
					        IF(r.resource = %s, r.singular_id, 0) AS page_id
					 FROM %i s
					 INNER JOIN %i iv ON iv.id = s.initial_view_id
					 INNER JOIN %i ref ON ref.id = s.referrer_id
					 LEFT JOIN %i r ON r.id = iv.resource_id
					 WHERE DATE(iv.viewed_at) = %s AND ref.domain <> %s',
					'singular',
					$sessions,
					$views,
					$this->table( 'referrers' ),
					$resources,
					$date,
					''
				),
				ARRAY_A
			);
			$refs     = self::landing_referrer_rows( $date, $landings, $self_host );
			$this->upsert_referrer_stats_bulk( $refs['site'] );
			$this->upsert_post_referrer_stats_bulk( $refs['posts'] );
		}

		if ( self::column_exists( $this->table( 'countries' ), 'country_code' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- import source
			$geo = (array) $wpdb->get_results(
				$wpdb->prepare(
					'SELECT c.country_code, COUNT(*) AS pageviews, COUNT(DISTINCT s.visitor_id) AS visitors
					 FROM %i v
					 INNER JOIN %i s ON s.session_id = v.session_id
					 INNER JOIN %i c ON c.country_id = s.country_id
					 WHERE DATE(v.viewed_at) = %s
					 GROUP BY c.country_code',
					$views,
					$sessions,
					$this->table( 'countries' ),
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

		if ( self::column_exists( $sessions, 'device_browser_id' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- import source
			$tech = (array) $wpdb->get_results(
				$wpdb->prepare(
					'SELECT b.device_browser AS browser, o.device_os AS os, t.device_type AS device,
					        COUNT(*) AS pageviews, COUNT(DISTINCT s.visitor_id) AS visitors
					 FROM %i v
					 INNER JOIN %i s ON s.session_id = v.session_id
					 LEFT JOIN %i b ON b.device_browser_id = s.device_browser_id
					 LEFT JOIN %i o ON o.device_os_id = s.device_os_id
					 LEFT JOIN %i t ON t.device_type_id = s.device_type_id
					 WHERE DATE(v.viewed_at) = %s
					 GROUP BY s.device_browser_id, s.device_os_id, s.device_type_id',
					$views,
					$sessions,
					$this->table( 'device_browsers' ),
					$this->table( 'device_oss' ),
					$this->table( 'device_types' ),
					$date
				),
				ARRAY_A
			);
			foreach ( self::tech_rows( $date, $tech ) as $r ) {
				$this->upsert_device_stats( $date, $r['browser'], $r['os'], $r['device_type'], $r['visitors'], $r['pageviews'] );
			}
		}

		$this->import_missing_pages( $date );

		if ( self::column_exists( $this->table( 'clicked_links' ), 'link_id' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- import source
			$clicks = (array) $wpdb->get_results(
				$wpdb->prepare(
					'SELECT lr.type, lr.name, ct.target, ct.protocol, s.visitor_id
					 FROM %i c
					 INNER JOIN %i cl ON cl.click_id = c.click_id
					 INNER JOIN %i l ON l.id = cl.link_id
					 INNER JOIN %i lr ON lr.link_rule_id = l.link_rule_id
					 INNER JOIN %i ct ON ct.click_target_id = l.click_target_id
					 LEFT JOIN %i v ON v.id = c.view_id
					 LEFT JOIN %i s ON s.session_id = v.session_id
					 WHERE DATE(c.created_at) = %s',
					$this->table( 'clicks' ),
					$this->table( 'clicked_links' ),
					$this->table( 'links' ),
					$this->table( 'link_rules' ),
					$this->table( 'click_targets' ),
					$views,
					$sessions,
					$date
				),
				ARRAY_A
			);
			$this->upsert_event_stats_bulk( self::click_events( $date, $clicks, $self_host ) );
		}

		if ( self::column_exists( $this->table( 'form_submissions' ), 'form_id' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- import source
			$forms  = (array) $wpdb->get_results(
				$wpdb->prepare(
					'SELECT f.cached_form_title AS title, COUNT(*) AS submissions, COUNT(DISTINCT s.visitor_id) AS visitors
					 FROM %i fs
					 LEFT JOIN %i f ON f.form_id = fs.form_id
					 LEFT JOIN %i s ON s.session_id = fs.session_id
					 WHERE DATE(fs.created_at) = %s
					 GROUP BY fs.form_id',
					$this->table( 'form_submissions' ),
					$this->table( 'forms' ),
					$sessions,
					$date
				),
				ARRAY_A
			);
			$events = [];
			foreach ( $forms as $f ) {
				$events[] = [
					'date'         => $date,
					'name'         => 'form_submit',
					'value'        => (string) $f['title'],
					'count'        => (int) $f['submissions'],
					'unique_count' => (int) $f['visitors'],
				];
			}
			$this->upsert_event_stats_bulk( $events );
		}
	}

	/**
	 * Missing pages, with where the reader came from: the session's referrer
	 * when the 404 was where they landed, and the page before it when they
	 * followed a link on this site, which is the broken link to go and fix.
	 */
	private function import_missing_pages( string $date ): void {
		global $wpdb;
		$views     = $this->table( 'views' );
		$resources = $this->table( 'resources' );
		$has_refs  = self::column_exists( $this->table( 'referrers' ), 'domain' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- import source
		$raw = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT r.not_found_url, r.cached_url, r.resource,
				        IF(s.initial_view_id = v.id, %s, %s) AS landed,
				        s.referrer_id, pr.resource AS prev_resource, pr.singular_id AS prev_singular_id,
				        pr.cached_url AS prev_cached_url, pr.not_found_url AS prev_not_found_url,
				        COUNT(*) AS hits
				 FROM %i v
				 INNER JOIN %i r ON r.id = v.resource_id
				 LEFT JOIN %i s ON s.session_id = v.session_id
				 LEFT JOIN %i pv ON pv.next_view_id = v.id
				 LEFT JOIN %i pr ON pr.id = pv.resource_id
				 WHERE DATE(v.viewed_at) = %s AND r.resource = %s
				 GROUP BY r.id, landed, s.referrer_id, pr.id',
				'1',
				'0',
				$views,
				$resources,
				$this->table( 'sessions' ),
				$views,
				$resources,
				$date,
				'404'
			),
			ARRAY_A
		);
		if ( ! $raw ) {
			return;
		}

		$domains = [];
		if ( $has_refs ) {
			$ids = array_values( array_unique( array_filter( array_map( 'intval', array_column( $raw, 'referrer_id' ) ) ) ) );
			if ( $ids ) {
				$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders holds only %d markers
				$domains = (array) $wpdb->get_results(
					$wpdb->prepare( "SELECT id, domain FROM %i WHERE id IN ({$placeholders})", $this->table( 'referrers' ), ...$ids ),
					OBJECT_K
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			}
		}

		$self_host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$rows      = [];
		foreach ( $raw as $r ) {
			$prev   = null === $r['prev_resource'] ? '' : $this->resource_path(
				[
					'resource'      => (string) $r['prev_resource'],
					'singular_id'   => (int) $r['prev_singular_id'],
					'cached_url'    => (string) $r['prev_cached_url'],
					'not_found_url' => (string) $r['prev_not_found_url'],
				]
			);
			$domain = isset( $domains[ $r['referrer_id'] ] ) ? (string) $domains[ $r['referrer_id'] ]->domain : '';
			$rows[] = [
				'date'         => $date,
				'path'         => self::path_of_resource( $r ),
				'referrer'     => '1' === (string) $r['landed'] ? self::referrer_host( $domain, $self_host ) : '',
				'internal_ref' => $prev,
				'hits'         => (int) $r['hits'],
			];
		}
		$this->upsert_404_stats_bulk( self::merge_rows( $rows, [ 'path', 'referrer', 'internal_ref' ], [ 'hits' ] ) );
	}

	/**
	 * Tracked clicks as the tracker's own events. A click is filed under every
	 * link rule it matched, as Independent Analytics files it: links to other
	 * sites as `outbound` by host, file extensions as `download` by file name,
	 * mailto and tel links as `mailto_click` (domain only, the way the tracker
	 * masks it) and `tel_click`, and any other rule under its own name.
	 *
	 * @param array<int, array{type:string, name:string, target:string, protocol:?string, visitor_id:int|string|null}> $raw
	 * @return array<int, array{date:string,name:string,value:string,count:int,unique_count:int}>
	 */
	public static function click_events( string $date, array $raw, string $self_host ): array {
		$agg = [];
		foreach ( $raw as $c ) {
			$target   = (string) $c['target'];
			$protocol = (string) $c['protocol'];
			$type     = (string) $c['type'];
			if ( 'mailto' === $protocol ) {
				$at    = strpos( $target, '@' );
				$name  = 'mailto_click';
				$value = false === $at ? $target : substr( $target, $at );
			} elseif ( 'tel' === $protocol ) {
				$name  = 'tel_click';
				$value = $target;
			} elseif ( 'sms' === $protocol ) {
				continue;
			} elseif ( 'extension' === $type ) {
				$name  = 'download';
				$value = self::download_name( $target );
			} elseif ( 'external' === $type ) {
				$name  = 'outbound';
				$value = self::outbound_host( $target, $self_host );
				if ( '' === $value ) {
					continue;
				}
			} else {
				$name  = (string) $c['name'];
				$value = '';
			}
			$k                  = $name . "\0" . $value;
			$agg[ $k ]['name']  = $name;
			$agg[ $k ]['value'] = $value;
			$agg[ $k ]['count'] = ( $agg[ $k ]['count'] ?? 0 ) + 1;
			$agg[ $k ]['visitors'][ (string) $c['visitor_id'] ] = true;
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
