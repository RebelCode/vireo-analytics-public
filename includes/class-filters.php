<?php
/**
 * Overview filter state.
 *
 * Reads the filter params from the request, normalizes them, and hands the
 * dashboard queries SQL fragments + bind args they can slot into their
 * FROM/WHERE clauses without knowing the filter implementation.
 *
 * Vireo stores daily rollups, not raw hits, so a filter can only be answered
 * by a table that carries its dimension:
 *
 *   device_stats          device
 *   geo_stats             country
 *   referrer_stats        source bucket, referrer host
 *   post_stats            page
 *   post_referrer_stats   page × (source bucket, referrer host)
 *
 * No table crosses device or country with anything else, so those two filters
 * stand alone. Page, source and referrer combine through the post × referrer
 * table. Anything else is reported as unsupported by supports(), and the
 * dashboard shows it as unavailable rather than answering from the wrong table.
 */

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

final class Filters {

	public const DEVICES = [ 'mobile', 'desktop', 'tablet' ];
	public const SOURCES = [ 'direct', 'search', 'ai', 'social', 'referral' ];

	/** Every request param a filter can arrive in. */
	public const PARAMS = [ 'device', 'country', 'source', 'ref', 'path' ];

	/** Defined once in va-filters.php; see VIREO_SEARCH_PATTERN. */
	public const SEARCH_PATTERN = \VIREO_SEARCH_PATTERN;
	public const SOCIAL_PATTERN = \VIREO_SOCIAL_PATTERN;

	/** @var string device_type value ('' = any) */
	public string $device = '';

	/** @var string ISO-3166-1 alpha-2 uppercase ('' = any) */
	public string $country = '';

	/** @var string one of SOURCES ('' = any) */
	public string $source = '';

	/** @var string referrer host, canonical (lowercase, no www.) ('' = any) */
	public string $ref = '';

	/** @var string page path as stored in the paths table ('' = any) */
	public string $path = '';

	/** @var int|null Post ID the page filter resolves to, looked up once. */
	private ?int $path_post = null;

