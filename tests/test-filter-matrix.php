<?php
/**
 * Zero-dependency assertion runner for the filter support matrix.
 * Run: php tests/test-filter-matrix.php
 *
 * Vireo keeps daily rollups per dimension, so a filter is only honest when a
 * table carries every active dimension. This pins down which combinations
 * Filters::supports() allows, which it refuses, and that the SQL it hands the
 * dashboard reads the table that matches. The DB-backed check that the
 * numbers then equal a direct query is tests/wp/filter-correctness.php.
 */

if ( ! function_exists( '__' ) ) {
	function __( $s, $d = null ) { return $s; }
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) { return $value; }
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}
// setAccessible() is a no-op from PHP 8.1 and deprecated from 8.5, but the
// plugin still runs on 7.4, where it is needed.
error_reporting( E_ALL & ~E_DEPRECATED );

require __DIR__ . '/../includes/va-filters.php';
require __DIR__ . '/../includes/class-filters.php';
require __DIR__ . '/../includes/class-ai-sources.php';

require __DIR__ . '/fixtures/sites-stub.php';

use VireoAnalytics\Filters;

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

/**
 * A page filter resolves its post ID from the database; set it directly so
 * the matrix can be exercised with and without one.
 */
function with_page( array $params, int $post_id ): Filters {
	$f    = Filters::from_params( $params );
	$prop = new ReflectionProperty( Filters::class, 'path_post' );
	$prop->setAccessible( true );
	$prop->setValue( $f, $post_id );
	return $f;
}

// ---- Parsing ---------------------------------------------------------

$f = Filters::from_params( [ 'device' => 'Mobile', 'country' => 'es', 'source' => 'search', 'ref' => 'WWW.Google.com', 'path' => '/pricing/' ] );
ok( 'mobile' === $f->device && 'ES' === $f->country && 'search' === $f->source && 'google.com' === $f->ref && '/pricing/' === $f->path, 'params are normalised: device lowercased, country uppercased, www. folded' );
ok( [ 'device', 'country', 'source', 'ref', 'page' ] === $f->dims(), 'dims() lists every active dimension in a fixed order' );

$f = Filters::from_params( [ 'device' => 'watch', 'country' => 'ESP', 'source' => 'email', 'ref' => 'bad host!', 'path' => 'pricing' ] );
ok( ! $f->is_active(), 'unknown device, 3-letter country, unknown source, bad host and a path without a leading slash are all dropped' );
ok( '' === Filters::clean_path( '/a?b=1' ) && '' === Filters::clean_path( '/a#x' ) && '' === Filters::clean_path( '//evil' ), 'a path with a query, fragment or double slash is dropped' );
ok( 'com.google.android.gm' === Filters::clean_host( 'com.google.android.gm' ), 'an Android app package passes as a host' );
ok( [ 'ref' => 'google.com', 'path' => '/pricing/' ] === Filters::from_params( [ 'ref' => 'google.com', 'path' => '/pricing/' ] )->to_query_args(), 'to_query_args() round-trips the new params' );

// ---- Support matrix -------------------------------------------------

$parts = [ 'totals', 'pages', 'referrers', 'countries', 'devices', 'hours' ];

$matrix = [
	// label => [ filters, post id, expected supported parts ]
	'none'                   => [ [], 0, $parts ],
	'device'                 => [ [ 'device' => 'mobile' ], 0, [ 'totals', 'devices' ] ],
	'country'                => [ [ 'country' => 'ES' ], 0, [ 'totals', 'countries' ] ],
	'source'                 => [ [ 'source' => 'search' ], 0, [ 'totals', 'pages', 'referrers' ] ],
	'direct'                 => [ [ 'source' => 'direct' ], 0, [ 'totals', 'pages', 'referrers' ] ],
	'referrer'               => [ [ 'ref' => 'google.com' ], 0, [ 'totals', 'pages', 'referrers' ] ],
	'referrer + source'      => [ [ 'ref' => 'google.com', 'source' => 'search' ], 0, [ 'totals', 'pages', 'referrers' ] ],
	'post page'              => [ [ 'path' => '/pricing/' ], 12, [ 'totals', 'pages', 'referrers' ] ],
	'bare page'              => [ [ 'path' => '/category/news/' ], 0, [ 'totals', 'pages' ] ],
	'post page + source'     => [ [ 'path' => '/pricing/', 'source' => 'search' ], 12, [ 'totals', 'pages', 'referrers' ] ],
	'post page + referrer'   => [ [ 'path' => '/pricing/', 'ref' => 'google.com' ], 12, [ 'totals', 'pages', 'referrers' ] ],
	'post page + direct'     => [ [ 'path' => '/pricing/', 'source' => 'direct' ], 12, [ 'totals', 'pages', 'referrers' ] ],
	'bare page + source'     => [ [ 'path' => '/category/news/', 'source' => 'search' ], 0, [] ],
	'bare page + referrer'   => [ [ 'path' => '/category/news/', 'ref' => 'google.com' ], 0, [] ],
	'device + country'       => [ [ 'device' => 'mobile', 'country' => 'ES' ], 0, [] ],
	'device + source'        => [ [ 'device' => 'mobile', 'source' => 'search' ], 0, [] ],
	'device + page'          => [ [ 'device' => 'mobile', 'path' => '/pricing/' ], 12, [] ],
	'country + referrer'     => [ [ 'country' => 'ES', 'ref' => 'google.com' ], 0, [] ],
	'country + source'       => [ [ 'country' => 'ES', 'source' => 'search' ], 0, [] ],
];

