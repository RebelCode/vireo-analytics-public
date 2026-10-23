<?php
/**
 * Every filtered number on the Overview must equal a direct query over the
 * rollup tables. Runs against a real WordPress with data in the tables:
 *
 *   wp eval-file tests/wp/filter-correctness.php
 *   VA_COMPARE=year wp eval-file tests/wp/filter-correctness.php
 *
 * The "truth" side never goes through Filters: hosts are bucketed with the
 * PHP classifier, the www. fold is done in PHP, direct is site minus every
 * referrer row, and the page's post ID is read straight from post_stats.
 * The two sides only agree when the SQL fragments say the same thing.
 *
 * Ends with two planted faults: a row changed under a passing check, and a
 * check pointed at the wrong device. Both must be reported as mismatches,
 * or a green run would prove nothing. Exits non-zero on any failure.
 */

use VireoAnalytics\Dashboard;
use VireoAnalytics\Filters;
use VireoAnalytics\Sites;

if ( ! class_exists( Dashboard::class ) ) {
	echo "Vireo Analytics is not loaded.\n";
	exit( 1 );
}

global $wpdb;

// wp eval-file runs this inside a function, so the tally lives in $GLOBALS.
$GLOBALS['va_tally'] = [ 'pass' => 0, 'fail' => 0 ];
function ok( bool $cond, string $msg ): void {
	if ( $cond ) {
		$GLOBALS['va_tally']['pass']++;
		echo "PASS: $msg\n";
	} else {
		$GLOBALS['va_tally']['fail']++;
		echo "FAIL: $msg\n";
	}
}

// A trusted request, so compare_mode() reads VA_COMPARE like a real view.
$mode = 'year' === getenv( 'VA_COMPARE' ) ? 'year' : 'prior';
wp_set_current_user( 1 );
$_GET['_vrn']    = Dashboard::view_nonce();
$_GET['compare'] = 'year' === $mode ? 'year' : '1';
ok( Dashboard::request_is_trusted(), 'the test request carries a valid view nonce' );
ok( Dashboard::compare_mode() === $mode, "compare mode is {$mode}" );

$t = static fn( string $name ): string => Sites::table( $name );

function clear_cache(): void {
	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_vireo_%' OR option_name LIKE '_transient_timeout_vireo_%'" );
	// Transients read through the options cache in this process too.
	wp_cache_flush();
}

// Thirty whole days ending yesterday: compare_weight() is 1 for a range that
// ends before today, so prior figures compare unweighted.
$today = vireo_bucket_date();
$end   = gmdate( 'Y-m-d', strtotime( $today . ' -1 day UTC' ) );
$start = gmdate( 'Y-m-d', strtotime( $today . ' -30 days UTC' ) );
[ $ps, $pe ] = Dashboard::compare_range( $start, $end );
echo "range {$start}..{$end}, compared with {$ps}..{$pe} ({$mode})\n";

// ---------------------------------------------------------------------
// Truth: plain SQL and PHP, no Filters.
// ---------------------------------------------------------------------

/** @return array{pageviews:int,visitors:int} */
function sum_rows( array $rows ): array {
	$pv = 0;
	$vi = 0;
	foreach ( $rows as $r ) {
		$pv += (int) $r['pageviews'];
		$vi += (int) $r['visitors'];
	}
	return [ 'pageviews' => $pv, 'visitors' => $vi ];
}

/**
 * Every referrer row in a range with its host, from referrer_stats or
 * post_referrer_stats. Rows stored for the site's own host or a spam host are
 * not referrers: the dashboard leaves them out at query time and they count
 * as Direct by subtraction, so the truth side drops them the same way, with
 * the same rule (Filters::referrer_is_reportable) rather than a copy of it.
 */