	/**
	 * Build filter state from the current request.
	 */
	public static function from_request(): self {
		// Segment filters are read-only view state; honor them only on a
		// trusted (nonce-stamped) request. See Dashboard::request_is_trusted().
		if ( ! Dashboard::request_is_trusted() ) {
			return new self();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in Dashboard::request_is_trusted(); every value is sanitized in from_params()
		return self::from_params( (array) wp_unslash( $_GET ) );
	}

	/**
	 * Build filter state from a param array: the request, or a saved view.
	 * Anything that is not a known, well-formed value is dropped.
	 *
	 * @param array<string,mixed> $params
	 */
	public static function from_params( array $params ): self {
		$f = new self();

		if ( isset( $params['device'] ) ) {
			$d = strtolower( sanitize_key( (string) $params['device'] ) );
			if ( in_array( $d, self::DEVICES, true ) ) {
				$f->device = $d;
			}
		}

		if ( isset( $params['country'] ) ) {
			$c = strtoupper( sanitize_key( (string) $params['country'] ) );
			if ( preg_match( '/^[A-Z]{2}$/', $c ) ) {
				$f->country = $c;
			}
		}

		if ( isset( $params['source'] ) ) {
			$s = strtolower( sanitize_key( (string) $params['source'] ) );
			if ( in_array( $s, self::SOURCES, true ) ) {
				$f->source = $s;
			}
		}

		if ( isset( $params['ref'] ) ) {
			$f->ref = self::clean_host( (string) $params['ref'] );
		}

		if ( isset( $params['path'] ) ) {
			$f->path = self::clean_path( (string) $params['path'] );
		}

		return $f;
	}

	/**
	 * A referrer host as the dashboard lists it: lowercase, no www., no
	 * scheme or path. Android app referrers are package names, which pass.
	 */
	public static function clean_host( string $raw ): string {
		$host = vireo_canonical_host( sanitize_text_field( $raw ) );
		if ( '' === $host || strlen( $host ) > 190 || ! preg_match( '/^[a-z0-9][a-z0-9.\-_]*$/', $host ) ) {
			return '';
		}
		return $host;
	}

	/**
	 * A page path as the paths table stores it: leading slash, no scheme,
	 * host, query or fragment.
	 */
	public static function clean_path( string $raw ): string {
		$path = trim( sanitize_text_field( $raw ) );
		if ( '' === $path || strlen( $path ) > 500 || '/' !== $path[0] ) {
			return '';
		}
		if ( false !== strpos( $path, '?' ) || false !== strpos( $path, '#' ) || false !== strpos( $path, '//' ) ) {
			return '';
		}
		return $path;
	}

	public function is_active(): bool {
		return [] !== $this->dims();
	}

	public function is_direct(): bool {
		return 'direct' === $this->source;
	}

	public function has_page(): bool {
		return '' !== $this->path;
	}

	public function has_ref(): bool {
		return '' !== $this->ref;
	}

	/**
	 * The active dimensions, in a fixed order.
	 *
	 * @return string[] Subset of device, country, source, ref, page.
	 */
	public function dims(): array {
		$out = [];
		if ( '' !== $this->device ) {
			$out[] = 'device';
		}
		if ( '' !== $this->country ) {
			$out[] = 'country';
		}
		if ( '' !== $this->source ) {
			$out[] = 'source';
		}
		if ( '' !== $this->ref ) {
			$out[] = 'ref';
		}
		if ( '' !== $this->path ) {
			$out[] = 'page';
		}
		return $out;
	}

	/**
	 * Whether a part of the dashboard can be answered under these filters.
	 *
	 * totals     The hero numbers, the daily chart and its sparklines.
	 * pages      The Top pages card.
	 * referrers  The Top referrers card and its group totals.
	 * countries  The Top countries card and map.
	 * devices    The Technology card.
	 * hours      The hourly chart. No rollup splits hours by anything.
	 *
	 * The rules follow the tables listed at the top of this file. A page
	 * crossed with a source or referrer needs the post × referrer table, which
	 * only holds pages that are posts, so a path with no post behind it can be
	 * filtered on its own and nothing more.
	 */
	public function supports( string $what ): bool {
		$dims = $this->dims();
		if ( [] === $dims ) {
			return true;
		}

		switch ( $what ) {
			case 'hours':
				return false;

			case 'countries':
				return [ 'country' ] === $dims;

			case 'devices':
				return [ 'device' ] === $dims;

			case 'totals':
			case 'pages':
			case 'referrers':
				if ( [ 'device' ] === $dims || [ 'country' ] === $dims ) {
					return 'totals' === $what;
				}
				if ( [] !== array_diff( $dims, [ 'source', 'ref', 'page' ] ) ) {
					return false;
				}
				// Page × source or referrer, and the referrers of a page, read
				// the post × referrer table, which needs a post ID.
				if ( $this->has_page() && ( [ 'page' ] !== $dims || 'referrers' === $what ) ) {
					return $this->page_post_id() > 0;
				}
				return true;
		}

		return false;
	}

	/**
	 * Why a part of the dashboard is unavailable under these filters, for the
	 * card to say so. '' when it is available.
	 */
	public function unavailable_reason( string $what ): string {
		if ( $this->supports( $what ) ) {
			return '';
		}
		if ( 'hours' === $what ) {
			return __( 'Hourly traffic is stored for the whole site, so the chart shows days while a filter is on.', 'vireo-analytics' );
		}
		if ( $this->has_page() && $this->page_post_id() <= 0 && [] === array_diff( $this->dims(), [ 'source', 'ref', 'page' ] ) ) {
			return __( 'Referrers are only recorded per page for posts and pages with an ID, so this page can be filtered on its own but not combined with a source or referrer.', 'vireo-analytics' );
		}
		$by    = [
			'device'  => __( 'device', 'vireo-analytics' ),
			'country' => __( 'country', 'vireo-analytics' ),
			'source'  => __( 'source', 'vireo-analytics' ),
			'ref'     => __( 'referrer', 'vireo-analytics' ),
			'page'    => __( 'page', 'vireo-analytics' ),
		];
		$names = array_map( static fn( string $d ): string => $by[ $d ], $this->dims() );
		/* translators: used between the filter names in a list, such as "device, country". */
		$list  = implode( __( ', ', 'vireo-analytics' ), $names );

		if ( count( $names ) > 1 ) {
			/* translators: %s: a list of filter names, such as "device, country". */
			return sprintf( __( 'Vireo keeps daily totals per dimension, not per visit, so filtering by %s together cannot be answered exactly.', 'vireo-analytics' ), $list );
		}
		/* translators: %s: a filter name, such as "device". */
		return sprintf( __( 'This breakdown is not stored by %s, so it is not shown while that filter is on.', 'vireo-analytics' ), $list );
	}

	/**
	 * The post the page filter resolves to, when the path was recorded with
	 * one, else 0. Looked up once per filter set.
	 *
	 * A path can be recorded with its post ID and without one (the front page
	 * as a bare "/"), so the page filter on its own matches by path, exactly as
	 * the Top pages row it was clicked from, and its referrer breakdown reads
	 * the post × referrer table by this ID.
	 */
	public function page_post_id(): int {
		if ( '' === $this->path ) {
			return 0;
		}
		if ( null !== $this->path_post ) {
			return $this->path_post;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one small lookup per render, kept on the object
		$this->path_post = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT MAX(s.post_id) FROM %i s JOIN %i p ON p.id = s.path_id WHERE p.path = %s',
				Sites::table( 'post_stats' ),
				Sites::table( 'paths' ),
				$this->path
			)
		);
		return $this->path_post;
	}