foreach ( $matrix as $label => [ $params, $post_id, $expected ] ) {
	$f   = with_page( $params, $post_id );
	$got = array_values( array_filter( $parts, static fn( string $p ): bool => $f->supports( $p ) ) );
	ok( $got === $expected, sprintf( '%s: supports [%s]', $label, implode( ', ', $got ) ) );
	foreach ( $parts as $p ) {
		$reason = $f->unavailable_reason( $p );
		if ( in_array( $p, $expected, true ) ) {
			if ( '' !== $reason ) {
				ok( false, "{$label}: {$p} is supported but has a reason" );
			}
		} elseif ( '' === $reason ) {
			ok( false, "{$label}: {$p} is unsupported but has no reason" );
		}
	}
	ok( ( null === $f->totals_query() ) === ! in_array( 'totals', $expected, true ), "{$label}: totals_query() is " . ( in_array( 'totals', $expected, true ) ? 'a query' : 'null' ) );
}

// ---- The table each query reads ---------------------------------------

$table = static function ( ?array $q ): string {
	return null === $q ? '' : implode( '+', array_map( static fn( string $t ): string => substr( $t, strlen( 'wp_va_' ) ), $q['tables'] ) );
};

ok( 'site_stats' === $table( ( new Filters() )->totals_query() ), 'no filter reads site_stats' );
ok( 'device_stats' === $table( Filters::from_params( [ 'device' => 'mobile' ] )->totals_query() ), 'device reads device_stats' );
ok( 'geo_stats' === $table( Filters::from_params( [ 'country' => 'ES' ] )->totals_query() ), 'country reads geo_stats' );
ok( 'referrer_stats+referrer_urls' === $table( Filters::from_params( [ 'source' => 'search' ] )->totals_query() ), 'source reads referrer_stats' );
ok( 'referrer_stats+referrer_urls' === $table( Filters::from_params( [ 'ref' => 'google.com' ] )->totals_query() ), 'referrer reads referrer_stats' );
ok( 'post_stats+paths' === $table( with_page( [ 'path' => '/pricing/' ], 12 )->totals_query() ), 'a page on its own reads post_stats by path' );
ok( 'post_referrer_stats+referrer_urls' === $table( with_page( [ 'path' => '/pricing/', 'source' => 'search' ], 12 )->totals_query() ), 'page × source reads post_referrer_stats' );
ok( 'post_referrer_stats+referrer_urls' === $table( with_page( [ 'path' => '/pricing/' ], 12 )->referrer_query() ), 'the referrers of a page read post_referrer_stats' );
ok( 'post_referrer_stats+referrer_urls' === $table( Filters::from_params( [ 'source' => 'search' ] )->referrer_query( true ) ), 'pages under a source read post_referrer_stats' );

// Direct: everything, minus what referrers claim.
$direct = Filters::from_params( [ 'source' => 'direct' ] );
ok( 'site_stats' === $table( $direct->totals_query() ) && '' === $direct->totals_query()['where'], 'direct starts from the whole site' );
ok( 'referrer_stats+referrer_urls' === $table( $direct->referred_query() ) && '' === $direct->referred_query()['where'], 'and takes away every referrer row' );
$page_direct = with_page( [ 'path' => '/pricing/', 'source' => 'direct' ], 12 );
ok( 'post_stats' === $table( $page_direct->totals_query() ) && [ 12 ] === $page_direct->totals_query()['args'], 'direct of a page starts from the post by ID' );
ok( ' AND s.post_id = %d' === $page_direct->referred_query()['where'] && [ 12 ] === $page_direct->referred_query()['args'], 'and takes away that post\'s referrer rows' );
$contra = Filters::from_params( [ 'source' => 'direct', 'ref' => 'google.com' ] );
ok( false !== strpos( $contra->totals_query()['where'], '0=1' ), 'direct with a referrer host selects nothing' );

// The host predicate folds www. like the Top referrers card.
[ $sql, $args ] = Filters::from_params( [ 'ref' => 'google.com' ] )->ref_where( 'u.url' );
ok( ' AND LOWER(u.url) IN (%s, %s)' === $sql && [ 'google.com', 'www.google.com' ] === $args, 'ref_where() matches the host and its www. variant' );

// Every %s / %d / %i in a query has a bound argument, so prepare() never
// runs short or long.
foreach ( $matrix as $label => [ $params, $post_id, $expected ] ) {
	$f = with_page( $params, $post_id );
	$q = $f->totals_query();
	if ( null === $q ) {
		continue;
	}
	$placeholders = preg_match_all( '/%[sdi]/', $q['from'] . $q['where'] );
	ok( $placeholders === count( $q['tables'] ) + count( $q['args'] ), "{$label}: {$placeholders} placeholders, " . ( count( $q['tables'] ) + count( $q['args'] ) ) . ' bindings' );
}

// Cache keys tell the new dimensions apart.
ok( Filters::from_params( [ 'ref' => 'google.com' ] )->cache_fragment() !== Filters::from_params( [ 'ref' => 'bing.com' ] )->cache_fragment(), 'cache fragment differs by referrer' );
ok( Filters::from_params( [ 'path' => '/a/' ] )->cache_fragment() !== Filters::from_params( [ 'path' => '/b/' ] )->cache_fragment(), 'cache fragment differs by page' );

echo $fail ? "\n{$fail} failed\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
