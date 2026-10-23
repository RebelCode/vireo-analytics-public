<?php
/**
 * Zero-dependency check of the Plausible export parser: which report each CSV
 * is, what each column maps to, what is dropped, and how the result is split
 * into batches.
 * Run: php tests/test-plausible-import.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}
if ( ! function_exists( '__' ) ) {
	function __( $s, $d = null ) { return $s; }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) { return json_encode( $data ); }
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
}

require __DIR__ . '/../includes/class-collector.php';
require __DIR__ . '/../includes/class-importer-base.php';
require __DIR__ . '/../importers/class-plausible-importer.php';

use VireoAnalytics\Importers\Plausible_Importer;

$fail = 0;
function ok( $cond, $msg ) {
	global $fail;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}

function stream_of( string $csv ) {
	$h = fopen( 'php://memory', 'r+' );
	fwrite( $h, $csv );
	rewind( $h );
	return $h;
}

function parse( array $csvs, string $from = '', string $to = '' ): array {
	$acc     = Plausible_Importer::new_accumulator( $from, $to, 'www.example.com' );
	$reports = [];
	foreach ( $csvs as $csv ) {
		$reports[] = Plausible_Importer::read_csv( stream_of( $csv ), $acc );
	}
	$acc['seen'] = $reports;
	return $acc;
}

/** Sum [pageviews, visitors] over every key and day of one kind. */
function totals( array $acc, string $kind ): array {
	$pv = 0;
	$v  = 0;
	foreach ( $acc['data'][ $kind ] ?? [] as $keys ) {
		foreach ( $keys as $c ) {
			$pv += $c[0];
			$v  += $c[1];
		}
	}
	return [ $pv, $v ];
}

// Headers exactly as Plausible's CSV importer declares them
// (lib/plausible/imported/csv_importer.ex, input_structures).
$visitors = "\xEF\xBB\xBF" . "date,visitors,pageviews,bounces,visits,visit_duration\n"
	. "2024-01-01,10,25,4,12,300\n"
	. "2024-01-02,7,9,3,8,100\n"
	. "2024-02-30,99,99,0,0,0\n"   // Not a real date.
	. "2024-01-03,0,0,0,0,0\n";    // Nothing to store.

$pages = "date,hostname,page,visits,visitors,pageviews,total_scroll_depth,total_scroll_depth_visits,total_time_on_page,total_time_on_page_visits\n"
	. "2024-01-01,example.com,/,5,5,12,0,0,0,0\n"
	. "2024-01-01,example.com,/blog/?utm_source=x,2,2,3,0,0,0,0\n"
	. "2024-01-01,example.com,/blog/,1,1,1,0,0,0,0\n"
	. "2024-01-01,shop.example.com,/,1,1,2,0,0,0,0\n"
	. "2024-01-02,example.com,about,3,3,4,0,0,0,0\n"
	. "2024-01-02,example.com,,9,9,9,0,0,0,0\n";

$sources = "date,source,referrer,utm_source,utm_medium,utm_campaign,utm_content,utm_term,pageviews,visitors,visits,visit_duration,bounces\n"
	. "2024-01-01,Hacker News,news.ycombinator.com/item,,,,,,6,3,3,10,1\n"
	. "2024-01-01,Hacker News,news.ycombinator.com,,,launch,,,2,1,1,10,0\n"
	. "2024-01-01,Google,www.google.com,,,,,,4,2,2,10,1\n"
	. "2024-01-01,,,,,,,,8,5,5,10,2\n"                       // Direct.
	. "2024-01-01,Newsletter,,newsletter,email,,,,3,2,2,10,0\n" // utm_source only.
	. "2024-01-02,example.com,https://www.example.com/x,,,,,,5,5,5,0,0\n"; // Self-referral.

$locations = "date,country,region,city,visitors,visits,visit_duration,bounces,pageviews\n"
	. "2024-01-01,PT,PT-11,2267057,3,3,10,1,5\n"
	. "2024-01-01,PT,PT-13,2735943,2,2,10,1,4\n"
	. "2024-01-01,us,US-CA,0,4,4,10,1,6\n"
	. "2024-01-01,ZZZ,,0,1,1,0,0,1\n";

$devices = "date,device,visitors,visits,visit_duration,bounces,pageviews\n"
	. "2024-01-01,Desktop,4,4,0,0,10\n"
	. "2024-01-01,Laptop,1,1,0,0,2\n"
	. "2024-01-01,Mobile,5,5,0,0,13\n"
	. "2024-01-01,(not set),1,1,0,0,1\n";

