<?php
/**
 * Shared harness for the AI visibility scan tests: assertion helpers, the few
 * WordPress functions the scan classes call, transients in memory, and a fake
 * web behind wp_safe_remote_get().
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}
if ( ! defined( 'VIREO_DIR' ) ) {
	define( 'VIREO_DIR', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

$fail = 0;
$ran  = 0;

function ok( $cond, $msg ) {
	global $fail, $ran;
	++$ran;
	if ( $cond ) {
		echo "  ok  $msg\n";
	} else {
		echo "  FAIL  $msg\n";
		++$fail;
	}
}

function same( $expected, $actual, $msg ) {
	ok( $expected === $actual, $msg . ( $expected === $actual ? '' : ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')' ) );
}

function has( $needle, $haystack, $msg ) {
	ok( false !== strpos( (string) $haystack, (string) $needle ), $msg );
}

function finish() {
	global $fail, $ran;
	echo "\n$ran assertions, " . ( $ran - $fail ) . " passed, $fail failed\n";
	exit( $fail ? 1 : 0 );
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) { return $text; }
}
if ( ! function_exists( '_n' ) ) {
	function _n( $single, $plural, $number, $domain = 'default' ) { return 1 === (int) $number ? $single : $plural; }
}
if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $n ) { return number_format( (float) $n ); }
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
}
if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( $length = 12, $special = true ) { return substr( bin2hex( random_bytes( 32 ) ), 0, $length ); }
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) { return $value; }
}

$GLOBALS['va_test_transients'] = [];
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) { return $GLOBALS['va_test_transients'][ $key ] ?? false; }
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $ttl = 0 ) { $GLOBALS['va_test_transients'][ $key ] = $value; return true; }
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) { unset( $GLOBALS['va_test_transients'][ $key ] ); return true; }
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $message;
		public function __construct( $code = '', $message = '' ) { $this->message = $message; }
		public function get_error_message() { return $this->message; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
}

// $GLOBALS['va_test_http']['routes'][ $url ] holds a response and every URL
// actually requested is appended to ['log'], with the headers it was sent.
// Like core, the safe variant rejects private and loopback addresses.
if ( ! function_exists( 'wp_safe_remote_get' ) ) {
	function wp_safe_remote_get( $url, $args = [] ) {
		if ( preg_match( '#^https?://(127\.|10\.|0\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)#', $url ) ) {
			return new WP_Error( 'http_request_failed', 'A valid URL was not provided.' );
		}
		$GLOBALS['va_test_http']['log'][]     = $url;
		$GLOBALS['va_test_http']['sent'][]    = [ 'url' => $url, 'args' => $args ];
		$route = $GLOBALS['va_test_http']['routes'][ $url ] ?? [ 'status' => 404 ];
		return [
			'headers'  => array_change_key_case( (array) ( $route['headers'] ?? [] ) ),
			'body'     => (string) ( $route['body'] ?? '' ),
			'response' => [ 'code' => (int) ( $route['status'] ?? 200 ) ],
		];
	}
	function wp_remote_retrieve_headers( $response ) { return $response['headers'] ?? []; }
	function wp_remote_retrieve_response_code( $response ) { return $response['response']['code'] ?? ''; }
	function wp_remote_retrieve_body( $response ) { return $response['body'] ?? ''; }
}
