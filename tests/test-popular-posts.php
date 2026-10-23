<?php
/**
 * Zero-dependency assertion runner for the popular posts query shaping.
 * Run: php tests/test-popular-posts.php
 *
 * Covers the pure parts: attribute normalisation, period ranges, cache keys
 * and result shaping. The SQL and rendering need WordPress and are checked
 * in wp-env.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

require __DIR__ . '/../includes/class-popular-posts.php';

use VireoAnalytics\Popular_Posts;

$fail = 0;
$pass = 0;
function ok( $cond, $msg ) {
	global $fail, $pass;
	if ( $cond ) {
		echo "PASS: $msg\n";
		$pass++;
	} else {
		echo "FAIL: $msg\n";
		$fail++;
	}
}

$types = [ 'post', 'page', 'product' ];

// Defaults.
$a = Popular_Posts::normalize_atts( [], $types );
ok( 5 === $a['number'], 'default number is 5' );
ok( '30' === $a['period'], 'default period is 30 days' );
ok( [ 'post' ] === $a['post_types'], 'default post type is post' );
ok( 'list' === $a['display'], 'default display is list' );
ok( false === $a['show_views'], 'view counts are off by default' );
ok( true === $a['exclude_current'], 'current post excluded by default' );

// Shortcode-style strings.
$a = Popular_Posts::normalize_atts(
	[
		'number'          => '8',
		'period'          => 'all',
		'post_types'      => 'page, post ,post',
		'display'         => 'thumbnails',
		'show_views'      => 'yes',
		'exclude_current' => 'false',
	],
	$types
);
ok( 8 === $a['number'], 'number parsed from string' );
ok( 'all' === $a['period'], 'all-time period accepted' );
ok( [ 'page', 'post' ] === $a['post_types'], 'post types split, trimmed, deduped and sorted' );
ok( 'thumbnails' === $a['display'], 'thumbnail display accepted' );
ok( true === $a['show_views'], '"yes" turns show_views on' );
ok( false === $a['exclude_current'], '"false" turns exclude_current off' );

// Clamping and rejection.
ok( 20 === Popular_Posts::normalize_atts( [ 'number' => 500 ], $types )['number'], 'number clamped to 20' );
ok( 1 === Popular_Posts::normalize_atts( [ 'number' => -3 ], $types )['number'], 'number clamped to 1' );
ok( '30' === Popular_Posts::normalize_atts( [ 'period' => '365' ], $types )['period'], 'unknown period falls back to 30' );
ok( 'list' === Popular_Posts::normalize_atts( [ 'display' => 'grid' ], $types )['display'], 'unknown display falls back to list' );
ok( [ 'post' ] === Popular_Posts::normalize_atts( [ 'post_types' => 'shop_order,nav_menu_item' ], $types )['post_types'], 'non-public post types rejected, falls back to post' );
ok( [ 'product' ] === Popular_Posts::normalize_atts( [ 'post_types' => [ 'product', 'secret' ] ], $types )['post_types'], 'array input filtered to allowed types' );

// Period ranges, inclusive of today, matching the dashboard's last_N presets.
ok( [ '2026-10-01', '2026-10-07' ] === Popular_Posts::period_range( '7', '2026-10-07' ), '7 days = today and the six before' );
ok( [ '2026-09-08', '2026-10-07' ] === Popular_Posts::period_range( '30', '2026-10-07' ), '30 days spans the month boundary' );
ok( [ '2026-07-10', '2026-10-07' ] === Popular_Posts::period_range( '90', '2026-10-07' ), '90 days' );
ok( null === Popular_Posts::period_range( 'all', '2026-10-07' ), 'all time has no range' );
ok( [ '2024-02-24', '2024-03-01' ] === Popular_Posts::period_range( '7', '2024-03-01' ), 'leap-year February handled' );

// Cache keys: only what changes the query.
$base = Popular_Posts::normalize_atts( [ 'post_types' => 'post,page' ], $types );
$k1   = Popular_Posts::cache_key( $base, '2026-10-07', 3 );
ok( 0 === strpos( $k1, 'vireo_pop_' ), 'cache key is prefixed' );
ok( strlen( $k1 ) <= 172, 'cache key fits the transient name limit' );
ok( $k1 === Popular_Posts::cache_key( Popular_Posts::normalize_atts( [ 'post_types' => 'page,post' ], $types ), '2026-10-07', 3 ), 'post type order does not change the key' );
ok( $k1 === Popular_Posts::cache_key( array_merge( $base, [ 'display' => 'thumbnails', 'show_views' => true, 'exclude_current' => false ] ), '2026-10-07', 3 ), 'display options share one cached query' );
ok( $k1 !== Popular_Posts::cache_key( array_merge( $base, [ 'number' => 6 ] ), '2026-10-07', 3 ), 'number changes the key' );
ok( $k1 !== Popular_Posts::cache_key( array_merge( $base, [ 'period' => '7' ] ), '2026-10-07', 3 ), 'period changes the key' );
ok( $k1 !== Popular_Posts::cache_key( $base, '2026-10-08', 3 ), 'a new day changes the key' );
ok( $k1 !== Popular_Posts::cache_key( $base, '2026-10-07', 4 ), 'a generation bump changes the key' );
$all = array_merge( $base, [ 'period' => 'all' ] );
ok( Popular_Posts::cache_key( $all, '2026-10-07', 3 ) === Popular_Posts::cache_key( $all, '2026-10-08', 3 ), 'all-time key does not roll over daily' );

// Shaping: order, exclusion, limit.
$rows = [
	[ 'post_id' => '12', 'views' => '40' ],
	[ 'post_id' => '7', 'views' => '90' ],
	[ 'post_id' => '30', 'views' => '40' ],
	[ 'post_id' => '5', 'views' => '10' ],
];
$out = Popular_Posts::shape( $rows, 0, 3 );
ok( [ 7, 12, 30 ] === array_column( $out, 'post_id' ), 'ordered by views desc, ties by lower post ID' );
ok( 90 === $out[0]['views'] && is_int( $out[0]['views'] ), 'views cast to int' );
$out = Popular_Posts::shape( $rows, 7, 3 );
ok( [ 12, 30, 5 ] === array_column( $out, 'post_id' ), 'current post excluded, next one fills the slot' );
ok( 3 === count( Popular_Posts::shape( $rows, 99, 3 ) ), 'excluding a post not in the list keeps the limit' );
ok( [] === Popular_Posts::shape( [], 0, 5 ), 'no rows, no posts' );
ok( [ 7 ] === array_column( Popular_Posts::shape( [ [ 'post_id' => 7, 'views' => 3 ], [ 'post_id' => 0, 'views' => 50 ] ], 0, 5 ), 'post_id' ), 'rows without a post ID are dropped' );

// The query asks for one spare row so excluding the current post never shortens the list.
ok( 6 === Popular_Posts::fetch_limit( 5 ), 'fetch limit is number + 1' );

// Fallback image: the first media-library image in the content, by attachment ID.
ok( 42 === Popular_Posts::first_content_image_id( '<!-- wp:image {"id":42} --><figure class="wp-block-image"><img src="x.jpg" class="wp-image-42"/></figure>' ), 'image block found by its wp-image class' );
ok( 7 === Popular_Posts::first_content_image_id( '<p>a</p><img class="alignleft size-medium wp-image-7" src="a.jpg"><img class="wp-image-9" src="b.jpg">' ), 'classic editor image, first one wins' );
ok( 0 === Popular_Posts::first_content_image_id( '<img src="https://elsewhere.example/a.jpg">' ), 'external images are not used' );
ok( 0 === Popular_Posts::first_content_image_id( '<p>No images here, just wp-image- talk.</p>' ), 'no image, no ID' );
ok( 0 === Popular_Posts::first_content_image_id( '' ), 'empty content' );

echo "\n" . ( $fail ? "$fail failed, $pass passed\n" : "all $pass passed\n" );
exit( $fail ? 1 : 0 );
