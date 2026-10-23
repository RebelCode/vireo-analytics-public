<?php
/**
 * Assertion runner for the AI visibility scan's robots.txt reader.
 * Run: php tests/test-ai-scan-robots.php
 */

require __DIR__ . '/ai-scan-stubs.php';
require __DIR__ . '/../includes/class-ai-scan-robots-too-complex.php';
require __DIR__ . '/../includes/class-ai-scan-robots.php';

use VireoAnalytics\AI_Scan_Robots as Robots;

ok( ( new Robots( '' ) )->is_allowed( 'GPTBot', '/' ), 'an empty file allows everything' );

$r = new Robots( "User-agent: *\nDisallow: /private/\n" );
ok( ! $r->is_allowed( 'GPTBot', '/private/page' ), 'the wildcard group applies when nothing names the crawler' );
ok( $r->is_allowed( 'GPTBot', '/public/' ), 'and leaves other paths open' );

$r = new Robots( "User-agent: *\nDisallow: /\n\nUser-agent: GPTBot\nAllow: /\n" );
ok( $r->is_allowed( 'GPTBot', '/anything' ), 'a group naming the crawler replaces the wildcard group' );
ok( ! $r->is_allowed( 'ClaudeBot', '/anything' ), 'others still fall under the wildcard' );

$r = new Robots( "User-agent: *\nDisallow: /\n\nUser-agent: PerplexityBot\nDisallow:\n" );
ok( $r->is_allowed( 'PerplexityBot', '/' ), 'a named group with an empty Disallow allows everything' );

ok( ! ( new Robots( "user-agent: gptbot\ndisallow: /\n" ) )->is_allowed( 'GPTBot', '/' ), 'agent names match without regard to case' );
ok( ( new Robots( "User-agent: Google\nDisallow: /\n" ) )->is_allowed( 'Google-Extended', '/' ), 'agent tokens must match exactly' );

$r = new Robots( "User-agent: GPTBot\nUser-agent: CCBot\nDisallow: /\n" );
ok( ! $r->is_allowed( 'GPTBot', '/' ) && ! $r->is_allowed( 'CCBot', '/' ) && $r->is_allowed( 'ClaudeBot', '/' ), 'consecutive User-agent lines share a group' );

$r = new Robots( "User-agent: GPTBot\nDisallow: /\nUser-agent: CCBot\nDisallow: /tmp/\n" );
ok( $r->is_allowed( 'CCBot', '/' ) && ! $r->is_allowed( 'CCBot', '/tmp/x' ), 'a User-agent after rules starts a new group' );

$r = new Robots( "User-agent: GPTBot\nDisallow: /a/\n\nUser-agent: GPTBot\nDisallow: /b/\n" );
ok( ! $r->is_allowed( 'GPTBot', '/a/1' ) && ! $r->is_allowed( 'GPTBot', '/b/1' ), 'groups for the same agent are merged' );

$r = new Robots( "User-agent: *\nDisallow: /blog/\nAllow: /blog/public/\n" );
ok( $r->is_allowed( 'GPTBot', '/blog/public/post' ) && ! $r->is_allowed( 'GPTBot', '/blog/draft' ), 'the longest matching rule wins' );

ok( ( new Robots( "User-agent: *\nDisallow: /page\nAllow: /page\n" ) )->is_allowed( 'GPTBot', '/page' ), 'Allow wins a tie' );

$r = new Robots( "User-agent: *\nDisallow: /*.pdf$\nDisallow: /*?s=\n" );
ok( ! $r->is_allowed( 'GPTBot', '/files/guide.pdf' ), 'a wildcard with an end anchor blocks the file' );
ok( $r->is_allowed( 'GPTBot', '/files/guide.pdf?download=1' ), 'the anchor stops it matching past the end' );
ok( ! $r->is_allowed( 'GPTBot', '/?s=query' ), 'a wildcard matches a query string' );

$r = new Robots( "User-agent: *\nDisallow: /a.b\n" );
ok( $r->is_allowed( 'GPTBot', '/axb' ) && ! $r->is_allowed( 'GPTBot', '/a.b' ), 'regex characters in paths are literal' );

