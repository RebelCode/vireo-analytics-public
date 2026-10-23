<?php
/**
 * Zero-dependency assertion runner for the digest's frequency: when a daily,
 * weekly or monthly digest is due, which period it covers and how one send
 * per period is keyed.
 * Run: php tests/test-email-frequency.php
 *
 * The clock stubs put the site at UTC+3, so a site hour and a UTC hour are
 * never the same number and a check that read UTC would show up.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

const VA_TEST_OFFSET = 3 * 3600;

function wp_date( $format, $ts = null ) {
	return gmdate( $format, ( $ts ?? time() ) + VA_TEST_OFFSET );
}
function vireo_format_day( $ymd, $format = 'M j, Y' ) {
	return gmdate( $format, strtotime( $ymd . ' 12:00:00 UTC' ) );
}
function __( $s, $d = null ) {
	return $s;
}
function _x( $s, $c, $d = null ) {
	return $s;
}
function add_action( $hook, $cb, $priority = 10, $args = 1 ) {
	return true;
}

require __DIR__ . '/../includes/class-email-reports.php';

use VireoAnalytics\Email_Reports;

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

/** A UTC instant whose site time is the given local date and hour. */
function at( string $local_date, int $hour ): int {
	return strtotime( $local_date . ' ' . sprintf( '%02d', $hour ) . ':00:00 UTC' ) - VA_TEST_OFFSET;
}

// ---------------------------------------------------------------------------
// Frequency setting
// ---------------------------------------------------------------------------

ok( 'weekly' === Email_Reports::frequency( [] ), 'no setting: weekly, as before the option existed' );
ok( 'daily' === Email_Reports::frequency( [ 'email_frequency' => 'daily' ] ), 'daily is accepted' );
ok( 'monthly' === Email_Reports::frequency( [ 'email_frequency' => 'monthly' ] ), 'monthly is accepted' );
ok( 'weekly' === Email_Reports::frequency( [ 'email_frequency' => 'hourly' ] ), 'an unknown value falls back to weekly' );

// ---------------------------------------------------------------------------
// Due: the hour is the site's hour
// ---------------------------------------------------------------------------

$monday = '2026-10-12'; // A Monday.
ok( Email_Reports::is_due( [ 'email_frequency' => 'daily', 'email_hour' => 9 ], at( $monday, 9 ) ), 'daily at 09:00 site time is due' );
ok( ! Email_Reports::is_due( [ 'email_frequency' => 'daily', 'email_hour' => 9 ], at( $monday, 6 ) ), 'daily at 06:00 site time (09:00 UTC) is not: the hour is read in site time' );
ok( Email_Reports::is_due( [ 'email_frequency' => 'daily', 'email_hour' => 9 ], at( '2026-10-15', 9 ) ), 'daily is due on any weekday' );

ok( Email_Reports::is_due( [ 'email_hour' => 9, 'email_day' => 1 ], at( $monday, 9 ) ), 'weekly on Monday at 09:00: due' );
ok( ! Email_Reports::is_due( [ 'email_hour' => 9, 'email_day' => 1 ], at( '2026-10-13', 9 ) ), 'weekly on Tuesday: not due' );
ok( ! Email_Reports::is_due( [ 'email_hour' => 9, 'email_day' => 1 ], at( $monday, 10 ) ), 'weekly on Monday at 10:00: not due' );

ok( Email_Reports::is_due( [ 'email_frequency' => 'monthly', 'email_hour' => 7 ], at( '2026-11-01', 7 ) ), 'monthly on the 1st at 07:00: due' );
ok( ! Email_Reports::is_due( [ 'email_frequency' => 'monthly', 'email_hour' => 7 ], at( '2026-11-02', 7 ) ), 'monthly on the 2nd: not due' );
ok( ! Email_Reports::is_due( [ 'email_frequency' => 'monthly', 'email_hour' => 7, 'email_day' => 7 ], at( '2026-11-01', 7 ) ) === false, 'monthly ignores the weekday setting' );

// ---------------------------------------------------------------------------
// One send per period
// ---------------------------------------------------------------------------

