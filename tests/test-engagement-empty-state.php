<?php
/**
 * Zero-dependency check of what the Time on page and Bounce rate cards say
 * when they have nothing to show. Each empty state names why and from which
 * day the number counts; none of them is the bare "no visitors counted yet"
 * that read as a broken feature on a freshly updated site.
 * Run: php tests/test-engagement-empty-state.php
 *
 * Fails on 0.13.0, where the helpers do not exist and the template printed
 * "no visitors counted yet" for a day that was not being counted yet.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}
function __( $s, $d = null ) { return $s; }
// The real one formats the day in the site's locale; the words are what is under test.
function vireo_format_day( $ymd, $format = 'M j, Y' ) { return gmdate( 'M j', (int) strtotime( $ymd . ' 12:00 UTC' ) ); }

require __DIR__ . '/../includes/class-engagement-basics.php';

use VireoAnalytics\Engagement_Basics;

$fail = 0;
function ok( $cond, $msg ) {
	global $fail;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}

if ( ! method_exists( Engagement_Basics::class, 'time_empty_state' ) || ! method_exists( Engagement_Basics::class, 'bounce_empty_state' ) ) {
	ok( false, 'the cards have empty-state copy helpers' );
	exit( 1 );
}

// ---- time on page ----------------------------------------------------------

$s = Engagement_Basics::time_empty_state( null, '2026-10-14' );
ok( 'no views timed yet' === $s['sub'] && false !== strpos( $s['hint'], 'within a few minutes' ), 'before any view reported: says when the first figure appears' );

$s = Engagement_Basics::time_empty_state( '2026-10-14', '2026-10-10' );
ok( 'timed from Oct 14' === $s['sub'] && false !== strpos( $s['hint'], 'ends before' ), 'a range that ends before timing began names the first day (got "' . $s['sub'] . '")' );

$s = Engagement_Basics::time_empty_state( '2026-10-14', '2026-10-20' );
ok( 'no views timed in this range' === $s['sub'] && false !== strpos( $s['hint'], 'since Oct 14' ), 'a range with timing but no reports says so and names the day' );

$s = Engagement_Basics::time_empty_state( '2026-10-14', '' );
ok( 'no views timed in this range' === $s['sub'], 'a hero cached without its range still gets a sentence' );

// ---- bounce rate -----------------------------------------------------------

$s = Engagement_Basics::bounce_empty_state( '', '2026-10-14', '2026-10-14' );
ok( 'not counted yet' === $s['sub'] && false !== strpos( $s['hint'], 'next scheduled run' ), 'before the installer set a day: says what has to happen' );

// jeangalea.com on the day of the update: counting starts tomorrow.
$s = Engagement_Basics::bounce_empty_state( '2026-10-15', '2026-10-14', '2026-10-14' );
ok( 'starts counting Oct 15' === $s['sub'] && false !== strpos( $s['hint'], 'first full day, Oct 15' ), 'the update day names tomorrow as the first counted day (got "' . $s['sub'] . '")' );

// The first counted day, before its first flush.
$s = Engagement_Basics::bounce_empty_state( '2026-10-15', '2026-10-15', '2026-10-15' );
ok( 'counted from Oct 15, no visitors yet' === $s['sub'] && false !== strpos( $s['hint'], 'within a few minutes' ), 'the first counted day says it fills in shortly (got "' . $s['sub'] . '")' );

// Last week, looked at after counting began.
$s = Engagement_Basics::bounce_empty_state( '2026-10-15', '2026-10-20', '2026-10-13' );
ok( 'counted from Oct 15' === $s['sub'] && false !== strpos( $s['hint'], 'ends before' ), 'a range before the first counted day says so (got "' . $s['sub'] . '")' );

$s = Engagement_Basics::bounce_empty_state( '2026-10-15', '2026-10-20', '' );
ok( 'counted from Oct 15, no visitors yet' === $s['sub'], 'a hero cached without its range still gets a sentence' );

// Control: no empty state falls back to the old wording.
foreach (
	[
		Engagement_Basics::time_empty_state( null, '' ),
		Engagement_Basics::time_empty_state( '2026-10-14', '2026-10-10' ),
		Engagement_Basics::bounce_empty_state( '', '2026-10-14', '' ),
		Engagement_Basics::bounce_empty_state( '2026-10-15', '2026-10-14', '' ),
		Engagement_Basics::bounce_empty_state( '2026-10-15', '2026-10-15', '' ),
	] as $state
) {
	ok( 'no visitors counted yet' !== $state['sub'] && 'counting starts tomorrow' !== $state['sub'], 'never the bare "' . $state['sub'] . '" without a day or a reason' );
}

echo $fail ? "\n{$fail} failed\n" : "\nall passed\n";
exit( $fail ? 1 : 0 );
