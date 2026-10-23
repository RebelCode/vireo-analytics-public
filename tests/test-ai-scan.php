<?php
/**
 * Assertion runner for the AI visibility scan itself: target validation,
 * what it fetches, redirects, challenge detection and the time budget.
 * Run: php tests/test-ai-scan.php
 */

require __DIR__ . '/ai-scan-stubs.php';
require __DIR__ . '/../includes/class-bot-filter.php';
require __DIR__ . '/../includes/class-ai-scan-robots-too-complex.php';
require __DIR__ . '/../includes/class-ai-scan-robots.php';
require __DIR__ . '/../includes/class-ai-scan-page-parser.php';
require __DIR__ . '/../includes/class-ai-scan-checks.php';
require __DIR__ . '/../includes/class-ai-scan.php';

use VireoAnalytics\AI_Scan;

$norm = static fn( $in, $home = 'https://example.com/' ) => AI_Scan::normalize_target( $in, $home );

same( 'https://example.com/', $norm( '' ), 'an empty target is the home page' );
same( 'https://example.com/about/', $norm( '/about/' ), 'a path is resolved against home' );
same( 'https://example.com/a/?x=1', $norm( 'https://EXAMPLE.com/a/?x=1#frag' ), 'a same-host URL is lower-cased and loses its fragment' );
same( 'http://localhost:8888/p/', $norm( '/p/', 'http://localhost:8888/' ), 'a port is kept' );
foreach ( [ 'https://evil.example/', '//evil.example/', 'https://example.com.evil.example/', 'https://example.com:8080/', 'https://user:pw@example.com/', 'ftp://example.com/' ] as $bad ) {
	same( null, $norm( $bad ), "rejected: $bad" );
}

function va_page( $extra = '' ) {
	return [ 'status' => 200, 'body' => '<html lang="en"><body><h1>Hi</h1><p>' . str_repeat( 'text ', 320 ) . '</p>' . $extra . '</body></html>' ];
}

function va_scan_with( callable $fetcher, $url = 'https://example.com/', ?callable $clock = null ) {
	$s = new AI_Scan();
	$s->set_fetcher( $fetcher );
	if ( $clock ) {
		$s->set_clock( $clock );
	}
	return $s->scan( $url, true );
}

$r = va_scan_with( static fn() => [ 'error' => 'cURL error 7' ] );
ok( false !== strpos( $r['error'], 'cURL error 7' ) && ! isset( $r['checks'] ), 'an unreachable page is reported, with no checks' );

$requests = [];
$r        = va_scan_with(
	static function ( $url, $ua ) use ( &$requests ) {
		$requests[] = $url . ' ' . $ua;
		switch ( $url ) {
			case 'https://example.com/':
				return false !== strpos( $ua, 'ClaudeBot' ) ? [ 'status' => 403 ] : va_page();
			case 'https://example.com/robots.txt':
				return [ 'status' => 200, 'body' => "User-agent: PerplexityBot\nDisallow: /\nSitemap: https://cdn.other.example/s.xml\nSitemap: https://example.com/custom.xml\n" ];
			case 'https://example.com/llms.txt':
				return [ 'status' => 200, 'body' => '<!DOCTYPE html><html><body>Not found</body></html>' ];
			case 'https://example.com/custom.xml':
				return [ 'status' => 200, 'body' => '<?xml version="1.0"?><urlset></urlset>' ];
		}
		return [ 'status' => 404 ];
	}
);
$k = array_column( $r['checks'], null, 'id' );
same( 'fail', $k['robots-search']['status'], 'end to end: PerplexityBot blocked in robots.txt fails the search check' );
same( 'warn', $k['live-bots']['status'], 'a 403 for ClaudeBot warns' );
has( 'ClaudeBot (HTTP 403)', $k['live-bots']['summary'], 'and names it' );
same( 'warn', $k['llms']['status'], 'an HTML page at /llms.txt is not an llms.txt' );
same( 'pass', $k['sitemap']['status'], 'the sitemap declared in robots.txt is found' );
has( 'https://example.com/custom.xml', $k['sitemap']['summary'], 'and named' );
same( 'pass', $k['readable-text']['status'], 'readable text passes' );
ok( $r['score'] > 0 && $r['score'] < 100, 'the score is between 0 and 100 (' . $r['score'] . ')' );
$fetched = implode( "\n", $requests );
ok( false === strpos( $fetched, 'cdn.other.example' ), 'a sitemap on another host is never fetched' );
ok( false !== strpos( $fetched, 'GPTBot' ) && false !== strpos( $fetched, 'PerplexityBot/1.0' ), 'the crawler user agents are used' );
has( 'VireoSiteScan', $fetched, 'the browser-like request is identifiable as the scan' );

