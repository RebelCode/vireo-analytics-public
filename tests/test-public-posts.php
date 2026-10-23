<?php
/**
 * Zero-dependency check that a post ID only sticks to a pageview, and only
 * renders as a title with a link, when the post is one a visitor could see.
 * Run: php tests/test-public-posts.php
 *
 * The post ID in a beacon comes from the visitor's browser. Until 0.9.1 any
 * existing post was accepted, so a forged beacon could make a draft, a
 * private post, a scheduled post or a reusable block show up by title in Top
 * pages, the weekly email and the MainWP payload. Two layers: the aggregator
 * drops such IDs as they arrive, and the renderers fall back to the path for
 * rows that were stored before.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

// What each post ID is on the imaginary site. Only 1 and 2 are public;
// 2 is password-protected, which is still publicly viewable in WordPress.
$GLOBALS['va_posts'] = [
	1 => 'publish post',
	2 => 'publish post, password',
	3 => 'draft post',
	4 => 'private post',
	5 => 'future post',
	6 => 'publish wp_block',
];
$GLOBALS['va_public'] = [ 1 => true, 2 => true ];

if ( ! function_exists( 'has_action' ) ) {
	function has_action( ...$a ) { return false; }
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( ...$a ) {}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( ...$a ) {}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) { return $value; }
}
if ( ! function_exists( '__' ) ) {
	function __( $s, $d = null ) { return $s; }
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) { return 'https://example.com' . $path; }
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post( $id ) {
		return isset( $GLOBALS['va_posts'][ (int) $id ] ) ? (object) [ 'ID' => (int) $id ] : null;
	}
}
if ( ! function_exists( 'is_post_publicly_viewable' ) ) {
	function is_post_publicly_viewable( $post ) {
		$id = is_object( $post ) ? (int) $post->ID : (int) $post;
		return ! empty( $GLOBALS['va_public'][ $id ] );
	}
}
if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( $id ) {
		return isset( $GLOBALS['va_posts'][ (int) $id ] ) ? 'Title of ' . $GLOBALS['va_posts'][ (int) $id ] : '';
	}
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $id ) {
		return isset( $GLOBALS['va_posts'][ (int) $id ] ) ? 'https://example.com/?p=' . (int) $id : false;
	}
}
if ( ! function_exists( 'vireo_get_exclusions' ) ) {
	function vireo_get_exclusions() { return [ 'countries' => [] ]; }
}
if ( ! function_exists( 'vireo_bucket_date' ) ) {
	function vireo_bucket_date( int $ts ): string { return gmdate( 'Y-m-d', $ts ); }
}
if ( ! function_exists( 'vireo_bucket_hour' ) ) {
	function vireo_bucket_hour( int $ts ): int { return (int) gmdate( 'G', $ts ); }
}
if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $n ) { return number_format( (float) $n ); }
}

if ( ! function_exists( 'str_ends_with' ) ) {
	function str_ends_with( $h, $n ) { return '' === $n || substr( $h, -strlen( $n ) ) === $n; }
}
require __DIR__ . '/../includes/class-sites.php';
require __DIR__ . '/../includes/class-upserter.php';
require __DIR__ . '/../includes/class-aggregator.php';
require __DIR__ . '/../includes/class-headless.php';
require __DIR__ . '/../includes/class-dashboard.php';

use VireoAnalytics\Aggregator;
use VireoAnalytics\Dashboard;

$fail = 0;
function ok( $cond, $msg ) {
	global $fail;
	echo ( $cond ? 'PASS: ' : 'FAIL: ' ) . $msg . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}

// ---- aggregation: which claimed IDs survive --------------------------------

/** Fold one same-site pageview claiming $post_id and return the post_id stored under its path. */
function folded_post_id( int $post_id ): int {
	$ref = new ReflectionClass( Aggregator::class );
	$agg = $ref->newInstanceWithoutConstructor();
	foreach ( $ref->getProperties() as $prop ) {
		$type = $prop->getType();
		$prop->setAccessible( true ); // PHP 7.4 needs this to read a private property.
		if ( $prop->isStatic() || ! $type || $prop->isInitialized( $agg ) ) {
			continue;
		}
		$prop->setValue( $agg, 'array' === $type->getName() ? [] : ( 'string' === $type->getName() ? '' : null ) );
	}
	$fold = $ref->getMethod( 'fold_pageview' );
	$fold->setAccessible( true );
	// [ts, path, post_id, new_visitor, unique_pageview, referrer]: no hash, no
	// UA, no IP, so nothing below the post rollup needs a database.
	$fold->invoke( $agg, [ 1791295993, '/some-page/', $post_id, 1, 1, '' ] );
	$rp = $ref->getProperty( 'post_rollup' );
	$rp->setAccessible( true );
	$rollup = $rp->getValue( $agg );
	return (int) ( $rollup['2026-10-06']['/some-page/']->post_id ?? -1 );
}

ok( 1 === folded_post_id( 1 ), 'a published post keeps its ID' );
ok( 2 === folded_post_id( 2 ), 'a password-protected published post keeps its ID (it is publicly viewable)' );
ok( 0 === folded_post_id( 3 ), 'a claimed draft is dropped; the view counts under the path' );
ok( 0 === folded_post_id( 4 ), 'a claimed private post is dropped' );
ok( 0 === folded_post_id( 5 ), 'a claimed scheduled post is dropped' );
ok( 0 === folded_post_id( 6 ), 'a claimed reusable block (non-viewable type) is dropped' );
ok( 0 === folded_post_id( 999 ), 'a claimed ID with no post is dropped' );
ok( 0 === folded_post_id( 0 ), 'no claim stays a plain path' );

// ---- rendering: rows stored before the check existed -----------------------

$p = Dashboard::public_post( 1 );
ok( 'Title of publish post' === $p['title'] && 'https://example.com/?p=1' === $p['url'], 'a public post renders with title and permalink' );
$p = Dashboard::public_post( 2 );
ok( 'Title of publish post, password' === $p['title'] && '' !== $p['url'], 'a password-protected post still renders (the visitor sees its title too)' );
foreach ( [ 3 => 'draft', 4 => 'private', 5 => 'scheduled', 6 => 'reusable block', 999 => 'deleted', 0 => 'no' ] as $id => $what ) {
	$p = Dashboard::public_post( $id );
	ok( [ 'title' => '', 'url' => '' ] === $p, "a stored row pointing at a $what post renders with no title and no link, so the path shows" );
}
ok( '/old-draft/' === Dashboard::page_label( Dashboard::public_post( 3 )['title'], '/old-draft/' ), 'page_label falls back to the path for a non-public post' );

echo $fail ? "\n$fail failure(s)\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
