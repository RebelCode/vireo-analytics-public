<?php
/**
 * Child process for tests/test-ai-scan-self-hit.php. Runs the real
 * vireo_collect_request() once, with everything that would touch WordPress or
 * disk replaced by recorders, and prints what the endpoint did, one marker per
 * line. It has to be a separate process because the endpoint ends in exit().
 *
 * argv: user agent, scan token header ('' for none), 'pixel' or 'beacon'.
 */

namespace {

error_reporting( 0 );

define( 'ABSPATH', __DIR__ );
define( 'VIREO_DIR', dirname( __DIR__, 2 ) . '/' );
define( 'MINUTE_IN_SECONDS', 60 );

$ua    = $argv[1] ?? '';
$token = $argv[2] ?? '';
$mode  = $argv[3] ?? 'beacon';
$live  = $argv[4] ?? '';

$_SERVER['HTTP_USER_AGENT']  = $ua;
$_SERVER['REQUEST_METHOD']   = 'pixel' === $mode ? 'GET' : 'POST';
if ( '' !== $token ) {
	$_SERVER['HTTP_X_VIREO_SCAN'] = $token;
}
$_GET = 'pixel' === $mode ? [ 'kind' => 'pageview', 'nojs' => '1', 'path' => '/about/' ] : [];
$_POST = 'pixel' === $mode ? [] : [ 'kind' => 'pageview', 'path' => '/about/' ];

function say( $line ) { echo $line . "\n"; }

// WordPress functions the endpoint and Bot_Filter call.
function get_transient( $key ) { global $live; return 'vireo_ai_scan_token' === $key && '' !== $live ? $live : false; }
function apply_filters( $hook, $value ) { return $value; }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function wp_unslash( $s ) { return $s; }
function status_header( $code ) { say( 'STATUS ' . $code ); }
function nocache_headers() {}

// Vireo helpers, in place of va-filters.php.
function vireo_visitor_opted_out( $server ) { return false; }
function vireo_get_client_ip() { return '203.0.113.9'; }
function vireo_beacon_rate_limit_ok( $ip ) { return true; }
function vireo_get_settings() { return []; }
function vireo_parse_lines( $text ) { return []; }
function vireo_match_goals( $x ) {}

}

namespace VireoAnalytics {

final class Headless {
	public static function classify_request() { return [ 'kind' => 'same', 'origin' => '' ]; }
	public static function allowed_origins() { return []; }
	public static function own_hosts() { return []; }
	public static function set_response_origin( $o ) {}
	public static function response_origin() { return ''; }
	public static function send_cors_headers( $preflight = false ) {}
}
final class Sites {}
final class Geo_Lookup { public static function country_from_headers( $s ) { return ''; } }
final class Salt_Manager {
	public static function instance() { return new self(); }
	public function get_salts() { return [ 'current' => 'salt' ]; }
	public static function hash_with_salt( $ip, $ua, $salt ) { return 'hash'; }
}
final class Collector {
	public static function append_pageview( $p ) { \say( 'APPEND_PAGEVIEW' ); return true; }
	public static function append_bot( $b ) { \say( 'APPEND_BOT ' . $b['category'] ); return true; }
	public static function append_event( $e ) { \say( 'APPEND_EVENT' ); return true; }
}

}

namespace {

require VIREO_DIR . 'includes/class-bot-filter.php';
require VIREO_DIR . 'vireo-analytics-collect.php';

register_shutdown_function( static function () { \say( "\nEND" ); } );
\vireo_collect_request();

}
