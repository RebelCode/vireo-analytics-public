<?php
/**
 * Zero-dependency assertion runner for the comment counts shown next to views.
 * Run: php tests/test-comment-stats.php
 *
 * Covers the rate rule, the local-day bounds and the cache key. The grouped
 * query itself needs WordPress and is reconciled against SQL in wp-env.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

require __DIR__ . '/../includes/class-comment-stats.php';

use VireoAnalytics\Comment_Stats;

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

// Rate per 1,000 views, hidden below the threshold where it means nothing.
ok( 3.0 === Comment_Stats::rate( 3, 1000 ), '3 comments on 1,000 views is 3.0' );
ok( 3.0 === Comment_Stats::rate( 7, 2345 ), 'rounded to one decimal (2.985 -> 3.0)' );
ok( 0.4 === Comment_Stats::rate( 1, 2500 ), 'small rates keep their decimal' );
ok( 0.0 === Comment_Stats::rate( 0, 500 ), 'no comments on real traffic is a real 0' );
ok( null === Comment_Stats::rate( 1, 99 ), 'below 100 views there is no rate' );
ok( 10.0 === Comment_Stats::rate( 1, 100 ), 'exactly 100 views has a rate' );
ok( null === Comment_Stats::rate( 5, 0 ), 'zero views has no rate' );
ok( null === Comment_Stats::rate( -1, 500 ), 'negative input has no rate' );

// Local-day bounds, inclusive of the whole end day.
ok( [ '2026-09-07 00:00:00', '2026-10-06 23:59:59' ] === Comment_Stats::local_bounds( '2026-09-07', '2026-10-06' ), 'bounds cover start midnight to end of the last day' );
ok( [ '2026-09-07 00:00:00', '2026-10-06 23:59:59' ] === Comment_Stats::local_bounds( '2026-10-06', '2026-09-07' ), 'reversed range is swapped' );

// IDs: positive, unique, sorted.
ok( [ 3, 9, 12 ] === Comment_Stats::normalize_ids( [ '12', 3, 9, 3, 0, -4, 'x' ] ), 'ids cleaned, deduped, sorted' );
ok( [] === Comment_Stats::normalize_ids( [] ), 'no ids, no ids' );

// Cache key: order-insensitive, changes with range and with the comment cache generation.
$k = Comment_Stats::cache_key( [ 3, 9, 12 ], '2026-09-07', '2026-10-06', '0.1 1' );
ok( $k === Comment_Stats::cache_key( [ 12, 3, 9, 9 ], '2026-09-07', '2026-10-06', '0.1 1' ), 'id order does not change the key' );
ok( $k !== Comment_Stats::cache_key( [ 3, 9 ], '2026-09-07', '2026-10-06', '0.1 1' ), 'different ids, different key' );
ok( $k !== Comment_Stats::cache_key( [ 3, 9, 12 ], '2026-09-08', '2026-10-06', '0.1 1' ), 'different start, different key' );
ok( $k !== Comment_Stats::cache_key( [ 3, 9, 12 ], '2026-09-07', '2026-10-07', '0.1 1' ), 'different end, different key' );
ok( $k !== Comment_Stats::cache_key( [ 3, 9, 12 ], '2026-09-07', '2026-10-06', '0.2 1' ), 'a new comment changes the key' );

// Only reader comments count: not pingbacks, trackbacks or editorial notes.
ok( in_array( 'comment', Comment_Stats::COUNTED_TYPES, true ) && in_array( '', Comment_Stats::COUNTED_TYPES, true ), 'comments (and the pre-5.5 empty type) count' );
ok( ! in_array( 'pingback', Comment_Stats::COUNTED_TYPES, true ) && ! in_array( 'trackback', Comment_Stats::COUNTED_TYPES, true ), 'pingbacks and trackbacks do not' );
ok( ! in_array( 'note', Comment_Stats::COUNTED_TYPES, true ), 'block editor notes do not' );

echo "\n" . ( $fail ? "$fail failed, $pass passed\n" : "all $pass passed\n" );
exit( $fail ? 1 : 0 );
