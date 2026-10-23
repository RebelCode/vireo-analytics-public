<?php
/**
 * Zero-dependency assertion runner for date-only labels.
 * Run: php tests/test-day-labels.php
 *
 * Range ends are site days ("2026-07-09"), not instants. Turning one into UTC
 * midnight and formatting it in the site's timezone moved every label a day
 * back west of UTC. The helper formats the day itself; the scan keeps the old
 * pattern from coming back.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

$va_test_tz = 'America/New_York';
function wp_timezone() {
	global $va_test_tz;
	return new DateTimeZone( $va_test_tz );
}
function wp_date( $format, $ts = null, $tz = null ) {
	$d = new DateTime( '@' . ( null === $ts ? time() : (int) $ts ) );
	$d->setTimezone( $tz ? $tz : wp_timezone() );
	return $d->format( $format );
}

require __DIR__ . '/../includes/va-tz.php';

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

// Control: the old expression really is wrong in New York, so the checks below can fail.
ok( 'Jul 8' === vireo_display_date( (int) strtotime( '2026-07-09 00:00:00 UTC' ), 'M j' ), 'control: UTC midnight shown in New York is the day before' );

foreach ( [ 'America/New_York', 'America/Los_Angeles', 'Pacific/Pago_Pago', 'UTC', 'Europe/Madrid', 'Asia/Kolkata', 'Pacific/Kiritimati', 'Pacific/Tongatapu' ] as $zone ) {
	$va_test_tz = $zone;
	ok( 'Jul 9, 2026' === vireo_format_day( '2026-07-09', 'M j, Y' ), "$zone: Jul 9 stays Jul 9" );
	ok( 'Oct 6' === vireo_format_day( '2026-10-06', 'M j' ), "$zone: Oct 6 stays Oct 6" );
}

// Days where the clocks change, including zones that skip midnight itself.
$va_test_tz = 'America/New_York';
ok( 'Sun, Mar 8' === vireo_format_day( '2026-03-08', 'D, M j' ), 'New York spring-forward day' );
ok( 'Sun, Nov 1' === vireo_format_day( '2026-11-01', 'D, M j' ), 'New York fall-back day' );
$va_test_tz = 'America/Santiago';
ok( 'Sep 6' === vireo_format_day( '2026-09-06', 'M j' ), 'Santiago, where midnight does not exist on DST day' );
$va_test_tz = 'America/New_York';
ok( 'Thursday, Jul 9' === vireo_format_day( '2026-07-09', 'l, M j' ), 'weekday matches the day' );
ok( 'Dec 31, 2025' === vireo_format_day( '2025-12-31', 'M j, Y' ), 'year boundary' );

// Bad input comes back as-is rather than as 1970.
ok( '' === vireo_format_day( '', 'M j' ), 'empty stays empty' );
ok( 'nope' === vireo_format_day( 'nope', 'M j' ), 'garbage is returned unformatted' );

// Scan: no label may format a UTC-midnight timestamp of a date-only value.
$root  = dirname( __DIR__ );
$files = array_merge(
	glob( $root . '/includes/*.php' ),
	glob( $root . '/templates/*.php' ),
	glob( $root . '/templates/*/*.php' )
);
ok( count( $files ) > 40, 'scan covers the plugin (' . count( $files ) . ' files)' );
$hits = [];
foreach ( $files as $file ) {
	foreach ( file( $file ) as $n => $line ) {
		if ( preg_match( '/(vireo_display_date|wp_date|date_i18n)\s*\(.*strtotime\s*\(/', $line ) ) {
			$hits[] = str_replace( $root . '/', '', $file ) . ':' . ( $n + 1 );
		}
	}
}
// Two-step forms: `$x = strtotime( $ymd . ' 00:00:00 UTC' )` later handed to a formatter.
foreach ( $files as $file ) {
	$src = file_get_contents( $file );
	if ( preg_match_all( '/\$(\w+)\s*=\s*\(?(?:int\)\s*)?strtotime\(\s*[^;]*\.\s*\'\s*(?:00:00:00|12:00:00)\s*UTC\'\s*\)/', $src, $m ) ) {
		foreach ( $m[1] as $var ) {
			if ( preg_match( '/(vireo_display_date|wp_date|date_i18n)\s*\(\s*[^;]*\$' . $var . '\b/', $src ) ) {
				$hits[] = str_replace( $root . '/', '', $file ) . ' ($' . $var . ')';
			}
		}
	}
}
ok( [] === $hits, 'no label formats a UTC timestamp of a site day' . ( $hits ? ': ' . implode( ', ', array_unique( $hits ) ) : '' ) );

echo "\n" . ( $fail ? "$fail failed, $pass passed\n" : "all $pass passed\n" );
exit( $fail ? 1 : 0 );
