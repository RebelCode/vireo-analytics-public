<?php

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * Every page with traffic in a range, sortable, searchable and paged.
 *
 * The Overview lists the top ten; this is the same data without the cap.
 * Visitors are summed across days, as everywhere else in the dashboard, and
 * time on page is the average over the views that reported one.
 */
class Pages_Report {

	public const PER_PAGE = 50;

	public const DEFAULT_SORT = 'pageviews';

	public const TITLE_MATCH_CAP = 500;

	private const SORT_SQL = [
		'pageviews' => 'pageviews',
		'visitors'  => 'visitors',
		'avg_time'  => 'avg_time',
	];

	/**
	 * Clean the report's own view params. Anything unknown falls back to the
	 * default, so a hand-edited URL never reaches the SQL.
	 *
	 * @param array<string,mixed> $raw Unslashed values for s, by, dir and n.
	 * @return array{s:string,by:string,dir:string,n:int}
	 */
	public static function normalize( array $raw ): array {
		$by  = isset( $raw['by'] ) ? sanitize_key( (string) $raw['by'] ) : '';
		$dir = isset( $raw['dir'] ) ? strtolower( (string) $raw['dir'] ) : '';
		$s   = isset( $raw['s'] ) ? trim( sanitize_text_field( (string) $raw['s'] ) ) : '';

		return [
			's'   => function_exists( 'mb_substr' ) ? mb_substr( $s, 0, 100 ) : substr( $s, 0, 100 ),
			'by'  => isset( self::SORT_SQL[ $by ] ) ? $by : self::DEFAULT_SORT,
			'dir' => 'asc' === $dir ? 'asc' : 'desc',
			'n'   => max( 1, (int) ( $raw['n'] ?? 1 ) ),
		];
	}

