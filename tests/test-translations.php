<?php
/**
 * Zero-dependency check: a wp.org language pack and the bundled catalogue are
 * both loaded, pack first, and only when both exist.
 * Run: php tests/test-translations.php
 *
 * Control: on the live 0.12.2 site a Japanese dashboard with the ja pack
 * installed shows "Year in review", "Page speed" and the settings tabs in
 * English, because WordPress loads the pack alone. With this merge the same
 * pages render fully in Japanese and the pack's wording is kept where it
 * exists (see the 0.12.3 QA screenshots).
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/va-translations-' . getmypid();
mkdir( "$tmp/plugin/languages", 0777, true );
mkdir( "$tmp/lang/plugins", 0777, true );
define( 'VIREO_DIR', "$tmp/plugin/" );
define( 'WP_LANG_DIR', "$tmp/lang" );

$hooks  = [];
$loaded = [];
$locale = 'ja';
function add_action( $hook, $cb ) {
	global $hooks;
	$hooks[] = $hook;
}
function determine_locale() {
	global $locale;
	return $locale;
}
function load_textdomain( $domain, $mofile, $loc = null ) {
	global $loaded;
	$loaded[] = [ $domain, basename( $mofile ), $loc ];
	return true;
}

require __DIR__ . '/../includes/class-translations.php';

use VireoAnalytics\Translations;

$fail = 0;
function ok( $cond, $msg ) {
	global $fail;
	echo ( $cond ? 'PASS: ' : 'FAIL: ' ), $msg, "\n";
	if ( ! $cond ) {
		$fail++;
	}
}
function touch_file( $path ) {
	file_put_contents( $path, 'x' );
}

Translations::boot();
ok( [ 'init', 'change_locale' ] === $hooks, 'merge runs on init and again when the locale is switched' );

// Nothing shipped for the locale: a no-op even with a pack present.
touch_file( "$tmp/lang/plugins/vireo-analytics-ja.mo" );
ok( [] === Translations::merge() && [] === $loaded, 'no bundled file for the locale: nothing is loaded' );

// Bundle but no pack: WordPress finds the bundle itself; nothing to do.
Translations::reset();
unlink( "$tmp/lang/plugins/vireo-analytics-ja.mo" );
touch_file( "$tmp/plugin/languages/vireo-analytics-ja.mo" );
ok( [] === Translations::merge() && [] === $loaded, 'bundle but no pack: nothing is loaded, the registry reaches the bundle on its own' );

// Both: pack first, then the bundle, for the same domain and locale.
Translations::reset();
touch_file( "$tmp/lang/plugins/vireo-analytics-ja.mo" );
$result = Translations::merge();
ok( 2 === count( $result ) && 'vireo-analytics-ja.mo' === basename( $result[0] ) && 0 === strpos( $result[0], "$tmp/lang/" ), 'with both files the pack is loaded first' );
ok( isset( $result[1] ) && 0 === strpos( $result[1], "$tmp/plugin/" ), 'and the bundled catalogue second, so the pack wins on overlaps and the bundle fills the gaps' );
ok( [ [ 'vireo-analytics', 'vireo-analytics-ja.mo', 'ja' ], [ 'vireo-analytics', 'vireo-analytics-ja.mo', 'ja' ] ] === $loaded, 'both loads name the plugin text domain and the locale' );

// Repeated init in the same request does not load again.
ok( [] === Translations::merge() && 2 === count( $loaded ), 'a second merge for the same locale is a no-op' );

// A pack shipped only as .l10n.php still counts as present.
Translations::reset();
$loaded = [];
unlink( "$tmp/lang/plugins/vireo-analytics-ja.mo" );
touch_file( "$tmp/lang/plugins/vireo-analytics-ja.l10n.php" );
ok( 2 === count( Translations::merge() ), 'a pack shipped as .l10n.php only is still merged' );
unlink( "$tmp/lang/plugins/vireo-analytics-ja.l10n.php" );

// change_locale passes the new locale; a locale without files is a no-op.
Translations::reset();
$loaded = [];
touch_file( "$tmp/lang/plugins/vireo-analytics-nl_NL.mo" );
touch_file( "$tmp/plugin/languages/vireo-analytics-nl_NL.mo" );
ok( 2 === count( Translations::merge( 'nl_NL' ) ) && 'nl_NL' === $loaded[0][2], 'change_locale merges the switched-to locale' );
ok( [] === Translations::merge( 'fr_FR' ), 'a locale with neither file is a no-op' );
Translations::reset();
$loaded = [];
touch_file( "$tmp/lang/plugins/vireo-analytics-ja.mo" );
ok( 2 === count( Translations::merge( null ) ) && 'ja' === $loaded[0][2], 'a null locale from a hook is taken as the current one without a type error' );

// Cleanup.
foreach ( glob( "$tmp/lang/plugins/*" ) as $f ) {
	unlink( $f );
}
foreach ( glob( "$tmp/plugin/languages/*" ) as $f ) {
	unlink( $f );
}
rmdir( "$tmp/lang/plugins" );
rmdir( "$tmp/lang" );
rmdir( "$tmp/plugin/languages" );
rmdir( "$tmp/plugin" );
rmdir( $tmp );

echo "\n";
if ( $fail ) {
	echo "$fail test(s) failed.\n";
	exit( 1 );
}
echo "All tests passed.\n";
