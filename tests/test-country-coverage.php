<?php
/**
 * Zero-dependency assertion runner for country coverage.
 * Run: php tests/test-country-coverage.php
 *
 * jeangalea.com's year in review ranked "Unknown" first with 21,168
 * visitors: imported history carries totals only, and a few months of live
 * data had no country either. An empty country code is a visit whose
 * country was never resolved, not a place, so it is never ranked, and the
 * cards say how much of the range has a country at all, derived from the data.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}
if ( ! function_exists( '__' ) ) {
	function __( $s, $d = null ) { return $s; }
}
require __DIR__ . '/../includes/class-country-coverage.php';

use VireoAnalytics\Country_Coverage;

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

// The live shape: Jan-Mar imported with no geo rows, Apr half resolved, May
// and Jun none, Jul half, Aug onward complete.
$site = [];
$geo  = [];
foreach ( [ '01', '02', '03', '04', '05', '06', '07', '08', '09', '10' ] as $m ) {
	foreach ( [ '01', '15' ] as $d ) {
		$date          = "2026-$m-$d";
		$site[ $date ] = 100;
		if ( in_array( $m, [ '08', '09', '10' ], true ) ) {
			$geo[ $date ] = 100;
		} elseif ( in_array( $m, [ '04', '07' ], true ) ) {
			$geo[ $date ] = 50;
		}
	}
}
$c = Country_Coverage::from_days( $site, $geo );
ok( 2000 === $c['total'] && 800 === $c['known'], "known/total summed from the days ({$c['known']}/{$c['total']})" );
ok( 40.0 === $c['share'], "share is 40% ({$c['share']})" );
ok( '2026-07-16' === $c['from'], "coverage starts the day after the last gap, a half-resolved July included ({$c['from']})" );

// Controls.
$full = Country_Coverage::from_days( $site, $site );
ok( 100.0 === $full['share'] && '' === $full['from'], 'control: full coverage has no start date' );
$none = Country_Coverage::from_days( $site, [] );
ok( 0.0 === $none['share'] && '' === $none['from'], 'control: no geo rows at all is zero, with no start to point at' );
$empty = Country_Coverage::from_days( [], [] );
ok( null === $empty['share'], 'control: no visitors at all gives no share' );
$nearly = Country_Coverage::from_days( [ '2026-01-01' => 100, '2026-01-02' => 100 ], [ '2026-01-01' => 95, '2026-01-02' => 100 ] );
ok( '' === $nearly['from'], 'control: a day with 95% resolved is not a gap (rounding across tables)' );
// A day with geo but no site row is clamped: coverage never exceeds 100%.
$over = Country_Coverage::from_days( [ '2026-01-01' => 10 ], [ '2026-01-01' => 12 ] );
ok( 100.0 === $over['share'], 'a geo count above the site count (rounding across tables) is clamped to 100%' );
// Fault control: a gap late in the range moves the start past it.
$late = $geo;
unset( $late['2026-09-15'] );
ok( '2026-09-16' === Country_Coverage::from_days( $site, $late )['from'], 'fault control: a later gap moves the start date past it' );

// The ranking rule: an empty code is never a country.
$rows = [ [ 'code' => '', 'visitors' => 500 ], [ 'code' => 'US', 'visitors' => 100 ], [ 'code' => 'xx', 'visitors' => 20 ], [ 'code' => 'CN', 'visitors' => 10 ] ];
$kept = Country_Coverage::rankable( $rows );
ok( [ 'US', 'CN' ] === array_column( $kept, 'code' ), 'empty and XX rows never rank, real countries keep their order: ' . implode( ',', array_column( $kept, 'code' ) ) );

echo $fail ? "\n$fail failure(s)\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
