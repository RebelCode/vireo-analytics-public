<?php
/**
 * Assertion runner for the AI visibility scan's HTML reader.
 * Run: php tests/test-ai-scan-parser.php
 */

require __DIR__ . '/ai-scan-stubs.php';
require __DIR__ . '/../includes/class-ai-scan-page-parser.php';

use VireoAnalytics\AI_Scan_Page_Parser as Parser;

$html = '<!doctype html><html lang="pt-PT"><head>'
	. '<meta name="robots" content="NoIndex"><meta property="og:title" content="Hi &amp; bye">'
	. '<link rel="alternate canonical" href="https://example.com/x/">'
	. '<script type="application/ld+json">{"@graph":[{"@type":["WebSite"]},{"@type":"https://schema.org/Dentist","sameAs":"https://y.example"}]}</script>'
	. '<script type="application/ld+json">{broken</script>'
	. '<script>var h1 = "<h1>not a heading</h1>";</script>'
	. '</head><body><!-- <h2>ghost</h2> --><h1>Main <em>title</em></h1><h2>Q one?</h2><h3>Q two?</h3>'
	. '<p>Café opens at 9 &mdash; 5.</p><img src="a" alt=""><img src="b">'
	. '<a href="tel:+351">call</a><a href=\'mailto:a@example.com\'>mail</a></body></html>';

$f = ( new Parser() )->parse( $html );
same( 'pt-PT', $f['lang'], 'lang' );
same( 'noindex', $f['robots_meta'], 'robots meta, lower-cased' );
same( 'Hi & bye', $f['og']['og:title'], 'og:title is entity-decoded' );
same( 'https://example.com/x/', $f['canonical'], 'canonical found among several rel values' );
same( [ 'Main title' ], $f['h1'], 'h1 text, markup stripped, script string ignored' );
same( [ 'Q one?' ], $f['h2'], 'h2, commented-out heading ignored' );
same( [ 'Main title', 'Q one?', 'Q two?' ], $f['headings'], 'headings in order' );
same( 2, $f['images'], 'images' );
same( 1, $f['images_alt'], 'images with an alt attribute' );
same( 1, $f['json_ld_errors'], 'one JSON-LD block failed to parse' );
$types = $f['schema_types'];
sort( $types );
same( [ 'Dentist', 'WebSite' ], $types, 'schema types, URL form trimmed' );
same( 1, $f['tel_links'], 'tel links' );
same( 1, $f['mailto_links'], 'mailto links' );
same( false, $f['app_shell'], 'not an app shell' );
same( 13, $f['word_count'], 'word count' );

$p   = new Parser();
$f   = $p->parse( '<script type="application/ld+json">{"@type":"WebPage","publisher":{"@type":"Organization","name":"Acme"}}</script>' );
$org = $p->find_org( $f['json_ld'] );
same( 'Acme', $org['name'] ?? null, 'finds a nested Organization node' );

same( 4, ( new Parser() )->parse( "<html><body><p>caf\xE9 ol\xE9 one two</p></body></html>" )['word_count'], 'invalid UTF-8 still counts words' );

$f = ( new Parser() )->parse( '<html><body><div id="__next"></div></body></html>' );
ok( true === $f['app_shell'] && 0 === $f['word_count'], 'an empty app root is an app shell' );

$f = ( new Parser() )->parse(
	'<html><head><!-- <meta name="robots" content="noindex"> -->'
	. '<!-- <script type="application/ld+json">{"@type":"Organization"}</script> -->'
	. '<!-- <link rel="canonical" href="https://example.com/old/"> --></head><body></body></html>'
);
ok( '' === $f['robots_meta'] && [] === $f['schema_types'] && '' === $f['canonical'], 'commented-out meta and schema are ignored' );

$f = ( new Parser() )->parse( '<html><head><meta property="og:title" content="Fast > slow"><meta name=\'robots\' content=\'a>b, noindex\'></head></html>' );
same( 'Fast > slow', $f['og']['og:title'], 'an attribute containing > is not truncated' );
has( 'noindex', $f['robots_meta'], 'including in the robots meta' );

finish();
