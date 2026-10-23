<?php

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * The few things worth saying about the last seven days: a record, the post
 * that gained most, a site sending visitors for the first time, a day that
 * stood out.
 *
 * Every highlight is a fact read from the rollups and has to clear a floor
 * before it is said. A quiet week gets no highlights rather than filler, and
 * the Overview card and weekly email both drop the block when it is empty.
 */
final class Highlights {

	private const CACHE_GROUP = 'vireo_hl_';

	/** Totals a post or the whole site has to pass to count as a milestone. */
	private const THRESHOLDS = [ 1000, 10000, 100000, 1000000, 10000000 ];

	/** Full weeks of history before "best week so far" means anything. */
	private const RECORD_MIN_WEEKS = 4;
	private const RECORD_MIN_VIEWS = 50;

	private const GAINER_MIN_DELTA  = 10;
	private const REFERRER_MIN_VIEW = 3;

	/** A best day has to clear this many views and beat the rest of the week's average by this much. */
	private const BEST_DAY_MIN_VIEWS = 10;
	private const BEST_DAY_LIFT      = 1.3;

	/**
	 * A referrer only counts as new when there were this many days of history
	 * before the week to have seen it in. On a fresh install every referrer is
	 * "new", which is true and tells nobody anything.
	 */
	private const REFERRER_MIN_HISTORY_DAYS = 28;

	/**
	 * Which facts are worth saying, most notable first.
	 *
	 * At most one milestone (best week, then the site's total, then a post's
	 * total), then the biggest gaining page, a new referrer and the best day.
	 *
	 * @param array<string,mixed> $facts days (date, pageviews), and optionally
	 *                                   gainer, new_referrer, best_week,
	 *                                   site_milestone, post_milestone.
	 * @return array<int,array{kind:string,text:string}>
	 */
	public static function select( array $facts, int $limit = 3 ): array {
		$out = [];

		$milestone = self::milestone( $facts );
		if ( null !== $milestone ) {
			$out[] = $milestone;
		}

		$gainer = $facts['gainer'] ?? null;
		if ( is_array( $gainer ) && (int) $gainer['delta'] >= self::GAINER_MIN_DELTA ) {
			$out[] = [
				'kind' => 'gainer',
				'text' => sprintf(
					/* translators: 1: page title, in quotation marks so a title ending in a full stop still reads as one name; use your language's quotation marks. 2: how many more views it had than the week before */
					_n( '“%1$s” gained the most, %2$s more view than the week before.', '“%1$s” gained the most, %2$s more views than the week before.', (int) $gainer['delta'], 'vireo-analytics' ),
					$gainer['label'],
					number_format_i18n( (int) $gainer['delta'] )
				),
			];
		}

		$referrer = $facts['new_referrer'] ?? null;
		if ( is_array( $referrer ) && (int) $referrer['pageviews'] >= self::REFERRER_MIN_VIEW ) {
			$out[] = [
				'kind' => 'new_referrer',
				'text' => sprintf(
					/* translators: 1: referring site, 2: number of views */
					__( '%1$s sent visitors for the first time: %2$s views.', 'vireo-analytics' ),
					$referrer['label'],
					number_format_i18n( (int) $referrer['pageviews'] )
				),
			];
		}

		$best_day = self::best_day( (array) ( $facts['days'] ?? [] ) );
		if ( null !== $best_day ) {
			$out[] = $best_day;
		}

		return array_slice( $out, 0, max( 0, $limit ) );
	}

	/** The largest threshold a running total passed going from $before to $after, or null. */
	public static function crossed( int $before, int $after ): ?int {
		$hit = null;
		foreach ( self::THRESHOLDS as $threshold ) {
			if ( $before < $threshold && $after >= $threshold ) {
				$hit = $threshold;
			}
		}
		return $hit;
	}

