<?php
/**
 * The Pages tab query against a real WordPress and database. Replaces the
 * stats tables with a plan it can recompute in PHP, then compares sorting,
 * paging, search and the date range with that plan:
 *
 *   wp eval-file tests/wp/pages-report.php
 *
 * Destructive: it empties post_stats, page_time and paths. Run it on a
 * throwaway install. The seeded rows stay behind for looking at the screen.
 *
 * Ends with planted faults: a wrong expected order and an extra row must be
 * reported as mismatches, or a green run would prove nothing.
 */

use VireoAnalytics\Pages_Report;
use VireoAnalytics\Sites;

if ( ! class_exists( Pages_Report::class ) ) {
	echo "Vireo Analytics is not loaded.\n";
	exit( 1 );
}

global $wpdb;

$GLOBALS['va_tally'] = [ 'pass' => 0, 'fail' => 0 ];
function ok( bool $cond, string $msg ): void {
	$GLOBALS['va_tally'][ $cond ? 'pass' : 'fail' ]++;
	echo ( $cond ? 'PASS' : 'FAIL' ) . ": $msg\n";
}

$stats = Sites::table( 'post_stats' );
$paths = Sites::table( 'paths' );
$time  = Sites::table( 'page_time' );
foreach ( [ $stats, $time, $paths ] as $table ) {
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) );
}

$today = vireo_bucket_date();
$day   = static fn( int $ago ): string => gmdate( 'Y-m-d', strtotime( $today . " -{$ago} days UTC" ) );
$start = $day( 10 );
$end   = $day( 1 );

$zebra = (int) wp_insert_post( [ 'post_title' => 'Zebra crossing guide', 'post_name' => 'zebra-crossing-guide', 'post_status' => 'publish', 'post_type' => 'post', 'post_content' => 'Seed.' ] );

// 130 pages, three days in range. Page 0 is the zebra post under an
// unrelated path; page 1 was only seen outside the range.
$plan = [];
for ( $i = 0; $i < 130; $i++ ) {
	$path = 0 === $i ? '/p-0/' : sprintf( '/page-%03d/', $i );
	$wpdb->insert( $paths, [ 'path' => $path ] );
	$id = (int) $wpdb->insert_id;

	$pv = 0;
	$vi = 0;
	foreach ( [ 9, 8, 7 ] as $ago ) {
		$views    = ( ( $i * 7 ) % 23 + 1 ) * 10 + $ago;
		$visitors = (int) ceil( $views * ( 0.4 + ( $i % 5 ) / 10 ) );
		if ( 1 === $i ) {
			continue;
		}
		$wpdb->insert( $stats, [ 'date' => $day( $ago ), 'post_id' => 0 === $i ? $zebra : 0, 'path_id' => $id, 'pageviews' => $views, 'visitors' => $visitors ] );
		$pv += $views;
		$vi += $visitors;
	}
	// Outside the range, on every page: must not be counted.
	$wpdb->insert( $stats, [ 'date' => $day( 20 ), 'post_id' => 0, 'path_id' => $id, 'pageviews' => 5000, 'visitors' => 4000 ] );

	// Time on page for two of every three pages, over two days.
	$avg = null;
	if ( 0 !== $i % 3 ) {
		$samples = 4 + $i % 7;
		$seconds = $samples * ( 20 + ( $i * 13 ) % 200 );
		$wpdb->insert( $time, [ 'date' => $day( 9 ), 'path_id' => $id, 'samples' => $samples, 'seconds' => $seconds ] );
		$wpdb->insert( $time, [ 'date' => $day( 8 ), 'path_id' => $id, 'samples' => $samples, 'seconds' => $seconds ] );
		$wpdb->insert( $time, [ 'date' => $day( 20 ), 'path_id' => $id, 'samples' => 100, 'seconds' => 90000 ] );
		$avg = (int) round( ( 2 * $seconds ) / ( 2 * $samples ) );
	}

	if ( 1 !== $i ) {
		$plan[ $path ] = [ 'path' => $path, 'pageviews' => $pv, 'visitors' => $vi, 'avg_time' => $avg ];
	}
}

// Expected order, computed here and not by the SQL under test.
$expect = static function ( array $rows, string $by, string $dir ): array {
	usort(
		$rows,
		static function ( array $a, array $b ) use ( $by, $dir ): int {
			if ( 'avg_time' === $by ) {
				if ( null === $a[ $by ] || null === $b[ $by ] ) {
					$cmp = (int) ( null === $a[ $by ] ) - (int) ( null === $b[ $by ] );
					return 0 !== $cmp ? $cmp : strcmp( $a['path'], $b['path'] );
				}
			}
			$cmp = $a[ $by ] <=> $b[ $by ];
			if ( 'desc' === $dir ) {
				$cmp = -$cmp;
			}
			return 0 !== $cmp ? $cmp : strcmp( $a['path'], $b['path'] );
		}
	);
	return array_column( $rows, 'path' );
};
$view = static fn( array $over = [] ): array => array_merge( Pages_Report::normalize( [] ), $over );
$got  = static fn( array $r ): array => array_column( $r['rows'], 'path' );

