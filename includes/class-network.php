<?php

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * One table for the whole network.
 *
 * Each site keeps its own tables, which is the right arrangement: a site's
 * traffic belongs to that site, and an editor on one has no business reading
 * another's. This is the view for the person who runs all of them, who
 * otherwise has to open thirty dashboards to answer "which of these is
 * growing".
 *
 * The roll-up is cached, because it costs one query per site and a network
 * admin refreshing a page should not fan out across two hundred databases
 * every time.
 */
final class Network {

	public const PAGE = 'vireo-analytics-network';

	private const CACHE_KEY = 'vireo_network_rollup';
	private const CACHE_TTL = 15 * MINUTE_IN_SECONDS;

	/** How many sites one page load will query. Larger networks are reported as truncated. */
	private const FANOUT_LIMIT = 300;

	public static function boot(): void {
		if ( ! is_multisite() ) {
			return;
		}

		add_action( 'network_admin_menu', [ __CLASS__, 'register_page' ] );
	}

	public static function register_page(): void {
		add_menu_page(
			__( 'Analytics', 'vireo-analytics' ),
			__( 'Analytics', 'vireo-analytics' ),
			'manage_network',
			self::PAGE,
			[ __CLASS__, 'render' ],
			'dashicons-chart-bar',
			30
		);
	}

	/**
	 * Totals per site for a range, busiest first.
	 *
	 * @return array<int,array{blog_id:int,name:string,url:string,pageviews:int,visitors:int}>
	 */
	public static function rollup( string $start, string $end, bool $use_cache = true ): array {
		$cache_key = self::CACHE_KEY . '_' . md5( $start . '|' . $end );

		if ( $use_cache ) {
			$cached = get_site_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$sites = get_sites(
			[
				'number'   => self::FANOUT_LIMIT,
				'fields'   => 'ids',
				'archived' => 0,
				'deleted'  => 0,
				'spam'     => 0,
			]
		);

		$out = [];

		foreach ( (array) $sites as $site_id ) {
			$site_id = (int) $site_id;
			switch_to_blog( $site_id );

			$totals = self::site_totals( $start, $end );

			if ( null !== $totals ) {
				$out[] = [
					'blog_id'   => $site_id,
					'name'      => get_bloginfo( 'name' ),
					'url'       => get_home_url( $site_id, '/' ),
					'pageviews' => $totals['pageviews'],
					'visitors'  => $totals['visitors'],
				];
			}

			restore_current_blog();
		}

		usort( $out, static fn( array $a, array $b ): int => $b['pageviews'] <=> $a['pageviews'] );

		set_site_transient( $cache_key, $out, self::CACHE_TTL );

		return $out;
	}

	/**
	 * How many sites the network has, against how many this page will read.
	 *
	 * The fan-out is one query per site, so it stops at a few hundred. It used
	 * to stop silently, and the page then said "across N sites" with N quietly
	 * capped, which reads as a complete answer when it is not.
	 *
	 * @return array{total:int,shown:int,truncated:bool}
	 */
	public static function fanout_status(): array {
		$total = (int) get_sites(
			[
				'count'    => true,
				'archived' => 0,
				'deleted'  => 0,
				'spam'     => 0,
			]
		);

		return [
			'total'     => $total,
			'shown'     => min( $total, self::FANOUT_LIMIT ),
			'truncated' => $total > self::FANOUT_LIMIT,
		];
	}

	/**
	 * Totals for the site currently switched to, or null if the plugin has
	 * never run there.
	 *
	 * @return array{pageviews:int,visitors:int}|null
	 */
	private static function site_totals( string $start, string $end ): ?array {
		global $wpdb;

		$table = Sites::table( 'site_stats' );

		// A site on the network may not have the plugin set up yet. Its absence
		// is not an error; it just has nothing to report.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- schema probe, uncacheable
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- reporting read
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(pageviews),0) AS pageviews, COALESCE(SUM(visitors),0) AS visitors
				 FROM {$table} WHERE date BETWEEN %s AND %s",
				$start,
				$end
			),
			ARRAY_A
		);

		return [
			'pageviews' => (int) ( $row['pageviews'] ?? 0 ),
			'visitors'  => (int) ( $row['visitors'] ?? 0 ),
		];
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_network' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'vireo-analytics' ), '', [ 'response' => 403 ] );
		}

		$days = 30;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only range selector on a capability-gated page
		if ( isset( $_GET['days'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$days = absint( $_GET['days'] );
		}
		$days = in_array( $days, [ 7, 30, 90 ], true ) ? $days : 30;

		$end   = gmdate( 'Y-m-d' );
		$start = gmdate( 'Y-m-d', time() - ( ( $days - 1 ) * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- refresh is a cache bust, not a state change
		$fresh = isset( $_GET['refresh'] ) && check_admin_referer( 'vireo_network_refresh' );

		$va_network_sites  = self::rollup( $start, $end, ! $fresh );
		$va_network_fanout = self::fanout_status();
		$va_network_days  = $days;
		$va_network_start = $start;
		$va_network_end   = $end;

		include VIREO_DIR . 'templates/admin/network-page.php';
	}
}
