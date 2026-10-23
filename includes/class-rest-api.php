<?php
/**
 * Read-only REST API under /wp-json/vireo-analytics/v1.
 *
 * All routes are read-only. Permission is gated by the same capability
 * (`view_vireo_analytics`) that protects the admin dashboard. Every /stats
 * route runs the matching ability's callback (see Abilities), so the query
 * logic, the post-visibility rule and the flattening of visitor-supplied
 * strings live in one place.
 *
 * The permission callback is filterable via `vireo_rest_permission_callback`
 * so consumers can expose a subset publicly (e.g. for shareable dashboards).
 */

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

final class REST_API {

	public const NAMESPACE = 'vireo-analytics/v1';

	/** Earliest allowed date in range args — 2 years back. */
	private const MAX_RANGE_DAYS = 730;

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		$date_args = [
			'start' => [
				'required'          => true,
				'type'              => 'string',
				'validate_callback' => [ $this, 'validate_date' ],
				'sanitize_callback' => [ $this, 'sanitize_date' ],
			],
			'end'   => [
				'required'          => true,
				'type'              => 'string',
				'validate_callback' => [ $this, 'validate_date' ],
				'sanitize_callback' => [ $this, 'sanitize_date' ],
			],
		];

		$limit_arg = [
			'limit' => [
				'required'          => false,
				'type'              => 'integer',
				'default'           => 10,
				'sanitize_callback' => [ $this, 'sanitize_limit' ],
			],
		];

		$permission = [ $this, 'permission_check' ];

