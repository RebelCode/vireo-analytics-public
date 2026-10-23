<?php
/**
 * Zero-dependency assertion runner for change chips.
 * Run: php tests/test-delta-chip.php
 *
 * A change that rounds to nothing must not be drawn as a fall. A page down 3
 * views out of 1,400 rounds to 0%, and used to render as a red "0%" with a
 * down arrow; one up 3 views did the same, because "up" was read off the
 * rounded figure while the chip still drew.
 */

if ( ! function_exists( '__' ) ) {
	function __( $s, $d = null ) { return $s; }
}
if ( ! function_exists( '_n' ) ) {
	function _n( $single, $plural, $n, $d = null ) { return 1 === (int) $n ? $single : $plural; }
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $s, $d = null ) { return $s; }
}
if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( $s, $d = null ) { return $s; }
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
}
if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, $d ); }
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) { return $value; }
}
if ( ! function_exists( 'wp_timezone' ) ) {
	function wp_timezone() { return new DateTimeZone( 'UTC' ); }
}
if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( $format, $ts ) { return gmdate( $format, $ts ); }
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

require __DIR__ . '/../includes/va-tz.php';
require __DIR__ . '/../includes/class-drivers.php';
require __DIR__ . '/../includes/va-filters.php';
require __DIR__ . '/../includes/class-filters.php';
require __DIR__ . '/../includes/class-ai-sources.php';
require __DIR__ . '/../includes/class-dashboard.php';
require __DIR__ . '/../includes/class-dashboard-widget.php';

use VireoAnalytics\Dashboard;
use VireoAnalytics\Dashboard_Widget;

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

// Overview chips.
ok( '' === Dashboard::delta_chip( -3, 1400 ), 'down 3 of 1,400 rounds to 0%: no chip' );
ok( '' === Dashboard::delta_chip( 3, 1400 ), 'up 3 of 1,400 rounds to 0%: no chip' );
ok( '' === Dashboard::delta_chip( -3, 1400, 'up_bad' ), 'a 404 count flat to the percent: no chip' );

// The arrow is the sign. A chip that printed "↑+17%" said it twice, and
// "↓-17%" read as a double negative, so the visible figure is unsigned and
// the signed change lives in the title.
$visible = static function ( string $chip ): string {
	return preg_match( '~<span>([^<]*)</span></span>$~', $chip, $m ) ? $m[1] : '';
};
$down = Dashboard::delta_chip( -140, 1400 );
ok( false !== strpos( $down, 'va-chip-down' ) && '10%' === $visible( $down ), 'a real fall is still red, and reads 10% under a down arrow: ' . $visible( $down ) );
ok( false !== strpos( $down, 'title="-140 vs 1,400' ), 'the title keeps the signed change' );
$up = Dashboard::delta_chip( 140, 1400 );
ok( false !== strpos( $up, 'va-chip-up' ) && '10%' === $visible( $up ), 'a real rise is still green, and reads 10% under an up arrow: ' . $visible( $up ) );
ok( false !== strpos( $up, 'title="+140 vs 1,400' ), 'the title keeps the plus' );
ok( false === strpos( $visible( $up ), '+' ) && false === strpos( $visible( $down ), '-' ), 'no sign is printed next to the arrow, either way' );
// The arrow has to survive wp_kses_post(), which most templates pass the chip
// through and which strips <svg>. A text glyph in a span does.
ok( false !== strpos( $up, 'va-arrow-up' ) && false !== strpos( $down, 'va-arrow-down' ), 'each chip carries an arrow glyph for its direction' );
ok( false === strpos( $up, '<svg' ) && false === strpos( $down, '<svg' ), 'and no <svg>, which wp_kses_post() would strip' );
ok( 0 === strpos( Dashboard::arrow_svg( 'up' ), '<span class="va-arrow va-arrow-up" aria-hidden="true">' ), 'the arrow is an aria-hidden span' );
$count = Dashboard::delta_chip( 25 );
ok( '25' === $visible( $count ) && false !== strpos( $count, 'va-chip-up' ), 'a raw count with no baseline is unsigned too: ' . $visible( $count ) );
$pp = Dashboard::delta_chip_pp( 2.5, 40 );
ok( '2.5 pp' === $visible( $pp ) && false !== strpos( $pp, 'va-chip-up' ), 'the share chip drops its plus as well: ' . $visible( $pp ) );
$ppd = Dashboard::delta_chip_pp( -2.5, -40 );
ok( '2.5 pp' === $visible( $ppd ) && false !== strpos( $ppd, 'va-chip-down' ), 'and its minus: ' . $visible( $ppd ) );
$bad = Dashboard::delta_chip( 140, 1400, 'up_bad' );
ok( false !== strpos( $bad, 'va-chip-down' ), 'more 404s are still red' );
ok( '' === Dashboard::delta_chip( 0, 1400 ), 'no change: no chip' );

// The admin-dashboard widget, which shows one decimal.
$widget = ( new ReflectionClass( Dashboard_Widget::class ) )->newInstanceWithoutConstructor();
$render = new ReflectionMethod( Dashboard_Widget::class, 'render_delta' );
if ( PHP_VERSION_ID < 80100 ) {
	$render->setAccessible( true );
}
$draw = static function ( int $now, int $prior ) use ( $widget, $render ): string {
	ob_start();
	$render->invoke( $widget, $now, $prior );
	return (string) ob_get_clean();
};

$flat = $draw( 9999, 10000 );
ok( false !== strpos( $flat, 'va-dw-flat' ) && false === strpos( $flat, '-0.0' ), 'widget: down 0.01% reads as flat, not "-0.0%" in red' );
$fell = $draw( 9000, 10000 );
ok( false !== strpos( $fell, 'va-dw-down' ) && false !== strpos( $fell, '-10.0%' ), 'widget: a real fall is still red' );

// Nothing to compare with draws nothing, not an empty grey "—" pill.
ok( '' === $draw( 9, 0 ), 'widget: no prior figure draws no badge at all' );

// A comparison reaching back before tracking began, into imported history.
$note = Dashboard_Widget::untracked_note( '2026-10-21', '2026-08-23', 7208 );
ok( 'Recording since Oct 21' === $note, 'widget: a prior window before tracking gets the neutral note: ' . $note );
ok( '' === Dashboard_Widget::untracked_note( '2026-10-21', '2026-10-21', 500 ), 'widget: a prior window inside tracking compares as usual' );
ok( '' === Dashboard_Widget::untracked_note( '2026-10-21', '2026-08-23', 0 ), 'widget: nothing imported, nothing to misread' );
ok( '' === Dashboard_Widget::untracked_note( '', '2026-08-23', 7208 ), 'widget: unknown tracking start changes nothing' );

echo $fail ? "\n$fail failed\n" : "\nall passed\n";
exit( $fail ? 1 : 0 );
