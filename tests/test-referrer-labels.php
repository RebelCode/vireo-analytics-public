<?php
/**
 * Zero-dependency assertion runner for referrer names and source buckets.
 * Run: php tests/test-referrer-labels.php
 *
 * Android apps arrive as android-app://<package>/ referrers, stored as the
 * package name. They should read as the app and land in the right bucket, in
 * the PHP grouping and the SQL source filter alike.
 */

if ( ! function_exists( '__' ) ) {
	function __( $s, $d = null ) { return $s; }
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) { return $value; }
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

require __DIR__ . '/../includes/va-filters.php';
require __DIR__ . '/../includes/class-filters.php';
require __DIR__ . '/../includes/class-ai-sources.php';

use VireoAnalytics\Filters;

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

// 1. Names.
ok( 'Google app' === vireo_referrer_label( 'com.google.android.googlequicksearchbox' ), 'Google app package reads as "Google app"' );
ok( 'Gmail' === vireo_referrer_label( 'com.google.android.gm' ), 'Gmail package reads as "Gmail"' );
ok( 'Slack' === vireo_referrer_label( 'com.Slack' ), 'package lookup ignores case' );
ok( 'example.com' === vireo_referrer_label( 'example.com' ), 'ordinary hosts are shown as they are' );
foreach ( [ 'chatgpt.com' => 'ChatGPT', 'perplexity.ai' => 'Perplexity', 'claude.ai' => 'Claude', 'gemini.google.com' => 'Gemini', 'copilot.microsoft.com' => 'Copilot' ] as $host => $name ) {
	ok( $name === vireo_referrer_label( $host ), "$host reads as \"$name\"" );
}
ok( 'com.example.someapp' === vireo_referrer_label( 'com.example.someapp' ), 'unknown packages are shown as they are' );

// Host shown under the name: only when the name replaced a real host.
ok( 'chatgpt.com' === vireo_referrer_subtitle( 'chatgpt.com' ), 'ChatGPT shows chatgpt.com beneath' );
ok( 'chat.openai.com' === vireo_referrer_subtitle( 'chat.openai.com' ), 'the second ChatGPT host shows its own host' );
ok( '' === vireo_referrer_subtitle( 'com.google.android.googlequicksearchbox' ), 'Google app shows nothing beneath' );
ok( '' === vireo_referrer_subtitle( 'example.com' ), 'a plain host shows nothing beneath' );

// 2. Buckets, from the stored host and from the raw referrer.
$cases = [
	'com.google.android.googlequicksearchbox'               => 'search',
	'android-app://com.google.android.googlequicksearchbox/' => 'search',
	'com.google.android.gm'                                 => 'referral',
	'com.linkedin.android'                                  => 'social',
	'org.telegram.messenger'                                => 'social',
	'com.reddit.frontpage'                                  => 'social',
	'google.com'                                            => 'search',
	'linkedin.com'                                          => 'social',
	'example.com'                                           => 'referral',
];
foreach ( $cases as $ref => $want ) {
	$got = vireo_classify_source( $ref );
	ok( $want === $got['type'], "vireo_classify_source( $ref ) is $want, got {$got['type']}" );
}
$got = vireo_classify_source( 'android-app://com.google.android.googlequicksearchbox/' );
ok( 'Google app' === $got['label'], 'classifier label for the raw Google app referrer is "Google app"' );

// 3. The two classifiers agree on every app.
foreach ( vireo_app_referrers() as $host => $app ) {
	ok( $app['type'] === Filters::classify_source( $host ), "Filters::classify_source agrees on $host" );
}

// 4. SQL source filter: placeholders and arguments line up, apps are matched
// by exact name inside their own bucket, and every app is kept out of the
// pattern branch so Gmail can't fall into search.
$app_count = count( vireo_app_referrers() );
foreach ( [ 'search', 'social', 'referral' ] as $bucket ) {
	$f         = new Filters();
	$f->source = $bucket;
	[ $sql, $args ] = $f->source_where( 'u.url' );

	$own = array_keys( array_filter( vireo_app_referrers(), static fn( array $a ): bool => $bucket === $a['type'] ) );
	ok( substr_count( $sql, '%s' ) === count( $args ), "$bucket: placeholders match arguments" );
	ok( array_slice( $args, 0, count( $own ) ) === $own, "$bucket: its own apps are matched first" );
	ok( array_slice( $args, - $app_count ) === array_keys( vireo_app_referrers() ), "$bucket: every app is excluded from the pattern branch" );
}

$f         = new Filters();
$f->source = 'search';
[ , $args ] = $f->source_where();
ok( in_array( 'com.google.android.googlequicksearchbox', array_slice( $args, 0, 1 ), true ), 'search filter includes the Google app' );

echo $fail ? "\n$fail failure(s)\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
