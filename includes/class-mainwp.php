<?php
namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * Answers a MainWP dashboard that runs the Vireo Analytics for MainWP add-on.
 *
 * Nothing here is reachable over HTTP on its own. Both filters fire inside
 * MainWP Child's request handler, after it has verified the dashboard's RSA
 * signature and logged in the connected administrator, so the add-on never
 * needs a route of its own and this plugin never has to check a signature.
 *
 * Two hooks, two shapes of the same data:
 *
 * - `mainwp_site_sync_others_data` runs on every sync. When the dashboard
 *   flags `vireo` in its request, the last 7 and 30 days go back with the
 *   sync payload, which is what the dashboard's overview widget shows.
 * - `mainwp_child_extra_execution` serves an on-demand range, used by the
 *   per-site page and by Pro Reports tokens, and runs one of this site's
 *   read-only abilities for an assistant connected to the dashboard.
 *
 * Only aggregates leave the site: totals, the top pages and the top
 * referrers for the range. Nothing per visitor is ever included.
 */
final class MainWP {
	/** Bump when the payload shape changes; the add-on refuses older ones. */
	public const API = 1;

	public const MAX_SPAN_DAYS = 400;
	public const MAX_LIMIT     = 50;

	/** The most JSON a dashboard may send as an ability's input. */
	public const MAX_INPUT_BYTES = 8192;

	/** The read-only abilities a dashboard may run here through the `ability` action. */
	public const PROXIED_ABILITIES = [
		'vireo-analytics/get-totals',
		'vireo-analytics/get-daily-stats',
		'vireo-analytics/get-hourly-stats',
		'vireo-analytics/get-changes',
		'vireo-analytics/get-highlights',
		'vireo-analytics/get-top-pages',
		'vireo-analytics/get-top-referrers',
		'vireo-analytics/get-traffic-sources',
		'vireo-analytics/get-referrer-detail',
		'vireo-analytics/get-ai-referrals',
		'vireo-analytics/get-search-console',
		'vireo-analytics/get-countries',
		'vireo-analytics/get-devices',
		'vireo-analytics/get-events',
		'vireo-analytics/get-event-detail',
		'vireo-analytics/get-post-stats',
		'vireo-analytics/get-404s',
		'vireo-analytics/get-bot-stats',
		'vireo-analytics/get-vitals',
		'vireo-analytics/get-annotations',
		'vireo-analytics/get-year-review',
		'vireo-analytics/get-goals',
	];

	/** Never proxied, whatever the filter says: a child's connected sites are not the dashboard's. */
	private const NEVER_PROXIED = [
		'vireo-analytics/get-sites',
		'vireo-analytics/get-sites-overview',
	];

	public static function boot(): void {
		add_filter( 'mainwp_site_sync_others_data', [ self::class, 'sync_others_data' ], 10, 2 );
		add_filter( 'mainwp_child_extra_execution', [ self::class, 'extra_execution' ], 10, 2 );
		add_filter( 'mainwp_child_extra_execution', [ self::class, 'ability_execution' ], 10, 2 );
	}

	/**
	 * The site owner's say. Administrators always hold the capability;
	 * `vireo_mainwp_enabled` lets a site opt out of reporting to its
	 * dashboard with a one-line filter.
	 */
	public static function allowed(): bool {
		if ( ! apply_filters( 'vireo_mainwp_enabled', true ) ) {
			return false;
		}
		return current_user_can( Roles::CAPABILITY );
	}

	public static function sync_others_data( $information, $data ): array {
		if ( ! is_array( $information ) ) {
			$information = [];
		}
		if ( ! is_array( $data ) || empty( $data['vireo'] ) ) {
			return $information;
		}
		if ( ! self::allowed() ) {
			$information['vireo'] = self::envelope( [ 'denied' => true ] );
			return $information;
		}
		$information['vireo'] = self::envelope( [
			'ranges' => [
				'7'  => self::report_for_days( 7, 5 ),
				'30' => self::report_for_days( 30, 5 ),
			],
		] );
		return $information;
	}

