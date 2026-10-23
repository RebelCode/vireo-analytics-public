<?php
/**
 * Zero-dependency check of time on page and bounce rate: what the aggregator
 * folds from buffer records, and the averages and rates read back.
 * Run: php tests/test-engagement-basics.php
 *
 * Control: comment out the `if ( $timed >= $views )` guard in
 * Aggregator::fold_time() and the "a second report for one view is not a
 * second view" and "a time with no pageview behind it is dropped" checks fail.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'PHP_URL_PATH' ) ) {
	define( 'PHP_URL_PATH', 5 );
}
// WP core polyfills these; the runner has no core, and PHP 7.4 has no natives.
if ( ! function_exists( 'str_contains' ) ) {
	function str_contains( $h, $n ) { return '' === $n || false !== strpos( $h, $n ); }
}
if ( ! function_exists( 'str_starts_with' ) ) {
	function str_starts_with( $h, $n ) { return 0 === strncmp( $h, $n, strlen( $n ) ); }
}
if ( ! function_exists( 'str_ends_with' ) ) {
	function str_ends_with( $h, $n ) { return '' === $n || substr( $h, -strlen( $n ) ) === $n; }
}
function has_action( $h ) { return false; }
function do_action( ...$a ) {}
function add_action( ...$a ) {}
function apply_filters( $hook, $value, ...$a ) { return $value; }
function get_option( $name, $default = false ) { return $GLOBALS['va_options'][ $name ] ?? $default; }
function vireo_bucket_date( $ts = null ) { return gmdate( 'Y-m-d', $ts ?? time() ); }
function vireo_bucket_hour( $ts = null ) { return (int) gmdate( 'G', $ts ?? time() ); }
function home_url( $p = '' ) { return 'https://example.com' . $p; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function is_post_publicly_viewable( $id ) { return true; }
function __( $s, $d = null ) { return $s; }

/**
 * Enough of wpdb for the fold path: va_visitor_seen as a set that INSERT
 * IGNORE arbitrates, va_visitor_load as a key-value store, every table present.
 */
final class Fake_Wpdb {
	public string $prefix     = 'wp_';
	public string $last_error = '';
	public int $rows_affected = 0;
	public array $seen        = [];
	public array $load        = [];
	public $row               = null;
	public array $rows        = [];
	private array $args       = [];

	public function prepare( $sql, ...$args ) {
		$this->args = is_array( $args[0] ?? null ) ? $args[0] : $args;
		return $sql;
	}

	public function query( $sql ) {
		if ( 0 === strpos( $sql, 'INSERT IGNORE' ) ) {
			$key                 = $this->args[1] . '|' . $this->args[2];
			$this->rows_affected = isset( $this->seen[ $key ] ) ? 0 : 1;
			$this->seen[ $key ]  = true;
		}
		return 1;
	}

	public function get_var( $sql ) {
		if ( 0 === strpos( $sql, 'SHOW TABLES' ) ) {
			return $this->args[0];
		}
		return $this->load[ $this->args[1] . '|' . $this->args[2] ] ?? null;
	}

	public function get_row( $sql, $output = null ) {
		return $this->row;
	}

	public function get_results( $sql, $output = null ) {
		return $this->rows;
	}
}

require __DIR__ . '/../includes/class-sites.php';
require __DIR__ . '/../includes/class-installer.php';
require __DIR__ . '/../includes/class-collector.php';
require __DIR__ . '/../includes/class-upserter.php';
require __DIR__ . '/../includes/class-aggregator.php';
require __DIR__ . '/../includes/class-engagement-basics.php';

use VireoAnalytics\Aggregator;
use VireoAnalytics\Engagement_Basics;
use VireoAnalytics\Installer;

$fail = 0;
function ok( $cond, $msg ) {
	global $fail;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}

/** A fresh aggregator over a fresh database. */
function agg(): array {
	global $wpdb;
	$wpdb = new Fake_Wpdb();
	$ref  = new ReflectionClass( Aggregator::class );
	$agg  = $ref->newInstanceWithoutConstructor();
	foreach ( $ref->getProperties() as $prop ) {
		$type = $prop->getType();
		$prop->setAccessible( true ); // PHP 7.4 needs this to touch a private property.
		if ( $prop->isStatic() || ! $type || $prop->isInitialized( $agg ) ) {
			continue;
		}
		$prop->setValue( $agg, $type->getName() === 'array' ? [] : ( $type->getName() === 'string' ? '' : null ) );
	}
	return [ $agg, $ref ];
}

function call( array $a, string $method, array $payload ): void {
	$m = $a[1]->getMethod( $method );
	$m->setAccessible( true );
	$m->invoke( $a[0], $payload );
}

function prop( array $a, string $name ) {
	$p = $a[1]->getProperty( $name );
	$p->setAccessible( true );
	return $p->getValue( $a[0] );
}

