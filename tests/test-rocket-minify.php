<?php
/**
 * Zero-dependency assertion runner for the WP Rocket minify exclusion.
 * Run: php tests/test-rocket-minify.php
 *
 * WP Rocket keyed its minified copy of tracker.js on the file's path, so the
 * copy made once kept being served after every Vireo update and the
 * WP RSS Aggregator and Spotlight listeners never reached the page. These
 * check the exclusion against WP Rocket's own matcher (3.23.5.1,
 * AbstractJSOptimization::get_excluded_files() and is_minify_excluded_file()).
 */

namespace VireoAnalytics;

define( 'ABSPATH', __DIR__ );
define( 'VIREO_URL', 'https://example.com/blog/wp-content/plugins/vireo-analytics/' );

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
}

require __DIR__ . '/../includes/class-script-loader.php';

$fail   = 0;
$passes = 0;
function ok( bool $cond, string $msg ): void {
	global $fail, $passes;
	if ( $cond ) {
		++$passes;
		echo "PASS: $msg\n";
	} else {
		++$fail;
		echo "FAIL: $msg\n";
	}
}

// WP Rocket's matcher, as it runs on a script tag's URL.
function rocket_excludes( array $excluded, string $url ): bool {
	foreach ( $excluded as $i => $file ) {
		$excluded[ $i ] = str_replace( '#', '\#', $file );
	}
	if ( ! $excluded ) {
		return false;
	}
	return 1 === preg_match( '#(' . implode( '|', $excluded ) . ')#', (string) parse_url( $url, PHP_URL_PATH ) );
}

$tracker = 'https://example.com/blog/wp-content/plugins/vireo-analytics/assets/js/tracker.js?ver=1791361121';
$vitals  = 'https://example.com/blog/wp-content/plugins/vireo-analytics/assets/js/vitals.js?ver=1791361121';

// Control: without the filter WP Rocket minifies the tracker, which is the bug.
ok( ! rocket_excludes( [], $tracker ), 'control: an empty exclusion list leaves the tracker to be minified' );
ok( ! rocket_excludes( [ '/wp-content/plugins/other/(.*).js' ], $tracker ), 'control: another plugin\'s exclusion does not cover the tracker' );

$list = Script_Loader::rocket_minify_exclusions( [] );
ok( 1 === count( $list ), 'one pattern is added' );
ok( rocket_excludes( $list, $tracker ), 'tracker.js is excluded from minification' );
ok( rocket_excludes( $list, $vitals ), 'vitals.js is excluded from minification' );
ok( ! rocket_excludes( $list, 'https://example.com/blog/wp-content/plugins/other/assets/js/tracker.js' ), 'another plugin\'s tracker.js is still minified' );
ok( ! rocket_excludes( $list, 'https://example.com/blog/wp-content/plugins/vireo-analytics/assets/css/admin.css' ), 'non-JS assets are not matched' );
ok( ! rocket_excludes( $list, 'https://example.com/wp-content/plugins/vireo-analytics/assets/js/tracker.js' ), 'the pattern is anchored to this install\'s plugin URL' );

$kept = Script_Loader::rocket_minify_exclusions( [ '/wp-includes/js/jquery/jquery.min.js' ] );
ok( 2 === count( $kept ) && '/wp-includes/js/jquery/jquery.min.js' === $kept[0], 'existing exclusions are kept' );
ok( rocket_excludes( $kept, 'https://example.com/wp-includes/js/jquery/jquery.min.js' ) && rocket_excludes( $kept, $tracker ), 'both the existing entry and ours match' );

ok( 1 === count( Script_Loader::rocket_minify_exclusions( null ) ), 'a null list from another filter becomes a list' );

if ( $passes < 11 ) {
	++$fail;
	echo "FAIL: expected 11 assertions, ran $passes\n";
}
echo $fail ? "\n$fail failure(s)\n" : "\nAll $passes passed\n";
exit( $fail ? 1 : 0 );
