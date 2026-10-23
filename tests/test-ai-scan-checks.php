<?php
/**
 * Assertion runner for the AI visibility scan's checks and scoring.
 * Run: php tests/test-ai-scan-checks.php
 */

require __DIR__ . '/ai-scan-stubs.php';
require __DIR__ . '/../includes/class-ai-scan-robots-too-complex.php';
require __DIR__ . '/../includes/class-ai-scan-robots.php';
require __DIR__ . '/../includes/class-ai-scan-page-parser.php';
require __DIR__ . '/../includes/class-ai-scan-checks.php';

use VireoAnalytics\AI_Scan_Checks as Checks;
use VireoAnalytics\AI_Scan_Page_Parser as Parser;
use VireoAnalytics\AI_Scan_Robots as Robots;

function va_check( $category, $weight, $status ) {
	return [ 'category' => $category, 'weight' => $weight, 'status' => $status ];
}

function va_input( array $overrides = [] ) {
	$html = '<html lang="en"><head><link rel="canonical" href="https://example.com/">'
		. '<meta property="og:title" content="T"><meta property="og:description" content="D"><meta property="og:image" content="https://example.com/i.png">'
		. '<script type="application/ld+json">{"@type":"Organization","name":"Acme","sameAs":["https://x.example"],"telephone":"+1"}</script>'
		. '</head><body><h1>Acme</h1><h2>What do we do?</h2><h2>Where are we?</h2><p>' . str_repeat( 'word ', 400 ) . '</p><img src="a.png" alt="A"></body></html>';
	return array_merge(
		[
			'path'          => '/',
			'headers'       => [],
			'facts'         => ( new Parser() )->parse( $html ),
			'robots'        => new Robots( "User-agent: *\nDisallow: /wp-admin/\n" ),
			'robots_status' => 200,
			'blog_public'   => true,
			'bots'          => [ [ 'name' => 'GPTBot', 'status' => 200, 'error' => '' ] ],
			'sitemap'       => 'https://example.com/wp-sitemap.xml',
			'llms_length'   => 500,
		],
		$overrides
	);
}

function va_by_id( array $checks ) {
	return array_column( $checks, null, 'id' );
}

$c = new Checks();

same( 100, $c->score( [ va_check( 'access', 5, 'pass' ), va_check( 'content', 1, 'pass' ) ] )['score'], 'all pass scores 100' );
same( 0, $c->score( [ va_check( 'access', 5, 'fail' ), va_check( 'content', 1, 'fail' ) ] )['score'], 'all fail scores 0' );
same( 50, $c->score( [ va_check( 'access', 2, 'warn' ) ] )['score'], 'a warning counts half' );
same( 75, $c->score( [ va_check( 'access', 3, 'pass' ), va_check( 'access', 1, 'fail' ) ] )['score'], 'the score is weighted' );

$s = $c->score( [ va_check( 'access', 1, 'pass' ), va_check( 'content', 1, 'fail' ), va_check( 'content', 1, 'warn' ) ] );
ok( 100 === $s['categories']['access'] && 25 === $s['categories']['content'] && null === $s['categories']['discovery'], 'category breakdown, an empty category is null' );
same( array_keys( Checks::categories() ), array_keys( $s['categories'] ), 'every category is reported' );
same( 0, $c->score( [] )['score'], 'no checks scores 0' );

$s = $c->score( [ va_check( 'access', 5, 'pass' ), va_check( 'access', 4, 'skip' ), va_check( 'discovery', 1, 'skip' ) ] );
ok( 100 === $s['score'] && null === $s['categories']['discovery'], 'checks that did not run are left out of the score' );

$checks = $c->build( va_input() );
same( 16, count( $checks ), 'a full scan has 16 checks' );
$bad = array_filter( $checks, static fn( $x ) => 'pass' !== $x['status'] || '' !== $x['fix'] );
ok( [] === $bad, 'a healthy page passes every check with no fix text' );
same( 100, $c->score( $checks )['score'], 'and scores 100' );

$k = va_by_id( $c->build( va_input( [ 'robots' => new Robots( "User-agent: OAI-SearchBot\nDisallow: /\n" ) ] ) ) );
ok( 'fail' === $k['robots-search']['status'] && 'pass' === $k['robots-training']['status'], 'a blocked search crawler fails' );
has( 'OAI-SearchBot', $k['robots-search']['summary'], 'and is named' );