$browsers = "date,browser,browser_version,visitors,visits,visit_duration,bounces,pageviews\n2024-01-01,Chrome,120,5,5,0,0,9\n";
$entry    = "date,entry_page,visitors,entrances,visit_duration,bounces,pageviews\n2024-01-01,/,5,5,0,0,9\n";
$props    = "date,property,value,visitors,events\n2024-01-01,url,x,1,1\n";
$events   = "date,name,link_url,path,visitors,events\n2024-01-01,Outbound Link: Click,https://a.example,,1,1\n";
$os       = "date,operating_system,operating_system_version,visitors,visits,visit_duration,bounces,pageviews\n2024-01-01,Mac,14,5,5,0,0,9\n";
$exits    = "date,exit_page,visitors,visit_duration,exits,bounces,pageviews\n2024-01-01,/,5,0,5,0,9\n";

$acc = parse( [ $visitors, $pages, $sources, $locations, $devices, $browsers, $entry, $props, $events, $os, $exits ] );

ok(
	[ 'visitors', 'pages', 'sources', 'locations', 'devices', 'browsers', 'entry_pages', 'custom_props', 'custom_events', 'operating_systems', 'exit_pages' ] === $acc['seen'],
	'every Plausible report is recognised by its columns (got ' . implode( ',', $acc['seen'] ) . ')'
);
ok( [ 'site', 'pages', 'referrers', 'countries', 'devices', 'events' ] === array_keys( $acc['data'] ), 'only the mapped reports produce data (got ' . implode( ',', array_keys( $acc['data'] ) ) . ')' );
ok( [ 1, 1 ] === ( $acc['data']['events']['2024-01-01']['["outbound","a.example"]'] ?? null ), 'an outbound click is stored as the tracker\'s outbound event, by host' );

// Goals and custom events, in the columns Plausible's export writes.
$ev = parse(
	[
		"date,name,link_url,path,visitors,events\n"
		. "2024-01-01,Outbound Link: Click,https://github.com/a,,2,3\n"
		. "2024-01-01,Outbound Link: Click,https://www.github.com/b,,1,1\n"
		. "2024-01-01,Outbound Link: Click,https://www.example.com/self,,1,1\n"
		. "2024-01-01,File Download,https://example.com/f/guide.pdf,,4,5\n"
		. "2024-01-01,404,,/old-post/,3,6\n"
		. "2024-01-01,Form: Submission,,/contact/,2,2\n"
		. "2024-01-01,Signup,,,7,9\n"
		. "2024-01-01,engagement,,,9,40\n",
	]
);
$e = $ev['data']['events']['2024-01-01'];
ksort( $e );
ok(
	[
		'["Signup",""]'           => [ 9, 7 ],
		'["download","guide.pdf"]' => [ 5, 4 ],
		'["form_submit",""]'      => [ 2, 2 ],
		'["outbound","github.com"]' => [ 3, 2 ],
		'["outbound","www.github.com"]' => [ 1, 1 ],
	] === $e,
	'events map onto the tracker\'s names, own-site links and engagement are dropped (' . json_encode( $e ) . ')'
);
ok( [ 6, 3 ] === ( $ev['data']['missing']['2024-01-01']['/old-post/'] ?? null ), 'a 404 event goes to missing pages with its path and event count' );

// Daily totals. CSV: 25+9 pageviews, 10+7 visitors on valid, non-empty days.
ok( [ 34, 17 ] === totals( $acc, 'site' ), 'site totals are 34 pageviews / 17 visitors (got ' . implode( '/', totals( $acc, 'site' ) ) . ')' );
ok( [ 25, 10 ] === $acc['data']['site']['2024-01-01'][''], 'pageviews and visitors land in the right slots (2024-01-01 = 25/10)' );
ok( ! isset( $acc['data']['site']['2024-02-30'] ), 'an impossible date is dropped' );
ok( ! isset( $acc['data']['site']['2024-01-03'] ), 'an all-zero day is dropped' );

