<?php
/**
 * Zero-dependency check of how the MCP server is booted and what it offers.
 * Run: php tests/test-mcp-server.php
 *
 * The adapter is stubbed: what matters here is that we suppress its
 * site-wide default server only when no other plugin uses the adapter,
 * including one that calls McpAdapter::instance() itself the way Rank Math
 * does, that the tool list is
 * the registered free abilities plus whatever Pro adds through the filter,
 * and that the transport refuses anyone without the dashboard capability.
 *
 * Control: VA_ROOT=<a 0.12.4 tree> fails on the Rank Math assertions, because
 * 0.12.4 suppressed the default server at plugins_loaded:
 *   mkdir /tmp/0124 && git archive v0.12.4 includes | tar -x -C /tmp/0124
 *   VA_ROOT=/tmp/0124 php tests/test-mcp-server.php
 */

// PHP 8 string helpers, so the zero-dependency runner also works on 7.4,
// where WordPress itself provides these.
if ( ! function_exists( 'str_contains' ) ) {
	function str_contains( $h, $n ) { return '' === $n || false !== strpos( $h, $n ); }
}
if ( ! function_exists( 'str_starts_with' ) ) {
	function str_starts_with( $h, $n ) { return 0 === strncmp( $h, $n, strlen( $n ) ); }
}
if ( ! function_exists( 'str_ends_with' ) ) {
	function str_ends_with( $h, $n ) { return '' === $n || substr( $h, -strlen( $n ) ) === $n; }
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}
if ( ! defined( 'VIREO_DIR' ) ) {
	define( 'VIREO_DIR', __DIR__ . '/no-such-dir/' );
}
if ( ! defined( 'VIREO_VERSION' ) ) {
	define( 'VIREO_VERSION', '0.0.0-test' );
}
if ( ! function_exists( '__' ) ) {
	function __( $s, $d = null ) { return $s; }
}
if ( ! function_exists( '__return_false' ) ) {
	function __return_false() { return false; }
}

$GLOBALS['va_actions']  = [];
$GLOBALS['va_filters']  = [];
$GLOBALS['va_did']      = [];
$GLOBALS['va_abilities'] = [];
$GLOBALS['va_extra_tools'] = [];
$GLOBALS['va_pro'] = false;
$GLOBALS['va_can'] = true;

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $cb, $priority = 10 ) { $GLOBALS['va_actions'][] = [ $hook, $cb, $priority ]; return true; }
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $cb ) { $GLOBALS['va_filters'][] = [ $hook, $cb ]; return true; }
}
if ( ! function_exists( 'did_action' ) ) {
	function did_action( $hook ) { return (int) ( $GLOBALS['va_did'][ $hook ] ?? 0 ); }
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) {
		if ( 'vireo_pro_is_active' === $hook ) {
			return $GLOBALS['va_pro'];
		}
		if ( 'vireo_mcp_tools' === $hook ) {
			return array_merge( (array) $value, $GLOBALS['va_extra_tools'] );
		}
		if ( 'vireo_mcp_server_description' === $hook ) {
			return $value . ( $GLOBALS['va_desc_suffix'] ?? '' );
		}
		return $value;
	}
}
// wp_has_ability is the quiet check; wp_get_ability notices on unknown names,
// so the stub counts how often it is asked.
$GLOBALS['va_get_calls'] = 0;
if ( ! function_exists( 'wp_has_ability' ) ) {
	function wp_has_ability( $name ) { return in_array( $name, $GLOBALS['va_abilities'], true ); }
}
if ( ! function_exists( 'wp_get_ability' ) ) {
	function wp_get_ability( $name ) { $GLOBALS['va_get_calls']++; return in_array( $name, $GLOBALS['va_abilities'], true ) ? (object) [ 'name' => $name ] : null; }
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can() { return $GLOBALS['va_can']; }
}
if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( $path ) { return 'https://example.test/wp-json/' . $path; }
}

// The adapter, stubbed to the two calls the class makes.
require __DIR__ . '/fixtures/mcp-adapter-stub.php';

$va_root = getenv( 'VA_ROOT' ) ?: dirname( __DIR__ );
require $va_root . '/includes/class-installer.php';
require $va_root . '/includes/class-mcp.php';

use VireoAnalytics\Mcp;

$fail = 0;
function ok( $cond, $msg ) {
	global $fail;
	echo ( $cond ? 'PASS: ' : 'FAIL: ' ) . $msg . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}
// Run what the plugin hooked onto $hook, the way do_action would.
function fire( string $hook ): void {
	foreach ( $GLOBALS['va_actions'] as $action ) {
		if ( $action[0] === $hook ) {
			call_user_func( $action[1] );
		}
	}
}
function filter_added( string $hook ): bool {
	foreach ( $GLOBALS['va_filters'] as [ $h, $cb ] ) {
		if ( $h === $hook ) {
			return true;
		}
	}
	return false;
}