ok( ! ( new Robots( "# hello\r\nUser-agent: GPTBot # training\r\nDisallow: / # all\r\n" ) )->is_allowed( 'GPTBot', '/' ), 'comments and CRLF are ignored' );

$r = new Robots( "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n\nSitemap: https://example.com/wp-sitemap.xml\n" );
ok( $r->is_allowed( 'OAI-SearchBot', '/' ) && ! $r->is_allowed( 'OAI-SearchBot', '/wp-admin/options.php' ) && $r->is_allowed( 'OAI-SearchBot', '/wp-admin/admin-ajax.php' ), "WordPress's default robots.txt allows search crawlers" );

$r = new Robots( "Sitemap: https://example.com/a.xml\nUser-agent: *\nDisallow:\nsitemap: https://example.com/b.xml\nSitemap:\n" );
same( [ 'https://example.com/a.xml', 'https://example.com/b.xml' ], $r->sitemaps(), 'sitemaps are collected, empty ones dropped' );

ok( ( new Robots( "User-agent: *\nDisallow: /foo*\nAllow: /foo\n" ) )->is_allowed( 'GPTBot', '/foo' ), 'a trailing star adds no specificity' );

$start = microtime( true );
$r     = new Robots( "User-agent: *\nDisallow: /" . str_repeat( '*a', 200 ) . "b\n" );
ok( $r->is_allowed( 'GPTBot', '/' . str_repeat( 'a', 5000 ) ) && microtime( true ) - $start < 1.0, 'a hostile pattern with no match finishes fast' );

$start = microtime( true );
$r     = new Robots( "User-agent: *\nDisallow: /" . str_repeat( '*a', 30 ) . "*b\n" );
ok( ! $r->is_allowed( 'GPTBot', '/' . str_repeat( 'a', 2000 ) . 'b' ) && microtime( true ) - $start < 1.0, 'a hostile pattern that matches is not lost to the backtrack limit' );

$r = new Robots( "User-agent: *\nDisallow: /%7Efoo\nDisallow: /~bar\nDisallow: /a%2fb\n" );
ok( ! $r->is_allowed( 'GPTBot', '/~foo' ) && ! $r->is_allowed( 'GPTBot', '/%7ebar' ) && ! $r->is_allowed( 'GPTBot', '/a%2Fb' ) && $r->is_allowed( 'GPTBot', '/a/b' ), 'percent escapes of unreserved characters are normalised' );

$r = new Robots( "User-agent: *\nDisallow: /" . str_repeat( 'x', 600 ) . "\n" );
ok( $r->is_truncated() && null === $r->verdicts( [ 'GPTBot' ], '/' ), 'over-long rules are skipped and flagged' );

$r = new Robots( "User-agent: *\n" . str_repeat( "Disallow: /filler\n", 2000 ) . "Disallow: /\n" );
ok( $r->is_truncated() && $r->is_allowed( 'GPTBot', '/page' ) && ! ( new Robots( "User-agent: *\nDisallow: /\n" ) )->is_truncated(), 'rules past the cap are skipped and flagged' );

$start = microtime( true );
$r     = new Robots( "User-agent: *\n" . str_repeat( 'Disallow: /' . str_repeat( '*a', 200 ) . "b\n", 600 ) );
ok( null === $r->verdicts( [ 'GPTBot', 'CCBot' ], '/' . str_repeat( 'a', 5000 ) ) && microtime( true ) - $start < 2.0, 'verdicts give up when the step budget runs out' );
same( [ 'GPTBot' => true ], ( new Robots( "User-agent: *\nDisallow: /x\n" ) )->verdicts( [ 'GPTBot' ], '/' ), 'and return a verdict when it does not' );

$r = new Robots( "User-agent: *\nDisallow: /*?lang=\n" );
ok( ! $r->is_allowed( 'GPTBot', '/?lang=en' ) && $r->is_allowed( 'GPTBot', '/' ), 'the query string is part of the match' );

finish();