function referrer_rows( string $table, string $s, string $e, int $post_id = 0 ): array {
	global $wpdb;
	$extra = $post_id > 0 ? $wpdb->prepare( ' AND s.post_id = %d', $post_id ) : '';
	$rows  = (array) $wpdb->get_results(
		$wpdb->prepare(
			"SELECT s.date, u.url AS host, s.pageviews, s.visitors FROM %i s JOIN %i u ON u.id = s.url_id WHERE s.date BETWEEN %s AND %s{$extra}",
			Sites::table( $table ),
			Sites::table( 'referrer_urls' ),
			$s,
			$e
		),
		ARRAY_A
	);
	$own   = vireo_own_hosts();
	return array_values( array_filter( $rows, static fn( array $r ): bool => Filters::referrer_is_reportable( (string) $r['host'], $own ) ) );
}

function plain_total( string $table, string $s, string $e, string $where = '', array $args = [] ): array {
	global $wpdb;
	$row = $wpdb->get_row(
		$wpdb->prepare( "SELECT COALESCE(SUM(pageviews),0) AS pageviews, COALESCE(SUM(visitors),0) AS visitors FROM %i WHERE date BETWEEN %s AND %s{$where}", array_merge( [ Sites::table( $table ), $s, $e ], $args ) ),
		ARRAY_A
	);
	return [ 'pageviews' => (int) $row['pageviews'], 'visitors' => (int) $row['visitors'] ];
}

function post_for_path( string $path ): int {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(s.post_id) FROM %i s JOIN %i p ON p.id = s.path_id WHERE p.path = %s', Sites::table( 'post_stats' ), Sites::table( 'paths' ), $path ) );
}

/**
 * What the filters should produce, computed without Filters.
 *
 * @return array{pageviews:int,visitors:int}|null Null when the combination has no answer.
 */
function truth( array $f, string $s, string $e ): ?array {
	$dims = array_keys( array_filter( $f, static fn( $v ) => '' !== $v && null !== $v ) );
	sort( $dims );

	if ( [] === $dims ) {
		return plain_total( 'site_stats', $s, $e );
	}
	if ( [ 'device' ] === $dims ) {
		return plain_total( 'device_stats', $s, $e, ' AND device_type = %s', [ $f['device'] ] );
	}
	if ( [ 'country' ] === $dims ) {
		return plain_total( 'geo_stats', $s, $e, ' AND country_code = %s', [ $f['country'] ] );
	}
	if ( array_intersect( $dims, [ 'device', 'country' ] ) ) {
		return null;
	}

	$post_id = isset( $f['path'] ) && '' !== $f['path'] ? post_for_path( $f['path'] ) : 0;
	if ( [ 'path' ] === $dims ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(s.pageviews),0) AS pageviews, COALESCE(SUM(s.visitors),0) AS visitors FROM %i s JOIN %i p ON p.id = s.path_id WHERE s.date BETWEEN %s AND %s AND p.path = %s',
				Sites::table( 'post_stats' ),
				Sites::table( 'paths' ),
				$s,
				$e,
				$f['path']
			),
			ARRAY_A
		);
		return [ 'pageviews' => (int) $row['pageviews'], 'visitors' => (int) $row['visitors'] ];
	}
	if ( in_array( 'path', $dims, true ) && $post_id <= 0 ) {
		return null;
	}

	$rows = $post_id > 0 ? referrer_rows( 'post_referrer_stats', $s, $e, $post_id ) : referrer_rows( 'referrer_stats', $s, $e );
	$keep = static function ( array $r ) use ( $f ): bool {
		$host = (string) $r['host'];
		if ( ! empty( $f['ref'] ) && vireo_canonical_host( $host ) !== $f['ref'] ) {
			return false;
		}
		if ( ! empty( $f['source'] ) && 'direct' !== $f['source'] && vireo_source_type( $host ) !== $f['source'] ) {
			return false;
		}
		return true;
	};

	if ( ! empty( $f['source'] ) && 'direct' === $f['source'] ) {
		if ( ! empty( $f['ref'] ) ) {
			return [ 'pageviews' => 0, 'visitors' => 0 ];
		}
		$all      = $post_id > 0 ? plain_total( 'post_stats', $s, $e, ' AND post_id = %d', [ $post_id ] ) : plain_total( 'site_stats', $s, $e );
		$referred = sum_rows( $rows );
		return [ 'pageviews' => max( 0, $all['pageviews'] - $referred['pageviews'] ), 'visitors' => max( 0, $all['visitors'] - $referred['visitors'] ) ];
	}

	return sum_rows( array_filter( $rows, $keep ) );
}

