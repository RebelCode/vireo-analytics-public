<?php
/**
 * Per-event stats drill-down.
 *
 * Route: /wp-admin/admin.php?page=vireo-analytics&view=event&name=X
 *
 * Linked from the Top Events card. Shows a daily chart for one event name
 * plus a leaderboard of top values. Date range reuses the same preset bar
 * as the Overview.
 */

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

final class Event_Detail {

	private const CACHE_GROUP = 'vireo_event_';
	private const CACHE_TTL   = 30;
	private const DEFAULT_DAYS = 30;

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Dashboard::render_overview_page delegates to us when view=event.
	}

	public function render(): void {
		if ( ! current_user_can( 'view_vireo_analytics' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- capability-gated detail view reached only via the nonce-verified view router
		$name = isset( $_GET['name'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['name'] ) ) : '';
		$name = strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', $name ) );
		$name = substr( $name, 0, 100 );

		if ( '' === $name ) {
			echo '<div class="wrap va-wrap"><div class="va-root"><p>'
				. esc_html__( 'Event not found.', 'vireo-analytics' )
				. '</p></div></div>';
			return;
		}

		[ $start, $end, $preset ] = $this->resolve_range_from_request();

		$totals = $this->get_totals( $name, $start, $end );
		$daily  = $this->get_daily_series( $name, $start, $end );
		$values = $this->get_top_values( $name, $start, $end, 20 );
		// The leaderboard stops at 20, so its length can't stand in for the total.
		$va_value_count = $this->count_values( $name, $start, $end );

		// Template locals.
		$va_event_name = $name;
		// Events live on the Campaigns tab, so that's where Back goes.
		$va_back_url   = admin_url( 'admin.php?page=vireo-analytics&tab=campaigns' );
		$va_page_title = sprintf(
			/* translators: %s: event name */
			__( 'Event · %s', 'vireo-analytics' ),
			$name
		);

		include VIREO_DIR . 'templates/dashboard/event-detail.php';
	}

	/**
	 * Range resolver — same preset keys as Dashboard, defaulting to last 30.
	 *
	 * @return array{0:string,1:string,2:string}
	 */
	private function resolve_range_from_request(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- capability-gated detail view reached only via the nonce-verified view router
		$period = isset( $_GET['period'] ) ? sanitize_key( (string) $_GET['period'] ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- capability-gated detail view reached only via the nonce-verified view router
		$start  = isset( $_GET['start_date'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['start_date'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- capability-gated detail view reached only via the nonce-verified view router
		$end    = isset( $_GET['end_date'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['end_date'] ) ) : '';

		$valid = static fn( string $s ): bool => (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $s );

		if ( 'custom' === $period && $valid( $start ) && $valid( $end ) ) {
			if ( strcmp( $end, $start ) < 0 ) {
				[ $start, $end ] = [ $end, $start ];
			}
			return [ $start, $end, 'custom' ];
		}

		$tz    = wp_timezone();
		$today = ( new \DateTimeImmutable( 'now', $tz ) )->setTime( 0, 0, 0 );
		$fmt   = 'Y-m-d';

		switch ( $period ) {
			case 'today':
				return [ $today->format( $fmt ), $today->format( $fmt ), 'today' ];
			case 'yesterday':
				$d = $today->modify( '-1 day' );
				return [ $d->format( $fmt ), $d->format( $fmt ), 'yesterday' ];
			case 'last_7':
				return [ $today->modify( '-6 days' )->format( $fmt ), $today->format( $fmt ), 'last_7' ];
			case 'last_14':
				return [ $today->modify( '-13 days' )->format( $fmt ), $today->format( $fmt ), 'last_14' ];
			case 'last_30':
				return [ $today->modify( '-29 days' )->format( $fmt ), $today->format( $fmt ), 'last_30' ];
			case 'last_90':
				return [ $today->modify( '-89 days' )->format( $fmt ), $today->format( $fmt ), 'last_90' ];
		}

		return [
			$today->modify( '-' . ( self::DEFAULT_DAYS - 1 ) . ' days' )->format( $fmt ),
			$today->format( $fmt ),
			'last_30',
		];
	}

	/**
	 * @return array{count:int, unique_count:int}
	 */
	public function get_totals( string $name, string $start, string $end ): array {
		$cache_key = self::CACHE_GROUP . 'tot_' . md5( Sites::current() . '|' . $name . '|' . $start . '|' . $end );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$table = Sites::table( 'event_stats' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- transient-cached above the direct query
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(count),0) AS count,
				        COALESCE(SUM(unique_count),0) AS unique_count
				 FROM %i
				 WHERE event_name = %s AND date BETWEEN %s AND %s',
				$table,
				$name,
				$start,
				$end
			),
			ARRAY_A
		);

		$result = [
			'count'        => (int) ( $row['count'] ?? 0 ),
			'unique_count' => (int) ( $row['unique_count'] ?? 0 ),
		];
		set_transient( $cache_key, $result, self::CACHE_TTL );
		return $result;
	}

	/**
	 * Daily series of the event over the range, zero-filled.
	 *
	 * @return array<int, array{date:string, pageviews:int, visitors:int}>
	 */
	public function get_daily_series( string $name, string $start, string $end ): array {
		$cache_key = self::CACHE_GROUP . 'daily_' . md5( Sites::current() . '|' . $name . '|' . $start . '|' . $end );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$table = Sites::table( 'event_stats' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- transient-cached above the direct query
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT date,
				        SUM(count) AS count,
				        SUM(unique_count) AS unique_count
				 FROM %i
				 WHERE event_name = %s AND date BETWEEN %s AND %s
				 GROUP BY date
				 ORDER BY date ASC',
				$table,
				$name,
				$start,
				$end
			),
			ARRAY_A
		);

		$by_date = [];
		foreach ( (array) $rows as $r ) {
			// Use pageviews/visitors shape so the chart JS doesn't need a separate
			// code path — the legend labels are adjusted in the template.
			$by_date[ $r['date'] ] = [
				'date'      => (string) $r['date'],
				'pageviews' => (int) $r['count'],
				'visitors'  => (int) $r['unique_count'],
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
	 * How many different values this event carried in the range.
	 */
	public function count_values( string $name, string $start, string $end ): int {
		$cache_key = self::CACHE_GROUP . 'nvals_' . md5( Sites::current() . '|' . $name . '|' . $start . '|' . $end );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- transient-cached above the direct query
		$n = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT event_value) FROM %i WHERE event_name = %s AND date BETWEEN %s AND %s AND event_value <> ''",
				Sites::table( 'event_stats' ),
				$name,
				$start,
				$end
			)
		);
		set_transient( $cache_key, $n, self::CACHE_TTL );
		return $n;
	}

	/**
	 * Top values for this event, grouped + summed.
	 *
	 * @return array<int, array{value:string, count:int, unique_count:int}>
	 */
	public function get_top_values( string $name, string $start, string $end, int $limit = 20 ): array {
		$cache_key = self::CACHE_GROUP . 'vals_' . md5( Sites::current() . '|' . $name . '|' . $start . '|' . $end . '|' . $limit );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$table = Sites::table( 'event_stats' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- transient-cached above the direct query
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT event_value AS value,
				        SUM(count) AS count,
				        SUM(unique_count) AS unique_count
				 FROM %i
				 WHERE event_name = %s AND date BETWEEN %s AND %s
				 GROUP BY event_value
				 ORDER BY count DESC
				 LIMIT %d',
				$table,
				$name,
				$start,
				$end,
				max( 1, min( 100, $limit ) )
			),
			ARRAY_A
		);

		$out = [];
		foreach ( (array) $rows as $r ) {
			$out[] = [
				'value'        => (string) $r['value'],
				'count'        => (int) $r['count'],
				'unique_count' => (int) $r['unique_count'],
			];
		}
		set_transient( $cache_key, $out, self::CACHE_TTL );
		return $out;
	}
}
