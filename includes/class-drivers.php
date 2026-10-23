<?php
/**
 * Drivers of change — what moved, and whether anything is broken.
 *
 * The rest of the dashboard states facts. This works out which of those facts
 * explain the number in the hero row, so a site owner doesn't have to read five
 * leaderboards to find out why traffic fell.
 *
 * Everything here is arithmetic over data we already have. No model is asked to
 * compute a figure: the Pro narrative only puts sentences around rows this class
 * has already worked out.
 */

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

final class Drivers {

	private const CACHE_TTL   = 30;
	private const CACHE_GROUP = 'vireo_drivers_';

	/**
	 * A mover has to carry at least this share of the total change before we're
	 * willing to call it "the reason". Below it, the drop is diffuse and there is
	 * no single story to tell, so the headline doesn't invent one.
	 */
	private const HEADLINE_SHARE = 0.25;

	/** A share that moves less than this is noise, not a shift. */
	private const MIX_MIN_PP = 3.0;

	/** 404s have to be both up sharply AND actually numerous to be worth saying. */
	private const NOT_FOUND_SPIKE_PCT = 50;
	private const NOT_FOUND_MIN_HITS  = 20;

	/** Traffic swings on a tiny site are meaningless; require both a % and a floor. */
	private const ANOMALY_PCT       = 40;
	private const ANOMALY_MIN_PRIOR = 100;

	/** Below this daily average, "no pageviews today" is just a quiet site. */
	private const TRACKING_MIN_DAILY = 5;

	private Dashboard $dashboard;

	public function __construct( Dashboard $dashboard ) {
		$this->dashboard = $dashboard;
	}

	/**
	 * Everything the drivers card renders.
	 *
	 * @param int $limit How many gainers and losers to return (free shows 3).
	 *
	 * Pages and sources are separate groups, each with its own gained/lost, so the
	 * card never mixes content with traffic sources in one ranking.
	 *
	 * @return array{
	 *   headline: string,
	 *   detail: string,
	 *   pages: array{gained: array<int, array{label:string,url:string,delta:int}>, lost: array<int, array>},
	 *   sources: array{gained: array<int, array>, lost: array<int, array>},
	 *   mix: string,
	 *   health: array<int, array{level:string,text:string,url:string}>
	 * }
	 */
	public function get( string $start, string $end, bool $compare, int $limit = 3, ?Filters $filters = null ): array {
		$health = $this->get_health( $start, $end, $compare );

		// With no baseline there is no change to explain. Say nothing rather than
		// dress up the current totals as movement.
		if ( ! $compare ) {
			return [
				'headline' => '',
				'detail'   => '',
				'pages'    => [ 'gained' => [], 'lost' => [] ],
				'sources'  => [ 'gained' => [], 'lost' => [] ],
				'mix'      => '',
				'health'   => $health,
			];
		}

		// A filter combination the rollups cannot answer has no totals to
		// explain either; see Filters::supports().
		if ( $filters instanceof Filters && ! $filters->supports( 'totals' ) ) {
			return [
				'headline' => '',
				'detail'   => '',
				'pages'    => [ 'gained' => [], 'lost' => [] ],
				'sources'  => [ 'gained' => [], 'lost' => [] ],
				'mix'      => '',
				'health'   => $health,
			];
		}

		$hero  = $this->dashboard->get_hero_stats( $start, $end, true, $filters );
		$since = self::untracked_headline( self::tracking_since(), $start, (int) ( $hero['prior']['pageviews'] ?? 0 ) );

		// The range reaches back before Vireo recorded anything itself, so the
		// "change" is imported history meeting an untracked gap. Nothing to explain.
		if ( '' !== $since ) {
			return [
				'headline' => $since,
				'detail'   => '',
				'pages'    => [ 'gained' => [], 'lost' => [] ],
				'sources'  => [ 'gained' => [], 'lost' => [] ],
				'mix'      => '',
				'health'   => $health,
			];
		}

		$movers = $this->get_movers( $start, $end, $limit, $filters );

		return [
			'headline' => $this->headline( $hero ),
			'detail'   => $this->detail( $hero, $movers ),
			'pages'    => $movers['pages'],
			'sources'  => $movers['sources'],
			// The mix is the whole site's countries and devices, which under a
			// filter would describe traffic the card is not showing.
			'mix'      => $filters instanceof Filters && $filters->is_active() ? '' : $this->mix_shift( $start, $end ),
			'health'   => $health,
		];
	}

