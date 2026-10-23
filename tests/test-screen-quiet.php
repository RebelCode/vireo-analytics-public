<?php
/**
 * Zero-dependency assertion runner for Screen_Quiet.
 * Run: php tests/test-screen-quiet.php
 *
 * On Vireo's own screens, notices from other plugins and themes are removed
 * and Vireo's own and WordPress core's stay. Pro's count as Vireo's: before
 * 0.10.3 only files under VIREO_DIR did, so Pro's admin_notices callbacks
 * were stripped and its Freemius revenue set-up card never rendered on the
 * Settings page, the only screen it renders on.
 *
 * Files stand in for plugins: VIREO_DIR is includes/, VIREO_PRO_DIR a
 * scratch folder, WP_CONTENT_DIR the repo root (so this test file itself is
 * "another plugin"), and a file outside the repo is "core".
 */

// realpath: on macOS the temp dir is a symlink, and Reflection reports the
// resolved file path, so an unresolved prefix would never match.
$scratch = realpath( sys_get_temp_dir() ) . '/vireo-screen-quiet-' . getmypid();
mkdir( $scratch . '/wp-content/plugins/vireo-analytics-pro', 0700, true );
mkdir( $scratch . '/wp-content/plugins/other-plugin', 0700, true );
mkdir( $scratch . '/core', 0700, true );

// A wp-content stand-in: Pro and "another plugin" live under it, Vireo's own
// includes/ (this repo) and "core" do not.
define( 'ABSPATH', $scratch . '/core/' );
define( 'WP_CONTENT_DIR', $scratch . '/wp-content' );
define( 'VIREO_DIR', dirname( __DIR__ ) . '/includes/' );
define( 'VIREO_PRO_DIR', $scratch . '/wp-content/plugins/vireo-analytics-pro/' );

file_put_contents( VIREO_PRO_DIR . 'notice.php', '<?php function va_test_pro_notice() {} class VA_Test_Pro_Card { public static function render() {} }' );
file_put_contents( WP_CONTENT_DIR . '/plugins/other-plugin/notice.php', '<?php function va_test_other_notice() {}' );
file_put_contents( ABSPATH . 'core-notice.php', '<?php function va_test_core_notice() {}' );
require VIREO_PRO_DIR . 'notice.php';
require WP_CONTENT_DIR . '/plugins/other-plugin/notice.php';
require ABSPATH . 'core-notice.php';

$GLOBALS['va_test_removed'] = [];
$GLOBALS['va_test_screen']  = 'toplevel_page_vireo-analytics';

function wp_normalize_path( $p ) {
	return str_replace( '\\', '/', $p );
}
function add_action( ...$a ) {}
function remove_action( $hook, $cb, $priority = 10 ) {
	$GLOBALS['va_test_removed'][] = [ $hook, is_array( $cb ) ? $cb[1] : ( $cb instanceof Closure ? 'closure' : $cb ), $priority ];
	return true;
}
function get_current_screen() {
	return (object) [ 'id' => $GLOBALS['va_test_screen'] ];
}

require __DIR__ . '/../includes/class-screen-quiet.php';

use VireoAnalytics\Screen_Quiet;

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

/** Plant one set of callbacks on admin_notices and run the silencer. */
function run_quiet(): array {
	global $wp_filter;
	$wp_filter = [
		'admin_notices' => (object) [
			'callbacks' => [
				10 => [
					'pro_fn'    => [ 'function' => 'va_test_pro_notice' ],
					'pro_card'  => [ 'function' => [ 'VA_Test_Pro_Card', 'render' ] ],
					'core_fn'   => [ 'function' => 'va_test_core_notice' ],
					'other_fn'  => [ 'function' => 'va_test_other_notice' ], // another plugin under wp-content
					'vireo_fn'  => [ 'function' => [ Screen_Quiet::instance(), 'silence_foreign_notices' ] ], // declared under VIREO_DIR
				],
			],
		],
	];
	$GLOBALS['va_test_removed'] = [];
	Screen_Quiet::instance()->silence_foreign_notices();
	return array_map( static fn( $r ) => $r[1], $GLOBALS['va_test_removed'] );
}

