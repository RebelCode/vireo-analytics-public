<?php
/**
 * Zero-dependency assertion runner for Dashboard::page_label().
 * Run: php tests/test-page-label.php
 *
 * Top pages, "What changed" and the weekly email all name pages through this,
 * so the front page reads "Home" in each rather than "/" in some.
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
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

require __DIR__ . '/../includes/va-filters.php';
require __DIR__ . '/../includes/class-dashboard.php';
require __DIR__ . '/../includes/class-insights.php';

use VireoAnalytics\Dashboard;
use VireoAnalytics\Insights;

if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $n ) { return number_format( (float) $n ); }
}

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

ok( 'Home' === Dashboard::page_label( '', '/' ), 'front page with no title is Home' );
ok( 'Home' === Dashboard::page_label( '/', '/' ), 'front page titled with its path is Home' );
ok( 'Welcome' === Dashboard::page_label( 'Welcome', '/' ), 'a static front page keeps its own title' );
ok( 'Hello world!' === Dashboard::page_label( 'Hello world!', '/hello-world/' ), 'a post keeps its title' );
ok( '/old-page/' === Dashboard::page_label( '', '/old-page/' ), 'an untitled page falls back to its path' );

// The lede names whichever label Drivers handed it, so a Home row reads Home.
$hero    = [ 'pageviews' => 1300, 'prior' => [ 'pageviews' => 1000 ] ];
$drivers = [
	'headline' => 'Pageviews are up 30%.',
	'pages'    => [ 'gained' => [ [ 'label' => Dashboard::page_label( '', '/' ), 'url' => '/', 'delta' => 250 ] ], 'lost' => [] ],
	'sources'  => [ 'gained' => [], 'lost' => [] ],
];
$line = Insights::line( $hero, $drivers, true );
ok( str_contains( $line, 'Home' ) && ! str_contains( $line, 'Mostly /.' ), "lede names the front page Home: $line" );

echo $fail ? "\n$fail failure(s)\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
