<?php
/**
 * Zero-dependency assertion runner for the import history sentences.
 * Run: php tests/test-import-summary.php
 *
 * The "Already imported" line printed the import's own date in the site's
 * format and the range it covered as raw 2025-06-29, in one sentence.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}
if ( ! function_exists( '__' ) ) {
	function __( $s, $d = null ) { return $s; }
}
if ( ! function_exists( '_n' ) ) {
	function _n( $one, $many, $n, $d = null ) { return 1 === (int) $n ? $one : $many; }
}
if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, $d ); }
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return [ 'date_format' => 'F j, Y', 'time_format' => 'g:i a' ][ $name ] ?? $default;
	}
}
if ( ! function_exists( 'wp_timezone' ) ) {
	function wp_timezone() { return new DateTimeZone( 'Europe/Lisbon' ); }
}
if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( $format, $ts = null, $tz = null ) {
		return ( new DateTimeImmutable( '@' . ( $ts ?? time() ) ) )->setTimezone( $tz ?? wp_timezone() )->format( $format );
	}
}

require __DIR__ . '/../includes/va-tz.php';
require __DIR__ . '/../includes/class-import-history.php';

use VireoAnalytics\Import_History;

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

$run = [
	'source'    => 'ga4',
	'start'     => '2025-06-29',
	'end'       => '2026-03-31',
	'at'        => (int) strtotime( '2026-04-01 10:00:00 UTC' ),
	'user'      => 1,
	'first'     => '2025-06-29',
	'last'      => '2026-03-31',
	'days'      => 276,
	'pageviews' => 161498,
	'own_from'  => '2026-04-01',
];

$written = Import_History::summary( $run );
ok( false !== strpos( $written, 'June 29, 2025 to March 31, 2026' ), 'the written range uses the site date format (' . $written . ')' );
ok( 0 === preg_match( '/\d{4}-\d{2}-\d{2}/', $written ), 'no raw Y-m-d left in the sentence' );

$asked = Import_History::summary( array_merge( $run, [ 'first' => '', 'last' => '', 'days' => 0 ] ) );
ok( false !== strpos( $asked, 'covering June 29, 2025 to March 31, 2026' ), 'the requested range does too (' . $asked . ')' );

$overlap = Import_History::describe( [ $run ] );
ok( 0 === preg_match( '/\d{4}-\d{2}-\d{2}/', $overlap ), 'the overlap warning names dates the same way (' . $overlap . ')' );

echo $fail ? "\n$fail failed\n" : "\nall passed\n";
exit( $fail ? 1 : 0 );
