<?php

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * What people searched for before they arrived.
 *
 * The rest of the dashboard can say a visit came from Google and stops there,
 * because the query is stripped before the browser ever sees it. Search
 * Console is the only place that knows, and it is the difference between "our
 * traffic fell" and "we lost the two queries that were carrying us".
 *
 * Pulled on a daily schedule rather than on page load: the API is slow, rate
 * limited, and its data lags two to three days, so fetching it while someone
 * waits for a dashboard would be all cost and no freshness.
 *
 * Storage is one row per (day, query), plus one row per day with an empty
 * query holding the site totals. The totals cannot be derived by summing the
 * queries, because Google truncates the query list and withholds rare ones,
 * so summing would quietly under-report every day.
 */
final class Search_Console {

	public const TABLE      = 'gsc_stats';
	public const PAGE_TABLE = 'gsc_pages';
	public const SCOPE      = 'https://www.googleapis.com/auth/webmasters.readonly';
	public const CRON_HOOK  = 'vireo_gsc_sync';

	/** Chained single events that load older history a slice at a time. */
	public const BACKFILL_HOOK   = 'vireo_gsc_backfill';
	public const BACKFILL_OPTION = 'vireo_gsc_backfill';
	public const OPTION     = 'vireo_gsc_settings';
	public const STATE      = 'vireo_gsc_state';

	private const API       = 'https://searchconsole.googleapis.com/webmasters/v3/sites/%s/searchAnalytics/query';
	private const TIMEOUT   = 60;
	/** Google's maximum page size. It was 1,000, which meant 25 round trips per pull. */
	private const ROW_LIMIT = 25000;

	/**
	 * Where paging stops. The old stop was 25,000 rows for the whole window,
	 * and a 90-day pull on one modest site hit it exactly, so everything past
	 * row 25,000 was dropped without a word. Google's own ceiling is 50,000
	 * rows per day per search type; this only exists so a misbehaving
	 * response cannot loop forever.
	 */
	private const MAX_ROWS = 2000000;

	/** Google's data is not final for about three days. */
	private const LAG_DAYS = 3;

	/** How far back a routine sync re-reads, since recent days keep changing. */
	private const REFRESH_DAYS = 7;

	/**
	 * Days per background slice. A 90-day pull took over 70 seconds once the
	 * row cap stopped truncating it, which is past the limit an admin request
	 * gets on plenty of hosts. Fourteen days runs in roughly ten.
	 */
	private const BACKFILL_SLICE = 14;

	public static function boot(): void {
		add_action( self::CRON_HOOK, [ __CLASS__, 'sync' ] );
		add_action( self::BACKFILL_HOOK, [ __CLASS__, 'run_backfill' ] );
		add_action( 'admin_post_vireo_gsc_save', [ __CLASS__, 'handle_save' ] );
		add_action( 'admin_post_vireo_gsc_sync', [ __CLASS__, 'handle_sync' ] );
		add_action( 'admin_post_vireo_gsc_disconnect', [ __CLASS__, 'handle_disconnect' ] );

		// Only in the admin. This used to run on every request, and
		// configured() reads an option that is deliberately not autoloaded, so
		// it cost an extra query per page view on every site that had never
		// set Search Console up. Scheduling also happens in save_settings(),
		// so a connected site is scheduled the moment it is connected.
		if ( is_admin() ) {
			add_action( 'admin_init', [ __CLASS__, 'ensure_scheduled' ] );
		}
	}

