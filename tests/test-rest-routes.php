<?php
/**
 * Zero-dependency check of the REST routes' wiring.
 * Run: php tests/test-rest-routes.php
 *
 * Every route has to be GET, carry validated start/end args, and sit behind
 * the capability check. The check itself is called with the capability
 * denied, for every route, so no route can be added without a gate. The
 * query code behind the routes is exercised against a real database in
 * tests/wp/.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}
if ( ! function_exists( '__' ) ) {
	function __( $s, $d = null ) { return $s; }
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action() { return true; }
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) {
		return 'vireo_pro_is_active' === $hook ? ! empty( $GLOBALS['va_test_pro'] ) : $value;
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can() { return ! empty( $GLOBALS['va_test_can'] ); }
}
if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		public array $params = [];
		public function get_param( $k ) { return $this->params[ $k ] ?? null; }
	}
}

$GLOBALS['va_test_routes'] = [];
if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( $ns, $route, $args ) {
		$GLOBALS['va_test_routes'][ $ns . $route ] = $args;
		return true;
	}
}

require __DIR__ . '/../includes/class-installer.php';
require __DIR__ . '/../includes/class-rest-api.php';

use VireoAnalytics\REST_API;

$fail = 0;
function ok( $cond, $msg ) {
	global $fail;
	echo ( $cond ? 'PASS: ' : 'FAIL: ' ) . $msg . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}

$api = ( new ReflectionClass( REST_API::class ) )->newInstanceWithoutConstructor();
$api->register_routes();
$routes = $GLOBALS['va_test_routes'];
$ns     = REST_API::NAMESPACE;

$expected = [
	'/stats/totals', '/stats/daily', '/stats/top-pages', '/stats/top-referrers', '/stats/countries',
	'/stats/devices', '/stats/events', '/stats/post/(?P<id>\d+)',
	'/stats/hourly', '/stats/changes', '/stats/highlights', '/stats/channels', '/stats/referrer',
	'/stats/ai-referrals', '/stats/search-console', '/stats/event/(?P<name>[a-z0-9_\-]+)', '/stats/404s',
	'/stats/bots', '/stats/vitals', '/stats/annotations', '/stats/year-review',
	'/stats/goals', '/stats/goals/(?P<id>\d+)',
];
foreach ( $expected as $route ) {
	ok( isset( $routes[ $ns . $route ] ), "$route is registered" );
}
ok( count( $routes ) === count( $expected ), 'no route this test does not know about (' . count( $routes ) . ')' );

$GLOBALS['va_test_can'] = false;
$GLOBALS['va_test_pro'] = false;
$request = new WP_REST_Request();

foreach ( $routes as $key => $args ) {
	$route = substr( $key, strlen( $ns ) );
	ok( 'GET' === ( $args['methods'] ?? '' ), "$route: GET only" );
	ok( is_array( $args['callback'] ) && $args['callback'][0] === $api && method_exists( $api, $args['callback'][1] ), "$route: callback exists" );
	ok( is_callable( $args['permission_callback'] ?? null ) && false === call_user_func( $args['permission_callback'], $request ), "$route: a subscriber is refused" );

	if ( '/stats/year-review' === $route ) {
		ok( isset( $args['args']['year'] ) && 'absint' === $args['args']['year']['sanitize_callback'], "$route: takes a sanitized year" );
		continue;
	}
	foreach ( [ 'start', 'end' ] as $d ) {
		ok( ! empty( $args['args'][ $d ]['required'] ) && [ $api, 'validate_date' ] === $args['args'][ $d ]['validate_callback'] && [ $api, 'sanitize_date' ] === $args['args'][ $d ]['sanitize_callback'], "$route: $d is required, validated and clamped" );
	}
	if ( isset( $args['args']['limit'] ) ) {
		ok( [ $api, 'sanitize_limit' ] === $args['args']['limit']['sanitize_callback'] && (int) $args['args']['limit']['default'] >= 1, "$route: limit is clamped and has a default" );
	}
}

// Pro shares the namespace. A path both plugins register is one handler
// shadowing the other on every site that runs both.
$pro_routes = require __DIR__ . '/fixtures/pro-routes.php';
ok( count( $pro_routes ) >= 21, 'the Pro route fixture is populated (' . count( $pro_routes ) . ')' );
foreach ( $pro_routes as $pro_route ) {
	ok( ! isset( $routes[ $ns . $pro_route ] ), "free does not register Pro's $pro_route" );
}
ok( isset( $routes[ $ns . '/stats/channels' ] ) && ! isset( $routes[ $ns . '/stats/sources' ] ), 'traffic channels live at /stats/channels, leaving /stats/sources to Pro' );

ok( ! empty( $routes[ $ns . '/stats/referrer' ]['args']['host']['required'] ), '/stats/referrer requires host' );
ok( 'sanitize_key' === ( $routes[ $ns . '/stats/event/(?P<name>[a-z0-9_\-]+)' ]['args']['name']['sanitize_callback'] ?? '' ), '/stats/event/{name} sanitizes the name as a key' );
ok( isset( $routes[ $ns . '/stats/search-console' ]['args']['post_id'], $routes[ $ns . '/stats/search-console' ]['args']['path'] ), '/stats/search-console takes post_id and path' );

// The gate itself, both ways.
$GLOBALS['va_test_can'] = true;
ok( $api->permission_check( $request ), 'a user with the capability is allowed' );
ok( ! $api->pro_permission_check( $request ), 'the Pro routes still refuse on free' );
ok( 1 === $api->sanitize_limit( 0 ) && 100 === $api->sanitize_limit( 5000 ), 'limit clamps to 1-100' );
ok( ! $api->validate_date( '2026-9-1' ) && $api->validate_date( '2026-09-01' ), 'dates must be Y-m-d' );

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