// Pages: query string stripped and merged, hostnames merged, slash added, blank dropped.
ok( [ 14, 6 ] === $acc['data']['pages']['2024-01-01']['/'], 'the same path on two hostnames is summed (14/6)' );
ok( [ 4, 3 ] === $acc['data']['pages']['2024-01-01']['/blog/'], 'a query string is stripped and the rows merged (4/3)' );
ok( isset( $acc['data']['pages']['2024-01-02']['/about'] ), 'a path without a leading slash gets one' );
ok( 1 === count( $acc['data']['pages']['2024-01-02'] ), 'a row with no page is dropped' );
ok( [ 22, 12 ] === totals( $acc, 'pages' ), 'page totals are 22/12 (got ' . implode( '/', totals( $acc, 'pages' ) ) . ')' );

// Referrers: host only, www stripped, direct / utm-only / self dropped.
$refs = $acc['data']['referrers']['2024-01-01'];
ok( [ 8, 4 ] === $refs['news.ycombinator.com'], 'two referrer URLs on one host are summed (8/4)' );
ok( isset( $refs['google.com'] ) && ! isset( $refs['www.google.com'] ), 'www. is stripped from referrer hosts' );
ok( 2 === count( $refs ), 'direct and utm-only rows are not stored as referrers (got ' . implode( ',', array_keys( $refs ) ) . ')' );
ok( ! isset( $acc['data']['referrers']['2024-01-02'] ), 'a referral from the site itself is dropped' );

// Countries: summed across regions and cities, uppercased, junk dropped.
$geo = $acc['data']['countries']['2024-01-01'];
ok( [ 9, 5 ] === $geo['PT'], 'regions of one country are summed (9/5)' );
ok( isset( $geo['US'] ), 'a lowercase country code is uppercased' );
ok( 2 === count( $geo ), 'a code that is not two letters is dropped' );

// Devices: Laptop folds into Desktop, (not set) becomes blank.
$dev = $acc['data']['devices']['2024-01-01'];
ok( [ 12, 5 ] === $dev['Desktop'], 'Laptop is counted as Desktop (12/5)' );
ok( [ 1, 1 ] === $dev[''], '(not set) is stored as an unknown device' );
ok( [ 26, 11 ] === totals( $acc, 'devices' ), 'device totals are 26/11 (got ' . implode( '/', totals( $acc, 'devices' ) ) . ')' );

// Columns are read by name, not position.
$reordered = parse( [ "pageviews,date,visitors\n40,2024-03-01,30\n" ] );
ok( [ 40, 30 ] === $reordered['data']['site']['2024-03-01'][''], 'a reordered visitors file still maps by column name' );

// Date range.
$ranged = parse( [ $visitors ], '2024-01-02', '2024-01-31' );
ok( [ '2024-01-02' ] === array_keys( $ranged['data']['site'] ), 'rows outside the chosen dates are skipped' );

// Not a Plausible file.
$other = parse( [ "foo,bar\n1,2\n" ] );
ok( '' === $other['seen'][0] && empty( $other['data'] ), 'a CSV that is not a Plausible report is ignored' );

// A mapped report missing a column it needs is an error, not a silent zero.
$threw = false;
try {
	parse( [ "date,page,pageviews\n2024-01-01,/,1\n" ] );
} catch ( \RuntimeException $e ) {
	$threw = false !== strpos( $e->getMessage(), 'visitors' );
}
ok( $threw, 'a pages file without visitors is rejected, naming the column' );

// Plausible's importer fills missing columns with zero, and the exports in its
// own test suite (test/plausible/imported/csv_importer_test.exs) use these
// narrower headers: quoted names, no pageviews on session reports, and a
// sources file that names each source without its referrer.
$narrow = parse(
	[
		"\"date\",\"device\",\"visitors\",\"visit_duration\",\"bounces\"\n\"2021-12-30\",\"Mobile\",12,0,3\n\"2021-12-30\",\"Tablet\",2,0,1\n",
		"\"date\",\"country\",\"region\",\"city\",\"visitors\",\"visits\",\"visit_duration\",\"bounces\"\n\"2021-12-30\",\"NL\",\"\",0,1,1,0,1\n\"2021-12-30\",\"NL\",\"\",2745321,2,2,0,1\n",
		"\"date\",\"source\",\"utm_medium\",\"utm_campaign\",\"utm_content\",\"utm_term\",\"visitors\",\"visits\",\"visit_duration\",\"bounces\"\n\"2021-12-30\",\"Google\",\"organic\",\"\",\"\",\"\",9,9,0,4\n",
		"\"date\",\"visitors\",\"exits\",\"exit_page\"\n\"2021-12-30\",3,3,\"/a\"\n",
		"\"date\",\"visitors\",\"pageviews\",\"hostname\",\"page\"\n\"2021-12-30\",4,6,\"example.com\",\"/a\"\n\"2021-12-30\",1,1,\"example.com\",\"/a\"\n",
	]
);
ok( [ 'devices', 'locations', 'sources_by_name', 'exit_pages', 'pages' ] === $narrow['seen'], 'narrow Plausible headers are all recognised (got ' . implode( ',', $narrow['seen'] ) . ')' );
ok( [ 0, 14 ] === totals( $narrow, 'devices' ), 'a devices file without pageviews keeps its visitors (0/14)' );
ok( [ 0, 3 ] === $narrow['data']['countries']['2021-12-30']['NL'], 'a locations file without pageviews keeps its visitors (0/3)' );
ok( ! isset( $narrow['data']['referrers'] ), 'sources named without a referrer store nothing' );
ok( [ 7, 5 ] === $narrow['data']['pages']['2021-12-30']['/a'], 'quoted page rows are parsed and summed (7/5)' );