$k = va_by_id( $c->build( va_input( [ 'robots' => new Robots( "User-agent: GPTBot\nDisallow: /\n\nUser-agent: CCBot\nDisallow: /\n" ) ] ) ) );
ok( 'pass' === $k['robots-search']['status'] && 'warn' === $k['robots-training']['status'], 'blocked training crawlers only warn' );
ok( false !== strpos( $k['robots-training']['summary'], 'GPTBot' ) && false !== strpos( $k['robots-training']['summary'], 'CCBot' ), 'and are named' );

$k = va_by_id( $c->build( va_input( [ 'robots' => new Robots( "User-agent: *\nDisallow: /\n" ) ] ) ) );
ok( 'fail' === $k['robots-search']['status'] && 'warn' === $k['robots-training']['status'], 'blocking everything fails search and warns training' );

$k = va_by_id( $c->build( va_input( [ 'robots' => null, 'robots_status' => 404 ] ) ) );
ok( 'pass' === $k['robots']['status'] && ! isset( $k['robots-search'] ), 'no robots.txt passes' );
$k = va_by_id( $c->build( va_input( [ 'robots' => null, 'robots_status' => 500 ] ) ) );
same( 'warn', $k['robots']['status'], 'a broken robots.txt warns' );

$k = va_by_id( $c->build( va_input( [ 'bots' => [ [ 'name' => 'ClaudeBot', 'status' => 403, 'error' => '' ] ] ] ) ) );
ok( 'warn' === $k['live-bots']['status'] && '' !== $k['live-bots']['fix'], 'a refused crawler warns with a fix' );
has( 'ClaudeBot (HTTP 403)', $k['live-bots']['summary'], 'and names the status' );

same( 'fail', va_by_id( $c->build( va_input( [ 'blog_public' => false ] ) ) )['indexable']['status'], 'a discouraged site fails indexable' );
same( 'fail', va_by_id( $c->build( va_input( [ 'headers' => [ 'x-robots-tag' => 'noindex, nofollow' ] ] ) ) )['indexable']['status'], 'X-Robots-Tag noindex fails indexable' );

$facts                = va_input()['facts'];
$facts['robots_meta'] = 'index, noai';
same( 'warn', va_by_id( $c->build( va_input( [ 'facts' => $facts ] ) ) )['indexable']['status'], 'noai warns' );

$facts = ( new Parser() )->parse( '<html><body><div id="root"></div><script>render()</script></body></html>' );
$k     = va_by_id( $c->build( va_input( [ 'facts' => $facts ] ) ) );
ok( 'fail' === $k['readable-text']['status'] && 'fail' === $k['h1']['status'] && 'fail' === $k['org-schema']['status'] && 'fail' === $k['og']['status'], 'an app shell fails the content and markup checks' );
has( 'JavaScript', $k['readable-text']['summary'], 'and says why' );

$k = va_by_id( $c->build( va_input( [ 'sitemap' => null, 'llms_length' => 0 ] ) ) );
ok( 'fail' === $k['sitemap']['status'] && 'warn' === $k['llms']['status'], 'no sitemap fails, no llms.txt warns' );

$facts            = va_input()['facts'];
$facts['json_ld'] = [ [ '@type' => 'LocalBusiness' ] ];
same( 'warn', va_by_id( $c->build( va_input( [ 'facts' => $facts ] ) ) )['org-schema']['status'], 'Organization without sameAs warns' );

$kinds = array_column( Checks::robots_agents(), 'kind', 'token' );
$good  = true;
foreach ( [ 'OAI-SearchBot', 'ChatGPT-User', 'PerplexityBot', 'Claude-SearchBot', 'Googlebot', 'Bingbot' ] as $t ) {
	$good = $good && 'search' === $kinds[ $t ];
}
foreach ( [ 'GPTBot', 'ClaudeBot', 'Google-Extended', 'Applebot-Extended', 'CCBot' ] as $t ) {
	$good = $good && 'training' === $kinds[ $t ];
}
ok( $good, 'search and training agent lists' );

$k = va_by_id( $c->build( va_input( [ 'robots' => new Robots( "User-agent: OAI-SearchBot\nDisallow: /" . str_repeat( 'x', 600 ) . "\n" ) ] ) ) );
ok( 'warn' === $k['robots']['status'] && ! isset( $k['robots-search'] ), 'robots.txt too complex to evaluate warns instead of guessing' );

finish();
