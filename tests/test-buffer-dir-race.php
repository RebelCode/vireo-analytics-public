<?php
/**
 * Zero-dependency check that a beacon which loses the race to create the
 * buffer directory still records its visit.
 * Run: php tests/test-buffer-dir-race.php
 *
 * A connected site's first two visits can both find its directory missing.
 * Both call mkdir(), and the loser gets false for a directory that now
 * exists. wp_mkdir_p() is stubbed here to play the loser: it creates the
 * directory, as the other request would, then reports failure.
 *
 * Control: in Collector::ensure_dir(), return false straight after a failed
 * wp_mkdir_p() (what 0.12.5 and earlier did) and the "losing request
 * still writes its line" check fails.
 */

// Stand-ins for the two classes Collector reaches for: a connected site's
// directory, and a hardening call that is only recorded.
namespace VireoAnalytics {
	final class Sites {
		const MAIN = 0;
		public static function data_dir( $id = null ) {
			return $GLOBALS['va_dir'] . '/site-1';
		}
	}
	final class Salt_Manager {
		public static function harden_directory( $d ) {
			$GLOBALS['hardened'][] = $d;
		}
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ );
	}

	$GLOBALS['va_dir']   = sys_get_temp_dir() . '/va-race-' . getmypid() . '-' . mt_rand();
	$GLOBALS['va_mode']  = 'lose';
	$GLOBALS['hardened'] = [];

	function wp_mkdir_p( $dir ) {
		if ( 'fail' === $GLOBALS['va_mode'] ) {
			return false;
		}
		mkdir( $dir, 0755, true );
		return 'win' === $GLOBALS['va_mode'];
	}

	function apply_filters( $hook, $value, ...$a ) { return $value; }

	require __DIR__ . '/../includes/class-collector.php';

	$fail = 0;
	function ok( $cond, $msg ) {
		global $fail;
		echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n";
		if ( ! $cond ) {
			$fail++;
		}
	}

	$dir = $GLOBALS['va_dir'] . '/site-1';

	// The losing request: mkdir() reports failure, but the directory is there.
	ok( ! is_dir( $dir ), 'the directory starts missing' );
	ok( true === \VireoAnalytics\Collector::append_line( [ 't', 1, '/a/', 5, 1, 'h', '' ] ), 'the losing request still writes its line' );
	$files = glob( $dir . '/buffer-*.csv' );
	ok( 1 === count( $files ) && 1 === count( file( $files[0] ) ), 'one buffer with one line (' . count( $files ) . ' buffers)' );
	ok( [] === $GLOBALS['hardened'], 'the loser leaves hardening to the request that created it' );

	// The winning request on a fresh directory hardens it.
	array_map( 'unlink', glob( $dir . '/*' ) );
	rmdir( $dir );
	$GLOBALS['va_mode'] = 'win';
	ok( true === \VireoAnalytics\Collector::append_line( [ 'b', 1, 'other' ] ), 'the winning request writes its line' );
	ok( [ $dir ] === $GLOBALS['hardened'], 'and hardens the directory it created' );

	// A directory that really can't be created is still a failure.
	array_map( 'unlink', glob( $dir . '/*' ) );
	rmdir( $dir );
	$GLOBALS['va_mode'] = 'fail';
	ok( false === \VireoAnalytics\Collector::append_line( [ 'b', 1, 'other' ] ), 'control: a directory that can\'t be created fails the write' );

	rmdir( $GLOBALS['va_dir'] );

	echo $fail ? "\n$fail failed\n" : "\nall passed\n";
	exit( $fail ? 1 : 0 );
}