// Decompressed size is capped in bytes, not only lines: a deflate bomb of long
// lines stays under the line cap while pushing gigabytes through the parser.
$long  = str_repeat( 'x', 4000 );
$bomb  = "date,hostname,page,visits,visitors,pageviews\n";
for ( $i = 0; $i < 50; $i++ ) {
	$bomb .= "2024-01-01,example.com,/$i$long,1,1,1\n";
}
$capped              = Plausible_Importer::new_accumulator( '', '', 'example.com' );
$capped['max_bytes'] = 100000;
$threw               = false;
try {
	Plausible_Importer::read_csv( stream_of( $bomb ), $capped );
} catch ( \RuntimeException $e ) {
	$threw = true;
}
ok( $threw, 'about 200 KB of CSV is refused under a 100 KB decompressed-bytes cap' );
ok( $capped['bytes'] <= 100000 + 65536 + 1024, 'parsing stops within a line of the cap (' . $capped['bytes'] . ' bytes counted)' );
$roomy              = Plausible_Importer::new_accumulator( '', '', 'example.com' );
$roomy['max_bytes'] = 1000000;
Plausible_Importer::read_csv( stream_of( $bomb ), $roomy );
ok( 50 === $roomy['rows'], 'the same file parses under a larger cap (' . $roomy['rows'] . ' rows)' );

// An unfinished stage is only swept once nothing has touched it for an hour,
// so loading the import screen can't cancel a run that is still going.
$now = 1800000000;
ok( ! Plausible_Importer::is_stale( [ 'batches' => 3, 'created' => $now - 60, 'touched' => 0 ], $now ), 'a stage created a minute ago is not stale' );
ok( ! Plausible_Importer::is_stale( [ 'batches' => 3, 'created' => $now - 7200, 'touched' => $now - 30 ], $now ), 'an old stage written to 30 seconds ago is not stale' );
ok( Plausible_Importer::is_stale( [ 'batches' => 3, 'created' => $now - 7200, 'touched' => $now - 3700 ], $now ), 'a stage untouched for over an hour is stale' );
ok( ! Plausible_Importer::is_stale( [ 'batches' => 0, 'created' => 0, 'touched' => 0 ], $now ), 'nothing staged is never stale' );

// Long paths and hosts are cut to the column size without splitting a
// multibyte character: invalid UTF-8 makes MySQL reject the whole chunk.
$cjk   = str_repeat( '日', 200 ); // 600 bytes.
$multi = parse(
	[
		"date,hostname,page,visits,visitors,pageviews\n2024-01-01,example.com,/$cjk,1,1,1\n",
		"date,source,referrer,utm_source,utm_medium,utm_campaign,utm_content,utm_term,pageviews,visitors,visits,visit_duration,bounces\n2024-01-01,X,a" . str_repeat( 'ü', 120 ) . ".example,,,,,,1,1,1,0,0\n",
		"date,hostname,page,visits,visitors,pageviews\n2024-01-01,example.com,/\xFF\xFEbad,1,1,1\n",
	]
);
$cut_path = (string) array_keys( $multi['data']['pages']['2024-01-01'] )[0];
$cut_host = (string) array_keys( $multi['data']['referrers']['2024-01-01'] )[0];
ok( 1 === preg_match( '//u', $cut_path ) && strlen( $cut_path ) <= 500, 'a long multibyte path is cut to 500 bytes of valid UTF-8 (' . strlen( $cut_path ) . ' bytes)' );
ok( 1 === preg_match( '//u', $cut_host ) && strlen( $cut_host ) <= 190, 'a long multibyte host is cut to 190 bytes of valid UTF-8 (' . strlen( $cut_host ) . ' bytes)' );
$all_valid = true;
foreach ( array_keys( $multi['data']['pages']['2024-01-01'] ) as $k ) {
	$all_valid = $all_valid && 1 === preg_match( '//u', (string) $k );
}
ok( $all_valid, 'a path that arrives as invalid UTF-8 is cleaned, not passed on' );

