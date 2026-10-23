<?php
/**
 * 404 tracker.
 *
 * Logs 404 hits per (date, path, referrer, internal_ref). The internal
 * referrer is what separates a broken link on the site from a stranger
 * guessing URLs: both arrive with no external referrer, so without it the
 * report is mostly vulnerability scanners. Frontend-only; admin, AJAX, REST,
 * cron and CLI requests are never recorded. Bot UAs and excluded IPs/paths
 * are skipped via Bot_Filter::request_is_excluded() — same rules as
 * Live_Tracker and Search_Tracker.
 *
 */

declare( strict_types=1 );

namespace VireoAnalytics;

use VireoAnalytics\Bot_Filter;

defined( 'ABSPATH' ) || exit;

final class NotFound_Tracker {

	public const TABLE = '404_stats';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public static function table_name(): string {
		return Sites::table( self::TABLE );
	}

	// ---------------------------------------------------------------------
	// Pro_Feature contract
	// ---------------------------------------------------------------------

	public static function register_tables(): void {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// dbDelta requires a literal table name in the CREATE statement; the
		// prefix is wpdb-controlled and cannot be user-influenced.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
		// Must stay identical to Installer::create_tables(). Two dbDelta
		// definitions of the same table that disagree will fight each other on
		// every upgrade.
		dbDelta(
			'CREATE TABLE ' . self::table_name() . " (
				date date NOT NULL,
				entry_hash char(32) NOT NULL DEFAULT '',
				path varchar(255) NOT NULL DEFAULT '',
				referrer varchar(255) NOT NULL DEFAULT '',
				internal_ref varchar(255) NOT NULL DEFAULT '',
				hits int unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (date, entry_hash),
				KEY date_hits (date, hits),
				KEY path (path(100)),
				KEY internal_ref (internal_ref(100))
			) {$charset_collate};"
		);
	}

	public static function unregister_tables(): void {
		global $wpdb;
		// Table name composed from wpdb prefix + class constant; no injection surface.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- explicit schema teardown
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::table_name() ) );
	}

	public static function boot(): void {
		// Frontend-only: skip admin, AJAX, REST, cron, CLI.
		if ( is_admin() ) {
			return;
		}
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}

		// Priority 999: run after any plugin redirect/normalisation so we only
		// log genuine terminal 404s.
		add_action( 'template_redirect', [ self::instance(), 'maybe_log' ], 999 );
	}

	// ---------------------------------------------------------------------
	// Logging
	// ---------------------------------------------------------------------

	public function maybe_log(): void {
		if ( ! is_404() ) {
			return;
		}

		// Take the path from the URI before decoding it, so a decoded '?' or
		// '#' inside a segment cannot move the boundary; then decode and
		// sanitise it with the helper the importers use, so an imported row for
		// the same page merges with this one. sanitize_text_field() on the
		// encoded form stripped the percent-encoded octets and stored
		// /caf%C3%A9/ as /caf/.
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised two lines down, after the path is split off
		$uri  = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $uri );
		$path = Collector::decode_path( (string) wp_parse_url( $uri, PHP_URL_PATH ) );
		if ( '' === $path ) {
			return;
		}

		$ua = isset( $_SERVER['HTTP_USER_AGENT'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) )
			: '';
		$ip = function_exists( 'vireo_get_client_ip' ) ? vireo_get_client_ip() : '';

		// Same exclusion policy as Live_Tracker and Search_Tracker.
		if ( Bot_Filter::request_is_excluded( [ 'ua' => $ua, 'ip' => $ip, 'path' => $path ] ) ) {
			return;
		}

		// This runs on template_redirect, not through the beacon, so nothing
		// else on this path is throttled — and both the requested path and the
		// referrer are attacker-controlled and part of the primary key. Without
		// a limit, one host requesting random URLs writes a new row per request
		// for as long as it cares to.
		if ( function_exists( 'vireo_beacon_rate_limit_ok' ) && ! vireo_beacon_rate_limit_ok( $ip ) ) {
			return;
		}

		// An internal referrer used to be discarded, which threw away the only
		// thing that distinguishes a broken link from a stranger guessing URLs.
		// A 404 reached from one of your own pages is a link you can go and fix,
		// and knowing which page linked to it is the whole job.
		$referrer_host = '';
		$internal_ref  = '';
		if ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
			$referer_raw = sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_REFERER'] ) );
			$host        = wp_parse_url( $referer_raw, PHP_URL_HOST );
			$site        = wp_parse_url( home_url(), PHP_URL_HOST );

			if ( $host && $host !== $site ) {
				$referrer_host = (string) $host;
			} elseif ( $host && $host === $site ) {
				$ref_path     = (string) wp_parse_url( $referer_raw, PHP_URL_PATH );
				$internal_ref = '' !== $ref_path ? $ref_path : '/';
			}
		}

		// The Referer header is attacker-controlled, so a same-host value is a
		// claim rather than evidence. A scanner requesting /222.php while
		// sending "Referer: https://yoursite/222.php" would otherwise be filed
		// as a broken link on your own site, which is exactly the noise this
		// split exists to remove. Seen in production within a day of shipping.
		if ( '' !== $internal_ref && ! $this->referrer_is_believable( $internal_ref, $path ) ) {
			$internal_ref = '';
		}

		$this->record( $path, $referrer_host, $internal_ref );
	}

	/**
	 * Would a real page on this site plausibly have linked here?
	 *
	 * Two cheap rejections, both aimed at forged headers rather than at
	 * legitimate visitors:
	 *
	 *   - A page cannot link to itself and 404 in the process. A self-referring
	 *     404 is always a fabricated header.
	 *   - A referrer that is itself a known 404 is not a page at all. Scanners
	 *     walk a wordlist and reuse the last URL as the Referer, which chains
	 *     one bogus path to the next.
	 *
	 * Deliberately not checking that the referring path resolves to a post:
	 * archives, taxonomies and pretty permalinks handled by other plugins are
	 * all real pages that url_to_postid() would reject, and silently dropping
	 * genuine broken links is worse than admitting the occasional forged one.
	 */
	private function referrer_is_believable( string $internal_ref, string $path ): bool {
		if ( $internal_ref === $path ) {
			return false;
		}

		global $wpdb;
		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- only runs for a 404 that claims an internal referrer, which is rare
		$ref_is_itself_missing = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE path = %s LIMIT 1", $internal_ref )
		);

		return 0 === $ref_is_itself_missing;
	}

	private function record( string $path, string $referrer, string $internal_ref = '' ): void {
		global $wpdb;

		// Cut at the column width without splitting a multibyte character; a
		// byte cut leaves invalid UTF-8 and a hash the importer cannot match.
		$path         = Collector::clip( $path, 255 );
		$referrer     = Collector::clip( $referrer, 255 );
		$internal_ref = Collector::clip( $internal_ref, 255 );
		// UTC bucket — matches wp_la_site_stats and our other daily tables.
		$date  = vireo_bucket_date();
		$table = self::table_name();
		$hash  = md5( $path . "\0" . $referrer . "\0" . $internal_ref );

		// Increment first. A 404 on a path we already know costs one UPDATE and
		// cannot grow the table, which is the overwhelmingly common case on a
		// real site (a handful of broken links hit over and over).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- counter increment
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET hits = hits + 1 WHERE date = %s AND entry_hash = %s',
				$table,
				$date,
				$hash
			)
		);

		if ( $updated ) {
			return;
		}

		// Only a genuinely new (path, referrer, internal_ref) grows the table,
		// so the daily cap is checked here rather than on every 404. The per-IP
		// limit above stops one host; this stops a distributed one.
		if ( self::daily_row_cap_reached( $date ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- counter upsert
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (date, entry_hash, path, referrer, internal_ref, hits)
				 VALUES (%s, %s, %s, %s, %s, 1)
				 ON DUPLICATE KEY UPDATE hits = hits + 1',
				$table,
				$date,
				$hash,
				$path,
				$referrer,
				$internal_ref
			)
		);
	}

	/**
	 * Whether today has already logged as many distinct 404s as we're willing
	 * to store.
	 *
	 * A site with more than a few hundred distinct broken paths in one day has
	 * a problem the 404 report already told them about; past that point the
	 * rows are noise, and on an unthrottled public endpoint they're a way to
	 * fill someone's database. Counting stops once the cap is hit so a flood
	 * doesn't turn into a COUNT(*) per request.
	 *
	 * Filterable via `vireo_404_max_new_rows_per_day`; 0 or less disables the
	 * cap for anyone who genuinely wants every path.
	 */
	private static function daily_row_cap_reached( string $date ): bool {
		$cap = (int) apply_filters( 'vireo_404_max_new_rows_per_day', 500 );
		if ( $cap <= 0 ) {
			return false;
		}

		$flag = 'va_404_cap_' . $date;
		if ( get_transient( $flag ) ) {
			return true;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- guarded by the transient above
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE date = %s', self::table_name(), $date )
		);

		if ( $count >= $cap ) {
			set_transient( $flag, 1, HOUR_IN_SECONDS );
			return true;
		}

		return false;
	}

	// ---------------------------------------------------------------------
	// Read helpers
	// ---------------------------------------------------------------------

	/**
	 * Top 404 paths between two dates, with each path's top referrer.
	 *
	 * @return array<int, object>
	 */
	public static function get_top( string $date_start, string $date_end, int $limit = 20 ): array {
		global $wpdb;
		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- aggregator output, callers transient-cache
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT path, SUM(hits) AS hits,
				        SUM(CASE WHEN referrer <> '' THEN hits ELSE 0 END) AS referred_hits,
				        (SELECT referrer FROM %i r2
				           WHERE r2.path = r1.path AND r2.date BETWEEN %s AND %s AND r2.referrer <> ''
				           ORDER BY r2.hits DESC LIMIT 1) AS top_referrer
				 FROM %i r1
				 WHERE date BETWEEN %s AND %s
				 GROUP BY path
				 ORDER BY hits DESC
				 LIMIT %d",
				$table,
				$date_start,
				$date_end,
				$table,
				$date_start,
				$date_end,
				$limit
			)
		);
	}

	/**
	 * Prior-period hit counts keyed by path — used by the template to render
	 * delta chips against the preceding window.
	 *
	 * @return array<string, int>
	 */
	public static function get_prior_by_path( string $date_start, string $date_end ): array {
		global $wpdb;
		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- aggregator output, callers transient-cache
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT path, SUM(hits) AS hits
				 FROM %i
				 WHERE date BETWEEN %s AND %s
				 GROUP BY path',
				$table,
				$date_start,
				$date_end
			)
		);

		$out = [];
		foreach ( $rows as $r ) {
			$out[ (string) $r->path ] = (int) $r->hits;
		}
		return $out;
	}

	/**
	 * Component render — include the template for a given date range.
	 */
	public static function render( string $date_start, string $date_end ): void {
		$rows          = self::get_top( $date_start, $date_end, 20 );
		$prior_by_path = self::prior_map_for_range( $date_start, $date_end );

		include VIREO_DIR . 'templates/dashboard/404-table.php';
	}

	/**
	 * @return array<string, int>
	 */
	private static function prior_map_for_range( string $date_start, string $date_end ): array {
		$s = strtotime( $date_start . ' 00:00:00 UTC' );
		$e = strtotime( $date_end . ' 00:00:00 UTC' );
		if ( ! $s || ! $e || $e < $s ) {
			return [];
		}
		$days        = (int) ( ( ( $e - $s ) / DAY_IN_SECONDS ) + 1 );
		$prior_end   = gmdate( 'Y-m-d', $s - DAY_IN_SECONDS );
		$prior_start = gmdate( 'Y-m-d', $s - ( $days * DAY_IN_SECONDS ) );

		return self::get_prior_by_path( $prior_start, $prior_end );
	}
}
