<?php
/**
 * Headless front end.
 *
 * A site that uses WordPress for editing and something else (Astro, Next.js)
 * for the public pages has its readers on one origin and its analytics on
 * another. The tracker runs on the front end and posts across origins to the
 * same endpoint the theme would use; this class decides which origins may do
 * that, what goes back in the CORS headers, and which post a front-end path
 * is once the aggregator sees it.
 *
 * Loaded on the SHORTINIT endpoint as well as under full WordPress, so the
 * request-side methods only touch get_option(), apply_filters() and plain
 * string work. Post resolution needs the rewrite rules and runs in the
 * aggregator, on cron, where everything is loaded.
 *
 * Free allows one front end. The two seams Pro extends are
 * `vireo_headless_allowed_origins` (a longer list) and the `site` value that
 * travels with every buffered hit (the matched origin, '' for this site), so
 * hits from different front ends can be told apart without a schema change.
 */

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

final class Headless {

	/** Settings key holding the one allowed origin in free. */
	public const SETTING = 'headless_origin';

	/** Filter that may add cross-origin post IDs the path alone cannot resolve. */
	public const POST_ID_FILTER = 'vireo_headless_post_id';

	/** Matched origin for the response in flight, '' when same-site. */
	private static string $response_origin = '';

	/** @var array<string,int> path => post_id, for one aggregator run. */
	private static array $resolved = [];

	/**
	 * Reduce what someone typed, or what a browser sent, to `scheme://host[:port]`.
	 *
	 * Returns '' for anything that is not an http(s) origin: a bare host, a
	 * different scheme, userinfo, or a host that would not survive a DNS lookup.
	 * Default ports are dropped because browsers never send them, and the
	 * comparison is a plain string compare after this.
	 */
	public static function normalize_origin( string $input ): string {
		$input = trim( $input );
		if ( '' === $input || strlen( $input ) > 300 ) {
			return '';
		}

		$parts = wp_parse_url( $input );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return '';
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return '';
		}

		$host = strtolower( (string) $parts['host'] );
		// Hostnames, IPv4 and bracketed IPv6. Anything else is a typo or an
		// attempt to smuggle a path into the host part.
		if ( 1 !== preg_match( '/^(\[[0-9a-f:.]+\]|[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*)$/', $host ) ) {
			return '';
		}