	/**
	 * Cache-key fragment so filtered queries don't collide with unfiltered ones.
	 */
	public function cache_fragment(): string {
		return 'f' . md5( $this->device . '|' . $this->country . '|' . $this->source . '|' . $this->ref . '|' . $this->path );
	}

	/**
	 * Build a ?foo=bar query-arg array reflecting the active filters. Skips
	 * empty values so URLs stay clean.
	 *
	 * @return array<string,string>
	 */
	public function to_query_args(): array {
		$out = [];
		if ( '' !== $this->device )  { $out['device']  = $this->device; }
		if ( '' !== $this->country ) { $out['country'] = $this->country; }
		if ( '' !== $this->source )  { $out['source']  = $this->source; }
		if ( '' !== $this->ref )     { $out['ref']     = $this->ref; }
		if ( '' !== $this->path )    { $out['path']    = $this->path; }
		return $out;
	}

	/**
	 * Classify a referrer host into one of the SOURCES buckets, the same way
	 * the referrer card and the SQL filter do.
	 */
	public static function classify_source( string $host ): string {
		return vireo_source_type( $host );
	}

	// ---------------------------------------------------------------------
	// SQL
	// ---------------------------------------------------------------------

	/**
	 * FROM + WHERE for the totals the hero and the chart read: pageviews and
	 * visitors per day, summed by the caller. Null when supports('totals') is
	 * false. For the direct source the caller subtracts referred_query().
	 *
	 * `from` holds %i placeholders for the tables in `tables`; `where` opens
	 * with AND and binds `args`. The stats table is always aliased `s`, so the
	 * caller writes `s.date BETWEEN %s AND %s` and sums `s.pageviews`.
	 *
	 * @return array{from:string,tables:string[],where:string,args:array<int,string|int>}|null
	 */
	public function totals_query(): ?array {
		if ( ! $this->supports( 'totals' ) ) {
			return null;
		}
		$dims = $this->dims();

		if ( [] === $dims ) {
			return self::plain( 'site_stats' );
		}
		if ( [ 'device' ] === $dims ) {
			return self::plain( 'device_stats', ' AND s.device_type = %s', [ $this->device ] );
		}
		if ( [ 'country' ] === $dims ) {
			return self::plain( 'geo_stats', ' AND s.country_code = %s', [ $this->country ] );
		}
		if ( $this->is_direct() ) {
			if ( $this->has_ref() ) {
				// Direct means no referrer; a referrer host contradicts it.
				return self::plain( 'site_stats', ' AND 0=1' );
			}
			// Everything, before the referred part is taken away.
			return $this->has_page() ? $this->page_all_query() : self::plain( 'site_stats' );
		}
		if ( [ 'page' ] === $dims ) {
			return $this->page_query();
		}
		return $this->referrer_query();
	}

	/**
	 * The referred part to subtract from totals_query() when the source is
	 * direct: every view a referrer claims, for the site or for the page.
	 *
	 * @return array{from:string,tables:string[],where:string,args:array<int,string|int>}
	 */
	public function referred_query( bool $by_post = false ): array {
		$f         = clone $this;
		$f->source = '';
		return $f->referrer_query( $by_post );
	}

