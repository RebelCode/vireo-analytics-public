<?php
/**
 * Zero-dependency assertion runner for automated browsers.
 * Run: php tests/test-automation.php
 *
 * jeangalea.com's access logs for three days: 4,255 page requests from
 * China, most from "Chrome 133", "Chrome 127", "Chrome 120" and the like
 * across hundreds of addresses, with no referrer or the site's own host as
 * one. Current Chrome was 150 and the same logs showed Firefox 154. Those
 * are scrapers driving real engines. The user-agent filter never sees them,
 * so the fold applies three signals together, and only together: a stale
 * browser, on a landing pageview, with no outside referrer.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}
require __DIR__ . '/../includes/class-automation.php';

use VireoAnalytics\Automation;

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

$chrome = static fn( int $major ): string => "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/{$major}.0.0.0 Safari/537.36";
$edge   = static fn( int $major ): string => $chrome( $major ) . " Edg/{$major}.0.0.0";
$ff     = static fn( int $major ): string => "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:{$major}.0) Gecko/20100101 Firefox/{$major}.0";
$safari = 'Mozilla/5.0 (iPhone; CPU iPhone OS 15_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.0 Mobile/15E148 Safari/604.1';

$cur = Automation::CURRENT['chrome'];

// 1. Version parsing.
ok( [ 'chrome', 133 ] === Automation::browser( $chrome( 133 ) ), 'Chrome major is read' );
ok( [ 'edge', 120 ] === Automation::browser( $edge( 120 ) ), 'Edge is read as Edge, not as its Chrome token' );
ok( [ 'firefox', 128 ] === Automation::browser( $ff( 128 ) ), 'Firefox major is read' );
ok( [ '', 0 ] === Automation::browser( $safari ), 'Safari is not measured' );
ok( [ '', 0 ] === Automation::browser( '' ), 'empty is nothing' );

// 2. Stale: twelve majors behind or more.
ok( Automation::stale_browser( $chrome( 133 ) ), "Chrome 133 is stale against {$cur}" );
ok( Automation::stale_browser( $chrome( 120 ) ), 'Chrome 120 is stale' );
ok( Automation::stale_browser( $edge( 110 ) ), 'Edge 110 is stale' );
ok( ! Automation::stale_browser( $chrome( $cur ) ), 'control: current Chrome is not stale' );
ok( ! Automation::stale_browser( $chrome( $cur - 2 ) ), 'control: Chrome N-2 (a reader who has not restarted) is not stale' );
ok( ! Automation::stale_browser( $chrome( $cur - 11 ) ), 'control: eleven behind is not stale; twelve is the line' );
ok( Automation::stale_browser( $chrome( $cur - 12 ) ), 'twelve behind is stale' );
foreach ( Automation::FIREFOX_ESR as $esr ) {
	ok( ! Automation::stale_browser( $ff( $esr ) ), "control: Firefox ESR {$esr} (enterprise) is not stale" );
}
ok( Automation::stale_browser( $ff( 100 ) ), 'Firefox 100, not an ESR line, is stale' );
ok( ! Automation::stale_browser( $safari ), 'control: an old iPhone Safari is never stale' );

// 3. The decision needs all three signals.
$stale = $chrome( 133 );
ok( Automation::looks_automated( $stale, true, true, false ), 'stale + landing + no referrer: automated (the log shape, 2,618 of them)' );
ok( Automation::looks_automated( $stale, true, false, true ), 'stale + landing + own-site referrer: automated (the log shape, 1,515 of them)' );
ok( ! Automation::looks_automated( $stale, true, false, false ), 'control: stale but arriving from google.com is a reader on an old browser' );
ok( ! Automation::looks_automated( $stale, false, true, false ), 'control: stale, no referrer, but the second page of the visit: a reader browsing' );
ok( ! Automation::looks_automated( $chrome( $cur - 2 ), true, true, false ), 'control: a returning reader opening a bookmark in a new tab on Chrome N-2' );
ok( ! Automation::looks_automated( $ff( Automation::FIREFOX_ESR[0] ), true, true, false ), 'control: an enterprise Firefox ESR user typing the address' );
ok( ! Automation::looks_automated( $safari, true, true, false ), 'control: Safari from a bookmark' );
ok( ! Automation::looks_automated( '', true, true, false ), 'control: no user agent decides nothing here (the bot filter already did)' );

// Fault control: the rule can fire. Flip one signal on a control and it does.
ok( Automation::looks_automated( $chrome( $cur - 12 ), true, true, false ), 'fault control: the bookmark reader on a browser twelve behind is caught' );

echo $fail ? "\n$fail failure(s)\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
