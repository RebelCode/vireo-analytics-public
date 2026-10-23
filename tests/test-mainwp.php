<?php
/**
 * Zero-dependency assertion runner for the MainWP bridge.
 * Run: php tests/test-mainwp.php
 *
 * Covers the request gate and the payload shaping. The two filters only reach
 * the rollup queries after every check here has passed, so the shims below
 * are all the WordPress the gate needs.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}
if ( ! defined( 'VIREO_VERSION' ) ) {
	define( 'VIREO_VERSION', '0.0.0-test' );
}

$GLOBALS['va_test_enabled'] = true;
$GLOBALS['va_test_can']     = true;

$GLOBALS['va_test_proxied_extra'] = [];
$GLOBALS['va_test_abilities']     = [];
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) {
		if ( 'vireo_mainwp_enabled' === $hook ) {
			return $GLOBALS['va_test_enabled'];
		}
		if ( 'vireo_mainwp_proxied_abilities' === $hook ) {
			return array_merge( (array) $value, $GLOBALS['va_test_proxied_extra'] );
		}
		return $value;
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $s, $d = null ) { return $s; }
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public string $code; public string $message;
		public function __construct( string $code = '', string $message = '' ) { $this->code = $code; $this->message = $message; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $t ) { return $t instanceof WP_Error; }
}
// A registered ability: meta, and an execute() that records its input.
class VA_Fake_Ability {
	public array $calls = []; public array $meta; public $answer;
	public function __construct( array $meta, $answer = [ 'pageviews' => 1 ] ) { $this->meta = $meta; $this->answer = $answer; }
	public function get_meta(): array { return $this->meta; }
	public function execute( $input ) { $this->calls[] = $input; return $this->answer; }
}
if ( ! function_exists( 'wp_has_ability' ) ) {
	function wp_has_ability( $name ) { return isset( $GLOBALS['va_test_abilities'][ $name ] ); }
}
if ( ! function_exists( 'wp_get_ability' ) ) {
	function wp_get_ability( $name ) { return $GLOBALS['va_test_abilities'][ $name ] ?? null; }
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter() { return true; }
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can() { return $GLOBALS['va_test_can']; }
}
if ( ! function_exists( 'wp_timezone' ) ) {
	function wp_timezone() { return new DateTimeZone( 'UTC' ); }
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $v ) { return is_array( $v ) ? array_map( 'wp_unslash', $v ) : stripslashes( (string) $v ); }
}

// VA_ROOT=<a tree of 0.12.3> is the control for the slashed-input case below.
$va_root = getenv( 'VA_ROOT' ) ?: dirname( __DIR__ );
require $va_root . '/includes/class-roles.php';
require $va_root . '/includes/class-mainwp.php';

use VireoAnalytics\MainWP;

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

// --- valid_range -----------------------------------------------------------

ok( MainWP::valid_range( '2026-09-01', '2026-09-30' ), 'a normal month is a valid range' );
ok( MainWP::valid_range( '2026-09-30', '2026-09-30' ), 'a single day is a valid range' );
ok( ! MainWP::valid_range( '2026-09-30', '2026-09-01' ), 'end before start is refused' );
ok( ! MainWP::valid_range( '2026-02-30', '2026-03-01' ), 'an impossible calendar date is refused' );
ok( ! MainWP::valid_range( '2026-9-1', '2026-09-30' ), 'a non Y-m-d string is refused' );
ok( ! MainWP::valid_range( "2026-09-01' OR 1=1", '2026-09-30' ), 'junk after the date is refused' );
ok( MainWP::valid_range( '2025-01-01', '2026-01-01' ), 'a 366-day span is allowed' );
ok( ! MainWP::valid_range( '2024-01-01', '2026-01-01' ), 'a two-year span is refused' );

// --- clamp_limit -----------------------------------------------------------

ok( 1 === MainWP::clamp_limit( 0 ), 'limit 0 becomes 1' );
ok( 1 === MainWP::clamp_limit( '-4' ), 'negative limit becomes 1' );
ok( 10 === MainWP::clamp_limit( '10' ), 'string limit is cast' );
ok( MainWP::MAX_LIMIT === MainWP::clamp_limit( 9999 ), 'limit is capped at MAX_LIMIT' );

// --- days_range ------------------------------------------------------------

$today = new DateTimeImmutable( '2026-10-06 15:42:00', new DateTimeZone( 'UTC' ) );
ok( [ '2026-09-30', '2026-10-06' ] === MainWP::days_range( 7, $today ), 'last 7 days is today and the six before it' );
ok( [ '2026-09-07', '2026-10-06' ] === MainWP::days_range( 30, $today ), 'last 30 days is today and the 29 before it' );
ok( [ '2026-10-06', '2026-10-06' ] === MainWP::days_range( 0, $today ), 'zero days collapses to today' );

// --- envelope --------------------------------------------------------------

$env = MainWP::envelope( [ 'denied' => true ] );
ok( MainWP::API === $env['api'] && '0.0.0-test' === $env['version'] && true === $env['denied'], 'envelope carries api, version and the body' );
ok( is_int( $env['generated'] ) && $env['generated'] > 0, 'envelope is timestamped' );

// --- shaping ---------------------------------------------------------------

$shaped = MainWP::shape_pages( [
	[ 'post_id' => 3, 'path' => '/guide', 'title' => 'Guide', 'url' => 'https://x.test/guide', 'pageviews' => '120', 'visitors' => '80', 'delta' => 4 ],
	[ 'path' => '/' ],
] );
ok( 2 === count( $shaped ), 'shape_pages keeps one row per input row' );
ok( [ 'path', 'title', 'url', 'pageviews', 'visitors' ] === array_keys( $shaped[0] ), 'shape_pages exposes only the public keys' );
ok( 120 === $shaped[0]['pageviews'] && 80 === $shaped[0]['visitors'], 'shape_pages casts counts to int' );
ok( '' === $shaped[1]['title'] && 0 === $shaped[1]['pageviews'], 'shape_pages fills missing fields with empties' );
ok( ! isset( $shaped[0]['post_id'] ) && ! isset( $shaped[0]['delta'] ), 'shape_pages drops internal fields' );

$refs = MainWP::shape_referrers( [ [ 'host' => 'reddit.com', 'pageviews' => '9', 'visitors' => '7', 'delta' => null ] ] );
ok( [ 'host', 'pageviews', 'visitors' ] === array_keys( $refs[0] ) && 9 === $refs[0]['pageviews'], 'shape_referrers exposes host and int counts only' );

// --- sync gate -------------------------------------------------------------

$in = [ 'version' => '5.0' ];
ok( MainWP::sync_others_data( $in, [] ) === $in, 'sync without the vireo flag passes the payload through untouched' );
ok( MainWP::sync_others_data( $in, [ 'other' => 1 ] ) === $in, 'sync with unrelated flags passes through untouched' );
ok( [] === MainWP::sync_others_data( null, [] ), 'sync tolerates a non-array information argument' );

$GLOBALS['va_test_enabled'] = false;
$out = MainWP::sync_others_data( $in, [ 'vireo' => 1 ] );
ok( isset( $out['vireo']['denied'] ) && true === $out['vireo']['denied'] && ! isset( $out['vireo']['ranges'] ), 'sync answers denied when the site opts out via filter' );
ok( '5.0' === $out['version'], 'sync keeps the rest of the payload' );
$GLOBALS['va_test_enabled'] = true;

$GLOBALS['va_test_can'] = false;
$out = MainWP::sync_others_data( $in, [ 'vireo' => 1 ] );
ok( isset( $out['vireo']['denied'] ), 'sync answers denied when the connected user lacks the capability' );
$GLOBALS['va_test_can'] = true;

// --- extra_execution gate --------------------------------------------------

$info = [ 'x' => 1 ];
ok( MainWP::extra_execution( $info, [] ) === $info, 'extra_execution ignores requests without vireo_action' );
ok( MainWP::extra_execution( $info, [ 'vireo_action' => 'drop' ] ) === $info, 'extra_execution ignores unknown actions' );
ok( 'nope' === MainWP::extra_execution( 'nope', 'not-an-array' ), 'extra_execution tolerates a non-array post' );

$out = MainWP::extra_execution( $info, [ 'vireo_action' => 'stats', 'vireo_start' => 'yesterday', 'vireo_end' => 'today' ] );
ok( 'bad_range' === ( $out['vireo']['error'] ?? null ) && 1 === $out['x'], 'extra_execution refuses a malformed range before touching the database' );

$out = MainWP::extra_execution( $info, [ 'vireo_action' => 'stats', 'vireo_start' => '2026-09-30', 'vireo_end' => '2026-09-01' ] );
ok( 'bad_range' === ( $out['vireo']['error'] ?? null ), 'extra_execution refuses an inverted range' );

$out = MainWP::extra_execution( $info, [ 'vireo_action' => 'stats' ] );
ok( 'bad_range' === ( $out['vireo']['error'] ?? null ), 'extra_execution refuses a request with neither days nor dates' );

$GLOBALS['va_test_enabled'] = false;
$out = MainWP::extra_execution( $info, [ 'vireo_action' => 'stats', 'vireo_days' => 7 ] );
ok( true === ( $out['vireo']['denied'] ?? null ) && ! isset( $out['vireo']['report'] ), 'extra_execution answers denied when the site opts out' );
$GLOBALS['va_test_enabled'] = true;

$GLOBALS['va_test_can'] = false;
$out = MainWP::extra_execution( $info, [ 'vireo_action' => 'stats', 'vireo_start' => '2026-09-01', 'vireo_end' => '2026-09-30' ] );
ok( true === ( $out['vireo']['denied'] ?? null ), 'extra_execution answers denied without the capability' );
$GLOBALS['va_test_can'] = true;

// --- ability proxy ---------------------------------------------------------

$read_meta = [ 'annotations' => [ 'readonly' => true, 'readOnlyHint' => true ], 'show_in_rest' => true ];
$totals    = new VA_Fake_Ability( $read_meta, [ 'pageviews' => 120, 'visitors' => 80 ] );
$GLOBALS['va_test_abilities'] = [ 'vireo-analytics/get-totals' => $totals ];

ok( MainWP::ability_execution( $info, [ 'vireo_action' => 'stats' ] ) === $info, 'ability_execution ignores the stats action' );
ok( 'nope' === MainWP::ability_execution( 'nope', 'not-an-array' ), 'ability_execution tolerates a non-array post' );

$req = [ 'vireo_action' => 'ability', 'vireo_ability' => 'vireo-analytics/get-totals', 'vireo_input' => '{"start":"2026-09-01","end":"2026-09-30","site":2}' ];
$out = MainWP::ability_execution( $info, $req );
ok( 'vireo-analytics/get-totals' === ( $out['vireo']['ability'] ?? null ) && 120 === ( $out['vireo']['result']['pageviews'] ?? null ) && 1 === $out['x'], 'an allowlisted read-only ability runs and answers inside the envelope' );
ok( [ 'start' => '2026-09-01', 'end' => '2026-09-30' ] === $totals->calls[0], 'the dashboard input reaches execute() with site stripped: a child answers for itself only' );
ok( MainWP::API === $out['vireo']['api'], 'the answer carries the api version' );

$GLOBALS['va_test_enabled'] = false;
$out = MainWP::ability_execution( $info, $req );
ok( true === ( $out['vireo']['denied'] ?? null ) && ! isset( $out['vireo']['result'] ) && 1 === count( $totals->calls ), 'the vireo_mainwp_enabled opt-out refuses before anything runs' );
$GLOBALS['va_test_enabled'] = true;
$GLOBALS['va_test_can'] = false;
$out = MainWP::ability_execution( $info, $req );
ok( true === ( $out['vireo']['denied'] ?? null ) && 1 === count( $totals->calls ), 'a connected user without the Vireo capability is refused before anything runs' );
$GLOBALS['va_test_can'] = true;

// MainWP Child hands over $_POST as WordPress slashed it (wp_magic_quotes runs
// before init), so the dashboard's JSON arrives as {\"start\":...}. Control: a
// 0.12.3 tree (VA_ROOT) answers bad_input here instead of running the ability.
$slashed = [ 'vireo_action' => 'ability', 'vireo_ability' => 'vireo-analytics/get-totals', 'vireo_input' => addslashes( '{"start":"2026-10-05","end":"2026-10-11","site":2}' ) ];
ok( '{\\"start\\":\\"2026-10-05\\",\\"end\\":\\"2026-10-11\\",\\"site\\":2}' === $slashed['vireo_input'], 'harness: the fixture is the slashed form MainWP Child passes' );
$out = MainWP::ability_execution( $info, $slashed );
ok( 120 === ( $out['vireo']['result']['pageviews'] ?? null ) && ! isset( $out['vireo']['error'] ), 'a slashed JSON input from the dashboard still runs the ability' );
ok( [ 'start' => '2026-10-05', 'end' => '2026-10-11' ] === end( $totals->calls ), 'and the date range reaches execute() unescaped, with site stripped' );

$answer = static function ( string $name, string $input = '{}' ): array { return MainWP::ability_answer( [ 'vireo_action' => 'ability', 'vireo_ability' => $name, 'vireo_input' => $input ] ); };
ok( 'bad_ability' === ( $answer( 'other-plugin/get-totals' )['error'] ?? null ), 'another plugin\'s ability is refused' );
ok( 'bad_ability' === ( $answer( 'vireo-analytics/delete-everything' )['error'] ?? null ), 'a vireo name outside the allowlist is refused' );
ok( 'bad_ability' === ( $answer( 'vireo-analytics/GET-TOTALS' )['error'] ?? null ), 'names are matched exactly' );
ok( 'bad_ability' === ( $answer( 'vireo-analytics/get-totals/../get-sites' )['error'] ?? null ), 'junk in the name is refused' );
ok( 'unknown_ability' === ( $answer( 'vireo-analytics/get-404s' )['error'] ?? null ), 'an allowlisted ability this site has not registered is refused' );

// Site tools stay out even when a filter puts them on the list, and a write
// ability a filter allowlisted is refused on its missing readonly annotation.
$GLOBALS['va_test_proxied_extra'] = [ 'vireo-analytics/get-sites', 'vireo-analytics/get-sites-overview', 'vireo-analytics/set-goal' ];
$GLOBALS['va_test_abilities']['vireo-analytics/get-sites'] = new VA_Fake_Ability( $read_meta, [ 'sites' => [] ] );
$GLOBALS['va_test_abilities']['vireo-analytics/set-goal']  = new VA_Fake_Ability( [ 'annotations' => [ 'destructive' => true ], 'show_in_rest' => true ], [ 'ok' => true ] );
ok( 'bad_ability' === ( $answer( 'vireo-analytics/get-sites' )['error'] ?? null ), 'get-sites is never proxied, filter or not' );
ok( 'bad_ability' === ( $answer( 'vireo-analytics/get-sites-overview' )['error'] ?? null ), 'get-sites-overview is never proxied either' );
ok( 'not_readonly' === ( $answer( 'vireo-analytics/set-goal' )['error'] ?? null ) && [] === $GLOBALS['va_test_abilities']['vireo-analytics/set-goal']->calls, 'a write ability is refused and never runs' );
$GLOBALS['va_test_proxied_extra'] = [];

ok( 'bad_input' === ( $answer( 'vireo-analytics/get-totals', '[1,2,3]' )['error'] ?? null ), 'a JSON list is not an input' );
ok( 'bad_input' === ( $answer( 'vireo-analytics/get-totals', 'not json' )['error'] ?? null ), 'junk input is refused' );
ok( 'input_too_large' === ( $answer( 'vireo-analytics/get-totals', '{"a":"' . str_repeat( 'x', 9000 ) . '"}' )['error'] ?? null ), 'oversized input is refused' );
$n = count( $totals->calls );
ok( isset( $answer( 'vireo-analytics/get-totals', '' )['result'] ) && [] === $totals->calls[ $n ], 'no input runs the ability with no arguments' );

$GLOBALS['va_test_abilities']['vireo-analytics/get-totals'] = new VA_Fake_Ability( $read_meta, new WP_Error( 'ability_invalid_input', 'bogus is not a valid property' ) );
$out = $answer( 'vireo-analytics/get-totals', '{"bogus":1}' );
ok( 'ability_invalid_input' === ( $out['error'] ?? null ) && ! isset( $out['result'] ), 'a WP_Error from execute() (schema or permission) comes back as an error, never as data' );

echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
exit( $fail ? 1 : 0 );