$bot_check = static function ( array $bot, string $page_body = '' ) {
	$page = '' !== $page_body ? [ 'status' => 200, 'body' => $page_body ] : va_page();
	$r    = va_scan_with(
		static function ( $url, $ua ) use ( $bot, $page ) {
			if ( 'https://example.com/' !== $url ) {
				return [ 'status' => 404 ];
			}
			return false !== strpos( $ua, 'GPTBot' ) ? $bot : $page;
		}
	);
	return array_column( $r['checks'], null, 'id' )['live-bots'];
};

$same_page = '<html><body><p>' . str_repeat( 'text ', 320 ) . '</p></body></html>';
same( 'pass', $bot_check( [ 'status' => 200, 'body' => $same_page ], $same_page )['status'], 'an identical page served to the bot passes' );
$c = $bot_check( [ 'status' => 200, 'body' => '<html><head><title>Just a moment...</title></head><body>' . str_repeat( 'text ', 320 ) . '</body></html>' ] );
ok( 'warn' === $c['status'] && false !== strpos( $c['summary'], 'GPTBot' ), 'a challenge page served with a 200 counts as blocked' );
same( 'warn', $bot_check( [ 'status' => 200, 'body' => '<html><body><p>Please wait.</p></body></html>' ] )['status'], 'a thin page served to the bot counts as blocked' );

foreach ( [ 'https://shield.example/check', '/wp-login.php?redirect_to=%2F' ] as $location ) {
	$r = va_scan_with(
		static function ( $url, $ua ) use ( $location ) {
			if ( 'https://example.com/' === $url && false !== strpos( $ua, 'GPTBot' ) ) {
				return [ 'status' => 302, 'headers' => [ 'Location' => $location ] ];
			}
			if ( 'https://example.com/' === $url || 0 === strpos( $url, 'https://example.com/wp-login.php' ) ) {
				return va_page();
			}
			return [ 'status' => 404 ];
		}
	);
	$c = array_column( $r['checks'], null, 'id' )['live-bots'];
	ok( 'warn' === $c['status'] && false !== strpos( $c['summary'], 'GPTBot' ), "a bot sent to $location counts as blocked" );
}

$caps = [];
va_scan_with(
	static function ( $url, $ua, $cap = 0 ) use ( &$caps ) {
		if ( 'https://example.com/' === $url ) {
			$caps[ false !== strpos( $ua, 'GPTBot' ) ? 'bot' : ( false !== strpos( $ua, 'VireoSiteScan' ) ? 'page' : 'other' ) ] = $cap;
			return [ 'status' => 200, 'body' => '<html><body>hi</body></html>' ];
		}
		return [ 'status' => 404 ];
	}
);
ok( ( $caps['page'] ?? 0 ) > 0 && ( $caps['page'] ?? 0 ) === ( $caps['bot'] ?? -1 ), 'the bot copy is fetched with the same size cap as the page' );