// ---------------------------------------------------------------------
// The cases: every supported shape, and the refusals.
// ---------------------------------------------------------------------

$post_path = '/pricing/';
$bare_path = '/category/news/';
ok( post_for_path( $post_path ) > 0, "{$post_path} is recorded with a post ID" );
ok( post_for_path( $bare_path ) === 0, "{$bare_path} is recorded without a post ID" );

$cases = [
	'no filter'                 => [],
	'device'                    => [ 'device' => 'mobile' ],
	'country'                   => [ 'country' => 'ES' ],
	'source search'             => [ 'source' => 'search' ],
	'source ai'                 => [ 'source' => 'ai' ],
	'source social'             => [ 'source' => 'social' ],
	'source referral'           => [ 'source' => 'referral' ],
	'source direct'             => [ 'source' => 'direct' ],
	'referrer'                  => [ 'ref' => 'google.com' ],
	'referrer + its bucket'     => [ 'ref' => 'google.com', 'source' => 'search' ],
	'referrer + other bucket'   => [ 'ref' => 'google.com', 'source' => 'social' ],
	'referrer + direct'         => [ 'ref' => 'google.com', 'source' => 'direct' ],
	'page'                      => [ 'path' => $post_path ],
	'bare page'                 => [ 'path' => $bare_path ],
	'page + source'             => [ 'path' => $post_path, 'source' => 'search' ],
	'page + referrer'           => [ 'path' => $post_path, 'ref' => 'google.com' ],
	'page + direct'             => [ 'path' => $post_path, 'source' => 'direct' ],
	'page + referrer + bucket'  => [ 'path' => $post_path, 'ref' => 'google.com', 'source' => 'search' ],
];
$refused = [
	'device + country'          => [ 'device' => 'mobile', 'country' => 'ES' ],
	'device + source'           => [ 'device' => 'mobile', 'source' => 'search' ],
	'country + page'            => [ 'country' => 'ES', 'path' => $post_path ],
	'device + referrer'         => [ 'device' => 'mobile', 'ref' => 'google.com' ],
	'bare page + source'        => [ 'path' => $bare_path, 'source' => 'search' ],
	'bare page + referrer'      => [ 'path' => $bare_path, 'ref' => 'google.com' ],
];

clear_cache();
$dash     = Dashboard::instance();
$nonempty = 0;

