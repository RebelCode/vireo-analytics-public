<?php
/**
 * Zero-dependency check that the Koko importer claims nothing it can't read.
 * It used to offer "Koko Analytics Plus" countries and devices from wp_kap_*
 * tables that no Koko build creates, so the option never worked on a real site.
 * Run: php tests/test-koko-import.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

require __DIR__ . '/../includes/class-importer-base.php';
require __DIR__ . '/../importers/class-koko-importer.php';

use VireoAnalytics\Importers\Koko_Importer;

$fail = 0;
function ok( $cond, $msg ) {
	global $fail;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}

$importer   = (string) file_get_contents( __DIR__ . '/../importers/class-koko-importer.php' );
$template   = (string) file_get_contents( __DIR__ . '/../templates/admin/import-koko.php' );
$controller = (string) file_get_contents( __DIR__ . '/../includes/class-import-controller.php' );

ok( '' !== $importer && '' !== $template && '' !== $controller, 'the files under test were read' );
ok( ! method_exists( Koko_Importer::class, 'has_plus_data' ), 'the importer no longer looks for Plus data' );
ok( 0 === preg_match( "/'kap_/", $importer ), 'the importer reads no kap_* table' );
ok( false === strpos( $template, 'include_plus' ) && false === stripos( $template, 'Koko Analytics Plus' ), 'the Koko screen offers no Plus import' );
ok( false === strpos( $controller, 'has_plus_data' ), 'the controller does not ask for Plus data' );

echo $fail ? "\n$fail failed\n" : "\nall passed\n";
exit( $fail ? 1 : 0 );