// --- contract -------------------------------------------------------------

ok( 'vireo-analytics' === Mcp::SERVER_ID && 'vireo-analytics' === Mcp::NAMESPACE && 'mcp' === Mcp::ROUTE, 'server id, namespace and route match what Pro clients were configured against' );
ok( 'https://example.test/wp-json/vireo-analytics/mcp' === Mcp::endpoint_url(), 'endpoint URL is the REST namespace plus route' );
ok( Mcp::available(), 'available() is true when the adapter classes exist' );

// --- boot never touches the adapter at plugins_loaded ----------------------

Mcp::boot();
ok( [ 'mcp_adapter_init', [ Mcp::class, 'register_server' ], 10 ] === $GLOBALS['va_actions'][0], 'register_server is hooked to mcp_adapter_init' );
ok( ! filter_added( 'mcp_adapter_create_default_server' ), 'boot leaves the default server alone: other plugins have not all loaded yet' );
ok( 0 === \WP\MCP\Plugin::$booted, 'boot does not instantiate the adapter yet' );
$deferred = array_values( array_filter( $GLOBALS['va_actions'], static fn( $a ) => 'rest_api_init' === $a[0] ) );
ok( 1 === count( $deferred ) && 14 === $deferred[0][2], 'the decision waits for rest_api_init at 14, one step before the adapter initialises at 15' );

// --- a plugin that called McpAdapter::instance() itself (Rank Math) ------

// The adapter's only trace is its own init callback on rest_api_init.
$GLOBALS['wp_filter'] = [
	'rest_api_init' => (object) [
		'callbacks' => [
			15 => [ 'x' => [ 'function' => [ new \WP\MCP\Core\McpAdapter(), 'init' ], 'accepted_args' => 1 ] ],
		],
	],
];
fire( 'rest_api_init' );
ok( ! filter_added( 'mcp_adapter_create_default_server' ), 'Rank Math\'s adapter: its default server, where its abilities live, is left alone' );
ok( 0 === \WP\MCP\Plugin::$booted, 'Rank Math\'s adapter: not booted a second time' );
ok( ! Mcp::we_booted_adapter(), 'Rank Math\'s adapter: we record that we did not boot it' );

// --- the adapter's plugin build, which fires an action --------------------

$GLOBALS['wp_filter'] = [];
$GLOBALS['va_did']    = [ 'wp_mcp_init' => 1 ];
fire( 'rest_api_init' );
ok( ! filter_added( 'mcp_adapter_create_default_server' ) && 0 === \WP\MCP\Plugin::$booted, 'the MCP Adapter plugin booted it: left alone' );

// --- nobody else uses the adapter ------------------------------------------

$GLOBALS['va_did'] = [];
fire( 'rest_api_init' );
ok( filter_added( 'mcp_adapter_create_default_server' ), 'we boot the adapter: the site-wide default server is suppressed' );
foreach ( $GLOBALS['va_filters'] as [ $h, $cb ] ) {
	if ( 'mcp_adapter_create_default_server' === $h ) {
		ok( '__return_false' === $cb, 'suppressed with __return_false' );
	}
}
ok( 1 === \WP\MCP\Plugin::$booted, 'we boot the adapter exactly once' );
ok( Mcp::we_booted_adapter(), 'and record that we did' );
fire( 'rest_api_init' );
ok( 1 === \WP\MCP\Plugin::$booted, 'a second rest_api_init does not boot it again' );

// --- tool list ------------------------------------------------------------

$free = Mcp::free_tools();
ok( count( $free ) === 21 && ! in_array( 'vireo-analytics/get-goals', $free, true ), 'free offers 21 tools and leaves the Pro-gated goals out (' . count( $free ) . ')' );
ok( in_array( 'vireo-analytics/get-search-console', $free, true ) && in_array( 'vireo-analytics/get-changes', $free, true ) && in_array( 'vireo-analytics/get-ai-referrals', $free, true ), 'the new abilities are in the list' );
$GLOBALS['va_pro'] = true;
ok( in_array( 'vireo-analytics/get-goals', Mcp::free_tools(), true ), 'goals is offered once Pro is active' );
$GLOBALS['va_pro'] = false;

$GLOBALS['va_abilities'] = [];
ok( [] === Mcp::available_tools(), 'no registered abilities: nothing is offered' );

