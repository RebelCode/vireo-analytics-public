<?php

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * One calendar year on one screen, built from the daily rollups.
 *
 * A hosted tool can only show the years it recorded. Vireo can also show the
 * years it imported, so every month says where its numbers came from: an
 * imported month is credited to the tool that counted it, never passed off as
 * Vireo's own counting.
 *
 * summarize(), origin_lines() and summary_text() are pure and run over rows
 * the caller fetched. build() does the fetching.
 */
final class Year_Review {

	private const DISMISSED_META = 'va_year_review_dismissed';
	private const DISMISS_NONCE  = 'vireo_year_review';
	private const CACHE_GROUP    = 'vireo_yir_';

	/**
	 * Last year's numbers only make a fair baseline when history reaches back to
	 * the start of the window. A site that launched in June would otherwise
	 * report January to May as growth from nothing.
	 */
	private const PRIOR_SLACK_DAYS = 7;

	public static function boot(): void {
		add_action( 'wp_ajax_vireo_dismiss_year_review', [ self::class, 'ajax_dismiss' ] );
	}

	/**
	 * The stretch of $year that has happened by $today, or null when the year
	 * has not started. $today is the site's day, so the year turns over at the
	 * site's midnight, not UTC's.
	 *
	 * @return array{start:string,end:string,partial:bool}|null
	 */
	public static function window( int $year, string $today ): ?array {
		$start = sprintf( '%04d-01-01', $year );
		if ( $today < $start ) {
			return null;
		}

		$last = sprintf( '%04d-12-31', $year );
		$end  = min( $last, $today );

		return [
			'start'   => $start,
			'end'     => $end,
			'partial' => $end < $last,
		];
	}

	/**
	 * The same stretch of the year before. A window ending on Feb 29 compares
	 * against Feb 28, the last day the year before has.
	 *
	 * @return array{0:string,1:string}
	 */
	public static function comparison_window( int $year, string $end ): array {
		$month_day = substr( $end, 5 );
		if ( '02-29' === $month_day ) {
			$month_day = '02-28';
		}

		return [ sprintf( '%04d-01-01', $year - 1 ), sprintf( '%04d-', $year - 1 ) . $month_day ];
	}