	public static function extra_execution( $information, $post ) {
		if ( ! is_array( $post ) || 'stats' !== ( $post['vireo_action'] ?? '' ) ) {
			return $information;
		}
		if ( ! is_array( $information ) ) {
			$information = [];
		}

		$days  = (int) ( $post['vireo_days'] ?? 0 );
		$start = is_string( $post['vireo_start'] ?? null ) ? $post['vireo_start'] : '';
		$end   = is_string( $post['vireo_end'] ?? null ) ? $post['vireo_end'] : '';
		$limit = self::clamp_limit( $post['vireo_limit'] ?? 10 );

		if ( $days <= 0 && ! self::valid_range( $start, $end ) ) {
			$information['vireo'] = self::envelope( [ 'error' => 'bad_range' ] );
			return $information;
		}
		if ( ! self::allowed() ) {
			$information['vireo'] = self::envelope( [ 'denied' => true ] );
			return $information;
		}

		$report = $days > 0
			? self::report_for_days( min( $days, self::MAX_SPAN_DAYS ), $limit )
			: self::report( $start, $end, $limit );

		$information['vireo'] = self::envelope( [ 'report' => $report ] );
		return $information;
	}

	/**
	 * Run one of this site's read-only abilities for the dashboard, so an
	 * assistant connected to the dashboard can read this site by id.
	 *
	 * Narrowed to Vireo's own abilities: the name has to be `vireo-analytics/…`,
	 * registered here, carry the readonly annotation, and not be a site tool
	 * (this site's connected sites are not reachable through its dashboard).
	 * `site` is dropped from the input for the same reason. The ability then
	 * runs through WP_Ability::execute(), so its own permission callback and
	 * input validation apply as for the connected administrator.
	 *
	 * @param mixed $information
	 * @param mixed $post
	 * @return mixed
	 */
	public static function ability_execution( $information, $post ) {
		if ( ! is_array( $post ) || 'ability' !== ( $post['vireo_action'] ?? '' ) ) {
			return $information;
		}
		if ( ! is_array( $information ) ) {
			$information = [];
		}
		$information['vireo'] = self::envelope( self::ability_answer( $post ) );
		return $information;
	}

	/**
	 * @param array<string,mixed> $post
	 * @return array<string,mixed> `{ ability, result }`, `{ denied }` or `{ error, message }`.
	 */
	public static function ability_answer( array $post ): array {
		if ( ! self::allowed() ) {
			return [ 'denied' => true ];
		}
		$name = isset( $post['vireo_ability'] ) ? (string) $post['vireo_ability'] : '';
		/**
		 * Filter the abilities a MainWP dashboard may run on this site. Pro adds
		 * its own; the site tools are never proxied.
		 *
		 * @param array<int,string> $names Ability names.
		 */
		$allowed = (array) apply_filters( 'vireo_mainwp_proxied_abilities', self::PROXIED_ABILITIES );
		if ( 1 !== preg_match( '#^vireo-analytics/[a-z0-9-]+$#', $name ) || ! in_array( $name, $allowed, true ) || in_array( $name, self::NEVER_PROXIED, true ) ) {
			return [ 'error' => 'bad_ability', 'message' => __( 'That tool cannot be run on a child site.', 'vireo-analytics' ) ];
		}
		if ( ! function_exists( 'wp_get_ability' ) || ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( $name ) ) {
			return [ 'error' => 'unknown_ability', 'message' => __( 'This site does not have that tool.', 'vireo-analytics' ) ];
		}
		$ability = wp_get_ability( $name );
		$meta    = is_object( $ability ) && method_exists( $ability, 'get_meta' ) ? (array) $ability->get_meta() : [];
		if ( true !== ( $meta['annotations']['readonly'] ?? null ) ) {
			return [ 'error' => 'not_readonly', 'message' => __( 'Only read-only tools can be run on a child site.', 'vireo-analytics' ) ];
		}
		// The input is the dashboard's JSON, bounded in size and depth, and has
		// to be an object: a list or a scalar is not an ability input. The
		// ability's own schema then validates every key (additionalProperties
		// is false), and its permission callback runs for the connected user.
		// $post is $_POST as WordPress slashed it (wp_magic_quotes runs before
		// init, where MainWP Child handles the request), so the dashboard's JSON
		// arrives with every quote escaped and has to be unslashed first.
		$raw = isset( $post['vireo_input'] ) ? wp_unslash( (string) $post['vireo_input'] ) : '';
		if ( strlen( $raw ) > self::MAX_INPUT_BYTES ) {
			return [ 'error' => 'input_too_large', 'message' => __( 'The tool input is too large.', 'vireo-analytics' ) ];
		}
		$input = '' === $raw ? [] : json_decode( $raw, true, 8 );
		if ( ! is_array( $input ) || ( [] !== $input && array_keys( $input ) === range( 0, count( $input ) - 1 ) ) ) {
			return [ 'error' => 'bad_input', 'message' => __( 'The tool input must be a JSON object.', 'vireo-analytics' ) ];
		}
		unset( $input['site'] );
		$result = $ability->execute( $input );
		if ( is_wp_error( $result ) ) {
			return [ 'error' => (string) $result->get_error_code(), 'message' => (string) $result->get_error_message() ];
		}
		return [ 'ability' => $name, 'result' => $result ];
	}

