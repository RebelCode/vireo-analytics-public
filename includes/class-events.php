<?php
/**
 * Custom events — data layer and PHP-side helper.
 *
 * Writes land via Collector::append_event() (same buffer as pageviews).
 * The Aggregator folds `e` lines into `wp_la_event_stats`. This class owns
 * the read side (dashboard queries, REST wrapper helpers) plus the
 * `vireo_track_event()` public function for PHP-side firing.
 *
 * Bracketed namespaces are used here so we can declare both the namespaced
 * class and the global-namespace `vireo_track_event()` helper in a single file.
 */

namespace VireoAnalytics {

	defined( 'ABSPATH' ) || exit;

	final class Events {

		/**
		 * Top events for a date range, grouped by event_name. Each row is the
		 * sum of counts across all values for that event name.
		 *
		 * @return array<int, array{name:string, count:int, unique_count:int}>
		 */
		public static function top_events( string $start, string $end, int $limit = 10 ): array {
			global $wpdb;
			$table = Sites::table( 'event_stats' );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- aggregator output, callers transient-cache
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT event_name AS name,
					        SUM(count) AS count,
					        SUM(unique_count) AS unique_count
					 FROM %i
					 WHERE date BETWEEN %s AND %s
					 GROUP BY event_name
					 ORDER BY count DESC
					 LIMIT %d',
					$table,
					$start,
					$end,
					max( 1, min( 100, $limit ) )
				),
				ARRAY_A
			);

			$out = [];
			foreach ( (array) $rows as $r ) {
				$out[] = [
					'name'         => (string) $r['name'],
					'count'        => (int) $r['count'],
					'unique_count' => (int) $r['unique_count'],
				];
			}
			return $out;
		}

		/**
		 * Events for a specific name, grouped by value. Used when a user clicks
		 * through from the top-events list into a single event's breakdown.
		 *
		 * @return array<int, array{name:string, value:string, count:int, unique_count:int}>
		 */
		public static function events_by_value( string $start, string $end, string $name, int $limit = 10 ): array {
			global $wpdb;
			$table = Sites::table( 'event_stats' );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- aggregator output, callers transient-cache
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT event_name AS name, event_value AS value,
					        SUM(count) AS count,
					        SUM(unique_count) AS unique_count
					 FROM %i
					 WHERE date BETWEEN %s AND %s AND event_name = %s
					 GROUP BY event_name, event_value
					 ORDER BY count DESC
					 LIMIT %d',
					$table,
					$start,
					$end,
					$name,
					max( 1, min( 100, $limit ) )
				),
				ARRAY_A
			);

			$out = [];
			foreach ( (array) $rows as $r ) {
				$out[] = [
					'name'         => (string) $r['name'],
					'value'        => (string) $r['value'],
					'count'        => (int) $r['count'],
					'unique_count' => (int) $r['unique_count'],
				];
			}
			return $out;
		}
	}
}

namespace {

	/**
	 * Public helper for PHP-side event tracking.
	 *
	 * Example:
	 *     vireo_track_event( 'newsletter_signup', 'footer_form' );
	 *     add_action( 'woocommerce_checkout_order_processed', function ( $order_id ) {
	 *         vireo_track_event( 'order_complete', (string) $order_id );
	 *     } );
	 *
	 * Sanitizes `name` to a-z0-9_-, truncates to 100 chars. `value` is optional,
	 * truncated to 100 chars. Writes directly to the buffer — same <5ms budget
	 * as the JS path. Returns false on invalid input.
	 */
	function vireo_track_event( string $name, string $value = '' ): bool {
		$name = strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', $name ) );
		$name = substr( $name, 0, 100 );
		if ( '' === $name ) {
			return false;
		}

		$value = substr( (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $value ), 0, 100 );

		$visitor_hash = '';
		if ( function_exists( 'vireo_get_client_ip' ) && class_exists( \VireoAnalytics\Salt_Manager::class ) ) {
			$ip = vireo_get_client_ip();
			$ua = isset( $_SERVER['HTTP_USER_AGENT'] )
				? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) )
				: '';
			if ( '' !== $ip ) {
				$bundle = \VireoAnalytics\Salt_Manager::instance()->get_salts();
				$salt   = (string) ( $bundle['current'] ?? '' );
				if ( '' !== $salt ) {
					$visitor_hash = substr( hash_hmac( 'sha256', $ip . '|' . $ua, $salt ), 0, 32 );
				}
			}
		}

		return \VireoAnalytics\Collector::append_event(
			[
				'ts'           => time(),
				'name'         => $name,
				'value'        => $value,
				'visitor_hash' => $visitor_hash,
			]
		);
	}
}
