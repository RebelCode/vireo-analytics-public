<?php
/**
 * Zero-dependency check of what the Abilities API gets told about us.
 * Run: php tests/test-abilities-registry.php
 *
 * Every ability has to carry the readonly annotation in the one place core
 * reads it, the REST flag, the MCP discovery flag, and the capability gate.
 * One mislabelled registration advertises a traffic query as something that
 * mutates the site, and the run controller then answers GET with a 405. The
 * shims below are all the WordPress the registration code touches.
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

$GLOBALS['va_test_registered'] = [];
if ( ! function_exists( 'wp_register_ability' ) ) {
	function wp_register_ability( $name, $args ) {
		$GLOBALS['va_test_registered'][ $name ] = $args;
		return true;
	}
}
if ( ! function_exists( 'wp_register_ability_category' ) ) {
	function wp_register_ability_category( $name, $args ) {
		$GLOBALS['va_test_category'] = [ $name, $args ];
		return true;
	}
}

require __DIR__ . '/../includes/class-installer.php';
require __DIR__ . '/../includes/class-abilities.php';

use VireoAnalytics\Abilities;

$fail = 0;
function ok( $cond, $msg ) {
	global $fail;
	echo ( $cond ? 'PASS: ' : 'FAIL: ' ) . $msg . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}

$abilities = Abilities::instance();
$abilities->register_category();
$abilities->register_abilities();

$registered = $GLOBALS['va_test_registered'];

ok( 'vireo-analytics' === ( $GLOBALS['va_test_category'][0] ?? '' ), 'the category registers under the plugin slug' );

$expected = [
	'get-totals', 'get-daily-stats', 'get-hourly-stats', 'get-changes', 'get-highlights',
	'get-top-pages', 'get-top-referrers', 'get-traffic-sources', 'get-referrer-detail',
	'get-ai-referrals', 'get-search-console', 'get-countries', 'get-devices', 'get-events',
	'get-event-detail', 'get-post-stats', 'get-404s', 'get-bot-stats', 'get-vitals',
	'get-annotations', 'get-year-review', 'get-goals',
];
foreach ( $expected as $short ) {
	ok( isset( $registered[ 'vireo-analytics/' . $short ] ), "vireo-analytics/$short is registered" );
}
ok( count( $registered ) === count( $expected ), 'nothing registers that this test does not know about (' . count( $registered ) . ')' );

foreach ( $registered as $name => $args ) {
	$meta  = $args['meta'] ?? [];
	$ann   = $meta['annotations'] ?? [];
	$short = substr( $name, strlen( 'vireo-analytics/' ) );

	ok( true === ( $ann['readonly'] ?? null ) && true === ( $ann['readOnlyHint'] ?? null ), "$short: readonly in meta.annotations, both spellings" );
	ok( true === ( $ann['idempotent'] ?? null ) && true === ( $ann['idempotentHint'] ?? null ), "$short: idempotent in meta.annotations, both spellings" );
	ok( ! isset( $meta['readonly'] ), "$short: no stray top-level meta.readonly" );
	ok( true === ( $meta['show_in_rest'] ?? null ), "$short: show_in_rest" );
	ok( '' !== trim( (string) ( $args['label'] ?? '' ) ) && strlen( (string) ( $args['description'] ?? '' ) ) > 40, "$short: has a label and a description an agent can choose by" );
	ok( 'vireo-analytics' === ( $args['category'] ?? '' ), "$short: in the Vireo category" );

	$schema = $args['input_schema'] ?? [];
	ok( 'object' === ( $schema['type'] ?? '' ) && [] === ( $schema['default'] ?? null ), "$short: input schema is an object with an empty default, so no-argument calls validate" );
	ok( false === ( $schema['additionalProperties'] ?? null ), "$short: unknown arguments are a validation error, not silently dropped" );
	// An ability with no inputs would declare [] here, which JSON-encodes as a
	// list; WooCommerce's bundled adapter 0.3.0 passes that straight to the
	// client and the official SDK rejects the whole tools/list.
	ok( 0 === strpos( (string) json_encode( $schema['properties'] ?? [] ), '{' ), "$short: properties encode as a JSON object" );

	$exec = $args['execute_callback'] ?? null;
	ok( $exec instanceof Closure, "$short: execute callback goes through the guarded closure, so output is flattened" );
	ok( method_exists( $abilities, 'exec_' . str_replace( '-', '_', substr( $short, 4 ) ) ) || in_array( $short, [ 'get-daily-stats', 'get-hourly-stats', 'get-post-stats', 'get-traffic-sources', 'get-bot-stats' ], true ), "$short: has an exec method" );

	$perm = $args['permission_callback'] ?? null;
	if ( 'get-goals' === $short ) {
		ok( [ $abilities, 'pro_permission_check' ] === $perm, "$short: Pro-gated permission" );
		ok( ! isset( $meta['mcp'] ), "$short: not advertised to the default MCP server on free" );
	} else {
		ok( [ $abilities, 'permission_check' ] === $perm, "$short: capability permission" );
		ok( true === ( $meta['mcp']['public'] ?? null ), "$short: meta.mcp.public for the adapter's default server" );
	}
}

// What core's validator does with the schema: a stray key fails, documented
// keys pass. Mirrors rest_validate_value_from_schema()'s additionalProperties
// handling, which is the part this guards.
$validate = static function ( array $schema, array $input ): bool {
	if ( false === ( $schema['additionalProperties'] ?? true ) ) {
		foreach ( array_keys( $input ) as $key ) {
			if ( ! isset( $schema['properties'][ $key ] ) ) {
				return false;
			}
		}
	}
	foreach ( (array) ( $schema['required'] ?? [] ) as $key ) {
		if ( ! array_key_exists( $key, $input ) ) {
			return false;
		}
	}
	return true;
};
$totals_schema = $registered['vireo-analytics/get-totals']['input_schema'];
ok( $validate( $totals_schema, [ 'start' => '2026-09-01', 'end' => '2026-09-30' ] ), 'get-totals accepts its documented start and end' );
ok( $validate( $totals_schema, [] ), 'get-totals accepts no arguments' );
ok( ! $validate( $totals_schema, [ 'start' => '2026-09-01', 'site' => 2 ] ), 'get-totals refuses a stray site argument instead of answering for this site' );
ok( ! $validate( $totals_schema, [ '_meta' => [ 'progressToken' => 1 ] ] ), 'MCP request metadata belongs in params, not arguments, and is refused there' );
$post_schema = $registered['vireo-analytics/get-post-stats']['input_schema'];
ok( $validate( $post_schema, [ 'post_id' => 5, 'start' => '2026-09-01' ] ) && ! $validate( $post_schema, [ 'post_id' => 5, 'limit' => 5 ] ), 'get-post-stats takes post_id but not a limit it never documented' );

// Range props and limit on the abilities that take them.
foreach ( [ 'get-top-pages', 'get-changes', 'get-404s', 'get-search-console', 'get-referrer-detail', 'get-event-detail' ] as $short ) {
	$props = $registered[ 'vireo-analytics/' . $short ]['input_schema']['properties'] ?? [];
	ok( isset( $props['start'], $props['end'], $props['limit'] ) && 1 === $props['limit']['minimum'] && 100 === $props['limit']['maximum'], "$short: start, end and a 1-100 limit" );
}
ok( [ 'host' ] === ( $registered['vireo-analytics/get-referrer-detail']['input_schema']['required'] ?? [] ), 'get-referrer-detail requires host' );
ok( [ 'name' ] === ( $registered['vireo-analytics/get-event-detail']['input_schema']['required'] ?? [] ), 'get-event-detail requires name' );
ok( [ 'post_id' ] === ( $registered['vireo-analytics/get-post-stats']['input_schema']['required'] ?? [] ), 'get-post-stats requires post_id' );
ok( isset( $registered['vireo-analytics/get-search-console']['input_schema']['properties']['post_id'], $registered['vireo-analytics/get-search-console']['input_schema']['properties']['path'] ), 'get-search-console takes post_id or path' );
ok( isset( $registered['vireo-analytics/get-year-review']['input_schema']['properties']['year'] ) && ! isset( $registered['vireo-analytics/get-year-review']['input_schema']['properties']['start'] ), 'get-year-review takes a year, not a range' );

// The permission callbacks themselves.
$GLOBALS['va_test_can'] = false;
$GLOBALS['va_test_pro'] = false;
ok( ! $abilities->permission_check( [] ), 'a user without view_vireo_analytics is refused' );
ok( ! $abilities->pro_permission_check( [] ), 'a user without the capability is refused the Pro ability too' );
$GLOBALS['va_test_can'] = true;
ok( $abilities->permission_check( [] ), 'a user with the capability is allowed' );
ok( ! $abilities->pro_permission_check( [] ), 'the Pro ability is refused on free even with the capability' );
$GLOBALS['va_test_pro'] = true;
ok( $abilities->pro_permission_check( [] ), 'the Pro ability is allowed once Pro is active' );

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