	/**
	 * @param int                                                      $year      Calendar year.
	 * @param string                                                   $today     The site's today, Y-m-d.
	 * @param array<int,array{date:string,pageviews:int,visitors:int}> $rows      Daily site totals covering the year and its comparison window.
	 * @param array<int,array{from:string,to:string,source:string}>    $ranges    Import_History::covered_ranges().
	 * @param string|null                                              $first_day The earliest day with any data at all.
	 * @return array<string,mixed>
	 */
	public static function summarize( int $year, string $today, array $rows, array $ranges, ?string $first_day ): array {
		$window = self::window( $year, $today );
		if ( null === $window ) {
			$start  = sprintf( '%04d-01-01', $year );
			$window = [
				'start'   => $start,
				'end'     => Import_History::day_before( $start ),
				'partial' => true,
			];
		}
		[ $prior_start, $prior_end ] = self::comparison_window( $year, $window['end'] );

		$months = [];
		for ( $m = 1; $m <= 12; $m++ ) {
			$month_start = sprintf( '%04d-%02d-01', $year, $m );
			$month_end   = gmdate( 'Y-m-t', (int) strtotime( $month_start . ' 00:00:00 UTC' ) );
			$months[]    = [
				'month'         => $m,
				'start'         => $month_start,
				'end'           => $month_end,
				'pageviews'     => 0,
				'visitors'      => 0,
				'days'          => 0,
				'imported_days' => 0,
				'imported_pv'   => 0,
				'sources'       => [],
				'origin'        => 'none',
				'future'        => $month_start > $window['end'],
				'partial'       => $month_start <= $window['end'] && $month_end > $window['end'],
			];
		}

		$empty    = [
			'pageviews' => 0,
			'visitors'  => 0,
			'days'      => 0,
		];
		$totals   = $empty;
		$imported = $empty + [ 'sources' => [] ];
		$own      = $empty;
		$prior    = $empty + [ 'imported_pageviews' => 0 ];
		$best_day  = null;
		$own_first = null;

		usort( $rows, static fn( array $a, array $b ): int => strcmp( (string) $a['date'], (string) $b['date'] ) );

		foreach ( $rows as $row ) {
			$date = (string) $row['date'];
			$pv   = (int) $row['pageviews'];
			$vis  = (int) $row['visitors'];
			if ( $pv <= 0 && $vis <= 0 ) {
				continue;
			}

			$sources = self::sources_for( $date, $ranges );

			if ( $date >= $prior_start && $date <= $prior_end ) {
				$prior['pageviews'] += $pv;
				$prior['visitors']  += $vis;
				++$prior['days'];
				if ( $sources ) {
					$prior['imported_pageviews'] += $pv;
				}
			}

			if ( $date < $window['start'] || $date > $window['end'] ) {
				continue;
			}

			$i = (int) substr( $date, 5, 2 ) - 1;

			$months[ $i ]['pageviews'] += $pv;
			$months[ $i ]['visitors']  += $vis;
			++$months[ $i ]['days'];

			$totals['pageviews'] += $pv;
			$totals['visitors']  += $vis;
			++$totals['days'];

			if ( $sources ) {
				++$months[ $i ]['imported_days'];
				$months[ $i ]['imported_pv'] += $pv;
				$months[ $i ]['sources'] = self::merge( $months[ $i ]['sources'], $sources );
				$imported['pageviews']  += $pv;
				$imported['visitors']   += $vis;
				++$imported['days'];
				$imported['sources'] = self::merge( $imported['sources'], $sources );
			} else {
				$own['pageviews'] += $pv;
				$own['visitors']  += $vis;
				++$own['days'];
				$own_first = $own_first ?? $date;
			}

			if ( null === $best_day || $pv > $best_day['pageviews'] ) {
				$best_day = [
					'date'      => $date,
					'pageviews' => $pv,
				];
			}
		}

		$best_month = null;
		foreach ( $months as $i => $month ) {
			if ( $month['days'] > 0 ) {
				if ( $month['imported_days'] === $month['days'] ) {
					$months[ $i ]['origin'] = 'imported';
				} elseif ( 0 === $month['imported_days'] ) {
					$months[ $i ]['origin'] = 'own';
				} else {
					$months[ $i ]['origin'] = 'mixed';
				}
			}
			if ( $month['pageviews'] > 0 && ( null === $best_month || $month['pageviews'] > $best_month['pageviews'] ) ) {
				$best_month = [
					'month'     => $month['month'],
					'pageviews' => $month['pageviews'],
				];
			}
		}

		$prior_out = null;
		$covers    = null !== $first_day
			&& $first_day <= gmdate( 'Y-m-d', (int) strtotime( $prior_start . ' +' . self::PRIOR_SLACK_DAYS . ' days UTC' ) );
		if ( $covers && $prior['pageviews'] > 0 ) {
			$prior_kind = self::origin_kind( $prior['imported_pageviews'], $prior['pageviews'] );
			$now_kind   = self::origin_kind( $imported['pageviews'], $totals['pageviews'] );
			$prior_out  = [
				'start'       => $prior_start,
				'end'         => $prior_end,
				'pageviews'   => $prior['pageviews'],
				'visitors'    => $prior['visitors'],
				'imported'    => $prior['imported_pageviews'] > 0,
				'same_origin' => $prior_kind === $now_kind && 'mixed' !== $now_kind,
			];
		}

		return [
			'year'          => $year,
			'start'         => $window['start'],
			'end'           => $window['end'],
			'partial'       => $window['partial'],
			'has_data'      => $totals['days'] > 0,
			'totals'        => $totals,
			'imported'      => $imported,
			'own'           => $own,
			'own_first'     => $own_first,
			'months'        => $months,
			'busiest_month' => $best_month,
			'busiest_day'   => $best_day,
			'prior'         => $prior_out,
		];
	}