foreach ( $cases as $label => $params ) {
	$filters = Filters::from_params( $params );
	ok( $filters->supports( 'totals' ), "{$label}: supported" );

	$want = truth( $params, $start, $end );
	$hero = $dash->get_hero_stats( $start, $end, true, $filters );
	ok( null !== $want && ! $hero['unavailable'], "{$label}: both sides answer" );
	if ( null === $want ) {
		continue;
	}
	if ( $want['pageviews'] > 0 ) {
		$nonempty++;
	}
	ok(
		$hero['pageviews'] === $want['pageviews'] && $hero['visitors'] === $want['visitors'],
		sprintf( '%s: hero %d/%d pageviews, %d/%d visitors', $label, $hero['pageviews'], $want['pageviews'], $hero['visitors'], $want['visitors'] )
	);

	// The chart is the same table day by day, so its bars add up to the hero.
	$series = $dash->get_chart_data( $start, $end, $filters );
	$sum    = array_sum( array_column( $series, 'pageviews' ) );
	ok( count( $series ) === 30 && $sum === $want['pageviews'], sprintf( '%s: chart has %d days summing to %d/%d', $label, count( $series ), $sum, $want['pageviews'] ) );
	$one_day = $series[10]['date'] ?? '';
	if ( '' !== $one_day ) {
		$day_want = truth( $params, $one_day, $one_day );
		ok( $series[10]['pageviews'] === $day_want['pageviews'], sprintf( '%s: day %s has %d/%d', $label, $one_day, $series[10]['pageviews'], $day_want['pageviews'] ) );
	}

	// The compared period, in whichever mode the request asked for.
	$prior_want = truth( $params, $ps, $pe );
	ok(
		is_array( $hero['prior'] ) && $hero['prior']['pageviews'] === $prior_want['pageviews'] && $hero['prior_range'] === [ $ps, $pe ],
		sprintf( '%s: prior (%s) %d/%d over %s..%s', $label, $mode, (int) ( $hero['prior']['pageviews'] ?? -1 ), $prior_want['pageviews'], $ps, $pe )
	);
}
ok( $nonempty >= 12, "{$nonempty} supported cases had traffic (a check over empty tables proves nothing)" );

foreach ( $refused as $label => $params ) {
	$filters = Filters::from_params( $params );
	$hero    = $dash->get_hero_stats( $start, $end, true, $filters );
	ok( ! $filters->supports( 'totals' ) && $hero['unavailable'] && 0 === $hero['pageviews'] && [] === $hero['sparkline'], "{$label}: refused, hero unavailable, no numbers" );
	ok( '' !== $filters->unavailable_reason( 'totals' ), "{$label}: has a reason" );
	ok( [] === $dash->get_top_pages( $start, $end, 10, true, $filters ) && [] === $dash->get_top_referrers( $start, $end, 10, true, $filters ) && [] === $dash->get_countries( $start, $end, 10, true, $filters ), "{$label}: cards come back empty" );
}

// ---------------------------------------------------------------------
// Cards: each row equals its own direct query.
// ---------------------------------------------------------------------

// Top pages, unfiltered: a row per path.
$pages = $dash->get_top_pages( $start, $end, 10, true );
ok( count( $pages ) >= 5, 'top pages lists ' . count( $pages ) . ' rows' );
$pages_ok = 0;
foreach ( $pages as $row ) {
	$want = truth( [ 'path' => $row['path'] ], $start, $end );
	if ( $want['pageviews'] === $row['pageviews'] ) {
		$pages_ok++;
	}
}
ok( $pages_ok === count( $pages ), "top pages: {$pages_ok}/" . count( $pages ) . ' rows equal their path total' );

// Top pages under a source filter: a row per post from the post × referrer table.
$f_search = Filters::from_params( [ 'source' => 'search' ] );
$pages    = $dash->get_top_pages( $start, $end, 10, true, $f_search );
$pages_ok = 0;
foreach ( $pages as $row ) {
	$rows = referrer_rows( 'post_referrer_stats', $start, $end, (int) $row['post_id'] );
	$want = sum_rows( array_filter( $rows, static fn( array $r ): bool => 'search' === vireo_source_type( (string) $r['host'] ) ) );
	if ( $want['pageviews'] === $row['pageviews'] && $row['post_id'] > 0 ) {
		$pages_ok++;
	}
}
ok( count( $pages ) >= 5 && $pages_ok === count( $pages ), "top pages under search: {$pages_ok}/" . count( $pages ) . ' post rows equal their search total' );

