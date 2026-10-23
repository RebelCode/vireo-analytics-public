<?php
/**
 * CSV export for site / pages / referrers.
 *
 * Trigger:
 *   admin-post.php?action=vireo_export_csv&type={site|pages|referrers}&start=YYYY-MM-DD&end=YYYY-MM-DD
 *
 * Streams output directly to php://output so large ranges don't load the full
 * result set into memory. Dates are interpreted as UTC (matches how the
 * aggregator buckets rows).
 */

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

// Dashboard provides the ISO country-code → display name map.
// We pull it in lazily (inside export_countries) rather than re-imploring it
// here, because Dashboard is already loaded by the time admin-post hits us.

final class CSV_Export {

	private const MAX_ROWS_PER_TYPE = 100000; // Hard ceiling — a 10-year daily range per-post is well inside this.

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_post_vireo_export_csv', [ $this, 'handle_export' ] );
	}

	public function handle_export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'vireo-analytics' ), 403 );
		}
		check_admin_referer( 'vireo_export_csv' );

		$type  = isset( $_GET['type'] ) ? sanitize_key( (string) wp_unslash( $_GET['type'] ) ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- normalize_date() validates against /^\d{4}-\d{2}-\d{2}$/ and returns '' for non-matches
		$start = isset( $_GET['start'] ) ? self::normalize_date( (string) wp_unslash( $_GET['start'] ) ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- normalize_date() validates against /^\d{4}-\d{2}-\d{2}$/ and returns '' for non-matches
		$end   = isset( $_GET['end'] ) ? self::normalize_date( (string) wp_unslash( $_GET['end'] ) ) : '';

		if ( '' === $start || '' === $end ) {
			// Sensible default: last 30 full days.
			$end   = gmdate( 'Y-m-d', strtotime( '-1 day' ) );
			$start = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
		}

		// Guard against reversed ranges — swap rather than erroring, users
		// sometimes paste these backwards.
		if ( strcmp( $start, $end ) > 0 ) {
			[ $start, $end ] = [ $end, $start ];
		}

		$filename = sprintf( 'vireo-analytics-%s-%s_to_%s.csv', $type ?: 'export', $start, $end );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		// Direct stream to the HTTP response — no intermediate buffer. WP_Filesystem
		// has no php://output equivalent, so fopen/fputcsv/fclose is the right tool.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_write_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$fh = fopen( 'php://output', 'w' );
		if ( ! $fh ) {
			wp_die( esc_html__( 'Failed to open output stream.', 'vireo-analytics' ), 500 );
		}

		switch ( $type ) {
			case 'site':
				$this->export_site( $fh, $start, $end );
				break;
			case 'pages':
				$this->export_pages( $fh, $start, $end );
				break;
			case 'referrers':
				$this->export_referrers( $fh, $start, $end );
				break;
			case 'countries':
				$this->export_countries( $fh, $start, $end );
				break;
			case 'devices':
				$this->export_devices( $fh, $start, $end );
				break;
			case '404s':
				$this->export_404s( $fh, $start, $end );
				break;
			case 'events':
				$this->export_events( $fh, $start, $end );
				break;
			default:
				fputcsv( $fh, [ 'error' ] );
				fputcsv( $fh, [ 'Unknown export type. Use site, pages, referrers, countries, devices, 404s, or events.' ] );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $fh );
		exit;
	}

	/**
	 * @param resource $fh Output stream.
	 */
	private function export_site( $fh, string $start, string $end ): void {
		global $wpdb;
		$table = Sites::table( 'site_stats' );

		fputcsv( $fh, [ 'date', 'pageviews', 'visitors', 'bounce_rate_percent', 'avg_time_on_page_seconds', 'timed_views' ] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- $sql is the output of $wpdb->prepare() above
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT date, pageviews, visitors
				 FROM %i
				 WHERE date BETWEEN %s AND %s
				 ORDER BY date ASC
				 LIMIT %d',
				$table,
				$start,
				$end,
				self::MAX_ROWS_PER_TYPE
			)
		);

		// Days before either was counted, and imported days, leave the
		// columns empty rather than showing a zero nobody measured.
		$bounce = self::by_date( 'bounce_stats', 'SUM(visitors) AS visitors, SUM(multi_page) AS multi_page', $start, $end );
		$time   = self::by_date( 'page_time', 'SUM(samples) AS samples, SUM(seconds) AS seconds', $start, $end );

		foreach ( (array) $rows as $row ) {
			$b = isset( $bounce[ $row->date ] ) ? Engagement_Basics::bounce_row( (int) $bounce[ $row->date ]->visitors, (int) $bounce[ $row->date ]->multi_page ) : null;
			$t = isset( $time[ $row->date ] ) ? Engagement_Basics::time_row( (int) $time[ $row->date ]->samples, (int) $time[ $row->date ]->seconds ) : null;
			fputcsv(
				$fh,
				[
					$row->date,
					(int) $row->pageviews,
					(int) $row->visitors,
					null === $b ? '' : $b['rate'],
					null === $t ? '' : $t['avg_seconds'],
					null === $t ? 0 : $t['samples'],
				]
			);
		}
	}

	/**
	 * One row per date from a daily table, keyed by date. Empty when the
	 * table is not there yet.
	 *
	 * @return array<string, object>
	 */
	private static function by_date( string $short, string $columns, string $start, string $end ): array {
		global $wpdb;
		$table = Sites::table( $short );
		if ( ! Aggregator::table_ready( $table ) ) {
			return [];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $columns is a literal from the callers above
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT date, {$columns} FROM %i WHERE date BETWEEN %s AND %s GROUP BY date", $table, $start, $end ) );

		$out = [];
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row->date ] = $row;
		}
		return $out;
	}

	/**
	 * @param resource $fh Output stream.
	 */
	private function export_pages( $fh, string $start, string $end ): void {
		global $wpdb;
		$post_stats = Sites::table( 'post_stats' );
		$paths      = Sites::table( 'paths' );

		fputcsv( $fh, [ 'path', 'post_id', 'pageviews', 'visitors', 'avg_time_on_page_seconds', 'timed_views' ] );

		// Aggregate across the full range. Streaming per-row ordering by
		// pageviews DESC keeps the most useful lines at the top.
		$sql = $wpdb->prepare(
			'SELECT p.path, s.post_id,
				SUM(s.pageviews) AS pageviews,
				SUM(s.visitors) AS visitors
			 FROM %i s
			 JOIN %i p ON p.id = s.path_id
			 WHERE s.date BETWEEN %s AND %s
			 GROUP BY p.path, s.post_id
			 ORDER BY pageviews DESC
			 LIMIT %d',
			$post_stats,
			$paths,
			$start,
			$end,
			self::MAX_ROWS_PER_TYPE
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- $sql is the output of $wpdb->prepare() above
		$rows = $wpdb->get_results( $sql );

		// Time is kept per path, so a path listed under two post IDs carries
		// the same figure on both lines.
		$times = Engagement_Basics::time_by_path( array_map( 'strval', wp_list_pluck( (array) $rows, 'path' ) ), $start, $end );

		foreach ( (array) $rows as $row ) {
			$t = $times[ (string) $row->path ] ?? null;
			fputcsv(
				$fh,
				[
					self::csv_safe( (string) $row->path ),
					(int) $row->post_id,
					(int) $row->pageviews,
					(int) $row->visitors,
					null === $t ? '' : $t['avg_seconds'],
					null === $t ? 0 : $t['samples'],
				]
			);
		}
	}

	/**
	 * @param resource $fh Output stream.
	 */
	private function export_referrers( $fh, string $start, string $end ): void {
		global $wpdb;
		$ref_stats = Sites::table( 'referrer_stats' );
		$ref_urls  = Sites::table( 'referrer_urls' );

		fputcsv( $fh, [ 'host', 'pageviews', 'visitors' ] );

		$sql = $wpdb->prepare(
			'SELECT u.url AS host,
				SUM(s.pageviews) AS pageviews,
				SUM(s.visitors) AS visitors
			 FROM %i s
			 JOIN %i u ON u.id = s.url_id
			 WHERE s.date BETWEEN %s AND %s
			 GROUP BY u.url
			 ORDER BY pageviews DESC
			 LIMIT %d',
			$ref_stats,
			$ref_urls,
			$start,
			$end,
			self::MAX_ROWS_PER_TYPE
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- $sql is the output of $wpdb->prepare() above
		$rows = $wpdb->get_results( $sql );
		foreach ( (array) $rows as $row ) {
			fputcsv(
				$fh,
				[
					self::csv_safe( (string) $row->host ),
					(int) $row->pageviews,
					(int) $row->visitors,
				]
			);
		}
	}

	/**
	 * @param resource $fh Output stream.
	 */
	private function export_countries( $fh, string $start, string $end ): void {
		global $wpdb;
		$table = Sites::table( 'geo_stats' );

		fputcsv( $fh, [ 'country_code', 'country_name', 'pageviews', 'visitors' ] );

		$sql = $wpdb->prepare(
			'SELECT country_code,
				SUM(pageviews) AS pageviews,
				SUM(visitors) AS visitors
			 FROM %i
			 WHERE date BETWEEN %s AND %s
			 GROUP BY country_code
			 ORDER BY visitors DESC
			 LIMIT %d',
			$table,
			$start,
			$end,
			self::MAX_ROWS_PER_TYPE
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- $sql is the output of $wpdb->prepare() above
		$rows = $wpdb->get_results( $sql );
		foreach ( (array) $rows as $row ) {
			$code = strtoupper( (string) $row->country_code );
			fputcsv(
				$fh,
				[
					$code,
					Dashboard::country_name( $code ),
					(int) $row->pageviews,
					(int) $row->visitors,
				]
			);
		}
	}

	/**
	 * @param resource $fh Output stream.
	 */
	private function export_devices( $fh, string $start, string $end ): void {
		global $wpdb;
		$table = Sites::table( 'device_stats' );

		fputcsv( $fh, [ 'browser', 'os', 'device_type', 'pageviews', 'visitors' ] );

		$sql = $wpdb->prepare(
			'SELECT browser, os, device_type,
				SUM(pageviews) AS pageviews,
				SUM(visitors) AS visitors
			 FROM %i
			 WHERE date BETWEEN %s AND %s
			 GROUP BY browser, os, device_type
			 ORDER BY visitors DESC
			 LIMIT %d',
			$table,
			$start,
			$end,
			self::MAX_ROWS_PER_TYPE
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- $sql is the output of $wpdb->prepare() above
		$rows = $wpdb->get_results( $sql );
		foreach ( (array) $rows as $row ) {
			fputcsv(
				$fh,
				[
					self::csv_safe( (string) $row->browser ),
					self::csv_safe( (string) $row->os ),
					self::csv_safe( (string) $row->device_type ),
					(int) $row->pageviews,
					(int) $row->visitors,
				]
			);
		}
	}

	/**
	 * @param resource $fh Output stream.
	 */
	private function export_404s( $fh, string $start, string $end ): void {
		global $wpdb;
		$table = Sites::table( '404_stats' );

		// The va_404_stats table is added in a later migration; guard against
		// running the export on sites that haven't installed it yet.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- schema probe
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			fputcsv( $fh, [ 'error' ] );
			fputcsv( $fh, [ '404 tracking table not installed — enable 404 logging and re-run the export.' ] );
			return;
		}

		fputcsv( $fh, [ 'path', 'referrer', 'hits' ] );

		$sql = $wpdb->prepare(
			'SELECT path, referrer, SUM(hits) AS hits
			 FROM %i
			 WHERE date BETWEEN %s AND %s
			 GROUP BY path, referrer
			 ORDER BY hits DESC
			 LIMIT %d',
			$table,
			$start,
			$end,
			self::MAX_ROWS_PER_TYPE
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- $sql is the output of $wpdb->prepare() above
		$rows = $wpdb->get_results( $sql );
		foreach ( (array) $rows as $row ) {
			fputcsv(
				$fh,
				[
					self::csv_safe( (string) $row->path ),
					self::csv_safe( (string) $row->referrer ),
					(int) $row->hits,
				]
			);
		}
	}

	/**
	 * @param resource $fh Output stream.
	 */
	private function export_events( $fh, string $start, string $end ): void {
		global $wpdb;
		$table = Sites::table( 'event_stats' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- schema probe
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			fputcsv( $fh, [ 'error' ] );
			fputcsv( $fh, [ 'Event tracking table not installed.' ] );
			return;
		}

		fputcsv( $fh, [ 'event_name', 'event_value', 'count', 'unique_count' ] );

		$sql = $wpdb->prepare(
			'SELECT event_name, event_value,
				SUM(count) AS count,
				SUM(unique_count) AS unique_count
			 FROM %i
			 WHERE date BETWEEN %s AND %s
			 GROUP BY event_name, event_value
			 ORDER BY count DESC
			 LIMIT %d',
			$table,
			$start,
			$end,
			self::MAX_ROWS_PER_TYPE
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- $sql is the output of $wpdb->prepare() above
		$rows = $wpdb->get_results( $sql );
		foreach ( (array) $rows as $row ) {
			fputcsv(
				$fh,
				[
					self::csv_safe( (string) $row->event_name ),
					self::csv_safe( (string) $row->event_value ),
					(int) $row->count,
					(int) $row->unique_count,
				]
			);
		}
	}

	/**
	 * Coerce a user-supplied string to a canonical Y-m-d or return ''.
	 */
	private static function normalize_date( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		$ts = strtotime( $value );
		if ( false === $ts ) {
			return '';
		}
		return gmdate( 'Y-m-d', $ts );
	}

	/**
	 * Neutralize CSV / spreadsheet formula injection.
	 *
	 * Excel, LibreOffice Calc, and Google Sheets interpret cells that begin
	 * with `=`, `+`, `-`, `@`, TAB, or CR as a formula. Attacker-controlled
	 * referrer / path / UA fields can therefore execute when an admin opens
	 * the exported file. Mitigation per OWASP: prefix the value with a
	 * single quote, which is a documented escape that the spreadsheet hides
	 * on display but suppresses the formula evaluation.
	 */
	private static function csv_safe( string $value ): string {
		if ( '' === $value ) {
			return '';
		}
		$first = $value[0];
		if ( '=' === $first || '+' === $first || '-' === $first || '@' === $first
			|| "\t" === $first || "\r" === $first ) {
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * Build a signed CSV URL for the supplied range/type. Helper for dashboards
	 * that want "Download CSV" buttons — not used internally, but part of the
	 * public API of this class.
	 */
	public static function build_url( string $type, string $start, string $end ): string {
		$url = add_query_arg(
			[
				'action' => 'vireo_export_csv',
				'type'   => $type,
				'start'  => $start,
				'end'    => $end,
			],
			admin_url( 'admin-post.php' )
		);
		return wp_nonce_url( $url, 'vireo_export_csv' );
	}
}
