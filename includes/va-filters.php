<?php
/**
 * Shared IP / path exclusion helpers.
 *
 * Pure-PHP utilities used by WP-side classes and the tracker handler.
 *
 * Bot detection lives in class-bot-filter.php.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Country-code to emoji flag. Takes a 2-letter ISO 3166-1 alpha-2 code and
 * returns the corresponding regional-indicator pair (e.g. "US" → "🇺🇸").
 * Empty / invalid codes return an empty string so callers can safely echo.
 */
function vireo_country_flag_emoji( string $code ): string {
	$code = strtoupper( trim( $code ) );
	if ( 2 !== strlen( $code ) || ! ctype_alpha( $code ) ) {
		return '';
	}
	// Regional indicator symbols live at U+1F1E6 (for 'A') through U+1F1FF ('Z').
	$offset = 0x1F1E6 - ord( 'A' );
	return mb_chr( ord( $code[0] ) + $offset, 'UTF-8' ) . mb_chr( ord( $code[1] ) + $offset, 'UTF-8' );
}

/**
 * Match an IP against a list of IPs or CIDR blocks.
 *
 * @param string   $ip    IPv4 or IPv6 address.
 * @param string[] $rules Array of exact IPs or CIDR strings (e.g. "1.2.3.0/24").
 */
function vireo_ip_matches_rules( string $ip, array $rules ): bool {
	if ( '' === $ip || empty( $rules ) ) {
		return false;
	}

	$ip_packed = @inet_pton( $ip );
	if ( false === $ip_packed ) {
		return false;
	}
	$ip_is_v6 = strlen( $ip_packed ) === 16;

	foreach ( $rules as $rule ) {
		$rule = trim( $rule );
		if ( '' === $rule ) {
			continue;
		}

		// Exact match fast path.
		if ( $rule === $ip ) {
			return true;
		}

		if ( false === strpos( $rule, '/' ) ) {
			continue;
		}

		[ $subnet, $bits ] = explode( '/', $rule, 2 );
		$bits              = (int) $bits;

		$subnet_packed = @inet_pton( $subnet );
		if ( false === $subnet_packed ) {
			continue;
		}

		$subnet_is_v6 = strlen( $subnet_packed ) === 16;
		if ( $subnet_is_v6 !== $ip_is_v6 ) {
			continue;
		}

		$max_bits = $ip_is_v6 ? 128 : 32;
		if ( $bits < 0 || $bits > $max_bits ) {
			continue;
		}

		$bytes = intdiv( $bits, 8 );
		$rem   = $bits % 8;

		if ( $bytes > 0 && substr( $ip_packed, 0, $bytes ) !== substr( $subnet_packed, 0, $bytes ) ) {
			continue;
		}
		if ( 0 === $rem ) {
			return true;
		}
		$mask        = chr( ( 0xFF << ( 8 - $rem ) ) & 0xFF );
		$ip_byte     = substr( $ip_packed, $bytes, 1 ) & $mask;
		$subnet_byte = substr( $subnet_packed, $bytes, 1 ) & $mask;
		if ( $ip_byte === $subnet_byte ) {
			return true;
		}
	}

	return false;
}

/**
 * Match a request path against a list of glob patterns.
 * Patterns support `*` (anything except slash) and `**` (anything).
 */
