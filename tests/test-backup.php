<?php
/**
 * Zero-dependency check of the backup file and its restore: what the export
 * writes, that a restore replaces rows rather than adding to them (so the same
 * file twice gives the same numbers), what "replace" deletes first, and which
 * files are refused.
 * Run: php tests/test-backup.php
 *
 * Control: swap the importer's `col = VALUES(col)` for the additive
 * `col = col + VALUES(col)` the other importers use and the idempotency check
 * fails on the first INSERT.
 */

namespace VireoAnalytics {
	// Table names without the real Sites class and its option reads.
	final class Sites {
		public const MAIN = 0;
		public static function table( string $name, ?int $site_id = null ): string {
			return 'wp_va_' . $name;
		}
	}
}

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ );
	}
	define( 'VIREO_VERSION', '0.14.0-test' );
	define( 'ARRAY_A', 'ARRAY_A' );
	define( 'ARRAY_N', 'ARRAY_N' );
	define( 'HOUR_IN_SECONDS', 3600 );

	$va_tmp = sys_get_temp_dir() . '/vireo-backup-test-' . bin2hex( random_bytes( 4 ) );
	mkdir( $va_tmp, 0777, true );

	$GLOBALS['va_options'] = [ 'vireo_db_version' => '12' ];

	function get_option( $key, $default = false ) {
		return $GLOBALS['va_options'][ $key ] ?? $default;
	}
	function update_option( $key, $value, $autoload = null ) {
		$GLOBALS['va_options'][ $key ] = $value;
		return true;
	}
	function delete_option( $key ) {
		unset( $GLOBALS['va_options'][ $key ] );
		return true;
	}
	function home_url( $path = '' ) {
		return 'https://old.example.com' . $path;
	}
	function wp_json_encode( $data, $flags = 0 ) {
		return json_encode( $data, $flags );
	}
	function sanitize_file_name( $s ) {
		return preg_replace( '/[^A-Za-z0-9.\-_]/', '-', (string) $s );
	}
	function sanitize_text_field( $s ) {
		return trim( (string) preg_replace( '/[\x00-\x1F\x7F]/', '', strip_tags( (string) $s ) ) );
	}
	function wp_delete_file( $path ) {
		if ( is_file( $path ) ) {
			unlink( $path );
		}
	}
	function wp_mkdir_p( $dir ) {
		return is_dir( $dir ) || mkdir( $dir, 0777, true );
	}
	function vireo_data_dir() {
		return $GLOBALS['va_tmp'] . '/data';
	}
	function size_format( $bytes ) {
		return $bytes . ' B';
	}
	function wp_max_upload_size() {
		return 1048576;
	}
	function get_current_user_id() {
		return 1;
	}
	function __( $s, $d = null ) {
		return $s;
	}
	function _n( $s, $p, $n, $d = null ) {
		return 1 === (int) $n ? $s : $p;
	}
	function esc_html( $s ) {
		return $s;
	}
	function add_action( ...$a ) {}
	function is_wp_error( $x ) {
		return $x instanceof WP_Error;
	}
	final class WP_Error {
		public string $code;
		public string $message;
		public function __construct( $code = '', $message = '' ) {
			$this->code    = (string) $code;
			$this->message = (string) $message;
		}
		public function get_error_code() {
			return $this->code;
		}
		public function get_error_message() {
			return $this->message;
		}
	}

	/**
	 * Enough of wpdb for the export reads and the import writes. Every
	 * statement goes through prepare(), so what is recorded is the final SQL.
	 */
	final class Fake_Wpdb {
		public string $prefix     = 'wp_';
		public string $options    = 'wp_options';
		public string $last_error = '';
		/** @var string[] tables that exist */
		public array $tables = [];
		/** @var array<string, array<int, array<int, mixed>>> export rows per table */
		public array $rows = [];
		/** @var string[] every write */
		public array $queries = [];
		private array $ids    = [];

		public function prepare( $query, ...$args ) {
			if ( 1 === count( $args ) && is_array( $args[0] ) ) {
				$args = $args[0];
			}
			$i = 0;
			return (string) preg_replace_callback(
				'/%[sdi]/',
				static function ( array $m ) use ( &$i, $args ): string {
					$v = $args[ $i++ ] ?? '';
					if ( '%i' === $m[0] ) {
						return '`' . $v . '`';
					}
					return '%d' === $m[0] ? (string) (int) $v : "'" . str_replace( "'", "''", (string) $v ) . "'";
				},
				$query
			);
		}
		public function get_var( $sql ) {
			if ( preg_match( "/^SHOW TABLES LIKE '([^']+)'$/", $sql, $m ) ) {
				return in_array( $m[1], $this->tables, true ) ? $m[1] : null;
			}
			return null;
		}
		public function get_row( $sql, $output = null ) {
			if ( false !== strpos( $sql, 'MIN(date)' ) ) {
				return [ 'first' => '2026-01-01', 'last' => '2026-01-02' ];
			}
			return null;
		}
		public function get_col( $sql ) {
			return [];
		}
		public function get_results( $sql, $output = null ) {
			// The dictionary lookup after an INSERT IGNORE: a stable id per value.
			if ( preg_match( '/^SELECT `(\w+)` AS v, id FROM `(\w+)` WHERE `\w+` IN \((.*)\)$/s', $sql, $m ) ) {
				preg_match_all( "/'((?:[^']|'')*)'/", $m[3], $vals );
				$out = [];
				foreach ( $vals[1] as $v ) {
					$v = str_replace( "''", "'", $v );
					if ( ! isset( $this->ids[ $m[2] ][ $v ] ) ) {
						$this->ids[ $m[2] ][ $v ] = 100 + count( $this->ids[ $m[2] ] ?? [] );
					}
					$out[] = [ 'v' => $v, 'id' => $this->ids[ $m[2] ][ $v ] ];
				}
				return $out;
			}
			if ( false !== strpos( $sql, 'SELECT date, label FROM' ) ) {
				return [];
			}
			// An export page read.
			if ( preg_match( '/FROM `(wp_va_\w+)` s .*OFFSET (\d+)$/s', $sql, $m ) ) {
				return 0 === (int) $m[2] ? ( $this->rows[ $m[1] ] ?? [] ) : [];
			}
			return [];
		}
		public function query( $sql ) {
			$this->queries[] = $sql;
			return 1;
		}
		public function esc_like( $s ) {
			return $s;
		}
		public function writes( string $kind = '' ): array {
			return array_values( array_filter( $this->queries, static fn( string $q ): bool => '' === $kind || 0 === strpos( $q, $kind ) ) );
		}
	}

	require __DIR__ . '/../includes/class-collector.php';
	require __DIR__ . '/../includes/class-upserter.php';
	require __DIR__ . '/../includes/class-importer-base.php';
	require __DIR__ . '/../includes/class-backup.php';
	require __DIR__ . '/../importers/class-backup-importer.php';

	use VireoAnalytics\Backup;
	use VireoAnalytics\Importers\Backup_Importer;

	$fail = 0;
	function ok( $cond, $msg ) {
		global $fail;
		echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n";
		if ( ! $cond ) {
			$fail++;
		}
	}

	// ---- export ---------------------------------------------------------------

	$all_tables = array_keys( Backup::tables() );
	$wpdb       = new Fake_Wpdb();
	// Every table but gsc_pages exists, as on an install that never connected Search Console's page report.
	$wpdb->tables = array_map( static fn( string $t ): string => 'wp_va_' . $t, array_diff( $all_tables, [ 'gsc_pages' ] ) );
	$wpdb->rows   = [
		'wp_va_site_stats' => [ [ '2026-01-01', '120', '80' ], [ '2026-01-02', '95', '60' ] ],
		'wp_va_post_stats' => [ [ '2026-01-01', '7', '/hello-world/', '40', '30' ], [ '2026-01-01', '0', '/café/', '3', '3' ] ],
		'wp_va_404_stats'  => [ [ '2026-01-02', '/old-page/', 'https://ref.example', '/about/', '4' ] ],
	];

	$lines = [];
	Backup::write_all( static function ( string $line ) use ( &$lines ): void {
		$lines[] = $line;
	} );

	ok( 5 === count( $lines ), 'export: header, three data lines, end (' . count( $lines ) . ' lines)' );
	$header = json_decode( $lines[0], true );
	ok( Backup::FORMAT === ( $header['format'] ?? '' ) && Backup::VERSION === ( $header['version'] ?? 0 ), 'export: header names the format and version' );
	ok( 'https://old.example.com' === ( $header['site'] ?? '' ) && '2026-01-01' === ( $header['from'] ?? '' ) && '2026-01-02' === ( $header['to'] ?? '' ), 'export: header carries the site and the date span' );
	ok( ! in_array( 'gsc_pages', (array) ( $header['tables'] ?? [] ), true ) && in_array( 'site_stats', (array) ( $header['tables'] ?? [] ), true ), 'export: header lists the tables present, not the missing one' );

	$site_line = json_decode( $lines[1], true );
	ok( 'site_stats' === ( $site_line['t'] ?? '' ) && [ 'date', 'pageviews', 'visitors' ] === ( $site_line['c'] ?? [] ), 'export: a data line names its table and columns' );
	ok( [ '2026-01-01', 120, 80 ] === ( $site_line['r'][0] ?? null ), 'export: counts are written as numbers, dates as strings' );

	$post_line = json_decode( $lines[2], true );
	ok( '/hello-world/' === ( $post_line['r'][0][2] ?? '' ), 'export: a path is written as text, not a path_id' );
	ok( false !== strpos( $lines[2], '/café/' ), 'export: unicode is written as is, not escaped' );
	ok( [ 'end' => true, 'rows' => 5 ] === json_decode( $lines[4], true ), 'export: the end line counts the rows' );
	ok( 1 === preg_match( '/^vireo-analytics-backup-old\.example\.com-2026-10-14\.jsonl\.gz$/', Backup::filename( 'old.example.com', '2026-10-14', true ) ), 'export: the file is named after the host and day' );

	$file = implode( '', $lines );
	file_put_contents( $va_tmp . '/backup.jsonl', $file );
	file_put_contents( $va_tmp . '/backup.jsonl.gz', gzencode( $file ) );

	// ---- inspect -----------------------------------------------------------------

	$seen = Backup_Importer::inspect( $va_tmp . '/backup.jsonl' );
	ok( is_array( $seen ) && 3 === $seen['lines'] && 5 === $seen['rows'] && 'https://old.example.com' === $seen['site'], 'inspect: counts the lines and rows of a plain file' );
	$seen_gz = Backup_Importer::inspect( $va_tmp . '/backup.jsonl.gz' );
	ok( is_array( $seen_gz ) && 3 === $seen_gz['lines'] && 5 === $seen_gz['rows'], 'inspect: reads the gzipped file the same way' );

	file_put_contents( $va_tmp . '/csv.jsonl', "date,pageviews\n2026-01-01,5\n" );
	$r = Backup_Importer::inspect( $va_tmp . '/csv.jsonl' );
	ok( is_wp_error( $r ) && 'vireo_backup_format' === $r->get_error_code(), 'inspect: a file that is not a backup is refused' );

	file_put_contents( $va_tmp . '/newer.jsonl', json_encode( [ 'format' => Backup::FORMAT, 'version' => 99 ] ) . "\n" . $lines[4] );
	$r = Backup_Importer::inspect( $va_tmp . '/newer.jsonl' );
	ok( is_wp_error( $r ) && 'vireo_backup_version' === $r->get_error_code(), 'inspect: a file from a newer version is refused' );

	file_put_contents( $va_tmp . '/cut.jsonl', $lines[0] . $lines[1] );
	$r = Backup_Importer::inspect( $va_tmp . '/cut.jsonl' );
	ok( is_wp_error( $r ) && 'vireo_backup_truncated' === $r->get_error_code(), 'inspect: a file without the end line is refused' );

	file_put_contents( $va_tmp . '/damaged.jsonl', $lines[0] . "{not json\n" . $lines[4] );
	$r = Backup_Importer::inspect( $va_tmp . '/damaged.jsonl' );
	ok( is_wp_error( $r ) && 'vireo_backup_line' === $r->get_error_code(), 'inspect: a damaged line is refused' );

	// ---- import: merge, twice ------------------------------------------------

	function stage_and_run( Fake_Wpdb $db, string $run, string $path, string $mode ): array {
		$GLOBALS['wpdb'] = $db;
		$importer        = new Backup_Importer();
		$importer->set_run( $run );
		$staged = $importer->stage_upload( [ 'name' => basename( $path ), 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => filesize( $path ) ], $mode );
		if ( is_wp_error( $staged ) ) {
			return [ 'error' => $staged->get_error_message() ];
		}
		$results = [];
		$offset  = 0;
		for ( $i = 0; $i < 10; $i++ ) {
			$res       = $importer->import_chunk( [], $offset, 100 );
			$results[] = $res;
			if ( ! empty( $res['done'] ) ) {
				break;
			}
			$offset = (int) $res['next_offset'];
		}
		return [ 'results' => $results, 'importer' => $importer, 'staged' => $staged ];
	}

	// stage_upload copies the upload, so hand it a copy it may delete.
	copy( $va_tmp . '/backup.jsonl.gz', $va_tmp . '/upload1.gz' );
	$db1 = new Fake_Wpdb();
	$db1->tables = $wpdb->tables;
	$run1        = stage_and_run( $db1, '1700000000-aaaaaaaa', $va_tmp . '/upload1.gz', 'merge' );
	ok( isset( $run1['results'] ) && ! empty( $run1['results'][0]['done'] ) && 1 === count( $run1['results'] ), 'merge: three lines finish in one chunk' );
	ok( ! isset( $GLOBALS['va_options']['vireo_backup_import_params_1700000000-aaaaaaaa'] ), 'merge: the staged params are cleared when done' );
	ok( [] === glob( vireo_data_dir() . '/backup-import-*' ), 'merge: the staged copy of the upload is deleted when done' );
	ok( [] === $db1->writes( 'DELETE' ), 'merge: nothing is deleted first' );

	$inserts = $db1->writes( 'INSERT INTO' );
	ok( 3 === count( $inserts ), 'merge: one INSERT per data line (' . count( $inserts ) . ')' );
	$site_sql = $inserts[0];
	ok( false !== strpos( $site_sql, 'INSERT INTO `wp_va_site_stats` (date, pageviews, visitors)' ), 'merge: site_stats rows go into site_stats' );
	ok( false !== strpos( $site_sql, "('2026-01-01',120,80),('2026-01-02',95,60)" ), 'merge: the file\'s values are written' );
	ok( false !== strpos( $site_sql, 'ON DUPLICATE KEY UPDATE pageviews = VALUES(pageviews), visitors = VALUES(visitors)' ), 'merge: an existing day is replaced, not added to' );
	ok( false === strpos( $site_sql, '+ VALUES' ), 'merge: no additive update anywhere in the statement' );

	$post_sql = $inserts[1];
	ok( false !== strpos( $post_sql, 'INSERT INTO `wp_va_post_stats` (date, post_id, path_id, pageviews, visitors)' ), 'merge: post_stats rows store a path_id' );
	ok( 1 === preg_match( "/\('2026-01-01',7,1\d\d,40,30\)/", $post_sql ), 'merge: the path text was resolved to an id through the dictionary' );
	ok( 1 === count( $db1->writes( 'INSERT IGNORE INTO `wp_va_paths`' ) ) && false !== strpos( $db1->writes( 'INSERT IGNORE' )[0], "'/café/'" ), 'merge: the paths dictionary is filled first, unicode intact' );

	$nf_sql = $inserts[2];
	$hash   = md5( "/old-page/\0https://ref.example\0/about/" );
	ok( false !== strpos( $nf_sql, "'$hash'" ), 'merge: a 404 row is keyed by the same hash the 404 tracker uses' );
	ok( false !== strpos( $nf_sql, 'ON DUPLICATE KEY UPDATE path = VALUES(path), referrer = VALUES(referrer), internal_ref = VALUES(internal_ref), hits = VALUES(hits)' ), 'merge: 404 counts are replaced too' );

	ok( [ 'first' => '2026-01-01', 'last' => '2026-01-02', 'days' => 2, 'pageviews' => 215 ] === $run1['importer']->written(), 'merge: the run reports what it wrote for the history' );

	// The same file again is the same statements.
	copy( $va_tmp . '/backup.jsonl.gz', $va_tmp . '/upload2.gz' );
	$db2 = new Fake_Wpdb();
	$db2->tables = $wpdb->tables;
	stage_and_run( $db2, '1700000001-bbbbbbbb', $va_tmp . '/upload2.gz', 'merge' );
	ok( $db1->queries === $db2->queries, 'idempotent: loading the same file twice issues identical statements' );

	// ---- import: replace ----------------------------------------------------------

	copy( $va_tmp . '/backup.jsonl', $va_tmp . '/upload3.jsonl' );
	$db3 = new Fake_Wpdb();
	$db3->tables = $wpdb->tables;
	$run3        = stage_and_run( $db3, '1700000002-cccccccc', $va_tmp . '/upload3.jsonl', 'replace' );
	$deletes     = $db3->writes( 'DELETE FROM' );
	ok( count( $wpdb->tables ) === count( $deletes ), 'replace: every present table is emptied (' . count( $deletes ) . ' of ' . count( $wpdb->tables ) . ')' );
	ok( ! in_array( 'DELETE FROM `wp_va_gsc_pages`', $deletes, true ), 'replace: a table that does not exist here is left alone' );
	ok( ! in_array( 'DELETE FROM `wp_va_paths`', $deletes, true ) && ! in_array( 'DELETE FROM `wp_va_visitor_seen`', $deletes, true ), 'replace: dictionaries and working state are not touched' );
	$first_insert = array_search( $db3->writes( 'INSERT INTO' )[0], $db3->queries, true );
	$last_delete  = array_search( $deletes[ count( $deletes ) - 1 ], $db3->queries, true );
	ok( $last_delete < $first_insert, 'replace: deletes run before the first insert' );
	ok( $db3->writes( 'INSERT INTO' ) === $db1->writes( 'INSERT INTO' ), 'replace: then the same rows as merge' );

	// ---- unknown table, chunking ---------------------------------------------

	$many = [ $lines[0] ];
	for ( $d = 1; $d <= 25; $d++ ) {
		$many[] = json_encode( [ 't' => 'site_stats', 'c' => [ 'date', 'pageviews', 'visitors' ], 'r' => [ [ sprintf( '2026-02-%02d', $d ), $d, 1 ] ] ] ) . "\n";
	}
	$many[] = json_encode( [ 't' => 'future_table', 'c' => [ 'date', 'x' ], 'r' => [ [ '2026-02-01', 1 ] ] ] ) . "\n";
	$many[] = $lines[4];
	file_put_contents( $va_tmp . '/many.jsonl', implode( '', $many ) );
	$db4 = new Fake_Wpdb();
	$db4->tables = $wpdb->tables;
	$run4        = stage_and_run( $db4, '1700000003-dddddddd', $va_tmp . '/many.jsonl', 'merge' );
	ok( 2 === count( $run4['results'] ), 'chunking: 26 lines take two requests (' . count( $run4['results'] ) . ')' );
	ok( 20 === ( $run4['results'][0]['next_offset'] ?? 0 ) && empty( $run4['results'][0]['done'] ), 'chunking: the first request stops after 20 lines and says so' );
	ok( ! empty( $run4['results'][1]['done'] ), 'chunking: the second request reaches the end' );
	ok( 25 === count( $db4->writes( 'INSERT INTO `wp_va_site_stats`' ) ), 'chunking: every one of the 25 days is written once' );
	ok( false !== strpos( (string) $run4['results'][1]['label'], '1 part' ), 'unknown table: skipped and reported, not fatal' );
	ok( [] === $db4->writes( 'INSERT INTO `wp_va_future_table`' ), 'unknown table: nothing is written to it' );

	// ---- cleanup ------------------------------------------------------------------
	foreach ( glob( $va_tmp . '/*' ) ?: [] as $f ) {
		if ( is_file( $f ) ) {
			unlink( $f );
		}
	}
	foreach ( glob( $va_tmp . '/data/*' ) ?: [] as $f ) {
		unlink( $f );
	}
	@rmdir( $va_tmp . '/data' );
	@rmdir( $va_tmp );

	echo $fail ? "\n$fail failed\n" : "\nall passed\n";
	exit( $fail ? 1 : 0 );
}
