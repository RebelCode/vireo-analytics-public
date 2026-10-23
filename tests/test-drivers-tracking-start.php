<?php
/**
 * Zero-dependency assertions for the pre-tracking comparison guard.
 * Run: php tests/test-drivers-tracking-start.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}
if ( ! function_exists( '__' ) ) {
	function __( $s, $d = null ) { return $s; }
}
if ( ! function_exists( 'str_contains' ) ) {
	function str_contains( $h, $n ) { return '' === $n || false !== strpos( $h, $n ); }
}

if ( ! function_exists( 'wp_timezone' ) ) {
	function wp_timezone() { return new DateTimeZone( 'UTC' ); }
}
if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( $format, $ts ) { return gmdate( $format, $ts ); }
}

require __DIR__ . '/../includes/va-tz.php';
require __DIR__ . '/../includes/class-drivers.php';

use VireoAnalytics\Drivers;

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

// Range entirely after tracking began: normal comparison.
ok( '' === Drivers::untracked_headline( '2026-10-21', '2026-10-21', 500 ), 'range starting on the first tracked day compares normally' );
ok( '' === Drivers::untracked_headline( '2026-10-21', '2026-11-30', 500 ), 'range after the first tracked day compares normally' );

// Range starts before tracking, imported history supplies a prior.
$line = Drivers::untracked_headline( '2026-10-21', '2026-09-22', 1200 );
ok( str_contains( $line, 'Vireo started recording on Oct 21, 2026.' ), 'range before tracking start gets the neutral line' );
ok( ! str_contains( $line, 'down' ) && ! str_contains( $line, 'drop' ), 'neutral line names no drop' );

// No prior data: the existing "No prior period" path owns it.
ok( '' === Drivers::untracked_headline( '2026-10-21', '2026-09-22', 0 ), 'no prior data leaves the headline to the existing path' );

// Option never set (upgraded install): nothing held back.
ok( '' === Drivers::untracked_headline( '', '2026-09-22', 1200 ), 'unknown tracking start changes nothing' );

echo $fail ? "\n$fail failure(s)\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
