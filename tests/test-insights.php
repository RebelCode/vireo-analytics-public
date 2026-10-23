<?php
/**
 * Zero-dependency assertion runner for the Insights composer.
 * Run: php tests/test-insights.php
 *
 * The composer only touches a few WordPress i18n helpers, shimmed here so the
 * pure sentence-assembly logic runs outside a WordPress bootstrap.
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
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ) { return $s; }
}
if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $n ) { return number_format( (float) $n ); }
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

require __DIR__ . '/../includes/class-insights.php';

use VireoAnalytics\Insights;

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

// 1. Real movement with a top gaining page → names the driver.
$hero    = [ 'pageviews' => 1180, 'prior' => [ 'pageviews' => 1000 ] ];
$drivers = [
	'headline' => 'Pageviews are up 18%.',
	'pages'    => [ 'gained' => [ [ 'label' => '/guide', 'url' => '', 'delta' => 150 ] ], 'lost' => [] ],
	'sources'  => [ 'gained' => [], 'lost' => [] ],
];
$line = Insights::line( $hero, $drivers, true );
ok( str_contains( $line, '18%' ) && str_contains( $line, '/guide' ), "movement line names the top driver: $line" );

// 2. A source out-gains a page → source is the named driver.
$hero    = [ 'pageviews' => 900, 'prior' => [ 'pageviews' => 700 ] ];
$drivers = [
	'headline' => 'Pageviews are up 28%.',
	'pages'    => [ 'gained' => [ [ 'label' => '/x', 'url' => '', 'delta' => 40 ] ], 'lost' => [] ],
	'sources'  => [ 'gained' => [ [ 'label' => 'reddit.com', 'url' => '', 'delta' => 160 ] ], 'lost' => [] ],
];
$line = Insights::line( $hero, $drivers, true );
ok( str_contains( $line, 'reddit.com' ), "bigger source out-ranks the page: $line" );

// 3. Flat movement, no strong driver → level + top page, no invented percentage.
$hero    = [ 'pageviews' => 420, 'prior' => [ 'pageviews' => 420 ] ];
$drivers = [
	'headline' => 'Pageviews are flat on the prior period.',
	'pages'    => [ 'gained' => [ [ 'label' => '/home', 'url' => '', 'delta' => 2 ] ], 'lost' => [] ],
	'sources'  => [ 'gained' => [], 'lost' => [] ],
];
$line = Insights::line( $hero, $drivers, true );
ok( '' !== $line && ! str_contains( $line, '%' ), "flat line avoids a percentage: $line" );

// 4. No comparison baseline (prior null) → honest collecting state, never empty.
$hero    = [ 'pageviews' => 37, 'prior' => null ];
$drivers = [ 'headline' => '', 'pages' => [ 'gained' => [], 'lost' => [] ], 'sources' => [ 'gained' => [], 'lost' => [] ] ];
$line    = Insights::line( $hero, $drivers, false );
ok( '' !== $line && str_contains( $line, '37' ), "no-baseline still returns a line with the count: $line" );

// 5. Brand new, zero data → still non-empty.
$hero    = [ 'pageviews' => 0, 'prior' => null ];
$drivers = [ 'headline' => '', 'pages' => [ 'gained' => [], 'lost' => [] ], 'sources' => [ 'gained' => [], 'lost' => [] ] ];
$line    = Insights::line( $hero, $drivers, false );
ok( '' !== $line, "zero-data still returns a line: $line" );

// 6. Compare requested but prior is zero → treated as no baseline, not a divide.
$hero    = [ 'pageviews' => 50, 'prior' => [ 'pageviews' => 0 ] ];
$drivers = [ 'headline' => '', 'pages' => [ 'gained' => [], 'lost' => [] ], 'sources' => [ 'gained' => [], 'lost' => [] ] ];
$line    = Insights::line( $hero, $drivers, true );
ok( '' !== $line, "zero prior returns a line without error: $line" );

// 10. Traffic down → never credit a gainer; name the biggest loser instead.
$hero    = [ 'pageviews' => 700, 'prior' => [ 'pageviews' => 1000 ] ];
$drivers = [
	'headline' => 'Pageviews are down 30%.',
	'pages'    => [
		'gained' => [ [ 'label' => '/rising-post', 'url' => '', 'delta' => 40 ] ],
		'lost'   => [ [ 'label' => '/old-post', 'url' => '', 'delta' => -120 ] ],
	],
	'sources'  => [
		'gained' => [ [ 'label' => 'reddit.com', 'url' => '', 'delta' => 60 ] ],
		'lost'   => [ [ 'label' => 'google.com', 'url' => '', 'delta' => -180 ] ],
	],
];
$line = Insights::line( $hero, $drivers, true );
ok( ! str_contains( $line, 'reddit.com' ) && ! str_contains( $line, '/rising-post' ), "a fall never names a gainer: $line" );
ok( str_contains( $line, 'down 30%' ) && str_contains( $line, 'google.com' ), "a fall names the biggest loser: $line" );

// 11. Traffic down with nothing that lost → headline alone, still no gainer.
$drivers['pages']['lost']   = [];
$drivers['sources']['lost'] = [];
$line = Insights::line( $hero, $drivers, true );
ok( 'Pageviews are down 30%.' === $line, "a fall with no named loser is the headline alone: $line" );

// 7. Lede with a drivers headline → headline plus its cause, composed line unused.
[ $head, $detail ] = Insights::lede( 'Composed line.', [ 'headline' => 'Pageviews are down 25%.', 'detail' => 'Most of the drop is /old-post.' ] );
ok( 'Pageviews are down 25%.' === $head && 'Most of the drop is /old-post.' === $detail, "lede prefers the drivers headline: $head $detail" );

// 8. Lede with no drivers headline → falls back to the composed line, no detail.
[ $head, $detail ] = Insights::lede( 'Collecting data, 37 views so far.', [ 'headline' => '', 'detail' => 'stray' ] );
ok( 'Collecting data, 37 views so far.' === $head && '' === $detail, "lede falls back to the composed line: $head" );

// 9. Nothing at all → empty headline, which callers treat as "say nothing".
[ $head, $detail ] = Insights::lede( '', [] );
ok( '' === $head && '' === $detail, 'lede with nothing to say is empty' );

echo $fail ? "\n$fail failure(s)\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