	/**
	 * The referrer rows these filters select: referrer_stats, or the post ×
	 * referrer table when a page is set or the caller groups by post, joined
	 * to referrer_urls as `u`.
	 *
	 * @param bool $by_post Read the post × referrer table even with no page set,
	 *                      for a list of pages under a source or referrer.
	 * @return array{from:string,tables:string[],where:string,args:array<int,string|int>}
	 */
	public function referrer_query( bool $by_post = false ): array {
		$where = '';
		$args  = [];

		if ( $this->has_page() || $by_post ) {
			$tables = [ Sites::table( 'post_referrer_stats' ), Sites::table( 'referrer_urls' ) ];
		} else {
			$tables = [ Sites::table( 'referrer_stats' ), Sites::table( 'referrer_urls' ) ];
		}
		if ( $this->has_page() ) {
			$where .= ' AND s.post_id = %d';
			$args[] = $this->page_post_id();
		}

		[ $sw, $sa ] = $this->source_where( 'u.url' );
		[ $rw, $ra ] = $this->ref_where( 'u.url' );
		[ $xw, $xa ] = self::excluded_where( 'u.id' );

		return [
			'from'   => '%i s JOIN %i u ON u.id = s.url_id',
			'tables' => $tables,
			'where'  => $where . $sw . $rw . $xw,
			'args'   => array_merge( $args, $sa, $ra, $xa ),
		];
	}

