<?php
/**
 * Per-referrer drill-down view.
 *
 * Route: /wp-admin/admin.php?page=vireo-analytics&view=referrer&url=<host>
 *
 * Mirrors Post_Detail. Shows a daily chart of traffic sent by this referrer
 * plus the posts it sent people to, read from va_post_referrer_stats.
 */

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

final class Referrer_Detail {

	private const CACHE_GROUP = 'vireo_ref_';
	private const CACHE_TTL   = 30;

	/** Default window mirrors Post_Detail for consistency. */
	private const DEFAULT_DAYS = 90;

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// No hooks needed — Dashboard::render_overview_page delegates here
		// when `view=referrer` is present.
	}

	public function render(): void {
		if ( ! current_user_can( 'view_vireo_analytics' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- capability-gated detail view reached only via the nonce-verified view router; sanitized via vireo_canonical_host below
		$raw = isset( $_GET['url'] ) ? (string) wp_unslash( $_GET['url'] ) : '';
		$host = vireo_canonical_host( $raw );
		if ( '' === $host ) {
			// Strip everything except what canonical_host would accept — keeps
			// the URL bar honest when someone hand-types a bad value.
			$host = sanitize_text_field( $raw );
		}

		if ( '' === $host ) {
			echo '<div class="wrap va-wrap"><div class="va-root"><p>' .
				esc_html__( 'Referrer not specified.', 'vireo-analytics' ) . '</p></div></div>';
			return;
		}

		[ $start, $end, $preset ] = $this->resolve_range_from_request();

		$daily      = $this->get_daily_series( $host, $start, $end );
		$period     = $this->get_period_totals( $host, $start, $end );
		$top_pages  = $this->get_top_pages_from_referrer( $host, $start, $end, 10 );

		$va_host       = $host;
		$va_back_url   = admin_url( 'admin.php?page=vireo-analytics' );
		$va_page_title = sprintf(
			/* translators: %s: referrer hostname */
			__( 'Referrer · %s', 'vireo-analytics' ),
			vireo_referrer_label( $host )
		);

		include VIREO_DIR . 'templates/dashboard/referrer-detail.php';
	}

	/**
	 * @return array{0:string,1:string,2:string}
	 */
	private function resolve_range_from_request(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- capability-gated detail view reached only via the nonce-verified view router
		$start = isset( $_GET['start_date'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['start_date'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- capability-gated detail view reached only via the nonce-verified view router
		$end   = isset( $_GET['end_date'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['end_date'] ) ) : '';

		// A preset, as the Overview's links now carry, so the detail opens on
		// the range the reader was looking at.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- capability-gated detail view reached only via the nonce-verified view router
		$period = isset( $_GET['period'] ) ? sanitize_key( (string) $_GET['period'] ) : '';
		$range  = '' !== $period && 'custom' !== $period ? Dashboard::preset_range( $period ) : null;
		if ( null !== $range ) {
			return [ $range['start'], $range['end'], $period ];
		}

		$valid = static fn( string $s ): bool => (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $s );
		if ( $valid( $start ) && $valid( $end ) ) {
			if ( strcmp( $end, $start ) < 0 ) {
				[ $start, $end ] = [ $end, $start ];
			}
			return [ $start, $end, 'custom' ];
		}

		$tz    = wp_timezone();
		$today = ( new \DateTimeImmutable( 'now', $tz ) )->setTime( 0, 0, 0 );
		$end   = $today->format( 'Y-m-d' );
		$start = $today->modify( '-' . ( self::DEFAULT_DAYS - 1 ) . ' days' )->format( 'Y-m-d' );
		return [ $start, $end, 'last_90' ];
	}

	/**
	 * @return array{pageviews:int,visitors:int}
	 */
	public function get_period_totals( string $host, string $start, string $end ): array {
		$cache_key = self::CACHE_GROUP . 'per_' . md5( Sites::current() . '|' . $host . '|' . $start . '|' . $end );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$ids = $this->url_ids_for_host( $host );
		if ( empty( $ids ) ) {
			$result = [ 'pageviews' => 0, 'visitors' => 0 ];
			set_transient( $cache_key, $result, self::CACHE_TTL );
			return $result;
		}

		global $wpdb;
		$stats        = Sites::table( 'referrer_stats' );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is %d-only, generated above; sniffer can't trace %i + spread
		$sql = $wpdb->prepare(
			"SELECT COALESCE(SUM(pageviews),0) AS pageviews,
			        COALESCE(SUM(visitors),0)  AS visitors
			 FROM %i
			 WHERE date BETWEEN %s AND %s
			 AND url_id IN ({$placeholders})",
			$stats,
			$start,
			$end,
			...$ids
		);
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- $sql is the output of $wpdb->prepare() above
		$row = $wpdb->get_row( $sql, ARRAY_A );

		$result = [
			'pageviews' => (int) ( $row['pageviews'] ?? 0 ),
			'visitors'  => (int) ( $row['visitors'] ?? 0 ),
		];

		set_transient( $cache_key, $result, self::CACHE_TTL );
		return $result;
	}

	/**
	 * @return array<int, array{date:string,pageviews:int,visitors:int}>
	 */
	public function get_daily_series( string $host, string $start, string $end ): array {
		$cache_key = self::CACHE_GROUP . 'daily_' . md5( Sites::current() . '|' . $host . '|' . $start . '|' . $end );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$ids = $this->url_ids_for_host( $host );
		$by_date = [];

		if ( ! empty( $ids ) ) {
			global $wpdb;
			$stats        = Sites::table( 'referrer_stats' );
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

			// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is %d-only, generated above; sniffer can't trace %i + spread
			$sql = $wpdb->prepare(
				"SELECT date,
				        SUM(pageviews) AS pageviews,
				        SUM(visitors)  AS visitors
				 FROM %i
				 WHERE date BETWEEN %s AND %s
				 AND url_id IN ({$placeholders})
				 GROUP BY date
				 ORDER BY date ASC",
				$stats,
				$start,
				$end,
				...$ids
			);
			// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- $sql is the output of $wpdb->prepare() above
			$rows = $wpdb->get_results( $sql, ARRAY_A );
			foreach ( (array) $rows as $r ) {
				$by_date[ $r['date'] ] = [
					'date'      => (string) $r['date'],
					'pageviews' => (int) $r['pageviews'],
					'visitors'  => (int) $r['visitors'],
				];
			}
		}

		$series = [];
		try {
			$cursor = new \DateTimeImmutable( $start );
			$stop   = new \DateTimeImmutable( $end );
		} catch ( \Exception $e ) {
			return [];
		}
		while ( $cursor <= $stop ) {
			$key      = $cursor->format( 'Y-m-d' );
			$series[] = $by_date[ $key ] ?? [ 'date' => $key, 'pageviews' => 0, 'visitors' => 0 ];
			$cursor   = $cursor->modify( '+1 day' );
			if ( count( $series ) > 400 ) {
				break;
			}
		}

		set_transient( $cache_key, $series, self::CACHE_TTL );
		return $series;
	}

	/**
	 * The posts this referrer sent people to, from the per-post referrer
	 * rollup. Only posts are recorded there (not archives or the home page),
	 * and only from the day it started being written, so the card says so.
	 *
	 * @return array<int, array{post_id:int,path:string,title:string,url:string,pageviews:int}>
	 */
	public function get_top_pages_from_referrer( string $host, string $start, string $end, int $limit = 10 ): array {
		$override = apply_filters( 'vireo_referrer_top_pages', null, $host, $start, $end, $limit );
		if ( is_array( $override ) ) {
			return $override;
		}

		// A connected site's post ids aren't posts on this WordPress.
		if ( Sites::is_connected() ) {
			return [];
		}

		$ids = $this->url_ids_for_host( $host );
		if ( empty( $ids ) ) {
			return [];
		}

		$cache_key = self::CACHE_GROUP . 'pages_' . md5( Sites::current() . '|' . $host . '|' . $start . '|' . $end . '|' . $limit );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is %d-only, generated above
		$sql = $wpdb->prepare(
			"SELECT post_id, SUM(pageviews) AS pageviews
			 FROM %i
			 WHERE date BETWEEN %s AND %s
			 AND url_id IN ({$placeholders})
			 GROUP BY post_id
			 ORDER BY pageviews DESC
			 LIMIT %d",
			Sites::table( 'post_referrer_stats' ),
			$start,
			$end,
			...array_merge( $ids, [ max( 1, min( 50, $limit ) ) ] )
		);
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- $sql is the output of $wpdb->prepare() above
		$rows = (array) $wpdb->get_results( $sql, ARRAY_A );

		$out = [];
		foreach ( $rows as $r ) {
			$post_id = (int) $r['post_id'];
			$url     = $post_id > 0 ? (string) get_permalink( $post_id ) : '';
			$out[]   = [
				'post_id'   => $post_id,
				'path'      => '' !== $url ? (string) wp_parse_url( $url, PHP_URL_PATH ) : '',
				'title'     => $post_id > 0 ? (string) get_the_title( $post_id ) : '',
				'url'       => $url,
				'pageviews' => (int) $r['pageviews'],
			];
		}

		set_transient( $cache_key, $out, self::CACHE_TTL );
		return $out;
	}

	/**
	 * Resolve every url_id in va_referrer_urls whose canonical host matches
	 * the requested host. This collapses `www.example.com`, `example.com`,
	 * and `https://example.com/path` into one set.
	 *
	 * @return int[]
	 */
	private function url_ids_for_host( string $host ): array {
		$host = vireo_canonical_host( $host );
		if ( '' === $host ) {
			return [];
		}

		$cache_key = self::CACHE_GROUP . 'ids_' . md5( Sites::current() . '|' . $host );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$urls = Sites::table( 'referrer_urls' );

		// va_referrer_urls.url stores the original referrer (sometimes a bare
		// host, sometimes a full URL). Match both literal-host rows and URLs
		// whose host component canonicalises to $host via a LIKE scan. A LIKE
		// with leading wildcard can't use an index but the table is small
		// (one row per distinct referrer) and we cache the result per host.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- transient-cached above
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, url FROM %i WHERE url = %s OR url = %s OR url LIKE %s OR url LIKE %s OR url LIKE %s OR url LIKE %s',
				$urls,
				$host,
				'www.' . $host,
				'http://' . $wpdb->esc_like( $host ) . '%',
				'https://' . $wpdb->esc_like( $host ) . '%',
				'http://www.' . $wpdb->esc_like( $host ) . '%',
				'https://www.' . $wpdb->esc_like( $host ) . '%'
			),
			ARRAY_A
		);

		$ids = [];
		foreach ( (array) $rows as $r ) {
			// Double-check via vireo_canonical_host so a row like "example.com.evil.com"
			// never matches for $host = "example.com".
			$stored_canon = vireo_canonical_host( (string) $r['url'] );
			if ( $stored_canon === $host ) {
				$ids[] = (int) $r['id'];
			}
		}

		set_transient( $cache_key, $ids, self::CACHE_TTL );
		return $ids;
	}
}
