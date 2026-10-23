<?php
/**
 * Zero-dependency assertion runner for referrers that are not referrers.
 * Run: php tests/test-self-referral.php
 *
 * jeangalea.com listed itself as its top referrer: 1,500 pageviews a month
 * since live tracking began. Every click from one of its pages to another
 * arrived with the site as the referrer, the tracker only dropped its own
 * host on headless front ends, and the aggregator stored whatever host it
 * was given. Internal navigation is not a source, and neither is localhost,
 * a private address or a bare IP.
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
	function home_url( $path = '' ) { return 'https://jeangalea.com' . $path; }
}
if ( ! function_exists( 'site_url' ) ) {
	function site_url( $path = '' ) { return 'https://jeangalea.com' . $path; }
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

require __DIR__ . '/../includes/va-filters.php';
require __DIR__ . '/../includes/va-referrers.php';

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

$own = vireo_own_hosts();
ok( [ 'jeangalea.com' ] === $own, 'own hosts come from home and site URL, canonical: ' . implode( ',', $own ) );

// 1. Every form the site's own referrer arrives in is dropped.
foreach ( [
	'https://jeangalea.com/',
	'https://jeangalea.com',
	'https://www.jeangalea.com/x',
	'http://jeangalea.com/some/post/',
	'http://www.jeangalea.com',
	'https://JeanGalea.com/Upper/',
	'https://jeangalea.com:443/',
	'https://blog.jeangalea.com/sub',
] as $raw ) {
	ok( '' === vireo_referrer_host( $raw, $own ), "own host dropped: $raw" );
}

// Control: the rule can fail. A different site is kept, and so is a host
// that merely contains the name.
ok( 'example.com' === vireo_referrer_host( 'https://www.example.com/path?q=1', $own ), 'control: another site is kept, canonical' );
ok( 'notjeangalea.com' === vireo_referrer_host( 'https://notjeangalea.com/', $own ), 'control: a host that only ends in the name is kept' );
ok( 'jeangalea.com.evil.net' === vireo_referrer_host( 'https://jeangalea.com.evil.net/', $own ), 'control: a host that only starts with the name is kept' );

// 2. Internal addresses are never referrers, whatever the scheme.
foreach ( [
	'http://localhost/',
	'http://localhost:8080/x',
	'http://127.0.0.1/',
	'http://127.0.0.1:9020/wp-admin/',
	'http://10.1.2.3/',
	'http://172.16.0.1/',
	'http://192.168.1.10/',
	'http://169.254.1.1/',
	'http://[::1]/',
	'http://[fc00::1]/',
	'http://8.8.8.8/',
	'http://dev.local/',
	'http://intranet.internal/',
	'http://site.test/',
	'http://foo.localhost/',
] as $raw ) {
	ok( '' === vireo_referrer_host( $raw, $own ), "internal address dropped: $raw" );
}
ok( vireo_is_internal_host( '127.0.0.1' ) && vireo_is_internal_host( '192.168.0.5' ) && vireo_is_internal_host( 'localhost' ), 'bare hosts read as internal' );
ok( ! vireo_is_internal_host( 'linkedin.com' ) && ! vireo_is_internal_host( 'eu.myconnectwise.net' ), 'control: real hosts are not internal' );

// 3. Real referrers survive, canonical.
ok( 'google.com' === vireo_referrer_host( 'https://www.google.com/', $own ), 'www. is folded' );
ok( 'l.facebook.com' === vireo_referrer_host( 'https://l.facebook.com/l.php?u=x', $own ), 'subdomains of other sites are kept' );
ok( 'com.google.android.gm' === vireo_referrer_host( 'android-app://com.google.android.gm/', $own ), 'android app referrers keep the package' );
ok( '' === vireo_referrer_host( '', $own ), 'no referrer stays no referrer' );
ok( '' === vireo_referrer_host( 'not a url', $own ), 'junk is dropped' );

// 4. A headless front end's origin is its own host too.
$own_headless = array_merge( $own, [ 'https://app.jeangalea.com' ] );
ok( '' === vireo_referrer_host( 'https://app.jeangalea.com/page', $own_headless ), 'a configured front end origin is internal' );

echo $fail ? "\n$fail failure(s)\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
