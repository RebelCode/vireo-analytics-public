<?php
/**
 * Zero-dependency check that the retention purge reaches every daily table,
 * on the main site and on each connected site's copy.
 * Run: php tests/test-retention.php
 *
 * Every table the installer defines with a `date` column must either be in
 * Retention::DAILY_TABLES or be named below with the reason it is not, so a
 * table added later can't silently keep its rows forever the way the Core Web
 * Vitals histograms did.
 *
 * Control: take 'vitals_stats' out of Retention::DAILY_TABLES and the
 * "vitals_stats is purged" and "every dated table is accounted for" checks fail.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
function add_action( ...$a ) {}
function get_option( $name, $default = false ) { return $GLOBALS['va_options'][ $name ] ?? $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['va_options'][ $name ] = $value; return true; }
function vireo_get_settings() { return [ 'retention_days' => 30 ]; }
function apply_filters( $hook, $value, ...$a ) { return $value; }
function sanitize_text_field( $s ) { return trim( (string) $s ); }

final class Fake_Wpdb {
	public string $prefix = 'wp_';
	public array $deleted = [];
	private array $args   = [];

	public function prepare( $sql, ...$args ) {
		$this->args = $args;
		return $sql;
	}

	public function get_var( $sql ) {
		return $this->args[0];
	}

	public function query( $sql ) {
		if ( 0 === strpos( $sql, 'DELETE' ) ) {
			$this->deleted[] = $this->args[0];
		}
		return 1;
	}
}

require __DIR__ . '/../includes/class-sites.php';
require __DIR__ . '/../includes/class-installer.php';
require __DIR__ . '/../includes/class-retention.php';

use VireoAnalytics\Installer;
use VireoAnalytics\Retention;
use VireoAnalytics\Sites;

$fail = 0;
function ok( $cond, $msg ) {
	global $fail;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}

$GLOBALS['va_options'] = [
	Sites::OPTION => [
		'sites' => [
			[ 'id' => 3, 'name' => 'Docs', 'origin' => 'https://docs.example.com', 'key' => str_repeat( 'a', 32 ) ],
		],
	],
];
$GLOBALS['wpdb'] = new Fake_Wpdb();

$ref = new ReflectionClass( Retention::class );
$ret = $ref->newInstanceWithoutConstructor();
$ret->run();
$deleted = $GLOBALS['wpdb']->deleted;

ok( count( $deleted ) > 20, 'the purge ran on both sites (' . count( $deleted ) . ' deletes)' );
ok( [ 3 ] === Retention::site_ids(), 'one connected site is configured' );

foreach ( [ 'vitals_stats', 'page_time', 'bounce_stats' ] as $name ) {
	ok( in_array( 'wp_va_' . $name, $deleted, true ), "$name is purged on the main site" );
	ok( in_array( 'wp_va_s3_' . $name, $deleted, true ), "$name is purged on the connected site's copy" );
}

// Every dated table is purged by retention or has a reason it isn't.
$elsewhere = [
	'visitor_seen' => 'working state, kept two days by purge_visitor_seen()',
	'visitor_load' => 'working state, kept two days by purge_visitor_seen()',
	'hour_stats'   => 'kept seven days by Aggregator::purge_hour_stats()',
	'annotations'  => 'notes the owner wrote, not collected data',
];
$dated = [];
foreach ( Installer::table_definitions() as $name => $columns ) {
	if ( 1 === preg_match( '/^\s*date date NOT NULL/m', $columns ) ) {
		$dated[] = $name;
	}
}
ok( count( $dated ) >= 15, 'the installer\'s dated tables were found (' . count( $dated ) . ')' );
$daily = ( new ReflectionClassConstant( Retention::class, 'DAILY_TABLES' ) )->getValue();
$missed = array_values( array_diff( $dated, $daily, array_keys( $elsewhere ) ) );
ok( [] === $missed, 'every dated table is accounted for (unaccounted: ' . implode( ',', $missed ) . ')' );

echo $fail ? "\n$fail failed\n" : "\nall passed\n";
exit( $fail ? 1 : 0 );
