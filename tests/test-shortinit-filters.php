<?php
/**
 * Zero-dependency check of Endpoint_Installer::SHORTINIT_BLIND_FILTERS, the
 * filters that keep a site off the fast endpoint when it sets them.
 * Run: php tests/test-shortinit-filters.php
 *
 * Under SHORTINIT no plugin or theme code loads, so a filter applied on the
 * fast path always returns its default there. The list is how the plugin
 * notices a site has customised one and keeps it on admin-ajax. A name in the
 * list that the plugin never applies protects nothing, and a filter the fast
 * path applies that is missing from the list is silently ignored.
 *
 * Control: VA_ROOT=<a 0.12.4 tree> fails on vireo_beacon_rate_limit_file_shards
 * (never applied) and on the three filters the list missed:
 *   mkdir /tmp/0124 && git archive v0.12.4 | tar -x -C /tmp/0124
 *   VA_ROOT=/tmp/0124 php tests/test-shortinit-filters.php
 */

$root = getenv( 'VA_ROOT' ) ?: dirname( __DIR__ );

$fail = 0;
function ok( $cond, $msg ) {
	global $fail;
	echo ( $cond ? 'PASS: ' : 'FAIL: ' ) . $msg . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}

/** Filter names passed as a literal to apply_filters() in $file. */
function applied_filters( string $file ): array {
	preg_match_all( '/apply_filters\(\s*\'([a-z0-9_]+)\'/', (string) file_get_contents( $file ), $m );
	return array_values( array_unique( $m[1] ) );
}

// The list, read from the source rather than loaded, so the class's
// WordPress dependencies don't need stubbing.
$installer = file_get_contents( "$root/includes/class-endpoint-installer.php" );
preg_match( '/SHORTINIT_BLIND_FILTERS\s*=\s*\[(.*?)\];/s', $installer, $m );
preg_match_all( '/\'([a-z0-9_]+)\'/', $m[1] ?? '', $names );
$listed = $names[1];
ok( count( $listed ) >= 5, 'the list was read (' . count( $listed ) . ' names)' );

// Every filter the plugin applies anywhere.
$files = array_merge(
	glob( "$root/includes/*.php" ),
	glob( "$root/includes/*/*.php" ),
	glob( "$root/importers/*.php" ),
	[ "$root/vireo-analytics.php", "$root/vireo-analytics-collect.php", "$root/endpoint.php" ]
);
$applied = [];
foreach ( array_filter( $files, 'is_file' ) as $f ) {
	$applied = array_merge( $applied, applied_filters( $f ) );
}
$applied = array_unique( $applied );
ok( count( $applied ) >= 30, 'the plugin\'s applied filters were found (' . count( $applied ) . ')' );

foreach ( $listed as $name ) {
	ok( in_array( $name, $applied, true ), "$name is a filter the plugin applies" );
}

// Every filter applied in a file endpoint.php loads must be listed, unless it
// only runs behind one that is.
$endpoint = (string) file_get_contents( "$root/endpoint.php" );
preg_match_all( '/require_once __DIR__ \. \'\/([^\']+\.php)\'/', $endpoint, $req );
ok( count( $req[1] ) >= 8, 'endpoint.php\'s own includes were found (' . count( $req[1] ) . ')' );

$behind_listed = [
	// Only read once vireo_trust_proxy_headers is on, which is listed.
	'vireo_trusted_proxies',
];
$fast_path = [];
foreach ( array_merge( [ 'endpoint.php' ], $req[1] ) as $rel ) {
	foreach ( applied_filters( "$root/$rel" ) as $name ) {
		$fast_path[ $name ] = $rel;
	}
}
ok( count( $fast_path ) >= 8, 'filters applied on the fast path were found (' . count( $fast_path ) . ')' );
foreach ( $fast_path as $name => $rel ) {
	if ( in_array( $name, $behind_listed, true ) ) {
		continue;
	}
	ok( in_array( $name, $listed, true ), "$name, applied in $rel on the fast path, keeps a site that sets it on admin-ajax" );
}

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