// Top pages under direct: post total minus its referred part.
$pages    = $dash->get_top_pages( $start, $end, 10, true, Filters::from_params( [ 'source' => 'direct' ] ) );
$pages_ok = 0;
foreach ( $pages as $row ) {
	$all  = plain_total( 'post_stats', $start, $end, ' AND post_id = %d', [ (int) $row['post_id'] ] );
	$ref  = sum_rows( referrer_rows( 'post_referrer_stats', $start, $end, (int) $row['post_id'] ) );
	$want = max( 0, $all['pageviews'] - $ref['pageviews'] );
	if ( $want === $row['pageviews'] ) {
		$pages_ok++;
	}
}
ok( count( $pages ) >= 5 && $pages_ok === count( $pages ), "top pages under direct: {$pages_ok}/" . count( $pages ) . ' rows equal post minus referred' );

// Top referrers: a row per canonical host, folded from its www. variant.
$refs    = $dash->get_top_referrers( $start, $end, 10, true, new Filters() );
$all     = referrer_rows( 'referrer_stats', $start, $end );
$refs_ok = 0;
foreach ( $refs as $row ) {
	$want = sum_rows( array_filter( $all, static fn( array $r ): bool => vireo_canonical_host( (string) $r['host'] ) === $row['host'] ) );
	if ( $want['pageviews'] === $row['pageviews'] ) {
		$refs_ok++;
	}
}
ok( count( $refs ) >= 5 && $refs_ok === count( $refs ), "top referrers: {$refs_ok}/" . count( $refs ) . ' hosts equal their folded total' );
$google = array_values( array_filter( $refs, static fn( array $r ): bool => 'google.com' === $r['host'] ) );
ok( 1 === count( $google ) && $google[0]['pageviews'] === truth( [ 'ref' => 'google.com' ], $start, $end )['pageviews'], 'google.com row equals the referrer filter total (www. folded)' );

// Top referrers of one page: the post × referrer table for that post.
$f_page  = Filters::from_params( [ 'path' => $post_path ] );
$refs    = $dash->get_top_referrers( $start, $end, 10, true, $f_page );
$all     = referrer_rows( 'post_referrer_stats', $start, $end, post_for_path( $post_path ) );
$refs_ok = 0;
foreach ( $refs as $row ) {
	$want = sum_rows( array_filter( $all, static fn( array $r ): bool => vireo_canonical_host( (string) $r['host'] ) === $row['host'] ) );
	if ( $want['pageviews'] === $row['pageviews'] ) {
		$refs_ok++;
	}
}
ok( count( $refs ) >= 5 && $refs_ok === count( $refs ), "top referrers of {$post_path}: {$refs_ok}/" . count( $refs ) . ' hosts equal the post\'s total' );

// Bucket totals: grouped in SQL, bucketed in PHP here; and they add up.
$buckets = $dash->get_referrer_bucket_totals( $start, $end, new Filters() );
$all     = referrer_rows( 'referrer_stats', $start, $end );
$by_type = [];
foreach ( $all as $r ) {
	$type             = vireo_source_type( (string) $r['host'] );
	$by_type[ $type ] = ( $by_type[ $type ] ?? 0 ) + (int) $r['pageviews'];
}
foreach ( [ 'search', 'ai', 'social', 'referral' ] as $type ) {
	ok( $buckets[ $type ]['pageviews'] === ( $by_type[ $type ] ?? 0 ), sprintf( 'bucket %s: %d/%d', $type, $buckets[ $type ]['pageviews'], $by_type[ $type ] ?? 0 ) );
}
$direct = $dash->get_direct_totals( $start, $end, new Filters() );
$site   = plain_total( 'site_stats', $start, $end );
ok( $direct['pageviews'] + array_sum( array_column( $buckets, 'pageviews' ) ) === $site['pageviews'], sprintf( 'direct %d + buckets %d = site %d', $direct['pageviews'], array_sum( array_column( $buckets, 'pageviews' ) ), $site['pageviews'] ) );

// Direct of a page, and direct under a referrer.
ok( $dash->get_direct_totals( $start, $end, $f_page )['pageviews'] === truth( [ 'path' => $post_path, 'source' => 'direct' ], $start, $end )['pageviews'], 'direct of a page equals post minus its referrers' );
ok( 0 === $dash->get_direct_totals( $start, $end, Filters::from_params( [ 'ref' => 'google.com' ] ) )['pageviews'], 'direct under a referrer filter is nothing' );

