<?php
/**
 * Zero-dependency assertion runner for the dashboard section registry.
 * Run: php tests/test-dashboard-sections.php
 */

if ( ! function_exists( '__' ) ) {
	function __( $s, $d = null ) { return $s; }
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

// In-memory user meta, so the persistence methods run outside WordPress.
$GLOBALS['va_test_meta'] = [];
if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( $user_id, $key, $single = false ) {
		return $GLOBALS['va_test_meta'][ $user_id ][ $key ] ?? '';
	}
}
if ( ! function_exists( 'update_user_meta' ) ) {
	function update_user_meta( $user_id, $key, $value ) {
		$GLOBALS['va_test_meta'][ $user_id ][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_user_meta' ) ) {
	function delete_user_meta( $user_id, $key ) {
		unset( $GLOBALS['va_test_meta'][ $user_id ][ $key ] );
		return true;
	}
}

require __DIR__ . '/../includes/class-dashboard-sections.php';

use VireoAnalytics\Dashboard_Sections;

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

$default = Dashboard_Sections::default_order();

// 1. The registry is the six reorderable sections, in the agreed default order.
ok(
	$default === [ 'chart', 'drivers', 'top-pages', 'top-referrers', 'countries', 'devices' ],
	'default order is chart, drivers, the three leaderboards, devices'
);

// 2. Every default id has a definition, and every definition has a usable width.
$defs = Dashboard_Sections::definitions();
$widths_ok = true;
foreach ( $default as $id ) {
	if ( ! isset( $defs[ $id ] ) || ! in_array( $defs[ $id ]['width'], [ 'full', 'third' ], true ) ) {
		$widths_ok = false;
	}
}
ok( $widths_ok, 'every section has a definition with width full or third' );

// 3. Garbage in, default out.
ok( Dashboard_Sections::normalize( '' ) === $default, 'empty string normalizes to default' );
ok( Dashboard_Sections::normalize( null ) === $default, 'null normalizes to default' );
ok( Dashboard_Sections::normalize( [] ) === $default, 'empty array normalizes to default' );
ok( Dashboard_Sections::normalize( [ 1, 2, 3 ] ) === $default, 'non-string ids normalize to default' );

// 4. A full valid order round-trips untouched.
$custom = [ 'chart', 'drivers', 'countries', 'top-pages', 'devices', 'top-referrers' ];
ok( Dashboard_Sections::normalize( $custom ) === $custom, 'a complete valid order is preserved exactly' );

// 5. Ids the registry does not know are dropped.
$with_junk = [ 'chart', 'ghost-section', 'drivers', 'countries', 'top-pages', 'devices', 'top-referrers' ];
ok( Dashboard_Sections::normalize( $with_junk ) === $custom, 'unknown ids are dropped' );

// 6. Duplicates collapse to the first occurrence.
$with_dupes = [ 'chart', 'chart', 'drivers', 'countries', 'top-pages', 'devices', 'top-referrers', 'drivers' ];
ok( Dashboard_Sections::normalize( $with_dupes ) === $custom, 'duplicate ids are collapsed' );

// 7. The upgrade case: a stored order predating a section still yields all six,
//    with the newcomer near its default slot and the user's order preserved.
$partial = Dashboard_Sections::normalize( [ 'devices', 'chart' ] );
ok( count( $partial ) === 6, 'a partial stored order is topped back up to six sections' );
ok( count( array_diff( $default, $partial ) ) === 0, 'no section goes missing after a partial order' );
ok(
	array_search( 'devices', $partial, true ) < array_search( 'chart', $partial, true ),
	'the user-chosen relative order of stored sections survives the top-up'
);

// 8. Persistence round-trip.
Dashboard_Sections::save_order( 7, $custom );
ok( Dashboard_Sections::user_order( 7 ) === $custom, 'a saved order reads back for that user' );
ok( Dashboard_Sections::user_order( 99 ) === $default, 'a user who never dragged gets the default' );

// 9. A hostile POST cannot store a broken layout.
Dashboard_Sections::save_order( 8, [ 'ghost', 'chart' ] );
$stored = Dashboard_Sections::user_order( 8 );
ok( count( $stored ) === 6 && ! in_array( 'ghost', $stored, true ), 'save sanitizes before storing' );

// 10. Reset drops back to default.
Dashboard_Sections::reset_order( 7 );
ok( Dashboard_Sections::user_order( 7 ) === $default, 'reset restores the default order' );

echo $fail ? "\n$fail test(s) failed.\n" : "\nAll tests passed.\n";
exit( $fail ? 1 : 0 );
