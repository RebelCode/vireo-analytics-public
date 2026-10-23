<?php
/**
 * On top of seed-dashboard.php: the shapes behind the 0.13.0 dashboard
 * feedback, so each fix has something real to render against.
 * Run inside wp-env: wp eval-file tests/wp/seed-feedback.php
 *
 *   - today at 5% of a normal day (a partial day, mid-morning)
 *   - a spike 45 days ago, inside the prior period of "last 30"
 *   - yesterday split across the hours, for the same-time comparison
 *   - bot rows, so the filter panel renders
 *   - the site's own host as a referrer (internal navigation that leaked)
 *   - a referrer with four views, first seen this week (noise)
 *   - a spam referrer first seen this week with a big count
 *   - a post published three days ago that "gained" everything it has
 */

global $wpdb;
$p     = $wpdb->prefix . 'va_';
$today = vireo_bucket_date();
$day   = static fn( int $back ): string => gmdate( 'Y-m-d', strtotime( $today . " -{$back} days UTC" ) );

$yesterday_pv = (int) $wpdb->get_var( $wpdb->prepare( "SELECT pageviews FROM {$p}site_stats WHERE date = %s", $day( 1 ) ) );
$yesterday_vi = (int) $wpdb->get_var( $wpdb->prepare( "SELECT visitors FROM {$p}site_stats WHERE date = %s", $day( 1 ) ) );

// Today: 5% of yesterday.
$today_pv = max( 1, (int) round( $yesterday_pv * 0.05 ) );
$today_vi = max( 1, (int) round( $yesterday_vi * 0.05 ) );
$wpdb->query( $wpdb->prepare( "REPLACE INTO {$p}site_stats (date,pageviews,visitors) VALUES (%s,%d,%d)", $today, $today_pv, $today_vi ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}post_stats WHERE date = %s", $today ) );
$home_path = (int) $wpdb->get_var( "SELECT id FROM {$p}paths WHERE path = '/'" );
$wpdb->query( $wpdb->prepare( "INSERT INTO {$p}post_stats (date,post_id,path_id,pageviews,visitors) VALUES (%s,%d,%d,%d,%d)", $today, 0, $home_path, $today_pv, $today_vi ) );

// A spike in the prior period.
$wpdb->query( $wpdb->prepare( "UPDATE {$p}site_stats SET pageviews = pageviews * 4, visitors = visitors * 3 WHERE date = %s", $day( 45 ) ) );

// Yesterday by the hour: a daytime curve that sums to the day.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}hour_stats WHERE date = %s", $day( 1 ) ) );
$weights = [];
for ( $h = 0; $h < 24; $h++ ) {
	$weights[ $h ] = 1 + (int) round( 6 * exp( -pow( ( $h - 14 ) / 5, 2 ) ) );
}
$sum  = array_sum( $weights );
$left = $yesterday_pv;
$lefv = $yesterday_vi;
for ( $h = 0; $h < 24; $h++ ) {
	$pv = 23 === $h ? $left : (int) floor( $yesterday_pv * $weights[ $h ] / $sum );
	$vi = 23 === $h ? $lefv : (int) floor( $yesterday_vi * $weights[ $h ] / $sum );
	$left -= $pv;
	$lefv -= $vi;
	$wpdb->query( $wpdb->prepare( "INSERT INTO {$p}hour_stats (date,hour,pageviews,visitors) VALUES (%s,%d,%d,%d)", $day( 1 ), $h, $pv, $vi ) );
}

// Bots over the last 90 days.
$wpdb->query( "DELETE FROM {$p}bot_stats" );
foreach ( [ 'search' => 520, 'ai' => 310, 'seo' => 140, 'scripted' => 60, 'other' => 30, 'uptime' => 12 ] as $cat => $per_day ) {
	for ( $d = 0; $d < 90; $d++ ) {
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$p}bot_stats (date,category,hits) VALUES (%s,%s,%d)", $day( $d ), $cat, $per_day + ( $d % 7 ) * 3 ) );
	}
}

// Referrer rows: the site's own host, a four-view newcomer, a spam newcomer.
$own = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
$ids = static function ( string $host ) use ( $wpdb, $p ): int {
	$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$p}referrer_urls (url) VALUES (%s)", $host ) );
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$p}referrer_urls WHERE url = %s", $host ) );
};
foreach ( [ $own => [ 7, 35 ], 'wnnkd.com' => [ 3, 2 ], 'semalt.com' => [ 2, 220 ] ] as $host => [ $days, $pv ] ) {
	$id = $ids( $host );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}referrer_stats WHERE url_id = %d", $id ) );
	for ( $d = 1; $d <= $days; $d++ ) {
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$p}referrer_stats (date,url_id,pageviews,visitors) VALUES (%s,%d,%d,%d)", $day( $d ), $id, $pv, max( 1, (int) ( $pv * 0.8 ) ) ) );
	}
}

// A post published three days ago, viewed only since.
$post_id = (int) wp_insert_post(
	[
		'post_title'   => 'Your NHR Is Ending: What to Do Next',
		'post_name'    => 'nhr-ending',
		'post_status'  => 'publish',
		'post_type'    => 'post',
		'post_content' => 'Seed.',
		'post_date'    => wp_date( 'Y-m-d H:i:s', strtotime( $day( 3 ) . ' 10:00:00 UTC' ) ),
	]
);
$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$p}paths (path) VALUES (%s)", '/nhr-ending/' ) );
$path_id = (int) $wpdb->get_var( "SELECT id FROM {$p}paths WHERE path = '/nhr-ending/'" );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}post_stats WHERE path_id = %d", $path_id ) );
foreach ( [ 3 => 60, 2 => 90, 1 => 70 ] as $back => $pv ) {
	$wpdb->query( $wpdb->prepare( "INSERT INTO {$p}post_stats (date,post_id,path_id,pageviews,visitors) VALUES (%s,%d,%d,%d,%d)", $day( $back ), $post_id, $path_id, $pv, (int) ( $pv * 0.7 ) ) );
}

$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_vireo_%' OR option_name LIKE '_transient_timeout_vireo_%'" );
echo "today {$today}: {$today_pv} pv (yesterday {$yesterday_pv}); own host {$own}; new post #{$post_id}\n";