// Batching: a year of 30 pages a day, 31 rows a day with the site row.
$csv = "date,hostname,page,visits,visitors,pageviews\n";
$day = new DateTimeImmutable( '2023-01-01' );
for ( $d = 0; $d < 365; $d++ ) {
	$date = $day->modify( "+$d days" )->format( 'Y-m-d' );
	for ( $p = 0; $p < 30; $p++ ) {
		$csv .= "$date,example.com,/p/$p/,1,1,2\n";
	}
}
$big     = parse( [ $csv ] );
$batches = Plausible_Importer::batches( $big['data'] );
$dates   = [];
$rows    = 0;
$largest = 0;
foreach ( $batches as $b ) {
	$n = 0;
	foreach ( $b['data']['pages'] as $date => $keys ) {
		$dates[] = $date;
		$n      += count( $keys );
	}
	$rows   += $n;
	$largest = max( $largest, $n );
}
ok( 365 * 30 === $big['rows'], 'the parser counted 10950 distinct rows (got ' . $big['rows'] . ')' );
ok( count( $batches ) > 1, 'a year of pages is split into several batches (' . count( $batches ) . ')' );
ok( $largest <= 2000, 'no batch exceeds 2000 rows (largest ' . $largest . ')' );
ok( 365 === count( $dates ) && 365 === count( array_unique( $dates ) ), 'every day lands in exactly one batch (' . count( $dates ) . ' entries, ' . count( array_unique( $dates ) ) . ' unique)' );
ok( 365 * 30 === $rows, 'batches carry every row (' . $rows . ' of 10950)' );
$sorted = $dates;
sort( $sorted );
ok( $sorted === $dates, 'batches run in date order' );
ok( '2023-01-01' === $batches[0]['from'] && '2023-12-31' === $batches[ count( $batches ) - 1 ]['to'], 'first and last batch bound the export' );
ok( [] === Plausible_Importer::batches( [] ), 'nothing parsed means no batches' );

// Zip: entries are streamed, never extracted, so a traversal name goes nowhere.
if ( class_exists( 'ZipArchive' ) ) {
	$zip_path = tempnam( sys_get_temp_dir(), 'vap' );
	$marker   = dirname( $zip_path ) . '/vap-escaped.csv';
	@unlink( $marker );
	$zip = new ZipArchive();
	$zip->open( $zip_path, ZipArchive::OVERWRITE );
	$zip->addFromString( 'imported_visitors_20240101_20240102.csv', $visitors );
	$zip->addFromString( '../vap-escaped.csv', $pages );
	$zip->addFromString( 'notes.txt', 'ignored' );
	$zip->close();

	$importer = ( new ReflectionClass( Plausible_Importer::class ) )->newInstanceWithoutConstructor();
	$method   = new ReflectionMethod( Plausible_Importer::class, 'read_zip' );
	if ( PHP_VERSION_ID < 80100 ) {
		$method->setAccessible( true );
	}
	$zacc = Plausible_Importer::new_accumulator( '', '', 'example.com' );
	$method->invokeArgs( $importer, [ $zip_path, &$zacc ] );

	ok( [ 34, 17 ] === totals( $zacc, 'site' ), 'a zipped export parses the same as the loose CSV (34/17)' );
	ok( [ 22, 12 ] === totals( $zacc, 'pages' ), 'an entry named ../ is read in memory like any other' );
	ok( ! file_exists( $marker ), 'nothing is written outside the archive' );
	unlink( $zip_path );
} else {
	echo "SKIP zip checks: ZipArchive not available\n";
}

echo $fail ? "\n$fail failed\n" : "\nall passed\n";
exit( $fail ? 1 : 0 );