ok( '2026-10-12' === Email_Reports::period_key( 'daily', at( $monday, 9 ) ), 'daily key is the site day' );
ok( '2026-10-13' === Email_Reports::period_key( 'daily', strtotime( '2026-10-12 22:00:00 UTC' ) ), '22:00 UTC is already Tuesday on the site: the key moves with the site day' );
ok( '2026-42' === Email_Reports::period_key( 'weekly', at( $monday, 9 ) ), 'weekly key is the ISO week' );
ok( '2026-11' === Email_Reports::period_key( 'monthly', at( '2026-11-01', 7 ) ), 'monthly key is the month' );

// ---------------------------------------------------------------------------
// The period covered
// ---------------------------------------------------------------------------

$p = Email_Reports::period( 'daily', '2026-10-14' );
ok( '2026-10-13' === $p['start'] && '2026-10-13' === $p['end'], 'daily covers yesterday' );
ok( '2026-10-12' === $p['prev_start'] && '2026-10-12' === $p['prev_end'], 'compared with the day before' );

$p = Email_Reports::period( 'weekly', '2026-10-14' );
ok( '2026-10-07' === $p['start'] && '2026-10-13' === $p['end'], 'weekly covers the last seven full days' );
ok( '2026-09-30' === $p['prev_start'] && '2026-10-06' === $p['prev_end'], 'compared with the seven before' );

$p = Email_Reports::period( 'monthly', '2026-10-01' );
ok( '2026-09-01' === $p['start'] && '2026-09-30' === $p['end'], 'monthly on Oct 1 covers September' );
ok( '2026-08-01' === $p['prev_start'] && '2026-08-31' === $p['prev_end'], 'compared with August' );

$p = Email_Reports::period( 'monthly', '2026-03-01' );
ok( '2026-02-01' === $p['start'] && '2026-02-28' === $p['end'], 'February has 28 days' );
ok( '2026-01-31' === $p['prev_end'], 'and the month before it ends on the 31st' );

$p = Email_Reports::period( 'monthly', '2026-01-01' );
ok( '2025-12-01' === $p['start'] && '2025-12-31' === $p['end'] && '2025-11-01' === $p['prev_start'] && '2025-11-30' === $p['prev_end'], 'January looks back across the year boundary' );

$p = Email_Reports::period( 'daily', '2026-01-01' );
ok( '2025-12-31' === $p['end'] && '2025-12-30' === $p['prev_end'], 'daily on New Year covers Dec 31' );

// ---------------------------------------------------------------------------
// Labels and subjects
// ---------------------------------------------------------------------------

ok( 'Oct 13, 2026' === Email_Reports::period_label( 'daily', '2026-10-13', '2026-10-13' ), 'a daily label is the one day' );
ok( 'Oct 7 – Oct 13, 2026' === Email_Reports::period_label( 'weekly', '2026-10-07', '2026-10-13' ), 'a weekly label is the range' );
ok( 'September 2026' === Email_Reports::period_label( 'monthly', '2026-09-01', '2026-09-30' ), 'a monthly label is the month' );

ok( '[Example] Daily Analytics: Oct 13, 2026' === Email_Reports::subject( 'daily', 'Example', 'Oct 13, 2026' ), 'daily subject' );
ok( '[Example] Weekly Analytics: Oct 7 – Oct 13, 2026' === Email_Reports::subject( 'weekly', 'Example', 'Oct 7 – Oct 13, 2026' ), 'weekly subject is unchanged' );
ok( '[Example] Monthly Analytics: September 2026' === Email_Reports::subject( 'monthly', 'Example', 'September 2026' ), 'monthly subject' );

// Control: the offset is real, so a UTC read of the hour would have failed above.
ok( '12' === wp_date( 'G', at( $monday, 12 ) ) && '09' === gmdate( 'H', at( $monday, 12 ) ), 'control: site hour and UTC hour differ in this harness' );

echo "\n";
if ( $fail ) {
	echo "$fail test(s) failed.\n";
	exit( 1 );
}
echo "All tests passed.\n";
