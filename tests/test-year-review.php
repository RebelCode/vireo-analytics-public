<?php
/**
 * Zero-dependency assertion runner for the year in review aggregation.
 * Run: php tests/test-year-review.php
 *
 * The aggregation takes rows already keyed on the site's day (the way every
 * rollup stores them) and the import history, so it runs without WordPress.
 */

// PHP 8 string helpers, so the zero-dependency runner also works on 7.4,
// where WordPress itself provides these.
if ( ! function_exists( 'str_contains' ) ) {
	function str_contains( $h, $n ) { return '' === $n || false !== strpos( $h, $n ); }
}
if ( ! function_exists( 'str_starts_with' ) ) {
	function str_starts_with( $h, $n ) { return 0 === strncmp( $h, $n, strlen( $n ) ); }
}
if ( ! function_exists( 'str_ends_with' ) ) {
	function str_ends_with( $h, $n ) { return '' === $n || substr( $h, -strlen( $n ) ) === $n; }
}

if ( ! function_exists( '__' ) ) {
	function __( $s, $d = null ) { return $s; }
}
if ( ! function_exists( '_n' ) ) {
	function _n( $single, $plural, $n, $d = null ) { return 1 === (int) $n ? $single : $plural; }
}
if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, $d ); }
}
if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( $format, $ts ) { return gmdate( $format, $ts ); }
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

if ( ! function_exists( 'wp_timezone' ) ) {
	function wp_timezone() { return new DateTimeZone( 'UTC' ); }
}
require_once __DIR__ . '/../includes/va-tz.php';
require __DIR__ . '/../includes/class-import-history.php';
require __DIR__ . '/../includes/class-year-review.php';

use VireoAnalytics\Year_Review;

$fail = 0;
function ok( $cond, $msg ) {
	global $fail;
	if ( $cond ) {
		echo "PASS: $msg\n";
	} else {
		echo "FAIL: $msg\n";
		$fail++;
	}
}

function row( string $date, int $pv, int $vis = 0 ): array {
	return [ 'date' => $date, 'pageviews' => $pv, 'visitors' => $vis ?: (int) ceil( $pv / 2 ) ];
}

function month_of( array $review, int $m ): array {
	return $review['months'][ $m - 1 ];
}

// 1. Month boundaries follow the stored site day: the last of a month and the
// first of the next land in different months, and Dec 31 stays in the year.
$rows = [
	row( '2025-01-31', 10 ),
	row( '2025-02-01', 20 ),
	row( '2025-12-31', 5 ),
	row( '2026-01-01', 99 ),
	row( '2024-12-31', 7 ),
];
$r = Year_Review::summarize( 2025, '2026-03-10', $rows, [], '2024-12-31' );
ok( 10 === month_of( $r, 1 )['pageviews'], 'Jan 31 counts in January' );
ok( 20 === month_of( $r, 2 )['pageviews'], 'Feb 1 counts in February' );
ok( 5 === month_of( $r, 12 )['pageviews'], 'Dec 31 counts in December' );
ok( 35 === $r['totals']['pageviews'], 'rows from neighbouring years are left out of the total: ' . $r['totals']['pageviews'] );
ok( '2025-12-31' === $r['end'] && ! $r['partial'], 'a past year runs to Dec 31 and is not partial' );

// 2. Leap years: Feb 29 is a real day of February, and the comparison window
// for a range ending Feb 29 stops at Feb 28 the year before.
$r = Year_Review::summarize( 2024, '2025-06-01', [ row( '2024-02-29', 40 ), row( '2024-03-01', 1 ) ], [], '2024-01-01' );
ok( 40 === month_of( $r, 2 )['pageviews'], 'Feb 29 counts in February' );
ok( '2024-02-29' === month_of( $r, 2 )['end'], 'February 2024 ends on the 29th' );
ok( '2025-02-28' === month_of( Year_Review::summarize( 2025, '2026-01-01', [], [], null ), 2 )['end'], 'February 2025 ends on the 28th' );
ok( [ '2023-01-01', '2023-02-28' ] === Year_Review::comparison_window( 2024, '2024-02-29' ), 'Feb 29 compares against Feb 28' );

// 3. Partial current year: ends on the site's today, later months are future,
// and the comparison is the same stretch of last year, not all of it.
$rows = [];
foreach ( [ '2025-01-05' => 100, '2025-03-10' => 100, '2025-11-20' => 500, '2026-01-02' => 80, '2026-03-10' => 120, '2026-03-11' => 999 ] as $d => $pv ) {
	$rows[] = row( $d, $pv );
}
$r = Year_Review::summarize( 2026, '2026-03-10', $rows, [], '2025-01-01' );
ok( $r['partial'] && '2026-03-10' === $r['end'], 'current year ends today and is partial' );
ok( 200 === $r['totals']['pageviews'], 'a row after today (stray future bucket) is not counted: ' . $r['totals']['pageviews'] );
ok( month_of( $r, 3 )['partial'] && ! month_of( $r, 2 )['partial'], 'the current month is partial, earlier ones are not' );
ok( month_of( $r, 4 )['future'] && ! month_of( $r, 3 )['future'], 'months after today are future' );
ok( null !== $r['prior'] && 200 === $r['prior']['pageviews'], 'prior is Jan 1 to Mar 10 last year, not the whole year: ' . json_encode( $r['prior'] ) );
ok( '2025-03-10' === $r['prior']['end'], 'prior window ends on the same day last year' );