	/** Whole-number percentage change, or null with nothing to compare against. */
	public static function pct_change( int $now, int $before ): ?int {
		if ( $before <= 0 ) {
			return null;
		}
		return (int) round( ( $now - $before ) / $before * 100 );
	}

	/**
	 * One sentence per run of months that share an origin, so the screen can
	 * say which part of the year was imported and from where. Empty when the
	 * whole year was Vireo's own counting: there is nothing to credit.
	 *
	 * @param array<int,array<string,mixed>> $months From summarize().
	 * @param array<string,string>           $names  Import source slug => display name.
	 * @return string[]
	 */
	public static function origin_lines( array $months, array $names ): array {
		$any_imported = false;
		foreach ( $months as $month ) {
			if ( $month['imported_days'] > 0 ) {
				$any_imported = true;
				break;
			}
		}
		if ( ! $any_imported ) {
			return [];
		}

		$runs = [];
		$last = null;
		foreach ( $months as $month ) {
			if ( 'none' === $month['origin'] ) {
				$last = null;
				continue;
			}
			$key = $month['origin'] . '|' . implode( ',', $month['sources'] );
			if ( null !== $last && $runs[ $last ]['key'] === $key ) {
				$runs[ $last ]['to'] = $month['month'];
				continue;
			}
			$runs[] = [
				'key'     => $key,
				'origin'  => $month['origin'],
				'sources' => $month['sources'],
				'from'    => $month['month'],
				'to'      => $month['month'],
			];
			$last   = count( $runs ) - 1;
		}

		$lines = [];
		foreach ( $runs as $run ) {
			$span = $run['from'] === $run['to']
				? self::month_name( $run['from'] )
				/* translators: 1: first month, 2: last month, e.g. "January to April" */
				: sprintf( __( '%1$s to %2$s', 'vireo-analytics' ), self::month_name( $run['from'] ), self::month_name( $run['to'] ) );
			$from = self::source_list( $run['sources'], $names );

			if ( 'imported' === $run['origin'] ) {
				/* translators: 1: month or span of months, 2: the tool the history was imported from */
				$lines[] = sprintf( __( '%1$s: imported from %2$s.', 'vireo-analytics' ), $span, $from );
			} elseif ( 'mixed' === $run['origin'] ) {
				/* translators: 1: month or span of months, 2: the tool the history was imported from */
				$lines[] = sprintf( __( '%1$s: partly imported from %2$s, partly counted by Vireo Analytics.', 'vireo-analytics' ), $span, $from );
			} else {
				/* translators: %s: month or span of months */
				$lines[] = sprintf( __( '%s: counted by Vireo Analytics.', 'vireo-analytics' ), $span );
			}
		}

		return $lines;
	}

