<?php
/**
 * The AI visibility scan asks the site for its own pages as GPTBot, ClaudeBot
 * and PerplexityBot. Vireo records those user agents under the `ai` category,
 * so without a guard a scan would add itself to the crawler figures it sits
 * beside. Each request carries a per-scan token; every place that counts a
 * request has to recognise it.
 * Run: php tests/test-ai-scan-self-hit.php
 *
 * Every "left out" assertion is paired with a control that sends the same
 * request without the token and is counted, so a guard that never fires and a
 * counter that never counts cannot both pass.
 */

require __DIR__ . '/ai-scan-stubs.php';
require __DIR__ . '/../includes/class-bot-filter.php';
require __DIR__ . '/../includes/class-ai-scan-robots-too-complex.php';
require __DIR__ . '/../includes/class-ai-scan-robots.php';
require __DIR__ . '/../includes/class-ai-scan-page-parser.php';
require __DIR__ . '/../includes/class-ai-scan-checks.php';
require __DIR__ . '/../includes/class-ai-scan.php';

use VireoAnalytics\AI_Scan;
use VireoAnalytics\Bot_Filter;

$gpt     = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.1; +https://openai.com/gptbot';
$browser = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130 Safari/537.36 VireoSiteScan';
$token   = 'abc123def456';

// ---- The token ------------------------------------------------------------

same( 'ai', Bot_Filter::classify( $gpt, [] ), 'control: the scan\'s GPTBot user agent is an AI crawler to the bot filter' );

$with = [ 'HTTP_X_VIREO_SCAN' => $token ];

ok( ! Bot_Filter::is_scan_request( $with ), 'no scan running: a header alone proves nothing' );
set_transient( Bot_Filter::SCAN_TOKEN_TRANSIENT, $token, 300 );
ok( Bot_Filter::is_scan_request( $with ), 'scan running: the right token is recognised' );
ok( ! Bot_Filter::is_scan_request( [ 'HTTP_X_VIREO_SCAN' => 'wrong' ] ), 'a wrong token is not' );
ok( ! Bot_Filter::is_scan_request( [ 'HTTP_X_VIREO_SCAN' => '' ] ), 'an empty token is not' );
ok( ! Bot_Filter::is_scan_request( [] ), 'no header is not' );
delete_transient( Bot_Filter::SCAN_TOKEN_TRANSIENT );
ok( ! Bot_Filter::is_scan_request( $with ), 'once the scan ends the token stops working' );

// ---- The shared exclusion gate (404 tracker, form events, Pro trackers) -------

set_transient( Bot_Filter::SCAN_TOKEN_TRANSIENT, $token, 300 );
$req = [ 'ua' => $browser, 'ip' => '', 'path' => '/llms.txt' ];
ok( ! Bot_Filter::request_is_excluded( $req, [] ), 'control: the scan\'s browser-like request with no token passes the gate as a visitor' );
ok( Bot_Filter::request_is_excluded( $req, $with ), 'with the token it is excluded (its 404 for /llms.txt is not a missing page)' );
ok( Bot_Filter::request_is_excluded( [ 'ua' => $gpt ] + $req, $with ), 'a scan request made as GPTBot is excluded too' );
delete_transient( Bot_Filter::SCAN_TOKEN_TRANSIENT );

// ---- Counting a page request as a crawler (Script_Loader::record_bot_request) --

