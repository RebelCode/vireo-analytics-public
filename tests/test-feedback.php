<?php
/**
 * Zero-dependency assertion runner for the review-request eligibility rules.
 * Run: php tests/test-feedback.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

require __DIR__ . '/../includes/class-feedback.php';

use VireoAnalytics\Feedback;

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

$now   = 1790000000;
$fresh = Feedback::normalize_state( '' );

// State read from user meta.
ok( [ 'days' => 0, 'last' => '', 'snooze' => 0, 'done' => false ] === $fresh, 'missing meta reads as a fresh state' );
ok( 3 === Feedback::normalize_state( [ 'days' => '3', 'last' => '2026-10-01' ] )['days'], 'partial meta is filled in and cast' );
ok( 0 === Feedback::normalize_state( [ 'days' => -4 ] )['days'], 'a negative usage count reads as zero' );

// Usage days: one per calendar day, however many visits.
$state = Feedback::record_visit( $fresh, '2026-10-01' );
ok( 1 === $state['days'] && '2026-10-01' === $state['last'], 'first visit counts a day' );
$again = Feedback::record_visit( $state, '2026-10-01' );
ok( $again === $state, 'a second visit on the same day changes nothing, so nothing is written' );
$state = Feedback::record_visit( $state, '2026-10-03' );
ok( 2 === $state['days'], 'a visit on another day counts a second day' );

$state = $fresh;
foreach ( [ '2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04', '2026-10-05' ] as $d ) {
	$state = Feedback::record_visit( $state, $d );
}
ok( Feedback::MIN_USAGE_DAYS === $state['days'], 'five separate days reach the usage threshold' );
ok( Feedback::record_visit( $state, '2026-10-09' ) === $state, 'counting stops once the threshold is reached' );

$used = $state;

// Eligibility: both thresholds.
ok( Feedback::is_due( $used, 14, $now ), 'due with 14 days of data and 5 days of use' );
ok( ! Feedback::is_due( $used, 13, $now ), 'not due with 13 days of data' );
ok( ! Feedback::is_due( $used, 0, $now ), 'not due on a site with no data' );
ok( ! Feedback::is_due( Feedback::normalize_state( [ 'days' => 4 ] ), 400, $now ), 'not due after only 4 days of use, however much data' );
ok( Feedback::is_due( $used, 365, $now ), 'still due with a year of data' );

// Not now: 30 days.
$later = Feedback::apply_choice( $used, 'later', $now );
ok( ! $later['done'] && $now + 30 * DAY_IN_SECONDS === $later['snooze'], 'not now snoozes for 30 days' );
ok( ! Feedback::is_due( $later, 20, $now + DAY_IN_SECONDS ), 'snoozed the next day' );
ok( ! Feedback::is_due( $later, 20, $now + 30 * DAY_IN_SECONDS - 1 ), 'snoozed one second before the 30 days are up' );
ok( Feedback::is_due( $later, 50, $now + 30 * DAY_IN_SECONDS ), 'asks again once the 30 days are up' );

// Don't ask again and Leave a review: permanent.
$never = Feedback::apply_choice( $used, 'never', $now );
ok( $never['done'], "don't ask again is permanent" );
ok( ! Feedback::is_due( $never, 1000, $now + 1000 * DAY_IN_SECONDS ), "don't ask again holds years later" );
$reviewed = Feedback::apply_choice( $used, 'review', $now );
ok( $reviewed['done'] && ! Feedback::is_due( $reviewed, 1000, $now + 1000 * DAY_IN_SECONDS ), 'leaving a review is permanent' );
$after_snooze = Feedback::apply_choice( $later, 'never', $now + DAY_IN_SECONDS );
ok( $after_snooze['done'], "don't ask again after a snooze still sticks" );

// Unknown choices leave the state alone.
ok( Feedback::apply_choice( $used, 'bogus', $now ) === $used, 'unknown choice changes nothing' );

echo $fail ? "\n$fail failed\n" : "\nall passed\n";
exit( $fail ? 1 : 0 );