$r = va_scan_with(
	static function ( $url ) {
		if ( 'https://example.com/?lang=en' === $url ) {
			return [ 'status' => 200, 'body' => '<html><body>hi</body></html>' ];
		}
		if ( 'https://example.com/robots.txt' === $url ) {
			return [ 'status' => 200, 'body' => "User-agent: OAI-SearchBot\nDisallow: /*?lang=\n" ];
		}
		return [ 'status' => 404 ];
	},
	'https://example.com/?lang=en'
);
same( 'fail', array_column( $r['checks'], null, 'id' )['robots-search']['status'], 'robots rules are matched against the query string' );

// The fake web, through the real HTTP path (wp_safe_remote_get, no redirects followed by core).
function va_fake_web( array $routes, $start = 'https://example.com/' ) {
	$GLOBALS['va_test_http'] = [ 'routes' => $routes, 'log' => [], 'sent' => [] ];
	$result = ( new AI_Scan() )->scan( $start, true );
	return [ $result, $GLOBALS['va_test_http']['log'] ];
}

[ $r, $log ] = va_fake_web( [ 'https://example.com/' => [ 'status' => 301, 'headers' => [ 'Location' => 'https://evil.example/' ] ], 'https://evil.example/' => va_page() ] );
ok( ! in_array( 'https://evil.example/', $log, true ) && in_array( 'https://example.com/', $log, true ), 'a redirect to another host is not followed' );
ok( isset( $r['error'] ) && false !== strpos( $r['error'], 'evil.example' ), 'and the error says where it went' );

[ $r, $log ] = va_fake_web(
	[
		'https://example.com/'           => va_page(),
		'https://example.com/robots.txt' => [ 'status' => 302, 'headers' => [ 'Location' => 'http://127.0.0.1/internal' ] ],
		'http://127.0.0.1/internal'      => [ 'status' => 200, 'body' => "User-agent: *\nDisallow: /\n" ],
	]
);
$loopback = array_filter( $log, static fn( $u ) => false !== strpos( $u, '127.0.0.1' ) );
ok( [] === $loopback && isset( $r['checks'] ), 'a redirect to loopback is not followed' );

[ $r, $log ] = va_fake_web(
	[
		'http://example.com/old/'  => [ 'status' => 301, 'headers' => [ 'Location' => 'https://example.com/old/' ] ],
		'https://example.com/old/' => [ 'status' => 302, 'headers' => [ 'Location' => '/new/' ] ],
		'https://example.com/new/' => va_page(),
	],
	'http://example.com/old/'
);
ok( in_array( 'https://example.com/new/', $log, true ) && ! isset( $r['error'] ) && 'https://example.com/new/' === $r['final_url'], 'same-host redirects are followed to the final page' );

$now      = 1000.0;
$requests = [];
$r        = va_scan_with(
	static function ( $url ) use ( &$now, &$requests ) {
		$requests[] = $url;
		$now       += 9;
		return 'https://example.com/' === $url ? va_page() : [ 'status' => 404 ];
	},
	'https://example.com/',
	static function () use ( &$now ) { return $now; }
);
$k = array_column( $r['checks'], null, 'id' );
same( [ 'https://example.com/', 'https://example.com/robots.txt', 'https://example.com/llms.txt' ], $requests, 'the scan stops asking once the time budget is spent' );
ok( 'skip' === $k['live-bots']['status'] && 'skip' === $k['sitemap']['status'], 'the checks it did not reach are marked not run' );
ok( 'pass' === $k['robots']['status'] && 'warn' === $k['llms']['status'], 'the ones it reached keep their result' );
has( '2 checks didn\'t run', $r['warning'], 'and the report says how many' );

$r = va_scan_with( static fn( $url ) => 'https://example.com/' === $url ? [ 'status' => 200, 'body' => '<html><body><p>' . str_repeat( 'text ', 320 ) . '</p></body></html>' ] : [ 'status' => 404 ] );
ok( ! isset( $r['warning'] ) && ! in_array( 'skip', array_column( $r['checks'], 'status' ), true ), 'a scan within budget has no warning' );

finish();