$ts   = (int) strtotime( '2026-10-12 10:00:00 UTC' );
$day  = '2026-10-12';
$GLOBALS['va_options'] = [ Installer::BOUNCE_SINCE_OPTION => $day ];

// [ts, path, post_id, new_visitor, unique_pageview, referrer, ua, ip, visitor_hash]
$pv = static function ( string $path, string $hash ) use ( $ts ): array {
	return [ $ts, $path, 0, 0, 0, '', '', '', $hash ];
};
// [ts, path, seconds, first, visitor_hash, site]
$tm = static function ( string $path, int $seconds, int $first, string $hash ) use ( $ts ): array {
	return [ $ts, $path, $seconds, $first, $hash, '' ];
};

// ---- bounce: three visitors, one of whom reads a second page ---------------

$a = agg();
call( $a, 'fold_pageview', $pv( '/a/', 'v1' ) );
call( $a, 'fold_pageview', $pv( '/a/', 'v2' ) );
call( $a, 'fold_pageview', $pv( '/b/', 'v2' ) );
call( $a, 'fold_pageview', $pv( '/c/', 'v2' ) );
call( $a, 'fold_pageview', $pv( '/a/', 'v3' ) );
$b = prop( $a, 'bounce_rollup' )[ $day ] ?? null;
ok( null !== $b && 3 === $b->visitors, 'every visitor is in the bounce base once (got ' . ( $b->visitors ?? 'none' ) . ')' );
ok( null !== $b && 1 === $b->multi_page, 'a visitor with three pages counts as multi-page once (got ' . ( $b->multi_page ?? 'none' ) . ')' );
$site = prop( $a, 'site_rollup' )[ $day ];
ok( $b->visitors === $site->visitors, 'the bounce base is the visitor total, same unit' );
$row = Engagement_Basics::bounce_row( $b->visitors, $b->multi_page );
ok( 66.7 === $row['rate'] && 2 === $row['bounced'], 'two of three visitors bounced: 66.7% (got ' . $row['rate'] . ')' );

// A second flush on the same day: v1 comes back for a second page. The seen
// table, not the run, decides, so v1 is not a new visitor and becomes multi-page.
$db = $GLOBALS['wpdb'];
$a2 = agg();
$GLOBALS['wpdb']->seen = $db->seen;
call( $a2, 'fold_pageview', $pv( '/b/', 'v1' ) );
$b2 = prop( $a2, 'bounce_rollup' )[ $day ];
ok( 0 === $b2->visitors && 1 === $b2->multi_page, 'a later flush adds the returning visitor as multi-page only' );

// Before the counting date nothing is folded.
$GLOBALS['va_options'] = [ Installer::BOUNCE_SINCE_OPTION => '2026-10-13' ];
$a = agg();
call( $a, 'fold_pageview', $pv( '/a/', 'v1' ) );
ok( [] === prop( $a, 'bounce_rollup' ), 'the upgrade day is not counted' );
ok( 1 === prop( $a, 'site_rollup' )[ $day ]->visitors, 'control: the visitor total still counts that day' );
$GLOBALS['va_options'] = [];
$a = agg();
call( $a, 'fold_pageview', $pv( '/a/', 'v1' ) );
ok( [] === prop( $a, 'bounce_rollup' ), 'nothing is counted before the installer sets a date' );
$GLOBALS['va_options'] = [ Installer::BOUNCE_SINCE_OPTION => $day ];

// ---- time on page ----------------------------------------------------------

$a = agg();
call( $a, 'fold_pageview', $pv( '/a/', 'v1' ) );
call( $a, 'fold_time', $tm( '/a/', 40, 1, 'v1' ) );
call( $a, 'fold_time', $tm( '/a/', 25, 0, 'v1' ) );
$t = prop( $a, 'time_rollup' )[ $day ]['/a/'] ?? null;
ok( null !== $t && 1 === $t->samples && 65 === $t->seconds, 'a view that hid and came back is one sample of 65s (got ' . ( $t ? $t->samples . '/' . $t->seconds : 'none' ) . ')' );

call( $a, 'fold_time', $tm( '/a/', 30, 1, 'v1' ) );
$t = prop( $a, 'time_rollup' )[ $day ]['/a/'];
ok( 1 === $t->samples, 'a second report for one view is not a second view' );

call( $a, 'fold_time', $tm( '/b/', 30, 1, 'v1' ) );
ok( ! isset( prop( $a, 'time_rollup' )[ $day ]['/b/'] ), 'a time with no pageview behind it is dropped' );

call( $a, 'fold_time', $tm( '/a/', 30, 1, '' ) );
ok( 1 === prop( $a, 'time_rollup' )[ $day ]['/a/']->samples, 'a report with no visitor hash is dropped' );