		$port = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
		if ( $port < 0 || $port > 65535 ) {
			return '';
		}
		$default = 'https' === $scheme ? 443 : 80;
		if ( 0 === $port || $default === $port ) {
			return $scheme . '://' . $host;
		}
		return $scheme . '://' . $host . ':' . $port;
	}

	/**
	 * Origins allowed to send beacons from another site.
	 *
	 * Free returns the one configured origin. Pro adds its own list through
	 * the filter. Every entry is normalised so a stray slash or capital in a
	 * setting cannot make a real front end fail the exact compare below.
	 *
	 * The filter does not run on the SHORTINIT endpoint, because nothing that
	 * could hook it is loaded there; Endpoint_Installer keeps a site that uses
	 * it on admin-ajax for that reason.
	 *
	 * @param array<string,mixed>|null $settings Defaults to vireo_get_settings().
	 * @return array<int,string>
	 */
	public static function allowed_origins( ?array $settings = null ): array {
		$settings   = $settings ?? ( function_exists( 'vireo_get_settings' ) ? vireo_get_settings() : [] );
		$configured = self::normalize_origin( (string) ( $settings[ self::SETTING ] ?? '' ) );

		/**
		 * Origins allowed to send beacons to this site from another origin.
		 *
		 * @param array<int,string> $origins Normalised `scheme://host[:port]` strings.
		 */
		$origins = (array) apply_filters( 'vireo_headless_allowed_origins', '' !== $configured ? [ $configured ] : [] );

		$out = [];
		foreach ( $origins as $origin ) {
			$origin = self::normalize_origin( (string) $origin );
			if ( '' !== $origin && ! in_array( $origin, $out, true ) ) {
				$out[] = $origin;
			}
		}
		return $out;
	}

	/**
	 * The allowed origin a request's Origin header is, or ''.
	 *
	 * Exact: scheme, host and port. A subdomain or a different port is a
	 * different origin, which is the browser's definition too.
	 *
	 * @param array<int,string> $allowed Normalised origins.
	 */
	public static function match_origin( string $origin, array $allowed ): string {
		$origin = self::normalize_origin( $origin );
		if ( '' === $origin ) {
			return '';
		}
		return in_array( $origin, $allowed, true ) ? $origin : '';
	}

	/**
	 * host[:port] values that mean "this WordPress site" for a beacon.
	 *
	 * The request's own Host, plus the home and site URL hosts. A same-origin
	 * POST carries an Origin header too, and it has to keep working exactly
	 * as it does today, including on sites whose public scheme or hostname
	 * differs from the stored home URL behind a proxy.
	 *
	 * @param array<string,mixed> $server $_SERVER or an equivalent map.
	 * @return array<int,string>
	 */
	public static function own_hosts( array $server ): array {
		$hosts = [];

		$request_host = isset( $server['HTTP_HOST'] ) ? strtolower( trim( (string) $server['HTTP_HOST'] ) ) : '';
		$request_host = (string) preg_replace( '/[^a-z0-9.\-:\[\]]/', '', $request_host );
		if ( '' !== $request_host ) {
			$hosts[] = self::strip_default_port( $request_host );
		}

		foreach ( [ 'home', 'siteurl' ] as $option ) {
			$url   = (string) get_option( $option, '' );
			$parts = '' !== $url ? wp_parse_url( $url ) : null;
			if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
				continue;
			}
			$host = strtolower( (string) $parts['host'] );
			if ( isset( $parts['port'] ) ) {
				$default = 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) ) ? 443 : 80;
				if ( (int) $parts['port'] !== $default ) {
					$host .= ':' . (int) $parts['port'];
				}
			}
			$hosts[] = $host;
		}

		return array_values( array_unique( $hosts ) );
	}

	/**
	 * What kind of request a beacon is.
	 *
	 *  - `same`: no Origin, an opaque `null` Origin, something that does not
	 *    parse, or an Origin on one of our own hosts. Handled as it always was,
	 *    with no CORS headers.
	 *  - `headless`: an Origin on the allowed list. Gets CORS headers and its
	 *    origin travels with the hit as the `site`.
	 *  - `refused`: a real origin that is neither. The caller answers 403.
	 *
	 * Own hosts are checked first, so configuring the WordPress origin itself
	 * as the front end changes nothing.
	 *
	 * @param array<string,mixed> $server  $_SERVER or an equivalent map.
	 * @param array<int,string>   $allowed From allowed_origins().
	 * @param array<int,string>   $own     From own_hosts().
	 * @return array{kind:string,origin:string}
	 */
	public static function classify_request( array $server, array $allowed, array $own ): array {
		$raw = isset( $server['HTTP_ORIGIN'] ) ? trim( (string) $server['HTTP_ORIGIN'] ) : '';
		if ( '' === $raw || 'null' === $raw ) {
			return [ 'kind' => 'same', 'origin' => '' ];
		}

		$origin = self::normalize_origin( $raw );
		if ( '' === $origin ) {
			return [ 'kind' => 'same', 'origin' => '' ];
		}

		$origin_host = self::strip_default_port( (string) substr( $origin, strpos( $origin, '://' ) + 3 ) );
		if ( in_array( $origin_host, $own, true ) ) {
			return [ 'kind' => 'same', 'origin' => '' ];
		}

		$matched = self::match_origin( $origin, $allowed );
		if ( '' !== $matched ) {
			return [ 'kind' => 'headless', 'origin' => $matched ];
		}

		return [ 'kind' => 'refused', 'origin' => '' ];
	}

	/**
	 * Headers for a response to an allowed cross-origin request.
	 *
	 * One origin, never `*`, never credentials. `Vary: Origin` so a shared
	 * cache does not hand one front end's header to another. The preflight
	 * set is only added for OPTIONS; the beacon itself is a simple request
	 * (form-encoded POST) and never triggers one.
	 *
	 * @return array<string,string>
	 */
	public static function cors_headers( string $origin, bool $preflight = false ): array {
		if ( '' === $origin ) {
			return [];
		}
		$headers = [
			'Access-Control-Allow-Origin' => $origin,
			'Vary'                        => 'Origin',
		];
		if ( $preflight ) {
			$headers['Access-Control-Allow-Methods'] = 'POST, GET, OPTIONS';
			$headers['Access-Control-Allow-Headers'] = 'Content-Type';
			$headers['Access-Control-Max-Age']       = '86400';
		}
		return $headers;
	}

	/** Remember the matched origin so every exit path of the request can echo it. */
	public static function set_response_origin( string $origin ): void {
		self::$response_origin = $origin;
	}

	/** The matched origin for the request in flight; '' for same-site. Doubles as the hit's `site`. */
	public static function response_origin(): string {
		return self::$response_origin;
	}

	/** Emit the CORS headers for the request in flight, if it was cross-origin. */
	public static function send_cors_headers( bool $preflight = false ): void {
		if ( '' === self::$response_origin || headers_sent() ) {
			return;
		}
		foreach ( self::cors_headers( self::$response_origin, $preflight ) as $name => $value ) {
			header( $name . ': ' . $value );
		}
	}

	/**
	 * Which post a front-end path is.
	 *
	 * The path is mapped onto this site's home URL and handed to WordPress's
	 * own resolver, so a front end that mirrors the WordPress permalink
	 * structure needs no configuration. When that finds nothing, a post ID the
	 * page declared is used, but only if it is a post a visitor could see
	 * (published, of a viewable type): the ID comes from the visitor's browser
	 * and can be anything. The path wins when both
	 * resolve, because a declared ID can be stale after a client-side
	 * navigation while the path never is.
	 *
	 * Lookups are cached for the run and in the object cache for an hour.
	 * Runs in the aggregator, where url_to_postid() has rewrite rules to work
	 * with; it is not available on the SHORTINIT endpoint.
	 *
	 * @param string $path    The path as the front end sent it, already normalised.
	 * @param int    $claimed Post ID the page declared, 0 for none.
	 * @param string $site    The front end's origin, for the filter.
	 */
	public static function resolve_post_id( string $path, int $claimed, string $site ): int {
		$post_id = self::post_for_path( $path );

		if ( 0 === $post_id && $claimed > 0 && is_post_publicly_viewable( $claimed ) ) {
			$post_id = $claimed;
		}

		/**
		 * Map a front-end path to a post when the default resolution cannot.
		 *
		 * @param int    $post_id Resolved post ID, 0 for none.
		 * @param string $path    The path the front end sent.
		 * @param string $site    The front end's origin.
		 * @param int    $claimed The post ID the page declared, 0 for none.
		 */
		return max( 0, (int) apply_filters( self::POST_ID_FILTER, $post_id, $path, $site, $claimed ) );
	}

	private static function post_for_path( string $path ): int {
		if ( '' === $path || '/' === $path ) {
			return 0;
		}
		if ( isset( self::$resolved[ $path ] ) ) {
			return self::$resolved[ $path ];
		}

		$key   = 'hl_' . md5( $path );
		$found = false;
		$hit   = wp_cache_get( $key, 'vireo', false, $found );
		if ( $found && is_int( $hit ) ) {
			self::$resolved[ $path ] = $hit;
			return $hit;
		}

		$post_id = (int) url_to_postid( home_url( $path ) );
		if ( $post_id > 0 && ! is_post_publicly_viewable( $post_id ) ) {
			$post_id = 0;
		}

		wp_cache_set( $key, $post_id, 'vireo', HOUR_IN_SECONDS );
		self::$resolved[ $path ] = $post_id;
		return $post_id;
	}

	/**
	 * The tag to paste into a front end that is not using the Astro package.
	 *
	 * `defer` rather than `async` so `document.currentScript` is set when the
	 * tracker reads its data attributes, and so it runs after the page's
	 * `<meta name="vireo:post-id">` exists.
	 */
	public static function snippet( string $endpoint_url, string $tracker_url ): string {
		return sprintf(
			'<script defer src="%s" data-endpoint="%s"></script>', // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- text for the owner to paste into another site, not output on this one.
			esc_url_raw( $tracker_url ),
			esc_url_raw( $endpoint_url )
		);
	}

	private static function strip_default_port( string $host_port ): string {
		return (string) preg_replace( '/:(80|443)$/', '', $host_port );
	}
}
