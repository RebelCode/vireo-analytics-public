<?php
/**
 * Zero-dependency check that the shipped tracker and vitals scripts are the
 * minified form of their sources and carry the same behaviour.
 * Run: php tests/test-tracker-build.php
 *
 * The browser loads assets/js/tracker.js and assets/js/vitals.js; the readable
 * code lives in assets/js/src/. Minification renames locals and drops
 * comments but never touches a string literal, so every beacon field name,
 * event name, DOM selector and attribute the source mentions has to appear in
 * the shipped file. A build left stale after a source edit fails here on the
 * literal that edit added.
 *
 * Control: add 'nonexistent_literal' to src/tracker.js without rebuilding and
 * the "every string literal" check fails.
 */

$root = dirname( __DIR__ );
$fail = 0;
function ok( $cond, $msg ) {
	global $fail;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}

/** Every single- or double-quoted string literal in a script, de-duplicated. */
function literals( string $js ): array {
	// Comments first, so a quote inside one is not read as a literal.
	$js = preg_replace( '~/\*.*?\*/~s', '', $js );
	$js = preg_replace( '~^\s*//.*$~m', '', $js );
	preg_match_all( "~'((?:[^'\\\\]|\\\\.)*)'|\"((?:[^\"\\\\]|\\\\.)*)\"~", $js, $m );
	$out = [];
	foreach ( array_merge( $m[1], $m[2] ) as $s ) {
		if ( '' !== $s ) {
			$out[ $s ] = true;
		}
	}
	return array_keys( $out );
}

foreach ( [ 'tracker', 'vitals' ] as $name ) {
	$src = "$root/assets/js/src/$name.js";
	$out = "$root/assets/js/$name.js";

	ok( is_file( $src ), "$name: readable source ships at assets/js/src/$name.js" );
	ok( is_file( $out ), "$name: the browser's file is still assets/js/$name.js" );
	if ( ! is_file( $src ) || ! is_file( $out ) ) {
		continue;
	}

	$source = (string) file_get_contents( $src );
	$built  = (string) file_get_contents( $out );

	ok( strlen( $built ) < strlen( $source ) / 2, sprintf( '%s: minified (%d B) is under half the source (%d B)', $name, strlen( $built ), strlen( $source ) ) );
	ok( substr_count( $built, "\n" ) <= 3, "$name: minified file is on one line" );
	ok( false === strpos( $built, '/*' ), "$name: no block comments survive" );

	$lits    = literals( $source );
	$missing = [];
	foreach ( $lits as $lit ) {
		// Terser may switch quote style, so compare the unescaped text.
		$plain = stripcslashes( $lit );
		if ( false === strpos( $built, $plain ) && false === strpos( $built, $lit ) ) {
			$missing[] = $lit;
		}
	}
	ok( count( $lits ) > 20, "$name: the source has string literals to compare (" . count( $lits ) . ')' );
	ok( [] === $missing, "$name: every string literal in the source is in the build" . ( $missing ? ' (missing: ' . implode( ', ', array_slice( $missing, 0, 5 ) ) . ')' : '' ) );
}

// The names the rest of the plugin depends on, by file.
$tracker = (string) file_get_contents( "$root/assets/js/tracker.js" );
foreach ( [ 'vireo_collect', 'pageview', 'time', 'event', 'data-endpoint', 'data-site', 'vireo:post-id', 'astro:page-load', 'form_submit', 'wpra_click', 'spotlight_post_click', 'pagehide', 'visibilitychange' ] as $needle ) {
	ok( false !== strpos( $tracker, $needle ), "tracker: carries '$needle'" );
}
$vitals = (string) file_get_contents( "$root/assets/js/vitals.js" );
foreach ( [ 'vitals', 'lcp', 'cls', 'inp' ] as $needle ) {
	ok( false !== strpos( $vitals, $needle ), "vitals: carries '$needle'" );
}

echo $fail ? "\n$fail failed\n" : "\nall passed\n";
exit( $fail ? 1 : 0 );
