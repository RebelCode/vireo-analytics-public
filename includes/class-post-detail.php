<?php
/**
 * Per-post stats drill-down.
 *
 * Route: /wp-admin/admin.php?page=vireo-analytics&view=post&post_id=N
 *
 * Hooks into the same `vireo-analytics` admin page as the Overview, and takes
 * over rendering when the `view=post` param is present. Keeps the menu tree
 * flat (no separate submenu entry) — this page is reached by clicking a
 * title in the Top Pages card.
 */

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

final class Post_Detail {

	private const CACHE_GROUP = 'vireo_post_';
	private const CACHE_TTL   = 30;

	/** How many days of daily chart data we show by default. */
	private const DEFAULT_DAYS = 90;

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// No hooks needed — Dashboard::render_overview_page delegates to us
		// when the `view=post` param is present. Keeping a constructor for
		// parity with other singletons.
	}

	public function render(): void {
		if ( ! current_user_can( 'view_vireo_analytics' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- capability-gated detail view reached only via the nonce-verified view router
		$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
		$post    = $post_id > 0 ? get_post( $post_id ) : null;

		// Any dashboard viewer can type any ID into the URL. A post nobody
		// could read on the site, and this viewer could not open in the
		// editor either, is not theirs to see here, title and traffic included.
		if ( ! $post instanceof \WP_Post || ! self::can_view( $post, $post_id ) ) {
			echo '<div class="wrap va-wrap"><div class="va-root"><p>' .
				esc_html__( 'Post not found.', 'vireo-analytics' ) . '</p></div></div>';
			return;
		}

		// Date range — reuse same resolution logic as the overview, defaulting
		// to last 90 days for the chart.
		[ $start, $end, $preset ] = $this->resolve_range_from_request();

		$lifetime   = $this->get_lifetime_stats( $post_id );
		$daily      = $this->get_daily_series( $post_id, $start, $end );
		$period     = $this->get_period_totals( $post_id, $start, $end );
		$referrers  = $this->get_top_referrers( $post_id, $start, $end, 10 );

		$va_comments     = Comment_Stats::counts( [ $post_id ], $start, $end )[ $post_id ] ?? 0;
		$va_comment_rate = Comment_Stats::rate( $va_comments, (int) $period['pageviews'] );

		// When the per-post referrer table is empty for this post's history
		// AND the table was installed later than the post's first-seen date,
		// mark this as "tracking just started" so the template can show a
		// notice instead of an empty card.
		$referrer_info = $this->get_referrer_tracking_info( $post_id );

		// Template locals.
		$va_post           = $post;
		$va_post_url       = (string) get_permalink( $post );
		$va_post_title     = (string) get_the_title( $post );
		$va_back_url       = admin_url( 'admin.php?page=vireo-analytics' );
		$va_page_title     = sprintf(
			/* translators: %s: post title */
			__( 'Post stats · %s', 'vireo-analytics' ),
			$va_post_title
		);
		$va_referrer_info  = $referrer_info;

		// What people typed into Google before landing here. Only when Search
		// Console is connected; otherwise the card offers to connect it.
		$va_gsc_on       = class_exists( Search_Console::class ) && Search_Console::configured();
		$va_post_queries = $va_gsc_on
			? Search_Console::page_queries( $va_post_url, $start, $end, 15 )
			: [];

		// Google's lab test of this post, from the cache only.
		$va_psi = PageSpeed::view( 'post', $post_id );

		include VIREO_DIR . 'templates/dashboard/post-detail.php';
	}

	/**
	 * May the current viewer see this post's stats screen? Yes when the post
	 * is publicly viewable (published, of a public type; a password is fine)
	 * or when the viewer could open it in the editor.
	 */
	public static function can_view( \WP_Post $post, int $post_id ): bool {
		return is_post_publicly_viewable( $post ) || current_user_can( 'read_post', $post_id );
	}

	/**
	 * Default range: last 90 days. Custom range honored if present.
	 *
	 * @return array{0:string,1:string,2:string} [start, end, preset-key]
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
	 * @return array{pageviews:int,visitors:int,first_seen:?string,best_day:?array{date:string,pageviews:int}}
	 */
	public function get_lifetime_stats( int $post_id ): array {
		$cache_key = self::CACHE_GROUP . 'life_' . $post_id;
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$stats = Sites::table( 'post_stats' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- transient-cached above the direct query
		$totals = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(pageviews),0) AS pageviews,
				        COALESCE(SUM(visitors),0)  AS visitors,
				        MIN(date) AS first_seen
				 FROM %i
				 WHERE post_id = %d',
				$stats,
				$post_id
			),
			ARRAY_A
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- transient-cached above the direct query
		$best = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT date, SUM(pageviews) AS pageviews
				 FROM %i
				 WHERE post_id = %d
				 GROUP BY date
				 ORDER BY pageviews DESC
				 LIMIT 1',
				$stats,
				$post_id
			),
			ARRAY_A
		);

		$result = [
			'pageviews'  => (int) ( $totals['pageviews'] ?? 0 ),
			'visitors'   => (int) ( $totals['visitors'] ?? 0 ),
			'first_seen' => ! empty( $totals['first_seen'] ) ? (string) $totals['first_seen'] : null,
			'best_day'   => $best && ! empty( $best['date'] ) ? [
				'date'      => (string) $best['date'],
				'pageviews' => (int) $best['pageviews'],
			] : null,
		];

		set_transient( $cache_key, $result, self::CACHE_TTL );
		return $result;
	}

	/**
	 * @return array{pageviews:int,visitors:int}
	 */
	public function get_period_totals( int $post_id, string $start, string $end ): array {
		$cache_key = self::CACHE_GROUP . 'per_' . md5( $post_id . '|' . $start . '|' . $end );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$stats = Sites::table( 'post_stats' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- transient-cached above the direct query
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(pageviews),0) AS pageviews,
				        COALESCE(SUM(visitors),0)  AS visitors
				 FROM %i
				 WHERE post_id = %d AND date BETWEEN %s AND %s',
				$stats,
				$post_id,
				$start,
				$end
			),
			ARRAY_A
		);

		$result = [
			'pageviews' => (int) ( $row['pageviews'] ?? 0 ),
			'visitors'  => (int) ( $row['visitors'] ?? 0 ),
		];

		set_transient( $cache_key, $result, self::CACHE_TTL );
		return $result;
	}

	/**
	 * Daily series for the chart, zero-filling missing dates.
	 *
	 * @return array<int, array{date:string,pageviews:int,visitors:int}>
	 */
	public function get_daily_series( int $post_id, string $start, string $end ): array {
		$cache_key = self::CACHE_GROUP . 'daily_' . md5( $post_id . '|' . $start . '|' . $end );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$stats = Sites::table( 'post_stats' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- transient-cached above the direct query
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT date,
				        SUM(pageviews) AS pageviews,
				        SUM(visitors)  AS visitors
				 FROM %i
				 WHERE post_id = %d AND date BETWEEN %s AND %s
				 GROUP BY date
				 ORDER BY date ASC',
				$stats,
				$post_id,
				$start,
				$end
			),
			ARRAY_A
		);

		$by_date = [];
		foreach ( (array) $rows as $r ) {
			$by_date[ $r['date'] ] = [
				'date'      => (string) $r['date'],
				'pageviews' => (int) $r['pageviews'],
				'visitors'  => (int) $r['visitors'],
			];
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
	 * Top referrers for this specific post over the date range. Reads from
	 * va_post_referrer_stats (separate from the site-wide va_referrer_stats)
	 * so we don't blow up the primary rollup with post_id granularity.
	 *
	 * @return array<int, array{host:string,pageviews:int,visitors:int}>
	 */
	public function get_top_referrers( int $post_id, string $start, string $end, int $limit = 10 ): array {
		$cache_key = self::CACHE_GROUP . 'refs_' . md5( $post_id . '|' . $start . '|' . $end . '|' . $limit );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$stats = Sites::table( 'post_referrer_stats' );
		$urls  = Sites::table( 'referrer_urls' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- schema probe
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $stats ) ) !== $stats ) {
			return [];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- transient-cached above
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT u.url AS host,
				        SUM(s.pageviews) AS pageviews,
				        SUM(s.visitors)  AS visitors
				 FROM %i s
				 JOIN %i u ON u.id = s.url_id
				 WHERE s.post_id = %d AND s.date BETWEEN %s AND %s
				 GROUP BY u.url
				 ORDER BY pageviews DESC
				 LIMIT %d',
				$stats,
				$urls,
				$post_id,
				$start,
				$end,
				max( 1, min( 100, $limit ) )
			),
			ARRAY_A
		);

		$out = [];
		foreach ( (array) $rows as $r ) {
			$out[] = [
				'host'      => (string) $r['host'],
				'pageviews' => (int) $r['pageviews'],
				'visitors'  => (int) $r['visitors'],
			];
		}

		set_transient( $cache_key, $out, self::CACHE_TTL );
		return $out;
	}

	/**
	 * Returns tracking-start metadata for the per-post referrer table. Used by
	 * the template to surface a "Referrer data starts tracking from X" note
	 * when the table is newer than the post.
	 *
	 * @return array{has_data:bool, first_seen:?string}
	 */
	public function get_referrer_tracking_info( int $post_id ): array {
		global $wpdb;
		$stats = Sites::table( 'post_referrer_stats' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- schema probe
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $stats ) ) !== $stats ) {
			return [ 'has_data' => false, 'first_seen' => null ];
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- cheap MIN() on indexed column
		$first = $wpdb->get_var(
			$wpdb->prepare( 'SELECT MIN(date) FROM %i WHERE post_id = %d', $stats, $post_id )
		);
		return [
			'has_data'   => null !== $first && '' !== $first,
			'first_seen' => $first ? (string) $first : null,
		];
	}
}
