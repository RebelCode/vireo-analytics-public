<?php
/**
 * DB schema, activation, deactivation.
 *
 * Only covers Phase 1 tables. Pro tables (va_live_hits, va_daily_sessions,
 * va_404_stats, va_utm_stats, va_search_stats, va_outbound_stats) are
 * installed by the pro/ loader in Phase 4.
 */

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

final class Installer {

	public const CAPABILITY = 'view_vireo_analytics';

	/** The first date bounce counting is complete for. See Aggregator::bounce_counted(). */
	public const BOUNCE_SINCE_OPTION = 'vireo_bounce_since';

	/** The first day Vireo recorded hits itself, as opposed to imported history. Set on a fresh install only. */
	public const TRACKING_SINCE_OPTION = 'vireo_tracking_since';

	/**
	 * Install on every site of a network.
	 *
	 * Capped, because a network with tens of thousands of sites would time out
	 * long before it finished and leave the activation half-done. Sites beyond
	 * the cap are not abandoned: each one installs itself on its first request,
	 * which is what the empty-version check in the bootstrap is for.
	 */
	public static function activate_network(): void {
		if ( ! is_multisite() ) {
			self::activate();
			return;
		}

		$limit = (int) apply_filters( 'vireo_network_activation_limit', 200 );
		$limit = max( 1, min( 10000, $limit ) );

		$sites = get_sites(
			[
				'number'   => $limit,
				'fields'   => 'ids',
				'archived' => 0,
				'deleted'  => 0,
				'spam'     => 0,
			]
		);

		foreach ( (array) $sites as $site_id ) {
			switch_to_blog( (int) $site_id );
			self::activate();
			restore_current_blog();
		}
	}

	public static function activate(): void {
		// Read before it is overwritten below: an existing install that is
		// reactivated has already seen today's visitors.
		$fresh = '' === (string) get_option( 'vireo_db_version', '' );

		self::create_tables();
		self::schedule_cron();
		self::register_capability();

		Endpoint_Installer::install();

		// Sweep away the stylesheet copies the old cache-busting scheme left in
		// uploads. The dashboard now loads its CSS straight from the plugin.
		if ( class_exists( CSS_Hash::class ) ) {
			CSS_Hash::cleanup_legacy();
		}

		// Seed the visitor salt so the first beacon after install has something
		// to hash against.
		if ( class_exists( Salt_Manager::class ) ) {
			Salt_Manager::instance()->rotate();
		}

		// Pick up any buffer the old retry naming stranded on disk. Those hold
		// real visits that nothing else will ever look at again.
		if ( class_exists( Aggregator::class ) ) {
			Aggregator::rescue_stranded_buffers();
		}
		self::schedule_cron();
		update_option( 'vireo_db_version', self::schema_version(), false );

		self::start_bounce_counting( $fresh );

		if ( $fresh ) {
			add_option( self::TRACKING_SINCE_OPTION, vireo_bucket_date() );
		}
	}

	/**
	 * Record the first day bounce counting is complete for, once.
	 *
	 * A new install has seen nobody yet, so that is today. On a site that was
	 * already recording, today's visitors were marked as seen before the
	 * counter existed, so it starts tomorrow. See Aggregator::bounce_counted().
	 */
	private static function start_bounce_counting( bool $fresh ): void {
		$today = vireo_bucket_date();
		add_option( self::BOUNCE_SINCE_OPTION, $fresh ? $today : gmdate( 'Y-m-d', (int) strtotime( $today . ' +1 day UTC' ) ) );
	}

	/**
	 * Upgrade path — called on every admin load via `admin_init` wiring. Picks up
	 * new tables added after the plugin was first activated, without forcing the
	 * user to deactivate/reactivate. Guarded by an option-versioned short-circuit
	 * so we don't re-run dbDelta on every pageload.
	 */
	/** The schema revision this code expects. */
	public static function schema_version(): string {
		return defined( 'VIREO_DB_VERSION' ) ? VIREO_DB_VERSION : VIREO_VERSION;
	}

