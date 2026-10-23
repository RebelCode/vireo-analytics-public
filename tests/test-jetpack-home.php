<?php
/**
 * Zero-dependency assertion runner for Jetpack's post 0.
 * Run: php tests/test-jetpack-home.php
 *
 * Jetpack Stats reports post ID 0 for "Home page / Archives": the front
 * page and every archive, as one bucket. jeangalea.com's import shows
 * 3,244 views under the literal path "/?p=0", and the current importer
 * drops the bucket altogether. It is stored under one fixed path, never
 * merged into "/" (which is the home page alone, and overlaps live rows),
 * and shown under an honest label everywhere.
 */

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
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

require __DIR__ . '/../includes/class-collector.php';
require __DIR__ . '/../includes/class-importer-base.php';
require __DIR__ . '/../importers/class-jetpack-importer.php';

use VireoAnalytics\Importers\Jetpack_Importer;

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

// 1. The path a post view is stored under.
ok( '/?p=0' === Jetpack_Importer::HOME_ARCHIVES_PATH, 'the home-and-archives bucket has one fixed path, the one older imports already wrote' );
ok( Jetpack_Importer::HOME_ARCHIVES_PATH === Jetpack_Importer::path_for( 0, null, null ), 'post 0 with nothing else is the bucket' );
ok( Jetpack_Importer::HOME_ARCHIVES_PATH === Jetpack_Importer::path_for( 0, 'https://example.com/', null ), 'post 0 is the bucket even when Jetpack sends the home permalink: archives are in it too' );
ok( '/hello-world/' === Jetpack_Importer::path_for( 12, 'https://example.com/hello-world/', null ), 'a post keeps its permalink path' );
ok( '/local-slug/' === Jetpack_Importer::path_for( 12, null, 'https://example.com/local-slug/' ), 'without a permalink the local one is used' );
ok( '/?p=12' === Jetpack_Importer::path_for( 12, null, null ), 'control: an unresolvable post keeps the ?p= fallback, as before' );
ok( '' === Jetpack_Importer::path_for( -1, null, null ), 'control: a negative id is dropped' );

// 2. Through postview_rows: a day with the home bucket and a post.
$day            = new stdClass();
$day->date      = '2026-03-01';
$home           = new stdClass();
$home->post_id  = 0;
$home->views    = 40;
$post           = new stdClass();
$post->post_id  = 12;
$post->views    = 10;
$post->post_permalink = 'https://example.com/hello-world/';
$day->postviews = [ $home, $post ];

$mapped = Jetpack_Importer::postview_rows( [ $day ], static fn( int $id, $pv ): string => Jetpack_Importer::path_for( $id, $pv->post_permalink ?? null, null ) );
$paths  = array_column( $mapped['posts'], 'pageviews', 'path' );
ok( isset( $paths['/?p=0'] ) && 40 === $paths['/?p=0'], 'the home-and-archives views are kept under the bucket path (' . json_encode( $paths ) . ')' );
ok( ! isset( $paths['/'] ), 'and never merged into "/"' );
ok( 50 === $mapped['site']['2026-03-01'], 'the day total includes them' );

// 3. The label the dashboard shows for the stored path.
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) { return $value; }
}
require __DIR__ . '/../includes/va-filters.php';
ok( 'Home page and archives (imported)' === vireo_page_label( '', '/?p=0' ), 'the bucket reads as what Jetpack meant' );
ok( 'Home' === vireo_page_label( '', '/' ), 'control: "/" still reads as Home' );
ok( '/?p=12' === vireo_page_label( '', '/?p=12' ), 'control: another unresolved post keeps its path' );
ok( 'My title' === vireo_page_label( 'My title', '/?p=0' ), 'control: a real title wins' );

echo $fail ? "\n$fail failure(s)\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
