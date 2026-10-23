<?php
/**
 * Zero-dependency check of the AI assistants exchange maths.
 * Run: php tests/test-ai-exchange.php
 *
 * Crawlers are counted from the day the bot filter started running, while
 * referrals go back as far as the referrer table does. On jeangalea.com the
 * 90-day view divided 27 days of crawling by 90 days of referrals and showed
 * 59.6 where the honest figure over the counted days was about 360. Both
 * sides have to start on the first day with crawler data, and readers have
 * to be visitors, not pageviews.
 *
 * $wpdb is a stub that answers the four queries the class runs from a small
 * table of seeded days.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) { return $value; }
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

// Sites::table() needs only the prefix.
if ( ! class_exists( 'VireoAnalytics\Sites' ) ) {
	require __DIR__ . '/../includes/class-sites.php';
}

/**
 * Range: 2026-07-13 to 2026-10-10 (90 days). Referrals every day from
 * chatgpt.com: 3 pageviews, 2 visitors. Bot stats only from 2026-09-14
 * (27 days): 200 ai hits a day, plus a search row so "any category" is
 * tested.
 */
final class VA_Fake_Wpdb {
	public string $prefix = 'wp_';
	public array $bot = [];
	public array $ref = [];
	public array $log = [];

	public function __construct() {
		$d = new DateTimeImmutable( '2026-07-13' );
		for ( $i = 0; $i < 90; $i++ ) {
			$day = $d->modify( "+$i days" )->format( 'Y-m-d' );
			$this->ref[ $day ] = [ 'pageviews' => 3, 'visitors' => 2 ];
			if ( $day >= '2026-09-14' ) {
				$this->bot[ $day ] = [ 'ai' => 200, 'search' => 50 ];
			}
		}
	}

	public function prepare( $sql, ...$args ) {
		$args = isset( $args[0] ) && is_array( $args[0] ) ? $args[0] : $args;
		$this->log[] = [ $sql, $args ];
		return [ $sql, $args ];
	}

	/** Dates are the last two string args that look like dates. */
	private function range( array $args ): array {
		$dates = array_values( array_filter( $args, static fn( $a ) => is_string( $a ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $a ) ) );
		return [ $dates[0] ?? '0000-00-00', $dates[1] ?? '9999-99-99' ];
	}

	public function get_var( $q ) {
		[ $sql, $args ] = $q;
		[ $s, $e ]      = $this->range( $args );
		if ( false !== strpos( $sql, 'MIN(date)' ) ) {
			$days = array_filter( array_keys( $this->bot ), static fn( $d ) => $d >= $s && $d <= $e );
			return $days ? min( $days ) : null;
		}
		if ( false !== strpos( $sql, 'SUM(hits)' ) ) {
			$cat = in_array( 'ai', $args, true ) ? 'ai' : 'other';
			$sum = 0;
			foreach ( $this->bot as $d => $row ) {
				if ( $d >= $s && $d <= $e ) {
					$sum += $row[ $cat ] ?? 0;
				}
			}
			return $sum;
		}
		return null;
	}

	public function get_results( $q, $output = null ) {
		[ $sql, $args ] = $q;
		[ $s, $e ]      = $this->range( $args );
		$pv = 0; $vis = 0;
		foreach ( $this->ref as $d => $row ) {
			if ( $d >= $s && $d <= $e ) {
				$pv  += $row['pageviews'];
				$vis += $row['visitors'];
			}
		}
		return [ [ 'host' => 'chatgpt.com', 'pageviews' => $pv, 'visitors' => $vis ] ];
	}
}

$GLOBALS['wpdb'] = new VA_Fake_Wpdb();

require __DIR__ . '/../includes/class-ai-sources.php';

use VireoAnalytics\AI_Sources;

$fail = 0;
function ok( $cond, $msg ) {
	global $fail;
	echo ( $cond ? 'PASS: ' : 'FAIL: ' ) . $msg . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}

$start = '2026-07-13';
$end   = '2026-10-10';

// --- the old maths, as a control: whole range on both sides ---------------
$old_crawled  = AI_Sources::crawl_requests( $start, $end );      // 27 * 200
$old_referred = (int) AI_Sources::referrals( $start, $end )['total']; // 90 * 2
$old_rate     = AI_Sources::exchange_rate( $old_crawled, $old_referred );
ok( 5400 === $old_crawled && 180 === $old_referred && 30.0 === $old_rate, "control: whole-range maths gives $old_crawled / $old_referred = $old_rate, the understated ratio" );

// --- readers are visitors ---------------------------------------------------
ok( 180 === $old_referred, 'readers sent back are visitors (2/day), not pageviews (3/day)' );
ok( 2 * 90 === (int) AI_Sources::referrals( $start, $end )['by_source'][0]['visitors'], 'the per-assistant row carries visitors too' );

// --- the exchange clamps both sides to the counted days --------------------
$x = AI_Sources::exchange( $start, $end );
ok( '2026-09-14' === $x['since'] && '2026-09-14' === $x['start'] && $end === $x['end'], "the window starts on the first day with bot rows: {$x['start']} to {$x['end']}, since={$x['since']}" );
ok( 5400 === $x['crawled'], 'crawler requests over the counted days' );
ok( 54 === $x['referred'], 'readers over the same 27 days, not all 90 (' . $x['referred'] . ')' );
ok( 100.0 === $x['rate'], "ratio over the same days is {$x['rate']}, not the whole-range $old_rate" );
ok( $x['rate'] !== $old_rate, 'the control and the fix disagree, so the test can tell them apart' );

// --- a range fully covered needs no note -------------------------------------
$y = AI_Sources::exchange( '2026-09-20', $end );
ok( '' === $y['since'] && '2026-09-20' === $y['start'], 'a range that starts inside the covered days keeps its start and has no since' );
ok( 21 * 200 === $y['crawled'] && 21 * 2 === $y['referred'] && 100.0 === $y['rate'], 'and its numbers are the plain range sums' );

// --- a range before any crawler data -------------------------------------------
$z = AI_Sources::exchange( '2026-07-13', '2026-08-31' );
ok( '' === $z['since'] && 0 === $z['crawled'] && null === $z['rate'], 'no bot rows at all: zero crawled, no ratio, no since' );
ok( 50 * 2 === $z['referred'], 'but readers are still reported for the range' );

// --- the first covered day is any category, not just ai ---------------------------
$GLOBALS['wpdb']->bot['2026-09-10'] = [ 'search' => 5 ];
$w = AI_Sources::exchange( $start, $end );
ok( '2026-09-10' === $w['since'], 'a day with only search-engine bot rows still counts as the filter running' );

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