function vireo_path_matches_rules( string $path, array $rules ): bool {
	if ( '' === $path || empty( $rules ) ) {
		return false;
	}

	foreach ( $rules as $rule ) {
		$rule = trim( $rule );
		if ( '' === $rule ) {
			continue;
		}

		if ( $rule === $path ) {
			return true;
		}

		$regex    = '';
		$segments = preg_split( '/(\*\*|\*)/', $rule, -1, PREG_SPLIT_DELIM_CAPTURE );
		foreach ( (array) $segments as $seg ) {
			if ( '**' === $seg ) {
				$regex .= '.*';
			} elseif ( '*' === $seg ) {
				$regex .= '[^/]*';
			} else {
				$regex .= preg_quote( $seg, '~' );
			}
		}
		if ( preg_match( '~^' . $regex . '$~i', $path ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Parse a textarea value (one entry per line) into a trimmed array.
 * Ignores empty lines and '#'-prefixed comments.
 *
 * @return string[]
 */
function vireo_parse_lines( string $text ): array {
	if ( '' === $text ) {
		return [];
	}
	$lines = preg_split( '/\r\n|\r|\n/', $text );
	$out   = [];
	foreach ( (array) $lines as $line ) {
		$line = trim( $line );
		if ( '' !== $line && '#' !== $line[0] ) {
			$out[] = $line;
		}
	}
	return $out;
}

/**
 * Parsed exclusion settings, cached per-request. Only call inside WP context.
 *
 * @return array{ips: string[], paths: string[], countries: string[], roles: string[], exclude_logged_in_admins: bool}
 */
function vireo_get_exclusions(): array {
	static $cache = null;
	if ( null !== $cache ) {
		return $cache;
	}
	$settings = function_exists( 'vireo_get_settings' ) ? vireo_get_settings() : [];
	$cache    = [
		'ips'                      => vireo_parse_lines( (string) ( $settings['excluded_ips'] ?? '' ) ),
		'paths'                    => vireo_parse_lines( (string) ( $settings['excluded_paths'] ?? '' ) ),
		'countries'                => array_map( 'strtoupper', vireo_parse_lines( (string) ( $settings['excluded_countries'] ?? '' ) ) ),
		'roles'                    => array_values( array_map( 'strval', (array) ( $settings['excluded_roles'] ?? [] ) ) ),
		'exclude_logged_in_admins' => ! empty( $settings['exclude_logged_in_admins'] ),
	];
	return $cache;
}

/**
 * Is this request from a logged-in user with a role the owner chose not to
 * count?
 *
 * Only ever true inside full WordPress. The SHORTINIT endpoint has no user
 * functions, which is why the tracker is withheld from excluded users on the
 * page itself rather than refused at the beacon.
 */
function vireo_user_is_excluded(): bool {
	if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() || ! function_exists( 'wp_get_current_user' ) ) {
		return false;
	}
	$excluded = vireo_get_exclusions()['roles'];
	if ( empty( $excluded ) ) {
		return false;
	}
	$user = wp_get_current_user();
	return [] !== array_intersect( (array) ( $user->roles ?? [] ), $excluded );
}

/**
 * Strip `www.` prefix and return the host portion of a URL (or the value
 * itself if it already looks like a host). Case-folded to lowercase.
 *
 * NOT a full PSL implementation — we just drop `www.` and lowercase. This is
 * enough to collapse `www.google.com` and `google.com` into the same bucket
 * without shipping a 100KB suffix list. Different country TLDs (google.com vs
 * google.co.uk) stay separate on purpose: they usually represent different
 * Search Console properties and SEO work.
 */
function vireo_canonical_host( string $url_or_host ): string {
	$value = trim( $url_or_host );
	if ( '' === $value ) {
		return '';
	}
	if ( false !== strpos( $value, '/' ) ) {
		$host = wp_parse_url( $value, PHP_URL_HOST );
		if ( is_string( $host ) && '' !== $host ) {
			$value = $host;
		}
	}
	$value = strtolower( $value );
	if ( 0 === strpos( $value, 'www.' ) ) {
		$value = substr( $value, 4 );
	}
	return $value;
}

/**
 * The name a page is listed under: its title, or Home for "/", or the path.
 * Pure, so the dashboard, the widget, the email and the year in review agree.
 *
 * Jetpack reports post 0 for "Home page / Archives" and the importer stores
 * that under one fixed path; it is shown as what Jetpack meant rather than as
 * a bare "/?p=0".
 */
function vireo_page_label( string $title, string $path ): string {
	if ( '/' === $path && ( '' === $title || '/' === $title ) ) {
		return __( 'Home', 'vireo-analytics' );
	}
	if ( '/?p=0' === $path && ( '' === $title || $path === $title ) ) {
		return __( 'Home page and archives (imported)', 'vireo-analytics' );
	}
	return '' !== $title ? $title : $path;
}

/**
 * Android apps that open links in their own browser send the referrer
 * `android-app://<package>/`, so the stored host is a package name. These are
 * the common ones, with the name and bucket they show under. Stored data keeps
 * the package name; this only changes how it reads.
 *
 * @return array<string, array{label:string,type:string}>
 */
function vireo_app_referrers(): array {
	return [
		'com.google.android.googlequicksearchbox' => [
			'label' => __( 'Google app', 'vireo-analytics' ),
			'type'  => 'search',
		],
		'com.google.android.gm'                   => [
			'label' => 'Gmail',
			'type'  => 'referral',
		],
		'com.linkedin.android'                    => [
			'label' => 'LinkedIn',
			'type'  => 'social',
		],
		'com.facebook.katana'                     => [
			'label' => 'Facebook',
			'type'  => 'social',
		],
		'com.twitter.android'                     => [
			'label' => 'X',
			'type'  => 'social',
		],
		'com.reddit.frontpage'                    => [
			'label' => 'Reddit',
			'type'  => 'social',
		],
		'com.slack'                               => [
			'label' => 'Slack',
			'type'  => 'social',
		],
		'org.telegram.messenger'                  => [
			'label' => 'Telegram',
			'type'  => 'social',
		],
	];
}

/**
 * The name a stored referrer host is shown under: the app's or assistant's
 * name when it is one we know, otherwise the host itself.
 */
function vireo_referrer_label( string $host ): string {
	$app = vireo_app_referrers()[ strtolower( $host ) ]['label'] ?? '';
	if ( '' !== $app ) {
		return $app;
	}

	$assistant = \VireoAnalytics\AI_Sources::classify( $host );
	return '' !== $assistant ? $assistant : $host;
}

/**
 * The host to show under a referrer's name: set when the name replaced the
 * host, so two rows both reading "ChatGPT" can be told apart. Empty for plain
 * hosts, and for Android apps, whose package name tells a reader nothing.
 */
function vireo_referrer_subtitle( string $host ): string {
	if ( isset( vireo_app_referrers()[ strtolower( $host ) ] ) ) {
		return '';
	}
	return vireo_referrer_label( $host ) !== $host ? $host : '';
}

/**
 * Host patterns for the search and social buckets. The one definition the
 * referrer card, the PHP classifier and the SQL source filter all read, so a
 * host can't land in different buckets in different places. Plain regex:
 * PHP runs it through preg_match(), and bound as a %s argument MySQL's REGEXP
 * receives the same characters. Each name must be a whole label followed by
 * a dot, which covers country TLDs (google.co.uk) and subdomains
 * (m.facebook.com, news.ycombinator.com). Slack and Discord count as social,
 * like their Android apps. AI assistants are their own bucket, defined in
 * AI_Sources, and are checked before these.
 */
const VIREO_SEARCH_PATTERN = '(^|\.)(google|bing|yahoo|duckduckgo|baidu|yandex|ecosia|startpage|brave|qwant)\.';
const VIREO_SOCIAL_PATTERN = '(^|\.)(facebook|fb|instagram|twitter|x|t|linkedin|lnkd|reddit|ycombinator|producthunt|pinterest|tiktok|youtube|youtu|mastodon[a-z0-9-]*|threads|bsky|bluesky|fediverse|discord|slack|tumblr|vk|wechat|weibo)\.';

/**
 * The source bucket for a stored referrer host: ai, search, social or
 * referral, and direct for no host at all. The SQL source filter applies the
 * same checks in the same order.
 */
function vireo_source_type( string $host ): string {
	$host = strtolower( $host );
	if ( '' === $host ) {
		return 'direct';
	}

	// Before the host patterns, which misread package names: Gmail's
	// com.google.android.gm would otherwise count as Google search.
	$app = vireo_app_referrers()[ $host ] ?? null;
	if ( null !== $app ) {
		return $app['type'];
	}

	// Ahead of the host patterns: gemini.google.com would otherwise be search
	// and x.ai social.
	if ( '' !== \VireoAnalytics\AI_Sources::classify( $host ) ) {
		return 'ai';
	}

	if ( preg_match( '~' . VIREO_SEARCH_PATTERN . '~', $host ) ) {
		return 'search';
	}
	if ( preg_match( '~' . VIREO_SOCIAL_PATTERN . '~', $host ) ) {
		return 'social';
	}
	return 'referral';
}

/**
 * Classify a referrer URL/host into a source bucket (see vireo_source_type())
 * and the name it shows under.
 *
 * @return array{type:string,label:string}
 */
function vireo_classify_source( string $referrer ): array {
	$referrer = trim( $referrer );
	if ( '' === $referrer ) {
		return [ 'type' => 'direct', 'label' => 'Direct' ];
	}

	$host = vireo_canonical_host( $referrer );
	if ( '' === $host ) {
		return [ 'type' => 'referral', 'label' => $referrer ];
	}

	return [
		'type'  => vireo_source_type( $host ),
		'label' => vireo_referrer_label( $host ),
	];
}

/**
 * Networks whose forwarding headers are believed, when the connection
 * actually came from one of them.
 *
 * Loopback and the private ranges cover a front-end on the same host or on
 * the same LAN. The Cloudflare ranges are included because a Cloudflare-fronted
 * site sees an edge address in REMOTE_ADDR, and without them every visitor
 * would collapse onto a handful of edge IPs: the per-address rate limit would
 * then throttle the site's own recording rather than an abuser.
 *
 * Override with the `vireo_trusted_proxies` filter to name your own, for
 * instance a load balancer that is not on a private range.
 *
 * @return array<int,string> CIDRs and bare addresses.
 */
function vireo_trusted_proxies(): array {
	$defaults = [
		'127.0.0.0/8',
		'::1/128',
		'10.0.0.0/8',
		'172.16.0.0/12',
		'192.168.0.0/16',
		'169.254.0.0/16',
		'fc00::/7',
		'fe80::/10',
		// Cloudflare, from cloudflare.com/ips as of 2026-09-15.
		'173.245.48.0/20',
		'103.21.244.0/22',
		'103.22.200.0/22',
		'103.31.4.0/22',
		'141.101.64.0/18',
		'108.162.192.0/18',
		'190.93.240.0/20',
		'188.114.96.0/20',
		'197.234.240.0/22',
		'198.41.128.0/17',
		'162.158.0.0/15',
		'104.16.0.0/13',
		'104.24.0.0/14',
		'172.64.0.0/13',
		'131.0.72.0/22',
		'2400:cb00::/32',
		'2606:4700::/32',
		'2803:f800::/32',
		'2405:b500::/32',
		'2405:8100::/32',
		'2a06:98c0::/29',
		'2c0f:f248::/32',
	];

	return (array) apply_filters( 'vireo_trusted_proxies', $defaults );
}

/**
 * Resolve the client's IP. Defaults to REMOTE_ADDR only.
 *
 * Sites behind a reverse proxy (Cloudflare, an nginx front-end, a load
 * balancer) opt in to proxy-header trust:
 *
 *     add_filter( 'vireo_trust_proxy_headers', '__return_true' );
 *
 * Opting in used to mean believing the first syntactically valid address in
 * CF-Connecting-IP, X-Forwarded-For or X-Real-IP, whoever sent it. Any visitor
 * could then forge a header and claim to be any address, which bypasses the
 * per-address rate limit and the excluded-IP rules and manufactures a fresh
 * visitor identity on demand. The headers are now read only when the
 * connection itself arrived from a network in vireo_trusted_proxies().
 *
 * X-Forwarded-For is a chain appended to by each hop, so it is walked from the
 * nearest hop backwards, skipping addresses that are themselves trusted
 * proxies. The first address that is not one of ours is the client. Reading it
 * left to right would return whatever the client put there first.
 */
function vireo_get_client_ip( ?array $headers = null ): string {
	$headers = $headers ?? $_SERVER;

	$remote = isset( $headers['REMOTE_ADDR'] ) ? trim( (string) $headers['REMOTE_ADDR'] ) : '';
	$remote = filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '';

	if ( ! (bool) apply_filters( 'vireo_trust_proxy_headers', false ) ) {
		return $remote;
	}

	$trusted = vireo_trusted_proxies();

	// The hop that actually connected has to be one we put there.
	if ( '' === $remote || ! vireo_ip_matches_rules( $remote, $trusted ) ) {
		return $remote;
	}

	// Single-value headers set by the edge itself.
	foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP' ] as $key ) {
		if ( empty( $headers[ $key ] ) ) {
			continue;
		}
		$candidate = trim( (string) $headers[ $key ] );
		if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
			return $candidate;
		}
	}

	if ( ! empty( $headers['HTTP_X_FORWARDED_FOR'] ) ) {
		$chain = array_map( 'trim', explode( ',', (string) $headers['HTTP_X_FORWARDED_FOR'] ) );
		for ( $i = count( $chain ) - 1; $i >= 0; $i-- ) {
			$candidate = $chain[ $i ];
			if ( ! filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				continue;
			}
			if ( vireo_ip_matches_rules( $candidate, $trusted ) ) {
				continue;
			}
			return $candidate;
		}
	}

	return $remote;
}

/**
 * Per-IP beacon rate limit check. Returns true if the request is within
 * budget, false if it should be rejected with 429.
 *
 * Default budget is 60 beacons per 60-second window per IP. Tune via the
 * `vireo_beacon_rate_limit_per_minute` filter. Zero or negative values disable
 * the limit. State is held in transients keyed off the IP — falls back
 * gracefully when the IP can't be resolved (no rate limit on those).
 *
 * Only runs when a persistent object cache is present, so transient state
 * lives in memory. Without one, `set_transient()` would write a row to
 * `wp_options` on every public beacon — high churn under heavy or abusive
 * traffic — so we skip the counter entirely rather than trade a DoS guard
 * for DB bloat. The oversized-body reject and bot filter still apply.
 *
 * @param string $ip Client IP, as returned by vireo_get_client_ip().
 */
function vireo_beacon_rate_limit_ok( string $ip ): bool {
	if ( '' === $ip ) {
		return true;
	}

	$budget = (int) apply_filters( 'vireo_beacon_rate_limit_per_minute', 60 );
	if ( $budget <= 0 ) {
		return true;
	}

	// Object cache is the cheapest counter when there is one. Transients are
	// deliberately NOT used as a fallback: without a persistent object cache they
	// land in the options table, and this runs on every public pageview.
	if ( wp_using_ext_object_cache() ) {
		// wp_cache_add() and wp_cache_incr() are atomic on Redis and Memcached,
		// which is the whole point here. Reading a transient, comparing it and
		// writing back is not: under a parallel flood every request reads the
		// same count, every request finds itself under budget, and every
		// request writes the same next value, so the limit can be exceeded by
		// as many requests as arrive together. The window is fixed rather than
		// sliding so the key expires on its own.
		$window = (int) floor( time() / MINUTE_IN_SECONDS );
		$key    = 'rl_' . substr( hash( 'sha256', vireo_rate_limit_key( $ip ) ), 0, 32 ) . '_' . $window;

		// add() succeeds only for the first request in this window.
		if ( wp_cache_add( $key, 1, 'vireo', 2 * MINUTE_IN_SECONDS ) ) {
			return 1 <= $budget;
		}

		$count = wp_cache_incr( $key, 1, 'vireo' );
		if ( false === $count ) {
			// The key expired between the add and the incr, or the backend
			// declined. Let the request through rather than reject on an
			// ambiguous counter.
			return true;
		}

		return (int) $count <= $budget;
	}

	if ( function_exists( 'apcu_enabled' ) && apcu_enabled() ) {
		$key = 'vireo_rl_' . substr( hash( 'sha256', $ip ), 0, 32 ) . '_' . (int) floor( time() / MINUTE_IN_SECONDS );
		$new = apcu_inc( $key, 1, $ok, MINUTE_IN_SECONDS * 2 );
		return ! $ok || (int) $new <= $budget;
	}

	return vireo_beacon_rate_limit_file_ok( $ip, $budget );
}

if ( ! function_exists( 'vireo_rate_limit_key' ) ) {
	/**
	 * Collapse an address to the unit a limit should apply to.
	 *
	 * A single IPv6 allocation is routinely a /64, which is 18 quintillion
	 * addresses. Limiting per address means one host rotates freely and never
	 * meets the limit, so IPv6 is bucketed to its /64 and IPv4 is used whole.
	 */
	function vireo_rate_limit_key( string $ip ): string {
		$packed = @inet_pton( $ip ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- malformed input falls through below
		if ( false === $packed || 16 !== strlen( $packed ) ) {
			return $ip;
		}
		// First 8 bytes = the /64 prefix.
		return bin2hex( substr( $packed, 0, 8 ) ) . '::/64';
	}
}

/**
 * Rate limit without an object cache and without touching the database.
 *
 * Most WordPress installs have no Redis or Memcached, and the endpoint this
 * guards is unauthenticated and reachable by the whole internet, so "no object
 * cache" cannot mean "no limit" — that left the beacon floodable on the
 * majority of sites.
 *
 * Counters live in a fixed set of shard files inside the (deny-all) data dir.
 * Each shard holds one fixed minute window; when the window rolls over the file
 * is simply overwritten, so nothing grows without bound and nothing needs
 * pruning. Fails open: if the filesystem won't cooperate we let the request
 * through rather than dropping real traffic.
 */
function vireo_beacon_rate_limit_file_ok( string $ip, int $budget ): bool {
	$dir = function_exists( 'vireo_data_dir' ) ? vireo_data_dir() : '';
	if ( '' === $dir || ! is_dir( $dir ) ) {
		return true;
	}

	$hash   = hash( 'sha256', vireo_rate_limit_key( $ip ) );
	$window = (int) floor( time() / MINUTE_IN_SECONDS );
	$file   = $dir . '/rl-' . substr( $hash, 0, 2 ) . '.php';

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,Generic.PHP.NoSilencedErrors.Discouraged -- counter file; WP_Filesystem has no locking API
	$handle = @fopen( $file, 'c+' );
	if ( false === $handle ) {
		return true;
	}

	try {
		// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- unlockable filesystem (NFS): fail open
		if ( ! @flock( $handle, LOCK_EX ) ) {
			return true;
		}

		$raw   = (string) stream_get_contents( $handle );
		$state = json_decode( $raw, true );

		if ( ! is_array( $state ) || (int) ( $state['w'] ?? -1 ) !== $window ) {
			$state = [
				'w' => $window,
				'c' => [],
			];
		}

		$key   = substr( $hash, 2, 12 );
		$count = (int) ( $state['c'][ $key ] ?? 0 ) + 1;

		if ( $count > $budget ) {
			return false;
		}

		$state['c'][ $key ] = $count;

		// Cap distinct keys per shard. Without it, an attacker rotating
		// addresses grows this file unbounded, and every request pays to read,
		// decode and rewrite the whole thing under an exclusive lock. Dropping
		// the oldest entries is safe: the window resets each minute anyway.
		$max_keys = (int) apply_filters( 'vireo_beacon_rate_limit_shard_keys', 2000 );
		if ( $max_keys > 0 && count( $state['c'] ) > $max_keys ) {
			$state['c'] = array_slice( $state['c'], -$max_keys, null, true );
		}

		rewind( $handle );
		ftruncate( $handle, 0 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- writing through the handle we hold an exclusive flock on; WP_Filesystem cannot read-modify-write under a lock, and dropping the lock to use it would let two concurrent requests each read the same counter and lose an increment, which is the whole point of the rate limiter.
		fwrite( $handle, (string) wp_json_encode( $state ) );
		fflush( $handle );

		return true;
	} finally {
		// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- best effort
		@flock( $handle, LOCK_UN );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $handle );
	}
}

if ( ! function_exists( 'vireo_visitor_opted_out' ) ) {
	/**
	 * Has this visitor asked not to be tracked?
	 *
	 * Do Not Track (`DNT: 1`) and Global Privacy Control (`Sec-GPC: 1`) are both
	 * sent as request headers, so one check covers the page request and the
	 * beacon. Honoured by default: a site owner who needs the pageviews more
	 * than the signal can return false from `vireo_honor_opt_out`.
	 *
	 * @param array<string, mixed> $server $_SERVER, or an equivalent map.
	 */
	function vireo_visitor_opted_out( array $server ): bool {
		if ( ! apply_filters( 'vireo_honor_opt_out', true ) ) {
			return false;
		}

		$dnt = isset( $server['HTTP_DNT'] ) ? trim( (string) $server['HTTP_DNT'] ) : '';
		$gpc = isset( $server['HTTP_SEC_GPC'] ) ? trim( (string) $server['HTTP_SEC_GPC'] ) : '';

		return '1' === $dnt || '1' === $gpc;
	}
}

/**
 * Read Vireo Analytics settings with sensible defaults.
 *
 * @return array<string, mixed>
 */
function vireo_get_settings(): array {
	$defaults = [
		// General.
		'track_logged_in_admins'   => false,
		'retention_days'           => 730,
		'admin_bar_enabled'        => true,

		// Exclusions.
		'excluded_ips'             => '',
		'excluded_paths'           => "/wp-admin/*\n/wp-login.php",
		'excluded_countries'       => '',
		// Logged-in users with any of these roles are not counted.
		'excluded_roles'           => [ 'administrator' ],
		// Kept for backwards-compat with the Phase 1 tracker and Pro's live
		// tracker; derived from `excluded_roles` below.
		'exclude_logged_in_admins' => true,

		// Email digest.
		'email_enabled'            => false,
		'email_recipients'         => '',
		'email_frequency'          => 'weekly', // daily | weekly | monthly.
		'email_day'                => 1,  // 1 = Mon, 7 = Sun; weekly only.
		'email_hour'               => 9,  // 0-23, site TZ.

		// Auto-event tracking. Off by default — site owners opt in from
		// Settings → Auto-events. Pro's Outbound_Tracker has a richer version
		// of `auto_event_outbound`; the Free handler self-disables when Pro
		// is active to avoid double-fires.
		'auto_event_downloads'     => false,
		'auto_event_forms'         => false,
		'auto_event_mailto'        => false,
		'auto_event_tel'           => false,
		'auto_event_outbound'      => false,

		// Headless front end: the one other origin allowed to send beacons,
		// as scheme://host[:port]. Empty means none.
		'headless_origin'          => '',
	];

	$stored   = get_option( 'vireo_settings', [] );
	$stored   = is_array( $stored ) ? $stored : [];
	$settings = wp_parse_args( $stored, $defaults );

	// A site that saved its settings before roles could be chosen only has
	// the administrator flag. Read the list from it, so nothing changes for
	// that site until it saves again.
	if ( ! isset( $stored['excluded_roles'] ) || ! is_array( $stored['excluded_roles'] ) ) {
		$settings['excluded_roles'] = empty( $settings['track_logged_in_admins'] ) ? [ 'administrator' ] : [];
	}
	$settings['excluded_roles'] = array_values( array_unique( array_map( 'strval', $settings['excluded_roles'] ) ) );

	// The two admin flags follow the list. Pro's live and outbound trackers
	// still read `exclude_logged_in_admins`.
	$settings['exclude_logged_in_admins'] = in_array( 'administrator', $settings['excluded_roles'], true );
	$settings['track_logged_in_admins']   = ! $settings['exclude_logged_in_admins'];

	return $settings;
}

if ( ! function_exists( 'vireo_uploads_basedir' ) ) {
	/**
	 * Resolve the WordPress uploads basedir. Returns empty string when the
	 * uploads directory is unavailable (wp_upload_dir() errored) — callers
	 * must treat an empty result as "no uploads dir, skip this work".
	 */
	function vireo_uploads_basedir(): string {
		// The tracker endpoint runs under SHORTINIT, where WP_CONTENT_URL is
		// never defined and wp_upload_dir() fatals on it. The installer caches
		// the resolved path so the beacon can read it without touching the
		// uploads machinery at all.
		$cached = (string) get_option( 'vireo_uploads_basedir', '' );
		if ( '' !== $cached && is_dir( $cached ) ) {
			return $cached;
		}

		if ( ! function_exists( 'wp_upload_dir' ) || ! defined( 'WP_CONTENT_URL' ) ) {
			return '';
		}

		$uploads = wp_upload_dir( null, false );
		if ( ! is_array( $uploads ) || ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}

		$base = rtrim( (string) $uploads['basedir'], '/\\' );

		// Refresh the cache whenever the resolved path moves (a migration, a
		// changed UPLOADS constant). Autoload off: only the endpoint and the
		// aggregator read it.
		if ( $base !== $cached ) {
			update_option( 'vireo_uploads_basedir', $base, false );
		}

		return $base;
	}
}
if ( ! function_exists( 'vireo_data_dir' ) ) {
	/**
	 * Runtime data dir for the aggregator's buffer files. Lives under uploads
	 * so plugin updates don't wipe it. Returns empty string when the uploads
	 * dir isn't usable.
	 */
	function vireo_data_dir(): string {
		$base = vireo_uploads_basedir();
		return '' === $base ? '' : $base . '/vireo-analytics';
	}
}