	/**
	 * A short plain-text recap for the "Copy summary" button. Every figure in it
	 * is one the screen shows. Empty for a year with no data.
	 *
	 * @param array<string,mixed> $review From summarize().
	 * @param array<string,mixed> $extra  site, and optionally top_post/top_source
	 *                                    ({label, pageviews}), top_country and
	 *                                    sources (slug => name).
	 */
	public static function summary_text( array $review, array $extra ): string {
		if ( empty( $review['has_data'] ) ) {
			return '';
		}

		$year  = (int) $review['year'];
		$site  = (string) ( $extra['site'] ?? '' );
		$pv    = (int) $review['totals']['pageviews'];
		$lines = [];

		$lines[] = $review['partial']
			/* translators: 1: site address, 2: year */
			? sprintf( __( '%1$s in %2$s so far', 'vireo-analytics' ), $site, $year )
			/* translators: 1: site address, 2: year */
			: sprintf( __( '%1$s in %2$s', 'vireo-analytics' ), $site, $year );

		$change = null === $review['prior'] ? null : self::pct_change( $pv, (int) $review['prior']['pageviews'] );
		if ( null === $change ) {
			/* translators: %s: number of pageviews */
			$lines[] = sprintf( _n( '%s pageview.', '%s pageviews.', $pv, 'vireo-analytics' ), number_format_i18n( $pv ) );
		} elseif ( $review['partial'] ) {
			/* translators: 1: number of pageviews, 2: signed percentage such as +12%, 3: the previous year */
			$lines[] = sprintf( __( '%1$s pageviews, %2$s on the same stretch of %3$s.', 'vireo-analytics' ), number_format_i18n( $pv ), sprintf( '%+d%%', $change ), $year - 1 );
		} else {
			/* translators: 1: number of pageviews, 2: signed percentage such as +12%, 3: the previous year */
			$lines[] = sprintf( __( '%1$s pageviews, %2$s on %3$s.', 'vireo-analytics' ), number_format_i18n( $pv ), sprintf( '%+d%%', $change ), $year - 1 );
		}

		if ( null !== $review['busiest_month'] && null !== $review['busiest_day'] ) {
			$lines[] = sprintf(
				/* translators: 1: month name, 2: date such as "Mar 14", 3: pageviews that day */
				__( 'Busiest month: %1$s. Busiest day: %2$s, with %3$s pageviews.', 'vireo-analytics' ),
				self::month_name( (int) $review['busiest_month']['month'] ),
				self::short_date( (string) $review['busiest_day']['date'] ),
				number_format_i18n( (int) $review['busiest_day']['pageviews'] )
			);
		}

		if ( ! empty( $extra['top_post']['label'] ) ) {
			/* translators: 1: page title, 2: number of pageviews */
			$lines[] = sprintf( __( 'Top page: %1$s (%2$s pageviews).', 'vireo-analytics' ), $extra['top_post']['label'], number_format_i18n( (int) $extra['top_post']['pageviews'] ) );
		}
		if ( ! empty( $extra['top_source']['label'] ) ) {
			/* translators: 1: referrer name, 2: number of pageviews */
			$lines[] = sprintf( __( 'Top referrer: %1$s (%2$s pageviews).', 'vireo-analytics' ), $extra['top_source']['label'], number_format_i18n( (int) $extra['top_source']['pageviews'] ) );
		}
		if ( ! empty( $extra['top_country'] ) ) {
			/* translators: %s: country name */
			$lines[] = sprintf( __( 'Top country: %s.', 'vireo-analytics' ), $extra['top_country'] );
		}
		if ( ! empty( $review['imported']['sources'] ) ) {
			/* translators: %s: the tools the history was imported from */
			$lines[] = sprintf( __( 'Includes history imported from %s.', 'vireo-analytics' ), self::source_list( $review['imported']['sources'], (array) ( $extra['sources'] ?? [] ) ) );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Which year the Overview notice is about on $today, or 0 outside the
	 * season. It opens on Dec 1 and stays through January, when the year it
	 * describes is the one that just finished.
	 */
	public static function notice_year( string $today ): int {
		$year      = (int) substr( $today, 0, 4 );
		$month_day = substr( $today, 5 );

		if ( $month_day >= '12-01' ) {
			return $year;
		}
		if ( $month_day <= '01-31' ) {
			return $year - 1;
		}
		return 0;
	}

	public static function url( int $year = 0 ): string {
		$args = [
			'page' => 'vireo-analytics',
			'view' => 'year',
			'_vrn' => Dashboard::view_nonce(),
		];
		if ( $year > 0 ) {
			$args['year'] = $year;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( 'view_vireo_analytics' ) ) {
			return;
		}

		$today        = vireo_bucket_date();
		$va_yir_years = self::years( $today );
		$va_yir       = self::build( self::requested_year( $va_yir_years, $today ), $today );

		include VIREO_DIR . 'templates/dashboard/year-review.php';
	}

	/**
	 * Everything the screen shows for one year. Cached, and keyed on the import
	 * history so an import shows up straight away.
	 *
	 * @return array<string,mixed>
	 */
	public static function build( int $year, string $today ): array {
		$runs  = Import_History::all();
		// Labels such as Home are translated before caching, so the locale keys it.
		$key   = self::CACHE_GROUP . md5( $year . '|' . $today . '|' . determine_locale() . '|' . count( $runs ) . '|' . ( $runs[0]['at'] ?? 0 ) );
		$found = get_transient( $key );
		if ( is_array( $found ) ) {
			return $found;
		}

		global $wpdb;
		$site_table = Sites::table( 'site_stats' );

		$last_day    = min( sprintf( '%04d-12-31', $year ), $today );
		$prior_start = self::comparison_window( $year, $last_day )[0];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- transient-cached above
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT date, pageviews, visitors FROM %i WHERE date BETWEEN %s AND %s',
				$site_table,
				$prior_start,
				$last_day
			),
			ARRAY_A
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- transient-cached above
		$first_day = $wpdb->get_var(
			$wpdb->prepare( 'SELECT MIN(date) FROM %i WHERE pageviews > 0 OR visitors > 0', $site_table )
		);

		$review = self::summarize( $year, $today, $rows, Import_History::covered_ranges( $runs ), null === $first_day ? null : (string) $first_day );
		$start  = $review['start'];
		$end    = $review['end'];

		$names = [];
		foreach ( $review['imported']['sources'] as $slug ) {
			$importer       = Import_Controller::instance()->get( $slug );
			$names[ $slug ] = $importer ? $importer->name() : $slug;
		}

		$dashboard = Dashboard::instance();
		$pages     = [];
		foreach ( $dashboard->get_top_pages( $start, $end, 5 ) as $page ) {
			$pages[] = [
				'label'     => Dashboard::page_label( (string) $page['title'], (string) $page['path'] ),
				'url'       => (string) $page['url'],
				'post_id'   => (int) $page['post_id'],
				'pageviews' => (int) $page['pageviews'],
			];
		}

		$referrers = $dashboard->get_top_referrers( $start, $end, 50 );
		$groups    = Dashboard::group_referrers_by_source(
			$referrers,
			$dashboard->get_direct_totals( $start, $end ),
			$dashboard->get_referrer_bucket_totals( $start, $end )
		);
		$sources   = [];
		foreach ( $groups as $type => $group ) {
			if ( (int) $group['pageviews'] <= 0 ) {
				continue;
			}
			$top       = $group['rows'][0] ?? null;
			$sources[] = [
				'type'      => $type,
				'pageviews' => (int) $group['pageviews'],
				'top'       => $top ? vireo_referrer_label( (string) $top['host'] ) : '',
				'top_views' => $top ? (int) $top['pageviews'] : 0,
			];
		}
		usort( $sources, static fn( array $a, array $b ): int => $b['pageviews'] <=> $a['pageviews'] );

		// Countries only over the days that have country data. Imported
		// history carries totals alone, so a year that starts with imports
		// ranked "Unknown" first; the card now names the day coverage begins.
		$coverage  = Country_Coverage::for_range( $start, $end );
		$geo_start = '' !== $coverage['from'] && $coverage['from'] > $start ? $coverage['from'] : $start;
		$countries = [];
		if ( $coverage['known'] > 0 ) {
			$known_in = Country_Coverage::for_range( $geo_start, $end )['known'];
			foreach ( $dashboard->get_countries( $geo_start, $end, 5 ) as $row ) {
				$row['share'] = $known_in > 0 ? round( (int) $row['visitors'] / $known_in * 100, 1 ) : null;
				$countries[]  = $row;
			}
		}

		$gsc     = Search_Console::summary( $start, $end );
		$queries = null === $gsc ? [] : Search_Console::top_queries( $start, $end, 5 );

		// Crawlers are only ever seen by Vireo's own bot filter, while imported
		// history does carry AI referrals. Over the whole year that would divide
		// a part-year of crawling by a full year of referrals, so both sides
		// start on the first day Vireo counted itself.
		$ai_from     = $review['own_first'];
		$ai_crawled  = null === $ai_from ? 0 : AI_Sources::crawl_requests( $ai_from, $end );
		$ai_referred = null === $ai_from ? 0 : (int) AI_Sources::referrals( $ai_from, $end )['total'];

		$top_ref = $referrers[0] ?? null;
		$text    = self::summary_text(
			$review,
			[
				'site'        => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
				'top_post'    => isset( $pages[0] ) ? [
					'label'     => html_entity_decode( wp_strip_all_tags( $pages[0]['label'] ), ENT_QUOTES, 'UTF-8' ),
					'pageviews' => $pages[0]['pageviews'],
				] : null,
				'top_source'  => $top_ref ? [
					'label'     => vireo_referrer_label( (string) $top_ref['host'] ),
					'pageviews' => (int) $top_ref['pageviews'],
				] : null,
				'top_country' => isset( $countries[0] ) ? (string) $countries[0]['name'] : '',
				'sources'     => $names,
			]
		);

		$out = [
			'review'       => $review,
			'origin_lines' => self::origin_lines( $review['months'], $names ),
			'source_names' => $names,
			'pages'        => $pages,
			'new_posts'    => self::new_posts( $year, $start, $end, 5 ),
			'sources'      => $sources,
			'countries'    => $countries,
			'coverage'     => $coverage + [ 'start' => $geo_start ],
			'gsc'          => $gsc,
			'queries'      => $queries,
			'ai'           => [
				'crawled'  => $ai_crawled,
				'referred' => $ai_referred,
				'rate'     => AI_Sources::exchange_rate( $ai_crawled, $ai_referred ),
				'from'     => null !== $ai_from && $ai_from > $start ? $ai_from : '',
			],
			'text'         => $text,
		];

		set_transient( $key, $out, $review['partial'] ? 10 * MINUTE_IN_SECONDS : 12 * HOUR_IN_SECONDS );
		return $out;
	}

	/**
	 * Years with any stored data, newest first. The current year is always
	 * there, so the screen has somewhere to land on a new site.
	 *
	 * @return int[]
	 */
	public static function years( string $today ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one row per day, read once per screen
		$found = (array) $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT LEFT(date, 4) FROM %i WHERE pageviews > 0 OR visitors > 0',
				Sites::table( 'site_stats' )
			)
		);