	/**
	 * @param array<string,mixed> $body
	 * @return array<string,mixed>
	 */
	public static function envelope( array $body ): array {
		return [
			'api'       => self::API,
			'version'   => defined( 'VIREO_VERSION' ) ? VIREO_VERSION : '',
			'generated' => time(),
		] + $body;
	}

	public static function valid_range( string $start, string $end ): bool {
		$re = '/^\d{4}-\d{2}-\d{2}$/';
		if ( ! preg_match( $re, $start ) || ! preg_match( $re, $end ) ) {
			return false;
		}
		$utc = new \DateTimeZone( 'UTC' );
		$s   = \DateTimeImmutable::createFromFormat( '!Y-m-d', $start, $utc );
		$e   = \DateTimeImmutable::createFromFormat( '!Y-m-d', $end, $utc );
		if ( ! $s || ! $e || $s->format( 'Y-m-d' ) !== $start || $e->format( 'Y-m-d' ) !== $end ) {
			return false;
		}
		if ( $e < $s ) {
			return false;
		}
		return (int) $s->diff( $e )->days < self::MAX_SPAN_DAYS;
	}

	public static function clamp_limit( $value ): int {
		$n = (int) $value;
		if ( $n < 1 ) {
			return 1;
		}
		return min( $n, self::MAX_LIMIT );
	}

	/**
	 * Today and the (days - 1) before it, in the site's timezone. Matches the
	 * dashboard's own "Last 7 days" preset so both screens agree.
	 *
	 * @return array{0:string,1:string}
	 */
	public static function days_range( int $days, ?\DateTimeImmutable $today = null ): array {
		$today = ( $today ?? new \DateTimeImmutable( 'now', wp_timezone() ) )->setTime( 0, 0, 0 );
		$days  = max( 1, $days );
		return [ $today->modify( '-' . ( $days - 1 ) . ' days' )->format( 'Y-m-d' ), $today->format( 'Y-m-d' ) ];
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function report_for_days( int $days, int $limit ): array {
		[ $start, $end ] = self::days_range( $days );
		return self::report( $start, $end, $limit );
	}

	/**
	 * Plain sums for the range and for the range of equal length before it.
	 * The prior period is deliberately unweighted, unlike the dashboard's
	 * compare chip, so the numbers match a direct query of the rollups.
	 *
	 * @return array<string,mixed>
	 */
	public static function report( string $start, string $end, int $limit ): array {
		$dashboard = Dashboard::instance();
		$hero      = $dashboard->get_hero_stats( $start, $end, false );

		[ $prior_start, $prior_end ] = Dashboard::compare_range( $start, $end );
		$prior                       = $dashboard->get_hero_stats( $prior_start, $prior_end, false );

		return [
			'start'     => $start,
			'end'       => $end,
			'pageviews' => (int) $hero['pageviews'],
			'visitors'  => (int) $hero['visitors'],
			'prior'     => [
				'start'     => $prior_start,
				'end'       => $prior_end,
				'pageviews' => (int) $prior['pageviews'],
				'visitors'  => (int) $prior['visitors'],
			],
			'pages'     => self::shape_pages( $dashboard->get_top_pages( $start, $end, $limit, false ) ),
			'referrers' => self::shape_referrers( $dashboard->get_top_referrers( $start, $end, $limit, false ) ),
		];
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<int,array{path:string,title:string,url:string,pageviews:int,visitors:int}>
	 */
	public static function shape_pages( array $rows ): array {
		$out = [];
		foreach ( $rows as $r ) {
			$out[] = [
				'path'      => (string) ( $r['path'] ?? '' ),
				'title'     => (string) ( $r['title'] ?? '' ),
				'url'       => (string) ( $r['url'] ?? '' ),
				'pageviews' => (int) ( $r['pageviews'] ?? 0 ),
				'visitors'  => (int) ( $r['visitors'] ?? 0 ),
			];
		}
		return $out;
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<int,array{host:string,pageviews:int,visitors:int}>
	 */
	public static function shape_referrers( array $rows ): array {
		$out = [];
		foreach ( $rows as $r ) {
			$out[] = [
				'host'      => (string) ( $r['host'] ?? '' ),
				'pageviews' => (int) ( $r['pageviews'] ?? 0 ),
				'visitors'  => (int) ( $r['visitors'] ?? 0 ),
			];
		}
		return $out;
	}
}
