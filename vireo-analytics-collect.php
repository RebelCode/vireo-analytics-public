<?php
/**
 * Vireo Analytics — tracker endpoint handler.
 *
 * Routed via admin-ajax (`wp_ajax_vireo_collect` / `wp_ajax_nopriv_vireo_collect`).
 * Reads the rotating salt and exclusion rules from the database, then appends
 * a single row to the buffer for the aggregator to flush. Always responds 200
 * (or 204) with no body — never leaks bot/exclude signal back to the client.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'vireo_collect_request' ) ) :

/**
 * Main entry point. Bound to the admin-ajax action by vireo-analytics.php.
 */
function vireo_collect_request(): void {
	// A beacon naming a connected site. The key has to be one we issued and
	// the request Origin has to be that site's registered address; either
	// failing is a 403, like a stranger's origin below. From here on, every
	// table name and the buffer directory resolve to that site, which is how
	// its visits stay out of this site's numbers and vice versa.
	// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- public tracker beacon, see below.
	$vireo_site_key = isset( $_POST['site'] ) ? (string) $_POST['site'] : ( isset( $_GET['site'] ) ? (string) $_GET['site'] : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared against a hex key by a strict regex, never stored
	$vireo_site_key = strtolower( trim( (string) preg_replace( '/[^A-Za-z0-9]/', '', $vireo_site_key ) ) );
	$vireo_site     = null;
	if ( '' !== $vireo_site_key ) {
		$vireo_site = \VireoAnalytics\Sites::find_by_key( $vireo_site_key );
		if ( null === $vireo_site || ! \VireoAnalytics\Sites::request_matches( $_SERVER, $vireo_site ) ) {
			vireo_respond( 403 );
			return;
		}
		\VireoAnalytics\Sites::switch_to( (int) $vireo_site['id'] );
		\VireoAnalytics\Headless::set_response_origin( (string) $vireo_site['origin'] );
	} else {
		// Where did this come from? A beacon from the site's own pages carries no
		// Origin header, or one naming this host, and is handled as it always was.
		// One from the configured headless front end gets CORS headers and its
		// origin travels with the hit. Any other origin is refused: a stranger's
		// page has no business recording pageviews here.
		$vireo_request = \VireoAnalytics\Headless::classify_request(
			$_SERVER,
			\VireoAnalytics\Headless::allowed_origins(),
			\VireoAnalytics\Headless::own_hosts( $_SERVER )
		);
		if ( 'refused' === $vireo_request['kind'] ) {
			vireo_respond( 403 );
			return;
		}
		\VireoAnalytics\Headless::set_response_origin( $vireo_request['origin'] );
	}

	// CORS preflight. The beacon is a form-encoded POST and never triggers
	// one, but a front end that adds a header will, and an unanswered
	// preflight loses every pageview after it.
	if ( 'OPTIONS' === vireo_request_method() ) {
		vireo_respond( 204 );
		return;
	}

	// DoS guard: reject oversized request bodies before any parsing.
	// A legitimate beacon is well under 1 KB; 4 KB is 4x headroom.
	$content_length = isset( $_SERVER['CONTENT_LENGTH'] )
		? (int) sanitize_text_field( wp_unslash( (string) $_SERVER['CONTENT_LENGTH'] ) )
		: 0;
	if ( $content_length > 4096 ) {
		vireo_respond( 413 );
		return;
	}

	// Build a merged params map. AMP integrations send via GET; standard
	// beacons use POST. Whitelist keys so an attacker can't inflate $params.
	// Note: `kind` distinguishes pageview vs event — we route on it instead
	// of `action` because admin-ajax already consumes `action` for routing.
	//
	// Tracker beacons cannot ship a CSRF nonce (they are anonymous, fire
	// from cached HTML, and the site owner gets no signal benefit from one).
	// Each value is sanitized at first read before any further use.
	// `path` is handled separately below. sanitize_text_field() deletes
	// percent-encoded octets, and a browser sends REQUEST_URI encoded, so
	// running it here turned /caf%C3%A9/ into /caf/ and a Japanese slug into
	// bare slashes. Validation still happens on the encoded form.
	$allowed = [ 'kind', 'post_id', 'nv', 'up', 'referrer', 'test', 'name', 'value', 'nojs', 'lcp', 'cls', 'inp', 'seconds', 'n' ];
	$params  = [];
	foreach ( $allowed as $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- public tracker beacon, see comment above.
		if ( isset( $_POST[ $key ] ) ) {
			$params[ $key ] = sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public tracker beacon.
		} elseif ( isset( $_GET[ $key ] ) ) {
			$params[ $key ] = sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
	}

	// Raw, still-encoded path. Control characters are stripped before anything
	// else looks at it; the decode happens only after validation has passed.
	$raw_path = '';
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- public tracker beacon, see comment above.
	if ( isset( $_POST['path'] ) ) {
		$raw_path = wp_unslash( (string) $_POST['path'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public tracker beacon.
	} elseif ( isset( $_GET['path'] ) ) {
		$raw_path = wp_unslash( (string) $_GET['path'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}
	$raw_path = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $raw_path );
	if ( '' !== $raw_path ) {
		$params['path'] = $raw_path;
	}

	if ( empty( $params ) ) {
		vireo_respond( 400 );
		return;
	}

	// Test ping from the admin diagnostic, and from Endpoint_Installer::verify().
	//
	// It deliberately exercises the two things that can fail on the SHORTINIT
	// path but not under full WordPress: rotating the visitor salt, and writing
	// to the buffer directory. A ping that short-circuits before those would
	// report a healthy endpoint while real beacons fatalled, which is exactly
	// how a missing pluggable.php function went unnoticed.
	if ( isset( $params['test'] ) ) {
		$healthy = \VireoAnalytics\Collector::test_writable();
		try {
			$salts   = \VireoAnalytics\Salt_Manager::instance()->get_salts();
			$healthy = $healthy && '' !== (string) ( $salts['current'] ?? '' );
		} catch ( \Throwable $e ) {
			$healthy = false;
		}
		vireo_respond( $healthy ? 204 : 500 );
		return;
	}

	$kind = isset( $params['kind'] ) ? (string) $params['kind'] : 'pageview';
	$ua   = isset( $_SERVER['HTTP_USER_AGENT'] )
		? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) )
		: '';
	$ip   = vireo_get_client_ip();

	// Do Not Track / Global Privacy Control. The tracker isn't emitted for these
	// visitors in the first place; this is the backstop for a beacon that
	// reaches us anyway (a cached page served before the header was set, or a
	// hand-rolled request). Silent 200 — an opted-out visitor is not an error.
	if ( vireo_visitor_opted_out( $_SERVER ) ) {
		vireo_respond( 200 );
		return;
	}

	// Per-IP rate limit. Silent-200 on overage so attackers don't learn the
	// threshold. Tune via `vireo_beacon_rate_limit_per_minute` (default 60/min).
	if ( function_exists( 'vireo_beacon_rate_limit_ok' ) && ! vireo_beacon_rate_limit_ok( $ip ) ) {
		vireo_respond( 200 );
		return;
	}

	// Bot + salt guards are shared by both pageview + event paths.
	//
	// The rejection stays here. The counting moved to
	// Script_Loader::record_bot_request, which runs on the page request itself.
	// A crawler that executes JavaScript has already been counted there by the
	// time its beacon arrives, so tallying it again here counted one visit
	// twice, and did it only for the minority of agents that render at all.
	// The AI visibility scan's own requests, which carry a per-scan token.
	// Dropped before the bot check so a connected site's bot tally and the
	// no-JS pixel path both leave them out.
	if ( \VireoAnalytics\Bot_Filter::is_scan_request( $_SERVER ) ) {
		if ( ! empty( $params['nojs'] ) ) {
			vireo_respond_pixel();
			return;
		}
		vireo_respond( 200 );
		return;
	}

	$bot_category = \VireoAnalytics\Bot_Filter::classify( $ua, $_SERVER );
	if ( '' !== $bot_category ) {
		// A connected site has no page request on this server to count the
		// crawler on, so the beacon is the only place it can be seen at all.
		// Only the ones that run scripts get this far, which the dashboard's
		// wording for connected sites allows for.
		if ( null !== $vireo_site ) {
			\VireoAnalytics\Collector::append_bot(
				[
					'ts'       => time(),
					'category' => $bot_category,
				]
			);
		}
		if ( ! empty( $params['nojs'] ) ) {
			vireo_respond_pixel();
			return;
		}
		vireo_respond( 200 );
		return;
	}

	// Exclusion rules, read live from settings. The tracker runs inside
	// admin-ajax (WordPress is loaded), so there is no need for an on-disk
	// compiled copy — the option read is already in cache by this point.
	//
	// The IP check sits above every dispatch: an address the site owner has
	// excluded should be excluded from everything it sends, not only from
	// pageviews. Vitals used to be dispatched before this ran, so an excluded
	// office machine still shaped the site's Core Web Vitals.
	$settings       = vireo_get_settings();
	$excluded_ips   = vireo_parse_lines( (string) ( $settings['excluded_ips'] ?? '' ) );
	$excluded_paths = vireo_parse_lines( (string) ( $settings['excluded_paths'] ?? '' ) );

	if ( '' !== $ip && ! empty( $excluded_ips ) && vireo_ip_matches_rules( $ip, $excluded_ips ) ) {
		vireo_respond( 200 );
		return;
	}

	if ( 'event' === $kind ) {
		vireo_collect_event( $params, $ua, $ip, null !== $vireo_site );
		return;
	}

	$path = isset( $params['path'] ) ? substr( (string) $params['path'], 0, 500 ) : '';

	if ( '' !== $path && ! empty( $excluded_paths ) && vireo_path_matches_rules( $path, $excluded_paths ) ) {
		vireo_respond( 200 );
		return;
	}

	// Same normalisation for both kinds. Vitals used to keep the encoded form
	// while pageviews were decoded, so /caf%C3%A9/ was stored twice in two
	// spellings and the per-page speed table never lined up with the pages
	// table it is meant to explain.
	$path = vireo_normalize_request_path( $path );

	if ( '' === $path ) {
		vireo_respond( 400 );
		return;
	}

	if ( 'vitals' === $kind ) {
		vireo_collect_vitals( $params, $path );
		return;
	}

	if ( 'time' === $kind ) {
		vireo_collect_time( $params, $path, vireo_collect_visitor_hash( $ip, $ua ) );
		return;
	}

	// A connected site's pages are not posts here; the aggregator drops the
	// id again regardless.
	$post_id  = isset( $params['post_id'] ) && null === $vireo_site ? (int) $params['post_id'] : 0;
	$referrer = isset( $params['referrer'] )
		? (string) filter_var( trim( (string) $params['referrer'] ), FILTER_VALIDATE_URL )
		: '';
	if ( false === $referrer ) {
		$referrer = '';
	}

	// Uniqueness hint from the client — retained for diagnostics but ignored
	// when we have a valid visitor_hash. Server-side hash is the source of
	// truth; the hints are only used if the salt is unavailable.
	$new_visitor     = ! empty( $params['nv'] );
	$unique_pageview = ! empty( $params['up'] );

	$visitor_hash = vireo_collect_visitor_hash( $ip, $ua );

	$ok = \VireoAnalytics\Collector::append_pageview(
		[
			'ts'              => time(),
			'path'            => $path,
			'post_id'         => $post_id,
			'new_visitor'     => $new_visitor,
			'unique_pageview' => $unique_pageview,
			'referrer'        => $referrer,
			'ua'              => $ua,
			'ip'              => $ip,
			'visitor_hash'    => $visitor_hash,
			// Ask the host before we ask MaxMind. Cloudflare and friends already
			// know the country and tell us for free.
			'country'         => \VireoAnalytics\Geo_Lookup::country_from_headers( $_SERVER ),
			// Which front end sent it; '' for this site's own pages.
			'site'            => \VireoAnalytics\Headless::response_origin(),
		]
	);

	// Goal matching (Pro). Rules file is written by the admin; if it doesn't
	// exist or is empty the branch is a single isset check per request. Goals
	// are this site's, so a connected site's pages never match them.
	if ( null === $vireo_site ) {
		vireo_match_goals( [ 'kind' => 'page', 'ts' => time(), 'visitor_hash' => $visitor_hash, 'path' => $path ] );
	}

	// The no-JS fallback arrives as an <img>, so answer it with a real image
	// rather than an empty 204.
	if ( ! empty( $params['nojs'] ) ) {
		vireo_respond_pixel();
		return;
	}

	vireo_respond( $ok ? 204 : 500 );
}

/**
 * Handle `kind=event` — custom event ingestion.
 *
 * @param array<string, mixed> $params
 */
function vireo_collect_event( array $params, string $ua, string $ip, bool $connected = false ): void {
	$raw_name = (string) ( $params['name'] ?? '' );
	// Sanitize to a-z0-9_- (case-folded) — same constraint as sanitize_key().
	$name = strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', $raw_name ) );
	$name = substr( $name, 0, 100 );
	if ( '' === $name ) {
		vireo_respond( 400 );
		return;
	}

	$value = substr( (string) ( $params['value'] ?? '' ), 0, 100 );
	// Strip control chars; visible printable only.
	$value = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $value );

	$visitor_hash = vireo_collect_visitor_hash( $ip, $ua );

	$ok = \VireoAnalytics\Collector::append_event(
		[
			'ts'           => time(),
			'name'         => $name,
			'value'        => $value,
			'visitor_hash' => $visitor_hash,
			'site'         => \VireoAnalytics\Headless::response_origin(),
		]
	);

	if ( ! $connected ) {
		vireo_match_goals( [ 'kind' => 'event', 'ts' => time(), 'visitor_hash' => $visitor_hash, 'name' => $name ] );
	}

	vireo_respond( $ok ? 204 : 500 );
}

/**
 * Validate and normalise a request path from a beacon.
 *
 * Returns '' for anything that is not a plausible path, so callers answer 400.
 *
 * Validation happens on the encoded form, because that is what a browser
 * sends and what FILTER_VALIDATE_URL understands. Decoding comes after, and
 * the control-character strip is repeated afterwards because %00 and friends
 * only become characters at that point. Traversal is re-checked for the same
 * reason: %2e%2e%2f looks like an ordinary path until it is decoded.
 */
function vireo_normalize_request_path( string $raw ): string {
	if ( '' === $raw || false === filter_var( "https://localhost{$raw}", FILTER_VALIDATE_URL, FILTER_FLAG_PATH_REQUIRED ) ) {
		return '';
	}

	$path = rawurldecode( $raw );
	$path = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $path );
	$path = sanitize_text_field( $path );

	if ( '' === $path || '/' !== $path[0] ) {
		return '';
	}

	if ( false !== strpos( $path, '../' ) || false !== strpos( $path, '..\\' ) ) {
		return '';
	}

	return substr( $path, 0, 500 );
}

/**
 * Handle `kind=vitals` — one Core Web Vitals sample for a page.
 *
 * No visitor hash. Page speed is a property of the page, not of the person who
 * happened to load it, and there is nothing here worth tying to an individual.
 * Every value is clamped rather than trusted: the worst a forged beacon can do
 * is drag one page's percentile around.
 *
 * @param array<string, mixed> $params
 */
function vireo_collect_vitals( array $params, string $path ): void {
	// Already validated, decoded and traversal-checked by the caller, the same
	// way a pageview's path is. Whitespace is stripped on top: it cannot appear
	// in a path a browser requested, and every distinct string here becomes
	// several rows in Pro's per-page table, so a forged beacon should not be
	// able to invent pages freely. The rate limiter caps the volume; this caps
	// the variety.
	$path = (string) preg_replace( '/\s+/', '', $path );

	if ( '' === $path || '/' !== $path[0] ) {
		vireo_respond( 400 );
		return;
	}

	// Upper bounds are where the histogram tops out anyway, so anything beyond
	// them is already indistinguishable from "very bad".
	$lcp = min( 60000, max( 0, (int) ( $params['lcp'] ?? 0 ) ) );
	$inp = min( 60000, max( 0, (int) ( $params['inp'] ?? 0 ) ) );
	$cls = min( 10000, max( 0, (int) ( $params['cls'] ?? 0 ) ) );

	if ( 0 === $lcp && 0 === $inp && 0 === $cls ) {
		vireo_respond( 204 );
		return;
	}

	$ok = \VireoAnalytics\Collector::append_vitals(
		[
			'ts'   => time(),
			'path' => $path,
			'lcp'  => $lcp,
			'cls'  => $cls,
			'inp'  => $inp,
			'site' => \VireoAnalytics\Headless::response_origin(),
		]
	);

	vireo_respond( $ok ? 204 : 500 );
}

/**
 * Handle `kind=time` — active seconds on a page, reported as the reader hides
 * or leaves it.
 *
 * Clamped rather than trusted. The visitor hash travels with it so the
 * aggregator can hold the report to a pageview it actually counted, and a
 * report without one has nothing to be held to, so it is dropped here.
 *
 * @param array<string, mixed> $params
 */
function vireo_collect_time( array $params, string $path, string $visitor_hash ): void {
	$seconds = min( \VireoAnalytics\Collector::MAX_VIEW_SECONDS, max( 0, (int) ( $params['seconds'] ?? 0 ) ) );

	if ( 0 === $seconds || '' === $visitor_hash ) {
		vireo_respond( 204 );
		return;
	}

	$ok = \VireoAnalytics\Collector::append_time(
		[
			'ts'           => time(),
			'path'         => $path,
			'seconds'      => $seconds,
			'first'        => '1' === (string) ( $params['n'] ?? '' ),
			'visitor_hash' => $visitor_hash,
			'site'         => \VireoAnalytics\Headless::response_origin(),
		]
	);

	vireo_respond( $ok ? 204 : 500 );
}

/**
 * Compute the visitor hash from the current rotating salt, read from the DB
 * option via Salt_Manager (which rotates lazily on first read of a new day).
 * Empty string when there's no IP to hash — the upserter treats that as "no
 * uniqueness signal".
 */
function vireo_collect_visitor_hash( string $ip, string $ua ): string {
	if ( '' === $ip ) {
		return '';
	}
	$bundle = \VireoAnalytics\Salt_Manager::instance()->get_salts();
	if ( empty( $bundle['current'] ) ) {
		return '';
	}
	return \VireoAnalytics\Salt_Manager::hash_with_salt( $ip, $ua, (string) $bundle['current'] );
}

/** The HTTP method of the request in flight, upper-cased. */
function vireo_request_method(): string {
	return isset( $_SERVER['REQUEST_METHOD'] )
		? strtoupper( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) )
		: '';
}

/**
 * Emit response headers + status and terminate. Never writes a body.
 */
function vireo_respond( int $status ): void {
	status_header( (int) $status );
	nocache_headers();
	header( 'X-Robots-Tag: noindex, nofollow' );
	header( 'Tk: N' ); // W3C Do Not Track signal.
	\VireoAnalytics\Headless::send_cors_headers( 'OPTIONS' === vireo_request_method() );
	// Deliberately not wp_die(). Outside an ajax request it falls through to
	// the default handler, which renders the full "WordPress > Error" page,
	// inline CSS and all, on every single beacon response. That is several KB
	// of HTML nobody reads, on the hot path.
	exit;
}

/**
 * Answer the no-JS pixel with a 1x1 transparent GIF and terminate.
 */
function vireo_respond_pixel(): void {
	status_header( 200 );
	nocache_headers();
	header( 'X-Robots-Tag: noindex, nofollow' );
	header( 'Tk: N' );
	\VireoAnalytics\Headless::send_cors_headers();
	header( 'Content-Type: image/gif' );
	echo base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- fixed binary GIF literal.
	exit;
}

endif; // function_exists guard.
