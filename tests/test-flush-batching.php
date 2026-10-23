<?php
/**
 * Zero-dependency check that a flush splits large days into batched
 * statements and still fails the whole flush when any one of them fails.
 * Run: php tests/test-flush-batching.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! function_exists( 'has_action' ) ) {
	function has_action( $h ) { return false; }
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( ...$a ) {}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( ...$a ) {}
}

/**
 * Records statements and fails the ones matching $fail_on, logging to
 * $EZSQL_ERROR the way wpdb::print_error() does.
 */
final class Fake_Wpdb {
	public string $prefix     = 'wp_';
	public string $last_error = '';
	public array $statements  = [];
	public array $fail_on     = [];
	private array $args       = [];
	private array $ids        = [];

	public function prepare( $sql, $args ) {
		$this->args = $args;
		return $sql;
	}

	public function query( $sql ) {
		$n = count( $this->statements );
		$this->statements[] = [ 'sql' => $sql, 'args' => $this->args ];
		if ( in_array( $n, $this->fail_on, true ) ) {
			global $EZSQL_ERROR;
			$this->last_error = "Got a packet bigger than 'max_allowed_packet' bytes";
			$EZSQL_ERROR[]    = [ 'query' => $sql, 'error_str' => $this->last_error ];
			return false;
		}
		$this->last_error = '';
		if ( 0 === strpos( $sql, 'INSERT IGNORE' ) ) {
			foreach ( array_slice( $this->args, 2 ) as $v ) {
				$this->ids[ $v ] = $this->ids[ $v ] ?? count( $this->ids ) + 1;
			}
		}
		return 1;
	}

	public function get_results( $sql, $output = null ) {
		$this->query( $sql );
		$rows = [];
		foreach ( array_slice( $this->args, 3 ) as $v ) {
			if ( isset( $this->ids[ $v ] ) ) {
				$rows[] = [ 'v' => $v, 'id' => $this->ids[ $v ] ];
			}
		}
		return $rows;
	}

	public function get_var( $sql ) {
		return null;
	}
}

require __DIR__ . '/../includes/class-sites.php';
require __DIR__ . '/../includes/class-upserter.php';
require __DIR__ . '/../includes/class-aggregator.php';

use VireoAnalytics\Aggregator;

$fail = 0;
function ok( $cond, $msg ) {
	global $fail;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}

/** A flush of $paths distinct paths on one day; returns [ok, wpdb, error]. */
function run_flush( int $paths, array $fail_on = [] ): array {
	global $wpdb, $EZSQL_ERROR;
	$wpdb          = new Fake_Wpdb();
	$wpdb->fail_on = $fail_on;
	$EZSQL_ERROR   = [];

	$ref = new ReflectionClass( Aggregator::class );
	$agg = $ref->newInstanceWithoutConstructor();

	$rollup = [];
	for ( $i = 0; $i < $paths; $i++ ) {
		$rollup['2026-10-05'][ "/p/$i/" ] = (object) [ 'post_id' => $i, 'pageviews' => 2, 'visitors' => 1 ];
	}
	foreach ( $ref->getProperties() as $prop ) {
		$type = $prop->getType();
		$prop->setAccessible( true ); // PHP 7.4 needs this to read a private property.
		if ( $prop->isStatic() || ! $type || $prop->isInitialized( $agg ) ) {
			continue;
		}
		$prop->setValue( $agg, $type->getName() === 'array' ? [] : ( $type->getName() === 'string' ? '' : null ) );
	}
	$p = $ref->getProperty( 'post_rollup' );
	$p->setAccessible( true );
	$p->setValue( $agg, $rollup );

	$flush = $ref->getMethod( 'flush_to_db' );
	$flush->setAccessible( true );
	$result = $flush->invoke( $agg );

	$err = $ref->getProperty( 'last_write_error' );
	$err->setAccessible( true );
	return [ $result, $wpdb, (string) $err->getValue( $agg ) ];
}

function of_kind( Fake_Wpdb $db, string $prefix ): array {
	return array_values( array_filter( $db->statements, static fn( $s ) => 0 === strpos( ltrim( $s['sql'] ), $prefix ) ) );
}

// 1,201 paths: three batches of 500, 500 and 201 for each statement kind.
[ $result, $db ] = run_flush( 1201 );
$ignores = of_kind( $db, 'INSERT IGNORE' );
$selects = of_kind( $db, 'SELECT' );
$inserts = array_values( array_filter( of_kind( $db, 'INSERT INTO' ), static fn( $s ) => false !== strpos( $s['sql'], 'post_id, path_id' ) ) );

ok( true === $result, 'clean flush reports success' );
ok( 3 === count( $ignores ) && 3 === count( $selects ), 'path upsert runs in 3 batches (got ' . count( $ignores ) . ' + ' . count( $selects ) . ')' );
ok( 3 === count( $inserts ), 'post stats insert runs in 3 batches (got ' . count( $inserts ) . ')' );
$rows = array_map( static fn( $s ) => ( count( $s['args'] ) - 1 ) / 5, $inserts );
ok( [ 500, 500, 201 ] === $rows, 'batch sizes are 500/500/201 (got ' . implode( '/', $rows ) . ')' );
ok( 1201 === array_sum( $rows ), 'every path written once (' . array_sum( $rows ) . ' of 1201)' );
ok( in_array( 'COMMIT', array_column( $db->statements, 'sql' ), true ), 'clean flush commits' );

// A day small enough for one statement still uses one.
[ , $db ] = run_flush( 40 );
ok( 1 === count( of_kind( $db, 'INSERT IGNORE' ) ), '40 paths stay in a single upsert' );

// First post insert batch fails, later ones succeed: last_error is clear by
// the end of the writer, so this is the case the old check missed.
[ , $probe ] = run_flush( 1201 );
$first_insert = null;
foreach ( $probe->statements as $i => $s ) {
	if ( false !== strpos( $s['sql'], 'post_id, path_id' ) ) {
		$first_insert = $i;
		break;
	}
}
[ $result, $db, $error ] = run_flush( 1201, [ $first_insert ] );
ok( '' === $db->last_error, 'control: wpdb last_error is already clear when the writer returns, so it cannot see the failure' );
ok( false === $result, 'an early failed batch fails the flush even though later batches succeeded' );
ok( false !== strpos( $error, 'max_allowed_packet' ) && 0 === strpos( $error, 'write_post_rollup' ), 'stall reason names the writer and the error: ' . $error );
ok( in_array( 'ROLLBACK', array_column( $db->statements, 'sql' ), true ), 'failed flush rolls back' );
ok( ! in_array( 'COMMIT', array_column( $db->statements, 'sql' ), true ), 'failed flush does not commit' );

// An upsert INSERT IGNORE that fails followed by a SELECT that succeeds.
[ $result, , $error ] = run_flush( 30, [ 1 ] );
ok( false === $result, 'a failed path upsert fails the flush (' . $error . ')' );

echo $fail ? "\n$fail failed\n" : "\nall passed\n";
exit( $fail ? 1 : 0 );