	public static function maybe_upgrade(): void {
		$target  = self::schema_version();
		$current = (string) get_option( 'vireo_db_version', '' );
		if ( $current === $target ) {
			return;
		}
		self::migrate_404_key();
		self::create_tables();
		foreach ( Sites::all() as $site ) {
			self::create_tables( (int) $site['id'] );
		}
		// Run the endpoint installer too — picks up the uploads-dir migration
		// and removes the legacy ABSPATH shim from prior versions.
		if ( class_exists( Endpoint_Installer::class ) ) {
			Endpoint_Installer::install();
		}
		// Sweep the stylesheet copies the old uploads-served scheme left behind.
		if ( class_exists( CSS_Hash::class ) ) {
			CSS_Hash::cleanup_legacy();
		}
		// Schema 9 keys Search Console rows on the whole query, not its first
		// 190 characters. Refetch recent history so long queries stored under
		// the old key don't show up twice next to their new rows.
		if ( '' !== $current && version_compare( $current, '9', '<' ) && class_exists( Search_Console::class ) && Search_Console::configured() ) {
			Search_Console::start_backfill( 90 );
		}
		// Schema 11 keys the country cache on a hash made with the site's
		// secret instead of an unkeyed md5 of the address. The old rows would
		// never be looked up again under the new key, and each one is a
		// reversible fingerprint of an address, so drop them rather than let
		// them sit out their 30 days.
		if ( '' !== $current && version_compare( $current, '11', '<' ) ) {
			self::purge_ip_cache();
		}
		// Schema 12 counts bounces.
		self::start_bounce_counting( '' === $current );
		self::backfill_tracking_since( '' === $current );
		// Schema 13 sets the start on installs already on 12, which never ran
		// the backfill above, and lifts a start 0.16.2 took from the weak path.
		if ( '' !== $current && version_compare( $current, '13', '<' ) ) {
			self::repair_tracking_since();
		}
		update_option( 'vireo_db_version', $target, false );
	}

	/**
	 * Work out the first day Vireo recorded on a site that installed before the
	 * option existed.
	 *
	 * The importers write the totals, page, referrer, country, device, event, 404
	 * and bot rollups, so those can't say when tracking began. Nothing imports
	 * hourly totals, time on page, bounce counts or vitals, so the earliest of
	 * those is trusted as it stands, unless a backup restore is on record: a
	 * restore writes them with the backup's own dates.
	 *
	 * Without them the import history decides, and it treats any imported day it
	 * can't place (a run recorded without its range, or rows written outside the
	 * importers) as Vireo's own, which can be years before Vireo was installed.
	 * That weak answer, and a restored one, is never earlier than
	 * tracking_floor(). On a site that was recording before the floor existed
	 * that is later than the real start, which only holds comparisons back for
	 * a period; a start that is too early shows a drop against imported history.
	 *
	 * With no live rows yet the option stays unset, unless $today_if_empty (a
	 * fresh install, or the first flush) says nothing has been recorded before
	 * today.
	 */
	public static function backfill_tracking_since( bool $today_if_empty = false ): void {
		if ( '' !== (string) get_option( self::TRACKING_SINCE_OPTION, '' ) ) {
			return;
		}

		$first = self::first_recorded_day( $today_if_empty );
		if ( '' !== $first ) {
			add_option( self::TRACKING_SINCE_OPTION, $first );
		}
	}

