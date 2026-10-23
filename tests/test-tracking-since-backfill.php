<?php
/**
 * Zero-dependency check that the tracking-start backfill reads only the tables
 * live tracking writes, and ignores imported rows.
 * Run: php tests/test-tracking-since-backfill.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

$GLOBALS['opts'] = [];
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function add_option( $k, $v ) { if ( ! isset( $GLOBALS['opts'][ $k ] ) ) { $GLOBALS['opts'][ $k ] = $v; } return true; }
function update_option( $k, $v ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function vireo_bucket_date() { return '2026-10-21'; }

// Fake wpdb: each table maps to the dates it holds; prepare() just tags the query.
class Fake_Wpdb {
	public $prefix = 'wp_';
	public $dates  = [];
	public $queries = [];
	public function prepare( $q, ...$args ) { return [ $q, $args ]; }
	public function get_var( $p ) {
		$table = $p[1][0];
		$this->queries[] = $table;
		return $this->dates[ $table ] ?? null;
	}
	public function get_col( $p ) { return $this->dates[ $p[1][0] ] ?? []; }
}

require __DIR__ . '/../includes/class-sites.php';
require __DIR__ . '/../includes/class-installer.php';
require __DIR__ . '/../includes/class-import-history.php';

use VireoAnalytics\Installer;
use VireoAnalytics\Sites;

$fail = 0;
function ok( $cond, $msg ) {
	global $fail;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}
function reset_opts() { $GLOBALS['opts'] = []; }

$wpdb = new Fake_Wpdb();
$GLOBALS['wpdb'] = $wpdb;

// Jetpack history sits in site_stats back to August; Vireo's own hours start Oct 21.
$wpdb->dates[ Sites::table( 'site_stats' ) ]  = '2026-08-01';
$wpdb->dates[ Sites::table( 'hour_stats' ) ]  = '2026-10-21';
$wpdb->dates[ Sites::table( 'page_time' ) ]   = '2026-10-22';
Installer::backfill_tracking_since();
ok( '2026-10-21' === get_option( 'vireo_tracking_since' ), 'earliest live-only day wins, imported site_stats ignored' );
ok( ! in_array( Sites::table( 'site_stats' ), $wpdb->queries, true ), 'imported tables are never queried' );

// An existing value is never moved.
$wpdb->dates[ Sites::table( 'hour_stats' ) ] = '2026-10-01';
Installer::backfill_tracking_since();
ok( '2026-10-21' === get_option( 'vireo_tracking_since' ), 'a set option is left alone' );

// No live rows and only recorded imports: stays unset, unless told nothing was
// recorded before today.
reset_opts();
$GLOBALS['opts']['vireo_import_history'] = [ [ 'source' => 'jetpack', 'first' => '2026-08-01', 'last' => '2026-08-01' ] ];
$wpdb->dates = [ Sites::table( 'site_stats' ) => '2026-08-01' ];
Installer::backfill_tracking_since();
ok( '' === (string) get_option( 'vireo_tracking_since', '' ), 'no live rows leaves the option unset' );
Installer::backfill_tracking_since( true );
ok( '2026-10-21' === get_option( 'vireo_tracking_since' ), 'first flush or fresh install sets today' );

// A long-running site with no imports: the live-only rows are its own and win,
// even though the install record (set by schema 12) is much later.
reset_opts();
$GLOBALS['opts']['vireo_bounce_since'] = '2026-10-13';
$wpdb->dates = [
	Sites::table( 'site_stats' )   => [ '2026-07-04', '2026-10-17' ],
	Sites::table( 'vitals_stats' ) => '2026-07-04',
	Sites::table( 'page_time' )    => '2026-10-12',
];
Installer::backfill_tracking_since();
ok( '2026-07-04' === get_option( 'vireo_tracking_since' ), 'live-only rows from July win over a later install record (got ' . get_option( 'vireo_tracking_since' ) . ')' );

// The real failure: Jetpack history back to 2018 imported with no run on
// record, empty live-only tables, Vireo installed today. The import-history
// fallback sees 2018 as Vireo's own, so the install record floors it.
reset_opts();
$GLOBALS['opts']['vireo_bounce_since'] = '2026-10-21';
$wpdb->dates = [ Sites::table( 'site_stats' ) => [ '2018-03-02', '2026-10-17' ] ];
Installer::backfill_tracking_since();
ok( '2026-10-21' === get_option( 'vireo_tracking_since' ), 'unplaced imported history never predates the install record (got ' . get_option( 'vireo_tracking_since' ) . ')' );

// Old rows restored from a backup into the live-only tables are floored too.
reset_opts();
$GLOBALS['opts']['vireo_bounce_since']   = '2026-10-21';
$GLOBALS['opts']['vireo_import_history'] = [ [ 'source' => 'backup', 'at' => 1 ] ];
$wpdb->dates = [
	Sites::table( 'site_stats' ) => [ '2018-03-02', '2026-10-17' ],
	Sites::table( 'hour_stats' ) => '2018-03-02',
	Sites::table( 'page_time' )  => '2019-01-01',
];
Installer::backfill_tracking_since( true );
ok( '2026-10-21' === get_option( 'vireo_tracking_since' ), 'restored live-only rows never predate the install record (got ' . get_option( 'vireo_tracking_since' ) . ')' );

// A day Vireo recorded after its install record stands.
reset_opts();
$GLOBALS['opts']['vireo_bounce_since'] = '2026-10-12';
$wpdb->dates = [ Sites::table( 'hour_stats' ) => '2026-10-15' ];
Installer::backfill_tracking_since();
ok( '2026-10-15' === get_option( 'vireo_tracking_since' ), 'a first live day after the install record is kept' );

// An upgrade marks bounce counting from tomorrow; the start never lands in the future.
reset_opts();
$GLOBALS['opts']['vireo_bounce_since'] = '2026-10-22';
$wpdb->dates = [];
Installer::backfill_tracking_since( true );
ok( '2026-10-21' === get_option( 'vireo_tracking_since' ), 'a floor of tomorrow is capped at today' );

// One-time repair of what 0.16.2 and 0.16.3 stored.
reset_opts();
$GLOBALS['opts']['vireo_bounce_since']   = '2026-10-21';
$GLOBALS['opts']['vireo_tracking_since'] = '2018-03-02';
$wpdb->dates = [ Sites::table( 'site_stats' ) => [ '2018-03-02', '2026-10-17' ] ];
Installer::repair_tracking_since();
ok( '2026-10-21' === get_option( 'vireo_tracking_since' ), 'a weak stored start with empty live-only tables is moved up to the install record' );

reset_opts();
$GLOBALS['opts']['vireo_bounce_since']   = '2026-10-13';
$GLOBALS['opts']['vireo_tracking_since'] = '2026-07-04';
$wpdb->dates = [
	Sites::table( 'site_stats' )   => [ '2026-07-04' ],
	Sites::table( 'vitals_stats' ) => '2026-07-05',
];
Installer::repair_tracking_since();
ok( '2026-07-04' === get_option( 'vireo_tracking_since' ), 'a stored July start the live rows back up is left alone' );

reset_opts();
$GLOBALS['opts']['vireo_bounce_since']   = '2026-10-13';
$GLOBALS['opts']['vireo_tracking_since'] = '2018-03-02';
$wpdb->dates = [
	Sites::table( 'site_stats' )   => [ '2018-03-02' ],
	Sites::table( 'vitals_stats' ) => '2026-07-04',
];
Installer::repair_tracking_since();
ok( '2026-07-04' === get_option( 'vireo_tracking_since' ), 'a stored start well before the live rows moves to them, not the floor (got ' . get_option( 'vireo_tracking_since' ) . ')' );

reset_opts();
$GLOBALS['opts']['vireo_tracking_since'] = '2018-03-02';
$wpdb->dates = [ Sites::table( 'site_stats' ) => [ '2018-03-02' ] ];
Installer::repair_tracking_since();
ok( '2018-03-02' === get_option( 'vireo_tracking_since' ), 'no install record, no repair' );

$main = (string) file_get_contents( __DIR__ . '/../vireo-analytics.php' );
ok( 1 === preg_match( "/'VIREO_DB_VERSION', '(\d+)'/", $main, $m ) && (int) $m[1] >= 13, 'schema bumped so installs already on 12 run the backfill and repair' );

ok( '' === Installer::earliest_day( [ null, '', 'x' ] ), 'junk values give no day' );

echo $fail ? "\n$fail failure(s)\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