// 4. No prior when last year is not fully covered: a site that started in
// June last year has no fair comparison for January to March.
$r = Year_Review::summarize( 2026, '2026-03-10', [ row( '2025-06-01', 50 ), row( '2026-02-01', 60 ) ], [], '2025-06-01' );
ok( null === $r['prior'], 'no comparison when history starts after the prior window opens' );

// 5. Imported vs own months, from the import history.
$runs   = [
	[ 'source' => 'ga4', 'start' => '2025-01-01', 'end' => '2025-12-31', 'first' => '2025-01-01', 'last' => '2025-04-30', 'own_from' => '' ],
	[ 'source' => 'jetpack', 'start' => '', 'end' => '', 'first' => '2025-05-01', 'last' => '2025-06-14', 'own_from' => '2025-06-15' ],
];
$ranges = \VireoAnalytics\Import_History::covered_ranges( $runs );
$rows   = [ row( '2025-01-10', 100 ), row( '2025-04-30', 100 ), row( '2025-05-02', 10 ), row( '2025-06-14', 10 ), row( '2025-06-15', 10 ), row( '2025-07-01', 30 ) ];
$r      = Year_Review::summarize( 2025, '2026-02-01', $rows, $ranges, '2025-01-10' );
ok( 'imported' === month_of( $r, 1 )['origin'] && [ 'ga4' ] === month_of( $r, 1 )['sources'], 'January imported from GA4' );
ok( 'imported' === month_of( $r, 4 )['origin'], 'April 30, the last imported day, is still imported' );
ok( 'imported' === month_of( $r, 5 )['origin'] && [ 'jetpack' ] === month_of( $r, 5 )['sources'], 'May imported from Jetpack' );
ok( 'mixed' === month_of( $r, 6 )['origin'], 'June is part imported, part own' );
ok( 'own' === month_of( $r, 7 )['origin'] && [] === month_of( $r, 7 )['sources'], 'July counted by Vireo' );
ok( 10 === month_of( $r, 6 )['imported_pv'] && 20 === month_of( $r, 6 )['pageviews'], 'June splits 10 imported of 20' );
ok( '2025-06-15' === $r['own_first'], 'the first day Vireo counted itself this year: ' . var_export( $r['own_first'], true ) );
ok( null === Year_Review::summarize( 2025, '2026-02-01', [ row( '2025-01-10', 100 ) ], $ranges, '2025-01-10' )['own_first'], 'no own day in a fully imported year' );
ok( 'none' === month_of( $r, 3 )['origin'], 'a month with no rows has no origin' );
ok( 220 === $r['imported']['pageviews'] && 40 === $r['own']['pageviews'], 'imported/own pageviews split by day: ' . json_encode( [ $r['imported'], $r['own'] ] ) );
ok( [ 'ga4', 'jetpack' ] === $r['imported']['sources'], 'imported sources listed once each, in order of appearance' );

$lines = Year_Review::origin_lines( $r['months'], [ 'ga4' => 'Google Analytics 4', 'jetpack' => 'Jetpack Stats' ] );
ok( 5 === count( $lines ), 'one line per run of months: ' . json_encode( $lines ) );
ok( str_contains( $lines[0], 'January' ) && str_contains( $lines[0], 'Google Analytics 4' ) && ! str_contains( $lines[0], 'April' ), 'January alone (empty Feb and March break the run): ' . $lines[0] );
ok( str_contains( $lines[1], 'April' ) && str_contains( $lines[1], 'Google Analytics 4' ), 'April imported from GA4: ' . $lines[1] );
ok( str_contains( $lines[2], 'May' ) && str_contains( $lines[2], 'Jetpack Stats' ), 'May from Jetpack: ' . $lines[2] );
ok( str_contains( $lines[3], 'June' ) && str_contains( $lines[3], 'Jetpack Stats' ) && str_contains( $lines[3], 'Vireo' ), 'June is partly each: ' . $lines[3] );
ok( str_contains( $lines[4], 'July' ) && str_contains( $lines[4], 'Vireo' ), 'July counted by Vireo: ' . $lines[4] );

$runs_long = [ [ 'source' => 'ga4', 'start' => '', 'end' => '', 'first' => '2025-01-01', 'last' => '2025-03-31', 'own_from' => '' ] ];
$long      = Year_Review::summarize( 2025, '2026-01-01', [ row( '2025-01-02', 1 ), row( '2025-02-02', 1 ), row( '2025-03-02', 1 ), row( '2025-04-02', 1 ) ], \VireoAnalytics\Import_History::covered_ranges( $runs_long ), '2025-01-02' );
$lines     = Year_Review::origin_lines( $long['months'], [ 'ga4' => 'Google Analytics 4' ] );
ok( 2 === count( $lines ) && str_contains( $lines[0], 'January to March' ), 'consecutive months read as a span: ' . json_encode( $lines ) );