	/**
	 * Pages and referrers, ranked by how much they moved the pageview count.
	 *
	 * Countries and devices are deliberately excluded: they are shares of the same
	 * traffic, not sources of it. A country "gaining" pageviews is the same visit
	 * counted a second way, so listing it alongside the page it landed on would
	 * double-count the explanation. They get the mix line instead.
	 *
	 * @return array{gained: array<int, array>, lost: array<int, array>, all: array<int, array>}
	 */
	private function get_movers( string $start, string $end, int $limit, ?Filters $filters = null ): array {
		$frag  = $filters ? $filters->cache_fragment() : 'none';
		// The locale is in the key because labels such as Home are translated.
		$key   = self::CACHE_GROUP . 'movers_' . md5( Sites::current() . '|' . determine_locale() . '|' . Dashboard::compare_mode() . '|' . $start . '|' . $end . '|' . $limit . '|' . $frag );
		$hit   = get_transient( $key );
		if ( is_array( $hit ) ) {
			return $hit;
		}

		// The candidates have to be gathered by how much they MOVED, not by how big
		// they are now.
		//
		// get_top_pages() and get_top_referrers() rank by current pageviews. A page
		// that fell from 1,200 views to 5 has almost no current traffic, so it sits
		// nowhere near the top of that list — and it is the single biggest mover on
		// the site. Over-fetching does not rescue it: on any site with more pages
		// than the fetch depth it is simply never a candidate, and the losers column
		// comes back empty while the headline reports a fall it cannot explain.
		//
		// So each dimension gets its own query, summing both windows in one indexed
		// scan and ordering by the size of the change.
		[ $prv_start, $prv_end ] = Dashboard::compare_range( $start, $end );

		$depth = max( 25, $limit * 5 );
		$rows  = array_merge(
			$this->page_movers( $start, $end, $prv_start, $prv_end, $depth, Dashboard::compare_weight( $start, $end ), $filters ),
			$this->referrer_movers( $start, $end, $prv_start, $prv_end, $depth, $filters, Dashboard::compare_weight( $start, $end ) )
		);

		// Pages and referrers are ranked separately, not in one pool. They measure
		// the same pageviews from two angles: when google.com sends fewer visits,
		// the pages that lost them show the same drop, so a single merged list
		// double-counts the change and reads as a jumble of causes and effects.
		// Pages answer "which content moved", sources answer "why", and the card
		// shows them as two labelled groups.
		$split = static function ( array $set ) use ( $limit ): array {
			$gained = array_values( array_filter( $set, static fn( array $r ): bool => $r['delta'] > 0 ) );
			$lost   = array_values( array_filter( $set, static fn( array $r ): bool => $r['delta'] < 0 ) );
			usort( $gained, static fn( array $a, array $b ): int => $b['delta'] <=> $a['delta'] );
			usort( $lost, static fn( array $a, array $b ): int => $a['delta'] <=> $b['delta'] );
			return [
				'gained' => array_slice( $gained, 0, $limit ),
				'lost'   => array_slice( $lost, 0, $limit ),
			];
		};

		$pages   = array_filter( $rows, static fn( array $r ): bool => 'page' === $r['kind'] );
		$sources = array_filter( $rows, static fn( array $r ): bool => 'referrer' === $r['kind'] );

		// The headline still names the single biggest mover across both as the
		// cause, so it keeps a combined view ranked by absolute change.
		$all = $rows;
		usort( $all, static fn( array $a, array $b ): int => abs( $b['delta'] ) <=> abs( $a['delta'] ) );

		$result = [
			'pages'   => $split( $pages ),
			'sources' => $split( $sources ),
			'all'     => $all,
		];

		set_transient( $key, $result, self::CACHE_TTL );
		return $result;
	}

