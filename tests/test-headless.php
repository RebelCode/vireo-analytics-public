<?php
/**
 * Zero-dependency assertion runner for Headless.
 * Run: php tests/test-headless.php
 *
 * A separate front end (Astro, Next.js) renders this site's content and sends
 * beacons across origins. These cover the three decisions that make that safe:
 * which origin strings are accepted, what a request with an Origin header is
 * (ours, the configured front end, or a stranger), and which post a front-end
 * path lands on.
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
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

// The pieces of WordPress the class touches, as the endpoint sees them under
// SHORTINIT and the aggregator sees them under cron.
$GLOBALS['va_filters'] = [];
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) {
		foreach ( $GLOBALS['va_filters'][ $hook ] ?? [] as $cb ) {
			$value = $cb( $value, ...$args );
		}
		return $value;
	}
}
$GLOBALS['va_options'] = [
	'home'    => 'https://cms.example.com',
	'siteurl' => 'https://cms.example.com/wp',
];
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		return $GLOBALS['va_options'][ $key ] ?? $default;
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) {
		return trim( (string) preg_replace( '/[\x00-\x1F\x7F]/', '', (string) $s ) );
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $s ) {
		return $s;
	}
}
if ( ! function_exists( 'vireo_get_settings' ) ) {
	function vireo_get_settings() {
		return $GLOBALS['va_settings'] ?? [];
	}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) {
		return 'https://cms.example.com' . $path;
	}
}
$GLOBALS['va_url_map'] = [];
$GLOBALS['va_url_calls'] = 0;
if ( ! function_exists( 'url_to_postid' ) ) {
	function url_to_postid( $url ) {
		$GLOBALS['va_url_calls']++;
		return (int) ( $GLOBALS['va_url_map'][ $url ] ?? 0 );
	}
}
// Which post IDs a visitor could see. 7 and 9 are published posts; 11 is a
// draft, 13 private, 15 scheduled, 17 a published reusable block (a type that
// is not viewable), and 12345 does not exist.
$GLOBALS['va_public'] = [];
if ( ! function_exists( 'is_post_publicly_viewable' ) ) {
	function is_post_publicly_viewable( $post ) {
		return ! empty( $GLOBALS['va_public'][ (int) $post ] );
	}
}
$GLOBALS['va_cache'] = [];
if ( ! function_exists( 'wp_cache_get' ) ) {
	function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
		$found = array_key_exists( "$group:$key", $GLOBALS['va_cache'] );
		return $found ? $GLOBALS['va_cache'][ "$group:$key" ] : false;
	}
}
if ( ! function_exists( 'wp_cache_set' ) ) {
	function wp_cache_set( $key, $value, $group = '', $expire = 0 ) {
		$GLOBALS['va_cache'][ "$group:$key" ] = $value;
		return true;
	}
}

require __DIR__ . '/../includes/class-headless.php';

use VireoAnalytics\Headless;

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

// ---- normalising what the owner typed --------------------------------------

ok( 'https://vireowp.com' === Headless::normalize_origin( 'https://vireowp.com' ), 'a bare https origin is kept' );
ok( 'https://vireowp.com' === Headless::normalize_origin( 'https://vireowp.com/' ), 'trailing slash is dropped' );
ok( 'https://vireowp.com' === Headless::normalize_origin( 'https://vireowp.com/blog/post/?x=1#top' ), 'path, query and fragment are dropped' );
ok( 'https://vireowp.com' === Headless::normalize_origin( '  HTTPS://VireoWP.com  ' ), 'scheme and host are lower-cased and trimmed' );
ok( 'https://vireowp.com' === Headless::normalize_origin( 'https://vireowp.com:443' ), 'the default https port is dropped' );
ok( 'http://vireowp.com' === Headless::normalize_origin( 'http://vireowp.com:80' ), 'the default http port is dropped' );
ok( 'http://localhost:4340' === Headless::normalize_origin( 'http://localhost:4340/' ), 'a non-default port is kept' );
ok( 'http://127.0.0.1:4340' === Headless::normalize_origin( 'http://127.0.0.1:4340' ), 'an IP origin is accepted' );
ok( '' === Headless::normalize_origin( '' ), 'empty is empty' );
ok( '' === Headless::normalize_origin( 'vireowp.com' ), 'a host without a scheme is rejected' );
ok( '' === Headless::normalize_origin( 'ftp://vireowp.com' ), 'a non-http scheme is rejected' );
ok( '' === Headless::normalize_origin( 'javascript:alert(1)' ), 'javascript: is rejected' );
ok( '' === Headless::normalize_origin( 'https://user:pw@vireowp.com' ), 'userinfo is rejected' );
ok( '' === Headless::normalize_origin( 'https://vireo wp.com' ), 'a host with a space is rejected' );
ok( '' === Headless::normalize_origin( 'https://' ), 'a scheme with no host is rejected' );
ok( '' === Headless::normalize_origin( 'null' ), 'the literal "null" origin is rejected' );

// ---- allowed origins ---------------------------------------------------------

$GLOBALS['va_settings'] = [ 'headless_origin' => 'https://vireowp.com' ];
ok( [ 'https://vireowp.com' ] === Headless::allowed_origins(), 'free allows the one configured origin' );
$GLOBALS['va_settings'] = [];
ok( [] === Headless::allowed_origins(), 'nothing configured means nothing allowed' );
$GLOBALS['va_filters']['vireo_headless_allowed_origins'] = [
	static function ( array $origins ): array {
		$origins[] = 'https://Second.example/';
		$origins[] = 'not an origin';
		return $origins;
	},
];
$GLOBALS['va_settings'] = [ 'headless_origin' => 'https://vireowp.com' ];
ok( [ 'https://vireowp.com', 'https://second.example' ] === Headless::allowed_origins(), 'the filter can add origins; they are normalised and junk is dropped' );
$GLOBALS['va_filters'] = [];

// ---- matching a request Origin against the list ---------------------------

$allowed = [ 'https://vireowp.com', 'http://localhost:4340' ];
ok( 'https://vireowp.com' === Headless::match_origin( 'https://vireowp.com', $allowed ), 'exact match' );
ok( 'https://vireowp.com' === Headless::match_origin( 'https://VIREOWP.com', $allowed ), 'host case does not matter' );
ok( 'https://vireowp.com' === Headless::match_origin( 'https://vireowp.com:443', $allowed ), 'explicit default port still matches' );
ok( '' === Headless::match_origin( 'http://vireowp.com', $allowed ), 'scheme must match' );
ok( '' === Headless::match_origin( 'https://www.vireowp.com', $allowed ), 'a subdomain is a different origin' );
ok( '' === Headless::match_origin( 'https://vireowp.com.evil.example', $allowed ), 'a suffix attack is a different origin' );
ok( '' === Headless::match_origin( 'http://localhost:4341', $allowed ), 'port must match' );
ok( '' === Headless::match_origin( '', $allowed ), 'no origin matches nothing' );
ok( '' === Headless::match_origin( 'https://vireowp.com', [] ), 'an empty list matches nothing' );

// ---- what kind of request is this ------------------------------------------

$own = Headless::own_hosts( [ 'HTTP_HOST' => 'cms.example.com' ] );
ok( in_array( 'cms.example.com', $own, true ), 'the request Host counts as our own' );

$own = Headless::own_hosts( [ 'HTTP_HOST' => 'localhost:8881' ] );
ok( in_array( 'localhost:8881', $own, true ), 'a Host with a port is kept as host:port' );
ok( in_array( 'cms.example.com', $own, true ), 'the home option host counts as our own' );

$allowed = [ 'http://localhost:4340' ];
$own     = [ 'localhost:8881', 'cms.example.com' ];

$r = Headless::classify_request( [ 'HTTP_HOST' => 'localhost:8881' ], $allowed, $own );
ok( 'same' === $r['kind'] && '' === $r['origin'], 'no Origin header is a same-site request, as today' );

$r = Headless::classify_request( [ 'HTTP_HOST' => 'localhost:8881', 'HTTP_ORIGIN' => 'null' ], $allowed, $own );
ok( 'same' === $r['kind'], 'an opaque "null" Origin is left alone, as today' );

$r = Headless::classify_request( [ 'HTTP_HOST' => 'localhost:8881', 'HTTP_ORIGIN' => 'http://localhost:8881' ], $allowed, $own );
ok( 'same' === $r['kind'] && '' === $r['origin'], 'an Origin matching the request Host is same-site' );

$r = Headless::classify_request( [ 'HTTP_HOST' => 'localhost:8881', 'HTTP_ORIGIN' => 'https://cms.example.com' ], $allowed, $own );
ok( 'same' === $r['kind'], 'an Origin matching the home host is same-site, whatever the scheme' );

$r = Headless::classify_request( [ 'HTTP_HOST' => 'localhost:8881', 'HTTP_ORIGIN' => 'http://localhost:4340' ], $allowed, $own );
ok( 'headless' === $r['kind'] && 'http://localhost:4340' === $r['origin'], 'the configured front end is a headless request carrying its origin' );

$r = Headless::classify_request( [ 'HTTP_HOST' => 'localhost:8881', 'HTTP_ORIGIN' => 'http://localhost:4341' ], $allowed, $own );
ok( 'refused' === $r['kind'] && '' === $r['origin'], 'any other origin is refused' );

$r = Headless::classify_request( [ 'HTTP_HOST' => 'localhost:8881', 'HTTP_ORIGIN' => 'http://localhost:4340' ], [], $own );
ok( 'refused' === $r['kind'], 'with nothing configured, a cross-origin beacon is refused' );

$r = Headless::classify_request( [ 'HTTP_HOST' => 'localhost:8881', 'HTTP_ORIGIN' => 'garbage' ], $allowed, $own );
ok( 'same' === $r['kind'], 'an Origin that is not an origin at all is left alone' );

// The same WordPress host on a different port is a different origin, which
// is what lets a dev front end on :4340 talk to a dev WordPress on :8881.
$r = Headless::classify_request( [ 'HTTP_HOST' => 'localhost:8881', 'HTTP_ORIGIN' => 'http://localhost:4340' ], [ 'http://localhost:4340' ], [ 'localhost:8881' ] );
ok( 'headless' === $r['kind'], 'same host, different port, is cross-origin and can be the front end' );

// ---- the headers that go back ----------------------------------------------

$h = Headless::cors_headers( 'http://localhost:4340' );
ok( 'http://localhost:4340' === ( $h['Access-Control-Allow-Origin'] ?? '' ), 'ACAO echoes the one matched origin' );
ok( 'Origin' === ( $h['Vary'] ?? '' ), 'Vary: Origin so caches keep origins apart' );
ok( ! isset( $h['Access-Control-Allow-Credentials'] ), 'credentials are never allowed' );
ok( ! isset( $h['Access-Control-Allow-Methods'] ), 'a plain response carries no preflight headers' );

$h = Headless::cors_headers( 'http://localhost:4340', true );
ok( str_contains( $h['Access-Control-Allow-Methods'] ?? '', 'POST' ), 'preflight lists POST' );
ok( str_contains( $h['Access-Control-Allow-Headers'] ?? '', 'Content-Type' ), 'preflight allows Content-Type' );
ok( '86400' === ( $h['Access-Control-Max-Age'] ?? '' ), 'preflight result is cacheable for a day' );

ok( [] === Headless::cors_headers( '' ), 'no matched origin, no CORS headers' );

// ---- which post a front-end path is ----------------------------------------

$GLOBALS['va_url_map']     = [ 'https://cms.example.com/hello-world/' => 7 ];
$GLOBALS['va_public'] = [
	7 => true,
	9 => true,
];
$GLOBALS['va_cache']       = [];
$GLOBALS['va_url_calls']   = 0;

ok( 7 === Headless::resolve_post_id( '/hello-world/', 0, 'http://localhost:4340' ), 'a path WordPress recognises resolves to its post' );
ok( 7 === Headless::resolve_post_id( '/hello-world/', 9, 'http://localhost:4340' ), 'the path wins over a claimed id when both resolve' );
ok( 9 === Headless::resolve_post_id( '/blog/unknown/', 9, 'http://localhost:4340' ), 'a claimed id is used when the path resolves to nothing and the post is published' );
ok( 0 === Headless::resolve_post_id( '/blog/unknown/', 11, 'http://localhost:4340' ), 'a claimed id for a draft is dropped' );
ok( 0 === Headless::resolve_post_id( '/blog/unknown/', 13, 'http://localhost:4340' ), 'a claimed id for a private post is dropped' );
ok( 0 === Headless::resolve_post_id( '/blog/unknown/', 15, 'http://localhost:4340' ), 'a claimed id for a scheduled post is dropped' );
ok( 0 === Headless::resolve_post_id( '/blog/unknown/', 17, 'http://localhost:4340' ), 'a claimed id for a reusable block is dropped' );
$GLOBALS['va_url_map']['https://cms.example.com/secret-draft/'] = 11;
ok( 0 === Headless::resolve_post_id( '/secret-draft/', 0, 'http://localhost:4340' ), 'a path that resolves to a non-public post is stored as a path' );
ok( 0 === Headless::resolve_post_id( '/blog/unknown/', 12345, 'http://localhost:4340' ), 'a claimed id for no post is dropped' );
ok( 0 === Headless::resolve_post_id( '/blog/unknown/', 0, 'http://localhost:4340' ), 'nothing resolves, nothing claimed, stays a plain path' );
ok( 0 === Headless::resolve_post_id( '/', 0, 'http://localhost:4340' ), 'the front page is a path, not a post' );

$calls = $GLOBALS['va_url_calls'];
Headless::resolve_post_id( '/hello-world/', 0, 'http://localhost:4340' );
Headless::resolve_post_id( '/hello-world/', 0, 'http://localhost:4340' );
ok( $GLOBALS['va_url_calls'] === $calls, 'repeat lookups of one path are served from cache' );

$GLOBALS['va_filters']['vireo_headless_post_id'] = [
	static function ( int $post_id, string $path, string $site, int $claimed ): int {
		return str_starts_with( $path, '/docs/' ) ? 9 : $post_id;
	},
];
ok( 9 === Headless::resolve_post_id( '/docs/anything/', 0, 'http://localhost:4340' ), 'the filter can map paths the front end spells differently' );
ok( 7 === Headless::resolve_post_id( '/hello-world/', 0, 'http://localhost:4340' ), 'the filter sees the resolved id for everything else' );
$GLOBALS['va_filters'] = [];

// ---- the snippet shown in settings -----------------------------------------

$snippet = Headless::snippet( 'https://cms.example.com/wp-content/plugins/vireo-analytics/endpoint.php', 'https://cms.example.com/wp-content/plugins/vireo-analytics/assets/js/tracker.js' );
ok( str_contains( $snippet, 'data-endpoint="https://cms.example.com/wp-content/plugins/vireo-analytics/endpoint.php"' ), 'snippet names the endpoint' );
ok( str_contains( $snippet, 'src="https://cms.example.com/wp-content/plugins/vireo-analytics/assets/js/tracker.js"' ), 'snippet loads the tracker from WordPress' );
ok( str_contains( $snippet, ' defer' ), 'snippet defers so document.currentScript is set' );

echo $fail ? "\n$fail failure(s)\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
