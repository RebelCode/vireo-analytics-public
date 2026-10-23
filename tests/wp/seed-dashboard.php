<?php
/**
 * Seed two years of consistent daily rollups for the dashboard-ux branch.
 * Run inside wp-env: wp eval-file tests/wp/seed-dashboard.php (SEED_DAYS=N for fewer days)
 *
 * Every table is built from one per-day, per-path, per-referrer plan so the
 * sums agree across tables the way the aggregator makes them agree:
 *   site_stats = sum(post_stats) = sum(geo_stats) = sum(device_stats)
 *   referrer_stats[host] = sum over all paths of that host's share
 *   post_referrer_stats[post, host] = that share for paths with a post ID only
 */

global $wpdb;
$p = $wpdb->prefix . 'va_';

$days_back = (int) ( getenv( 'SEED_DAYS' ) ?: 760 );
$today     = vireo_bucket_date();

// Posts to attribute paths to.
$titles = [ 'Hello world', 'About', 'Pricing', 'Privacy', 'Announcing headless mode', 'Year-end review' ];
$posts  = [];
foreach ( $titles as $t ) {
	$existing = get_page_by_path( sanitize_title( $t ), OBJECT, [ 'post', 'page' ] );
	if ( $existing ) {
		$posts[ $t ] = (int) $existing->ID;
		continue;
	}
	$posts[ $t ] = (int) wp_insert_post( [ 'post_title' => $t, 'post_name' => sanitize_title( $t ), 'post_status' => 'publish', 'post_type' => 'post', 'post_content' => 'Seed.' ] );
}
update_option( 'permalink_structure', '/%postname%/' );
flush_rewrite_rules();

$paths = [ '/' => $posts['Hello world'] ];
foreach ( $titles as $t ) {
	if ( 'Hello world' === $t ) {
		continue;
	}
	$paths[ '/' . sanitize_title( $t ) . '/' ] = $posts[ $t ];
}
$paths['/category/news/'] = 0;

$hosts     = [ 'google.com', 'www.google.com', 'bing.com', 'chatgpt.com', 'facebook.com', 't.co', 'github.com', 'example-blog.com', 'com.google.android.gm' ];
$countries = [ 'US', 'GB', 'DE', 'ES', 'PT', 'FR', 'IN' ];
$devices   = [ [ 'Chrome', 'Windows', 'desktop' ], [ 'Safari', 'iOS', 'mobile' ], [ 'Chrome', 'Android', 'mobile' ], [ 'Firefox', 'Mac OS', 'desktop' ], [ 'Safari', 'iPadOS', 'tablet' ] ];

$ids = static function ( string $table, string $col, array $values ) use ( $wpdb, $p ): array {
	$out = [];
	foreach ( $values as $v ) {
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$p}{$table} ({$col}) VALUES (%s)", $v ) );
		$out[ $v ] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$p}{$table} WHERE {$col} = %s", $v ) );
	}
	return $out;
};
$path_ids = $ids( 'paths', 'path', array_keys( $paths ) );
$host_ids = $ids( 'referrer_urls', 'url', $hosts );

foreach ( [ 'site_stats', 'post_stats', 'referrer_stats', 'geo_stats', 'device_stats', 'post_referrer_stats', 'hour_stats' ] as $t ) {
	$wpdb->query( "DELETE FROM {$p}{$t}" );
}
delete_option( 'vireo_import_history' );

mt_srand( 42 );
$split = static function ( int $total, array $weights ): array {
	$sum = array_sum( $weights );
	$out = [];
	$left = $total;
	$keys = array_keys( $weights );
	foreach ( $keys as $i => $k ) {
		if ( $i === count( $keys ) - 1 ) {
			$out[ $k ] = $left;
			break;
		}
		$n        = (int) floor( $total * $weights[ $k ] / $sum );
		$out[ $k ] = $n;
		$left    -= $n;
	}
	return $out;
};

$rows = [ 'site' => [], 'post' => [], 'ref' => [], 'geo' => [], 'dev' => [], 'postref' => [] ];

