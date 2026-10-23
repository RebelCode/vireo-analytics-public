<?php
/**
 * Zero-dependency check of the Jetpack Stats mapping. The csv.php API returns
 * view counts only, so nothing it returns may land as visitors.
 * Run: php tests/test-jetpack-import.php
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

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
}

require __DIR__ . '/../includes/class-collector.php';
require __DIR__ . '/../includes/class-importer-base.php';
require __DIR__ . '/../importers/class-jetpack-importer.php';

use VireoAnalytics\Importers\Jetpack_Importer;

$fail = 0;
function ok( $cond, $msg ) {
	global $fail;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}

// Shapes as csv.php returns them with format=json.
$postviews = json_decode( '[{"date":"2026-03-01","postviews":[{"post_id":12,"post_title":"A","post_permalink":"https://example.com/a/","views":30},{"post_id":0,"post_title":"Home page","post_permalink":"https://example.com/","views":10}]},{"date":"2026-03-02","postviews":[{"post_id":12,"post_permalink":"https://example.com/a/","views":5}]}]' );
$mapped    = Jetpack_Importer::postview_rows( $postviews, static fn( int $id, $pv ): string => (string) wp_parse_url( (string) $pv->post_permalink, PHP_URL_PATH ) );

ok( 3 === count( $mapped['posts'] ), 'one post row per post per day (' . count( $mapped['posts'] ) . ')' );
ok( [ '2026-03-01' => 40, '2026-03-02' => 5 ] === $mapped['site'], 'daily pageviews are the sum of post views (' . json_encode( $mapped['site'] ) . ')' );
ok( [ 0, 0, 0 ] === array_column( $mapped['posts'], 'visitors' ), 'post rows carry no visitors: Jetpack returns views only' );
ok( [ 30, 10, 5 ] === array_column( $mapped['posts'], 'pageviews' ), 'post rows keep their views as pageviews' );

$referrers = json_decode( '[{"date":"2026-03-01","referrers":[{"url":"https://www.google.com/","views":7},{"group":"Search Engines","views":9},{"url":"https://news.ycombinator.com/item?id=1","total":3}]}]' );
$refs      = Jetpack_Importer::referrer_rows( $referrers );
ok( [ 'google.com' => 7, 'news.ycombinator.com' => 3 ] === array_column( $refs, 'pageviews', 'host' ), 'referrers by host, grouped labels dropped (' . json_encode( array_column( $refs, 'pageviews', 'host' ) ) . ')' );
ok( [ 0, 0 ] === array_column( $refs, 'visitors' ), 'referrer rows carry no visitors either' );

echo $fail ? "\n$fail failed\n" : "\nall passed\n";
exit( $fail ? 1 : 0 );
