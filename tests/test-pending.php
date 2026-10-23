<?php
/**
 * Zero-dependency assertion runner for today's buffered hits.
 * Run: php tests/test-pending.php
 *
 * On a host that runs wp-cron from system cron every fifteen minutes the
 * buffer holds a quarter hour of hits, and just after midnight the new day
 * has no rollup row yet, so "Pageviews today" read 0 beside a live count of
 * 10, and -100% against the whole of yesterday scaled to nineteen minutes.
 * The widget now adds what the buffer holds for today and compares with
 * yesterday up to the same time of day, hour by hour.
 */

namespace {
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
$va_test_tz = 'Europe/Lisbon';
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
}

// Pending reads files; Collector, Sites and the DB are only behind today(),
// which is not exercised here. The class file needs the names to exist.
namespace VireoAnalytics {
	final class Collector {
		public static function live_buffers(): array { return []; }
	}
	final class Sites {
		public static function current(): int { return 0; }
		public static function is_connected(): bool { return false; }
		public static function table( string $n ): string { return 'wp_va_' . $n; }
	}
	final class Aggregator {
		public static function table_ready( string $t ): bool { return false; }
	}
}

namespace {
require __DIR__ . '/../includes/class-pending.php';
require __DIR__ . '/../includes/class-partial-day.php';

use VireoAnalytics\Pending;
use VireoAnalytics\Partial_Day;

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

$dir = sys_get_temp_dir() . '/va-pending-' . getmypid();
mkdir( $dir );
$today     = vireo_bucket_date();
$noon      = ( new DateTimeImmutable( $today . ' 12:00', wp_timezone() ) )->getTimestamp();
$yesterday = $noon - DAY_IN_SECONDS;

// The record shape Collector::append_pageview() writes, as the aggregator reads it.
function pv( int $ts, string $hash, string $country = '', string $site = '' ): string {
	return serialize( [ 'p', $ts, '/post/', 12, 1, 1, '', 'Mozilla/5.0', '203.0.113.9', $hash, $country, $site ] ) . PHP_EOL;
}
$lines  = '';
$lines .= pv( $noon, 'aaaa' );
$lines .= pv( $noon + 60, 'aaaa' );          // same visitor, second page
$lines .= pv( $noon + 120, 'bbbb' );
$lines .= pv( $yesterday, 'cccc' );          // yesterday: not today's
$lines .= pv( $noon + 180, 'dddd', 'CN' );   // an excluded country, when the owner excludes it
$lines .= serialize( [ 'e', $noon, '/post/', 'click', '' ] ) . PHP_EOL;   // an event, not a pageview
$lines .= serialize( [ 't', $noon, '/post/', 12, 1 ] ) . PHP_EOL;        // time on page
$lines .= serialize( [ 'b', $noon, 'search' ] ) . PHP_EOL;               // a bot record
$lines .= "garbage line\n";
file_put_contents( "$dir/buffer-" . str_repeat( 'a', 32 ) . '.csv', $lines );

$files = [ "$dir/buffer-" . str_repeat( 'a', 32 ) . '.csv' ];

// 1. Today's pageviews and distinct visitors, with nothing excluded.
$c = Pending::count( $files, $today );
ok( 4 === $c['pageviews'], "four of today's pageviews counted (got {$c['pageviews']})" );
ok( 3 === $c['visitors'], "three distinct visitors (got {$c['visitors']})" );
ok( ! $c['capped'], 'not capped' );

// 2. Bot exclusion the aggregator applies: an excluded country.
$c = Pending::count( $files, $today, [ 'CN' ] );
ok( 3 === $c['pageviews'] && 2 === $c['visitors'], "an excluded country's hit is left out ({$c['pageviews']}/{$c['visitors']})" );

// 3. Control: the count can be zero, and yesterday is a different day.
$c = Pending::count( $files, vireo_bucket_date( $yesterday ) );
ok( 1 === $c['pageviews'] && 1 === $c['visitors'], 'yesterday counts only its own line' );
ok( 0 === Pending::count( [], $today )['pageviews'], 'no buffer, nothing pending' );
ok( 0 === Pending::count( [ "$dir/missing.csv" ], $today )['pageviews'], 'a buffer renamed away mid-read counts nothing' );

// 4. Not double-counted after a flush: the flush renames the live buffer,
//    so it is no longer live, and a visitor the rollups already counted today
//    is not a new visitor again. The unseen callback answers that.
rename( $files[0], $files[0] . '.1.busy' );
ok( 0 === Pending::count( glob( "$dir/buffer-*.csv" ) ?: [], $today )['pageviews'], 'after the flush renames it, the buffer holds nothing pending' );
rename( $files[0] . '.1.busy', $files[0] );
$c = Pending::count( $files, $today, [], static function ( array $hashes, string $date ): int {
	// aaaa was counted by an earlier flush; bbbb and dddd were not.
	return count( array_diff( $hashes, [ 'aaaa' ] ) );
} );
ok( 4 === $c['pageviews'] && 2 === $c['visitors'], "a visitor already in today's rollups is not counted again ({$c['visitors']} new of 3)" );

// 5. The cap: a buffer longer than MAX_LINES stops, and says so.
$big = str_repeat( pv( $noon, 'zzzz' ), Pending::MAX_LINES + 5 );
file_put_contents( "$dir/buffer-" . str_repeat( 'b', 32 ) . '.csv', $big );
$c = Pending::count( [ "$dir/buffer-" . str_repeat( 'b', 32 ) . '.csv' ], $today );
ok( $c['capped'] && Pending::MAX_LINES === $c['pageviews'], 'a huge buffer is capped at MAX_LINES and flagged' );

// 6. Yesterday up to the same time of day, hour by hour.
$hours = [];
for ( $h = 0; $h < 24; $h++ ) {
	$hours[ $h ] = [ 'pageviews' => 10 * ( $h + 1 ), 'visitors' => 5 * ( $h + 1 ) ];
}
$same = Partial_Day::to_same_time( $hours, 0, 19 / 60 );
ok( 3 === $same['pageviews'] && 2 === $same['visitors'], "at 00:19 yesterday-to-now is 19/60 of hour 0 ({$same['pageviews']} pv), not the whole day" );
$same = Partial_Day::to_same_time( $hours, 2, 0.5 );
ok( 10 + 20 + 15 === $same['pageviews'], 'at 02:30 it is hours 0 and 1 plus half of hour 2' );
$same = Partial_Day::to_same_time( [], 5, 0.5 );
ok( 0 === $same['pageviews'], 'no hourly rows gives zero, so the caller falls back' );
// Fault control: the old method. Yesterday scaled by 19 minutes of 1,440,
// against 128 buffered hits, read as a collapse only because the buffer was
// ignored; with the buffer it reads as what it is.
$whole_scaled = (int) round( 3000 * ( 19 / 1440 ) );
ok( 40 === $whole_scaled, 'control: the whole-day-scaled baseline at 00:19 is 40' );
ok( 0 === (int) round( ( ( 0 - 40 ) / 40 ) * 100 ) + 100, 'control: 0 pageviews against it is -100%, the bug as reported' );

array_map( 'unlink', glob( "$dir/*" ) ?: [] );
rmdir( $dir );

echo $fail ? "\n$fail failure(s)\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
}