for ( $d = $days_back; $d >= 0; $d-- ) {
	$date = gmdate( 'Y-m-d', strtotime( $today . " -{$d} days UTC" ) );
	$ts   = strtotime( $date . ' UTC' );
	$dow  = (int) gmdate( 'N', $ts );
	// Growth over time plus a weekly dip, plus noise.
	$age   = $days_back - $d;
	$base  = 180 + (int) ( $age * 0.35 ) + ( $dow >= 6 ? -60 : 0 ) + mt_rand( -40, 40 );
	$base  = max( 40, $base );
	// A spike last spring to make year-over-year interesting.
	if ( $d > 365 && $d < 372 ) {
		$base *= 3;
	}

	$path_w = [ '/' => 30, '/about/' => 8, '/pricing/' => 14, '/privacy/' => 3, '/announcing-headless-mode/' => 22, '/year-end-review/' => 10, '/category/news/' => 6 ];
	foreach ( $path_w as $k => $w ) {
		$path_w[ $k ] = $w + mt_rand( 0, 6 );
	}
	$by_path = $split( $base, $path_w );

	$site_pv = 0;
	$site_vi = 0;
	foreach ( $by_path as $path => $pv ) {
		$vi      = (int) round( $pv * 0.62 );
		$post_id = $paths[ $path ];
		$site_pv += $pv;
		$site_vi += $vi;
		$rows['post'][] = $wpdb->prepare( '(%s,%d,%d,%d,%d)', $date, $post_id, $path_ids[ $path ], $pv, $vi );

		// Referrers for this path: direct keeps the rest.
		$ref_w = [ 'direct' => 40, 'google.com' => 22, 'www.google.com' => 6, 'bing.com' => 4, 'chatgpt.com' => 3 + (int) ( $age / 90 ), 'facebook.com' => 5, 't.co' => 3, 'github.com' => 4, 'example-blog.com' => 5, 'com.google.android.gm' => 2 ];
		foreach ( $ref_w as $k => $w ) {
			$ref_w[ $k ] = $w + mt_rand( 0, 3 );
		}
		$by_ref = $split( $pv, $ref_w );
		$vi_ref = $split( $vi, $ref_w );
		foreach ( $by_ref as $host => $rpv ) {
			if ( 'direct' === $host || 0 === $rpv ) {
				continue;
			}
			$rvi = $vi_ref[ $host ];
			$rows['ref'][ $date . '|' . $host ] = [ ( $rows['ref'][ $date . '|' . $host ][0] ?? 0 ) + $rpv, ( $rows['ref'][ $date . '|' . $host ][1] ?? 0 ) + $rvi ];
			if ( $post_id > 0 ) {
				$rows['postref'][] = $wpdb->prepare( '(%s,%d,%d,%d,%d)', $date, $post_id, $host_ids[ $host ], $rpv, $rvi );
			}
		}
	}
	$rows['site'][] = $wpdb->prepare( '(%s,%d,%d)', $date, $site_pv, $site_vi );

	$geo_w = [ 'US' => 40, 'GB' => 15, 'DE' => 12, 'ES' => 8, 'PT' => 6 + (int) ( $age / 60 ), 'FR' => 7, 'IN' => 9 ];
	$geo   = $split( $site_pv, $geo_w );
	$geo_v = $split( $site_vi, $geo_w );
	foreach ( $geo as $cc => $pv ) {
		$rows['geo'][] = $wpdb->prepare( '(%s,%s,%d,%d)', $date, $cc, $pv, $geo_v[ $cc ] );
	}

	$dev_w = [ 0 => 38, 1 => 25 + (int) ( $age / 50 ), 2 => 18, 3 => 12, 4 => 7 ];
	$dev   = $split( $site_pv, $dev_w );
	$dev_v = $split( $site_vi, $dev_w );
	foreach ( $dev as $i => $pv ) {
		[ $b, $o, $t ]  = $devices[ $i ];
		$rows['dev'][] = $wpdb->prepare( '(%s,%s,%s,%s,%d,%d)', $date, $b, $o, $t, $pv, $dev_v[ $i ] );
	}
}

$flush = static function ( string $sql, array $values ) use ( $wpdb ): void {
	foreach ( array_chunk( $values, 500 ) as $chunk ) {
		$wpdb->query( $sql . implode( ',', $chunk ) );
	}
};
$flush( "INSERT INTO {$p}site_stats (date,pageviews,visitors) VALUES ", $rows['site'] );
$flush( "INSERT INTO {$p}post_stats (date,post_id,path_id,pageviews,visitors) VALUES ", $rows['post'] );
$flush( "INSERT INTO {$p}geo_stats (date,country_code,pageviews,visitors) VALUES ", $rows['geo'] );
$flush( "INSERT INTO {$p}device_stats (date,browser,os,device_type,pageviews,visitors) VALUES ", $rows['dev'] );
$flush( "INSERT INTO {$p}post_referrer_stats (date,post_id,url_id,pageviews,visitors) VALUES ", $rows['postref'] );
$ref_rows = [];
foreach ( $rows['ref'] as $key => $pair ) {
	[ $date, $host ] = explode( '|', $key, 2 );
	$ref_rows[]      = $wpdb->prepare( '(%s,%d,%d,%d)', $date, $host_ids[ $host ], $pair[0], $pair[1] );
}
$flush( "INSERT INTO {$p}referrer_stats (date,url_id,pageviews,visitors) VALUES ", $ref_rows );

// The first fourteen months came from Plausible; Vireo took over after.
$import_last = gmdate( 'Y-m-d', strtotime( $today . ' -300 days UTC' ) );
$own_from    = gmdate( 'Y-m-d', strtotime( $today . ' -299 days UTC' ) );
$first       = gmdate( 'Y-m-d', strtotime( $today . " -{$days_back} days UTC" ) );
\VireoAnalytics\Import_History::record( 'plausible', '', '', [ 'first' => $first, 'last' => $import_last, 'days' => $days_back - 300, 'pageviews' => 1, 'own_from' => $own_from ] );

// The dashboard caches for 30 s; clear so the seed shows at once.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_vireo_%' OR option_name LIKE '_transient_timeout_vireo_%'" );

echo "seeded: site=" . count( $rows['site'] ) . " post=" . count( $rows['post'] ) . " ref=" . count( $ref_rows ) . " geo=" . count( $rows['geo'] ) . " dev=" . count( $rows['dev'] ) . " postref=" . count( $rows['postref'] ) . "\n";
echo "import covers {$first} .. {$import_last}\n";