$removed = run_quiet();
ok( in_array( 'va_test_other_notice', $removed, true ), 'a notice from another plugin (a file under wp-content, outside Vireo and Pro) is removed' );
ok( ! in_array( 'va_test_pro_notice', $removed, true ), 'a Pro function (declared under VIREO_PRO_DIR) stays' );
ok( ! in_array( 'render', $removed, true ), 'a Pro class method stays: this is the Freemius revenue card' );
ok( ! in_array( 'va_test_core_notice', $removed, true ), 'a core notice (declared outside wp-content) stays' );
ok( ! in_array( 'silence_foreign_notices', $removed, true ), 'Vireo\'s own callback stays' );
ok( 1 === count( $removed ), 'exactly one callback was removed' );

$GLOBALS['va_test_screen'] = 'edit-post';
ok( [] === run_quiet(), 'on a screen that is not Vireo\'s nothing is touched' );
$GLOBALS['va_test_screen'] = 'vireo-analytics_page_vireo-analytics-settings';
ok( [ 'va_test_other_notice' ] === run_quiet(), 'on the Settings screen (where the Freemius card renders) the same single foreign notice goes' );

// Control: the Pro exemption is the VIREO_PRO_DIR prefix with its trailing
// slash, nothing looser. A sibling folder whose name merely starts with the
// Pro folder's name does not match it.
mkdir( WP_CONTENT_DIR . '/plugins/vireo-analytics-pro-fake', 0700, true );
file_put_contents( WP_CONTENT_DIR . '/plugins/vireo-analytics-pro-fake/notice.php', '<?php function va_test_fake_pro_notice() {}' );
require WP_CONTENT_DIR . '/plugins/vireo-analytics-pro-fake/notice.php';
$fake = wp_normalize_path( ( new ReflectionFunction( 'va_test_fake_pro_notice' ) )->getFileName() );
ok( 0 !== strpos( $fake, wp_normalize_path( VIREO_PRO_DIR ) ), 'control: a look-alike sibling folder is not under the Pro prefix' );
ok( 0 === strpos( wp_normalize_path( ( new ReflectionFunction( 'va_test_pro_notice' ) )->getFileName() ), wp_normalize_path( VIREO_PRO_DIR ) ), 'control: the real Pro file is' );
global $wp_filter;
$wp_filter = [ 'admin_notices' => (object) [ 'callbacks' => [ 10 => [ 'fake' => [ 'function' => 'va_test_fake_pro_notice' ] ] ] ] ];
$GLOBALS['va_test_removed'] = [];
Screen_Quiet::instance()->silence_foreign_notices();
ok( 1 === count( $GLOBALS['va_test_removed'] ), 'control: and the look-alike, being another plugin under wp-content, is removed' );

// Clean up the scratch files.
foreach ( [ VIREO_PRO_DIR . 'notice.php', ABSPATH . 'core-notice.php', WP_CONTENT_DIR . '/plugins/other-plugin/notice.php', WP_CONTENT_DIR . '/plugins/vireo-analytics-pro-fake/notice.php' ] as $f ) {
	unlink( $f );
}
foreach ( [ VIREO_PRO_DIR, ABSPATH, WP_CONTENT_DIR . '/plugins/other-plugin', WP_CONTENT_DIR . '/plugins/vireo-analytics-pro-fake', WP_CONTENT_DIR . '/plugins', WP_CONTENT_DIR, $scratch ] as $d ) {
	rmdir( $d );
}

echo "\n";
if ( $fail ) {
	echo "$fail test(s) failed.\n";
	exit( 1 );
}
echo "All tests passed.\n";
