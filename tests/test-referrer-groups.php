<?php
/**
 * Zero-dependency assertion runner for the Top referrers group headers.
 * Run: php tests/test-referrer-groups.php
 *
 * The card lists ten hosts but each group header must count every host in the
 * range. Built here from fourteen hosts, of which only the top ten are shown:
 * Claude and Gemini fall below the cut, as on the site where the AI header
 * read 322 against 373 on the Search & AI tab.
 */

if ( ! function_exists( '__' ) ) {
	function __( $s, $d = null ) { return $s; }
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) { return $value; }
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

require __DIR__ . '/../includes/va-filters.php';
require __DIR__ . '/../includes/class-filters.php';
require __DIR__ . '/../includes/class-ai-sources.php';
require __DIR__ . '/../includes/class-dashboard.php';

use VireoAnalytics\Dashboard;

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

$all = [
	'google.com'        => 200,
	'chatgpt.com'       => 150,
	'perplexity.ai'     => 120,
	'bing.com'          => 90,
	'facebook.com'      => 80,
	'example.com'       => 70,
	'reddit.com'        => 60,
	'duckduckgo.com'    => 55,
	'linkedin.com'      => 52,
	'myblog.example'    => 52,
	'claude.ai'         => 30,
	'gemini.google.com' => 21,
	'x.com'             => 9,
	'wordpress.org'     => 4,
];

$rows = [];
foreach ( array_slice( $all, 0, 10, true ) as $host => $pv ) {
	$rows[] = [ 'host' => $host, 'pageviews' => $pv, 'visitors' => $pv ];
}

// What the whole range holds per bucket, from every host.
$totals = [];
foreach ( $all as $host => $pv ) {
	$b = vireo_source_type( $host );
	$totals[ $b ]['pageviews'] = ( $totals[ $b ]['pageviews'] ?? 0 ) + $pv;
	$totals[ $b ]['visitors']  = ( $totals[ $b ]['visitors'] ?? 0 ) + $pv;
}

$direct = [ 'pageviews' => 500, 'visitors' => 400 ];
$groups = Dashboard::group_referrers_by_source( $rows, $direct, $totals );

ok( 321 === (int) $groups['ai']['pageviews'], 'AI header counts Claude and Gemini below the cut: ' . $groups['ai']['pageviews'] . ' (want 321)' );
ok( 345 === (int) $groups['search']['pageviews'], 'Search header counts every search host: ' . $groups['search']['pageviews'] . ' (want 345)' );
ok( 201 === (int) $groups['social']['pageviews'], 'Social header counts x.com below the cut: ' . $groups['social']['pageviews'] . ' (want 201)' );
ok( 126 === (int) $groups['referral']['pageviews'], 'Referral header counts wordpress.org below the cut: ' . $groups['referral']['pageviews'] . ' (want 126)' );
ok( 500 === (int) $groups['direct']['pageviews'], 'Direct is passed through as before' );

$listed = array_sum( array_map( static fn( $g ): int => count( $g['rows'] ), $groups ) );
ok( 10 === $listed, "the rows stay the top ten ($listed listed)" );
ok( [ 'chatgpt.com', 'perplexity.ai' ] === array_column( $groups['ai']['rows'], 'host' ), 'the AI rows are only the ones shown' );

// Without totals (any older caller), the header falls back to the shown rows.
$fallback = Dashboard::group_referrers_by_source( $rows, $direct );
ok( 270 === (int) $fallback['ai']['pageviews'], 'without totals the header sums the shown rows' );

echo $fail ? "\n$fail failure(s)\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