call( $a, 'fold_time', $tm( '/a/', 9999, 0, 'v1' ) );
$t = prop( $a, 'time_rollup' )[ $day ]['/a/'];
ok( 1800 === $t->seconds, 'one view adds at most half an hour, however it is split (got ' . $t->seconds . ')' );
call( $a, 'fold_time', $tm( '/a/', 60, 0, 'v1' ) );
ok( 1800 === prop( $a, 'time_rollup' )[ $day ]['/a/']->seconds, 'a continuation past the cap adds nothing' );

call( $a, 'fold_time', $tm( '/c/', 20, 0, 'v1' ) );
ok( ! isset( prop( $a, 'time_rollup' )[ $day ]['/c/'] ), 'a continuation with no first report is dropped' );

// Two views of a page: two samples, and the allowance follows.
$a = agg();
call( $a, 'fold_pageview', $pv( '/a/', 'v9' ) );
call( $a, 'fold_pageview', $pv( '/a/', 'v9' ) );
call( $a, 'fold_time', $tm( '/a/', 10, 1, 'v9' ) );
call( $a, 'fold_time', $tm( '/a/', 20, 1, 'v9' ) );
call( $a, 'fold_time', $tm( '/a/', 5, 1, 'v9' ) );
$t = prop( $a, 'time_rollup' )[ $day ]['/a/'];
ok( 2 === $t->samples && 30 === $t->seconds, 'two counted views give two samples, a third report is refused (got ' . $t->samples . '/' . $t->seconds . ')' );

// The allowance is carried to the database with the pageview cap.
$delta = prop( $a, 'load_delta' );
ok( 2 === ( $delta[ $day . '|' . md5( 'time|/a/|v9' ) ] ?? 0 ) && 30 === ( $delta[ $day . '|' . md5( 'secs|/a/|v9' ) ] ?? 0 ), 'timed views and seconds are queued for va_visitor_load' );

// A later flush reads the allowance back: the pageview landed earlier.
$GLOBALS['wpdb']->load = [ $day . '|' . md5( 'load|/z/|v7' ) => 1 ];
$a3 = agg();
$GLOBALS['wpdb']->load = [ $day . '|' . md5( 'load|/z/|v7' ) => 1 ];
call( $a3, 'fold_time', $tm( '/z/', 12, 1, 'v7' ) );
ok( 1 === ( prop( $a3, 'time_rollup' )[ $day ]['/z/']->samples ?? 0 ), 'a report in a later flush finds the pageview counted before it' );

// ---- reads -----------------------------------------------------------------

ok( null === Engagement_Basics::time_row( 0, 0 ), 'no timed views is no average, not zero' );
ok( 33 === Engagement_Basics::time_row( 3, 100 )['avg_seconds'], 'the average is seconds over timed views' );
ok( null === Engagement_Basics::bounce_row( 0, 0 ), 'no visitors is no rate' );
ok( 0.0 === Engagement_Basics::bounce_row( 4, 9 )['rate'], 'more multi-page than visitors clamps at 0%, never negative' );
ok( '45s' === Engagement_Basics::format_seconds( 45 ), '45 seconds reads 45s' );
ok( '3m 07s' === Engagement_Basics::format_seconds( 187 ), '187 seconds reads 3m 07s' );

$GLOBALS['wpdb']->row = [ 'samples' => '4', 'seconds' => '250' ];
$s = Engagement_Basics::time_summary( '2026-10-01', '2026-10-12' );
ok( 63 === $s['avg_seconds'] && 4 === $s['samples'], 'time_summary averages the range (got ' . $s['avg_seconds'] . ')' );
$GLOBALS['wpdb']->row = [ 'samples' => null, 'seconds' => null ];
ok( null === Engagement_Basics::time_summary( '2026-10-01', '2026-10-12' ), 'an empty range has no average' );
$GLOBALS['wpdb']->row = [ 'visitors' => '200', 'multi_page' => '90' ];
ok( 55.0 === Engagement_Basics::bounce( '2026-10-01', '2026-10-12' )['rate'], 'bounce reads 110 of 200 as 55%' );

$GLOBALS['wpdb']->rows = [ [ 'path' => '/a/', 'samples' => '2', 'seconds' => '90' ] ];
$by = Engagement_Basics::time_by_path( [ '/a/', '/b/' ], '2026-10-01', '2026-10-12' );
ok( [ '/a/' ] === array_keys( $by ) && 45 === $by['/a/']['avg_seconds'], 'time_by_path keys by path and leaves untimed paths out' );
ok( [] === Engagement_Basics::time_by_path( [], '2026-10-01', '2026-10-12' ), 'no paths, no query' );

echo $fail ? "\n$fail failed\n" : "\nall passed\n";
exit( $fail ? 1 : 0 );
