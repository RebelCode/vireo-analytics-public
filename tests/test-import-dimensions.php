<?php
/**
 * Zero-dependency check of how each importer maps a source's breakdowns
 * (referrers, referrers per post, countries, devices, 404s, events, filtered
 * bots) onto Vireo's rows. Fixtures are in the shape each source's own tables
 * or API return them.
 * Run: php tests/test-import-dimensions.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}
if ( ! defined( 'VIREO_DIR' ) ) {
	define( 'VIREO_DIR', dirname( __DIR__ ) . '/' );
}
if ( ! function_exists( '__' ) ) {
	function __( $s, $d = null ) { return $s; }
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ) { return $s; }
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
}
// Close enough to the real ones for these checks: tags and their script
// bodies go, control characters go, whitespace is collapsed.
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $s, $remove_breaks = false ) {
		$s = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $s );
		return trim( strip_tags( $s ) );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) {
		$s = wp_strip_all_tags( (string) $s );
		// The real one removes percent-encoded octets, which is the whole point
		// of decoding a path before handing it over.
		$s = (string) preg_replace( '/%[a-f0-9]{2}/i', '', $s );
		$s = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $s );
		return trim( (string) preg_replace( '/\s+/', ' ', $s ) );
	}
}

require __DIR__ . '/../includes/class-sites.php';
require __DIR__ . '/../includes/class-collector.php';
require __DIR__ . '/../includes/class-importer-base.php';
require __DIR__ . '/../importers/class-burst-importer.php';
require __DIR__ . '/../importers/class-independent-analytics-importer.php';
require __DIR__ . '/../importers/class-wp-statistics-importer.php';
require __DIR__ . '/../importers/class-slimstat-importer.php';
require __DIR__ . '/../importers/class-statify-importer.php';
require __DIR__ . '/../importers/class-ga4-importer.php';
require __DIR__ . '/../importers/class-plausible-importer.php';
require __DIR__ . '/../includes/class-bot-filter.php';

use VireoAnalytics\Importers\Importer_Base;
use VireoAnalytics\Collector;
use VireoAnalytics\Importers\Burst_Importer;
use VireoAnalytics\Importers\Independent_Analytics_Importer;
use VireoAnalytics\Importers\WP_Statistics_Importer;
use VireoAnalytics\Importers\SlimStat_Importer;
use VireoAnalytics\Importers\Statify_Importer;
use VireoAnalytics\Importers\GA4_Importer;

$fail = 0;
function ok( $cond, $msg ) {
	global $fail;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}

// ---------------------------------------------------------------------------
// Shared label mapping.
// ---------------------------------------------------------------------------

$browsers = [
	'Chrome'            => 'Chrome',
	'Chrome Mobile'     => 'Chrome',
	'Chrome Mobile iOS' => 'Chrome',
	'Mobile Safari'     => 'Safari',
	'Safari'            => 'Safari',
	'Microsoft Edge'    => 'Edge',
	'Firefox'           => 'Firefox',
	'Opera Mini'        => 'Opera',
	'Samsung Browser'   => 'Samsung Internet',
	'SamsungBrowser'    => 'Samsung Internet',
	'Internet Explorer' => 'IE',
	'IE'                => 'IE',
	'Default Browser'   => 'Other',
	''                  => 'Other',
];
foreach ( $browsers as $in => $want ) {
	ok( $want === Importer_Base::browser_label( $in ), "browser '$in' => $want (got " . Importer_Base::browser_label( $in ) . ')' );
}

$oses = [
	'Windows'    => 'Windows',
	'win10'      => 'Windows',
	'wi10'       => 'Windows',
	'win8.1'     => 'Windows',
	'winphone8'  => 'Other',
	'Mac'        => 'macOS',
	'Macintosh'  => 'macOS',
	'Mac OS X'   => 'macOS',
	'macosx'     => 'macOS',
	'iOS'        => 'iOS',
	'iPhone'     => 'iOS',
	'iPad'       => 'iOS',
	'Android'    => 'Android',
	'GNU/Linux'  => 'Linux',
	'Ubuntu'     => 'Linux',
	'Chrome OS'  => 'Chrome OS',
	'Windows Phone' => 'Other',
	'unknown'    => 'Other',
];
foreach ( $oses as $in => $want ) {
	ok( $want === Importer_Base::os_label( $in ), "os '$in' => $want (got " . Importer_Base::os_label( $in ) . ')' );
}

$devices = [
	'desktop'    => 'Desktop',
	'Desktop'    => 'Desktop',
	'smartphone' => 'Mobile',
	'mobile'     => 'Mobile',
	'phablet'    => 'Mobile',
	'tablet'     => 'Tablet',
	'tv'         => 'Unknown',
	''           => 'Unknown',
];
foreach ( $devices as $in => $want ) {
	ok( $want === Importer_Base::device_label( $in ), "device '$in' => $want (got " . Importer_Base::device_label( $in ) . ')' );
}

$refs = [
	'https://www.Google.com/search?q=x' => 'google.com',
	'news.ycombinator.com'              => 'news.ycombinator.com',
	'https://example.com/a'             => '',
	'https://www.example.com/b'         => '',
	'googleads'                         => '',
	'429047995'                         => '',
	''                                  => '',
];
foreach ( $refs as $in => $want ) {
	ok( $want === Importer_Base::referrer_host( $in, 'www.example.com' ), "referrer '$in' => '$want' (got '" . Importer_Base::referrer_host( $in, 'www.example.com' ) . "')" );
}

ok( 'github.com' === Importer_Base::outbound_host( 'https://github.com/x/y', 'example.com' ), 'outbound link keeps its host' );
ok( '' === Importer_Base::outbound_host( 'https://www.example.com/page', 'example.com' ), 'a link to this site is not outbound' );
ok( 'guide.pdf' === Importer_Base::download_name( 'https://example.com/files/guide.pdf?v=2' ), 'a download is recorded by its file name' );

$merged = Importer_Base::merge_rows(
	[
		[ 'date' => '2026-01-01', 'browser' => 'Chrome', 'visitors' => 2, 'pageviews' => 3 ],
		[ 'date' => '2026-01-01', 'browser' => 'Chrome', 'visitors' => 1, 'pageviews' => 4 ],
		[ 'date' => '2026-01-01', 'browser' => 'Safari', 'visitors' => 5, 'pageviews' => 5 ],
	],
	[ 'date', 'browser' ],
	[ 'visitors', 'pageviews' ]
);
ok( 2 === count( $merged ) && 3 === $merged[0]['visitors'] && 7 === $merged[0]['pageviews'], 'rows mapped onto the same label are summed, not written twice' );

// ---------------------------------------------------------------------------
// Burst Statistics: sessions carry the referrer, browser, OS, device and
// location; hits carry the page and a page_type of '404' for missing pages.
// ---------------------------------------------------------------------------

$landings = [
	// Two sessions from Google by one visitor, landing on post 12 and 30.
	[ 'referrer' => 'https://www.google.com/', 'uid_id' => '7', 'page_id' => '12' ],
	[ 'referrer' => 'https://www.google.com/', 'uid_id' => '7', 'page_id' => '30' ],
	[ 'referrer' => 'https://news.ycombinator.com/item?id=1', 'uid_id' => '8', 'page_id' => '12' ],
	// Landed on a page with no post (an archive): counts for the site only.
	[ 'referrer' => 'https://news.ycombinator.com/', 'uid_id' => '9', 'page_id' => '0' ],
	// This site referring to itself is not a referral.
	[ 'referrer' => 'https://example.com/other/', 'uid_id' => '9', 'page_id' => '12' ],
];
$refs = Importer_Base::landing_referrer_rows( '2026-03-02', $landings, 'example.com' );
$site = array_column( $refs['site'], null, 'host' );
ok( 2 === count( $site ), 'Burst: two referring hosts, own host dropped (' . implode( ',', array_keys( $site ) ) . ')' );
ok( 2 === $site['google.com']['pageviews'] && 1 === $site['google.com']['visitors'], 'Burst: google.com = 2 landings by 1 visitor' );
ok( 2 === $site['news.ycombinator.com']['pageviews'] && 2 === $site['news.ycombinator.com']['visitors'], 'Burst: HN = 2 landings by 2 visitors' );
$posts = [];
foreach ( $refs['posts'] as $r ) {
	$posts[ $r['post_id'] . ' ' . $r['host'] ] = $r['pageviews'];
}
ksort( $posts );
ok( [ '12 google.com' => 1, '12 news.ycombinator.com' => 1, '30 google.com' => 1 ] === $posts, 'Burst: referrers per post follow the landing page (' . json_encode( $posts ) . ')' );

$tech = Importer_Base::tech_rows(
	'2026-03-02',
	[
		[ 'browser' => 'Chrome', 'os' => 'Windows', 'device' => 'desktop', 'pageviews' => '10', 'visitors' => '4' ],
		[ 'browser' => 'Chrome Mobile', 'os' => 'Android', 'device' => 'mobile', 'pageviews' => '3', 'visitors' => '2' ],
		[ 'browser' => 'Safari', 'os' => 'iPad', 'device' => 'tablet', 'pageviews' => '2', 'visitors' => '1' ],
		[ 'browser' => null, 'os' => null, 'device' => null, 'pageviews' => '1', 'visitors' => '1' ],
	]
);
$keyed = [];
foreach ( $tech as $r ) {
	$keyed[ $r['browser'] . '/' . $r['os'] . '/' . $r['device_type'] ] = $r['pageviews'];
}
ok( [ 'Chrome/Windows/Desktop' => 10, 'Chrome/Android/Mobile' => 3, 'Safari/iOS/Tablet' => 2, 'Other/Other/Unknown' => 1 ] === $keyed, 'Burst: devices map onto the tracker labels (' . json_encode( $keyed ) . ')' );
ok( 16 === array_sum( array_column( $tech, 'pageviews' ) ), 'Burst: device pageviews add up to the hits read' );

$missing = Burst_Importer::missing_rows(
	'2026-03-02',
	[
		[ 'page_url' => '/old-post/', 'hits' => '3' ],
		[ 'page_url' => 'https://example.com/old-post/', 'hits' => '2' ],
		[ 'page_url' => '', 'hits' => '1' ],
	]
);
ok( 1 === count( $missing ) && '/old-post/' === $missing[0]['path'] && 5 === $missing[0]['hits'], 'Burst: 404 hits are listed by path, full URLs reduced to it' );

// ---------------------------------------------------------------------------
// Independent Analytics: `resource` is the kind of page, not its path.
// ---------------------------------------------------------------------------

$paths = [
	[ [ 'resource' => 'singular', 'cached_url' => 'http://example.com/2026/10/06/bravo/', 'not_found_url' => null ], '/2026/10/06/bravo/' ],
	[ [ 'resource' => '404', 'cached_url' => 'http://example.com/missing-0/', 'not_found_url' => 'missing-0/' ], '/missing-0/' ],
	[ [ 'resource' => 'home', 'cached_url' => null, 'not_found_url' => null ], '/' ],
	[ [ 'resource' => 'singular', 'cached_url' => null, 'not_found_url' => null ], '' ],
];
foreach ( $paths as [ $row, $want ] ) {
	$got = Independent_Analytics_Importer::path_of_resource( $row );
	ok( $want === $got, "IA: a '{$row['resource']}' resource is stored at '$want' (got '$got'), never at /{$row['resource']}" );
}

$clicks = [
	[ 'type' => 'external', 'name' => 'External', 'target' => 'https://github.com/x', 'protocol' => null, 'visitor_id' => '1' ],
	[ 'type' => 'external', 'name' => 'External', 'target' => 'https://github.com/y', 'protocol' => null, 'visitor_id' => '1' ],
	[ 'type' => 'external', 'name' => 'External', 'target' => 'https://github.com/z', 'protocol' => null, 'visitor_id' => '2' ],
	[ 'type' => 'external', 'name' => 'External', 'target' => 'https://example.com/a', 'protocol' => null, 'visitor_id' => '2' ],
	[ 'type' => 'extension', 'name' => 'PDF', 'target' => 'https://example.com/files/guide.pdf', 'protocol' => null, 'visitor_id' => '3' ],
	[ 'type' => 'protocol', 'name' => 'Email', 'target' => 'someone@example.org', 'protocol' => 'mailto', 'visitor_id' => '3' ],
	[ 'type' => 'protocol', 'name' => 'Phone number', 'target' => '+35699999999', 'protocol' => 'tel', 'visitor_id' => '4' ],
	[ 'type' => 'class', 'name' => 'Buy button', 'target' => 'https://shop.example.net/', 'protocol' => null, 'visitor_id' => '4' ],
];
$ev = [];
foreach ( Independent_Analytics_Importer::click_events( '2026-03-02', $clicks, 'example.com' ) as $e ) {
	$ev[ $e['name'] . ' ' . $e['value'] ] = [ $e['count'], $e['unique_count'] ];
}
ksort( $ev );
ok(
	[
		'Buy button '              => [ 1, 1 ],
		'download guide.pdf'       => [ 1, 1 ],
		'mailto_click @example.org' => [ 1, 1 ],
		'outbound github.com'      => [ 3, 2 ],
		'tel_click +35699999999'   => [ 1, 1 ],
	] === $ev,
	'IA: clicks become the tracker\'s events, a link to this site is not outbound (' . json_encode( $ev ) . ')'
);

// ---------------------------------------------------------------------------
// WP Statistics: one visitor row per day with agent/platform/device and hits;
// exclusions by reason; events with JSON event_data.
// ---------------------------------------------------------------------------

$tech = Importer_Base::tech_rows(
	'2026-03-02',
	[
		[ 'browser' => 'Chrome', 'os' => 'Windows', 'device' => 'desktop', 'visitors' => '3', 'pageviews' => '7' ],
		[ 'browser' => 'Chrome Mobile', 'os' => 'Android', 'device' => 'smartphone', 'visitors' => '2', 'pageviews' => '2' ],
		[ 'browser' => 'Mobile Safari', 'os' => 'iOS', 'device' => 'smartphone', 'visitors' => '1', 'pageviews' => '4' ],
		[ 'browser' => 'Safari', 'os' => 'iPadOS', 'device' => 'tablet', 'visitors' => '1', 'pageviews' => '1' ],
		[ 'browser' => 'Firefox', 'os' => 'GNU/Linux', 'device' => 'desktop', 'visitors' => '1', 'pageviews' => '1' ],
		[ 'browser' => 'Safari', 'os' => 'Mac', 'device' => 'desktop', 'visitors' => '1', 'pageviews' => '3' ],
	]
);
$keyed = [];
foreach ( $tech as $r ) {
	$keyed[ $r['browser'] . '/' . $r['os'] . '/' . $r['device_type'] ] = [ $r['visitors'], $r['pageviews'] ];
}
ok(
	[
		'Chrome/Windows/Desktop'  => [ 3, 7 ],
		'Chrome/Android/Mobile'   => [ 2, 2 ],
		'Safari/iOS/Mobile'       => [ 1, 4 ],
		'Safari/iOS/Tablet'       => [ 1, 1 ],
		'Firefox/Linux/Desktop'   => [ 1, 1 ],
		'Safari/macOS/Desktop'    => [ 1, 3 ],
	] === $keyed,
	'WP Statistics: DeviceDetector names map onto the tracker labels (' . json_encode( $keyed ) . ')'
);

$bots = WP_Statistics_Importer::bot_rows(
	'2026-03-02',
	[
		[ 'reason' => 'robot', 'hits' => '40' ],
		[ 'reason' => 'robot_threshold', 'hits' => '5' ],
		[ 'reason' => 'headless', 'hits' => '2' ],
		[ 'reason' => 'user role', 'hits' => '30' ],
		[ 'reason' => 'ip match', 'hits' => '9' ],
	]
);
ok( [ 'other' => 40, 'repeat' => 5, 'automation' => 2 ] === array_column( $bots, 'hits', 'category' ), 'WP Statistics: only bot exclusions count as filtered bots (' . json_encode( array_column( $bots, 'hits', 'category' ) ) . ')' );

$ev = [];
foreach (
	WP_Statistics_Importer::event_rows(
		'2026-03-02',
		[
			[ 'event_name' => 'click', 'event_data' => '{"target_url":"https://github.com/a","text":"x"}', 'visitor_id' => '1' ],
			[ 'event_name' => 'mouseup', 'event_data' => '{"target_url":"https://github.com/b"}', 'visitor_id' => '2' ],
			[ 'event_name' => 'click', 'event_data' => '{"target_url":"https://example.com/about/"}', 'visitor_id' => '2' ],
			[ 'event_name' => 'file_download', 'event_data' => '{"target_url":"https://example.com/f/report.zip"}', 'visitor_id' => '3' ],
			[ 'event_name' => 'signup', 'event_data' => '{"plan":"pro"}', 'visitor_id' => '3' ],
			[ 'event_name' => 'signup', 'event_data' => '{"plan":"free"}', 'visitor_id' => '3' ],
			[ 'event_name' => 'page_view', 'event_data' => '{}', 'visitor_id' => '3' ],
		],
		'example.com'
	) as $e
) {
	$ev[ $e['name'] . ' ' . $e['value'] ] = [ $e['count'], $e['unique_count'] ];
}
ksort( $ev );
ok( [ 'download report.zip' => [ 1, 1 ], 'outbound github.com' => [ 2, 2 ], 'signup ' => [ 2, 1 ] ] === $ev, 'WP Statistics: clicks, downloads and custom events (' . json_encode( $ev ) . ')' );

// ---------------------------------------------------------------------------
// SlimStat: one row per pageview, but also per download and per crawler hit.
// ---------------------------------------------------------------------------

$tech = Importer_Base::tech_rows(
	'2026-03-02',
	SlimStat_Importer::with_device(
		[
			[ 'browser' => 'Chrome', 'os' => 'win10', 'browser_type' => '0', 'pageviews' => '5', 'visitors' => '2' ],
			[ 'browser' => 'Safari', 'os' => 'ios', 'browser_type' => '2', 'pageviews' => '3', 'visitors' => '1' ],
			[ 'browser' => 'Firefox', 'os' => 'macosx', 'browser_type' => '0', 'pageviews' => '1', 'visitors' => '1' ],
		]
	)
);
$keyed = [];
foreach ( $tech as $r ) {
	$keyed[ $r['browser'] . '/' . $r['os'] . '/' . $r['device_type'] ] = $r['pageviews'];
}
ok( [ 'Chrome/Windows/Desktop' => 5, 'Safari/iOS/Mobile' => 3, 'Firefox/macOS/Desktop' => 1 ] === $keyed, 'SlimStat: browser_type 2 is Mobile, platform codes map (' . json_encode( $keyed ) . ')' );

$missing = SlimStat_Importer::missing_rows(
	'2026-03-02',
	[
		[ 'resource' => '/gone/', 'referer' => 'https://example.com/blog/', 'hits' => '2' ],
		[ 'resource' => '/gone/', 'referer' => 'https://www.google.com/', 'hits' => '1' ],
		[ 'resource' => '/gone/', 'referer' => '', 'hits' => '4' ],
		[ 'resource' => '/gone/', 'referer' => 'https://example.com/gone/', 'hits' => '1' ],
	],
	'example.com'
);
$keyed = [];
foreach ( $missing as $m ) {
	$keyed[ $m['referrer'] . '|' . $m['internal_ref'] ] = $m['hits'];
}
ok( [ '|/blog/' => 2, 'google.com|' => 1, '|' => 5 ] === $keyed, 'SlimStat: a 404 keeps the page that linked to it, and a self-referring one is a claim, not a link (' . json_encode( $keyed ) . ')' );

$bots = SlimStat_Importer::bot_rows(
	'2026-03-02',
	[
		[ 'user_agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', 'hits' => '6' ],
		[ 'user_agent' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.1; +https://openai.com/gptbot)', 'hits' => '2' ],
		[ 'user_agent' => 'FeedBurner/1.0 (http://www.FeedBurner.com)', 'hits' => '1' ],
	]
);
$cats = array_column( $bots, 'hits', 'category' );
ok( 9 === array_sum( $cats ) && 6 === ( $cats['search'] ?? 0 ) && 2 === ( $cats['ai'] ?? 0 ), 'SlimStat: crawler rows become filtered bots in the bot filter\'s categories (' . json_encode( $cats ) . ')' );

$ev = [];
foreach (
	SlimStat_Importer::event_rows(
		'2026-03-02',
		[ [ 'resource' => '/wp-content/uploads/guide.pdf', 'hits' => '3', 'visitors' => '2' ] ],
		[
			[ 'outbound_resource' => 'https://github.com/a;;;https://github.com/b', 'ip' => '1.1.1.1' ],
			[ 'outbound_resource' => 'https://wordpress.org/', 'ip' => '2.2.2.2' ],
			[ 'outbound_resource' => 'https://example.com/internal/', 'ip' => '2.2.2.2' ],
		],
		'example.com'
	) as $e
) {
	$ev[ $e['name'] . ' ' . $e['value'] ] = [ $e['count'], $e['unique_count'] ];
}
ksort( $ev );
ok( [ 'download guide.pdf' => [ 3, 2 ], 'outbound github.com' => [ 2, 1 ], 'outbound wordpress.org' => [ 1, 1 ] ] === $ev, 'SlimStat: downloads by file name, outbound clicks by host (' . json_encode( $ev ) . ')' );

// ---------------------------------------------------------------------------
// Statify: one row per view with the referrer and the target path.
// ---------------------------------------------------------------------------

$refs = Statify_Importer::referrer_rows(
	'2026-03-02',
	[
		[ 'referrer' => 'https://www.google.com/', 'target' => '/alpha/', 'views' => '3' ],
		[ 'referrer' => 'https://google.com/search', 'target' => '/bravo/', 'views' => '1' ],
		[ 'referrer' => 'https://t.co/x', 'target' => '/category/news/', 'views' => '2' ],
	],
	[ '/alpha/' => 5, '/bravo/' => 6, '/category/news/' => 0 ],
	'example.com'
);
ok( [ 'google.com' => 4, 't.co' => 2 ] === array_column( $refs['site'], 'pageviews', 'host' ), 'Statify: referrers by host, www folded in' );
$posts = [];
foreach ( $refs['posts'] as $r ) {
	$posts[ $r['post_id'] . ' ' . $r['host'] ] = $r['pageviews'];
}
ok( [ '5 google.com' => 3, '6 google.com' => 1, '0 t.co' => 2 ] === $posts, 'Statify: referrers per post follow the target, an archive has no post (' . json_encode( $posts ) . ')' );
ok( 0 === max( array_column( $refs['site'], 'visitors' ) ), 'Statify: no visitors are made up' );

// ---------------------------------------------------------------------------
// Google Analytics 4: rows as runReport returns them, [date, dimension] with
// metrics [eventCount, totalUsers].
// ---------------------------------------------------------------------------

$ga = static function ( string $report, array $rows ): array {
	$out = [];
	foreach ( GA4_Importer::event_rows( $report, $rows ) as $e ) {
		$out[ $e['date'] . ' ' . $e['name'] . ' ' . $e['value'] ] = [ $e['count'], $e['unique_count'] ];
	}
	ksort( $out );
	return $out;
};
ok(
	[ '2026-03-02 outbound youtube.com' => [ 4, 3 ] ] === $ga(
		'outbound',
		[
			[ 'dimensions' => [ '20260302', 'youtube.com' ], 'metrics' => [ '4', '3' ] ],
			[ 'dimensions' => [ '20260302', '(not set)' ], 'metrics' => [ '1', '1' ] ],
		]
	),
	'GA4: outbound clicks by link domain, (not set) dropped'
);
ok(
	[ '2026-03-02 download dinner-menu.pdf' => [ 2, 2 ] ] === $ga( 'downloads', [ [ 'dimensions' => [ '20260302', '/menus/dinner-menu.pdf' ], 'metrics' => [ '2', '2' ] ] ] ),
	'GA4: downloads by file name'
);
$events = $ga(
	'events',
	[
		[ 'dimensions' => [ '20260302', 'page_view' ], 'metrics' => [ '900', '300' ] ],
		[ 'dimensions' => [ '20260302', 'session_start' ], 'metrics' => [ '320', '300' ] ],
		[ 'dimensions' => [ '20260302', 'click' ], 'metrics' => [ '4', '3' ] ],
		[ 'dimensions' => [ '20260302', 'form_submit' ], 'metrics' => [ '5', '5' ] ],
		[ 'dimensions' => [ '20260302', 'sign_up' ], 'metrics' => [ '7', '6' ] ],
	]
);
ok( [ '2026-03-02 form_submit ' => [ 5, 5 ], '2026-03-02 sign_up ' => [ 7, 6 ] ] === $events, 'GA4: automatic events are left out, the site\'s own come across (' . json_encode( $events ) . ')' );

// ---------------------------------------------------------------------------
// Every self::method() an importer calls exists. Most of the import path only
// runs against a database, so a helper renamed or moved out from under a
// caller would otherwise surface as a fatal in the middle of someone's import.
// ---------------------------------------------------------------------------

$classes = [ Burst_Importer::class, Independent_Analytics_Importer::class, WP_Statistics_Importer::class, SlimStat_Importer::class, Statify_Importer::class, GA4_Importer::class, \VireoAnalytics\Importers\Plausible_Importer::class ];
$checked = 0;
foreach ( $classes as $class ) {
	$file = ( new ReflectionClass( $class ) )->getFileName();
	preg_match_all( '/(?:self|static)::([a-z_][a-z0-9_]*)\s*\(/i', (string) file_get_contents( $file ), $m );
	foreach ( array_unique( $m[1] ) as $method ) {
		++$checked;
		ok( method_exists( $class, $method ), "$class calls self::$method(), which exists" );
	}
}
ok( $checked > 40, "the call check looked at enough calls to mean something ($checked)" );

// ---------------------------------------------------------------------------
// The new write helpers honour the cutoff like the old ones.
// ---------------------------------------------------------------------------

final class Fake_Wpdb {
	public string $prefix     = 'wp_';
	public string $last_error = '';
	public array $writes      = [];
	private array $args       = [];

	public function prepare( $sql, ...$args ) {
		$this->args = $args;
		return $sql;
	}

	public function query( $sql ) {
		$this->writes[] = $this->args;
		return 1;
	}
}

final class Probe_Importer extends Importer_Base {
	public function slug(): string { return 'probe'; }
	public function name(): string { return 'Probe'; }
	public function is_available(): bool { return true; }
	public function count_records(): int { return 0; }
	public function import_chunk( array $params, int $offset, int $limit ): array { return []; }

	public function events( array $rows ): void { $this->upsert_event_stats_bulk( $rows ); }
	public function missing( array $rows ): void { $this->upsert_404_stats_bulk( $rows ); }
	public function bots( array $rows ): void { $this->upsert_bot_stats_bulk( $rows ); }
}

$wpdb = new Fake_Wpdb();
$imp  = new Probe_Importer();
$imp->set_cutoff( '2026-09-01' );
$imp->events(
	[
		[ 'date' => '2026-08-31', 'name' => 'outbound', 'value' => 'github.com', 'count' => 3, 'unique_count' => 2 ],
		[ 'date' => '2026-09-01', 'name' => 'outbound', 'value' => 'github.com', 'count' => 3, 'unique_count' => 2 ],
		[ 'date' => '2026-08-31', 'name' => '', 'value' => 'x', 'count' => 3, 'unique_count' => 2 ],
	]
);
ok( 1 === count( $wpdb->writes ) && [ 'wp_va_event_stats', '2026-08-31', 'outbound', 'github.com', 3, 2 ] === $wpdb->writes[0], 'events stop at the cutoff and skip nameless rows' );

$wpdb->writes = [];
$imp->missing( [ [ 'date' => '2026-08-31', 'path' => '/gone/', 'referrer' => '', 'internal_ref' => '/home/', 'hits' => 4 ] ] );
ok( 1 === count( $wpdb->writes ) && md5( "/gone/\0\0/home/" ) === $wpdb->writes[0][2], 'a 404 row is keyed exactly as the 404 tracker keys it' );

$wpdb->writes = [];
$imp->bots(
	[
		[ 'date' => '2026-08-31', 'category' => 'other', 'hits' => 9 ],
		[ 'date' => '2026-09-02', 'category' => 'other', 'hits' => 9 ],
	]
);
ok( 1 === count( $wpdb->writes ) && 9 === $wpdb->writes[0][3], 'filtered-bot counts stop at the cutoff' );

// ---------------------------------------------------------------------------
// Imported 404 strings are cut at the column width without splitting a
// multibyte character. substr() cut by byte, so a path ending in "é" on the
// boundary was stored with a lone lead byte: invalid UTF-8 that MySQL rejects
// or mangles, and a hash key that never matches the tracker's.
// ---------------------------------------------------------------------------

$wpdb->writes = [];
$fits  = str_repeat( 'a', 253 ) . 'é'; // 255 bytes: fits whole.
$over  = str_repeat( 'a', 254 ) . 'é'; // 256 bytes: the é must go, not be split.
$imp->missing(
	[
		[ 'date' => '2026-08-31', 'path' => $fits, 'referrer' => $over, 'internal_ref' => $over, 'hits' => 1 ],
	]
);
[ , , , $path_out, $ref_out, $iref_out ] = $wpdb->writes[0];
ok( $fits === $path_out, 'a 255-byte path ending in a multibyte character is stored whole' );
ok( str_repeat( 'a', 254 ) === $ref_out, 'a 256-byte referrer loses the whole trailing character, not half of it' );
ok( str_repeat( 'a', 254 ) === $iref_out, 'the internal referrer is cut the same way' );
ok( 1 === preg_match( '//u', $ref_out ) && strlen( $ref_out ) <= 255, 'the stored referrer is valid UTF-8 within the column width' );

// ---------------------------------------------------------------------------
// Imported event names and values, and 404 strings, get the tracker's
// treatment. A source plugin's export is as untrusted as a beacon: it is
// written by whatever a visitor sent that plugin, and ends up printed on the
// dashboard and in the weekly email.
// ---------------------------------------------------------------------------

$wpdb->writes = [];
$imp->events(
	[
		[ 'date' => '2026-08-31', 'name' => '<svg onload=alert(1)>', 'value' => '<svg onload=alert(1)>x', 'count' => 1, 'unique_count' => 1 ],
		[ 'date' => '2026-08-31', 'name' => 'Outbound Click', 'value' => "git\x00hub.com <b>bold</b>", 'count' => 1, 'unique_count' => 1 ],
		[ 'date' => '2026-08-31', 'name' => '<>', 'value' => 'x', 'count' => 1, 'unique_count' => 1 ],
	]
);
ok( 2 === count( $wpdb->writes ), 'an event whose name has nothing left after the name rule is dropped' );
ok( 'svgonloadalert1' === ( $wpdb->writes[0][2] ?? '' ), 'an event name is reduced to the tracker\'s [a-z0-9_-] alphabet: ' . ( $wpdb->writes[0][2] ?? '' ) );
ok( 'x' === ( $wpdb->writes[0][3] ?? '' ), 'an event value loses its markup: ' . ( $wpdb->writes[0][3] ?? '' ) );
ok( 'outboundclick' === ( $wpdb->writes[1][2] ?? '' ), 'a mixed-case name with a space maps the way the tracker maps it: ' . ( $wpdb->writes[1][2] ?? '' ) );
ok( 'github.com bold' === ( $wpdb->writes[1][3] ?? '' ), 'a value loses control characters and tags but keeps its text: ' . ( $wpdb->writes[1][3] ?? '' ) );

$wpdb->writes = [];
$imp->missing(
	[
		[ 'date' => '2026-08-31', 'path' => '/<svg onload=alert(1)>gone/', 'referrer' => "https://evil.example/<script>alert(1)</script>ref", 'internal_ref' => "/home/\x07", 'hits' => 2 ],
		[ 'date' => '2026-08-31', 'path' => '<script>alert(1)</script>', 'referrer' => '', 'internal_ref' => '', 'hits' => 2 ],
	]
);
ok( 1 === count( $wpdb->writes ), 'a 404 row whose path is only markup is dropped' );
[ , , , $path_out, $ref_out, $iref_out ] = $wpdb->writes[0];
ok( '/gone/' === $path_out, 'a 404 path loses its markup: ' . $path_out );
ok( 'https://evil.example/ref' === $ref_out, 'a 404 referrer loses script tags and their body: ' . $ref_out );
ok( '/home/' === $iref_out, 'an internal referrer loses control characters: ' . $iref_out );

// The tracker cuts an event name to the column's 100 bytes after applying the
// name rule; the importer has to as well or the insert fails on a long name.
$wpdb->writes = [];
$imp->events( [ [ 'date' => '2026-08-31', 'name' => str_repeat( 'a', 150 ), 'value' => '', 'count' => 1, 'unique_count' => 1 ] ] );
ok( str_repeat( 'a', 100 ) === ( $wpdb->writes[0][2] ?? '' ), 'an imported event name is cut to 100 bytes like a recorded one' );
ok( str_repeat( 'a', 100 ) === Importer_Base::event_name( str_repeat( 'A', 120 ) . '!!' ), 'the cut happens after the name rule, so it is 100 bytes of the mapped name' );

// A 404 path a source stored still encoded has to land under the same entry
// hash the tracker writes for that request, or the two never merge.
// sanitize_text_field() removes percent-encoded octets, so without a decode
// first /caf%C3%A9/ became /caf/.
ok( '/café/' === Collector::decode_path( '/caf%C3%A9/' ), 'the shared path normaliser decodes before it sanitises: ' . Collector::decode_path( '/caf%C3%A9/' ) );
ok( '/gone/' === Collector::decode_path( '/%3Csvg onload=alert(1)%3Egone/' ), 'markup that was percent-encoded is still stripped after decoding: ' . Collector::decode_path( '/%3Csvg onload=alert(1)%3Egone/' ) );
ok( '/a b/' === Collector::decode_path( "/a%20b/\x07" ), 'a control character goes, a decoded space stays: ' . Collector::decode_path( "/a%20b/\x07" ) );
$wpdb->writes = [];
$imp->missing( [ [ 'date' => '2026-08-31', 'path' => '/caf%C3%A9/', 'referrer' => '', 'internal_ref' => '', 'hits' => 1 ] ] );
ok( '/café/' === ( $wpdb->writes[0][3] ?? '' ), 'an encoded imported path is stored decoded: ' . ( $wpdb->writes[0][3] ?? '' ) );
ok( md5( Collector::decode_path( '/caf%C3%A9/' ) . "\0\0" ) === ( $wpdb->writes[0][2] ?? '' ), 'and keyed by the hash the tracker computes from the same normaliser' );

echo $fail ? "\n$fail failed\n" : "\nall passed\n";
exit( $fail ? 1 : 0 );
