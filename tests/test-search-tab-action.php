<?php
/**
 * The Search & AI tab fires vireo_overview_tab_search( $start, $end, $compare )
 * right after the AI visibility scan card, and only for this WordPress site.
 * Run: php tests/test-search-tab-action.php
 *
 * The template needs a whole admin page, so this runs the tab's closure as it
 * is written in the template and checks where it is called from.
 */

require __DIR__ . '/ai-scan-stubs.php';

$fired = [];
function do_action( $hook, ...$args ) {
	global $fired;
	$fired[] = [ $hook, $args ];
	echo '<p>note</p>';
}

$src = file_get_contents( __DIR__ . '/../templates/admin/dashboard-page.php' );

ok( 1 === preg_match( '/\$va_tab_action = static function.*?\n\t\t\};\n/s', $src, $m ), 'the tab closure is in the template' );
$closure = $m[0];

$start   = '2026-01-01';
$end     = '2026-01-31';
$compare = true;

$va_connected = false;
eval( $closure ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
$out = $va_tab_action( 'search' );
same( 1, count( $fired ), 'main site: the action fires once' );
same( 'vireo_overview_tab_search', $fired[0][0] ?? '', 'main site: the hook is vireo_overview_tab_search' );
same( [ $start, $end, $compare ], $fired[0][1] ?? [], 'main site: it passes start, end and compare' );
same( '<p>note</p>', $out, 'main site: what a callback prints comes back as the tab markup' );

$fired        = [];
$va_connected = true;
eval( $closure ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
$out = $va_tab_action( 'search' );
same( 0, count( $fired ), 'connected site: the action does not fire' );
same( '', $out, 'connected site: nothing is printed' );

$scan   = strpos( $src, "include VIREO_DIR . 'templates/dashboard/ai-scan.php';" );
$call   = strpos( $src, "echo \$va_tab_action( 'search' );" );
$define = strpos( $src, '$va_tab_action = static function' );
$guard  = strrpos( substr( $src, 0, $scan ), 'if ( ! $va_connected ) {' );
$close  = strpos( $src, "\n\t\t}\n", $scan );
ok( false !== $scan && false !== $call && $call > $scan, 'the call comes after the scan card' );
ok( $define < $scan, 'the closure is defined before the Search & AI panel uses it' );
ok( false !== $guard && $call < $close, 'the call sits inside the not-connected branch with the scan card' );
same( "\n\t\t\t", substr( $src, $scan + strlen( "include VIREO_DIR . 'templates/dashboard/ai-scan.php';" ), 4 ), 'nothing sits between the scan card and the call' );

finish();