require __DIR__ . '/fixtures/ai-scan-collector-stub.php';

	function is_admin() { return false; }
	function add_action() {}
	function add_filter() {}
	function wp_doing_ajax() { return false; }
	function wp_doing_cron() { return false; }
	function sanitize_text_field( $s ) { return trim( (string) $s ); }
	function wp_unslash( $s ) { return $s; }

	require __DIR__ . '/../includes/class-script-loader.php';

	$loader = \VireoAnalytics\Script_Loader::instance();

	$_SERVER['REQUEST_METHOD'] = 'GET';
	$_SERVER['HTTP_USER_AGENT'] = $gpt;
	unset( $_SERVER['HTTP_X_VIREO_SCAN'] );
	\VireoAnalytics\Collector::$bots = [];
	$loader->record_bot_request();
	same( [ 'ai' ], \VireoAnalytics\Collector::$bots, 'control: a GPTBot page request with no token is counted as an AI crawler' );

	set_transient( Bot_Filter::SCAN_TOKEN_TRANSIENT, $token, 300 );
	$_SERVER['HTTP_X_VIREO_SCAN'] = $token;
	\VireoAnalytics\Collector::$bots = [];
	$loader->record_bot_request();
	same( [], \VireoAnalytics\Collector::$bots, 'the same request carrying the scan token is not counted' );

	foreach ( [ 'ClaudeBot/1.0; +claudebot@anthropic.com', 'PerplexityBot/1.0; +https://perplexity.ai/perplexitybot' ] as $agent ) {
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; ' . $agent . ')';
		$loader->record_bot_request();
	}
	same( [], \VireoAnalytics\Collector::$bots, 'nor are its ClaudeBot and PerplexityBot requests' );

	$_SERVER['HTTP_X_VIREO_SCAN'] = 'stale-token';
	$_SERVER['HTTP_USER_AGENT']   = $gpt;
	$loader->record_bot_request();
	same( [ 'ai' ], \VireoAnalytics\Collector::$bots, 'a crawler that copies the header with a wrong token is still counted' );
	delete_transient( Bot_Filter::SCAN_TOKEN_TRANSIENT );
	unset( $_SERVER['HTTP_X_VIREO_SCAN'] );

	// ---- The beacon and the no-JS pixel, through the real endpoint code -------

	$child = __DIR__ . '/fixtures/ai-scan-collect-child.php';
	$run   = static function ( string $ua, string $header, string $mode, string $live ) use ( $child ): array {
		$out = [];
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $child ) . ' ' . escapeshellarg( $ua ) . ' ' . escapeshellarg( $header ) . ' ' . escapeshellarg( $mode ) . ' ' . escapeshellarg( $live ) . ' 2>&1', $out );
		return array_values( array_filter( array_map( 'trim', $out ), static fn( $l ) => 1 === preg_match( '/^(APPEND_|STATUS|END)/', $l ) ) );
	};

	$chrome = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130 Safari/537.36';

	foreach ( [ 'pixel' => 'the no-JS pixel', 'beacon' => 'the JS beacon' ] as $mode => $label ) {
		$lines = $run( $chrome, '', $mode, '' );
		ok( in_array( 'APPEND_PAGEVIEW', $lines, true ) && in_array( 'END', $lines, true ), "control: $label from a visitor with no token records a pageview" );

		$lines = $run( $chrome, 'scan-token', $mode, 'scan-token' );
		ok( ! in_array( 'APPEND_PAGEVIEW', $lines, true ) && in_array( 'STATUS 200', $lines, true ) && in_array( 'END', $lines, true ), "$label carrying the scan token records nothing and still answers 200" );

		$lines = $run( $chrome, 'scan-token', $mode, '' );
		ok( in_array( 'APPEND_PAGEVIEW', $lines, true ), "$label with a token but no scan running is a visitor" );

		$lines = $run( $chrome, 'forged', $mode, 'scan-token' );
		ok( in_array( 'APPEND_PAGEVIEW', $lines, true ), "$label with a forged token is a visitor" );
	}

	// ---- A live scan: tokened requests, and the token gone afterwards -----------

	$GLOBALS['va_test_http'] = [
		'routes' => [
			'https://example.com/' => [ 'status' => 200, 'body' => '<html lang="en"><body><h1>Hi</h1><p>' . str_repeat( 'text ', 320 ) . '</p></body></html>' ],
		],
		'log'    => [],
		'sent'   => [],
	];
	$seen_during = [];
	$result      = ( new AI_Scan() )->run( 'https://example.com/', true );
	foreach ( $GLOBALS['va_test_http']['sent'] as $sent ) {
		$seen_during[] = $sent['args']['headers'][ AI_Scan::HEADER ] ?? '';
	}
	$sent_count = count( $seen_during );
	ok( $sent_count >= 8, "the scan made real requests ($sent_count)" );
	ok( '' !== $seen_during[0] && 1 === count( array_unique( $seen_during ) ), 'every one carried the same non-empty token' );
	same( 'X-Vireo-Scan', AI_Scan::HEADER, 'the header is named for Vireo' );
	same( false, get_transient( Bot_Filter::SCAN_TOKEN_TRANSIENT ), 'the token is gone once the scan returns' );
	ok( isset( $result['checks'] ), 'the scan produced a report' );

	// A second scan while one is running is refused, and leaves the first's token alone.
	set_transient( Bot_Filter::SCAN_TOKEN_TRANSIENT, 'running', 300 );
	$GLOBALS['va_test_http']['sent'] = [];
	$busy = ( new AI_Scan() )->run( 'https://example.com/', true );
	ok( ! empty( $busy['busy'] ) && [] === $GLOBALS['va_test_http']['sent'], 'a second scan is refused without making a request' );
	same( 'running', get_transient( Bot_Filter::SCAN_TOKEN_TRANSIENT ), 'and does not disturb the running scan\'s token' );

	finish();