	public static function ensure_scheduled(): void {
		if ( self::configured() && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	public static function table_name(): string {
		return Sites::table( self::TABLE );
	}

	/**
	 * @return array{key_json:string, site_url:string}
	 */
	public static function settings(): array {
		$raw = (array) get_option( self::OPTION, [] );

		return [
			'key_json' => (string) ( $raw['key_json'] ?? '' ),
			'site_url' => (string) ( $raw['site_url'] ?? '' ),
		];
	}

	public static function configured(): bool {
		$s = self::settings();

		return '' !== $s['key_json'] && '' !== $s['site_url'];
	}

	public static function account_email(): string {
		return Google_Auth::account_email( self::settings()['key_json'] );
	}

	/**
	 * @param array{key_json?:string, site_url?:string} $input
	 * @return true|\WP_Error
	 */
	public static function save_settings( array $input ) {
		$current = self::settings();

		// An empty key means keep the stored one: the form never renders it
		// back, so editing the site URL would otherwise wipe the credentials.
		$key = isset( $input['key_json'] ) ? trim( (string) $input['key_json'] ) : '';
		if ( '' === $key ) {
			$key = $current['key_json'];
		} elseif ( is_wp_error( Google_Auth::parse_key( $key ) ) ) {
			return Google_Auth::parse_key( $key );
		}

		$site = self::normalize_site( (string) ( $input['site_url'] ?? $current['site_url'] ) );
		if ( '' === $site ) {
			return new \WP_Error(
				'vireo_gsc_site',
				__( 'Enter the property exactly as Search Console shows it, either a URL or sc-domain:example.com.', 'vireo-analytics' )
			);
		}

		if ( '' === $key ) {
			return new \WP_Error( 'vireo_gsc_key', __( 'Paste the service account key file to begin.', 'vireo-analytics' ) );
		}

		// A replaced key leaves its access tokens cached and usable for the
		// best part of an hour. Drop them with it.
		if ( '' !== $current['key_json'] && $current['key_json'] !== $key ) {
			Google_Auth::forget( $current['key_json'], [ self::SCOPE ] );
		}

		update_option(
			self::OPTION,
			[
				'key_json' => $key,
				'site_url' => $site,
			],
			false
		);

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'daily', self::CRON_HOOK );
		}

		return true;
	}

	public static function clear_settings(): void {
		$key = self::settings()['key_json'];
		if ( '' !== $key ) {
			Google_Auth::forget( $key, [ self::SCOPE ] );
		}

		delete_option( self::OPTION );
		delete_option( self::STATE );
		delete_option( self::BACKFILL_OPTION );
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( self::BACKFILL_HOOK );
	}

