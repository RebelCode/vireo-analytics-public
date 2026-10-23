<?php
/**
 * Shared base class for migration importers.
 *
 * Contract:
 *   - slug()           Machine identifier for AJAX routing ('koko', 'jetpack').
 *   - name()           Human name for UI copy.
 *   - is_available()   Is there anything to import? (source rows present, plugin active)
 *   - count_records()  Estimated total units of work for the progress bar.
 *                      Units are importer-defined; for Koko it's days of data,
 *                      for Jetpack it's days in the requested range.
 *   - import_chunk()   Process one unit of work. Returns:
 *                        [ 'done' => bool, 'processed' => int,
 *                          'total' => int, 'next_offset' => int,
 *                          'label' => string, 'error' => ?string ]
 *
 * Upsert helpers use INSERT ... ON DUPLICATE KEY UPDATE with
 * `visitors = visitors + VALUES(visitors)`. This is idempotent-across-chunks
 * but NOT idempotent across reruns — running the same import twice doubles
 * the numbers. The UI warns about this.
 */

namespace VireoAnalytics\Importers;

use VireoAnalytics\Upserter;

defined( 'ABSPATH' ) || exit;

abstract class Importer_Base {

	/** Maximum rows to pack into a single bulk INSERT. Keeps prepared queries
	 * well under MySQL's default max_allowed_packet (16 MB) with plenty of
	 * headroom for long paths/URLs. */
	protected const MAX_BULK_ROWS = 500;

	/**
	 * What this request wrote, so a finished import can say what it brought
	 * in. `processed` can't: most importers report it as a running offset, so
	 * summing it across chunks overstated the total.
	 *
	 * @var array{first:string,last:string,days:int,pageviews:int}
	 */
	private array $written = [ 'first' => '', 'last' => '', 'days' => 0, 'pageviews' => 0 ];

	/**
	 * @return array{first:string,last:string,days:int,pageviews:int}
	 */
	public function written(): array {
		return $this->written;
	}

	/**
	 * First day this run must not write, YYYY-MM-DD, or '' for no limit.
	 *
	 * Set to the day Vireo Analytics started recording, so an import fills the
	 * history before it and leaves Vireo's own days alone. Enforced here
	 * rather than in each importer because every write goes through these
	 * helpers, whatever the source.
	 *
	 * @var string
	 */
	private string $cutoff = '';

	public function set_cutoff( string $date ): void {
		$this->cutoff = $date;
	}

	/**
	 * The run this request belongs to. Per-run state (the replay ledger, the
	 * cutoff, a staged upload) is stored under it, so two runs, or a tab left
	 * open from an earlier one, can't read or overwrite each other's.
	 *
	 * @var string
	 */
	protected string $run = '';

	public function set_run( string $run ): void {
		$this->run = $run;
	}

	/** A fresh run token: when it started, then eight random characters. */
	public static function new_run(): string {
		return time() . '-' . strtolower( wp_generate_password( 8, false ) );
	}

	/** The token if it is well formed, '' otherwise. It ends up in option names. */
	public static function valid_run( string $raw ): string {
		return 1 === preg_match( '/^\d{10}-[a-z0-9]{8}$/', $raw ) ? $raw : '';
	}

	public static function run_started( string $run ): int {
		return '' === self::valid_run( $run ) ? 0 : (int) substr( $run, 0, 10 );
	}

	public static function run_option( string $prefix, string $slug, string $run ): string {
		return $prefix . $slug . '_' . $run;
	}

	private function allowed( string $date ): bool {
		return '' === $this->cutoff || $date < $this->cutoff;
	}

	/**
	 * @param array<int, array{date:string}> $rows
	 * @return array<int, array{date:string}>
	 */
	protected function before_cutoff( array $rows ): array {
		if ( '' === $this->cutoff ) {
			return $rows;
		}
		return array_values( array_filter( $rows, fn( array $r ): bool => $this->allowed( (string) $r['date'] ) ) );
	}

	abstract public function slug(): string;

	abstract public function name(): string;

	abstract public function is_available(): bool;