	/**
	 * Highlights for a finished week, read from the rollups. Cached for an hour:
	 * the days in it are over, so only an import changes the answer, and the
	 * import history is part of the key.
	 *
	 * @return array<int,array{kind:string,text:string}>
	 */
	public static function for_week( string $start, string $end, int $limit = 3 ): array {
		$runs  = Import_History::all();
		// The cached rows are finished sentences, so the language is part of the
		// key: without it a German admin read whatever language loaded first.
		$key   = self::CACHE_GROUP . md5( Sites::current() . '|' . determine_locale() . '|' . $start . '|' . $end . '|' . $limit . '|' . count( $runs ) . '|' . ( $runs[0]['at'] ?? 0 ) );
		$found = get_transient( $key );
		if ( is_array( $found ) ) {
			return $found;
		}

		$out = self::select( self::facts( $start, $end ), $limit );
		set_transient( $key, $out, HOUR_IN_SECONDS );
		return $out;
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function facts( string $start, string $end ): array {
		global $wpdb;

		$dashboard = Dashboard::instance();
		$days      = $dashboard->get_chart_data( $start, $end );
		$week_pv   = (int) array_sum( array_column( $days, 'pageviews' ) );

		$facts = [ 'days' => $days ];
		if ( $week_pv <= 0 ) {
			return $facts;
		}

		$site = Sites::table( 'site_stats' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- cached by for_week()
		$history = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT MIN(date) AS first_day, COALESCE(SUM(pageviews),0) AS pageviews FROM %i WHERE date < %s AND (pageviews > 0 OR visitors > 0)',
				$site,
				$start
			),
			ARRAY_A
		);
		$first_day    = (string) ( $history['first_day'] ?? '' );
		$history_days = '' === $first_day ? 0 : (int) ( ( strtotime( $start . ' UTC' ) - strtotime( $first_day . ' UTC' ) ) / DAY_IN_SECONDS );

		// Weeks counted back from the day before this one starts, so each bucket
		// is the same seven weekdays as the week being reported.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- cached by for_week()
		$best_before = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT MAX(t.pv) FROM (
					SELECT FLOOR((DATEDIFF(%s, date) - 1) / 7) AS wk, SUM(pageviews) AS pv
					FROM %i WHERE date < %s GROUP BY wk
				) t',
				$start,
				$site,
				$start
			)
		);
		$facts['best_week'] = [
			'pageviews'     => $week_pv,
			'previous_best' => $best_before,
			'weeks'         => intdiv( $history_days, 7 ),
		];

		$site_total = self::crossed( (int) ( $history['pageviews'] ?? 0 ), (int) ( $history['pageviews'] ?? 0 ) + $week_pv );
		if ( null !== $site_total ) {
			$facts['site_milestone'] = [ 'threshold' => $site_total ];
		}

		$facts['post_milestone'] = self::post_milestone( $start, $end );

		$movers = ( new Drivers( $dashboard ) )->get( $start, $end, true, 1 );
		$gainer = $movers['pages']['gained'][0] ?? null;
		if ( is_array( $gainer ) ) {
			$facts['gainer'] = [
				'label' => self::plain( (string) $gainer['label'] ),
				'url'   => (string) $gainer['url'],
				'delta' => (int) $gainer['delta'],
			];
		}

		if ( $history_days >= self::REFERRER_MIN_HISTORY_DAYS ) {
			$facts['new_referrer'] = self::new_referrer( $start, $end );
		}

		return $facts;
	}

	/**
	 * @return array{label:string,url:string,threshold:int}|null
	 */
	private static function post_milestone( string $start, string $end ): ?array {
		global $wpdb;
		$stats = Sites::table( 'post_stats' );

		// Only posts viewed this week can have crossed anything this week, so the
		// lifetime sums are limited to those.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- cached by for_week()
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT s.post_id,
					SUM(CASE WHEN s.date < %s THEN s.pageviews ELSE 0 END) AS before_pv,
					SUM(CASE WHEN s.date >= %s THEN s.pageviews ELSE 0 END) AS week_pv
				 FROM %i s
				 WHERE s.date <= %s
				   AND s.post_id IN (SELECT DISTINCT w.post_id FROM %i w WHERE w.date BETWEEN %s AND %s AND w.post_id > 0)
				 GROUP BY s.post_id',
				$start,
				$start,
				$stats,
				$end,
				$stats,
				$start,
				$end
			),
			ARRAY_A
		);

		$best = null;
		foreach ( $rows as $row ) {
			$before    = (int) $row['before_pv'];
			$threshold = self::crossed( $before, $before + (int) $row['week_pv'] );
			if ( null !== $threshold && ( null === $best || $threshold > $best['threshold'] ) ) {
				$best = [
					'post_id'   => (int) $row['post_id'],
					'threshold' => $threshold,
				];
			}
		}
		if ( null === $best ) {
			return null;
		}

		$post = Dashboard::public_post( $best['post_id'] );
		if ( '' === $post['title'] ) {
			// The row points at a post nobody could have read, or one that is
			// gone. A milestone with no name is not a highlight.
			return null;
		}
		return [
			'label'     => self::plain( Dashboard::page_label( $post['title'], '' ) ),
			'url'       => $post['url'],
			'threshold' => $best['threshold'],
		];
	}

	/**
	 * The busiest referrer this week that never sent anyone before it. Hosts are
	 * folded the way the referrer card folds them, so www.example.com reappearing
	 * as example.com is not new.
	 *
	 * @return array{label:string,pageviews:int}|null
	 */
	private static function new_referrer( string $start, string $end ): ?array {
		global $wpdb;
		$stats = Sites::table( 'referrer_stats' );

		// The site itself and internal addresses never "sent visitors".
		[ $xw, $xa ] = Filters::excluded_where( 'u.id' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- cached by for_week(); the fragment is a literal with its ids bound in the args
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT u.url AS host, MIN(s.date) AS first_seen,
					SUM(CASE WHEN s.date >= %s THEN s.pageviews ELSE 0 END) AS week_pv
				 FROM %i s
				 JOIN %i u ON u.id = s.url_id
				 WHERE s.date <= %s
				   AND s.url_id IN (SELECT DISTINCT w.url_id FROM %i w WHERE w.date BETWEEN %s AND %s)' . $xw . '
				 GROUP BY u.url',
				array_merge( [ $start, $stats, Sites::table( 'referrer_urls' ), $end, $stats, $start, $end ], $xa )
			),
			ARRAY_A
		);

		$suspects = Referrer_Spam::suspects( array_column( $rows, 'host' ), $start, $end );

		$hosts = [];
		foreach ( $rows as $row ) {
			$canon = vireo_canonical_host( (string) $row['host'] );
			if ( '' === $canon || in_array( (string) $row['host'], $suspects, true ) ) {
				continue;
			}
			$first = (string) $row['first_seen'];
			if ( ! isset( $hosts[ $canon ] ) ) {
				$hosts[ $canon ] = [
					'first' => $first,
					'week'  => 0,
				];
			}
			$hosts[ $canon ]['first']  = min( $hosts[ $canon ]['first'], $first );
			$hosts[ $canon ]['week']  += (int) $row['week_pv'];
		}

		$best = null;
		foreach ( $hosts as $host => $info ) {
			if ( $info['first'] >= $start && ( null === $best || $info['week'] > $best['pageviews'] ) ) {
				$best = [
					'label'     => vireo_referrer_label( $host ),
					'pageviews' => $info['week'],
				];
			}
		}
		return $best;
	}

	/**
	 * @param array<string,mixed> $facts
	 * @return array{kind:string,text:string}|null
	 */
	private static function milestone( array $facts ): ?array {
		$week = $facts['best_week'] ?? null;
		if ( is_array( $week )
			&& (int) $week['weeks'] >= self::RECORD_MIN_WEEKS
			&& (int) $week['pageviews'] >= self::RECORD_MIN_VIEWS
			&& (int) $week['previous_best'] > 0
			&& (int) $week['pageviews'] > (int) $week['previous_best'] ) {
			return [
				'kind' => 'best_week',
				'text' => sprintf(
					/* translators: 1: pageviews this week, 2: the previous best week's pageviews */
					__( 'Your best week so far: %1$s pageviews, ahead of the previous best of %2$s.', 'vireo-analytics' ),
					number_format_i18n( (int) $week['pageviews'] ),
					number_format_i18n( (int) $week['previous_best'] )
				),
			];
		}

		$site = $facts['site_milestone'] ?? null;
		if ( is_array( $site ) ) {
			return [
				'kind' => 'site_milestone',
				'text' => sprintf(
					/* translators: %s: a round number of pageviews such as 10,000 */
					__( 'The site passed %s pageviews in total.', 'vireo-analytics' ),
					number_format_i18n( (int) $site['threshold'] )
				),
			];
		}

		$post = $facts['post_milestone'] ?? null;
		if ( is_array( $post ) ) {
			return [
				'kind' => 'post_milestone',
				'text' => sprintf(
					/* translators: 1: page title, 2: a round number of views such as 1,000 */
					__( '%1$s passed %2$s views.', 'vireo-analytics' ),
					$post['label'],
					number_format_i18n( (int) $post['threshold'] )
				),
			];
		}

		return null;
	}

	/**
	 * @param array<int,array{date:string,pageviews:int}> $days
	 * @return array{kind:string,text:string}|null
	 */
	private static function best_day( array $days ): ?array {
		if ( count( $days ) < 2 ) {
			return null;
		}

		$best = null;
		foreach ( $days as $day ) {
			if ( null === $best || (int) $day['pageviews'] > (int) $best['pageviews'] ) {
				$best = $day;
			}
		}

		$best_pv = (int) $best['pageviews'];
		$others  = ( array_sum( array_column( $days, 'pageviews' ) ) - $best_pv ) / ( count( $days ) - 1 );
		if ( $best_pv < self::BEST_DAY_MIN_VIEWS || $best_pv < $others * self::BEST_DAY_LIFT ) {
			return null;
		}

		return [
			'kind' => 'best_day',
			'text' => sprintf(
				/* translators: 1: day of the week, 2: number of views */
				_n( '%1$s was the busiest day, with %2$s view.', '%1$s was the busiest day, with %2$s views.', $best_pv, 'vireo-analytics' ),
				vireo_format_day( $best['date'], 'l' ),
				number_format_i18n( $best_pv )
			),
		];
	}

	/** Page titles come back with HTML entities; highlights are plain text. */
	private static function plain( string $label ): string {
		return html_entity_decode( wp_strip_all_tags( $label ), ENT_QUOTES, 'UTF-8' );
	}
}
