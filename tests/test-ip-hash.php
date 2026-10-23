<?php
/**
 * Zero-dependency assertion runner for the keyed IP hash.
 * Run: php tests/test-ip-hash.php
 *
 * The country-lookup cache and the aggregator's fallback visitor token both
 * hashed addresses as md5( 'vireo_' . $ip ): no secret, so an IPv4 address
 * could be recovered from a cache row by trying all four billion of them.
 * Salt_Manager::hash_ip() keys the hash with the site's auth salt instead.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
	define( 'HOUR_IN_SECONDS', 3600 );
}

$GLOBALS['va_test_auth_salt'] = 'site-secret-one';
function wp_salt( $scheme = 'auth' ) {
	return $GLOBALS['va_test_auth_salt'];
}
function add_action( ...$a ) {}

require __DIR__ . '/../includes/class-salt-manager.php';

use VireoAnalytics\Salt_Manager;

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

$ip4 = '203.0.113.42';
$ip6 = '2001:db8::1';

$h = Salt_Manager::hash_ip( $ip4 );
ok( 1 === preg_match( '/^[a-f0-9]{32}$/', $h ), 'the hash is 32 lowercase hex characters, the width of the ip_cache column' );
ok( $h === Salt_Manager::hash_ip( $ip4 ), 'the same address hashes the same way, so cache lookups hit' );
ok( $h !== Salt_Manager::hash_ip( '203.0.113.43' ), 'a neighbouring address hashes differently' );
ok( '' === Salt_Manager::hash_ip( '' ), 'no address, no token' );
ok( 1 === preg_match( '/^[a-f0-9]{32}$/', Salt_Manager::hash_ip( $ip6 ) ), 'IPv6 addresses hash too' );
ok( $h === substr( hash_hmac( 'sha256', $ip4, 'site-secret-one' ), 0, 32 ), 'it is HMAC-SHA256 of the bare address under the auth salt, truncated' );

// The secret is what makes it unrecoverable: another site, another value.
$GLOBALS['va_test_auth_salt'] = 'site-secret-two';
ok( $h !== Salt_Manager::hash_ip( $ip4 ), 'a different site secret gives a different hash for the same address' );
$GLOBALS['va_test_auth_salt'] = 'site-secret-one';

// Controls.
ok( md5( 'vireo_' . $ip4 ) !== $h, 'control: the old unkeyed md5 value no longer matches, so old cache rows miss and expire' );
ok( md5( 'vireo_' . $ip4 ) !== Salt_Manager::hash_ip( $ip4 ) && hash( 'sha256', $ip4 ) !== Salt_Manager::hash_ip( $ip4 ), 'control: neither an unkeyed md5 nor an unkeyed sha256 of the address reproduces it' );
ok( $h !== Salt_Manager::hash_with_salt( $ip4, '', 'site-secret-one' ), 'control: it is not the visitor hash either (that one carries the user agent and the rotating salt)' );

echo "\n";
if ( $fail ) {
	echo "$fail test(s) failed.\n";
	exit( 1 );
}
echo "All tests passed.\n";