	/**
	 * Move a start stored by 0.16.2 or 0.16.3 to what the backfill gives now,
	 * once, and only later. Those versions floored nothing, so the weak answer
	 * could be years early. A stored value doesn't say where it came from, so
	 * this only touches one the live-only tables don't back up: they are empty,
	 * or start more than a day after it. A start the live rows confirm stays.
	 */
	public static function repair_tracking_since(): void {
		$since = (string) get_option( self::TRACKING_SINCE_OPTION, '' );
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $since ) ) {
			return;
		}

		$live = self::live_only_start();
		if ( '' !== $live && $live <= gmdate( 'Y-m-d', (int) strtotime( $since . ' +1 day UTC' ) ) ) {
			return;
		}

		$fixed = self::first_recorded_day( false );
		if ( '' === $fixed ) {
			$fixed = self::no_earlier_than_floor( $since );
		}
		if ( $fixed > $since ) {
			update_option( self::TRACKING_SINCE_OPTION, $fixed );
		}
	}

	/** The backfill's answer, as backfill_tracking_since() describes, or ''. */
	private static function first_recorded_day( bool $today_if_empty ): string {
		$live = self::live_only_start();
		if ( '' !== $live && ! self::backup_restored() ) {
			return $live;
		}

		$first = $live;
		if ( '' === $first && class_exists( Import_History::class ) ) {
			$first = (string) Import_History::own_data_start();
		}
		if ( '' === $first && $today_if_empty ) {
			$first = vireo_bucket_date();
		}

		return '' === $first ? '' : self::no_earlier_than_floor( $first );
	}

	/** The earliest day in the tables only live tracking (or a restore) fills, or ''. */
	private static function live_only_start(): string {
		global $wpdb;

		$days = [];
		foreach ( [ 'hour_stats' => 'pageviews', 'page_time' => 'samples', 'bounce_stats' => 'visitors', 'vitals_stats' => 'samples' ] as $name => $column ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- runs once, on upgrade
			$days[] = $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(date) FROM %i WHERE %i > 0', Sites::table( $name ), $column ) );
		}

		return self::earliest_day( $days );
	}

	/** Whether a Vireo backup was restored here, so the live-only rows may be the backup's. */
	private static function backup_restored(): bool {
		return class_exists( Import_History::class ) && [] !== Import_History::all( 'backup' );
	}

	/**
	 * The earliest day Vireo can have recorded on this site: when bounce
	 * counting started. Activation sets that to the install day; on a site that
	 * was already recording, schema 12 set it to the day after the upgrade.
	 * Neither a restore nor an import touches it. '' when it was never set.
	 */
	private static function tracking_floor(): string {
		$floor = (string) get_option( self::BOUNCE_SINCE_OPTION, '' );
		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $floor ) ? $floor : '';
	}

	/** $day, raised to the floor, and never later than today. */
	private static function no_earlier_than_floor( string $day ): string {
		$floor = self::tracking_floor();
		if ( '' !== $floor && $day < $floor ) {
			$day = $floor;
		}
		return min( $day, vireo_bucket_date() );
	}

	/** The earliest valid Y-m-d in a list of query results, or ''. */
	public static function earliest_day( array $days ): string {
		$days = array_filter( $days, static fn( $d ): bool => is_string( $d ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) );
		return $days ? min( $days ) : '';
	}

	/** Empty the country-lookup cache for the main site and every connected site. */
	public static function purge_ip_cache(): void {
		global $wpdb;
		$site_ids = [ 0 ];
		foreach ( Sites::all() as $site ) {
			$site_ids[] = (int) $site['id'];
		}
		foreach ( $site_ids as $site_id ) {
			$table = Sites::table( 'ip_cache', $site_id );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( "DELETE FROM {$table}" );
		}
	}

	/**
	 * Re-key va_404_stats from (date, path, referrer) onto (date, entry_hash).
	 *
	 * dbDelta can add a column but will not change a primary key, so this has
	 * to run first and by hand. It has to run before create_tables() too, or
	 * dbDelta sees a table whose key it cannot reconcile.
	 *
	 * The old key could not tell a broken internal link from a vulnerability
	 * scanner: both arrive with an empty external referrer, so both collapsed
	 * into the same row. Adding internal_ref only helps if it is part of the
	 * key, and putting three varchar(255) columns in one key lands within three
	 * bytes of InnoDB's limit — hence the hash.
	 *
	 * Existing rows keep their counts and get an empty internal_ref, which is
	 * honest: we genuinely do not know whether those older hits came from a
	 * link on the site.
	 */
	private static function migrate_404_key(): void {
		global $wpdb;
		$table = Sites::table( '404_stats', Sites::MAIN );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- schema probe
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- schema probe
		$columns = (array) $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );
		if ( in_array( 'entry_hash', $columns, true ) ) {
			return;
		}

		if ( ! in_array( 'internal_ref', $columns, true ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN internal_ref varchar(255) NOT NULL DEFAULT '' AFTER referrer" );
		}

		$wpdb->query( "ALTER TABLE {$table} ADD COLUMN entry_hash char(32) NOT NULL DEFAULT '' AFTER date" );
		$wpdb->query( "UPDATE {$table} SET entry_hash = MD5(CONCAT(path, CHAR(0), referrer, CHAR(0), internal_ref))" );

		// Two rows could in principle hash alike only if they were already
		// identical, so this cannot lose a distinct row — but drop duplicates
		// defensively before the key goes on, or the ALTER fails outright.
		$wpdb->query(
			"DELETE t1 FROM {$table} t1
			 INNER JOIN {$table} t2
			 ON t1.date = t2.date AND t1.entry_hash = t2.entry_hash AND t1.hits < t2.hits"
		);

		$wpdb->query( "ALTER TABLE {$table} DROP PRIMARY KEY, ADD PRIMARY KEY (date, entry_hash)" );
	}

	public static function deactivate(): void {
		self::unschedule_cron();
		Endpoint_Installer::uninstall();

		if ( class_exists( Salt_Manager::class ) ) {
			Salt_Manager::remove_mirror();
		}
	}

	/**
	 * Grant `view_vireo_analytics` to admins. Idempotent — safe on every
	 * activation. Intentionally does NOT remove on deactivate; admins often
	 * deactivate briefly and re-activate, and removing the cap churns the
	 * options row for no gain.
	 */
	private static function register_capability(): void {
		$role = get_role( 'administrator' );
		if ( $role && ! $role->has_cap( self::CAPABILITY ) ) {
			$role->add_cap( self::CAPABILITY );
		}
	}

	/**
	 * Every table the plugin owns, as short name => column and key list.
	 *
	 * One definition per table, used for the main site and for each connected
	 * site alike: Sites::table() turns the short name into the real one and
	 * Sites::PER_SITE says which of these a connected site gets a copy of. A
	 * table added here without a decision there is shared, which the sites
	 * test flags.
	 *
	 * Static and free of WordPress calls so a test can read it.
	 *
	 * @return array<string,string>
	 */
	public static function table_definitions(): array {
		return [
			'site_stats' => "
				date date NOT NULL,
				pageviews int unsigned NOT NULL DEFAULT 0,
				visitors int unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (date)",

			// What the bot filter rejected, by category. Every other analytics
			// plugin throws this away, which leaves the site owner unable to check
			// whether the filtering is doing anything. One row per day per
			// category, so it stays small no matter how hard the crawlers hit.
			'bot_stats' => "
				date date NOT NULL,
				category varchar(32) NOT NULL DEFAULT 'other',
				hits int unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (date,category)",

			// Search Console: one row per (day, query), plus one per day with an
			// empty query holding the site totals. The totals are stored rather
			// than summed because Google truncates the query list and withholds
			// rare ones, so a sum would under-report every day.
			'gsc_stats' => "
				date date NOT NULL,
				query_hash char(32) NOT NULL,
				query varchar(190) NOT NULL DEFAULT '',
				clicks int unsigned NOT NULL DEFAULT 0,
				impressions int unsigned NOT NULL DEFAULT 0,
				position_x10 int unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (date,query_hash),
				KEY date_clicks (date,clicks)",

			// The same searches, broken down by the page each one landed on, so a
			// post's own screen can say what people typed to reach it.
			//
			// A separate table rather than a page column on va_gsc_stats: Search
			// Console aggregates by page differently from by site. One search that
			// shows two of your pages counts once by query and twice by page, so
			// rows from here never sum to the site totals and must not replace them.
			//
			// `page` is the URL path, normalised the way Search_Console::page_path()
			// does it, so a post finds its rows from get_permalink() alone.
			'gsc_pages' => "
				date date NOT NULL,
				page_hash char(32) NOT NULL,
				query_hash char(32) NOT NULL,
				page varchar(190) NOT NULL DEFAULT '',
				query varchar(190) NOT NULL DEFAULT '',
				clicks int unsigned NOT NULL DEFAULT 0,
				impressions int unsigned NOT NULL DEFAULT 0,
				position_x10 int unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (date,page_hash,query_hash),
				KEY page_date (page_hash,date)",

			// Notes pinned to a date, so a spike six weeks ago still has an
			// explanation attached to it.
			'annotations' => "
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				date date NOT NULL,
				label varchar(160) NOT NULL DEFAULT '',
				created_by bigint unsigned NOT NULL DEFAULT 0,
				created_at bigint unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY date (date)",

			// Core Web Vitals, as histograms rather than totals. These are graded
			// on the 75th percentile, and a percentile cannot be recovered from a
			// sum and a count: a handful of very slow loads is precisely what an
			// average hides. One row per (day, metric, bucket) keeps the footprint
			// flat no matter how much traffic arrives.
			'vitals_stats' => "
				date date NOT NULL,
				metric varchar(3) NOT NULL DEFAULT '',
				bucket smallint unsigned NOT NULL DEFAULT 0,
				samples int unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (date,metric,bucket)",

			// Working state, not history: which (date, scope, visitor) combinations
			// have already been counted. The aggregator flushes in batches, so an
			// in-memory set only dedupes within one flush — a visitor whose views
			// land in different flushes would be counted again. Retention keeps
			// only the last couple of days here; see Retention::purge_visitor_seen().
			'visitor_seen' => "
				date date NOT NULL,
				dedupe_key char(32) NOT NULL,
				PRIMARY KEY  (date,dedupe_key)",

			// How many pageviews each visitor has contributed today, overall and per
			// path, so the aggregator can stop counting one visitor past a daily cap.
			// dedupe_key is md5 of the scope and visitor hash, like va_visitor_seen.
			'visitor_load' => "
				date date NOT NULL,
				dedupe_key char(32) NOT NULL,
				hits int unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (date,dedupe_key)",

			// Active time on page, per day and path: how many views reported a
			// time, and the seconds they reported between them. The average is
			// worked out on read, so the row count stays one per page per day
			// however many people read it. path_id is va_paths, as in post_stats.
			'page_time' => "
				date date NOT NULL,
				path_id int unsigned NOT NULL,
				samples int unsigned NOT NULL DEFAULT 0,
				seconds bigint unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (date,path_id)",

			// The bounce rate's two counts per day: visitors, and the visitors
			// among them who viewed a second page. Separate from site_stats so
			// importers, which only know the totals, never leave a day looking
			// like everyone bounced. See Aggregator::apply_bounce_totals().
			'bounce_stats' => "
				date date NOT NULL,
				visitors int unsigned NOT NULL DEFAULT 0,
				multi_page int unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (date)",

			'paths' => "
				id int unsigned NOT NULL AUTO_INCREMENT,
				path varchar(500) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				UNIQUE KEY path (path(191))",

			'post_stats' => "
				date date NOT NULL,
				post_id bigint unsigned NOT NULL,
				path_id int unsigned NOT NULL,
				pageviews int unsigned NOT NULL DEFAULT 0,
				visitors int unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (date,post_id,path_id),
				KEY post_id (post_id)",

			'referrer_urls' => "
				id int unsigned NOT NULL AUTO_INCREMENT,
				url varchar(500) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				UNIQUE KEY url (url(191))",

			'referrer_stats' => "
				date date NOT NULL,
				url_id int unsigned NOT NULL,
				pageviews int unsigned NOT NULL DEFAULT 0,
				visitors int unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (date,url_id)",

			'geo_stats' => "
				date date NOT NULL,
				country_code char(2) NOT NULL DEFAULT '',
				pageviews int unsigned NOT NULL DEFAULT 0,
				visitors int unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (date,country_code)",

			'device_stats' => "
				date date NOT NULL,
				browser varchar(50) NOT NULL DEFAULT '',
				os varchar(50) NOT NULL DEFAULT '',
				device_type varchar(20) NOT NULL DEFAULT '',
				pageviews int unsigned NOT NULL DEFAULT 0,
				visitors int unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (date,browser,os,device_type)",

			'ip_cache' => "
				ip_hash char(32) NOT NULL,
				country_code char(2) NOT NULL DEFAULT '',
				expires int unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (ip_hash)",

			// `internal_ref` is the page on this site that linked to the missing
			// one. It is the difference between a broken link you can fix and a
			// stranger knocking on a door that was never there — and without it
			// the report is mostly vulnerability scanners, which is what it was.
			//
			// The key is a hash of the three text columns rather than the
			// columns themselves: (date, path, referrer, internal_ref) as a
			// composite would be ~3069 bytes against InnoDB's 3072-byte limit,
			// which is close enough to break on a different collation.
			'404_stats' => "
				date date NOT NULL,
				entry_hash char(32) NOT NULL DEFAULT '',
				path varchar(255) NOT NULL DEFAULT '',
				referrer varchar(255) NOT NULL DEFAULT '',
				internal_ref varchar(255) NOT NULL DEFAULT '',
				hits int unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (date,entry_hash),
				KEY date_hits (date,hits),
				KEY path (path(100)),
				KEY internal_ref (internal_ref(100))",

			'event_stats' => "
				date date NOT NULL,
				event_name varchar(100) NOT NULL DEFAULT '',
				event_value varchar(100) NOT NULL DEFAULT '',
				count int unsigned NOT NULL DEFAULT 0,
				unique_count int unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (date,event_name,event_value),
				KEY date_count (date,count)",

			// Hourly site rollup — written alongside daily rollup by the aggregator,
			// purged after a short window. Powers the "Hour" toggle on the main chart.
			'hour_stats' => "
				date date NOT NULL,
				hour tinyint unsigned NOT NULL DEFAULT 0,
				pageviews int unsigned NOT NULL DEFAULT 0,
				visitors int unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (date,hour)",

			// Per-post referrer rollup — separate from va_referrer_stats (which
			// stays site-wide) to avoid a 100x row-count blowup on busy sites. Only
			// written forward; historical data is not backfilled.
			'post_referrer_stats' => "
				date date NOT NULL,
				post_id bigint unsigned NOT NULL,
				url_id int unsigned NOT NULL,
				pageviews int unsigned NOT NULL DEFAULT 0,
				visitors int unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (date,post_id,url_id),
				KEY post_id (post_id)",

		];
	}

	/**
	 * Create or update the tables for one site.
	 *
	 * The main site (the default) gets every table under its original name.
	 * A connected site gets only the per-site tables, under its own names,
	 * which is what Sites::add() calls and what the upgrade path repeats for
	 * every connected site so a schema change reaches them all.
	 */
	public static function create_tables( int $site_id = Sites::MAIN ): void {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( self::table_definitions() as $name => $columns ) {
			if ( Sites::MAIN !== $site_id && ! in_array( $name, Sites::PER_SITE, true ) ) {
				continue;
			}
			// dbDelta wants the literal name in the statement. It comes from
			// Sites::table(), which only ever builds it from the prefix, a fixed
			// list of names and an integer id.
			dbDelta( 'CREATE TABLE ' . Sites::table( $name, $site_id ) . " (\n\t\t\t\t" . trim( $columns ) . "\n\t\t\t) {$charset_collate};" );
		}
	}

	/**
	 * Schedule recurring jobs.
	 *
	 *   vireo_flush_buffer          every minute   buffer -> DB flush
	 *   vireo_purge_ip_cache        daily          trim geo IP cache
	 *   vireo_rotate_visitor_salt   daily          rotate salt for uniqueness hashing
	 *   vireo_weekly_email          hourly (gated) weekly email digest; Email_Reports
	 *                                            self-gates on configured day+hour
	 *
	 * Hook callbacks are registered by the classes that own them (Aggregator,
	 * Geo_Lookup, Salt_Manager, Email_Reports). This method only ensures the
	 * events are scheduled.
	 */
	/**
	 * Put the recurring jobs back if any of them have gone missing.
	 *
	 * These were scheduled once, at activation, and never looked at again. If
	 * the flush event is lost afterwards — a deactivate that doesn't reactivate
	 * cleanly, a migration, two requests racing to write the cron array — the
	 * site stops recording for good, because nothing re-creates it and the
	 * buffer has nowhere to drain. Two of six sites checked had lost exactly
	 * this event, one of them for fifty days.
	 *
	 * Cheap enough for every request: wp_next_scheduled() reads the autoloaded
	 * cron option, so the common case is an array lookup and nothing else.
	 */
	public static function ensure_cron(): void {
		if ( wp_next_scheduled( 'vireo_flush_buffer' ) ) {
			return;
		}
		if ( function_exists( 'wp_installing' ) && wp_installing() ) {
			return;
		}
		self::schedule_cron();
	}

	private static function schedule_cron(): void {
		if ( ! wp_next_scheduled( 'vireo_flush_buffer' ) ) {
			// Round to the next whole minute so two requests healing at the
			// same time land on one slot instead of creating two events.
			wp_schedule_event( (int) ceil( time() / 60 ) * 60, 'vireo_every_minute', 'vireo_flush_buffer' );
		}
		if ( ! wp_next_scheduled( 'vireo_purge_ip_cache' ) ) {
			wp_schedule_event( time(), 'daily', 'vireo_purge_ip_cache' );
		}

		if ( class_exists( Salt_Manager::class ) ) {
			Salt_Manager::schedule_cron();
		}

		if ( ! wp_next_scheduled( 'vireo_weekly_email' ) ) {
			wp_schedule_event( time(), 'hourly', 'vireo_weekly_email' );
		}
		if ( ! wp_next_scheduled( 'vireo_purge_hour_stats' ) ) {
			wp_schedule_event( time(), 'daily', 'vireo_purge_hour_stats' );
		}
		// Retention purge — deletes rows older than retention_days from daily
		// rollup tables. The Retention class binds the hook callback on every
		// request; this just ensures the event is scheduled.
		if ( ! wp_next_scheduled( 'vireo_purge_old_data' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'vireo_purge_old_data' );
		}
	}

	private static function unschedule_cron(): void {
		wp_clear_scheduled_hook( 'vireo_flush_buffer' );
		wp_clear_scheduled_hook( 'vireo_purge_ip_cache' );
		wp_clear_scheduled_hook( 'vireo_weekly_email' );
		wp_clear_scheduled_hook( 'vireo_purge_hour_stats' );
		wp_clear_scheduled_hook( 'vireo_purge_old_data' );

		if ( class_exists( Salt_Manager::class ) ) {
			Salt_Manager::unschedule_cron();
		}
	}
}