	/**
	 * Accepts a domain property (`sc-domain:example.com`) or a URL prefix
	 * property, which Search Console is fussy about: it wants the trailing
	 * slash it showed you.
	 */
	public static function normalize_site( string $raw ): string {
		$raw = trim( $raw );

		if ( '' === $raw ) {
			return '';
		}

		if ( 0 === stripos( $raw, 'sc-domain:' ) ) {
			$domain = strtolower( trim( substr( $raw, 10 ) ) );

			return '' === $domain ? '' : 'sc-domain:' . $domain;
		}

		if ( false === strpos( $raw, '://' ) ) {
			$raw = 'https://' . $raw;
		}

		$parts = wp_parse_url( $raw );
		if ( empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : 'https';
		$path   = isset( $parts['path'] ) ? $parts['path'] : '/';

		return $scheme . '://' . strtolower( $parts['host'] ) . rtrim( $path, '/' ) . '/';
	}

	/**
	 * Pull recent days. Safe to run repeatedly: rows are replaced, not added
	 * to, because Google revises the last few days after first reporting them.
	 *
	 * @return array{days:int,rows:int}|\WP_Error rows is how many were written,
	 *         which on an unchanged re-sync is the same number again, not zero.
	 */
	/**
	 * @param int    $days How many days to read, ending at $end.
	 * @param string $end  Last day to read (Y-m-d). Empty means the most
	 *                     recent day Google treats as settled. Background
	 *                     slices pass an older one to walk history backwards.
	 */
	public static function sync( $days = 0, $end = '' ) {
		$days = (int) $days;
		$end  = (string) $end;
		if ( ! self::configured() ) {
			return new \WP_Error( 'vireo_gsc_unconfigured', __( 'Search Console is not connected yet.', 'vireo-analytics' ) );
		}

		$settings = self::settings();
		$token    = Google_Auth::access_token( $settings['key_json'], self::SCOPE );

		if ( is_wp_error( $token ) ) {
			self::remember( [ 'error' => $token->get_error_message() ] );
			return $token;
		}

		$days   = $days > 0 ? min( 480, $days ) : self::REFRESH_DAYS;
		$is_old = 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end );
		if ( ! $is_old ) {
			$end = gmdate( 'Y-m-d', time() - ( self::LAG_DAYS * DAY_IN_SECONDS ) );
		}
		$start = gmdate( 'Y-m-d', strtotime( $end ) - ( ( $days - 1 ) * DAY_IN_SECONDS ) );

		$totals = self::query( $settings['site_url'], $token, $start, $end, [ 'date' ] );
		if ( is_wp_error( $totals ) ) {
			self::remember( [ 'error' => $totals->get_error_message() ] );
			return $totals;
		}

		$queries = self::query( $settings['site_url'], $token, $start, $end, [ 'date', 'query' ] );
		if ( is_wp_error( $queries ) ) {
			self::remember( [ 'error' => $queries->get_error_message() ] );
			return $queries;
		}

		// The same searches by landing page, for each post's own screen. Fetched
		// separately rather than derived: Google aggregates by page and by site
		// differently, so neither set can be rebuilt from the other.
		$pages = self::query( $settings['site_url'], $token, $start, $end, [ 'date', 'query', 'page' ] );
		if ( is_wp_error( $pages ) ) {
			self::remember( [ 'error' => $pages->get_error_message() ] );
			return $pages;
		}

		global $wpdb;
		$table = self::table_name();

		// Group everything by the day it belongs to, then replace one day at a
		// time. The whole window used to be deleted up front and refilled row
		// by row, so a PHP timeout partway through the refill left the deleted
		// days gone for good: the daily sync only re-reads the last week, so
		// anything older was never fetched again. Now a run that dies loses at
		// most the day it was in the middle of.
		$by_day = [];

		foreach ( $totals as $row ) {
			$date = (string) ( $row['keys'][0] ?? '' );
			if ( self::in_window( $date, $start, $end ) ) {
				$by_day[ $date ][] = [ '', $row ];
			}
		}

		foreach ( $queries as $row ) {
			$date  = (string) ( $row['keys'][0] ?? '' );
			$query = (string) ( $row['keys'][1] ?? '' );
			if ( '' !== $query && self::in_window( $date, $start, $end ) ) {
				$by_day[ $date ][] = [ $query, $row ];
			}
		}

		$pages_by_day = [];
		foreach ( $pages as $row ) {
			$date  = (string) ( $row['keys'][0] ?? '' );
			$query = (string) ( $row['keys'][1] ?? '' );
			$path  = self::page_path( (string) ( $row['keys'][2] ?? '' ) );
			if ( '' !== $query && '' !== $path && self::in_window( $date, $start, $end ) ) {
				$pages_by_day[ $date ][] = [ $path, $query, $row ];
			}
		}

		$page_table = self::page_table_name();
		$has_pages  = self::page_table_exists();

		$written      = 0;
		$page_written = 0;

		// A day can carry page rows without query rows (and the reverse), so walk
		// every day either set mentions rather than only the query set's.
		$all_days = array_unique( array_merge( array_keys( $by_day ), array_keys( $pages_by_day ) ) );
		sort( $all_days );

		foreach ( $all_days as $date ) {
			$rows = $by_day[ $date ] ?? [];
			// One day, one transaction: the delete and its replacements commit
			// together or not at all.
			$wpdb->query( 'START TRANSACTION' );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- resync
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE date = %s", $date ) );

			$failed = '' !== (string) $wpdb->last_error;

			if ( ! $failed && $has_pages ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- resync
				$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE date = %s', $page_table, $date ) );
				$failed = '' !== (string) $wpdb->last_error;
			}

			// Written in batches. One INSERT per row meant over half a million
			// round trips for a 90-day pull once the row cap stopped truncating
			// it, which outran the time limit an admin request gets on most
			// hosts. The importers batch the same way for the same reason.
			if ( ! $failed ) {
				$n = self::store_batch( $date, $rows );
				if ( $n < 0 ) {
					$failed = true;
				} else {
					$written += $n;
				}
			}

			if ( ! $failed && $has_pages ) {
				$n = self::store_page_batch( $date, $pages_by_day[ $date ] ?? [] );
				if ( $n < 0 ) {
					$failed = true;
				} else {
					$page_written += $n;
				}
			}

			if ( $failed ) {
				$wpdb->query( 'ROLLBACK' );
			} else {
				$wpdb->query( 'COMMIT' );
			}

			if ( $failed ) {
				self::remember( [ 'error' => sprintf( 'sync failed while writing %s', $date ) ] );
				return new \WP_Error(
					'vireo_gsc_write',
					sprintf(
						/* translators: %s: a date */
						__( 'Could not store the data for %s. Nothing was lost; run the sync again.', 'vireo-analytics' ),
						$date
					)
				);
			}
		}

		// A background slice covers older days than the last full sync did, so
		// widen the stored range rather than replacing it. Otherwise the status
		// line would report only the last slice: fourteen days of history on a
		// site that has ninety.
		$prior = self::state();
		$from  = $start;
		$to    = $end;
		if ( $is_old ) {
			if ( ! empty( $prior['from'] ) && (string) $prior['from'] < $from ) {
				$from = (string) $prior['from'];
			}
			if ( ! empty( $prior['to'] ) && (string) $prior['to'] > $to ) {
				$to = (string) $prior['to'];
			}
		}

		self::remember(
			[
				'error'    => '',
				'synced'   => time(),
				'from'     => $from,
				'to'       => $to,
				'rows'     => $written,
				'pages'    => $page_written,
			]
		);

		return [
			'days'  => $days,
			'rows'  => $written,
			'pages' => $page_written,
		];
	}

