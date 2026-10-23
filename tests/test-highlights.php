<?php
/**
 * Zero-dependency assertion runner for the weekly highlights.
 * Run: php tests/test-highlights.php
 *
 * Selection is pure: it takes facts already read from the rollups and decides
 * which, if any, are worth saying. Nothing notable means nothing said.
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
require __DIR__ . '/../includes/class-highlights.php';

use VireoAnalytics\Highlights;

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

function week( array $pvs, string $from = '2026-09-28' ): array {
	$out = [];
	foreach ( $pvs as $i => $pv ) {
		$out[] = [ 'date' => gmdate( 'Y-m-d', strtotime( "$from +$i days" ) ), 'pageviews' => $pv ];
	}
	return $out;
}

function kinds( array $items ): array {
	return array_column( $items, 'kind' );
}

$flat = week( [ 20, 20, 20, 20, 20, 20, 20 ] );

// 1. Nothing notable: a flat week and no other facts says nothing.
ok( [] === Highlights::select( [ 'days' => $flat ] ), 'flat week, no facts: no highlights' );
ok( [] === Highlights::select( [] ), 'no data at all: no highlights' );
ok( [] === Highlights::select( [ 'days' => week( [ 0, 0, 0, 0, 0, 0, 0 ] ) ] ), 'empty week: no highlights' );

// 2. Best day only when it stands out from the rest of the week.
$h = Highlights::select( [ 'days' => week( [ 20, 20, 20, 60, 20, 20, 20 ] ) ] );
ok( [ 'best_day' ] === kinds( $h ), 'a standout day is a highlight' );
ok( str_contains( $h[0]['text'], 'Thursday' ) && str_contains( $h[0]['text'], '60' ), 'names the day (Oct 1 2026 is a Thursday) and its views: ' . $h[0]['text'] );
ok( [] === Highlights::select( [ 'days' => week( [ 2, 1, 0, 4, 1, 0, 1 ] ) ] ), 'a standout day on a tiny site is not notable' );

// 3. The biggest gaining post, only past a floor.
$h = Highlights::select( [ 'days' => $flat, 'gainer' => [ 'label' => 'Hello world', 'url' => '/hello/', 'delta' => 140 ] ] );
ok( [ 'gainer' ] === kinds( $h ) && str_contains( $h[0]['text'], 'Hello world' ) && str_contains( $h[0]['text'], '140' ), 'gainer named with its gain: ' . ( $h[0]['text'] ?? '' ) );
ok( [] === Highlights::select( [ 'days' => $flat, 'gainer' => [ 'label' => 'x', 'url' => '', 'delta' => 3 ] ] ), 'a gain of 3 views is noise' );

// 4. New referrer, only with enough views.
$h = Highlights::select( [ 'days' => $flat, 'new_referrer' => [ 'label' => 'news.ycombinator.com', 'pageviews' => 48 ] ] );
ok( [ 'new_referrer' ] === kinds( $h ) && str_contains( $h[0]['text'], 'news.ycombinator.com' ), 'new referrer named' );
ok( [] === Highlights::select( [ 'days' => $flat, 'new_referrer' => [ 'label' => 'x.com', 'pageviews' => 1 ] ] ), 'one view from a new site is not notable' );

// 5. Milestones: one at most, the strongest first.
$facts = [
	'days'           => $flat,
	'best_week'      => [ 'pageviews' => 900, 'previous_best' => 800, 'weeks' => 30 ],
	'site_milestone' => [ 'threshold' => 10000 ],
	'post_milestone' => [ 'label' => 'Guide', 'url' => '', 'threshold' => 1000 ],
];
$h = Highlights::select( $facts );
ok( [ 'best_week' ] === kinds( $h ), 'best week beats the other milestones, and only one milestone shows' );
ok( str_contains( $h[0]['text'], '900' ) && str_contains( $h[0]['text'], '800' ), 'best week gives both numbers: ' . $h[0]['text'] );

unset( $facts['best_week'] );
ok( [ 'site_milestone' ] === kinds( Highlights::select( $facts ) ), 'site total milestone next' );
unset( $facts['site_milestone'] );
$h = Highlights::select( $facts );
ok( [ 'post_milestone' ] === kinds( $h ) && str_contains( $h[0]['text'], 'Guide' ) && str_contains( $h[0]['text'], '1,000' ), 'post milestone: ' . ( $h[0]['text'] ?? '' ) );

// 6. Best week needs a history to beat, and has to actually beat it.
ok( [] === Highlights::select( [ 'days' => $flat, 'best_week' => [ 'pageviews' => 900, 'previous_best' => 800, 'weeks' => 2 ] ] ), 'two weeks of history is not a record' );
ok( [] === Highlights::select( [ 'days' => $flat, 'best_week' => [ 'pageviews' => 800, 'previous_best' => 800, 'weeks' => 30 ] ] ), 'equalling the best is not a new best' );

// 7. Order and cap: milestone, gainer, new referrer, best day; three at most.
$all = Highlights::select(
	[
		'days'           => week( [ 20, 20, 20, 90, 20, 20, 20 ] ),
		'gainer'         => [ 'label' => 'Hello', 'url' => '', 'delta' => 50 ],
		'new_referrer'   => [ 'label' => 'example.org', 'pageviews' => 12 ],
		'site_milestone' => [ 'threshold' => 100000 ],
	]
);
ok( [ 'site_milestone', 'gainer', 'new_referrer' ] === kinds( $all ), 'priority order, capped at three: ' . implode( ',', kinds( $all ) ) );
ok( 4 === count( Highlights::select( [ 'days' => week( [ 20, 20, 20, 90, 20, 20, 20 ] ), 'gainer' => [ 'label' => 'Hello', 'url' => '', 'delta' => 50 ], 'new_referrer' => [ 'label' => 'example.org', 'pageviews' => 12 ], 'site_milestone' => [ 'threshold' => 100000 ] ], 4 ) ), 'limit is honoured' );

// 8. Milestone thresholds: the largest one crossed this week, or none.
ok( 1000 === Highlights::crossed( 950, 1020 ), '950 -> 1,020 crosses 1,000' );
ok( null === Highlights::crossed( 1000, 1500 ), 'already at 1,000 before the week: nothing crossed' );
ok( 10000 === Highlights::crossed( 900, 12000 ), 'a jump past two thresholds reports the larger' );
ok( null === Highlights::crossed( 10, 900 ), 'below the first threshold: nothing' );
ok( 1000000 === Highlights::crossed( 999999, 1000000 ), 'landing exactly on a threshold counts' );

echo $fail ? "\n$fail failure(s)\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
