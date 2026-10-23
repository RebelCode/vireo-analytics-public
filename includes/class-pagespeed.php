<?php

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * Google's lab test for one page, run on request.
 *
 * Vireo's own speed numbers are field data: what real visitors' browsers
 * measured. PageSpeed Insights is the other half, one Lighthouse run on
 * Google's servers that comes back with a score and a list of fixes. The two
 * disagree often, and that is expected; the dashboard labels which is which.
 *
 * The test only ever runs because someone pressed the button. No cron, nothing
 * on the front end. The browser never names a URL either: it says "this post"
 * or "the homepage" and the URL is worked out here, then checked against this
 * site's own hosts before anything is sent.
 *
 * One long request rather than start-and-poll. A test takes 20 to 40 seconds
 * and there is no background worker to hand it to without cron, which is ruled
 * out, or a loopback request, which plenty of hosts block. The request ignores
 * the browser going away, so if a proxy cuts the connection first the result
 * still lands in the cache and shows on the next page load.
 *
 * Google's keyless allowance is zero: every request without a key comes back
 * 429. Without a key the button is a plain link to pagespeed.web.dev, opened
 * in the browser, and this class makes no request and stores nothing. Running
 * the test here, with the results on the dashboard, needs an administrator to
 * add a key.
 */
final class PageSpeed {

	public const API    = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
	public const OPTION = 'vireo_psi_settings';
	public const NONCE  = 'vireo_pagespeed';
	public const ACTION = 'vireo_pagespeed_run';

	public const STRATEGIES = [ 'mobile', 'desktop' ];

	public const CACHE_PREFIX = 'vireo_psi_';
	public const CACHE_TTL    = 86400;

	private const TIMEOUT  = 60;
	private const LOCK_TTL = 120;
	private const TOP      = 5;

	/** Lighthouse groups that hold suggestions, old and new names. */
	private const SUGGESTION_GROUPS = [ 'insights', 'diagnostics', 'load-opportunities' ];

	/** Lab thresholds Lighthouse grades on: at or below [0] is good, above [1] is poor. */
	private const LAB_THRESHOLDS = [
		'lcp' => [ 2500, 4000 ],
		'fcp' => [ 1800, 3000 ],
		'tbt' => [ 200, 600 ],
		'cls' => [ 0.1, 0.25 ],
	];

	private const METRIC_AUDITS = [
		'lcp' => 'largest-contentful-paint',
		'cls' => 'cumulative-layout-shift',
		'tbt' => 'total-blocking-time',
		'fcp' => 'first-contentful-paint',
	];

	public static function boot(): void {
		add_action( 'wp_ajax_' . self::ACTION, [ __CLASS__, 'ajax_run' ] );
		add_action( 'admin_post_vireo_psi_save', [ __CLASS__, 'handle_save' ] );
		add_action( 'admin_post_vireo_psi_remove', [ __CLASS__, 'handle_remove' ] );
	}

	// ---------------------------------------------------------------------
	//  API key
	// ---------------------------------------------------------------------

	public static function api_key(): string {
		$raw = get_option( self::OPTION, [] );

		return is_array( $raw ) ? (string) ( $raw['api_key'] ?? '' ) : '';
	}

	public static function has_key(): bool {
		return '' !== self::api_key();
	}

	public static function valid_key( string $key ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_-]{20,100}$/', $key );
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function save_key( string $key ) {
		$key = trim( $key );

		if ( ! self::valid_key( $key ) ) {
			return new \WP_Error( 'vireo_psi_key_format', __( 'That does not look like a Google API key. It is a single line of letters, numbers, dashes and underscores, usually starting with AIza.', 'vireo-analytics' ) );
		}

		// Not autoloaded: only the dashboard and this request read it.
		update_option( self::OPTION, [ 'api_key' => $key ], false );

		return true;
	}

	public static function remove_key(): void {
		delete_option( self::OPTION );
	}

	// ---------------------------------------------------------------------
	//  Which URL
	// ---------------------------------------------------------------------