	/** One sentence for the import screen naming what comes across, or ''. */
	public function carries(): string {
		return '';
	}

	/**
	 * Does the source table exist and hold at least one row?
	 *
	 * Existence alone is not enough. A plugin that has been deactivated and
	 * deleted often leaves its tables behind, empty, and offering to import
	 * from one of those promises data that isn't there.
	 */
	protected static function table_has_rows( string $table ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- existence probe
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-row probe on the source table
		return null !== $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i LIMIT 1', $table ) );
	}

	protected static function column_exists( string $table, string $column ): bool {
		global $wpdb;
		// SHOW COLUMNS on a missing table leaves an error behind, and the next
		// assert_no_db_error() would abort the import over a probe.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- existence probe
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- schema probe on the source table
		return null !== $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, $column ) );
	}

	abstract public function count_records(): int;

	/**
	 * @param array<string,mixed> $params Importer-specific params (API key, date range, etc).
	 * @param int $offset Current progress offset (caller-supplied).
	 * @param int $limit  Per-chunk limit hint (ignored by importers that use a fixed date window).
	 *
	 * @return array{
	 *   done: bool,
	 *   processed: int,
	 *   total: int,
	 *   next_offset: int,
	 *   label: string,
	 *   error?: string
	 * }
	 */
	abstract public function import_chunk( array $params, int $offset, int $limit ): array;

	/**
	 * Upsert a single date's site-level totals. Idempotent per-chunk, additive
	 * across reruns.
	 */
	protected function upsert_site_stats( string $date, int $visitors, int $pageviews ): void {
		if ( ( $visitors <= 0 && $pageviews <= 0 ) || ! $this->allowed( $date ) ) {
			return;
		}
		global $wpdb;
		$table = \VireoAnalytics\Sites::table( 'site_stats' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- import upsert; cache not relevant
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (date, pageviews, visitors) VALUES (%s, %d, %d)
				 ON DUPLICATE KEY UPDATE pageviews = pageviews + VALUES(pageviews), visitors = visitors + VALUES(visitors)',
				$table,
				$date,
				$pageviews,
				$visitors
			)
		);
		$this->assert_no_db_error();

		if ( '' === $this->written['first'] || $date < $this->written['first'] ) {
			$this->written['first'] = $date;
		}
		if ( $date > $this->written['last'] ) {
			$this->written['last'] = $date;
		}
		++$this->written['days'];
		$this->written['pageviews'] += max( 0, $pageviews );
	}

	/**
	 * Bulk upsert for post_stats. Groups rows by the shared paths table via
	 * Upserter, then issues a single INSERT ... ON DUPLICATE KEY UPDATE.
	 *
	 * @param array<int, array{date:string,post_id:int,path:string,visitors:int,pageviews:int}> $rows
	 */
	protected function upsert_post_stats_bulk( array $rows ): void {
		$rows = $this->before_cutoff( $rows );
		if ( empty( $rows ) ) {
			return;
		}

		global $wpdb;
		$table    = \VireoAnalytics\Sites::table( 'post_stats' );
		$upserter = new Upserter( 'paths', 'path' );
		$paths    = array_values( array_unique( array_column( $rows, 'path' ) ) );
		$path_ids = $upserter->upsert( $paths );

		// Chunk to keep prepared queries a sane size.
		foreach ( array_chunk( $rows, self::MAX_BULK_ROWS ) as $batch ) {
			$values = [];
			$count  = 0;
			foreach ( $batch as $r ) {
				if ( ! isset( $path_ids[ $r['path'] ] ) ) {
					continue;
				}
				array_push(
					$values,
					$r['date'],
					(int) $r['post_id'],
					(int) $path_ids[ $r['path'] ],
					(int) $r['pageviews'],
					(int) $r['visitors']
				);
				++$count;
			}
			if ( 0 === $count ) {
				continue;
			}
			$placeholders = rtrim( str_repeat( '(%s,%d,%d,%d,%d),', $count ), ',' );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter -- bulk upsert; $placeholders is generated above with only %s/%d markers
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO %i (date, post_id, path_id, pageviews, visitors) VALUES {$placeholders}
					 ON DUPLICATE KEY UPDATE pageviews = pageviews + VALUES(pageviews), visitors = visitors + VALUES(visitors)",
					$table,
					...$values
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter
			$this->assert_no_db_error();
		}
	}

	/**
	 * Bulk upsert for referrer_stats. Takes an array of [date, host, visitors, pageviews].
	 *
	 * @param array<int, array{date:string,host:string,visitors:int,pageviews:int}> $rows
	 */
	protected function upsert_referrer_stats_bulk( array $rows ): void {
		$rows = $this->before_cutoff( $rows );
		if ( empty( $rows ) ) {
			return;
		}

		global $wpdb;
		$table    = \VireoAnalytics\Sites::table( 'referrer_stats' );
		$upserter = new Upserter( 'referrer_urls', 'url' );
		$urls     = array_values( array_unique( array_column( $rows, 'host' ) ) );
		$url_ids  = $upserter->upsert( $urls );

		foreach ( array_chunk( $rows, self::MAX_BULK_ROWS ) as $batch ) {
			$values = [];
			$count  = 0;
			foreach ( $batch as $r ) {
				if ( ! isset( $url_ids[ $r['host'] ] ) ) {
					continue;
				}
				array_push(
					$values,
					$r['date'],
					(int) $url_ids[ $r['host'] ],
					(int) $r['pageviews'],
					(int) $r['visitors']
				);
				++$count;
			}
			if ( 0 === $count ) {
				continue;
			}
			$placeholders = rtrim( str_repeat( '(%s,%d,%d,%d),', $count ), ',' );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter -- bulk upsert; $placeholders is generated above with only %s/%d markers
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO %i (date, url_id, pageviews, visitors) VALUES {$placeholders}
					 ON DUPLICATE KEY UPDATE pageviews = pageviews + VALUES(pageviews), visitors = visitors + VALUES(visitors)",
					$table,
					...$values
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter
			$this->assert_no_db_error();
		}
	}

	/**
	 * Upsert a single geo row.
	 */
	protected function upsert_geo_stats( string $date, string $country_code, int $visitors, int $pageviews ): void {
		if ( ( $visitors <= 0 && $pageviews <= 0 ) || ! $this->allowed( $date ) ) {
			return;
		}

		// Importers write straight into the reporting table, bypassing the
		// exclusion the aggregator applies to live traffic. Bringing a history
		// across would reinject the countries the owner had already chosen to
		// discard, into the same card that was correctly hiding them, and
		// nothing on screen would say where those rows came from.
		$excluded = function_exists( 'vireo_get_exclusions' )
			? (array) ( vireo_get_exclusions()['countries'] ?? [] )
			: [];
		if ( ! empty( $excluded ) && in_array( strtoupper( $country_code ), $excluded, true ) ) {
			return;
		}

		global $wpdb;
		$table = \VireoAnalytics\Sites::table( 'geo_stats' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- import upsert; cache not relevant
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (date, country_code, pageviews, visitors) VALUES (%s, %s, %d, %d)
				 ON DUPLICATE KEY UPDATE pageviews = pageviews + VALUES(pageviews), visitors = visitors + VALUES(visitors)',
				$table,
				$date,
				strtoupper( $country_code ),
				$pageviews,
				$visitors
			)
		);
		$this->assert_no_db_error();
	}

	/**
	 * Upsert a single device row.
	 */
	protected function upsert_device_stats( string $date, string $browser, string $os, string $device_type, int $visitors, int $pageviews ): void {
		if ( ( $visitors <= 0 && $pageviews <= 0 ) || ! $this->allowed( $date ) ) {
			return;
		}
		global $wpdb;
		$table = \VireoAnalytics\Sites::table( 'device_stats' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- import upsert; cache not relevant
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (date, browser, os, device_type, pageviews, visitors) VALUES (%s, %s, %s, %s, %d, %d)
				 ON DUPLICATE KEY UPDATE pageviews = pageviews + VALUES(pageviews), visitors = visitors + VALUES(visitors)',
				$table,
				$date,
				$browser,
				$os,
				$device_type,
				$pageviews,
				$visitors
			)
		);
		$this->assert_no_db_error();
	}

	/**
	 * Bulk upsert for post_referrer_stats: which external host sent readers
	 * to which post.
	 *
	 * @param array<int, array{date:string,post_id:int,host:string,visitors:int,pageviews:int}> $rows
	 */
	protected function upsert_post_referrer_stats_bulk( array $rows ): void {
		$rows = array_values(
			array_filter(
				$this->before_cutoff( $rows ),
				static fn( array $r ): bool => (int) $r['post_id'] > 0 && '' !== (string) $r['host'] && ( (int) $r['pageviews'] > 0 || (int) $r['visitors'] > 0 )
			)
		);
		if ( empty( $rows ) ) {
			return;
		}

		global $wpdb;
		$table    = \VireoAnalytics\Sites::table( 'post_referrer_stats' );
		$upserter = new Upserter( 'referrer_urls', 'url' );
		$url_ids  = $upserter->upsert( array_values( array_unique( array_column( $rows, 'host' ) ) ) );

		foreach ( array_chunk( $rows, self::MAX_BULK_ROWS ) as $batch ) {
			$values = [];
			$count  = 0;
			foreach ( $batch as $r ) {
				if ( ! isset( $url_ids[ $r['host'] ] ) ) {
					continue;
				}
				array_push( $values, $r['date'], (int) $r['post_id'], (int) $url_ids[ $r['host'] ], (int) $r['pageviews'], (int) $r['visitors'] );
				++$count;
			}
			if ( 0 === $count ) {
				continue;
			}
			$placeholders = rtrim( str_repeat( '(%s,%d,%d,%d,%d),', $count ), ',' );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter -- bulk upsert; $placeholders is generated above with only %s/%d markers
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO %i (date, post_id, url_id, pageviews, visitors) VALUES {$placeholders}
					 ON DUPLICATE KEY UPDATE pageviews = pageviews + VALUES(pageviews), visitors = visitors + VALUES(visitors)",
					$table,
					...$values
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter
			$this->assert_no_db_error();
		}
	}

	/**
	 * Upsert event rows in the shape the tracker's own events take: outbound
	 * clicks as `outbound` with the host as the value, downloads as `download`
	 * with the file name, anything else under its own name.
	 *
	 * @param array<int, array{date:string,name:string,value:string,count:int,unique_count:int}> $rows
	 */
	protected function upsert_event_stats_bulk( array $rows ): void {
		global $wpdb;
		$table = \VireoAnalytics\Sites::table( 'event_stats' );
		foreach ( $this->before_cutoff( $rows ) as $r ) {
			// The same rule the tracker applies to a beacon's event name, so an
			// imported "Outbound Click" merges with a recorded "outboundclick"
			// instead of sitting beside it, and so nothing a visitor typed into
			// another plugin's form reaches the dashboard as markup.
			$name = self::event_name( (string) $r['name'] );
			if ( '' === $name || (int) $r['count'] <= 0 ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- import upsert; cache not relevant
			$wpdb->query(
				$wpdb->prepare(
					'INSERT INTO %i (date, event_name, event_value, count, unique_count) VALUES (%s, %s, %s, %d, %d)
					 ON DUPLICATE KEY UPDATE count = count + VALUES(count), unique_count = unique_count + VALUES(unique_count)',
					$table,
					$r['date'],
					$name,
					\VireoAnalytics\Collector::clip( self::plain_text( (string) $r['value'] ), 100 ),
					(int) $r['count'],
					(int) $r['unique_count']
				)
			);
			$this->assert_no_db_error();
		}
	}

	/**
	 * Upsert missing-page hits, keyed the way the 404 tracker keys them so an
	 * imported row and a recorded one for the same path and referrer merge.
	 *
	 * @param array<int, array{date:string,path:string,referrer:string,internal_ref:string,hits:int}> $rows
	 */
	protected function upsert_404_stats_bulk( array $rows ): void {
		global $wpdb;
		$table = \VireoAnalytics\Sites::table( '404_stats' );
		foreach ( $this->before_cutoff( $rows ) as $r ) {
			// clip() rather than substr(): the columns are 255 bytes, and a byte
			// cut through a multibyte character leaves invalid UTF-8 that MySQL
			// rejects or mangles, under a hash that never matches the tracker's.
			// The path is decoded before it is sanitised, with the same helper the
			// 404 tracker uses, so an export that kept /caf%C3%A9/ encoded lands
			// on the row the tracker writes for that request.
			$path         = \VireoAnalytics\Collector::clip( \VireoAnalytics\Collector::decode_path( (string) $r['path'] ), 255 );
			$referrer     = \VireoAnalytics\Collector::clip( self::plain_text( (string) $r['referrer'] ), 255 );
			$internal_ref = \VireoAnalytics\Collector::clip( self::plain_text( (string) $r['internal_ref'] ), 255 );
			if ( '' === $path || (int) $r['hits'] <= 0 ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- import upsert; cache not relevant
			$wpdb->query(
				$wpdb->prepare(
					'INSERT INTO %i (date, entry_hash, path, referrer, internal_ref, hits) VALUES (%s, %s, %s, %s, %s, %d)
					 ON DUPLICATE KEY UPDATE hits = hits + VALUES(hits)',
					$table,
					$r['date'],
					md5( $path . "\0" . $referrer . "\0" . $internal_ref ),
					$path,
					$referrer,
					$internal_ref,
					(int) $r['hits']
				)
			);
			$this->assert_no_db_error();
		}
	}

	/**
	 * An event name as the tracker would store it: lower-case letters, digits,
	 * underscore and hyphen, anything else removed. Mirrors
	 * vireo_collect_event() in vireo-analytics-collect.php.
	 */
	public static function event_name( string $raw ): string {
		$name = strtolower( (string) preg_replace( '/[^a-z0-9_\\-]/i', '', $raw ) );
		// The column is varchar(100); the rule leaves only ASCII, so bytes and
		// characters agree and substr() cannot split anything.
		return substr( $name, 0, 100 );
	}

	/**
	 * A string from another plugin's tables as plain text: tags and their
	 * script bodies gone, control characters gone. The tracker runs every
	 * beacon field through sanitize_text_field(); an export written by what
	 * visitors sent that plugin deserves the same before it is printed.
	 */
	public static function plain_text( string $raw ): string {
		return sanitize_text_field( wp_strip_all_tags( $raw ) );
	}

	/**
	 * Upsert what a source filtered out as automated traffic.
	 *
	 * @param array<int, array{date:string,category:string,hits:int}> $rows
	 */
	protected function upsert_bot_stats_bulk( array $rows ): void {
		global $wpdb;
		$table = \VireoAnalytics\Sites::table( 'bot_stats' );
		foreach ( $this->before_cutoff( $rows ) as $r ) {
			if ( (int) $r['hits'] <= 0 ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- import upsert; cache not relevant
			$wpdb->query(
				$wpdb->prepare(
					'INSERT INTO %i (date, category, hits) VALUES (%s, %s, %d)
					 ON DUPLICATE KEY UPDATE hits = hits + VALUES(hits)',
					$table,
					$r['date'],
					(string) $r['category'],
					(int) $r['hits']
				)
			);
			$this->assert_no_db_error();
		}
	}

	/**
	 * Referrer rows from sessions. A source that keeps the referrer on the
	 * session rather than the hit is read as the tracker would have recorded
	 * it: one pageview per session from another site, the landing one, which
	 * is also the post that referrer sent the reader to.
	 *
	 * @param array<int, array{referrer:string, uid_id:int|string, page_id:int|string|null}> $landings
	 * @return array{site: array<int,array<string,mixed>>, posts: array<int,array<string,mixed>>}
	 */
	public static function landing_referrer_rows( string $date, array $landings, string $self_host ): array {
		$site  = [];
		$posts = [];
		foreach ( $landings as $l ) {
			$host = self::referrer_host( (string) $l['referrer'], $self_host );
			if ( '' === $host ) {
				continue;
			}
			$uid                           = (string) $l['uid_id'];
			$site[ $host ]['sessions']     = ( $site[ $host ]['sessions'] ?? 0 ) + 1;
			$site[ $host ]['uids'][ $uid ] = true;

			$post_id = (int) ( $l['page_id'] ?? 0 );
			if ( $post_id > 0 ) {
				$k                           = $post_id . "\0" . $host;
				$posts[ $k ]['post_id']      = $post_id;
				$posts[ $k ]['host']         = $host;
				$posts[ $k ]['sessions']     = ( $posts[ $k ]['sessions'] ?? 0 ) + 1;
				$posts[ $k ]['uids'][ $uid ] = true;
			}
		}

		$out = [
			'site'  => [],
			'posts' => [],
		];
		foreach ( $site as $host => $agg ) {
			$out['site'][] = [
				'date'      => $date,
				'host'      => (string) $host,
				'pageviews' => $agg['sessions'],
				'visitors'  => count( $agg['uids'] ),
			];
		}
		foreach ( $posts as $agg ) {
			$out['posts'][] = [
				'date'      => $date,
				'post_id'   => $agg['post_id'],
				'host'      => $agg['host'],
				'pageviews' => $agg['sessions'],
				'visitors'  => count( $agg['uids'] ),
			];
		}
		return $out;
	}

	/**
	 * Browser, OS and device rows from a source's own names, mapped onto the
	 * tracker's labels and merged where two of its names become one of ours.
	 *
	 * @param array<int, array{browser:?string, os:?string, device:?string, pageviews:int|string, visitors:int|string}> $raw
	 * @return array<int, array{date:string,browser:string,os:string,device_type:string,pageviews:int,visitors:int}>
	 */
	public static function tech_rows( string $date, array $raw ): array {
		$out = [];
		foreach ( $raw as $r ) {
			$out[] = [
				'date'        => $date,
				'browser'     => self::browser_label( (string) $r['browser'] ),
				'os'          => self::os_label( (string) $r['os'] ),
				'device_type' => self::device_label( (string) $r['device'] ),
				'pageviews'   => (int) $r['pageviews'],
				'visitors'    => (int) $r['visitors'],
			];
		}
		return self::merge_rows( $out, [ 'browser', 'os', 'device_type' ], [ 'pageviews', 'visitors' ] );
	}

	/**
	 * Fold rows that share a key into one, summing the rest. Mapping a
	 * source's labels onto Vireo's (two Chrome variants onto Chrome) leaves
	 * duplicates that would otherwise be written twice.
	 *
	 * @param array<int, array<string,mixed>> $rows
	 * @param string[]                        $keys
	 * @param string[]                        $sums
	 * @return array<int, array<string,mixed>>
	 */
	public static function merge_rows( array $rows, array $keys, array $sums ): array {
		$out = [];
		foreach ( $rows as $r ) {
			$k = implode( "\0", array_map( static fn( string $f ): string => (string) $r[ $f ], $keys ) );
			if ( ! isset( $out[ $k ] ) ) {
				$out[ $k ] = $r;
				continue;
			}
			foreach ( $sums as $f ) {
				$out[ $k ][ $f ] = (int) $out[ $k ][ $f ] + (int) $r[ $f ];
			}
		}
		return array_values( $out );
	}

	/**
	 * Every source names browsers its own way ("Chrome Mobile iOS",
	 * "Microsoft Edge", "SamsungBrowser"). The tracker only ever records the
	 * names below, so an imported history has to use them too or each card
	 * splits one browser across several rows.
	 */
	public static function browser_label( string $raw ): string {
		$s = strtolower( trim( $raw ) );
		if ( '' === $s ) {
			return 'Other';
		}
		$map = [
			'edge'              => 'Edge',
			'opera'             => 'Opera',
			'opr'               => 'Opera',
			'samsung'           => 'Samsung Internet',
			'firefox'           => 'Firefox',
			'chrome'            => 'Chrome',
			'safari'            => 'Safari',
			'internet explorer' => 'IE',
			'msie'              => 'IE',
		];
		foreach ( $map as $needle => $label ) {
			if ( false !== strpos( $s, $needle ) ) {
				return $label;
			}
		}
		return 'ie' === $s ? 'IE' : 'Other';
	}

	public static function os_label( string $raw ): string {
		$s = strtolower( trim( $raw ) );
		if ( '' === $s || false !== strpos( $s, 'windows phone' ) || 0 === strpos( $s, 'winphone' ) ) {
			return 'Other';
		}
		if ( preg_match( '/\bios\b|iphone|ipad|ipod/', $s ) ) {
			return 'iOS';
		}
		if ( false !== strpos( $s, 'android' ) ) {
			return 'Android';
		}
		if ( preg_match( '/chrome ?os|chromium os/', $s ) ) {
			return 'Chrome OS';
		}
		if ( 1 === preg_match( '/^wi(n|\d)/', $s ) ) {
			return 'Windows';
		}
		if ( preg_match( '/^mac|os ?x|macintosh/', $s ) ) {
			return 'macOS';
		}
		if ( preg_match( '/linux|ubuntu|debian|fedora|mint|centos|red ?hat|suse|arch/', $s ) ) {
			return 'Linux';
		}
		return 'Other';
	}

	/**
	 * Desktop, Mobile or Tablet, the three the tracker records. Anything else
	 * (a TV, a console) is Unknown rather than forced into one of them, the
	 * same as the Google Analytics import does.
	 */
	public static function device_label( string $raw ): string {
		$s = strtolower( trim( $raw ) );
		if ( in_array( $s, [ 'desktop', 'computer', 'pc', 'laptop' ], true ) ) {
			return 'Desktop';
		}
		if ( in_array( $s, [ 'mobile', 'smartphone', 'phone', 'phablet', 'feature phone', 'wearable' ], true ) ) {
			return 'Mobile';
		}
		if ( 'tablet' === $s ) {
			return 'Tablet';
		}
		return 'Unknown';
	}

	/**
	 * Reduce a referrer to the host the tracker would have stored: lower case,
	 * no www., and nothing at all for this site linking to itself or for a
	 * value that isn't a host (a source's own label, an app id).
	 */
	public static function referrer_host( string $raw, string $self_host ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}
		$host = (string) wp_parse_url( false === strpos( $raw, '://' ) ? 'http://' . $raw : $raw, PHP_URL_HOST );
		$host = strtolower( (string) preg_replace( '/^www\./i', '', $host ) );
		$self = strtolower( (string) preg_replace( '/^www\./i', '', $self_host ) );
		if ( '' === $host || false === strpos( $host, '.' ) || $host === $self ) {
			return '';
		}
		return \VireoAnalytics\Collector::clip( $host, 190 );
	}

	/** Host of an outbound link, as the tracker records it, or '' for one on this site. */
	public static function outbound_host( string $url, string $self_host ): string {
		$host = strtolower( (string) wp_parse_url( trim( $url ), PHP_URL_HOST ) );
		$bare = (string) preg_replace( '/^www\./', '', $host );
		$self = strtolower( (string) preg_replace( '/^www\./i', '', $self_host ) );
		return '' === $host || $bare === $self ? '' : $host;
	}

	/** The file name a download is recorded under: the last path segment. */
	public static function download_name( string $url ): string {
		$path = (string) wp_parse_url( trim( $url ), PHP_URL_PATH );
		$name = basename( $path );
		return '' === $name ? $path : $name;
	}

	/**
	 * Throw if the last wpdb query produced an error. Keeps the chunk loop
	 * from silently swallowing schema/size issues.
	 */
	protected function assert_no_db_error(): void {
		global $wpdb;
		if ( ! empty( $wpdb->last_error ) ) {
			throw new \RuntimeException( 'Database error during import: ' . esc_html( (string) $wpdb->last_error ) );
		}
	}
}