	/**
	 * Stored referrer rows that are not referrers: the site's own hosts,
	 * localhost, private addresses and bare IPs, recorded before the
	 * aggregator refused them. Left in the table and excluded here, so every
	 * referrer read (the card, the channel totals, Direct by subtraction, the
	 * movers, the year in review) agrees without a destructive migration.
	 *
	 * @return array<int,int> referrer_urls ids.
	 */
	public static function excluded_referrer_ids(): array {
		global $wpdb;

		// Outside WordPress (the zero-dependency runners) there is nothing to read.
		if ( ! function_exists( 'get_transient' ) || ! function_exists( 'vireo_own_hosts' ) || ! method_exists( Sites::class, 'current' ) ) {
			return [];
		}

		// The blocked list is part of the key, so a block shows at once.
		$key   = 'vireo_ref_excluded_' . md5( Sites::current() . '|' . implode( ',', class_exists( Referrer_Spam::class ) ? Referrer_Spam::blocked() : [] ) );
		$found = get_transient( $key );
		if ( is_array( $found ) ) {
			return $found;
		}

		$own = vireo_own_hosts();
		$ids = [];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- cached below
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, url FROM %i', Sites::table( 'referrer_urls' ) ), ARRAY_A ) as $row ) {
			if ( ! self::referrer_is_reportable( (string) $row['url'], $own ) ) {
				$ids[] = (int) $row['id'];
			}
		}
		set_transient( $key, $ids, 5 * MINUTE_IN_SECONDS );
		return $ids;
	}

	/**
	 * Whether a stored referrer host belongs in a report. Pure given the own
	 * hosts; Android packages (com.google.android.gm) are kept.
	 *
	 * @param array<int,string> $own_hosts From vireo_own_hosts().
	 */
	public static function referrer_is_reportable( string $stored, array $own_hosts ): bool {
		if ( '' === $stored ) {
			return false;
		}
		if ( isset( vireo_app_referrers()[ strtolower( $stored ) ] ) ) {
			return true;
		}
		if ( class_exists( Referrer_Spam::class ) && Referrer_Spam::is_spam( $stored ) ) {
			return false;
		}
		return '' !== vireo_referrer_host( 'https://' . $stored . '/', $own_hosts );
	}

	/**
	 * SQL fragment keeping excluded_referrer_ids() out of a referrer read.
	 *
	 * @return array{0:string,1:array<int,int>}
	 */
	public static function excluded_where( string $id_col = 'u.id' ): array {
		$ids = self::excluded_referrer_ids();
		if ( [] === $ids ) {
			return [ '', [] ];
		}
		return [ " AND {$id_col} NOT IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', $ids ];
	}

	/**
	 * The page's own rows, matched by path like the Top pages row.
	 *
	 * @return array{from:string,tables:string[],where:string,args:array<int,string|int>}
	 */
	private function page_query(): array {
		return [
			'from'   => '%i s JOIN %i p ON p.id = s.path_id',
			'tables' => [ Sites::table( 'post_stats' ), Sites::table( 'paths' ) ],
			'where'  => ' AND p.path = %s',
			'args'   => [ $this->path ],
		];
	}

	/**
	 * The page's rows by post ID, the identity the post × referrer table uses,
	 * so direct = this minus referred_query() subtracts like with like.
	 *
	 * @return array{from:string,tables:string[],where:string,args:array<int,string|int>}
	 */
	private function page_all_query(): array {
		return self::plain( 'post_stats', ' AND s.post_id = %d', [ $this->page_post_id() ] );
	}

	/**
	 * @param array<int,string|int> $args
	 * @return array{from:string,tables:string[],where:string,args:array<int,string|int>}
	 */
	private static function plain( string $table, string $where = '', array $args = [] ): array {
		return [
			'from'   => '%i s',
			'tables' => [ Sites::table( $table ) ],
			'where'  => $where,
			'args'   => $args,
		];
	}

	/**
	 * Referrer-host SQL: the host and its www. variant, which the Top referrers
	 * card folds into one row, so the filter counts what the row counts.
	 *
	 * @return array{0:string,1:array<int,string>}
	 */
	public function ref_where( string $url_col = 'u.url' ): array {
		if ( '' === $this->ref ) {
			return [ '', [] ];
		}
		return [ " AND LOWER({$url_col}) IN (%s, %s)", [ $this->ref, 'www.' . $this->ref ] ];
	}

	/**
	 * Source-type SQL: returns a fragment + bind args that can be appended to
	 * a query against the va_referrer_stats x va_referrer_urls join to isolate
	 * a single source bucket. The caller is responsible for providing the
	 * `url` column alias (defaults to `u.url`).
	 *
	 * Returns [sql_fragment, bind_args[]]. Empty sql for no filter.
	 *
	 * @return array{0:string,1:array<int,string>}
	 */
	public function source_where( string $url_col = 'u.url' ): array {
		if ( '' === $this->source ) {
			return [ '', [] ];
		}
		if ( 'direct' === $this->source ) {
			// Direct = no referrer at all. The referrer tables only have
			// rows where the referrer exists, so a direct-only filter has
			// no rows. Caller must special-case (compute direct = site - referrers).
			return [ ' AND 0=1', [] ];
		}

		// Mirror vireo_source_type() in SQL, in its order: a host belongs to the
		// first pattern that claims it, so each bucket also rules out every
		// pattern ahead of it. AI comes first because gemini.google.com and x.ai
		// would otherwise read as search and social.
		$ordered = array_filter(
			[
				'ai'     => AI_Sources::pattern(),
				'search' => self::SEARCH_PATTERN,
				'social' => self::SOCIAL_PATTERN,
			]
		);
		if ( ! in_array( $this->source, [ 'ai', 'search', 'social', 'referral' ], true ) ) {
			return [ '', [] ];
		}

		// Android app referrers are package names the patterns misread (Gmail's
		// com.google.android.gm matches the Google pattern), so they're matched
		// by exact name and kept out of the patterns.
		$apps     = vireo_app_referrers();
		$all_apps = array_keys( $apps );
		$own_apps = array_keys( array_filter( $apps, fn( array $app ): bool => $this->source === $app['type'] ) );
		$list     = static fn( array $hosts ): string => implode( ',', array_fill( 0, count( $hosts ), '%s' ) );
		$is_app   = $own_apps ? "{$url_col} IN (" . $list( $own_apps ) . ')' : '0=1';

		$clauses  = [];
		$patterns = [];
		foreach ( $ordered as $bucket => $pattern ) {
			if ( $bucket === $this->source ) {
				$clauses[]  = "{$url_col} REGEXP %s";
				$patterns[] = $pattern;
				break;
			}
			$clauses[]  = "{$url_col} NOT REGEXP %s";
			$patterns[] = $pattern;
		}

		// A bucket whose pattern was filtered away (no AI sources) keeps only
		// its apps.
		if ( 'referral' !== $this->source && ! isset( $ordered[ $this->source ] ) ) {
			return [ " AND ( {$is_app} )", $own_apps ];
		}

		$clauses[] = "{$url_col} NOT IN (" . $list( $all_apps ) . ')';

		return [
			" AND ( {$is_app} OR ( " . implode( ' AND ', $clauses ) . ' ) )',
			array_merge( $own_apps, $patterns, $all_apps ),
		];
	}

	/**
	 * A CASE expression naming each referrer row's bucket, built from the same
	 * source_where() fragments the filter runs, so a total grouped by it always
	 * equals what that bucket's filter selects. Hosts no fragment claims are
	 * referral, as they are for the filter.
	 *
	 * @return array{0:string,1:array<int,string>}
	 */
	public static function bucket_case( string $url_col = 'u.url' ): array {
		$sql  = 'CASE';
		$args = [];
		foreach ( [ 'ai', 'search', 'social' ] as $bucket ) {
			$f         = new self();
			$f->source = $bucket;

			[ $where, $where_args ] = $f->source_where( $url_col );

			$sql .= ' WHEN ' . preg_replace( '/^\s*AND\s+/', '', $where ) . ' THEN %s';
			$args = array_merge( $args, $where_args, [ $bucket ] );
		}
		$args[] = 'referral';

		return [ $sql . ' ELSE %s END', $args ];
	}
}