$GLOBALS['va_abilities'] = [ 'vireo-analytics/get-totals', 'vireo-analytics/get-404s', 'vireo-analytics/get-revenue' ];
$GLOBALS['va_extra_tools'] = [ 'vireo-analytics/get-revenue', 'vireo-analytics/get-totals', 'vireo-analytics/not-registered' ];
$tools = Mcp::available_tools();
ok( [ 'vireo-analytics/get-totals', 'vireo-analytics/get-404s', 'vireo-analytics/get-revenue' ] === $tools, 'only registered abilities survive, Pro\'s filter additions included, duplicates removed: ' . implode( ',', $tools ) );
ok( 0 === $GLOBALS['va_get_calls'], 'the quiet wp_has_ability() check is used, never the noticing wp_get_ability()' );

// --- description: the Pro pointer appears without Pro, never with it -----

$GLOBALS['va_pro'] = false;
$desc = Mcp::description();
ok( str_ends_with( $desc, 'need Vireo Pro: https://vireowp.com/pricing/' ), 'without Pro the instructions end with where revenue questions are answered' );
ok( 1 === substr_count( $desc, 'vireowp.com/pricing' ), 'the pointer appears once' );

$GLOBALS['va_pro'] = true;
$GLOBALS['va_desc_suffix'] = ' Revenue attribution, campaigns, funnels and real-time visitors are included.';
$desc = Mcp::description();
ok( false === strpos( $desc, 'vireowp.com/pricing' ), 'with Pro active the free pointer is gone' );
ok( str_ends_with( $desc, 'period. Revenue attribution, campaigns, funnels and real-time visitors are included.' ), 'and Pro\'s own sentence through vireo_mcp_server_description takes its place' );
ok( false === strpos( $desc, 'need Vireo Pro' ) && 1 === substr_count( $desc, 'real-time' ), 'the two sentences never both appear' );
$GLOBALS['va_desc_suffix'] = '';
$GLOBALS['va_pro'] = false;

// --- register_server ------------------------------------------------------

$adapter = new \WP\MCP\Core\McpAdapter();
Mcp::register_server( $adapter );
ok( 1 === count( $adapter->servers ), 'one server created' );
$args = $adapter->servers[0];
ok( 'vireo-analytics' === $args[0] && 'vireo-analytics' === $args[1] && 'mcp' === $args[2], 'created with the contract id, namespace and route' );
ok( '0.0.0-test' === $args[5], 'server version is the plugin version' );
ok( [ 'WP\MCP\Transport\HttpTransport' ] === $args[6], 'HTTP transport only' );
ok( $tools === $args[9] && [] === $args[10] && [] === $args[11], 'tools are the available list, no resources or prompts' );
ok( [ Mcp::class, 'transport_permission_check' ] === $args[12], 'the transport is gated by our permission check' );

$GLOBALS['va_abilities'] = [];
$GLOBALS['va_extra_tools'] = [];
$adapter = new \WP\MCP\Core\McpAdapter();
Mcp::register_server( $adapter );
ok( [] === $adapter->servers, 'with nothing to offer, no server is created' );

// --- list results are wrapped for MCP only ---------------------------------

$filters_now = array_map( static fn( $f ) => $f[0], $GLOBALS['va_filters'] );
ok( in_array( 'mcp_adapter_tool_call_result', $filters_now, true ), 'the tool result filter is hooked at boot' );
$server = new class { public function get_server_id() { return 'vireo-analytics'; } };
$other  = new class { public function get_server_id() { return 'someone-else'; } };
ok( [ 'rows' => [ [ 'date' => '2026-09-01', 'pageviews' => 3 ] ] ] === Mcp::wrap_list_result( [ [ 'date' => '2026-09-01', 'pageviews' => 3 ] ], [], 'x', null, $server ), 'a list of rows becomes { rows: [...] }, an object as the MCP schema requires' );
ok( [ 'rows' => [] ] === Mcp::wrap_list_result( [], [], 'x', null, $server ), 'an empty list is still an object' );
ok( [ 'pageviews' => 1, 'visitors' => 1 ] === Mcp::wrap_list_result( [ 'pageviews' => 1, 'visitors' => 1 ], [], 'x', null, $server ), 'an object result is untouched' );
ok( [ 1, 2 ] === Mcp::wrap_list_result( [ 1, 2 ], [], 'x', null, $other ), 'another server\'s results are left alone' );
$err = new stdClass();
ok( $err === Mcp::wrap_list_result( $err, [], 'x', null, $server ), 'non-array results pass through' );

// --- transport gate -------------------------------------------------------

$GLOBALS['va_can'] = false;
ok( ! Mcp::transport_permission_check(), 'a logged-in user without view_vireo_analytics is refused at the transport' );
$GLOBALS['va_can'] = true;
ok( Mcp::transport_permission_check(), 'a user with the capability gets through' );

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
