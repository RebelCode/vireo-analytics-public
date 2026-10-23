<?php
/**
 * Zero-dependency assertion runner for PageSpeed.
 * Run: php tests/test-pagespeed.php
 *
 * Covers the three things that keep the lab test honest: the browser cannot
 * point it at a page that is not this site's, Google's answer is read into the
 * numbers the dashboard shows, and a page tested once is not tested again
 * within the day unless someone asks.
 *
 * fixtures/psi-vireowp-mobile.json is a Lighthouse 13.5 run of vireowp.com
 * (mobile, performance only) in the runPagespeed response envelope, with the
 * screenshot and network-trace audits removed for size.
 * fixtures/psi-429-keyless.json is Google's live answer to a request without
 * an API key. fixtures/psi-live-vireowp-{mobile,desktop}.json are real
 * runPagespeed answers for vireowp.com (2026-10-09), trimmed the same way.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}
if ( ! defined( 'VIREO_DIR' ) ) {
	define( 'VIREO_DIR', dirname( __DIR__ ) . '/' );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;
		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}
		public function get_error_code() {
			return $this->code;
		}
		public function get_error_message() {
			return $this->message;
		}
	}
}
if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public $ID = 0;
	}
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function __( $s, $d = null ) {
	return $s;
}
function number_format_i18n( $n, $decimals = 0 ) {
	return number_format( (float) $n, $decimals );
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}
function apply_filters( $hook, $value ) {
	return $value;
}
function home_url( $path = '' ) {
	return 'https://blog.example.com' . $path;
}

$GLOBALS['va_options'] = [
	'vireo_connected_sites' => [
		'next'  => 3,
		'sites' => [
			2 => [
				'id'     => 2,
				'name'   => 'Docs',
				'origin' => 'https://docs.example.org:8443',
				'key'    => str_repeat( 'a', 32 ),
			],
		],
	],
];
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

$GLOBALS['va_transients'] = [];
function get_transient( $key ) {
	return $GLOBALS['va_transients'][ $key ] ?? false;
}
function set_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['va_transients'][ $key ] = $value;
	return true;
}
function delete_transient( $key ) {
	unset( $GLOBALS['va_transients'][ $key ] );
	return true;
}

// Post 7 is published, 8 is a draft its viewer wrote, 9 does not exist.
$GLOBALS['va_posts'] = [ 7 => true, 8 => false ];
function get_post( $id ) {
	if ( ! isset( $GLOBALS['va_posts'][ (int) $id ] ) ) {
		return null;
	}
	$p     = new WP_Post();
	$p->ID = (int) $id;
	return $p;
}
function is_post_publicly_viewable( $post ) {
	return ! empty( $GLOBALS['va_posts'][ is_object( $post ) ? $post->ID : (int) $post ] );
}
function current_user_can( $cap, $id = 0 ) {
	return true;
}
function get_permalink( $post ) {
	return 'https://blog.example.com/?p=' . $post->ID;
}

// The network. Every call is recorded; the reply is whatever the test set.
$GLOBALS['va_http_calls'] = [];
$GLOBALS['va_http_reply'] = null;
function wp_remote_get( $url, $args = [] ) {
	$GLOBALS['va_http_calls'][] = $url;
	return $GLOBALS['va_http_reply'];
}
function wp_remote_retrieve_response_code( $r ) {
	return $r['response']['code'] ?? 0;
}
function wp_remote_retrieve_body( $r ) {
	return $r['body'] ?? '';
}
function get_user_locale() {
	return 'pt_BR';
}

require __DIR__ . '/../includes/class-sites.php';
require __DIR__ . '/../includes/class-vitals.php';
require __DIR__ . '/../includes/class-post-detail.php';
require __DIR__ . '/../includes/class-pagespeed.php';

use VireoAnalytics\PageSpeed;

$fail = 0;
$pass = 0;
function ok( $cond, $label ) {
	global $fail, $pass;
	if ( $cond ) {
		$pass++;
		echo "ok   - $label\n";
	} else {
		$fail++;
		echo "FAIL - $label\n";
	}
}

$fixture = (string) file_get_contents( __DIR__ . '/fixtures/psi-vireowp-mobile.json' );
$quota   = (string) file_get_contents( __DIR__ . '/fixtures/psi-429-keyless.json' );
ok( strlen( $fixture ) > 10000 && strlen( $quota ) > 100, 'both fixtures loaded' );

// ---- which URLs may be tested ----------------------------------------------

$allowed = PageSpeed::allowed_hosts();
ok( [ 'blog.example.com', 'docs.example.org:8443' ] === $allowed, 'allowed hosts are this site and the connected site, with its port' );

$cases = [
	[ 'https://blog.example.com/hello/', true, 'a page on this site' ],
	[ 'http://blog.example.com/', true, 'this site over http' ],
	[ 'https://blog.example.com:443/x', true, 'the default port spelled out' ],
	[ 'https://BLOG.example.com/x', true, 'host case does not matter' ],
	[ 'https://docs.example.org:8443/guide', true, 'a page on the connected site' ],
	[ 'https://docs.example.org/guide', false, 'the connected site on the wrong port' ],
	[ 'https://evil.example.net/', false, 'another host' ],
	[ 'https://blog.example.com.evil.net/', false, 'a host that starts like ours' ],
	[ 'https://evil.net/?u=https://blog.example.com/', false, 'our host in the query string' ],
	[ 'https://user:pw@blog.example.com/', false, 'userinfo' ],
	[ 'javascript://blog.example.com/%0Aalert(1)', false, 'a javascript: URL' ],
	[ 'ftp://blog.example.com/', false, 'another scheme' ],
	[ '//blog.example.com/', false, 'a scheme-relative URL' ],
	[ '', false, 'nothing' ],
];
foreach ( $cases as [ $url, $expect, $label ] ) {
	$got = PageSpeed::validate_url( $url, $allowed );
	ok( $expect === ! is_wp_error( $got ), ( $expect ? 'accepts ' : 'refuses ' ) . $label );
}
ok( 'https://blog.example.com/a' === PageSpeed::validate_url( 'https://blog.example.com/a#top', $allowed ), 'the fragment is dropped' );
ok( is_wp_error( PageSpeed::validate_url( 'https://blog.example.com/', [] ) ), 'control: with no allowed hosts, our own page is refused' );

ok( 'https://blog.example.com/' === PageSpeed::target_url( 'home', 0 ), 'home target is this site' );
ok( 'https://docs.example.org:8443/' === PageSpeed::target_url( 'home', 2 ), 'home target for a connected site is its origin' );
ok( is_wp_error( PageSpeed::target_url( 'home', 99 ) ), 'a site that is not connected is refused' );
ok( 'https://blog.example.com/?p=7' === PageSpeed::target_url( 'post', 7 ), 'a published post is its permalink' );
ok( 'vireo_psi_not_public' === PageSpeed::target_url( 'post', 8 )->get_error_code(), 'a draft is refused, Google could not load it' );
ok( is_wp_error( PageSpeed::target_url( 'post', 9 ) ), 'a missing post is refused' );
ok( is_wp_error( PageSpeed::target_url( 'url', 0 ) ), 'an unknown target is refused' );

foreach ( [ 'localhost', 'mysite.local', 'wp.test', '127.0.0.1', '10.0.0.4', '192.168.1.20', 'devbox', '[::1]' ] as $host ) {
	ok( PageSpeed::is_local_host( $host ), "local: $host" );
}
foreach ( [ 'blog.example.com', '8.8.8.8', 'vireowp.com' ] as $host ) {
	ok( ! PageSpeed::is_local_host( $host ), "public: $host" );
}

$req = PageSpeed::request_url( 'https://blog.example.com/a?b=1&c=2', 'mobile', 'KEY_123', 'pt-BR' );
parse_str( (string) parse_url( $req, PHP_URL_QUERY ), $q );
ok( 0 === strpos( $req, PageSpeed::API . '?' ), 'request goes to the runPagespeed endpoint' );
ok( 'https://blog.example.com/a?b=1&c=2' === $q['url'], 'the page URL survives encoding, query string included' );
ok( 'MOBILE' === $q['strategy'] && 'PERFORMANCE' === $q['category'] && 'KEY_123' === $q['key'] && 'pt-BR' === $q['locale'], 'strategy, category, key and locale are sent' );

// ---- reading Google's answer ------------------------------------------------

$r = PageSpeed::interpret( 200, $fixture );
ok( is_array( $r ), 'the fixture parses' );
ok( 95 === $r['score'], 'performance score is 0.95 as 95' );
ok( abs( $r['metrics']['lcp'] - 2657.828 ) < 0.01, 'LCP read in milliseconds' );
ok( abs( $r['metrics']['fcp'] - 1891.5795 ) < 0.01, 'FCP read in milliseconds' );
ok( 0.0 === $r['metrics']['tbt'] && 0.0 === $r['metrics']['cls'], 'TBT and CLS of zero are kept as zero, not dropped' );
ok( '13.5.0' === $r['lighthouse'], 'Lighthouse version kept' );

$ids = array_column( $r['opportunities'], 'id' );
ok( 4 === count( $ids ), 'four suggestions with a saving (got ' . count( $ids ) . ')' );
ok( [ 'legacy-javascript-insight', 'render-blocking-insight', 'cache-insight', 'image-delivery-insight' ] === $ids, 'ordered by estimated time saved, the byte-only one last' );
ok( 300 === $r['opportunities'][0]['ms'] && 'Legacy JavaScript' === $r['opportunities'][0]['title'], 'top suggestion carries Google\'s title and its saving' );
ok( "Est savings of 17\u{a0}KiB" === $r['opportunities'][3]['display'], 'Google\'s own estimate text is kept, non-breaking space and all' );
ok( ! in_array( 'unused-javascript', $ids, true ), 'a passed audit is not a suggestion' );
ok( ! in_array( 'layout-shifts', $ids, true ), 'a not-applicable audit is not a suggestion' );
ok( strlen( serialize( $r ) ) < 4000, 'the stored result is small (' . strlen( serialize( $r ) ) . ' bytes)' );

// Real runPagespeed answers, one per device. Same shape as the local run;
// these guard against Google's envelope drifting from it.
$live_m = PageSpeed::interpret( 200, (string) file_get_contents( __DIR__ . '/fixtures/psi-live-vireowp-mobile.json' ) );
$live_d = PageSpeed::interpret( 200, (string) file_get_contents( __DIR__ . '/fixtures/psi-live-vireowp-desktop.json' ) );
ok( is_array( $live_m ) && is_array( $live_d ), 'both live responses parse' );
ok( 97 === $live_m['score'] && 100 === $live_d['score'], 'live scores read: 97 mobile, 100 desktop' );
ok( abs( $live_m['metrics']['lcp'] - 2280.72 ) < 0.01 && abs( $live_d['metrics']['lcp'] - 477 ) < 0.01, 'live LCP read for each device' );
ok( abs( $live_d['metrics']['cls'] - 0.0187 ) < 0.0001 && abs( $live_m['metrics']['fcp'] - 1677.43 ) < 0.01, 'live CLS and FCP read' );
ok( 'https://vireowp.com/' === $live_m['final_url'] && '13.5.0' === $live_m['lighthouse'], 'live final URL and Lighthouse version read' );
ok( [ 'render-blocking-insight', 'legacy-javascript-insight', 'cache-insight', 'image-delivery-insight' ] === array_column( $live_m['opportunities'], 'id' ), 'live mobile suggestions: four, largest time saving first' );
ok( 350 === $live_m['opportunities'][0]['ms'], 'live saving is the largest of the per-metric estimates' );
ok( 3 === count( $live_d['opportunities'] ) && ! in_array( 'render-blocking-insight', array_column( $live_d['opportunities'], 'id' ), true ), 'live desktop: a failing insight with no estimate at all is left out' );

// More than five candidates: only five come back, biggest first.
$many = json_decode( $fixture, true );
foreach ( range( 1, 8 ) as $i ) {
	$many['lighthouseResult']['audits'][ "fake-$i" ] = [
		'id'               => "fake-$i",
		'title'            => "Fake `$i`",
		'score'            => 0,
		'scoreDisplayMode' => 'metricSavings',
		'metricSavings'    => [ 'LCP' => $i * 1000 ],
	];
	$many['lighthouseResult']['categories']['performance']['auditRefs'][] = [ 'id' => "fake-$i", 'group' => 'insights' ];
}
$top = PageSpeed::parse( $many )['opportunities'];
ok( 5 === count( $top ) && 'fake-8' === $top[0]['id'] && 'fake-4' === $top[4]['id'], 'capped at five, largest saving first' );
ok( 'Fake 8' === $top[0]['title'], 'markdown backticks are stripped from titles' );

// Lighthouse 12 style: an opportunity with overallSavingsMs and no metricSavings.
$legacy = json_decode( $fixture, true );
$legacy['lighthouseResult']['audits']['render-blocking-resources'] = [
	'id'               => 'render-blocking-resources',
	'title'            => 'Eliminate render-blocking resources',
	'score'            => 0.2,
	'scoreDisplayMode' => 'metricSavings',
	'details'          => [ 'type' => 'opportunity', 'overallSavingsMs' => 1250 ],
];
$legacy['lighthouseResult']['categories']['performance']['auditRefs'][] = [ 'id' => 'render-blocking-resources', 'group' => 'load-opportunities' ];
ok( 'render-blocking-resources' === PageSpeed::parse( $legacy )['opportunities'][0]['id'], 'older opportunities with overallSavingsMs still rank' );

$broken = json_decode( $fixture, true );
$broken['lighthouseResult']['runtimeError'] = [ 'code' => 'FAILED_DOCUMENT_REQUEST', 'message' => 'x' ];
ok( 'vireo_psi_unreachable' === PageSpeed::parse( $broken )->get_error_code(), 'a runtime error is a plain error, not a score of nothing' );

ok( 'vireo_psi_shape' === PageSpeed::interpret( 200, '{"kind":"pagespeedonline#result"}' )->get_error_code(), 'control: a 200 without lighthouseResult is refused' );
ok( 'vireo_psi_shape' === PageSpeed::interpret( 200, '<html>' )->get_error_code(), 'control: a 200 that is not JSON is refused' );
ok( 'vireo_psi_quota' === PageSpeed::interpret( 429, $quota )->get_error_code(), 'Google\'s live 429 reads as the quota message' );
ok( 'vireo_psi_key' === PageSpeed::interpret( 400, '{"error":{"code":400,"message":"API key not valid. Please pass a valid API key.","errors":[{"reason":"badRequest"}]}}' )->get_error_code(), 'a bad key is named as such' );
ok( 'vireo_psi_forbidden' === PageSpeed::interpret( 403, '{"error":{"code":403,"message":"PageSpeed Insights API has not been used in project"}}' )->get_error_code(), 'a key without the API enabled is named as such' );
ok( 'vireo_psi_unreachable' === PageSpeed::interpret( 500, '{"error":{"code":500,"message":"Lighthouse returned error: FAILED_DOCUMENT_REQUEST."}}' )->get_error_code(), 'a page Lighthouse could not load' );
ok( 'vireo_psi_http' === PageSpeed::interpret( 503, '' )->get_error_code(), 'anything else is an HTTP error with its status' );
ok( 'vireo_psi_local' === PageSpeed::interpret( 400, '{"error":{"code":400}}', true )->get_error_code(), 'a failure on a local site says why' );

// ---- caching ----------------------------------------------------------------

$url = 'https://blog.example.com/hello/';
$GLOBALS['va_http_reply'] = [ 'response' => [ 'code' => 200 ], 'body' => $fixture ];

$first = PageSpeed::run( $url, 'mobile' );
ok( is_wp_error( $first ) && 'vireo_psi_no_key' === $first->get_error_code() && 0 === count( $GLOBALS['va_http_calls'] ), 'without a key, nothing is sent' );

// Without a key the dashboard shows a link to pagespeed.web.dev: the URL is
// worked out and checked, but nothing is fetched and no stored result is read.
$home = 'https://blog.example.com/';
$GLOBALS['va_transients'][ PageSpeed::cache_key( $home, 'mobile' ) ] = [ 'url' => $home, 'strategy' => 'mobile', 'score' => 50 ];
$view = PageSpeed::view( 'home', 0 );
ok( $home === $view['url'] && '' === $view['error'], 'no key: the view still has the checked homepage URL for the link' );
ok( null === $view['results']['mobile'] && 0 === count( $GLOBALS['va_http_calls'] ), 'no key: no stored result is shown and nothing is sent' );
ok( 0 === strpos( PageSpeed::report_url( $view['url'], 'mobile' ), 'https://pagespeed.web.dev/analysis?url=' ), 'no key: the link goes to pagespeed.web.dev' );
ok( '' !== PageSpeed::view( 'post', 8 )['error'], 'no key: a draft still gets no link' );

PageSpeed::save_key( 'TESTKEY_not-a-real-key_0123456789' );
ok( 50 === PageSpeed::view( 'home', 0 )['results']['mobile']['score'], 'control: with a key, the stored result is shown' );
unset( $GLOBALS['va_transients'][ PageSpeed::cache_key( $home, 'mobile' ) ] );
ok( is_wp_error( PageSpeed::save_key( 'not a key' ) ), 'a malformed key is refused' );
ok( PageSpeed::has_key(), 'the good key was kept' );

$first = PageSpeed::run( $url, 'mobile' );
ok( is_array( $first ) && 1 === count( $GLOBALS['va_http_calls'] ), 'first run calls Google once' );
ok( false !== strpos( $GLOBALS['va_http_calls'][0], 'key=TESTKEY_not-a-real-key_0123456789' ), 'the stored key is sent' );
ok( $url === $first['url'] && 'mobile' === $first['strategy'] && $first['fetched'] > 0, 'the result records page, device and time' );
ok( null !== PageSpeed::cached( $url, 'mobile' ), 'the result is cached' );
ok( [] === array_filter( array_keys( $GLOBALS['va_transients'] ), static function ( $k ) { return '_lock' === substr( $k, -5 ); } ), 'the run lock is released' );

$second = PageSpeed::run( $url, 'mobile' );
ok( 1 === count( $GLOBALS['va_http_calls'] ) && $second === $first, 'a second run within the day is served from the cache' );

PageSpeed::run( $url, 'desktop' );
ok( 2 === count( $GLOBALS['va_http_calls'] ), 'control: the other device is a separate test' );

PageSpeed::run( 'https://blog.example.com/other/', 'mobile' );
ok( 3 === count( $GLOBALS['va_http_calls'] ), 'control: another page is a separate test' );

PageSpeed::run( $url, 'mobile', true );
ok( 4 === count( $GLOBALS['va_http_calls'] ), '"Run again" skips the cache' );

$GLOBALS['va_transients'][ PageSpeed::cache_key( 'https://blog.example.com/busy/', 'mobile' ) . '_lock' ] = 1;
$busy = PageSpeed::run( 'https://blog.example.com/busy/', 'mobile' );
ok( 'vireo_psi_busy' === $busy->get_error_code() && 4 === count( $GLOBALS['va_http_calls'] ), 'a test already running is not started twice' );

$GLOBALS['va_http_reply'] = [ 'response' => [ 'code' => 429 ], 'body' => $quota ];
$err = PageSpeed::run( 'https://blog.example.com/quota/', 'mobile' );
ok( is_wp_error( $err ) && null === PageSpeed::cached( 'https://blog.example.com/quota/', 'mobile' ), 'an error is not cached' );

$GLOBALS['va_http_reply'] = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 60001 milliseconds' );
ok( 'vireo_psi_timeout' === PageSpeed::run( 'https://blog.example.com/slow/', 'mobile' )->get_error_code(), 'a timeout says so' );

$GLOBALS['va_transients'][ PageSpeed::cache_key( $url, 'mobile' ) ]['url'] = 'https://blog.example.com/elsewhere/';
ok( null === PageSpeed::cached( $url, 'mobile' ), 'a cached entry for a different page is ignored' );

// ---- display helpers --------------------------------------------------------

ok( 'good' === PageSpeed::score_rating( 90 ) && 'needs-improvement' === PageSpeed::score_rating( 89 ) && 'poor' === PageSpeed::score_rating( 49 ), 'score bands match Lighthouse' );
ok( 'good' === PageSpeed::lab_rating( 'cls', 0.1 ) && 'poor' === PageSpeed::lab_rating( 'cls', 0.26 ), 'CLS graded as a ratio' );
ok( 'needs-improvement' === PageSpeed::lab_rating( 'tbt', 350 ), 'TBT graded on the lab thresholds' );
ok( '2.7s' === PageSpeed::lab_display( 'lcp', 2657.8 ) && '120ms' === PageSpeed::lab_display( 'tbt', 120 ) && '0.05' === PageSpeed::lab_display( 'cls', 0.05 ), 'values display in the units the field card uses' );
ok( 'Up to 300ms faster' === PageSpeed::savings_label( [ 'ms' => 300 ] ) && 'Up to 1.3s faster' === PageSpeed::savings_label( [ 'ms' => 1250 ] ), 'savings read in ms under a second, seconds above' );
ok( false !== strpos( PageSpeed::report_url( 'https://blog.example.com/a?b=1', 'desktop' ), 'url=https%3A%2F%2Fblog.example.com%2Fa%3Fb%3D1&form_factor=desktop' ), 'full report link encodes the page and device' );

echo "\n$pass passed";
echo $fail ? ", $fail failure(s)\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