	/**
	 * Pages that moved, ranked by the size of the move.
	 *
	 * Both windows are summed in one scan over a date-indexed range, so this costs
	 * a single query rather than one per period.
	 *
	 * @return array<int, array{label:string,url:string,kind:string,delta:int}>
	 */
	private function page_movers( string $cur_start, string $cur_end, string $prv_start, string $prv_end, int $depth, float $weight = 1.0, ?Filters $filters = null ): array {
		global $wpdb;

		// One page is not a list of movers, and a device or country filter has
		// no per-page rollup to rank; see Filters::supports().
		if ( $filters instanceof Filters && ( $filters->has_page() || ! $filters->supports( 'pages' ) ) ) {
			return [];
		}
		// Under a source or referrer filter the pages come from the post ×
		// referrer table instead.
		if ( $filters instanceof Filters && ( '' !== $filters->source || $filters->has_ref() ) ) {
			return $this->page_movers_by_referrer( $cur_start, $cur_end, $prv_start, $prv_end, $depth, $weight, $filters );
		}

		$stats = Sites::table( 'post_stats' );
		$paths = Sites::table( 'paths' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- transient-cached by the caller
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// The aggregate is wrapped in a derived table: MariaDB will not let
				// ABS() take an alias that refers to a group function.
				// Grouped by path alone, so a page recorded both with and without its
				// post ID (the front page as a bare "/") is one row, not two.
				"SELECT t.post_id, t.path, t.delta FROM (
				     SELECT MAX(s.post_id) AS post_id,
				            p.path AS path,
				            ROUND(SUM(CASE WHEN s.date BETWEEN %s AND %s THEN s.pageviews ELSE 0 END)
				          - SUM(CASE WHEN s.date BETWEEN %s AND %s THEN s.pageviews ELSE 0 END) * %f) AS delta
				     FROM {$stats} s
				     LEFT JOIN {$paths} p ON p.id = s.path_id
				     WHERE s.date BETWEEN %s AND %s
				     GROUP BY p.path
				 ) t
				 WHERE t.delta <> 0
				 ORDER BY ABS(t.delta) DESC
				 LIMIT %d",
				$cur_start,
				$cur_end,
				$prv_start,
				$prv_end,
				$weight,
				$prv_start,
				$cur_end,
				$depth
			),
			ARRAY_A
		);

		$out = [];
		foreach ( (array) $rows as $r ) {
			$post_id = (int) $r['post_id'];
			$title   = Dashboard::public_post( $post_id )['title'];
			$path    = (string) $r['path'];
			$out[]   = [
				'label' => Dashboard::page_label( $title, $path ),
				'url'   => $path,
				'kind'  => 'page',
				'delta' => (int) $r['delta'],
			];
		}

		return $out;
	}

	/**
	 * Pages that moved under a source or referrer filter, from the post ×
	 * referrer table. Direct is left out: subtracting two moving totals per
	 * post explains nothing a reader can act on.
	 *
	 * @return array<int, array{label:string,url:string,kind:string,delta:int}>
	 */
	private function page_movers_by_referrer( string $cur_start, string $cur_end, string $prv_start, string $prv_end, int $depth, float $weight, Filters $filters ): array {
		global $wpdb;

		if ( $filters->is_direct() ) {
			return [];
		}
		$q = $filters->referrer_query( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- transient-cached by the caller; the fragments are literals from Filters with every value bound in the args
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT t.post_id, t.delta FROM (
				     SELECT s.post_id,
				            ROUND(SUM(CASE WHEN s.date BETWEEN %s AND %s THEN s.pageviews ELSE 0 END)
				          - SUM(CASE WHEN s.date BETWEEN %s AND %s THEN s.pageviews ELSE 0 END) * %f) AS delta
				     FROM ' . $q['from'] . '
				     WHERE s.date BETWEEN %s AND %s' . $q['where'] . '
				     GROUP BY s.post_id
				 ) t
				 WHERE t.delta <> 0
				 ORDER BY ABS(t.delta) DESC
				 LIMIT %d',
				array_merge( [ $cur_start, $cur_end, $prv_start, $prv_end, $weight ], $q['tables'], [ $prv_start, $cur_end ], $q['args'], [ $depth ] )
			),
			ARRAY_A
		);

		$out = [];
		foreach ( (array) $rows as $r ) {
			$post  = Dashboard::public_post( (int) $r['post_id'] );
			$path  = '' !== $post['url'] ? (string) wp_parse_url( $post['url'], PHP_URL_PATH ) : '';
			$out[] = [
				'label' => Dashboard::page_label( $post['title'], $path ),
				'url'   => $path,
				'kind'  => 'page',
				'delta' => (int) $r['delta'],
			];
		}

		return $out;
	}

	/**
	 * Referrers that moved, ranked by the size of the move. Several URLs fold into
	 * one host, so the folding happens here rather than in SQL.
	 *
	 * @return array<int, array{label:string,url:string,kind:string,delta:int}>
	 */
	private function referrer_movers( string $cur_start, string $cur_end, string $prv_start, string $prv_end, int $depth, ?Filters $filters = null, float $weight = 1.0 ): array {
		global $wpdb;

		if ( $filters instanceof Filters && ! $filters->supports( 'referrers' ) ) {
			return [];
		}

		// The rows the filters select: a source bucket, a host, or the
		// referrers of one page from the post × referrer table.
		$q = ( $filters instanceof Filters ? $filters : new Filters() )->referrer_query();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- transient-cached by the caller; the fragments are literals from Filters with every value bound in the args
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT t.url, t.delta FROM (
				     SELECT u.url AS url,
				            ROUND(SUM(CASE WHEN s.date BETWEEN %s AND %s THEN s.pageviews ELSE 0 END)
				          - SUM(CASE WHEN s.date BETWEEN %s AND %s THEN s.pageviews ELSE 0 END) * %f) AS delta
				     FROM ' . $q['from'] . '
				     WHERE s.date BETWEEN %s AND %s' . $q['where'] . '
				     GROUP BY u.url
				 ) t
				 WHERE t.delta <> 0
				 ORDER BY ABS(t.delta) DESC
				 LIMIT %d',
				// Fold to hosts after, so leave room.
				array_merge( [ $cur_start, $cur_end, $prv_start, $prv_end, $weight ], $q['tables'], [ $prv_start, $cur_end ], $q['args'], [ $depth * 2 ] )
			),
			ARRAY_A
		);

		// The site's own domain shows up as a referrer whenever a visitor moves
		// between pages. That's internal navigation, not a traffic source, so it
		// has no place in "which sources moved", and it stops the site listing
		// itself as a mover.
		$own = vireo_own_hosts();
		// A host whose visits show no engagement is not a mover worth naming;
		// it stays in the referrer card, tagged, for the admin to block.
		$suspects = Referrer_Spam::suspects( array_column( (array) $rows, 'url' ), $cur_start, $cur_end );

		$by_host = [];
		foreach ( (array) $rows as $r ) {
			$host = vireo_canonical_host( (string) $r['url'] );
			if ( '' === $host || in_array( $host, $own, true ) || vireo_is_internal_host( $host ) || in_array( (string) $r['url'], $suspects, true ) ) {
				continue;
			}
			if ( ! isset( $by_host[ $host ] ) ) {
				$by_host[ $host ] = [
					'label' => vireo_referrer_label( $host ),
					'url'   => '',
					'kind'  => 'referrer',
					'delta' => 0,
				];
			}
			$by_host[ $host ]['delta'] += (int) $r['delta'];
		}

		// Folding can cancel a host out to zero; it is not a mover then.
		return array_values(
			array_filter( $by_host, static fn( array $r ): bool => 0 !== $r['delta'] )
		);
	}

	/**
	 * The day Vireo itself first recorded, or '' when unknown (installs that
	 * predate the option, where nothing is held back).
	 */
	public static function tracking_since(): string {
		$since = (string) get_option( Installer::TRACKING_SINCE_OPTION, '' );
		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $since ) ? $since : '';
	}

	/**
	 * Neutral line for a range that starts before tracking did, when imported
	 * history gives it a prior period to misread. '' means compare as usual.
	 */
	public static function untracked_headline( string $since, string $start, int $prior ): string {
		if ( '' === $since || $start >= $since || $prior <= 0 ) {
			return '';
		}

		/* translators: %s: the first day Vireo recorded, e.g. Oct 21, 2026 */
		return sprintf( __( 'Vireo started recording on %s. Comparisons start once a full period is in.', 'vireo-analytics' ), vireo_format_day( $since ) );
	}

	/**
	 * "Pageviews are down 25%." Plain, and always true.
	 */
	private function headline( array $hero ): string {
		$prior = (int) ( $hero['prior']['pageviews'] ?? 0 );
		$now   = (int) ( $hero['pageviews'] ?? 0 );

		if ( $prior <= 0 ) {
			return $now > 0 ? __( 'No prior period to compare against yet.', 'vireo-analytics' ) : '';
		}

		$pct = (int) round( ( ( $now - $prior ) / $prior ) * 100 );

		if ( 0 === $pct ) {
			return 'year' === Dashboard::compare_mode()
				? __( 'Pageviews are flat on the same period last year.', 'vireo-analytics' )
				: __( 'Pageviews are flat on the prior period.', 'vireo-analytics' );
		}

		return $pct > 0
			/* translators: %d: percentage increase */
			? sprintf( __( 'Pageviews are up %d%%.', 'vireo-analytics' ), $pct )
			/* translators: %d: percentage decrease, without the minus sign */
			: sprintf( __( 'Pageviews are down %d%%.', 'vireo-analytics' ), abs( $pct ) );
	}

	/**
	 * The second sentence, naming a cause — but only when one exists.
	 *
	 * A single mover has to account for a quarter of the whole change before we
	 * point at it. A drop spread evenly over forty pages has no headline cause, and
	 * saying "most of the drop is /some-page" when that page is 4% of it would be a
	 * lie the numbers underneath immediately contradict.
	 */
	private function detail( array $hero, array $movers ): string {
		$prior = (int) ( $hero['prior']['pageviews'] ?? 0 );
		$now   = (int) ( $hero['pageviews'] ?? 0 );
		$total = $now - $prior;

		if ( 0 === $total || empty( $movers['all'] ) ) {
			return '';
		}

		$top = $movers['all'][0];

		// The biggest mover has to point the same way as the total. A page that grew
		// during an overall collapse is not the explanation for the collapse.
		if ( ( $total > 0 ) !== ( $top['delta'] > 0 ) ) {
			return '';
		}

		$share = abs( $total ) > 0 ? abs( $top['delta'] ) / abs( $total ) : 0.0;
		if ( $share < self::HEADLINE_SHARE ) {
			return '';
		}

		if ( $total < 0 ) {
			return 'referrer' === $top['kind']
				/* translators: %s: referrer host, e.g. google.com */
				? sprintf( __( 'Most of the drop is traffic that stopped coming from %s.', 'vireo-analytics' ), $top['label'] )
				/* translators: %s: page title */
				: sprintf( __( 'Most of the drop is %s.', 'vireo-analytics' ), $top['label'] );
		}

		return 'referrer' === $top['kind']
			/* translators: %s: referrer host */
			? sprintf( __( 'Most of the rise is new traffic from %s.', 'vireo-analytics' ), $top['label'] )
			/* translators: %s: page title */
			: sprintf( __( 'Most of the rise is %s.', 'vireo-analytics' ), $top['label'] );
	}

	/**
	 * The single biggest shift in the device mix, in percentage points.
	 *
	 * Only worth a line when it actually moved. A 0.4pp wobble between desktop and
	 * mobile is what every site does every week.
	 */
	private function mix_shift( string $start, string $end ): string {
		$devices = $this->dashboard->get_devices( $start, $end, true );
		$rows    = $devices['devices'] ?? [];

		$best = null;
		foreach ( $rows as $r ) {
			$pp = isset( $r['pp_delta'] ) ? (float) $r['pp_delta'] : 0.0;
			if ( null === $best || abs( $pp ) > abs( (float) $best['pp_delta'] ) ) {
				$best = $r;
			}
		}

		if ( null === $best || abs( (float) $best['pp_delta'] ) < self::MIX_MIN_PP ) {
			return '';
		}

		$pp    = (float) $best['pp_delta'];
		$label = (string) $best['label'];

		return $pp > 0
			/* translators: 1: device type e.g. Mobile, 2: percentage points */
			? sprintf( __( '%1$s took %2$s more of the mix.', 'vireo-analytics' ), $label, number_format_i18n( $pp, 1 ) . 'pp' )
			/* translators: 1: device type, 2: percentage points */
			: sprintf( __( '%1$s lost %2$s of the mix.', 'vireo-analytics' ), $label, number_format_i18n( abs( $pp ), 1 ) . 'pp' );
	}

	/**
	 * Things that are wrong, rather than things that changed.
	 *
	 * @return array<int, array{level:string,text:string,url:string}>
	 */
	public function get_health( string $start, string $end, bool $compare = true ): array {
		$out = [];

		$tracking = $this->check_tracking_stopped();
		if ( '' !== $tracking ) {
			$out[] = [
				'level' => 'error',
				'text'  => $tracking,
				'url'   => admin_url( 'admin.php?page=' . Settings::PAGE_SLUG ),
			];
		}

		$geo = $this->check_geo();
		if ( '' !== $geo ) {
			$out[] = [
				'level' => 'warn',
				'text'  => $geo,
				'url'   => admin_url( 'admin.php?page=' . Settings::PAGE_SLUG ),
			];
		}

		$not_found = $this->check_404_spike( $start, $end );
		if ( '' !== $not_found ) {
			$out[] = [ 'level' => 'warn', 'text' => $not_found, 'url' => '' ];
		}

		// Only worth saying when nothing else is saying it. With compare on, the
		// headline already opens with "Pageviews are down 42%", so an alert repeating
		// that under it is noise dressed as a warning. With compare off there is no
		// headline, and a 40% cliff is worth flagging even unasked.
		if ( ! $compare ) {
			$anomaly = $this->check_anomaly( $start, $end );
			if ( '' !== $anomaly ) {
				$out[] = [ 'level' => 'warn', 'text' => $anomaly, 'url' => '' ];
			}
		}

		return $out;
	}

	/**
	 * The alert nothing else gives you: a beacon that has stopped firing looks
	 * exactly like a site nobody visited. Compare the last complete day against the
	 * week before it.
	 */
	private function check_tracking_stopped(): string {
		global $wpdb;
		$table = Sites::table( 'site_stats' );

		$today     = vireo_bucket_date();
		$yesterday = gmdate( 'Y-m-d', strtotime( '-1 day', strtotime( $today ) ) );
		$week_ago  = gmdate( 'Y-m-d', strtotime( '-8 days', strtotime( $today ) ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- cheap, and cached by the caller's transient
		$last = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COALESCE(SUM(pageviews),0) FROM %i WHERE date = %s', $table, $yesterday )
		);
		if ( $last > 0 ) {
			return '';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- as above
		$before = (float) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(AVG(pageviews),0) FROM %i WHERE date BETWEEN %s AND %s',
				$table,
				$week_ago,
				gmdate( 'Y-m-d', strtotime( '-2 days', strtotime( $today ) ) )
			)
		);

		if ( $before < self::TRACKING_MIN_DAILY ) {
			return '';
		}

		return __( 'No pageviews were recorded yesterday, but this site was busy the week before. Tracking may have stopped.', 'vireo-analytics' );
	}

	/**
	 * Every visitor landing as Unknown. Mark hit this on a fresh install.
	 */
	private function check_geo(): string {
		if ( class_exists( Geo_Lookup::class ) && Geo_Lookup::resolves_countries() ) {
			return '';
		}

		global $wpdb;
		$table = Sites::table( 'geo_stats' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- cached by caller
		$resolved = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE country_code <> %s AND country_code <> %s', $table, 'XX', '' )
		);
		if ( $resolved > 0 ) {
			return '';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- cached by caller
		$unknown = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE country_code = %s', $table, 'XX' ) );
		if ( $unknown < 1 ) {
			return '';
		}

		return __( 'Every visitor is being recorded as Unknown. Countries will stay empty until geo-location is set up.', 'vireo-analytics' );
	}

	private function check_404_spike( string $start, string $end ): string {
		global $wpdb;
		$table = Sites::table( '404_stats' );

		[ $ps, $pe ] = Dashboard::compare_range( $start, $end );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- cached by caller
		$now = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COALESCE(SUM(hits),0) FROM %i WHERE date BETWEEN %s AND %s', $table, $start, $end )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- cached by caller
		$before = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COALESCE(SUM(hits),0) FROM %i WHERE date BETWEEN %s AND %s', $table, $ps, $pe )
		);

		if ( $now < self::NOT_FOUND_MIN_HITS || $before < 1 ) {
			return '';
		}

		$pct = (int) round( ( ( $now - $before ) / $before ) * 100 );
		if ( $pct < self::NOT_FOUND_SPIKE_PCT ) {
			return '';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- cached by caller
		$worst = (string) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT path FROM %i WHERE date BETWEEN %s AND %s GROUP BY path ORDER BY SUM(hits) DESC LIMIT 1',
				$table,
				$start,
				$end
			)
		);

		return sprintf(
			/* translators: 1: percentage increase, 2: the worst-offending path */
			__( '404 errors are up %1$d%%, mostly on %2$s.', 'vireo-analytics' ),
			$pct,
			'' !== $worst ? $worst : '/'
		);
	}

	private function check_anomaly( string $start, string $end ): string {
		$hero  = $this->dashboard->get_hero_stats( $start, $end, true, null );
		$prior = (int) ( $hero['prior']['pageviews'] ?? 0 );

		if ( '' !== self::untracked_headline( self::tracking_since(), $start, $prior ) ) {
			return '';
		}
		$now   = (int) ( $hero['pageviews'] ?? 0 );

		if ( $prior < self::ANOMALY_MIN_PRIOR ) {
			return '';
		}

		$pct = (int) round( ( ( $now - $prior ) / $prior ) * 100 );
		if ( abs( $pct ) < self::ANOMALY_PCT ) {
			return '';
		}

		return $pct < 0
			/* translators: %d: percentage drop, without the minus sign */
			? sprintf( __( 'Traffic is down %d%% on the prior period, well beyond normal variation.', 'vireo-analytics' ), abs( $pct ) )
			/* translators: %d: percentage rise */
			: sprintf( __( 'Traffic is up %d%% on the prior period, well beyond normal variation.', 'vireo-analytics' ), $pct );
	}
}
