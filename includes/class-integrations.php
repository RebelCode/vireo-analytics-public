<?php
/**
 * Reports for WP RSS Aggregator and Spotlight sites.
 *
 * Both plugins put content on a page that sends readers elsewhere: an aggregated
 * item links to the original article, an Instagram tile opens the post. Vireo
 * already counts the visit to the page; these reports add what happened to the
 * content on it. Clicks arrive as custom events from the tracker, which only
 * listens for them when the plugin is active.
 */

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

final class Integrations {

	public const WPRA_CLICK = 'wpra_click';

	/** Spotlight event names, in the order the card lists them. */
	public const SPOTLIGHT_EVENTS = [
		'spotlight_post_click',
		'spotlight_popup_link',
		'spotlight_follow',
		'spotlight_load_more',
	];

	/**
	 * WP RSS Aggregator 5 is running. Version 4 stores imported items
	 * differently and isn't covered.
	 */
	public static function wpra_active(): bool {
		return defined( 'WPRA_VERSION' ) && function_exists( 'wpra' )
			&& ! ( defined( 'WPRA_V5_USE_V4' ) && WPRA_V5_USE_V4 );
	}

	public static function spotlight_active(): bool {
		return defined( 'SL_INSTA' );
	}

	/**
	 * Visits to imported posts and clicks out to the original articles, per
	 * source.
	 *
	 * @return array{sources: array<int, array{name:string, views:int, clicks:int}>, views:int, clicks:int, share:?float}
	 */
	public static function wpra_report( string $start, string $end ): array {
		global $wpdb;

		$empty = [ 'sources' => [], 'views' => 0, 'clicks' => 0, 'share' => null ];
		if ( ! self::wpra_active() ) {
			return $empty;
		}

		$va_post_stats  = Sites::table( 'post_stats' );
		$va_event_stats = Sites::table( 'event_stats' );
		$va_site_stats  = Sites::table( 'site_stats' );

		$by_name = [];

		// Imported posts are ordinary posts carrying _wpra_source, one meta row
		// per source. A post from two sources is credited to each.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP RSS Aggregator's own filter, read so a changed table prefix is honored.
		$sources = $wpdb->prefix . (string) apply_filters( 'wpra.db.prefix', 'agg_' ) . 'sources';
		$named   = Aggregator::table_ready( $sources );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- reporting read; table names come from $wpdb->prefix
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_value AS source_id, SUM(s.pageviews) AS views
				 FROM {$va_post_stats} s
				 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = s.post_id AND pm.meta_key = '_wpra_source'
				 WHERE s.post_id > 0 AND s.date BETWEEN %s AND %s
				 GROUP BY pm.meta_value",
				$start,
				$end
			),
			ARRAY_A
		);

		$names = [];
		if ( $named && $rows ) {
			$ids = array_map( 'absint', array_column( $rows, 'source_id' ) );
			$in  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in is built from %d markers only
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, name FROM {$sources} WHERE id IN ({$in})", ...$ids ), ARRAY_A ) as $src ) {
				$names[ (int) $src['id'] ] = (string) $src['name'];
			}
		}

		$views_total = 0;
		foreach ( (array) $rows as $row ) {
			$id   = (int) $row['source_id'];
			$name = $names[ $id ] ?? sprintf(
				/* translators: %d: WP RSS Aggregator source ID */
				__( 'Source #%d', 'vireo-analytics' ),
				$id
			);
			$by_name[ $name ]          = $by_name[ $name ] ?? [ 'name' => $name, 'views' => 0, 'clicks' => 0 ];
			$by_name[ $name ]['views'] += (int) $row['views'];
			$views_total              += (int) $row['views'];
		}

		$clicks = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT event_value AS name, SUM(count) AS clicks
				 FROM {$va_event_stats}
				 WHERE event_name = %s AND date BETWEEN %s AND %s
				 GROUP BY event_value",
				self::WPRA_CLICK,
				$start,
				$end
			),
			ARRAY_A
		);

		$site_views = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(pageviews), 0) FROM {$va_site_stats} WHERE date BETWEEN %s AND %s",
				$start,
				$end
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$clicks_total = 0;
		foreach ( (array) $clicks as $row ) {
			$name = (string) $row['name'];
			if ( '' === $name ) {
				continue;
			}
			$by_name[ $name ]           = $by_name[ $name ] ?? [ 'name' => $name, 'views' => 0, 'clicks' => 0 ];
			$by_name[ $name ]['clicks'] += (int) $row['clicks'];
			$clicks_total              += (int) $row['clicks'];
		}

		$list = array_values( $by_name );
		usort(
			$list,
			static fn( array $a, array $b ): int => ( $b['views'] + $b['clicks'] ) <=> ( $a['views'] + $a['clicks'] )
		);

		return [
			'sources' => array_slice( $list, 0, 10 ),
			'views'   => $views_total,
			'clicks'  => $clicks_total,
			'share'   => $site_views > 0 ? $views_total / $site_views : null,
		];
	}

	/**
	 * What visitors did with Instagram feeds, overall and per page.
	 *
	 * @return array{totals: array<string,int>, pages: array<int, array{path:string, total:int, by_event: array<string,int>}>}
	 */
	public static function spotlight_report( string $start, string $end ): array {
		global $wpdb;

		$totals = array_fill_keys( self::SPOTLIGHT_EVENTS, 0 );
		if ( ! self::spotlight_active() ) {
			return [ 'totals' => $totals, 'pages' => [] ];
		}

		$va_event_stats = Sites::table( 'event_stats' );

		$in = implode( ',', array_fill( 0, count( self::SPOTLIGHT_EVENTS ), '%s' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- reporting read; $in is built from %s markers only
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT event_name, event_value AS path, SUM(count) AS n
				 FROM {$va_event_stats}
				 WHERE event_name IN ({$in}) AND date BETWEEN %s AND %s
				 GROUP BY event_name, event_value",
				...array_merge( self::SPOTLIGHT_EVENTS, [ $start, $end ] )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$pages = [];
		foreach ( (array) $rows as $row ) {
			$event = (string) $row['event_name'];
			$path  = (string) $row['path'];
			$n     = (int) $row['n'];

			$totals[ $event ] += $n;

			$pages[ $path ]                      = $pages[ $path ] ?? [ 'path' => $path, 'total' => 0, 'by_event' => array_fill_keys( self::SPOTLIGHT_EVENTS, 0 ) ];
			$pages[ $path ]['by_event'][ $event ] += $n;
			$pages[ $path ]['total']             += $n;
		}

		$pages = array_values( $pages );
		usort( $pages, static fn( array $a, array $b ): int => $b['total'] <=> $a['total'] );

		return [ 'totals' => $totals, 'pages' => array_slice( $pages, 0, 10 ) ];
	}
}