		$current = (int) substr( $today, 0, 4 );
		$years   = array_map( 'intval', $found );
		$years[] = $current;
		$years   = array_values( array_unique( array_filter( $years, static fn( int $y ): bool => $y > 1970 && $y <= $current ) ) );
		rsort( $years );

		return $years;
	}

	/**
	 * Posts first published in $year, ranked by their views that year.
	 *
	 * @return array<int,array{label:string,url:string,post_id:int,pageviews:int,published:string}>
	 */
	private static function new_posts( int $year, string $start, string $end, int $limit ): array {
		global $wpdb;

		$types = array_values( array_diff( get_post_types( [ 'public' => true ] ), [ 'attachment' ] ) );
		if ( ! $types ) {
			return [];
		}
		$in = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		$sql  = "SELECT s.post_id, SUM(s.pageviews) AS pageviews
			FROM %i s
			INNER JOIN %i p ON p.ID = s.post_id
			WHERE s.date BETWEEN %s AND %s
			  AND p.post_status = 'publish'
			  AND p.post_date BETWEEN %s AND %s
			  AND p.post_type IN ({$in})
			GROUP BY s.post_id
			ORDER BY pageviews DESC
			LIMIT %d";
		$args = array_merge(
			[ Sites::table( 'post_stats' ), $wpdb->posts, $start, $end, $year . '-01-01 00:00:00', $year . '-12-31 23:59:59' ],
			$types,
			[ $limit ]
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- cached by build(); $in is only %s placeholders, bound in $args
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );

		$out = [];
		foreach ( $rows as $row ) {
			$post_id = (int) $row['post_id'];
			$post    = Dashboard::public_post( $post_id );
			$out[]   = [
				'label'     => Dashboard::page_label( $post['title'], '' ),
				'url'       => $post['url'],
				'post_id'   => $post_id,
				'pageviews' => (int) $row['pageviews'],
				'published' => (string) get_the_date( 'M j', $post_id ),
			];
		}
		return $out;
	}

	/** The year in the URL, when it is one with data; otherwise this year. */
	private static function requested_year( array $years, string $today ): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified in Dashboard::request_is_trusted()
		$asked = Dashboard::request_is_trusted() && isset( $_GET['year'] ) ? absint( $_GET['year'] ) : 0;
		return in_array( $asked, $years, true ) ? $asked : (int) substr( $today, 0, 4 );
	}

	/**
	 * The year the Overview notice should announce for this user, or 0: out of
	 * season, already dismissed, or a year with nothing in it.
	 */
	public static function notice_for_user( int $user_id ): int {
		$year = self::notice_year( vireo_bucket_date() );
		if ( 0 === $year || $user_id <= 0 ) {
			return 0;
		}

		$dismissed = get_user_meta( $user_id, self::DISMISSED_META, true );
		if ( is_array( $dismissed ) && in_array( $year, array_map( 'intval', $dismissed ), true ) ) {
			return 0;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- primary-key range probe, one row
		$has = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT 1 FROM %i WHERE date BETWEEN %s AND %s AND pageviews > 0 LIMIT 1',
				Sites::table( 'site_stats' ),
				$year . '-01-01',
				$year . '-12-31'
			)
		);

		return null === $has ? 0 : $year;
	}

	public static function dismiss_nonce(): string {
		return wp_create_nonce( self::DISMISS_NONCE );
	}

	public static function ajax_dismiss(): void {
		if ( ! current_user_can( 'view_vireo_analytics' ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}
		check_ajax_referer( self::DISMISS_NONCE, 'nonce' );

		$year = isset( $_POST['year'] ) ? absint( wp_unslash( $_POST['year'] ) ) : 0;
		if ( $year < 2000 ) {
			wp_send_json_error( [ 'message' => 'bad year' ], 400 );
		}

		$user_id   = get_current_user_id();
		$dismissed = get_user_meta( $user_id, self::DISMISSED_META, true );
		$dismissed = is_array( $dismissed ) ? array_map( 'intval', $dismissed ) : [];
		if ( ! in_array( $year, $dismissed, true ) ) {
			$dismissed[] = $year;
		}
		update_user_meta( $user_id, self::DISMISSED_META, array_slice( $dismissed, -10 ) );

		wp_send_json_success();
	}

	public static function month_name( int $month ): string {
		return wp_date( 'F', (int) gmmktime( 12, 0, 0, $month, 15, 2000 ) );
	}

	public static function short_date( string $date ): string {
		return vireo_format_day( $date, 'M j' );
	}

	/**
	 * @param string[] $have
	 * @param string[] $add
	 * @return string[]
	 */
	private static function merge( array $have, array $add ): array {
		foreach ( $add as $slug ) {
			if ( ! in_array( $slug, $have, true ) ) {
				$have[] = $slug;
			}
		}
		return $have;
	}

	/**
	 * @param string                                                $date   Y-m-d.
	 * @param array<int,array{from:string,to:string,source:string}> $ranges Import_History::covered_ranges().
	 * @return string[]
	 */
	private static function sources_for( string $date, array $ranges ): array {
		$out = [];
		foreach ( $ranges as $range ) {
			if ( $date >= $range['from'] && $date <= $range['to'] && ! in_array( $range['source'], $out, true ) ) {
				$out[] = $range['source'];
			}
		}
		return $out;
	}

	private static function origin_kind( int $imported, int $total ): string {
		if ( 0 === $imported ) {
			return 'own';
		}
		return $imported >= $total ? 'imported' : 'mixed';
	}

	/**
	 * @param string[]             $slugs
	 * @param array<string,string> $names
	 */
	private static function source_list( array $slugs, array $names ): string {
		return implode( ', ', array_map( static fn( string $slug ): string => $names[ $slug ] ?? $slug, $slugs ) );
	}
}
