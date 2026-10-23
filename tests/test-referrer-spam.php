<?php
/**
 * Zero-dependency assertion runner for referrer spam and junk traffic.
 * Run: php tests/test-referrer-spam.php
 *
 * Two different things. Known spam (the Matomo list, plus hosts the admin
 * blocked) is filtered at fold time and hidden from reports. Suspected junk
 * is read from the rollups: a referrer whose visits show no engagement at
 * all, which on jeangalea.com was a compromised site loading pages in
 * frames (pageviews equal to visitors on every day, almost none on a post).
 * That is tagged for the admin, never removed on its own.
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
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) { return $value; }
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) { return 'https://example.com' . $path; }
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}
if ( ! defined( 'VIREO_DIR' ) ) {
	define( 'VIREO_DIR', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

// Options and transients in memory, so block/unblock run outside WordPress.
$GLOBALS['va_test_options'] = [];
function get_option( $key, $default = false ) {
	return $GLOBALS['va_test_options'][ $key ] ?? $default;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['va_test_options'][ $key ] = $value;
	return true;
}
function get_transient( $key ) { return false; }
function set_transient( $key, $value, $ttl = 0 ) { return true; }
function wp_cache_flush() {}
class WPDB_Stub {
	public $options = 'wp_options';
	public function query( $sql ) { return 0; }
}
$wpdb = new WPDB_Stub();

require __DIR__ . '/../includes/va-filters.php';
require __DIR__ . '/../includes/va-referrers.php';
require __DIR__ . '/../includes/class-referrer-spam.php';

use VireoAnalytics\Referrer_Spam;

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

// 1. The bundled list is loaded, is large, and matches subdomains.
$listed = Referrer_Spam::listed();
ok( count( $listed ) > 2000, 'bundled list holds thousands of hosts (' . count( $listed ) . ')' );
ok( Referrer_Spam::is_listed( 'semalt.com' ), 'semalt.com is on the list' );
ok( Referrer_Spam::is_listed( 'www.semalt.com' ), 'www. variant matches' );
ok( Referrer_Spam::is_listed( 'buttons-for-website.semalt.com' ), 'a subdomain of a listed host matches' );
ok( Referrer_Spam::is_listed( 'SEMALT.COM' ), 'matching ignores case' );
ok( ! Referrer_Spam::is_listed( 'notsemalt.com' ), 'control: a host that only ends in a listed name does not match' );
ok( ! Referrer_Spam::is_listed( 'semalt.com.example.net' ), 'control: a host that only starts with a listed name does not match' );
foreach ( [ 'google.com', 'linkedin.com', 'aocr.org', 'eu.myconnectwise.net', 'news.ycombinator.com' ] as $host ) {
	ok( ! Referrer_Spam::is_listed( $host ), "control: $host is not on the list" );
}
ok( Referrer_Spam::host_matches( 'a.b.c', [ 'b.c' ] ) && ! Referrer_Spam::host_matches( 'ab.c', [ 'b.c' ] ), 'suffix match needs a dot boundary' );

// 2. Manual block and unblock.
ok( [] === Referrer_Spam::blocked(), 'nothing blocked at first' );
ok( Referrer_Spam::block( 'https://www.Compromised-Site.org/path' ), 'block takes a URL and stores the canonical host' );
ok( [ 'compromised-site.org' ] === Referrer_Spam::blocked(), 'stored canonical: ' . implode( ',', Referrer_Spam::blocked() ) );
ok( Referrer_Spam::is_spam( 'compromised-site.org' ) && Referrer_Spam::is_spam( 'cdn.compromised-site.org' ), 'a blocked host and its subdomains are spam' );
ok( ! Referrer_Spam::block( 'compromised-site.org' ), 'blocking twice changes nothing' );
ok( ! Referrer_Spam::block( '' ) && ! Referrer_Spam::block( 'not a host!' ), 'junk is refused' );
ok( Referrer_Spam::unblock( 'compromised-site.org' ) && [] === Referrer_Spam::blocked(), 'unblock removes it' );
ok( ! Referrer_Spam::is_spam( 'compromised-site.org' ), 'control: once unblocked it is not spam' );
ok( ! Referrer_Spam::unblock( 'never-blocked.org' ), 'unblocking an unknown host changes nothing' );

// 3. Suspected junk: the jeangalea.com shape. 430 pageviews over 12 days,
//    pageviews == visitors on every day, 29 on a post, first seen 16 days ago.
$end  = '2026-10-14';
$junk = [ 'pageviews' => 430, 'visitors' => 430, 'days' => 12, 'post_pageviews' => 29, 'first_seen' => '2026-09-28' ];
ok( Referrer_Spam::looks_like_junk( $junk, $end ), 'the compromised-site shape is junk (pv==v, no post landings, new, a burst)' );

// A one-day burst like 2026-09-18: 97 pageviews, 96 visitors, none on a post, tied to a referrer.
$burst = [ 'pageviews' => 97, 'visitors' => 96, 'days' => 2, 'post_pageviews' => 0, 'first_seen' => '2026-09-18' ];
ok( Referrer_Spam::looks_like_junk( $burst, '2026-09-19' ), 'a two-day pv≈v burst with no post landings is junk' );

// Controls: real referrers with normal engagement.
$linkedin = [ 'pageviews' => 120, 'visitors' => 95, 'days' => 20, 'post_pageviews' => 110, 'first_seen' => '2025-01-01' ];
ok( ! Referrer_Spam::looks_like_junk( $linkedin, $end ), 'control: linkedin.com, readers who browse on, landing on posts, long known' );
$google = [ 'pageviews' => 4000, 'visitors' => 3200, 'days' => 30, 'post_pageviews' => 3900, 'first_seen' => '2024-01-01' ];
ok( ! Referrer_Spam::looks_like_junk( $google, $end ), 'control: google.com' );
$blog = [ 'pageviews' => 40, 'visitors' => 36, 'days' => 5, 'post_pageviews' => 38, 'first_seen' => '2026-10-02' ];
ok( ! Referrer_Spam::looks_like_junk( $blog, $end ), 'control: a small new blog linking to a post is not junk' );
$newsletter = [ 'pageviews' => 300, 'visitors' => 290, 'days' => 3, 'post_pageviews' => 295, 'first_seen' => '2026-10-10' ];
ok( ! Referrer_Spam::looks_like_junk( $newsletter, $end ), 'control: a newsletter burst lands on a post, so it is not junk' );
$tiny = [ 'pageviews' => 8, 'visitors' => 8, 'days' => 3, 'post_pageviews' => 0, 'first_seen' => '2026-10-10' ];
ok( ! Referrer_Spam::looks_like_junk( $tiny, $end ), 'control: too few views to call anything' );
$one_day = [ 'pageviews' => 200, 'visitors' => 200, 'days' => 1, 'post_pageviews' => 0, 'first_seen' => '2026-10-13' ];
ok( ! Referrer_Spam::looks_like_junk( $one_day, $end ), 'control: a single day is not a pattern yet' );
$old = [ 'pageviews' => 430, 'visitors' => 430, 'days' => 12, 'post_pageviews' => 0, 'first_seen' => '2025-02-01' ];
ok( ! Referrer_Spam::looks_like_junk( $old, $end ), 'control: a host known for a year is not a burst' );

// Fault control: the rule can fail. Add real browsing to the junk shape and it clears.
$browsing = $junk;
$browsing['visitors'] = 300;
ok( ! Referrer_Spam::looks_like_junk( $browsing, $end ), 'fault control: the same host with 130 second pageviews is not junk' );

// 4. strip(): rows for spam and the site's own host leave a report.
$rows = [
	[ 'host' => 'google.com', 'pageviews' => 10 ],
	[ 'host' => 'semalt.com', 'pageviews' => 9 ],
	[ 'host' => 'example.com', 'pageviews' => 8 ],
	[ 'host' => 'www.example.com', 'pageviews' => 7 ],
	[ 'host' => 'linkedin.com', 'pageviews' => 6 ],
];
ok( [ 'google.com', 'linkedin.com' ] === array_column( Referrer_Spam::strip( $rows ), 'host' ), 'strip keeps only real referrers: ' . implode( ',', array_column( Referrer_Spam::strip( $rows ), 'host' ) ) );

echo $fail ? "\n$fail failure(s)\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
