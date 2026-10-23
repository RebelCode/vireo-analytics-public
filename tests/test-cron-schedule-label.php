<?php
/**
 * Zero-dependency check of the cron schedule label's timing.
 * Run: php tests/test-cron-schedule-label.php
 *
 * WooCommerce's bundled Jetpack Connection calls wp_schedule_event() during
 * plugins_loaded, which runs our cron_schedules filter before
 * after_setup_theme. A __() there makes WordPress log a "translation loading
 * triggered too early" notice. The filter must not translate until the theme
 * is set up, and must translate once it is.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$GLOBALS['va_filters']   = [];
$GLOBALS['va_translated'] = [];
$GLOBALS['va_did']        = [];

function add_filter( $hook, $cb ) { $GLOBALS['va_filters'][ $hook ][] = $cb; return true; }
function add_action( $hook, $cb ) { return true; }
function did_action( $hook ) { return (int) ( $GLOBALS['va_did'][ $hook ] ?? 0 ); }
function __( $s, $d = null ) { $GLOBALS['va_translated'][] = $s; return '[' . $s . ']'; }
function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function plugin_dir_url( $f ) { return 'https://example.test/wp-content/plugins/vireo-analytics/'; }
function plugin_basename( $f ) { return 'vireo-analytics/vireo-analytics.php'; }
function register_activation_hook() {}
function register_deactivation_hook() {}

require __DIR__ . '/../vireo-analytics.php';

$fail = 0;
function ok( $cond, $msg ) {
	global $fail;
	echo ( $cond ? 'PASS: ' : 'FAIL: ' ) . $msg . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}

$filter = $GLOBALS['va_filters']['cron_schedules'][0] ?? null;
ok( is_callable( $filter ), 'the cron_schedules filter is registered at file load' );

// Before after_setup_theme, as when WooCommerce schedules an event on plugins_loaded.
$GLOBALS['va_did']        = [];
$GLOBALS['va_translated'] = [];
$out = $filter( [] );
ok( 60 === ( $out['vireo_every_minute']['interval'] ?? 0 ), 'the schedule is registered either way' );
ok( 'Every minute (Vireo Analytics)' === ( $out['vireo_every_minute']['display'] ?? '' ), 'before after_setup_theme the label is the plain string' );
ok( [] === $GLOBALS['va_translated'], 'and __() is not called, so no text domain loads early' );

// After after_setup_theme, as on a normal admin request.
$GLOBALS['va_did']        = [ 'after_setup_theme' => 1 ];
$GLOBALS['va_translated'] = [];
$out = $filter( [] );
ok( '[Every minute (Vireo Analytics)]' === ( $out['vireo_every_minute']['display'] ?? '' ), 'after after_setup_theme the label is translated' );
ok( [ 'Every minute (Vireo Analytics)' ] === $GLOBALS['va_translated'], 'through __() with the plugin\'s string' );

ok( isset( $filter( [ 'vireo_every_minute' => [ 'interval' => 1 ] ] )['vireo_every_minute'] ) && 1 === $filter( [ 'vireo_every_minute' => [ 'interval' => 1 ] ] )['vireo_every_minute']['interval'], 'an existing schedule of the same name is left alone' );

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