		register_rest_route( self::NAMESPACE, '/stats/totals', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'route_totals' ],
			'permission_callback' => $permission,
			'args'                => $date_args,
		] );

		register_rest_route( self::NAMESPACE, '/stats/daily', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'route_daily' ],
			'permission_callback' => $permission,
			'args'                => $date_args,
		] );

		register_rest_route( self::NAMESPACE, '/stats/top-pages', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'route_top_pages' ],
			'permission_callback' => $permission,
			'args'                => array_merge( $date_args, $limit_arg ),
		] );

		register_rest_route( self::NAMESPACE, '/stats/top-referrers', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'route_top_referrers' ],
			'permission_callback' => $permission,
			'args'                => array_merge( $date_args, $limit_arg ),
		] );

		register_rest_route( self::NAMESPACE, '/stats/countries', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'route_countries' ],
			'permission_callback' => $permission,
			'args'                => array_merge( $date_args, [
				'limit' => [
					'required'          => false,
					'type'              => 'integer',
					'default'           => 20,
					'sanitize_callback' => [ $this, 'sanitize_limit' ],
				],
			] ),
		] );

		register_rest_route( self::NAMESPACE, '/stats/devices', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'route_devices' ],
			'permission_callback' => $permission,
			'args'                => $date_args,
		] );

		register_rest_route( self::NAMESPACE, '/stats/events', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'route_events' ],
			'permission_callback' => $permission,
			'args'                => array_merge( $date_args, $limit_arg, [
				'name' => [
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				],
			] ),
		] );

		register_rest_route( self::NAMESPACE, '/stats/post/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'route_post' ],
			'permission_callback' => $permission,
			'args'                => array_merge( $date_args, [
				'id' => [
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				],
			] ),
		] );

		// Everything below mirrors an ability one to one and runs the same
		// callback, so the two surfaces cannot drift apart. The ability layer
		// already clamps dates and limits; REST validates the args first so a
		// bad request answers 400 rather than being silently corrected.
		$optional_limit = function ( int $default ): array {
			return [
				'limit' => [
					'required'          => false,
					'type'              => 'integer',
					'default'           => $default,
					'sanitize_callback' => [ $this, 'sanitize_limit' ],
				],
			];
		};

		register_rest_route( self::NAMESPACE, '/stats/hourly', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'route_hourly' ],
			'permission_callback' => $permission,
			'args'                => $date_args,
		] );

		register_rest_route( self::NAMESPACE, '/stats/changes', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'route_changes' ],
			'permission_callback' => $permission,
			'args'                => array_merge( $date_args, $optional_limit( 3 ) ),
		] );

		register_rest_route( self::NAMESPACE, '/stats/highlights', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'route_highlights' ],
			'permission_callback' => $permission,
			'args'                => array_merge( $date_args, $optional_limit( 3 ) ),
		] );

		// /stats/channels, not /stats/sources: Pro has served revenue by source
		// at /stats/sources since 1.0, and a second handler on the same path
		// shadows whichever registered first.
		register_rest_route( self::NAMESPACE, '/stats/channels', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'route_sources' ],
			'permission_callback' => $permission,
			'args'                => $date_args,
		] );

		register_rest_route( self::NAMESPACE, '/stats/referrer', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'route_referrer' ],
			'permission_callback' => $permission,
			'args'                => array_merge( $date_args, $optional_limit( 10 ), [
				'host' => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				],
			] ),
		] );

		register_rest_route( self::NAMESPACE, '/stats/ai-referrals', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'route_ai_referrals' ],
			'permission_callback' => $permission,
			'args'                => $date_args,
		] );

		register_rest_route( self::NAMESPACE, '/stats/search-console', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'route_search_console' ],
			'permission_callback' => $permission,
			'args'                => array_merge( $date_args, $optional_limit( 10 ), [
				'post_id' => [
					'required'          => false,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				],
				'path'    => [
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				],
			] ),
		] );

		register_rest_route( self::NAMESPACE, '/stats/event/(?P<name>[a-z0-9_\-]+)', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'route_event_detail' ],
			'permission_callback' => $permission,
			'args'                => array_merge( $date_args, $optional_limit( 20 ), [
				'name' => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				],
			] ),
		] );

		register_rest_route( self::NAMESPACE, '/stats/404s', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'route_404s' ],
			'permission_callback' => $permission,
			'args'                => array_merge( $date_args, $optional_limit( 20 ) ),
		] );

		register_rest_route( self::NAMESPACE, '/stats/bots', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'route_bots' ],
			'permission_callback' => $permission,
			'args'                => $date_args,
		] );

		register_rest_route( self::NAMESPACE, '/stats/vitals', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'route_vitals' ],
			'permission_callback' => $permission,
			'args'                => $date_args,
		] );

		register_rest_route( self::NAMESPACE, '/stats/annotations', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'route_annotations' ],
			'permission_callback' => $permission,
			'args'                => $date_args,
		] );

		register_rest_route( self::NAMESPACE, '/stats/year-review', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'route_year_review' ],
			'permission_callback' => $permission,
			'args'                => [
				'year' => [
					'required'          => false,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				],
			],
		] );

		// Goals — Pro feature. The permission callback is the same Free check
		// (capability) AND the Pro gate. When Pro is inactive the route 403s,
		// keeping the surface area honest.
		$pro_permission = [ $this, 'pro_permission_check' ];

		register_rest_route( self::NAMESPACE, '/stats/goals', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'route_goals' ],
			'permission_callback' => $pro_permission,
			'args'                => $date_args,
		] );

		register_rest_route( self::NAMESPACE, '/stats/goals/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'route_goal_detail' ],
			'permission_callback' => $pro_permission,
			'args'                => array_merge( $date_args, [
				'id' => [
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				],
			] ),
		] );
	}

	// ---------------------------------------------------------------------
	// Permission + validation
	// ---------------------------------------------------------------------

	/**
	 * Base permission check for the dashboard REST endpoints.
	 *
	 * The `vireo_rest_permission_callback` filter can NARROW access (e.g. require
	 * an additional capability, an IP allowlist, a feature flag) but cannot
	 * BROADEN it. The default `current_user_can( Installer::CAPABILITY )`
	 * always has to pass first — returning `true` from the filter on its own
	 * is not enough to grant access.
	 *
	 * This is a deliberate safety rail: returning `__return_true` from a
	 * snippets plugin or a stale prototype should never be enough to expose
	 * the analytics endpoints anonymously. If you genuinely need a public
	 * read endpoint, register a separate route with its own permission
	 * callback rather than weakening this one.
	 */
	public function permission_check( \WP_REST_Request $request ): bool {
		$default = current_user_can( Installer::CAPABILITY );
		if ( ! $default ) {
			return false;
		}
		return (bool) apply_filters( 'vireo_rest_permission_callback', $default, $request );
	}

	/**
	 * Pro-gated permission check. Same baseline as the regular check, plus the
	 * `vireo_pro_is_active` filter must return true. The filter is only registered
	 * by the Pro loader, so the Free build will always 403 here.
	 */
	public function pro_permission_check( \WP_REST_Request $request ): bool {
		if ( ! $this->permission_check( $request ) ) {
			return false;
		}
		return (bool) apply_filters( 'vireo_pro_is_active', false );
	}

	public function validate_date( $value ): bool {
		return is_string( $value ) && (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value );
	}

	public function sanitize_date( $value ): string {
		$value = is_string( $value ) ? $value : '';
		if ( ! $this->validate_date( $value ) ) {
			return '';
		}
		// Cap at 2-year lookback from today to keep queries bounded.
		$today  = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		$parsed = \DateTimeImmutable::createFromFormat( 'Y-m-d', $value, new \DateTimeZone( 'UTC' ) );
		if ( ! $parsed instanceof \DateTimeImmutable ) {
			return '';
		}
		$min = $today->modify( '-' . self::MAX_RANGE_DAYS . ' days' );
		if ( $parsed < $min ) {
			return $min->format( 'Y-m-d' );
		}
		if ( $parsed > $today ) {
			return $today->format( 'Y-m-d' );
		}
		return $value;
	}

	public function sanitize_limit( $value ): int {
		$n = (int) $value;
		if ( $n < 1 ) return 1;
		if ( $n > 100 ) return 100;
		return $n;
	}

	/**
	 * @return array{0:string,1:string}|\WP_Error
	 */
	private function resolve_range( \WP_REST_Request $request ) {
		$start = (string) $request->get_param( 'start' );
		$end   = (string) $request->get_param( 'end' );

		if ( '' === $start || '' === $end ) {
			return new \WP_Error( 'vireo_rest_bad_range', __( 'Invalid start or end date.', 'vireo-analytics' ), [ 'status' => 400 ] );
		}
		if ( strcmp( $end, $start ) < 0 ) {
			return new \WP_Error( 'vireo_rest_bad_range', __( 'end must be on or after start.', 'vireo-analytics' ), [ 'status' => 400 ] );
		}
		return [ $start, $end ];
	}

	// ---------------------------------------------------------------------
	// Route callbacks
	// ---------------------------------------------------------------------

	// Every /stats route answers with its ability's callback through
	// Abilities::run(), so REST and MCP return the same numbers and the same
	// flattened strings. The route layer only validates and maps arguments.

	public function route_totals( \WP_REST_Request $request ) {
		return $this->from_ability( 'exec_totals', $request );
	}

	public function route_daily( \WP_REST_Request $request ) {
		return $this->from_ability( 'exec_daily', $request, [], true );
	}

	public function route_top_pages( \WP_REST_Request $request ) {
		return $this->from_ability( 'exec_top_pages', $request, [], true );
	}

	public function route_top_referrers( \WP_REST_Request $request ) {
		return $this->from_ability( 'exec_top_referrers', $request, [], true );
	}

	public function route_countries( \WP_REST_Request $request ) {
		return $this->from_ability( 'exec_countries', $request, [], true );
	}

	public function route_devices( \WP_REST_Request $request ) {
		return $this->from_ability( 'exec_devices', $request );
	}

	public function route_events( \WP_REST_Request $request ) {
		$input = [];
		if ( '' !== (string) $request->get_param( 'name' ) ) {
			$input['name'] = (string) $request->get_param( 'name' );
		}
		return $this->from_ability( 'exec_events', $request, $input, true );
	}

	public function route_post( \WP_REST_Request $request ) {
		// A draft the caller cannot read is not found here either.
		return $this->from_ability( 'exec_post', $request, [ 'post_id' => (int) $request->get_param( 'id' ) ] );
	}

	// ---------------------------------------------------------------------
	// Routes that run an ability's callback
	// ---------------------------------------------------------------------

	/**
	 * Run one of the Abilities callbacks with the validated REST args. An
	 * ability error carries no HTTP status, so one is added here from its code.
	 *
	 * @param array<string, mixed> $input
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function from_ability( string $method, \WP_REST_Request $request, array $input = [], bool $bare_rows = false ) {
		$range = $this->resolve_range( $request );
		if ( $range instanceof \WP_Error ) return $range;
		[ $start, $end ] = $range;

		$input += [ 'start' => $start, 'end' => $end ];
		if ( null !== $request->get_param( 'limit' ) ) {
			$input['limit'] = (int) $request->get_param( 'limit' );
		}

		$result = Abilities::instance()->run( $method, $input );

		// Routes from before 0.12.0 answered with a bare list. The abilities now
		// wrap lists as { rows: [...] } for MCP's sake; these routes keep their
		// shape for whoever already reads them.
		if ( $bare_rows && is_array( $result ) && isset( $result['rows'] ) && 1 === count( $result ) ) {
			$result = $result['rows'];
		}

		return $this->respond( $result );
	}

	/**
	 * @param mixed $result
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function respond( $result ) {
		if ( $result instanceof \WP_Error ) {
			$status = str_ends_with( $result->get_error_code(), '_not_found' ) ? 404 : 400;
			$result->add_data( [ 'status' => $status ] );
			return $result;
		}
		return rest_ensure_response( $result );
	}

	public function route_hourly( \WP_REST_Request $request ) {
		return $this->from_ability( 'exec_hourly', $request );
	}

	public function route_changes( \WP_REST_Request $request ) {
		return $this->from_ability( 'exec_changes', $request );
	}

	public function route_highlights( \WP_REST_Request $request ) {
		return $this->from_ability( 'exec_highlights', $request );
	}

	public function route_sources( \WP_REST_Request $request ) {
		return $this->from_ability( 'exec_traffic_sources', $request );
	}

	public function route_referrer( \WP_REST_Request $request ) {
		return $this->from_ability( 'exec_referrer_detail', $request, [ 'host' => (string) $request->get_param( 'host' ) ] );
	}

	public function route_ai_referrals( \WP_REST_Request $request ) {
		return $this->from_ability( 'exec_ai_referrals', $request );
	}

	public function route_search_console( \WP_REST_Request $request ) {
		$input = [];
		if ( $request->get_param( 'post_id' ) ) {
			$input['post_id'] = (int) $request->get_param( 'post_id' );
		}
		if ( '' !== (string) $request->get_param( 'path' ) ) {
			$input['path'] = (string) $request->get_param( 'path' );
		}
		return $this->from_ability( 'exec_search_console', $request, $input );
	}

	public function route_event_detail( \WP_REST_Request $request ) {
		return $this->from_ability( 'exec_event_detail', $request, [ 'name' => (string) $request->get_param( 'name' ) ] );
	}

	public function route_404s( \WP_REST_Request $request ) {
		return $this->from_ability( 'exec_404s', $request );
	}

	public function route_bots( \WP_REST_Request $request ) {
		return $this->from_ability( 'exec_bot_stats', $request );
	}

	public function route_vitals( \WP_REST_Request $request ) {
		return $this->from_ability( 'exec_vitals', $request );
	}

	public function route_annotations( \WP_REST_Request $request ) {
		return $this->from_ability( 'exec_annotations', $request );
	}

	public function route_year_review( \WP_REST_Request $request ) {
		$input = [];
		if ( $request->get_param( 'year' ) ) {
			$input['year'] = (int) $request->get_param( 'year' );
		}
		return $this->respond( Abilities::instance()->run( 'exec_year_review', $input ) );
	}

	// ---------------------------------------------------------------------
	// Goals (Pro)
	// ---------------------------------------------------------------------

	public function route_goals( \WP_REST_Request $request ) {
		$range = $this->resolve_range( $request );
		if ( $range instanceof \WP_Error ) return $range;
		[ $start, $end ] = $range;

		$cls = '\\VireoAnalytics\\Pro\\Goals';
		if ( ! class_exists( $cls ) ) {
			return new \WP_Error( 'vireo_rest_unavailable', __( 'Goals feature unavailable.', 'vireo-analytics' ), [ 'status' => 503 ] );
		}

		$goals       = $cls::all_goals();
		$range_stats = $cls::completions_in_range( $start, $end );

		$out = [];
		foreach ( $goals as $g ) {
			$gid          = (int) $g->id;
			$stats        = $range_stats[ $gid ] ?? [ 'completions' => 0, 'unique_completions' => 0, 'total_value' => 0.0 ];
			$out[] = [
				'id'                 => $gid,
				'name'               => (string) $g->name,
				'type'               => (string) $g->type,
				'pattern'            => (string) $g->pattern,
				'value'              => (float) $g->value,
				'active'             => (int) $g->active === 1,
				'completions'        => (int) $stats['completions'],
				'unique_completions' => (int) $stats['unique_completions'],
				'total_value'        => (float) $stats['total_value'],
			];
		}
		return rest_ensure_response( $out );
	}

	public function route_goal_detail( \WP_REST_Request $request ) {
		$range = $this->resolve_range( $request );
		if ( $range instanceof \WP_Error ) return $range;
		[ $start, $end ] = $range;

		$cls = '\\VireoAnalytics\\Pro\\Goals';
		if ( ! class_exists( $cls ) ) {
			return new \WP_Error( 'vireo_rest_unavailable', __( 'Goals feature unavailable.', 'vireo-analytics' ), [ 'status' => 503 ] );
		}

		$id   = (int) $request->get_param( 'id' );
		$goal = $cls::get_goal( $id );
		if ( ! $goal ) {
			return new \WP_Error( 'vireo_rest_not_found', __( 'Goal not found.', 'vireo-analytics' ), [ 'status' => 404 ] );
		}

		$range_stats = $cls::completions_in_range( $start, $end );
		$row         = $range_stats[ $id ] ?? [ 'completions' => 0, 'unique_completions' => 0, 'total_value' => 0.0 ];

		return rest_ensure_response( [
			'id'      => $id,
			'name'    => (string) $goal->name,
			'type'    => (string) $goal->type,
			'pattern' => (string) $goal->pattern,
			'value'   => (float) $goal->value,
			'active'  => (int) $goal->active === 1,
			'period'  => [
				'completions'        => (int) $row['completions'],
				'unique_completions' => (int) $row['unique_completions'],
				'total_value'        => (float) $row['total_value'],
			],
			'daily'   => $cls::daily_series( $id, $start, $end ),
		] );
	}
}
