<?php
/**
 * Daily data-retention purge job.
 *
 * Deletes rows older than `retention_days` from every daily-rollup table on a
 * daily cron schedule. Normalisation tables (va_paths, va_referrer_urls) are
 * left alone — orphaned rows there are harmless and their cleanup is much
 * more complex (would need to check every child table first).
 *
 * On activation Installer::activate() schedules `vireo_purge_old_data`; this
 * class is instantiated on every request so the hook callback is bound when
 * the cron event fires.
 */

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

final class Retention {

	public const CRON_HOOK = 'vireo_purge_old_data';

	/** Tables that get purged by retention_days. Date column always `date`. Short names, see Sites::table(). */
	private const DAILY_TABLES = [
		'site_stats',
		'post_stats',
		'referrer_stats',
		'post_referrer_stats',
		'geo_stats',
		'device_stats',
		'404_stats',
		'event_stats',
		'bot_stats',
		// Search Console rows were never pruned. The sync only rewrites the
		// window it fetches, so every older day stayed forever, and the
		// per-page table multiplies that by the number of pages a search hit.
		'gsc_stats',
		'gsc_pages',
		// Core Web Vitals histograms were left out when they were added, so
		// their rows were kept forever whatever the retention setting said.
		'vitals_stats',
		'page_time',
		'bounce_stats',
	];

	/**
	 * Days of visitor-dedupe state to keep. Only today's rows are consulted;
	 * yesterday's are kept as slack for late buffer flushes and timezone edges.
	 */
	private const VISITOR_SEEN_DAYS = 2;

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( self::CRON_HOOK, [ $this, 'run' ] );
	}

	/**
	 * Schedule the daily purge cron event. Called from Installer::activate()
	 * and from the upgrade path.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Purge callback. Safe to run when no settings exist (uses defaults) and
	 * when some tables haven't been installed yet (we probe each one).
	 */
	public function run(): void {
		$settings = vireo_get_settings();
		$days     = (int) ( $settings['retention_days'] ?? 730 );

		// The main site first, then each connected site in its own context, so
		// one retention setting reaches every copy of the tables.
		$this->purge_site( $days );
		foreach ( self::site_ids() as $site_id ) {
			Sites::switch_to( $site_id );
			try {
				$this->purge_site( $days );
			} finally {
				Sites::restore();
			}
		}

		update_option( 'vireo_retention_last_run', time(), false );
	}

	/**
	 * Every connected site's id. Separate so a test can run the loop without
	 * the option machinery.
	 *
	 * @return array<int,int>
	 */
	public static function site_ids(): array {
		return array_keys( Sites::all() );
	}

	/**
	 * Purge the site in context.
	 */
	private function purge_site( int $days ): void {
		global $wpdb;

		// Dedupe state is working memory, not analytics history, so it expires on
		// its own short clock. This runs before the "never purge" check below:
		// that setting is about keeping stats, and letting it pin visitor-seen
		// rows forever would grow the table without bound for no benefit.
		$this->purge_visitor_seen();

		// 0 / negative means "never purge" — users selecting the "Never purge"
		// preset save 0. Default/fallback is 730.
		if ( $days <= 0 ) {
			return;
		}

		$cutoff = gmdate( 'Y-m-d', time() - ( $days * DAY_IN_SECONDS ) );

		foreach ( self::DAILY_TABLES as $slug ) {
			// Shared tables belong to the main site and were purged in its pass.
			if ( Sites::is_connected() && ! in_array( $slug, Sites::PER_SITE, true ) ) {
				continue;
			}
			$table = Sites::table( $slug );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- schema probe
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- destructive write; no caching applies
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE date < %s', $table, $cutoff ) );
		}
	}

	/**
	 * Drop visitor-dedupe rows the aggregator will never consult again.
	 */
	private function purge_visitor_seen(): void {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d', time() - ( self::VISITOR_SEEN_DAYS * DAY_IN_SECONDS ) );

		// va_visitor_load holds per-visitor daily counts for the cap and ages out
		// on the same schedule.
		foreach ( [ 'visitor_seen', 'visitor_load' ] as $name ) {
			$table = Sites::table( $name );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- schema probe
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- destructive write; no caching applies
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE date < %s', $table, $cutoff ) );
		}
	}

	/**
	 * Total size (bytes) of every va_* table on this database. Used by the
	 * Settings UI to show current disk usage next to the retention control.
	 *
	 * @return array{bytes:int,mb:float,table_count:int}
	 */
	public static function database_usage(): array {
		global $wpdb;
		$like = $wpdb->esc_like( $wpdb->prefix . 'va_' ) . '%';

		// information_schema.TABLES gives us DATA_LENGTH + INDEX_LENGTH per
		// table without needing to run SHOW TABLE STATUS per row.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- admin probe; cheap
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT TABLE_NAME AS name, DATA_LENGTH + INDEX_LENGTH AS bytes
				 FROM information_schema.TABLES
				 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s',
				$like
			)
		);

		$total = 0;
		$count = 0;
		foreach ( (array) $rows as $r ) {
			$total += (int) $r->bytes;
			$count++;
		}
		return [
			'bytes'       => $total,
			'mb'          => round( $total / 1048576, 1 ),
			'table_count' => $count,
		];
	}

	/**
	 * Retention presets exposed to the Settings UI. 0 means "never purge".
	 *
	 * @return array<int,string>  days => label
	 */
	public static function presets(): array {
		return [
			90   => __( '90 days', 'vireo-analytics' ),
			180  => __( '180 days', 'vireo-analytics' ),
			365  => __( '1 year', 'vireo-analytics' ),
			730  => __( '2 years', 'vireo-analytics' ),
			1825 => __( '5 years', 'vireo-analytics' ),
			0    => __( 'Never purge', 'vireo-analytics' ),
		];
	}
}
