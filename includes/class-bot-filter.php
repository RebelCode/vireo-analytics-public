<?php
/**
 * Bot filter — UA regex + Cloudflare signal detection.
 *
 * All methods are static and pure: identical inputs, identical verdict.
 */

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

final class Bot_Filter {

	/**
	 * Default bot UA regex. Public so the tracker endpoint can pre-compile it.
	 */
	public const DEFAULT_UA_PATTERN = 'bot|crawl|spider|slurp|ClaudeBot|GPTBot|PerplexityBot|YandexBot|DuckDuckBot|facebookexternalhit|Twitterbot|Googlebot|Bingbot|AhrefsBot|SemrushBot|MJ12bot|DotBot|PetalBot|BingPreview|Applebot|Baiduspider|sogou|Exabot|ia_archiver|archive\.org_bot|LinkChecker|HeadlessChrome|Lighthouse|GTmetrix|Pingdom|UptimeRobot|StatusCake|site24x7|newrelic';

	/**
	 * True if the request looks like a bot.
	 *
	 * Combines UA regex with Cloudflare-provided signals (CF-Mitigated,
	 * CF-Verified-Bot, CF-Threat-Score). The UA regex is filterable via
	 * `vireo_bot_ua_pattern` when WordPress is loaded.
	 *
	 * @param string     $ua      Raw User-Agent string.
	 * @param array|null $headers Optional header map (defaults to $_SERVER).
	 */
	public static function is_bot_request( string $ua, ?array $headers = null ): bool {
		return '' !== self::classify( $ua, $headers );
	}

	/**
	 * Ordered category patterns. First match wins, so the specific families
	 * sit above the generic `bot|crawl|spider` catch-all. The keys are stored
	 * in va_bot_stats and rendered on the dashboard, so renaming one is a
	 * breaking change for existing history.
	 */
	private const CATEGORY_PATTERNS = [
		'ai'        => 'GPTBot|OAI-SearchBot|ChatGPT-User|ClaudeBot|Claude-Web|anthropic-ai|PerplexityBot|Perplexity-User|CCBot|Bytespider|Google-Extended|Applebot-Extended|meta-externalagent|FacebookBot|Amazonbot|cohere-ai|Diffbot|ImagesiftBot|Timpibot|omgili|YouBot|AI2Bot|Firecrawl',
		'search'    => 'Googlebot|Storebot-Google|Google-InspectionTool|Bingbot|BingPreview|Slurp|DuckDuckBot|DuckAssistBot|YandexBot|Baiduspider|sogou|Exabot|PetalBot|Applebot|Qwantify|SeznamBot|NaverBot',
		'seo'       => 'AhrefsBot|SemrushBot|MJ12bot|DotBot|BLEXBot|Barkrowler|SerpstatBot|ZoominfoBot|rogerbot|MegaIndex|DataForSeo|Screaming Frog|SiteAuditBot|LinkpadBot|Neevabot',
		'uptime'    => 'UptimeRobot|StatusCake|Pingdom|site24x7|newrelic|Better Uptime|betterstack|HetrixTools|Uptime-Kuma|NodePing|Monitoring',
		'social'    => 'facebookexternalhit|Twitterbot|LinkedInBot|Slackbot|WhatsApp|TelegramBot|Discordbot|Pinterest|redditbot|Mastodon|SkypeUriPreview',
		'automation'=> 'HeadlessChrome|Lighthouse|GTmetrix|PhantomJS|Selenium|Playwright|Puppeteer|Cypress|Chrome-Lighthouse|PTST',
		'scripted'  => 'curl/|Wget|python-requests|python-urllib|aiohttp|Go-http-client|okhttp|Java/|libwww|Scrapy|axios|node-fetch|Guzzle|http[-_]?client|Embarcadero URI Client|WordPress/',
		'archive'   => 'ia_archiver|archive\.org_bot|Wayback|LinkChecker|validator|W3C_Validator',
		'other'     => 'bot\b|bot/|crawl|spider|slurp|scraper|scanner|fetcher|checker|analyzer|transcoder|feedfetcher',
	];

	/**
	 * Where the AI visibility scan keeps the token that marks its own requests.
	 * The scan asks the site for pages as GPTBot, ClaudeBot and PerplexityBot,
	 * which would otherwise be counted as the AI crawler visits it reports on.
	 */
	public const SCAN_TOKEN_TRANSIENT = 'vireo_ai_scan_token';

	/** The server variable the scan's token arrives in (X-Vireo-Scan). */
	public const SCAN_HEADER_KEY = 'HTTP_X_VIREO_SCAN';

