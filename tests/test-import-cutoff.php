<?php
/**
 * Zero-dependency check that an import stops where Vireo's own tracking
 * begins: which day counts as Vireo's own, and that the write helpers drop
 * anything from that day on.
 * Run: php tests/test-import-cutoff.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ) { return $s; }
}

require __DIR__ . '/../includes/class-sites.php';
require __DIR__ . '/../includes/class-import-history.php';
require __DIR__ . '/../includes/class-importer-base.php';

use VireoAnalytics\Import_History;
use VireoAnalytics\Importers\Importer_Base;

$fail = 0;
function ok( $cond, $msg ) {
	global $fail;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}

function days( string $from, int $n ): array {
	$out = [];
	for ( $i = 0; $i < $n; $i++ ) {
		$out[] = gmdate( 'Y-m-d', strtotime( "$from +$i days" ) );
	}
	return $out;
}

function run( array $fields ): array {
	return $fields + [ 'source' => 'koko', 'start' => '', 'end' => '', 'first' => '', 'last' => '', 'days' => 0, 'pageviews' => 0, 'at' => 0, 'user' => 0, 'own_from' => '' ];
}

// Nothing stored: no own data, nothing to stop at.
ok( null === Import_History::first_own_day( [], [] ), 'no stored days means no own data' );

// Only Vireo's own tracking: the first stored day.
ok( '2026-09-01' === Import_History::first_own_day( days( '2026-09-01', 10 ), [] ), 'with no imports, own data starts on the first stored day' );

// 50 imported days then 10 tracked: the import's written range is skipped.
$stored = array_merge( days( '2026-07-13', 50 ), days( '2026-09-01', 10 ) );
$runs   = [ run( [ 'first' => '2026-07-13', 'last' => '2026-08-31' ] ) ];
ok( '2026-09-01' === Import_History::first_own_day( $stored, $runs ), 'days written by an import are not own data (got ' . var_export( Import_History::first_own_day( $stored, $runs ), true ) . ')' );

// Unsorted input gives the same answer.
ok( '2026-09-01' === Import_History::first_own_day( array_reverse( $stored ), $runs ), 'order of stored days does not matter' );

// A run recorded before `first`/`last` existed falls back to the range it asked for.
$runs = [ run( [ 'source' => 'ga4', 'start' => '2026-07-13', 'end' => '2026-08-31' ] ) ];
ok( '2026-09-01' === Import_History::first_own_day( $stored, $runs ), 'an older run is placed by its requested range' );

// A run with no range at all cannot be placed, so it covers nothing: the
// answer errs towards stopping early, never towards counting a day twice.
$runs = [ run( [] ) ];
ok( '2026-07-13' === Import_History::first_own_day( $stored, $runs ), 'a run with no recorded range covers nothing' );

// Every stored day came from imports: no own data.
$runs = [ run( [ 'first' => '2026-07-13', 'last' => '2026-09-10' ] ) ];
ok( null === Import_History::first_own_day( $stored, $runs ), 'when imports cover every stored day there is no own data' );

// A run that chose to include Vireo's own days wrote across them, but they
// are still Vireo's own: the run remembers where they began.
$runs = [ run( [ 'first' => '2026-07-13', 'last' => '2026-09-10', 'own_from' => '2026-09-01' ] ) ];
ok( '2026-09-01' === Import_History::first_own_day( $stored, $runs ), 'an import that included own days does not hide them from the next one (got ' . var_export( Import_History::first_own_day( $stored, $runs ), true ) . ')' );

// The last day an import may write.
ok( '2026-08-31' === Import_History::day_before( '2026-09-01' ), 'the day before 2026-09-01 is 2026-08-31' );
ok( '2024-02-29' === Import_History::day_before( '2024-03-01' ), 'the day before 2024-03-01 is 2024-02-29' );

// The write helpers honour the cutoff.
final class Fake_Wpdb {
	public string $prefix     = 'wp_';
	public string $last_error = '';
	public array $writes      = [];
	private array $args       = [];

	public function prepare( $sql, ...$args ) {
		$this->args = $args;
		return $sql;
	}

	public function query( $sql ) {
		$this->writes[] = $this->args;
		return 1;
	}
}

final class Probe_Importer extends Importer_Base {
	public function slug(): string { return 'probe'; }
	public function name(): string { return 'Probe'; }
	public function is_available(): bool { return true; }
	public function count_records(): int { return 0; }
	public function import_chunk( array $params, int $offset, int $limit ): array { return []; }

	public function site( string $date ): void {
		$this->upsert_site_stats( $date, 1, 2 );
	}

	public function geo( string $date ): void {
		$this->upsert_geo_stats( $date, 'PT', 1, 2 );
	}

	public function device( string $date ): void {
		$this->upsert_device_stats( $date, 'Chrome', 'macOS', 'Desktop', 1, 2 );
	}

	public function kept( array $rows ): array {
		return $this->before_cutoff( $rows );
	}
}

$wpdb = new Fake_Wpdb();
$imp  = new Probe_Importer();

foreach ( days( '2026-08-30', 4 ) as $d ) {
	$imp->site( $d );
}
ok( 4 === count( $wpdb->writes ), 'with no cutoff every day is written (' . count( $wpdb->writes ) . ' of 4)' );

$wpdb->writes = [];
$imp          = new Probe_Importer();
$imp->set_cutoff( '2026-09-01' );
foreach ( days( '2026-08-30', 4 ) as $d ) {
	$imp->site( $d );
	$imp->geo( $d );
	$imp->device( $d );
}
$dates = array_unique( array_column( $wpdb->writes, 1 ) );
sort( $dates );
ok( 6 === count( $wpdb->writes ), 'site, geo and device writes stop at the cutoff (' . count( $wpdb->writes ) . ' of 6 expected)' );
ok( [ '2026-08-30', '2026-08-31' ] === array_values( $dates ), 'only days before the cutoff are written (got ' . implode( ',', $dates ) . ')' );
ok( 2 === $imp->written()['days'] && '2026-08-31' === $imp->written()['last'], 'the run reports what it actually wrote (' . $imp->written()['days'] . ' days, last ' . $imp->written()['last'] . ')' );

$rows = [
	[ 'date' => '2026-08-31', 'path' => '/a' ],
	[ 'date' => '2026-09-01', 'path' => '/a' ],
	[ 'date' => '2026-09-02', 'path' => '/b' ],
];
ok( [ $rows[0] ] === $imp->kept( $rows ), 'bulk rows on or after the cutoff are dropped' );

// Run tokens keep two runs, or a stale tab, from sharing per-run state.
ok( '1791300000-ab12cd34' === Importer_Base::valid_run( '1791300000-ab12cd34' ), 'a well-formed run token is accepted' );
foreach ( [ '', 'koko', '1791300000', '1791300000-AB12CD34', '1791300000-ab12cd34x', '../1791300000-ab12cd34', '1791300000-ab12cd3' ] as $bad ) {
	ok( '' === Importer_Base::valid_run( $bad ), 'a malformed run token is refused: ' . var_export( $bad, true ) );
}
$a = Importer_Base::run_option( 'vireo_import_done_', 'koko', '1791300000-aaaaaaaa' );
$b = Importer_Base::run_option( 'vireo_import_done_', 'koko', '1791300000-bbbbbbbb' );
ok( $a !== $b && 0 === strpos( $a, 'vireo_import_done_koko_' ), 'two runs of one importer get separate state (' . $a . ', ' . $b . ')' );
ok( 1791300000 === Importer_Base::run_started( '1791300000-aaaaaaaa' ) && 0 === Importer_Base::run_started( 'nope' ), 'a run token says when the run started' );
ok( strlen( Importer_Base::run_option( 'vireo_import_cutoff_', 'independent-analytics', '1791300000-aaaaaaaa' ) ) <= 191, 'the longest run option name fits wp_options' );

echo $fail ? "\n$fail failed\n" : "\nall passed\n";
exit( $fail ? 1 : 0 );