	/**
	 * Hosts a test may point at: this site's own, and each connected site's.
	 *
	 * @return string[] `host` or `host:port`, lowercase, default ports dropped.
	 */
	public static function allowed_hosts(): array {
		$hosts = [];

		$own = self::host_key( (string) home_url( '/' ) );
		if ( '' !== $own ) {
			$hosts[] = $own;
		}

		if ( class_exists( Sites::class ) ) {
			foreach ( Sites::all() as $site ) {
				$host = self::host_key( (string) $site['origin'] );
				if ( '' !== $host ) {
					$hosts[] = $host;
				}
			}
		}

		return array_values( array_unique( $hosts ) );
	}

	public static function host_key( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}

		$host   = strtolower( (string) $parts['host'] );
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$port   = isset( $parts['port'] ) ? (int) $parts['port'] : 0;

		if ( 0 === $port || ( 'http' === $scheme && 80 === $port ) || ( 'https' === $scheme && 443 === $port ) ) {
			return $host;
		}

		return $host . ':' . $port;
	}

	/**
	 * Accept an http(s) URL on one of the allowed hosts, and nothing else.
	 *
	 * @param string[] $allowed From allowed_hosts().
	 * @return string|\WP_Error The URL without its fragment.
	 */
	public static function validate_url( string $url, array $allowed ) {
		$url   = trim( $url );
		$parts = '' === $url || strlen( $url ) > 2000 ? false : wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), [ 'http', 'https' ], true ) ) {
			return new \WP_Error( 'vireo_psi_url', __( 'That is not a page address PageSpeed can test.', 'vireo-analytics' ) );
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || ! in_array( self::host_key( $url ), $allowed, true ) ) {
			return new \WP_Error( 'vireo_psi_host', __( 'Only pages on this site can be tested.', 'vireo-analytics' ) );
		}

		$hash = strpos( $url, '#' );

		return false === $hash ? $url : substr( $url, 0, $hash );
	}

	/**
	 * A host Google's servers cannot reach: a development machine or a private
	 * network.
	 */
	public static function is_local_host( string $host ): bool {
		$host = strtolower( trim( $host, '[]' ) );

		if ( '' === $host || 'localhost' === $host ) {
			return true;
		}

		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return false === filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}

		if ( false === strpos( $host, '.' ) ) {
			return true;
		}

		foreach ( [ '.localhost', '.local', '.test', '.internal', '.invalid', '.lan', '.home.arpa' ] as $suffix ) {
			if ( substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The URL for what the dashboard asked about: a post, or a site's homepage.
	 *
	 * @param string $target `post` or `home`.
	 * @param int    $id     The post ID, or the connected site ID (0 for this site).
	 * @return string|\WP_Error
	 */
	public static function target_url( string $target, int $id ) {
		if ( 'post' === $target ) {
			$post = $id > 0 ? get_post( $id ) : null;
			if ( ! $post instanceof \WP_Post || ! Post_Detail::can_view( $post, $id ) ) {
				return new \WP_Error( 'vireo_psi_post', __( 'Post not found.', 'vireo-analytics' ) );
			}
			if ( ! is_post_publicly_viewable( $post ) ) {
				return new \WP_Error( 'vireo_psi_not_public', __( 'PageSpeed can only test pages anyone can open. Publish this post first.', 'vireo-analytics' ) );
			}
			$url = (string) get_permalink( $post );
		} elseif ( 'home' === $target ) {
			if ( $id > 0 ) {
				$site = Sites::get( $id );
				if ( null === $site ) {
					return new \WP_Error( 'vireo_psi_site', __( 'That site is no longer connected.', 'vireo-analytics' ) );
				}
				$url = rtrim( (string) $site['origin'], '/' ) . '/';
			} else {
				$url = (string) home_url( '/' );
			}
		} else {
			return new \WP_Error( 'vireo_psi_target', __( 'That is not a page address PageSpeed can test.', 'vireo-analytics' ) );
		}

		return self::validate_url( $url, self::allowed_hosts() );
	}

	// ---------------------------------------------------------------------
	//  Running a test
	// ---------------------------------------------------------------------

	public static function cache_key( string $url, string $strategy ): string {
		return self::CACHE_PREFIX . md5( $strategy . '|' . $url );
	}

	/**
	 * The stored result for a page, or null if it has not been tested in the
	 * last day.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function cached( string $url, string $strategy ): ?array {
		$hit = get_transient( self::cache_key( $url, $strategy ) );

		if ( ! is_array( $hit ) || ( $hit['url'] ?? '' ) !== $url || ( $hit['strategy'] ?? '' ) !== $strategy ) {
			return null;
		}

		return $hit;
	}

	public static function request_url( string $url, string $strategy, string $key, string $locale = '' ): string {
		$args = [
			'url'      => $url,
			'strategy' => strtoupper( $strategy ),
			'category' => 'PERFORMANCE',
		];
		if ( '' !== $locale ) {
			$args['locale'] = $locale;
		}
		if ( '' !== $key ) {
			$args['key'] = $key;
		}

		return self::API . '?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Test a page, or return the stored result unless $force.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function run( string $url, string $strategy, bool $force = false ) {
		if ( ! in_array( $strategy, self::STRATEGIES, true ) ) {
			$strategy = 'mobile';
		}

		if ( ! $force ) {
			$hit = self::cached( $url, $strategy );
			if ( null !== $hit ) {
				return $hit;
			}
		}

		$key = self::api_key();
		if ( '' === $key ) {
			return new \WP_Error( 'vireo_psi_no_key', __( 'PageSpeed tests need a Google API key. An administrator can add one under Settings, Search Console & API.', 'vireo-analytics' ) );
		}

		// Two clicks, or two people, on the same page would spend two tests and
		// get the same answer twice.
		$lock = self::cache_key( $url, $strategy ) . '_lock';
		if ( get_transient( $lock ) ) {
			return new \WP_Error( 'vireo_psi_busy', __( 'A test of this page is already running. Its result shows here when it finishes.', 'vireo-analytics' ) );
		}
		set_transient( $lock, 1, self::LOCK_TTL );

		try {
			$response = wp_remote_get(
				self::request_url( $url, $strategy, $key, self::locale() ),
				[
					'timeout'     => self::TIMEOUT,
					'redirection' => 2,
					'headers'     => [ 'Accept' => 'application/json' ],
				]
			);

			$local = self::is_local_host( (string) wp_parse_url( $url, PHP_URL_HOST ) );

			if ( is_wp_error( $response ) ) {
				return self::transport_error( $response, $local );
			}

			$result = self::interpret( (int) wp_remote_retrieve_response_code( $response ), (string) wp_remote_retrieve_body( $response ), $local );
		} finally {
			delete_transient( $lock );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['url']      = $url;
		$result['strategy'] = $strategy;
		$result['fetched']  = time();

		set_transient( self::cache_key( $url, $strategy ), $result, self::CACHE_TTL );

		return $result;
	}

	/** Google's titles in the reader's language where Lighthouse has it. */
	private static function locale(): string {
		$locale = function_exists( 'get_user_locale' ) ? (string) get_user_locale() : '';

		return str_replace( '_', '-', $locale );
	}

	private static function transport_error( \WP_Error $error, bool $local ): \WP_Error {
		if ( $local ) {
			return self::local_error();
		}

		$message = strtolower( $error->get_error_message() );
		if ( false !== strpos( $message, 'timed out' ) || false !== strpos( $message, 'timeout' ) ) {
			return new \WP_Error( 'vireo_psi_timeout', __( 'Google did not answer within a minute. Slow pages can take that long. Try again.', 'vireo-analytics' ) );
		}

		return new \WP_Error(
			'vireo_psi_transport',
			sprintf(
				/* translators: %s: the network error, in English, from the server */
				__( 'Could not reach Google: %s', 'vireo-analytics' ),
				$error->get_error_message()
			)
		);
	}

	private static function local_error(): \WP_Error {
		return new \WP_Error( 'vireo_psi_local', __( 'PageSpeed runs on Google\'s servers, which cannot reach a site that is only on your computer or private network.', 'vireo-analytics' ) );
	}

	/**
	 * Turn Google's answer into a result or a plain-language error.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function interpret( int $code, string $body, bool $local = false ) {
		$json = json_decode( $body, true );

		if ( 200 === $code && is_array( $json ) && isset( $json['lighthouseResult'] ) ) {
			$parsed = self::parse( $json );
			if ( is_wp_error( $parsed ) && $local ) {
				return self::local_error();
			}
			return $parsed;
		}

		if ( $local ) {
			return self::local_error();
		}

		$google = is_array( $json ) && isset( $json['error'] ) && is_array( $json['error'] ) ? $json['error'] : [];
		$detail = (string) ( $google['message'] ?? '' );
		$reason = '';
		if ( isset( $google['errors'][0]['reason'] ) ) {
			$reason = (string) $google['errors'][0]['reason'];
		}

		if ( 429 === $code ) {
			return new \WP_Error( 'vireo_psi_quota', __( 'Google\'s limit for PageSpeed tests on this API key is used up for now. Try again later, or raise the quota in Google Cloud.', 'vireo-analytics' ) );
		}

		if ( 'keyInvalid' === $reason || false !== stripos( $detail, 'API key not valid' ) ) {
			return new \WP_Error( 'vireo_psi_key', __( 'Google rejected the API key. Check it under Settings, Search Console & API.', 'vireo-analytics' ) );
		}

		if ( 403 === $code ) {
			return new \WP_Error( 'vireo_psi_forbidden', __( 'Google refused the request. Check that the PageSpeed Insights API is enabled in the key\'s Google Cloud project and that the key\'s restrictions allow it.', 'vireo-analytics' ) );
		}

		if ( false !== stripos( $detail, 'Lighthouse returned error' ) || false !== stripos( $detail, 'unable to process' ) ) {
			return new \WP_Error( 'vireo_psi_unreachable', __( 'Google\'s test could not load this page. Check that it is public and opens for logged-out visitors.', 'vireo-analytics' ) );
		}

		if ( 200 === $code ) {
			return new \WP_Error( 'vireo_psi_shape', __( 'PageSpeed Insights sent back a result this version of Vireo cannot read.', 'vireo-analytics' ) );
		}

		return new \WP_Error(
			'vireo_psi_http',
			sprintf(
				/* translators: %d: HTTP status code */
				__( 'PageSpeed Insights returned an error (HTTP %d). Try again in a few minutes.', 'vireo-analytics' ),
				$code
			)
		);
	}

	/**
	 * The parts of a runPagespeed response the dashboard shows. The raw answer
	 * runs to hundreds of kilobytes; this keeps a few hundred bytes of it.
	 *
	 * @param array<string,mixed> $json
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function parse( array $json ) {
		$lhr = $json['lighthouseResult'] ?? null;
		if ( ! is_array( $lhr ) || ! isset( $lhr['audits'] ) || ! is_array( $lhr['audits'] ) ) {
			return new \WP_Error( 'vireo_psi_shape', __( 'PageSpeed Insights sent back a result this version of Vireo cannot read.', 'vireo-analytics' ) );
		}

		$runtime = (string) ( $lhr['runtimeError']['code'] ?? '' );
		if ( '' !== $runtime && 'NO_ERROR' !== $runtime ) {
			return new \WP_Error( 'vireo_psi_unreachable', __( 'Google\'s test could not load this page. Check that it is public and opens for logged-out visitors.', 'vireo-analytics' ) );
		}

		$score = $lhr['categories']['performance']['score'] ?? null;

		$metrics = [];
		foreach ( self::METRIC_AUDITS as $metric => $audit_id ) {
			$audit = $lhr['audits'][ $audit_id ] ?? null;
			$value = is_array( $audit ) ? ( $audit['numericValue'] ?? null ) : null;

			$metrics[ $metric ] = is_numeric( $value ) && 'error' !== ( $audit['scoreDisplayMode'] ?? '' ) ? (float) $value : null;
		}

		return [
			'final_url'     => (string) ( $lhr['finalDisplayedUrl'] ?? $lhr['finalUrl'] ?? '' ),
			'score'         => is_numeric( $score ) ? (int) round( (float) $score * 100 ) : null,
			'metrics'       => $metrics,
			'opportunities' => self::opportunities( $lhr ),
			'lighthouse'    => (string) ( $lhr['lighthouseVersion'] ?? '' ),
		];
	}

	/**
	 * The suggestions worth the most, biggest estimated saving first.
	 *
	 * Lighthouse 13 moved these from "opportunities" with an overall saving in
	 * milliseconds to "insights" with a saving per metric. Both are read: the
	 * saving is the largest of the time estimates, layout shift ranks after
	 * time, and a failing suggestion with only a byte estimate ranks last.
	 *
	 * @param array<string,mixed> $lhr
	 * @return array<int,array{id:string,title:string,display:string,ms:int,cls:float}>
	 */
	public static function opportunities( array $lhr ): array {
		$audits = (array) $lhr['audits'];

		$ids  = [];
		$refs = $lhr['categories']['performance']['auditRefs'] ?? null;
		if ( is_array( $refs ) && [] !== $refs ) {
			foreach ( $refs as $ref ) {
				if ( is_array( $ref ) && in_array( (string) ( $ref['group'] ?? '' ), self::SUGGESTION_GROUPS, true ) ) {
					$ids[] = (string) ( $ref['id'] ?? '' );
				}
			}
		} else {
			$ids = array_keys( $audits );
		}

		$out = [];
		foreach ( $ids as $id ) {
			$audit = $audits[ $id ] ?? null;
			if ( ! is_array( $audit ) || in_array( (string) ( $audit['scoreDisplayMode'] ?? '' ), [ 'notApplicable', 'manual', 'error', 'informative' ], true ) ) {
				continue;
			}

			$score = $audit['score'] ?? null;
			if ( is_numeric( $score ) && (float) $score >= 0.9 ) {
				continue;
			}

			$savings = is_array( $audit['metricSavings'] ?? null ) ? $audit['metricSavings'] : [];
			$ms      = (float) ( $audit['details']['overallSavingsMs'] ?? 0 );
			foreach ( [ 'LCP', 'FCP', 'TBT', 'INP' ] as $metric ) {
				if ( isset( $savings[ $metric ] ) && is_numeric( $savings[ $metric ] ) ) {
					$ms = max( $ms, (float) $savings[ $metric ] );
				}
			}
			$cls     = isset( $savings['CLS'] ) && is_numeric( $savings['CLS'] ) ? (float) $savings['CLS'] : 0.0;
			$display = trim( (string) ( $audit['displayValue'] ?? '' ) );

			if ( $ms <= 0 && $cls <= 0 && '' === $display ) {
				continue;
			}

			$out[] = [
				'id'      => (string) $id,
				'title'   => str_replace( '`', '', (string) ( $audit['title'] ?? $id ) ),
				'display' => $display,
				'ms'      => (int) round( $ms ),
				'cls'     => round( $cls, 3 ),
				'score'   => is_numeric( $score ) ? (float) $score : 1.0,
			];
		}

		usort(
			$out,
			static function ( array $a, array $b ): int {
				return [ $b['ms'], $b['cls'], $a['score'] ] <=> [ $a['ms'], $a['cls'], $b['score'] ];
			}
		);

		return array_map(
			static function ( array $o ): array {
				unset( $o['score'] );
				return $o;
			},
			array_slice( $out, 0, self::TOP )
		);
	}

	// ---------------------------------------------------------------------
	//  Display
	// ---------------------------------------------------------------------

	public static function report_url( string $url, string $strategy ): string {
		return 'https://pagespeed.web.dev/analysis?' . http_build_query(
			[
				'url'         => $url,
				'form_factor' => 'desktop' === $strategy ? 'desktop' : 'mobile',
			],
			'',
			'&',
			PHP_QUERY_RFC3986
		);
	}

	public static function score_rating( int $score ): string {
		if ( $score >= 90 ) {
			return 'good';
		}

		return $score >= 50 ? 'needs-improvement' : 'poor';
	}

	public static function lab_rating( string $metric, float $value ): string {
		$bounds = self::LAB_THRESHOLDS[ $metric ] ?? null;
		if ( null === $bounds ) {
			return 'unknown';
		}

		if ( $value <= $bounds[0] ) {
			return 'good';
		}

		return $value <= $bounds[1] ? 'needs-improvement' : 'poor';
	}

	public static function lab_display( string $metric, float $value ): string {
		if ( 'cls' === $metric ) {
			return number_format_i18n( $value, 2 );
		}

		if ( 'tbt' === $metric ) {
			return Vitals::display( 'inp', (int) round( $value ) );
		}

		return Vitals::display( 'lcp', (int) round( $value ) );
	}

	public static function lab_label( string $metric ): string {
		switch ( $metric ) {
			case 'tbt':
				return __( 'Blocking time', 'vireo-analytics' );
			case 'fcp':
				return __( 'First content', 'vireo-analytics' );
			default:
				return Vitals::label( $metric );
		}
	}

	public static function lab_explanation( string $metric ): string {
		switch ( $metric ) {
			case 'tbt':
				return __( 'The lab stand-in for response to clicks. A test cannot click, so it measures how long the page was too busy to respond.', 'vireo-analytics' );
			case 'fcp':
				return __( 'How long until anything appeared.', 'vireo-analytics' );
			default:
				return Vitals::explanation( $metric );
		}
	}

	public static function savings_label( array $opportunity ): string {
		$ms = (int) ( $opportunity['ms'] ?? 0 );
		if ( $ms > 0 ) {
			return sprintf(
				/* translators: %s: a duration, e.g. "0.3s" or "150ms" */
				__( 'Up to %s faster', 'vireo-analytics' ),
				$ms >= 1000 ? Vitals::display( 'lcp', $ms ) : Vitals::display( 'inp', $ms )
			);
		}

		if ( (float) ( $opportunity['cls'] ?? 0 ) > 0 ) {
			return __( 'Less layout shift', 'vireo-analytics' );
		}

		return '';
	}

	/**
	 * Locals for templates/dashboard/pagespeed.php.
	 *
	 * @return array{target:string,id:int,url:string,error:string,results:array<string,array<string,mixed>|null>}
	 */
	public static function view( string $target, int $id ): array {
		$url = self::target_url( $target, $id );

		$results = [];
		$has_key = self::has_key();
		foreach ( self::STRATEGIES as $strategy ) {
			$results[ $strategy ] = is_wp_error( $url ) || ! $has_key ? null : self::cached( $url, $strategy );
		}

		return [
			'target'  => $target,
			'id'      => $id,
			'url'     => is_wp_error( $url ) ? '' : $url,
			'error'   => is_wp_error( $url ) ? $url->get_error_message() : '',
			'results' => $results,
		];
	}

	/** @param array<string,mixed> $result */
	public static function render_result( array $result ): string {
		$va_psi_result = $result;

		ob_start();
		include VIREO_DIR . 'templates/dashboard/pagespeed-result.php';

		return (string) ob_get_clean();
	}

	// ---------------------------------------------------------------------
	//  Requests
	// ---------------------------------------------------------------------

	public static function ajax_run(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'view_vireo_analytics' ) ) {
			wp_send_json_error( [ 'message' => __( 'You are not allowed to run page tests.', 'vireo-analytics' ) ], 403 );
		}

		$target   = isset( $_POST['target'] ) ? sanitize_key( wp_unslash( (string) $_POST['target'] ) ) : '';
		$id       = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$strategy = isset( $_POST['strategy'] ) ? sanitize_key( wp_unslash( (string) $_POST['strategy'] ) ) : '';
		$force    = ! empty( $_POST['force'] );

		if ( ! in_array( $strategy, self::STRATEGIES, true ) ) {
			$strategy = 'mobile';
		}

		$url = self::target_url( $target, $id );
		if ( is_wp_error( $url ) ) {
			wp_send_json_error( [ 'message' => $url->get_error_message() ], 400 );
		}

		// A test can outlast a 30-second limit, and a proxy may give up on the
		// browser before Google answers. Finish anyway, so the result is cached
		// for the next page load.
		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- disabled on some hosts; the default limit then applies
			@set_time_limit( self::TIMEOUT + 30 );
		}

		$result = self::run( $url, $strategy, $force );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ], 'vireo_psi_busy' === $result->get_error_code() ? 409 : 502 );
		}

		wp_send_json_success( [ 'html' => self::render_result( $result ) ] );
	}

	private static function guard( string $nonce_action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'vireo-analytics' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( $nonce_action );
	}

	private static function settings_redirect( string $notice ): void {
		wp_safe_redirect( Settings::url( 'integrations', [ 'vireo_psi' => $notice ] ) . '#vireo-psi' );
		exit;
	}

	public static function handle_save(): void {
		self::guard( 'vireo_psi_save' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard()
		$key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['api_key'] ) ) : '';

		// The field never shows the saved key, so an empty save keeps it.
		if ( '' === $key && self::has_key() ) {
			self::settings_redirect( '' );
		}

		self::settings_redirect( is_wp_error( self::save_key( $key ) ) ? 'invalid' : 'saved' );
	}

	public static function handle_remove(): void {
		self::guard( 'vireo_psi_remove' );
		self::remove_key();
		self::settings_redirect( 'removed' );
	}
}
