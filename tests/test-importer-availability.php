<?php
/**
 * Zero-dependency assertion runner for importer availability.
 * Run: php tests/test-importer-availability.php
 *
 * A plugin that has been deactivated and deleted often leaves its tables
 * behind, empty. An importer should only offer itself when there is
 * something to bring in, not merely when the table exists.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

/**
 * Just enough of wpdb to answer the queries an availability check makes:
 * SHOW TABLES LIKE, and a read from the table itself.
 */
final class Fake_Wpdb {
	public string $prefix = 'wp_';

	/** @var array<string,int> table => row count */
	public array $tables = [];

	public function prepare( string $query, ...$args ): string {
		$i = 0;
		return (string) preg_replace_callback(
			'/%[sdi]/',
			static function ( array $m ) use ( &$i, $args ): string {
				$v = (string) ( $args[ $i++ ] ?? '' );
				return '%i' === $m[0] ? '`' . $v . '`' : ( '%d' === $m[0] ? (string) (int) $v : "'" . $v . "'" );
			},
			$query
		);
	}

	public function get_var( string $query ) {
		if ( preg_match( "/^SHOW TABLES LIKE '([^']+)'$/", $query, $m ) ) {
			return isset( $this->tables[ $m[1] ] ) ? $m[1] : null;
		}
		if ( preg_match( '/FROM `([^`]+)`/', $query, $m ) ) {
			if ( ! isset( $this->tables[ $m[1] ] ) ) {
				return null;
			}
			if ( preg_match( '/^SELECT 1 FROM/', $query ) ) {
				return $this->tables[ $m[1] ] > 0 ? '1' : null;
			}
			return (string) $this->tables[ $m[1] ];
		}
		return null;
	}
}

$GLOBALS['wpdb'] = new Fake_Wpdb();

require __DIR__ . '/../includes/class-importer-base.php';
foreach ( [ 'burst', 'independent-analytics', 'koko', 'slimstat', 'statify', 'wp-statistics' ] as $va_slug ) {
	require __DIR__ . '/../importers/class-' . $va_slug . '-importer.php';
}

use VireoAnalytics\Importers;

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

// The table each importer reads to decide whether there is anything to import.
$cases = [
	'Burst'                 => [ new Importers\Burst_Importer(), 'wp_burst_statistics' ],
	'Independent Analytics' => [ new Importers\Independent_Analytics_Importer(), 'wp_independent_analytics_views' ],
	'Koko'                  => [ new Importers\Koko_Importer(), 'wp_koko_analytics_site_stats' ],
	'SlimStat'              => [ new Importers\SlimStat_Importer(), 'wp_slim_stats' ],
	'Statify'               => [ new Importers\Statify_Importer(), 'wp_statify' ],
	'WP Statistics'         => [ new Importers\WP_Statistics_Importer(), 'wp_statistics_pages' ],
];

foreach ( $cases as $name => [ $importer, $table ] ) {
	$GLOBALS['wpdb']->tables = [];
	ok( ! $importer->is_available(), "$name: no table, nothing to import" );

	$GLOBALS['wpdb']->tables = [ $table => 0 ];
	ok( ! $importer->is_available(), "$name: an empty table left behind is not data" );

	$GLOBALS['wpdb']->tables = [ $table => 12 ];
	ok( $importer->is_available(), "$name: a table with rows is offered" );
}

echo $fail ? "\n$fail failed\n" : "\nall passed\n";
exit( $fail ? 1 : 0 );