	/**
	 * True when the request carries the token of a scan that is running now.
	 *
	 * Every place that counts a request calls this first. It is static and
	 * reads one transient, so it works in the SHORTINIT beacon endpoint too.
	 *
	 * @param array|null $headers Optional header map (defaults to $_SERVER).
	 */
	public static function is_scan_request( ?array $headers = null ): bool {
		$headers = $headers ?? $_SERVER;
		$sent    = isset( $headers[ self::SCAN_HEADER_KEY ] ) ? (string) $headers[ self::SCAN_HEADER_KEY ] : '';
		if ( '' === $sent || ! function_exists( 'get_transient' ) ) {
			return false;
		}

		$token = get_transient( self::SCAN_TOKEN_TRANSIENT );
		return is_string( $token ) && '' !== $token && hash_equals( $token, $sent );
	}

	/** What device-detector puts in front of each of its bot patterns. */
	private const TOKEN_START = '(?:^|[^A-Z0-9_-]|[^A-Z0-9-]_|sprd-|MZ-)';

	/**
	 * Classify a request. Returns a category key, or an empty string when the
	 * request looks human.
	 *
	 * Categories exist so the dashboard can show what was filtered rather than
	 * only how much. The UA regex stays filterable via `vireo_bot_ua_pattern`;
	 * when a site overrides it, a match reports as `other`.
	 *
	 * @param string     $ua      Raw User-Agent string.
	 * @param array|null $headers Optional header map (defaults to $_SERVER).
	 */
	public static function classify( string $ua, ?array $headers = null ): string {
		$headers = $headers ?? $_SERVER;

		// Cf-Verified-Bot carries a value, not a presence flag: WP Engine's edge
		// sends `false` on every human request, so testing it for emptiness
		// filtered every visitor on those sites.
		$verified_bot = strtolower( trim( (string) ( $headers['HTTP_CF_VERIFIED_BOT'] ?? '' ) ) );
		if ( ! empty( $headers['HTTP_CF_MITIGATED'] ) || 'true' === $verified_bot ) {
			return 'other';
		}
		if ( isset( $headers['HTTP_CF_THREAT_SCORE'] ) && (int) $headers['HTTP_CF_THREAT_SCORE'] >= 50 ) {
			return 'other';
		}

		// Prefetch and prerender. The browser is fetching a page the reader has
		// not asked for and may never look at, so counting it inflates views.
		foreach ( [ 'HTTP_PURPOSE', 'HTTP_SEC_PURPOSE', 'HTTP_X_PURPOSE', 'HTTP_X_MOZ' ] as $key ) {
			if ( ! empty( $headers[ $key ] ) && preg_match( '~prefetch|prerender|preview~i', (string) $headers[ $key ] ) ) {
				return 'prefetch';
			}
		}

		if ( '' === $ua ) {
			// Empty UA is suspicious — treat as bot.
			return 'other';
		}

		// A site-supplied pattern replaces the built-in set wholesale, so it
		// can't be split into categories. Report those matches as `other`.
		if ( function_exists( 'apply_filters' ) ) {
			$pattern = (string) apply_filters( 'vireo_bot_ua_pattern', self::DEFAULT_UA_PATTERN );
			if ( $pattern !== self::DEFAULT_UA_PATTERN ) {
				return preg_match( '~(' . $pattern . ')~i', $ua ) ? 'other' : '';
			}
		}

		// Every named family gets its say before anything generic. The curated
		// patterns cover the common families cheaply; the extended set from
		// data/bots.php catches the long tail, currently around 800 named
		// agents, without bundling the library they came from. Both have an
		// `other` entry whose generic bot|crawl|spider terms would otherwise
		// claim Amzn-SearchBot or MojeekBot before the ai and search entries
		// that name them are tried, so the two `other` entries go last.
		//
		// The extended patterns are matched the way device-detector matches
		// them, anchored to the start of a token. Bare, its `oBot` entry finds
		// "obot" inside ExaBot or SEOkicks-Robot and calls them search engines.
		$curated  = [];
		$extended = [];
		foreach ( self::CATEGORY_PATTERNS as $key => $pattern ) {
			$curated[ $key ] = '~(' . $pattern . ')~i';
		}
		foreach ( self::extended_signatures() as $key => $pattern ) {
			$extended[ $key ] = self::extended_regex( $pattern );
		}

		foreach ( [ $curated, $extended ] as $set ) {
			foreach ( $set as $key => $regex ) {
				if ( 'other' !== $key && preg_match( $regex, $ua ) ) {
					return $key;
				}
			}
		}

		foreach ( [ $curated['other'], $extended['other'] ?? '' ] as $regex ) {
			if ( '' !== $regex && preg_match( $regex, $ua ) ) {
				return 'other';
			}
		}

		return '';
	}