// Countries and devices.
$countries = $dash->get_countries( $start, $end, 10, true, new Filters() );
$geo_ok    = 0;
foreach ( $countries as $row ) {
	if ( plain_total( 'geo_stats', $start, $end, ' AND country_code = %s', [ $row['code'] ] )['visitors'] === $row['visitors'] ) {
		$geo_ok++;
	}
}
ok( count( $countries ) >= 5 && $geo_ok === count( $countries ), "countries: {$geo_ok}/" . count( $countries ) . ' rows equal their total' );
$one = $dash->get_countries( $start, $end, 10, true, Filters::from_params( [ 'country' => 'ES' ] ) );
ok( 1 === count( $one ) && 'ES' === $one[0]['code'] && $one[0]['visitors'] === plain_total( 'geo_stats', $start, $end, ' AND country_code = %s', [ 'ES' ] )['visitors'], 'country filter lists that one country with its total' );

$devices = $dash->get_devices( $start, $end, true, Filters::from_params( [ 'device' => 'mobile' ] ) );
$mobile  = plain_total( 'device_stats', $start, $end, ' AND device_type = %s', [ 'mobile' ] );
ok( 1 === count( $devices['devices'] ) && 'mobile' === $devices['devices'][0]['type'] && $devices['devices'][0]['visitors'] === $mobile['visitors'], 'device filter lists that one device type with its total' );
ok( array_sum( array_column( $devices['browsers'], 'visitors' ) ) === $mobile['visitors'], 'browsers under a device filter sum to that device' );

// ---------------------------------------------------------------------
// Planted faults: the checker has to be able to fail.
// ---------------------------------------------------------------------

$before = truth( [ 'device' => 'mobile' ], $start, $end );
$day    = gmdate( 'Y-m-d', strtotime( $start . ' +3 days UTC' ) );
// One row, by its whole key, so the same one is put back.
$key    = $wpdb->get_row( $wpdb->prepare( 'SELECT date, browser, os, device_type FROM %i WHERE date = %s AND device_type = %s ORDER BY browser LIMIT 1', $t( 'device_stats' ), $day, 'mobile' ), ARRAY_A );
$bump   = static function ( int $by ) use ( $wpdb, $t, $key ): int {
	return (int) $wpdb->query( $wpdb->prepare( 'UPDATE %i SET pageviews = pageviews + %d WHERE date = %s AND browser = %s AND os = %s AND device_type = %s', $t( 'device_stats' ), $by, $key['date'], $key['browser'], $key['os'], $key['device_type'] ) );
};
$bumped = is_array( $key ) ? $bump( 7 ) : 0;
clear_cache();
$hero_after = $dash->get_hero_stats( $start, $end, false, Filters::from_params( [ 'device' => 'mobile' ] ) );
ok( 1 === $bumped && $hero_after['pageviews'] === $before['pageviews'] + 7, sprintf( 'planted fault 1: a changed row is seen (%d vs stale truth %d, mismatch reported)', $hero_after['pageviews'], $before['pageviews'] ) );
$bump( -7 );
clear_cache();
ok( $dash->get_hero_stats( $start, $end, false, Filters::from_params( [ 'device' => 'mobile' ] ) )['pageviews'] === $before['pageviews'], 'planted fault 1: row restored' );

$wrong = truth( [ 'device' => 'desktop' ], $start, $end );
ok( $wrong['pageviews'] !== $before['pageviews'], sprintf( 'planted fault 2: mobile hero (%d) against desktop truth (%d) is a mismatch', $before['pageviews'], $wrong['pageviews'] ) );

$pass = $GLOBALS['va_tally']['pass'];
$fail = $GLOBALS['va_tally']['fail'];
echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
