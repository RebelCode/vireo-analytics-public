<?php
/**
 * Zero-dependency assertion runner for Bot_Filter: header checks, category
 * order, and a control set of real browsers that must stay human.
 * Run: php tests/test-bot-filter.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}
if ( ! defined( 'VIREO_DIR' ) ) {
	define( 'VIREO_DIR', dirname( __DIR__ ) . '/' );
}

require __DIR__ . '/../includes/class-bot-filter.php';

use VireoAnalytics\Bot_Filter;

$fail = 0;
function ok( $cond, $msg ) {
	global $fail;
	if ( $cond ) {
		echo "  ok  $msg\n";
	} else {
		echo "  FAIL  $msg\n";
		$fail++;
	}
}

$chrome = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';

// The headers WP Engine's edge put on a real Chrome request to spotlightwp.com.
$wpe_human = [
	'HTTP_USER_AGENT'      => $chrome,
	'HTTP_CF_VERIFIED_BOT' => 'false',
	'HTTP_CF_THREAT_SCORE' => '0',
];

ok( '' === Bot_Filter::classify( $chrome, $wpe_human ), 'Cf-Verified-Bot: false is a human' );
ok( 'other' === Bot_Filter::classify( $chrome, [ 'HTTP_CF_VERIFIED_BOT' => 'true' ] ), 'Cf-Verified-Bot: true is a bot' );
ok( 'other' === Bot_Filter::classify( $chrome, [ 'HTTP_CF_VERIFIED_BOT' => 'TRUE' ] ), 'the value is matched without regard to case' );
ok( '' === Bot_Filter::classify( $chrome, [] ), 'no Cloudflare headers is a human' );
ok( 'other' === Bot_Filter::classify( $chrome, [ 'HTTP_CF_MITIGATED' => 'challenge' ] ), 'a mitigated request is a bot' );
ok( 'other' === Bot_Filter::classify( $chrome, [ 'HTTP_CF_THREAT_SCORE' => '60' ] ), 'a high threat score is a bot' );

// Named families win over the generic bot|crawl|spider fallback. These all
// used to land in `other` because that fallback ran before data/bots.php.
$named = [
	'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Amzn-SearchBot/0.1) Chrome/119.0.6045.214 Safari/537.36' => 'ai',
	'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Claude-SearchBot/1.0; +searchbot@anthropic.com)'           => 'ai',
	'Mozilla/5.0 (compatible; DeepseekBot/1.0; +https://www.deepseek.com/bot)'                                                  => 'ai',
	'Mozilla/5.0 (compatible; xAI-Bot/1.0; +https://x.ai/)'                                                                     => 'ai',
	'Mozilla/5.0 (compatible; MojeekBot/0.6; http://www.mojeek.com/bot.html)'                                                   => 'search',
	'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ClaudeBot/1.0; +ClaudeBot@anthropic.com)'                   => 'ai',
	'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.0; +https://openai.com/gptbot)'                    => 'ai',
	// Extended patterns match from the start of a token, as device-detector
	// does: the search entry `oBot` must not find "obot" inside "Robot".
	'Mozilla/5.0 (compatible; SEOkicks-Robot; +http://www.seokicks.de/robot.html)'                                              => 'other',
	// Agents that matched nothing at all and counted as people.
	'Mozilla/5.0 (compatible; Dormouse/1.0)'                                                                                    => 'other',
	'Mozilla/5.0 (compatible; PhishBucketRust/1.0)'                                                                             => 'other',
	'google-xrawler'                                                                                                            => 'search',
];
foreach ( $named as $ua => $want ) {
	$got = Bot_Filter::classify( $ua, [] );
	ok( $want === $got, "$want: " . substr( $ua, 0, 70 ) . ( $want === $got ? '' : " (got '$got')" ) );
}

// Current browsers, desktop and mobile, must stay human. Reordering puts the
// long-tail patterns ahead of the generic fallback, so this is the guard
// against one of them matching a person.
$humans = [
	'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36',
	'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36',
	'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36',
	'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/141.0.7390.41 Mobile/15E148 Safari/604.1',
	'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.0 Safari/605.1.15',
	'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.6 Mobile/15E148 Safari/604.1',
	'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:143.0) Gecko/20100101 Firefox/143.0',
	'Mozilla/5.0 (Android 15; Mobile; rv:143.0) Gecko/143.0 Firefox/143.0',
	'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) FxiOS/143.0 Mobile/15E148 Safari/605.1.15',
	'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0',
	'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36 EdgA/141.0.0.0',
	'Mozilla/5.0 (Linux; Android 15; SAMSUNG SM-S931B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/28.0 Chrome/130.0.0.0 Mobile Safari/537.36',
	'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) GSA/385.0.786010394 Mobile/15E148 Safari/604.1',
	'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/22G86 [FBAN/FBIOS;FBAV/530.0.0.40.86;FBBV/770000000;FBDV/iPhone15,3;FBMD/iPhone;FBSN/iOS;FBSV/18.6;FBSS/3;FBCR/;FBID/phone;FBLC/en_US;FBOP/80]',
	'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Instagram 400.0.0.29.71 (iPhone15,3; iOS 18_6; en_US; en; scale=3.00; 1290x2796; 780000000)',
	'Mozilla/5.0 (Windows NT 6.1; WOW64; Trident/7.0; SLCC2; .NET CLR 2.0.50727; .NET CLR 3.5.30729; .NET CLR 3.0.30729; Media Center PC 6.0; .NET4.0C; .NET4.0E; rv:11.0) like Gecko',
];
foreach ( $humans as $ua ) {
	$got = Bot_Filter::classify( $ua, [] );
	ok( '' === $got, 'human: ' . substr( $ua, 0, 70 ) . ( '' === $got ? '' : " (got '$got')" ) );
}

echo $fail ? "\n$fail failed\n" : "\nall passed\n";
exit( $fail ? 1 : 0 );