	/**
	 * The regex an extended signature is matched with, anchored to the start
	 * of a token. Public so the generator and the tests compile exactly what
	 * classify() runs.
	 */
	public static function extended_regex( string $pattern ): string {
		return '~' . self::TOKEN_START . '(?:' . $pattern . ')~i';
	}

	/**
	 * Load the generated signature set, once per request.
	 *
	 * Kept in a data file rather than in this class so it can be regenerated
	 * without touching logic, and skipped entirely by the `vireo_bot_signatures`
	 * filter on sites that would rather not pay for it.
	 *
	 * @return array<string,string>
	 */
	private static function extended_signatures(): array {
		static $cache = null;

		if ( null !== $cache ) {
			return $cache;
		}

		$file = VIREO_DIR . 'data/bots.php';
		$data = is_readable( $file ) ? require $file : [];
		$data = is_array( $data ) ? $data : [];

		if ( function_exists( 'apply_filters' ) ) {
			$data = (array) apply_filters( 'vireo_bot_signatures', $data );
		}

		$cache = $data;
		return $cache;
	}
	/**
	 * Category keys in dashboard display order, mapped to their labels.
	 *
	 * @return array<string,string>
	 */
	public static function categories(): array {
		return [
			'ai'         => __( 'AI crawlers', 'vireo-analytics' ),
			'search'     => __( 'Search engines', 'vireo-analytics' ),
			'seo'        => __( 'SEO tools', 'vireo-analytics' ),
			'uptime'     => __( 'Uptime monitors', 'vireo-analytics' ),
			'social'     => __( 'Link previews', 'vireo-analytics' ),
			'automation' => __( 'Automated browsers', 'vireo-analytics' ),
			'scripted'   => __( 'Scripts and clients', 'vireo-analytics' ),
			'archive'    => __( 'Archivers and validators', 'vireo-analytics' ),
			'prefetch'   => __( 'Prefetch and prerender', 'vireo-analytics' ),
			'repeat'     => __( 'Repeated views past the daily cap', 'vireo-analytics' ),
			'referrer_spam' => __( 'Referrer spam', 'vireo-analytics' ),
			'other'      => __( 'Other bots', 'vireo-analytics' ),
		];
	}

	/**
	 * True if the current request should be excluded based on UA, IP, or path.
	 * Centralises what "excluded" means so the beacon, the Koko-style hook,
	 * and the tracker endpoint all enforce identical rules.
	 *
	 * @param array{ua:string,ip:string,path:string} $request
	 * @param array|null                             $headers Optional header map (defaults to $_SERVER).
	 */
	public static function request_is_excluded( array $request, ?array $headers = null ): bool {
		$ua   = (string) ( $request['ua'] ?? '' );
		$ip   = (string) ( $request['ip'] ?? '' );
		$path = (string) ( $request['path'] ?? '' );

		if ( self::is_scan_request( $headers ) ) {
			return true;
		}

		if ( self::is_bot_request( $ua, $headers ) ) {
			return true;
		}

		// WP-context exclusions (IPs / paths / admin flag).
		if ( function_exists( 'vireo_get_exclusions' ) ) {
			$rules = vireo_get_exclusions();
			if ( ! empty( $rules['ips'] ) && vireo_ip_matches_rules( $ip, $rules['ips'] ) ) {
				return true;
			}
			if ( ! empty( $rules['paths'] ) && vireo_path_matches_rules( $path, $rules['paths'] ) ) {
				return true;
			}
			if ( function_exists( 'vireo_user_is_excluded' ) && vireo_user_is_excluded() ) {
				return true;
			}

			// Countries the owner marked as junk. The aggregator has always
			// applied this to buffered pageviews, but it was never added here,
			// so every caller of this shared gate has been ignoring the setting
			// since it shipped. Pro's live tracker is one of them, which is why
			// excluded countries kept appearing in the live visitor feed while
			// the same traffic was correctly dropped from the daily rollups.
			//
			// The lookup only runs when there is a list to check, so a site
			// that excludes nothing pays nothing for this.
			if ( ! empty( $rules['countries'] ) ) {
				$country = (string) ( $request['country'] ?? '' );
				if ( '' === $country && '' !== $ip && class_exists( __NAMESPACE__ . '\\Geo_Lookup' ) ) {
					$country = Geo_Lookup::lookup( $ip );
				}
				if ( '' !== $country && in_array( strtoupper( $country ), $rules['countries'], true ) ) {
					return true;
				}
			}
		}

		return false;
	}
}