$all_own = Year_Review::summarize( 2025, '2026-02-01', [ row( '2025-03-01', 5 ) ], [], '2025-03-01' );
ok( [] === Year_Review::origin_lines( $all_own['months'], [] ), 'no origin note when every month is Vireo\'s own' );

// 6. A prior year that was imported while this one was counted is flagged,
// because the change compares two ways of counting.
$runs   = [ [ 'source' => 'ga4', 'start' => '2025-01-01', 'end' => '2025-12-31', 'first' => '2025-01-01', 'last' => '2025-12-31', 'own_from' => '' ] ];
$r      = Year_Review::summarize( 2026, '2026-02-01', [ row( '2025-01-01', 100 ), row( '2026-01-15', 150 ) ], \VireoAnalytics\Import_History::covered_ranges( $runs ), '2025-01-01' );
ok( null !== $r['prior'] && $r['prior']['imported'] && ! $r['prior']['same_origin'], 'prior from an import against own counting is flagged' );

// 7. Busiest month and day, ties to the earliest.
$r = Year_Review::summarize( 2025, '2026-01-10', [ row( '2025-02-03', 50 ), row( '2025-05-09', 70 ), row( '2025-05-10', 70 ), row( '2025-09-01', 100 ) ], [], '2025-01-01' );
ok( 2025 === $r['year'] && 5 === $r['busiest_month']['month'] && 140 === $r['busiest_month']['pageviews'], 'busiest month is May' );
ok( '2025-09-01' === $r['busiest_day']['date'], 'busiest day is Sep 1' );
$r = Year_Review::summarize( 2025, '2026-01-10', [ row( '2025-02-03', 70 ), row( '2025-05-09', 70 ) ], [], '2025-01-01' );
ok( '2025-02-03' === $r['busiest_day']['date'] && 2 === $r['busiest_month']['month'], 'ties go to the earlier day and month' );

// 8. Empty data: zeros, no busiest anything, no prior, and nothing invented.
$r = Year_Review::summarize( 2025, '2026-01-10', [], [], null );
ok( 0 === $r['totals']['pageviews'] && null === $r['busiest_day'] && null === $r['busiest_month'] && null === $r['prior'], 'empty year is empty' );
ok( ! $r['has_data'], 'empty year says it has no data' );
ok( '' === Year_Review::summary_text( $r, [ 'site' => 'example.com' ] ), 'no copy text for an empty year' );

// 9. Future year: no window at all.
ok( null === Year_Review::window( 2027, '2026-10-06' ), 'a year that has not started has no window' );
ok( [ 'start' => '2026-01-01', 'end' => '2026-10-06', 'partial' => true ] === Year_Review::window( 2026, '2026-10-06' ), 'current year window' );
ok( [ 'start' => '2026-01-01', 'end' => '2026-12-31', 'partial' => false ] === Year_Review::window( 2026, '2026-12-31' ), 'Dec 31 completes the year' );

// 10. Plain-text recap: built from the numbers, imported months credited.
$runs = [ [ 'source' => 'ga4', 'start' => '2025-01-01', 'end' => '2025-03-31', 'first' => '2025-01-01', 'last' => '2025-03-31', 'own_from' => '' ] ];
$rows = [ row( '2024-01-02', 500 ), row( '2025-02-14', 800 ), row( '2025-06-01', 400 ) ];
$r    = Year_Review::summarize( 2025, '2026-01-10', $rows, \VireoAnalytics\Import_History::covered_ranges( $runs ), '2024-01-01' );
$text = Year_Review::summary_text(
	$r,
	[
		'site'        => 'example.com',
		'top_post'    => [ 'label' => 'Hello world', 'pageviews' => 300 ],
		'top_source'  => [ 'label' => 'Google', 'pageviews' => 250 ],
		'top_country' => 'Germany',
		'sources'     => [ 'ga4' => 'Google Analytics 4' ],
	]
);
ok( str_contains( $text, '2025' ) && str_contains( $text, 'example.com' ), "recap names the year and site:\n$text" );
ok( str_contains( $text, '1,200 pageviews' ) && str_contains( $text, '+140%' ), 'recap has the total and change vs 2024' );
ok( str_contains( $text, 'February' ) && str_contains( $text, 'Feb 14' ), 'recap names the busiest month and day' );
ok( str_contains( $text, 'Hello world' ) && str_contains( $text, 'Google' ) && str_contains( $text, 'Germany' ), 'recap names top post, source and country' );
ok( str_contains( $text, 'Google Analytics 4' ), 'recap credits the imported history' );
ok( false === strpos( $text, '<' ), 'recap is plain text' );

// 11. Notice season: Dec 1 to Jan 31, for the year ending.
ok( 0 === Year_Review::notice_year( '2026-11-30' ), 'no notice in November' );
ok( 2026 === Year_Review::notice_year( '2026-12-01' ), 'notice for this year from Dec 1' );
ok( 2026 === Year_Review::notice_year( '2027-01-31' ), 'still last year in January' );
ok( 0 === Year_Review::notice_year( '2027-02-01' ), 'gone in February' );

echo $fail ? "\n$fail failure(s)\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