$all = Pages_Report::query( $start, $end, $view(), 1000 );
ok( 129 === $all['total'], 'total counts the pages seen in the range (129 of 130 seeded)' );
ok( ! in_array( '/page-001/', $got( $all ), true ), 'a page seen only outside the range is not listed' );

$row = null;
foreach ( $all['rows'] as $r ) {
	if ( '/page-002/' === $r['path'] ) {
		$row = $r;
	}
}
ok( null !== $row && $row['pageviews'] === $plan['/page-002/']['pageviews'] && $row['visitors'] === $plan['/page-002/']['visitors'], 'pageviews and visitors are sums over the range only, not the 5000 outside it' );
ok( null !== $row && $row['avg_time'] === $plan['/page-002/']['avg_time'], 'average time uses the range only' );

foreach ( [ 'pageviews', 'visitors', 'avg_time' ] as $by ) {
	foreach ( [ 'desc', 'asc' ] as $dir ) {
		$r = Pages_Report::query( $start, $end, $view( [ 'by' => $by, 'dir' => $dir ] ), 1000 );
		ok( $expect( $plan, $by, $dir ) === $got( $r ), "sorted by {$by} {$dir}" );
	}
}

// Paging: 50 a page, pages join up to the full order with no gap or overlap.
$order  = $expect( $plan, 'pageviews', 'desc' );
$joined = [];
foreach ( [ 1, 2, 3 ] as $n ) {
	$r = Pages_Report::query( $start, $end, $view( [ 'n' => $n ] ) );
	ok( 129 === $r['total'] && 3 === $r['pages'] && $n === $r['n'], "page {$n}: total 129 over 3 pages" );
	$joined = array_merge( $joined, $got( $r ) );
}
ok( $order === $joined && 129 === count( array_unique( $joined ) ), 'three pages of 50 join up to every page once, in order' );
ok( 29 === count( Pages_Report::query( $start, $end, $view( [ 'n' => 3 ] ) )['rows'] ), 'last page holds the remaining 29' );
$over = Pages_Report::query( $start, $end, $view( [ 'n' => 999 ] ) );
ok( 3 === $over['n'] && 29 === count( $over['rows'] ), 'a page past the end lands on the last page' );

// Search.
$r = Pages_Report::query( $start, $end, $view( [ 's' => 'page-01' ] ) );
$want = array_values( array_filter( array_keys( $plan ), static fn( string $p ): bool => false !== strpos( $p, 'page-01' ) ) );
ok( count( $want ) === $r['total'] && 10 === $r['total'], 'path search matches by substring (10 paths contain page-01)' );
$r = Pages_Report::query( $start, $end, $view( [ 's' => 'zebra crossing' ] ) );
ok( 1 === $r['total'] && '/p-0/' === $r['rows'][0]['path'], 'title search finds a page whose path does not contain the words' );
$titled = Pages_Report::with_titles( $r['rows'] );
ok( 'Zebra crossing guide' === $titled[0]['title'] && '' !== $titled[0]['url'], 'the row carries the post title and permalink' );
ok( 0 === Pages_Report::query( $start, $end, $view( [ 's' => '100%_nomatch' ] ) )['total'], 'LIKE wildcards in the search are literal' );
ok( 0 === Pages_Report::query( $start, $end, $view( [ 's' => "x' OR '1'='1" ] ) )['total'], 'a quote in the search matches nothing and breaks nothing' );
ok( 1 === count( Pages_Report::query( $start, $end, $view( [ 's' => 'page-129' ] ) )['rows'] ), 'search narrows the list' );

// Range.
$none = Pages_Report::query( $day( 40 ), $day( 30 ), $view() );
ok( 0 === $none['total'] && [] === $none['rows'] && 1 === $none['pages'], 'a range with no traffic is empty' );
$wide = Pages_Report::query( $day( 25 ), $end, $view(), 1000 );
ok( 130 === $wide['total'], 'a wider range picks up the older rows, so page 1 appears' );

// Request values are cleaned before they reach the query.
$bad = Pages_Report::normalize( [ 'by' => 'pageviews; DROP TABLE x', 'dir' => 'sideways', 'n' => '-5', 's' => '  ' . str_repeat( 'a', 300 ) ] );
ok( 'pageviews' === $bad['by'] && 'desc' === $bad['dir'] && 1 === $bad['n'] && 100 === strlen( $bad['s'] ), 'unknown sort, direction and page fall back; search is capped' );

// Planted faults: the checks above have to be able to fail.
$wrong = $expect( $plan, 'visitors', 'desc' );
ok( $wrong !== $got( Pages_Report::query( $start, $end, $view(), 1000 ) ), 'planted fault 1: visitors order against a pageviews result is a mismatch' );
$plan['/page-002/']['pageviews'] += 1;
$r = Pages_Report::query( $start, $end, $view(), 1000 );
$seen = null;
foreach ( $r['rows'] as $x ) {
	if ( '/page-002/' === $x['path'] ) {
		$seen = $x['pageviews'];
	}
}
ok( $seen !== $plan['/page-002/']['pageviews'], 'planted fault 2: a one-view difference in the plan is reported' );

$pass = $GLOBALS['va_tally']['pass'];
$fail = $GLOBALS['va_tally']['fail'];
echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