	/**
	 * The report's params from the request. Like the other view params they
	 * are honored only with a valid view nonce.
	 *
	 * @return array{s:string,by:string,dir:string,n:int}
	 */
	public static function request(): array {
		if ( ! Dashboard::request_is_trusted() ) {
			return self::normalize( [] );
		}
		$raw = [];
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- nonce verified in Dashboard::request_is_trusted()
		foreach ( [ 's', 'by', 'dir', 'n' ] as $key ) {
			if ( isset( $_GET[ 'pg_' . $key ] ) && is_scalar( $_GET[ 'pg_' . $key ] ) ) {
				$raw[ $key ] = sanitize_text_field( wp_unslash( (string) $_GET[ 'pg_' . $key ] ) );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		return self::normalize( $raw );
	}

	/**
	 * Link to the report for a range, carrying the report's own params.
	 *
	 * @param array<string,mixed> $view Normalized params, plus any overrides.
	 */
	public static function url( string $preset, string $start, string $end, string $compare_param, array $view = [] ): string {
		$args = [
			'page' => 'vireo-analytics',
			'tab'  => 'pages',
			'_vrn' => Dashboard::view_nonce(),
		];
		if ( 'custom' === $preset ) {
			$args['period']     = 'custom';
			$args['start_date'] = $start;
			$args['end_date']   = $end;
		} else {
			$args['period'] = $preset;
		}
		if ( '' !== $compare_param ) {
			$args['compare'] = $compare_param;
		}

		$defaults = self::normalize( [] );
		foreach ( [ 's', 'by', 'dir', 'n' ] as $key ) {
			if ( isset( $view[ $key ] ) && $view[ $key ] !== $defaults[ $key ] ) {
				$args[ 'pg_' . $key ] = $view[ $key ];
			}
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * One page of the report.
	 *
	 * @param array{s:string,by:string,dir:string,n:int} $view Normalized params.
	 * @return array{rows:array<int,array<string,mixed>>,total:int,pages:int,n:int}
	 */
	public static function query( string $start, string $end, array $view, int $per_page = self::PER_PAGE ): array {
		global $wpdb;

		$stats = Sites::table( 'post_stats' );
		$paths = Sites::table( 'paths' );
		$time  = Sites::table( 'page_time' );

		$has_time = Aggregator::table_ready( $time );
		$by       = ( 'avg_time' === $view['by'] && ! $has_time ) ? self::DEFAULT_SORT : $view['by'];
		$dir_sql  = 'asc' === $view['dir'] ? 'ASC' : 'DESC';

		// Placeholders are bound in the order they appear in $inner: the two
		// tables, then the time subquery if there is one, then the range.
		$args = [ $stats, $paths ];
		if ( $has_time ) {
			$time_sql = 'LEFT JOIN ( SELECT path_id, SUM(seconds) / NULLIF(SUM(samples), 0) AS avg_time
			                         FROM %i WHERE date BETWEEN %s AND %s GROUP BY path_id ) t ON t.path_id = s.path_id';
			$time_col = 'MAX(t.avg_time) AS avg_time';
			array_push( $args, $time, $start, $end );
		} else {
			$time_sql = '';
			$time_col = 'NULL AS avg_time';
		}
		array_push( $args, $start, $end );

		$having = '';
		if ( '' !== $view['s'] ) {
			$having   = ' HAVING p.path LIKE %s';
			$args[]   = '%' . $wpdb->esc_like( $view['s'] ) . '%';
			$post_ids = self::post_ids_by_title( $view['s'] );
			if ( [] !== $post_ids ) {
				$having .= ' OR MAX(s.post_id) IN (' . implode( ',', array_fill( 0, count( $post_ids ), '%d' ) ) . ')';
				$args    = array_merge( $args, $post_ids );
			}
		}

		// The fragments are literals and every value is bound in $args. The sort
		// column and direction come from a whitelist.
		$inner = "SELECT p.path, MAX(s.post_id) AS post_id,
		                 SUM(s.pageviews) AS pageviews,
		                 SUM(s.visitors) AS visitors,
		                 {$time_col}
		          FROM %i s
		          JOIN %i p ON p.id = s.path_id
		          {$time_sql}
		          WHERE s.date BETWEEN %s AND %s
		          GROUP BY p.path{$having}";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,PluginCheck.Security.DirectDB.UnescapedDBParameter -- literals only, values bound
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM ( {$inner} ) c", $args ) );

		$last = max( 1, (int) ceil( $total / max( 1, $per_page ) ) );
		$n    = min( $view['n'], $last );

		$order = self::SORT_SQL[ $by ];
		$sort  = 'avg_time' === $by
			? "(avg_time IS NULL) ASC, avg_time {$dir_sql}"
			: "{$order} {$dir_sql}";

		$args[] = $per_page;
		$args[] = ( $n - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,PluginCheck.Security.DirectDB.UnescapedDBParameter -- literals only, values bound
		$found = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM ( {$inner} ) r ORDER BY {$sort}, path ASC LIMIT %d OFFSET %d", $args ), ARRAY_A );

		$rows = [];
		foreach ( (array) $found as $r ) {
			$rows[] = [
				'path'      => (string) $r['path'],
				'post_id'   => (int) $r['post_id'],
				'pageviews' => (int) $r['pageviews'],
				'visitors'  => (int) $r['visitors'],
				'avg_time'  => null === $r['avg_time'] ? null : (int) round( (float) $r['avg_time'] ),
			];
		}

		return [
			'rows'  => $rows,
			'total' => $total,
			'pages' => $last,
			'n'     => $n,
		];
	}

	/**
	 * Add a title and permalink to each row. Posts are primed in one query;
	 * a connected site's post IDs mean nothing here, so it keeps its paths.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows from query().
	 * @return array<int,array<string,mixed>>
	 */
	public static function with_titles( array $rows ): array {
		$ids = array_values( array_filter( array_unique( array_map( 'intval', array_column( $rows, 'post_id' ) ) ) ) );
		if ( [] !== $ids && ! Sites::is_connected() ) {
			_prime_post_caches( $ids, false, false );
		}

		foreach ( $rows as $i => $row ) {
			$title = '';
			$url   = '';
			if ( $row['post_id'] > 0 && ! Sites::is_connected() ) {
				[ 'title' => $title, 'url' => $url ] = Dashboard::public_post( (int) $row['post_id'] );
			}
			$rows[ $i ]['title'] = Dashboard::page_label( $title, (string) $row['path'] );
			$rows[ $i ]['url']   = $url;
		}
		return $rows;
	}

	/**
	 * IDs of published posts whose title contains the search text, so a title
	 * can be searched although the stats only store paths.
	 *
	 * @return int[]
	 */
	private static function post_ids_by_title( string $search ): array {
		global $wpdb;
		if ( Sites::is_connected() ) {
			return [];
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- admin report, one bounded query
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_title LIKE %s LIMIT %d",
				'%' . $wpdb->esc_like( $search ) . '%',
				self::TITLE_MATCH_CAP
			)
		);
		return array_map( 'intval', (array) $ids );
	}
}