	/**
	 * Only keep what we asked for.
	 *
	 * The window is cleared before writing so a re-sync replaces rather than
	 * accumulates. A row outside it would be inserted but never cleared by a
	 * later run, so it would sit there forever holding whatever Google said
	 * the first time.
	 */
	private static function in_window( string $date, string $start, string $end ): bool {
		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) && $date >= $start && $date <= $end;
	}

	/** Rows per INSERT. Matches Importer_Base::MAX_BULK_ROWS. */
	private const BATCH = 500;

	/**
	 * Replace one day's site-wide rows. Returns the rows written, or -1 if
	 * the database refused a batch, in which case the caller rolls back.
	 *
	 * Later rows overwrite earlier ones on a key collision, as the single-row
	 * version did: queries are cut to 190 characters, so two long ones that
	 * share that prefix land on one key.
	 *
	 * @param array<int,array{0:string,1:array<string,mixed>}> $rows
	 */
	private static function store_batch( string $date, array $rows ): int {
		global $wpdb;
		$table   = self::table_name();
		$written = 0;

		foreach ( array_chunk( $rows, self::BATCH ) as $chunk ) {
			$values = [];
			foreach ( $chunk as $pair ) {
				// The key is the whole query and only the stored text is cut to
				// fit the column. Hashing the cut text made any two queries that
				// share their first 190 characters the same row.
				$full  = (string) $pair[0];
				$query = mb_substr( $full, 0, 190 );
				$row   = $pair[1];
				array_push(
					$values,
					$date,
					md5( $full ),
					$query,
					(int) round( (float) ( $row['clicks'] ?? 0 ) ),
					(int) round( (float) ( $row['impressions'] ?? 0 ) ),
					(int) round( (float) ( $row['position'] ?? 0 ) * 10 )
				);
			}
			$ph = rtrim( str_repeat( '(%s,%s,%s,%d,%d,%d),', count( $chunk ) ), ',' );

			$wpdb->last_error = '';
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,PluginCheck.Security.DirectDB.UnescapedDBParameter -- bulk upsert; $ph is generated above from %s/%d markers only
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO %i (date, query_hash, query, clicks, impressions, position_x10)
					 VALUES {$ph}
					 ON DUPLICATE KEY UPDATE
						clicks = VALUES(clicks),
						impressions = VALUES(impressions),
						position_x10 = VALUES(position_x10)",
					$table,
					...$values
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,PluginCheck.Security.DirectDB.UnescapedDBParameter
			if ( '' !== (string) $wpdb->last_error ) {
				return -1;
			}
			$written += count( $chunk );
		}

		return $written;
	}

	/**
	 * Replace one day's per-page rows. Same contract as store_batch().
	 *
	 * Collisions add rather than overwrite here. page_path() folds address
	 * variants Google reports separately, "/post" and "/post/" for one, into
	 * a single key, and those are real visits to the same page that belong
	 * summed.
	 *
	 * @param array<int,array{0:string,1:string,2:array<string,mixed>}> $rows
	 */
	private static function store_page_batch( string $date, array $rows ): int {
		global $wpdb;
		$table   = self::page_table_name();
		$written = 0;

		foreach ( array_chunk( $rows, self::BATCH ) as $chunk ) {
			$values = [];
			foreach ( $chunk as $triple ) {
				$path  = (string) $triple[0];
				$full  = (string) $triple[1];
				$query = mb_substr( $full, 0, 190 );
				$row   = $triple[2];
				array_push(
					$values,
					$date,
					md5( $path ),
					md5( $full ),
					$path,
					$query,
					(int) round( (float) ( $row['clicks'] ?? 0 ) ),
					(int) round( (float) ( $row['impressions'] ?? 0 ) ),
					(int) round( (float) ( $row['position'] ?? 0 ) * 10 )
				);
			}
			$ph = rtrim( str_repeat( '(%s,%s,%s,%s,%s,%d,%d,%d),', count( $chunk ) ), ',' );

			$wpdb->last_error = '';
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,PluginCheck.Security.DirectDB.UnescapedDBParameter -- bulk upsert; $ph is generated above from %s/%d markers only
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO %i (date, page_hash, query_hash, page, query, clicks, impressions, position_x10)
					 VALUES {$ph}
					 ON DUPLICATE KEY UPDATE
						clicks = clicks + VALUES(clicks),
						impressions = impressions + VALUES(impressions),
						position_x10 = VALUES(position_x10)",
					$table,
					...$values
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,PluginCheck.Security.DirectDB.UnescapedDBParameter
			if ( '' !== (string) $wpdb->last_error ) {
				return -1;
			}
			$written += count( $chunk );
		}

		return $written;
	}

	public static function page_table_name(): string {
		return Sites::table( self::PAGE_TABLE );
	}

	/**
	 * Whether the per-page table exists yet. A site that updated but has not
	 * run the schema upgrade must still sync its site-wide rows, so the page
	 * writes are skipped rather than taking the whole run down with them.
	 */
	public static function page_table_exists(): bool {
		global $wpdb;
		static $exists = null;
		if ( null === $exists ) {
			$table = self::page_table_name();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- schema probe
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
		}
		return $exists;
	}

	/**
	 * The one way a page address becomes a lookup key, used on both sides.
	 *
	 * Search Console reports full URLs and a post knows its permalink, and the
	 * two have to meet. Host, scheme, query string and fragment go; the
	 * trailing slash goes too, because Google reports whichever form it
	 * crawled and permalinks carry one depending on settings. The root stays
	 * "/" so the homepage still has a key.
	 */
	public static function page_path( string $url ): string {
		if ( '' === trim( $url ) ) {
			return '';
		}
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$path = '/' . trim( $path, '/' );
		return mb_substr( $path, 0, 190 );
	}

	/**
	 * What people searched before landing on one page.
	 *
	 * @return array<int,array{query:string,clicks:int,impressions:int,position:float}>
	 */
	public static function page_queries( string $path, string $start, string $end, int $limit = 15 ): array {
		global $wpdb;

		$path = self::page_path( $path );
		if ( '' === $path || ! self::page_table_exists() ) {
			return [];
		}
		$table = self::page_table_name();
		$limit = max( 1, min( 100, $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- reporting read
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT MAX(query) AS query,
					SUM(clicks) AS clicks,
					SUM(impressions) AS impressions,
					SUM(position_x10 * impressions) AS weighted
				 FROM %i
				 WHERE page_hash = %s AND date BETWEEN %s AND %s
				 GROUP BY query_hash
				 ORDER BY clicks DESC, impressions DESC
				 LIMIT %d',
				$table,
				md5( $path ),
				$start,
				$end,
				$limit
			),
			ARRAY_A
		);

		return array_map(
			static function ( array $r ): array {
				$impressions = max( 1, (int) $r['impressions'] );
				return [
					'query'       => (string) $r['query'],
					'clicks'      => (int) $r['clicks'],
					'impressions' => (int) $r['impressions'],
					'position'    => round( ( (float) $r['weighted'] / 10 ) / $impressions, 1 ),
				];
			},
			is_array( $rows ) ? $rows : []
		);
	}

	/**
	 * The page Google most often showed for each of these searches, for the
	 * site-wide card. "Landed on" would be wrong: a search with no clicks
	 * reached nothing, it was only displayed. One query can show several
	 * pages, so "most" means most clicks, then most impressions.
	 *
	 * Takes query keys rather than query text: the text shown on the card is
	 * cut to 190 characters, so a key rebuilt from it would not match a long
	 * query's real one.
	 *
	 * @param string[] $hashes Query keys, as returned by top_queries().
	 * @return array<string,string> query key => path
	 */
	public static function landing_pages( array $hashes, string $start, string $end ): array {
		global $wpdb;

		$hashes = array_values( array_filter( array_map( 'strval', $hashes ) ) );
		if ( empty( $hashes ) || ! self::page_table_exists() ) {
			return [];
		}
		$table = self::page_table_name();
		$in    = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,PluginCheck.Security.DirectDB.UnescapedDBParameter -- reporting read; $in is generated above from %s markers only
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT query_hash, page, SUM(clicks) AS clicks, SUM(impressions) AS impressions
				 FROM %i
				 WHERE query_hash IN ({$in}) AND date BETWEEN %s AND %s
				 GROUP BY query_hash, page_hash, page",
				...array_merge( [ $table ], $hashes, [ $start, $end ] )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,PluginCheck.Security.DirectDB.UnescapedDBParameter

		$best = [];
		foreach ( (array) $rows as $r ) {
			$q     = (string) $r['query_hash'];
			$score = [ (int) $r['clicks'], (int) $r['impressions'] ];
			if ( ! isset( $best[ $q ] ) || $score > $best[ $q ]['score'] ) {
				$best[ $q ] = [ 'score' => $score, 'page' => (string) $r['page'] ];
			}
		}

		return array_map( static fn( array $b ): string => $b['page'], $best );
	}

	/**
	 * One searchAnalytics query, following pagination.
	 *
	 * @param string[] $dimensions
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	private static function query( string $site, string $token, string $start, string $end, array $dimensions ) {
		$rows      = [];
		$start_row = 0;

		do {
			$response = wp_remote_post(
				sprintf( self::API, rawurlencode( $site ) ),
				[
					'timeout' => self::TIMEOUT,
					'headers' => [
						'Authorization' => 'Bearer ' . $token,
						'Content-Type'  => 'application/json',
					],
					'body'    => wp_json_encode(
						[
							'startDate'  => $start,
							'endDate'    => $end,
							'dimensions' => $dimensions,
							'rowLimit'   => self::ROW_LIMIT,
							'startRow'   => $start_row,
						]
					),
				]
			);

			if ( is_wp_error( $response ) ) {
				return new \WP_Error( 'vireo_gsc_http', $response->get_error_message() );
			}

			$code    = (int) wp_remote_retrieve_response_code( $response );
			$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

			if ( 200 !== $code ) {
				$message = is_array( $decoded ) ? (string) ( $decoded['error']['message'] ?? '' ) : '';

				if ( 403 === $code ) {
					$message = __( 'Google refused the request. Check the service account has access to this Search Console property.', 'vireo-analytics' );
				} elseif ( 404 === $code ) {
					$message = __( 'Search Console has no property with that address. Copy it exactly as it appears there.', 'vireo-analytics' );
				}

				return new \WP_Error(
					'vireo_gsc_api',
					'' !== $message ? $message : sprintf(
						/* translators: %d: HTTP status code */
						__( 'Search Console returned an unexpected response (HTTP %d).', 'vireo-analytics' ),
						$code
					)
				);
			}

			if ( ! is_array( $decoded ) ) {
				return new \WP_Error( 'vireo_gsc_parse', __( 'Search Console returned a response we could not read.', 'vireo-analytics' ) );
			}

			$batch = (array) ( $decoded['rows'] ?? [] );
			foreach ( $batch as $row ) {
				$rows[] = (array) $row;
			}

			$start_row += self::ROW_LIMIT;
		} while ( count( $batch ) === self::ROW_LIMIT && $start_row < self::MAX_ROWS );

		return $rows;
	}

	/**
	 * @param array<string,mixed> $patch
	 */
	private static function remember( array $patch ): void {
		$state = (array) get_option( self::STATE, [] );
		update_option( self::STATE, array_merge( $state, $patch ), false );
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function state(): array {
		return (array) get_option( self::STATE, [] );
	}

	/**
	 * Site totals for a range.
	 *
	 * @return array{clicks:int,impressions:int,position:float}|null
	 */
	public static function summary( string $start, string $end ): ?array {
		global $wpdb;

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- schema probe, uncacheable
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- reporting read
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT SUM(clicks) AS clicks, SUM(impressions) AS impressions,
					SUM(position_x10 * impressions) AS weighted, SUM(impressions) AS imps
				 FROM {$table}
				 WHERE date BETWEEN %s AND %s AND query = ''",
				$start,
				$end
			),
			ARRAY_A
		);

		$impressions = (int) ( $row['impressions'] ?? 0 );
		if ( $impressions < 1 ) {
			return null;
		}

		return [
			'clicks'      => (int) ( $row['clicks'] ?? 0 ),
			'impressions' => $impressions,
			// Weighted by impressions: a query seen twice at position 90 should
			// not drag the average as hard as one seen ten thousand times at 3.
			'position'    => round( ( (float) ( $row['weighted'] ?? 0 ) / 10 ) / max( 1, $impressions ), 1 ),
		];
	}

	/**
	 * @return array<int,array{query:string,clicks:int,impressions:int,position:float}>
	 */
	public static function top_queries( string $start, string $end, int $limit = 10 ): array {
		global $wpdb;

		$table = self::table_name();
		$limit = max( 1, min( 100, $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- schema probe, uncacheable
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return [];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- reporting read
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT query_hash, MAX(query) AS query,
					SUM(clicks) AS clicks,
					SUM(impressions) AS impressions,
					SUM(position_x10 * impressions) AS weighted
				 FROM {$table}
				 WHERE date BETWEEN %s AND %s AND query <> ''
				 GROUP BY query_hash
				 ORDER BY clicks DESC, impressions DESC
				 LIMIT %d",
				$start,
				$end,
				$limit
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return [];
		}

		return array_map(
			static function ( array $r ): array {
				$impressions = max( 1, (int) $r['impressions'] );

				return [
					'query'       => (string) $r['query'],
					'query_hash'  => (string) $r['query_hash'],
					'clicks'      => (int) $r['clicks'],
					'impressions' => (int) $r['impressions'],
					'position'    => round( ( (float) $r['weighted'] / 10 ) / $impressions, 1 ),
				];
			},
			$rows
		);
	}

	// -------------------------------------------------------------------------
	// Admin actions
	// -------------------------------------------------------------------------

	/** Where the flash message for the current user is parked between requests. */
	private static function flash_key(): string {
		return 'vireo_gsc_flash_' . get_current_user_id();
	}

	/**
	 * Hand the message over in a transient rather than in the URL.
	 *
	 * The text used to travel as a query argument and was rendered straight
	 * into an admin notice. It was escaped, so nothing executed, but anyone
	 * could still hand an administrator a link that put their words in a red
	 * box on the settings screen, which is a serviceable phishing primitive.
	 * A nonce would fix it; not putting attacker-reachable text in the URL at
	 * all fixes it better.
	 */
	private static function settings_redirect( string $notice, string $message = '' ): void {
		if ( '' !== $message ) {
			set_transient( self::flash_key(), $message, 60 );
		}

		wp_safe_redirect( Settings::url( 'integrations', [ 'vireo_gsc' => $notice ] ) . '#vireo-gsc' );
		exit;
	}

	/** Read and clear the flash message, if this user has one waiting. */
	public static function take_flash(): string {
		$message = get_transient( self::flash_key() );

		if ( ! is_string( $message ) || '' === $message ) {
			return '';
		}

		delete_transient( self::flash_key() );

		return $message;
	}

	private static function guard( string $nonce_action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'vireo-analytics' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( $nonce_action );
	}

	/**
	 * Queue the older history behind a quick sync of the recent days.
	 *
	 * The newest slice is what someone clicking "sync" wants to see, so the
	 * caller fetches that inline. Everything older loads here in slices that
	 * each fit comfortably inside a request, newest first, so a run that
	 * stalls has still filled the recent past before the distant one.
	 */
	public static function start_backfill( int $days, int $already_have = 0 ): void {
		$latest = strtotime( gmdate( 'Y-m-d', time() - ( self::LAG_DAYS * DAY_IN_SECONDS ) ) );
		$floor  = gmdate( 'Y-m-d', $latest - ( ( max( 1, $days ) - 1 ) * DAY_IN_SECONDS ) );
		$cursor = gmdate( 'Y-m-d', $latest - ( max( 0, $already_have ) * DAY_IN_SECONDS ) );

		if ( $cursor < $floor ) {
			delete_option( self::BACKFILL_OPTION );
			return;
		}

		update_option( self::BACKFILL_OPTION, [ 'cursor' => $cursor, 'floor' => $floor ], false );
		wp_clear_scheduled_hook( self::BACKFILL_HOOK );
		wp_schedule_single_event( time(), self::BACKFILL_HOOK );
	}

	/**
	 * One slice of history, then queue the next until the floor is reached.
	 */
	public static function run_backfill(): void {
		$job = get_option( self::BACKFILL_OPTION );
		if ( ! is_array( $job ) || empty( $job['cursor'] ) || empty( $job['floor'] ) ) {
			return;
		}

		$cursor = (string) $job['cursor'];
		$floor  = (string) $job['floor'];
		$start  = max( $floor, gmdate( 'Y-m-d', strtotime( $cursor ) - ( ( self::BACKFILL_SLICE - 1 ) * DAY_IN_SECONDS ) ) );
		$days   = (int) round( ( strtotime( $cursor ) - strtotime( $start ) ) / DAY_IN_SECONDS ) + 1;

		$result = self::sync( $days, $cursor );

		if ( is_wp_error( $result ) ) {
			// Keep the cursor and try the same slice later. sync() has already
			// put the reason where the settings screen shows it.
			wp_schedule_single_event( time() + 10 * MINUTE_IN_SECONDS, self::BACKFILL_HOOK );
			return;
		}

		$next = gmdate( 'Y-m-d', strtotime( $start ) - DAY_IN_SECONDS );
		if ( $next < $floor ) {
			delete_option( self::BACKFILL_OPTION );
			return;
		}

		update_option( self::BACKFILL_OPTION, [ 'cursor' => $next, 'floor' => $floor ], false );
		wp_schedule_single_event( time() + 30, self::BACKFILL_HOOK );
	}

	/** Whether older history is still loading, for the settings screen. */
	public static function backfill_pending(): bool {
		return is_array( get_option( self::BACKFILL_OPTION ) );
	}

	public static function handle_save(): void {
		self::guard( 'vireo_gsc_save' );

		// The key is JSON with newlines inside the private key, so it must not
		// go through sanitize_text_field(), which would flatten them and break
		// every signature. It is validated by being parsed instead.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard()
		$key = isset( $_POST['key_json'] ) ? trim( wp_unslash( (string) $_POST['key_json'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard()
		$site = isset( $_POST['site_url'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['site_url'] ) ) : '';

		$result = self::save_settings(
			[
				'key_json' => $key,
				'site_url' => $site,
			]
		);

		if ( is_wp_error( $result ) ) {
			self::settings_redirect( 'error', $result->get_error_message() );
		}

		self::settings_redirect( 'saved' );
	}

	public static function handle_sync(): void {
		self::guard( 'vireo_gsc_sync' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard()
		$days = isset( $_POST['days'] ) ? absint( $_POST['days'] ) : 0;
		$days = $days > 0 ? min( 480, $days ) : self::REFRESH_DAYS;

		// The recent week inline, so the click has something to show for it,
		// and the rest in the background. Pulling all of it here outran the
		// request time limit on plenty of hosts.
		$result = self::sync( min( $days, self::REFRESH_DAYS ) );

		if ( is_wp_error( $result ) ) {
			self::settings_redirect( 'error', $result->get_error_message() );
		}

		if ( $days > self::REFRESH_DAYS ) {
			self::start_backfill( $days, self::REFRESH_DAYS );
		}

		self::settings_redirect( 'synced' );
	}

	public static function handle_disconnect(): void {
		self::guard( 'vireo_gsc_disconnect' );
		self::clear_settings();
		self::settings_redirect( 'disconnected' );
	}
}
