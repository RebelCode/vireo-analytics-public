<?php
/**
 * WordPress Abilities API integration.
 *
 * Exposes the read-only analytics queries as registered abilities so AI agents
 * and other Abilities API clients (command palette, MCP servers) can ask "how's
 * my traffic" without learning the bespoke REST surface. Every ability reuses
 * the same Dashboard / Events / Post_Detail query logic that backs the admin
 * dashboard and REST API, so there is one source of truth for the numbers.
 *
 * Every ability is informational, declared as `meta.annotations.readonly`.
 * That nesting is load-bearing: core reads the flag from the annotations array
 * (WP_Ability::$default_annotations), and a `readonly` key at the top of `meta`
 * is stored but never consulted. With the annotation unset, the run controller
 * assumes an ability performs updates, requires POST, and answers GET with a
 * 405 — so a mislabelled read is both unreachable the obvious way and
 * advertised to agents as something that mutates the site.
 *
 * Registration is guarded by `function_exists( 'wp_register_ability' )`: the
 * Abilities API ships in WordPress core from 6.9, and earlier sites only get
 * these abilities when the Abilities API feature plugin is installed. When the
 * API is absent the init hooks never fire and this class is a no-op.
 *
 * Every free ability also carries `meta.mcp.public = true`. Vireo's own MCP
 * server (class-mcp.php) lists its tools by name, but the MCP Adapter's default
 * site-wide server, where another plugin runs one, discovers abilities through
 * that flag, and an analytics query is exactly what such a server should offer.
 *
 * Access is gated by the same `view_vireo_analytics` capability that protects
 * the dashboard and REST endpoints. The Pro goals abilities additionally require
 * the `vireo_pro_is_active` filter to return true, so the Free build never
 * exposes them.
 */

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

final class Abilities {

	public const CATEGORY = 'vireo-analytics';

	/**
	 * Annotations for an informational ability.
	 *
	 * Both spellings are deliberate. WordPress reads `readonly` / `idempotent`;
	 * MCP clients read `readOnlyHint` / `idempotentHint`. Current versions of
	 * the MCP Adapter translate between them, but older ones copy the
	 * annotations array to the tool verbatim, and whichever copy of the adapter
	 * loads first on a site wins — WooCommerce, for one, bundles its own. On
	 * those sites a WordPress-only annotation reaches the client with no
	 * readOnlyHint at all, and the agent is left to assume a traffic query might
	 * change something. Emitting both costs two array keys that core ignores.
	 */
	private const READ_ANNOTATIONS = [
		'readonly'       => true,
		'idempotent'     => true,
		'readOnlyHint'   => true,
		'idempotentHint' => true,
	];

	/** Match the REST API: cap the lookback window at 2 years. */
	private const MAX_RANGE_DAYS = 730;

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_abilities_api_categories_init', [ $this, 'register_category' ] );
		add_action( 'wp_abilities_api_init', [ $this, 'register_abilities' ] );
	}

	public function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category( self::CATEGORY, [
			'label'       => __( 'Vireo Analytics', 'vireo-analytics' ),
			'description' => __( 'Read-only site analytics: traffic, pages, referrers, geography, devices, and events.', 'vireo-analytics' ),
		] );
	}

	public function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$permission     = [ $this, 'permission_check' ];
		$pro_permission = [ $this, 'pro_permission_check' ];
		$range_props    = $this->range_schema_props();
		$meta           = $this->read_meta();

		wp_register_ability( 'vireo-analytics/get-totals', [
			'label'             => __( 'Get traffic totals', 'vireo-analytics' ),
			'description'       => __( 'Total pageviews, unique visitors, average daily pageviews, average time on page and bounce rate for a date range (defaults to the last 30 days). Time on page is active time (the tab in front of the reader, at most 30 minutes a view), averaged over the views that reported one. Bounce rate is the percentage of visitors who viewed exactly one page in a day. Both are null until there is data for the range.', 'vireo-analytics' ),
			'category'          => self::CATEGORY,
			'input_schema'      => $this->input_schema( $range_props ),
			'output_schema'     => [
				'type'       => 'object',
				'properties' => [
					'pageviews'                => [ 'type' => 'integer' ],
					'visitors'                 => [ 'type' => 'integer' ],
					'days'                     => [ 'type' => 'integer' ],
					'avg_daily'                => [ 'type' => 'integer' ],
					'avg_time_on_page_seconds' => [ 'type' => [ 'integer', 'null' ] ],
					'timed_views'              => [ 'type' => 'integer' ],
					'bounce_rate_percent'      => [ 'type' => [ 'number', 'null' ] ],
				],
			],
			'execute_callback'  => $this->guarded( 'exec_totals' ),
			'permission_callback' => $permission,
			'meta'              => $meta,
		] );

		wp_register_ability( 'vireo-analytics/get-daily-stats', [
			'label'             => __( 'Get daily traffic series', 'vireo-analytics' ),
			'description'       => __( 'Per-day pageviews and visitors over a date range, for charting trends.', 'vireo-analytics' ),
			'category'          => self::CATEGORY,
			'input_schema'      => $this->input_schema( $range_props ),
			'execute_callback'  => $this->guarded( 'exec_daily' ),
			'permission_callback' => $permission,
			'meta'              => $meta,
		] );

		wp_register_ability( 'vireo-analytics/get-top-pages', [
			'label'             => __( 'Get top pages', 'vireo-analytics' ),
			'description'       => __( 'Most-viewed pages for a date range, ranked by pageviews, with each page\'s average active time on page in seconds (null when no view of it reported a time).', 'vireo-analytics' ),
			'category'          => self::CATEGORY,
			'input_schema'      => $this->input_schema( $range_props + $this->limit_schema_prop( 10 ) ),
			'execute_callback'  => $this->guarded( 'exec_top_pages' ),
			'permission_callback' => $permission,
			'meta'              => $meta,
		] );

		wp_register_ability( 'vireo-analytics/get-top-referrers', [
			'label'             => __( 'Get top referrers', 'vireo-analytics' ),
			'description'       => __( 'Top referring sites sending traffic for a date range.', 'vireo-analytics' ),
			'category'          => self::CATEGORY,
			'input_schema'      => $this->input_schema( $range_props + $this->limit_schema_prop( 10 ) ),
			'execute_callback'  => $this->guarded( 'exec_top_referrers' ),
			'permission_callback' => $permission,
			'meta'              => $meta,
		] );

		wp_register_ability( 'vireo-analytics/get-countries', [
			'label'             => __( 'Get traffic by country', 'vireo-analytics' ),
			'description'       => __( 'Visitor counts broken down by country for a date range.', 'vireo-analytics' ),
			'category'          => self::CATEGORY,
			'input_schema'      => $this->input_schema( $range_props + $this->limit_schema_prop( 20 ) ),
			'execute_callback'  => $this->guarded( 'exec_countries' ),
			'permission_callback' => $permission,
			'meta'              => $meta,
		] );

		wp_register_ability( 'vireo-analytics/get-devices', [
			'label'             => __( 'Get device breakdown', 'vireo-analytics' ),
			'description'       => __( 'Visitor breakdown by browser, operating system, and device type for a date range.', 'vireo-analytics' ),
			'category'          => self::CATEGORY,
			'input_schema'      => $this->input_schema( $range_props ),
			'execute_callback'  => $this->guarded( 'exec_devices' ),
			'permission_callback' => $permission,
			'meta'              => $meta,
		] );

		wp_register_ability( 'vireo-analytics/get-events', [
			'label'             => __( 'Get custom events', 'vireo-analytics' ),
			'description'       => __( 'Top custom events for a date range. Pass a `name` to break a single event down by value.', 'vireo-analytics' ),
			'category'          => self::CATEGORY,
			'input_schema'      => $this->input_schema(
				$range_props + $this->limit_schema_prop( 10 ) + [
					'name' => [
						'type'        => 'string',
						'description' => __( 'Optional event name to break down by value.', 'vireo-analytics' ),
					],
				]
			),
			'execute_callback'  => $this->guarded( 'exec_events' ),
			'permission_callback' => $permission,
			'meta'              => $meta,
		] );

		wp_register_ability( 'vireo-analytics/get-post-stats', [
			'label'             => __( 'Get stats for a post', 'vireo-analytics' ),
			'description'       => __( 'Lifetime and date-range pageviews/visitors, a daily series, and the top referrers for a single post or page. Use get-top-pages first if you only have a title or path.', 'vireo-analytics' ),
			'category'          => self::CATEGORY,
			'input_schema'      => $this->input_schema(
				$range_props + [
					'post_id' => [
						'type'        => 'integer',
						'description' => __( 'The post or page ID to report on.', 'vireo-analytics' ),
					],
				],
				[ 'post_id' ]
			),
			'execute_callback'  => $this->guarded( 'exec_post' ),
			'permission_callback' => $permission,
			'meta'              => $meta,
		] );

		wp_register_ability( 'vireo-analytics/get-hourly-stats', [
			'label'             => __( 'Get hourly traffic series', 'vireo-analytics' ),
			'description'       => __( 'Pageviews and visitors per hour, for "what time of day do people visit" and for looking at today or yesterday in detail. Covers at most two days; longer ranges are cut to the last two days. Defaults to today.', 'vireo-analytics' ),
			'category'          => self::CATEGORY,
			'input_schema'      => $this->input_schema( $range_props ),
			'execute_callback'  => $this->guarded( 'exec_hourly' ),
			'permission_callback' => $permission,
			'meta'              => $meta,
		] );

		wp_register_ability( 'vireo-analytics/get-changes', [
			'label'             => __( 'Get what changed', 'vireo-analytics' ),
			'description'       => __( 'What changed against the previous period of the same length: a one-sentence summary, the pages and traffic sources that gained or lost the most, shifts in the traffic mix, and health warnings such as tracking having stopped or a 404 spike. Call this first when asked "how is the site doing" or "why is traffic up/down".', 'vireo-analytics' ),
			'category'          => self::CATEGORY,
			'input_schema'      => $this->input_schema( $range_props + $this->limit_schema_prop( 3 ) ),
			'execute_callback'  => $this->guarded( 'exec_changes' ),
			'permission_callback' => $permission,
			'meta'              => $meta,
		] );

		wp_register_ability( 'vireo-analytics/get-highlights', [
			'label'             => __( 'Get weekly highlights', 'vireo-analytics' ),
			'description'       => __( 'Short, ready-to-quote highlights for a finished week: milestones crossed, the biggest gaining page, a new referrer, the best day. Defaults to the last seven full days. Returns an empty list when nothing stood out.', 'vireo-analytics' ),
			'category'          => self::CATEGORY,
			'input_schema'      => $this->input_schema( $range_props + $this->limit_schema_prop( 3 ) ),
			'execute_callback'  => $this->guarded( 'exec_highlights' ),
			'permission_callback' => $permission,
			'meta'              => $meta,
		] );

		wp_register_ability( 'vireo-analytics/get-traffic-sources', [
			'label'             => __( 'Get traffic by channel', 'vireo-analytics' ),
			'description'       => __( 'Pageviews and visitors grouped by how people arrived: direct, search engines, AI assistants, social networks, and other referring sites, with each channel\'s share. Use get-top-referrers for the individual sites inside a channel.', 'vireo-analytics' ),
			'category'          => self::CATEGORY,
			'input_schema'      => $this->input_schema( $range_props ),
			'execute_callback'  => $this->guarded( 'exec_traffic_sources' ),
			'permission_callback' => $permission,
			'meta'              => $meta,
		] );

		wp_register_ability( 'vireo-analytics/get-referrer-detail', [
			'label'             => __( 'Get stats for one referrer', 'vireo-analytics' ),
			'description'       => __( 'Everything about traffic from one referring site: pageviews and visitors in the range, a daily series, and which of your pages it sent people to. Pass the host as shown by get-top-referrers, for example "news.ycombinator.com".', 'vireo-analytics' ),
			'category'          => self::CATEGORY,
			'input_schema'      => $this->input_schema(
				$range_props + $this->limit_schema_prop( 10 ) + [
					'host' => [
						'type'        => 'string',
						'description' => __( 'The referring site, as a hostname or URL.', 'vireo-analytics' ),
					],
				],
				[ 'host' ]
			),
			'execute_callback'  => $this->guarded( 'exec_referrer_detail' ),
			'permission_callback' => $permission,
			'meta'              => $meta,
		] );

		wp_register_ability( 'vireo-analytics/get-ai-referrals', [
			'label'             => __( 'Get AI assistant traffic', 'vireo-analytics' ),
			'description'       => __( 'What AI assistants take and what they send back: requests made by AI crawlers (GPTBot, ClaudeBot, PerplexityBot and others) against readers (visitors) that ChatGPT, Claude, Perplexity, Gemini and the rest referred to the site, per assistant, with the ratio of crawler requests per reader sent. Everything covers the days crawlers were counted; when that starts later than the requested range, `since` holds that date.', 'vireo-analytics' ),
			'category'          => self::CATEGORY,
			'input_schema'      => $this->input_schema( $range_props ),
			'execute_callback'  => $this->guarded( 'exec_ai_referrals' ),
			'permission_callback' => $permission,
			'meta'              => $meta,
		] );

		wp_register_ability( 'vireo-analytics/get-search-console', [
			'label'             => __( 'Get Google Search Console data', 'vireo-analytics' ),
			'description'       => __( 'Google Search performance synced from Search Console: site-wide clicks, impressions, CTR and average position plus the top search queries, or, when post_id or path is given, the queries that brought people to that one page. Returns connected=false with no data when Search Console is not connected under Settings. Google\'s data lags two to three days behind.', 'vireo-analytics' ),
			'category'          => self::CATEGORY,
			'input_schema'      => $this->input_schema(
				$range_props + $this->limit_schema_prop( 10 ) + [
					'post_id' => [
						'type'        => 'integer',
						'description' => __( 'Optional post or page ID to get the queries for that page only.', 'vireo-analytics' ),
					],
					'path'    => [
						'type'        => 'string',
						'description' => __( 'Optional page path (for example /pricing) instead of post_id.', 'vireo-analytics' ),
					],
				]
			),
			'execute_callback'  => $this->guarded( 'exec_search_console' ),
			'permission_callback' => $permission,
			'meta'              => $meta,
		] );

		wp_register_ability( 'vireo-analytics/get-event-detail', [
			'label'             => __( 'Get stats for one event', 'vireo-analytics' ),
			'description'       => __( 'One custom event in depth: total and unique counts, a daily series, how many distinct values it carried, and the top values. Use get-events to find event names first.', 'vireo-analytics' ),
			'category'          => self::CATEGORY,
			'input_schema'      => $this->input_schema(
				$range_props + $this->limit_schema_prop( 20 ) + [
					'name' => [
						'type'        => 'string',
						'description' => __( 'The event name, as listed by get-events.', 'vireo-analytics' ),
					],
				],
				[ 'name' ]
			),
			'execute_callback'  => $this->guarded( 'exec_event_detail' ),
			'permission_callback' => $permission,
			'meta'              => $meta,
		] );

		wp_register_ability( 'vireo-analytics/get-404s', [
			'label'             => __( 'Get missing pages (404s)', 'vireo-analytics' ),
			'description'       => __( 'Paths that returned 404, ranked by hits. Split into broken links (reached from one of your own pages, with the page that links there, so they can be fixed) and probes (scanners and outside links). Use this for "do I have broken links" and for a 404 spike flagged by get-changes.', 'vireo-analytics' ),
			'category'          => self::CATEGORY,
			'input_schema'      => $this->input_schema( $range_props + $this->limit_schema_prop( 20 ) ),
			'execute_callback'  => $this->guarded( 'exec_404s' ),
			'permission_callback' => $permission,
			'meta'              => $meta,
		] );

		wp_register_ability( 'vireo-analytics/get-bot-stats', [
			'label'             => __( 'Get bot and crawler traffic', 'vireo-analytics' ),
			'description'       => __( 'Requests the bot filter rejected, per category (search engines, AI crawlers, SEO tools, monitors, scrapers and so on), with the share of all requests they made up. None of these are in the pageview totals; use this when numbers look lower than another tool\'s, or when asked how much AI crawlers read the site.', 'vireo-analytics' ),
			'category'          => self::CATEGORY,
			'input_schema'      => $this->input_schema( $range_props ),
			'execute_callback'  => $this->guarded( 'exec_bot_stats' ),
			'permission_callback' => $permission,
			'meta'              => $meta,
		] );

		wp_register_ability( 'vireo-analytics/get-vitals', [
			'label'             => __( 'Get Core Web Vitals', 'vireo-analytics' ),
			'description'       => __( 'Core Web Vitals (LCP, INP, CLS) measured on real visitors, reported at the 75th percentile the way Google grades them, each with a good/needs-improvement/poor rating and the sample count. Empty when no measurements were recorded in the range.', 'vireo-analytics' ),
			'category'          => self::CATEGORY,
			'input_schema'      => $this->input_schema( $range_props ),
			'execute_callback'  => $this->guarded( 'exec_vitals' ),
			'permission_callback' => $permission,
			'meta'              => $meta,
		] );

		wp_register_ability( 'vireo-analytics/get-annotations', [
			'label'             => __( 'Get chart notes', 'vireo-analytics' ),
			'description'       => __( 'Notes the site owner pinned to dates on the traffic chart, such as "newsletter sent" or "redesign launched". Check these when explaining a spike or a dip, since they often hold the reason.', 'vireo-analytics' ),
			'category'          => self::CATEGORY,
			'input_schema'      => $this->input_schema( $range_props ),
			'execute_callback'  => $this->guarded( 'exec_annotations' ),
			'permission_callback' => $permission,
			'meta'              => $meta,
		] );

		wp_register_ability( 'vireo-analytics/get-year-review', [
			'label'             => __( 'Get year in review', 'vireo-analytics' ),
			'description'       => __( 'The year in review for one calendar year: totals against the year before, the busiest month and day, the top pages, posts published that year, top referrers, countries, and AI crawler activity. Includes imported history from a previous analytics tool. Defaults to the current year.', 'vireo-analytics' ),
			'category'          => self::CATEGORY,
			'input_schema'      => $this->input_schema( [
				'year' => [
					'type'        => 'integer',
					'minimum'     => 2000,
					'description' => __( 'The calendar year to report on. Defaults to the current year.', 'vireo-analytics' ),
				],
			] ),
			'execute_callback'  => $this->guarded( 'exec_year_review' ),
			'permission_callback' => $permission,
			'meta'              => $meta,
		] );

		// Goals — Pro. Permission check folds in the Pro gate, so the ability is
		// registered everywhere but only executes when Pro is active.
		wp_register_ability( 'vireo-analytics/get-goals', [
			'label'             => __( 'Get goal completions (Pro)', 'vireo-analytics' ),
			'description'       => __( 'Conversion goals with completion counts and total value for a date range. Requires Vireo Analytics Pro.', 'vireo-analytics' ),
			'category'          => self::CATEGORY,
			'input_schema'      => $this->input_schema( $range_props ),
			'execute_callback'  => $this->guarded( 'exec_goals' ),
			'permission_callback' => $pro_permission,
			'meta'              => [ 'annotations' => self::READ_ANNOTATIONS, 'show_in_rest' => true ],
		] );
	}

	// ---------------------------------------------------------------------
	// Permission
	// ---------------------------------------------------------------------

	/**
	 * Same capability gate as the dashboard and REST API.
	 *
	 * We deliberately do NOT reuse the REST `vireo_rest_permission_callback`
	 * filter here: that filter is documented to receive a WP_REST_Request as its
	 * second argument, and abilities only have an input array to offer. Handing a
	 * different type to existing filter callbacks would break them. The capability
	 * is the real gate, and abilities that need narrowing can use the dedicated
	 * `vireo_abilities_permission_callback` filter (default-only narrowing).
	 *
	 * @param array<string, mixed> $input
	 */
	public function permission_check( $input ): bool {
		$default = current_user_can( Installer::CAPABILITY );
		if ( ! $default ) {
			return false;
		}
		return (bool) apply_filters( 'vireo_abilities_permission_callback', $default, $input );
	}

	/**
	 * Pro-gated permission: baseline capability plus an active Pro license. The
	 * `vireo_pro_is_active` filter is only registered by the Pro loader, so the
	 * Free build always denies.
	 *
	 * @param array<string, mixed> $input
	 */
	public function pro_permission_check( $input ): bool {
		if ( ! $this->permission_check( $input ) ) {
			return false;
		}
		return (bool) apply_filters( 'vireo_pro_is_active', false );
	}

	// ---------------------------------------------------------------------
	// Execute callbacks
	// ---------------------------------------------------------------------

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, int|float|null>|\WP_Error
	 */
	public function exec_totals( $input ) {
		[ $start, $end ] = $this->resolve_range( $input );

		$hero = Dashboard::instance()->get_hero_stats( $start, $end, false );
		return [
			'pageviews'                => (int) $hero['pageviews'],
			'visitors'                 => (int) $hero['visitors'],
			'days'                     => (int) $hero['days'],
			'avg_daily'                => (int) $hero['avg_daily'],
			'avg_time_on_page_seconds' => isset( $hero['avg_time'] ) ? (int) $hero['avg_time'] : null,
			'timed_views'              => (int) ( $hero['time_samples'] ?? 0 ),
			'bounce_rate_percent'      => isset( $hero['bounce_rate'] ) ? (float) $hero['bounce_rate'] : null,
		];
	}

	/**
	 * @param array<string, mixed> $input
	 */
	public function exec_daily( $input ): array {
		[ $start, $end ] = $this->resolve_range( $input );
		return Dashboard::instance()->get_chart_data( $start, $end );
	}

	/**
	 * @param array<string, mixed> $input
	 */
	public function exec_top_pages( $input ): array {
		[ $start, $end ] = $this->resolve_range( $input );
		$limit = $this->resolve_limit( $input, 10 );

		$out = [];
		foreach ( Dashboard::instance()->get_top_pages( $start, $end, $limit, false ) as $r ) {
			$out[] = [
				'post_id'   => (int) $r['post_id'],
				'path'      => (string) $r['path'],
				'title'     => (string) $r['title'],
				'pageviews' => (int) $r['pageviews'],
				'visitors'  => (int) $r['visitors'],
				// Active seconds per timed view, null when no view reported one.
				'avg_time_on_page_seconds' => isset( $r['avg_time'] ) ? (int) $r['avg_time'] : null,
			];
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $input
	 */
	public function exec_top_referrers( $input ): array {
		[ $start, $end ] = $this->resolve_range( $input );
		$limit = $this->resolve_limit( $input, 10 );

		$out = [];
		foreach ( Dashboard::instance()->get_top_referrers( $start, $end, $limit, false ) as $r ) {
			$out[] = [
				'url'       => (string) $r['host'],
				'pageviews' => (int) $r['pageviews'],
				'visitors'  => (int) $r['visitors'],
			];
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $input
	 */
	public function exec_countries( $input ): array {
		[ $start, $end ] = $this->resolve_range( $input );
		$limit = $this->resolve_limit( $input, 20 );

		$out = [];
		foreach ( Dashboard::instance()->get_countries( $start, $end, $limit, false ) as $r ) {
			$out[] = [
				'code'      => (string) $r['code'],
				'name'      => (string) $r['name'],
				'pageviews' => (int) $r['pageviews'],
				'visitors'  => (int) $r['visitors'],
			];
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $input
	 */
	public function exec_devices( $input ): array {
		[ $start, $end ] = $this->resolve_range( $input );
		$devices = Dashboard::instance()->get_devices( $start, $end, false );

		$project = static function ( array $rows ): array {
			$out = [];
			foreach ( $rows as $r ) {
				$out[] = [
					'label'    => (string) $r['label'],
					'visitors' => (int) $r['visitors'],
					'pct'      => (float) $r['pct'],
				];
			}
			return $out;
		};

		return [
			'browsers'     => $project( $devices['browsers'] ?? [] ),
			'os'           => $project( $devices['os'] ?? [] ),
			'device_types' => $project( $devices['devices'] ?? [] ),
		];
	}

	/**
	 * @param array<string, mixed> $input
	 */
	public function exec_events( $input ): array {
		[ $start, $end ] = $this->resolve_range( $input );
		$limit = $this->resolve_limit( $input, 10 );
		$name  = isset( $input['name'] ) ? sanitize_key( (string) $input['name'] ) : '';

		return '' !== $name
			? Events::events_by_value( $start, $end, $name, $limit )
			: Events::top_events( $start, $end, $limit );
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|\WP_Error
	 */
	public function exec_post( $input ) {
		[ $start, $end ] = $this->resolve_range( $input );

		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		if ( ! $this->can_view_post( $post_id ) ) {
			return new \WP_Error( 'vireo_ability_not_found', __( 'Post not found.', 'vireo-analytics' ) );
		}

		$detail = Post_Detail::instance();
		return [
			'post_id'   => $post_id,
			'title'     => (string) get_the_title( $post_id ),
			'url'       => (string) get_permalink( $post_id ),
			'lifetime'  => $detail->get_lifetime_stats( $post_id ),
			'period'    => $detail->get_period_totals( $post_id, $start, $end ),
			'daily'     => $detail->get_daily_series( $post_id, $start, $end ),
			'referrers' => $detail->get_top_referrers( $post_id, $start, $end, 10 ),
		];
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public function exec_goals( $input ) {
		[ $start, $end ] = $this->resolve_range( $input );

		$cls = '\\VireoAnalytics\\Pro\\Goals';
		if ( ! class_exists( $cls ) ) {
			return new \WP_Error( 'vireo_ability_unavailable', __( 'Goals feature unavailable.', 'vireo-analytics' ) );
		}

		$goals       = $cls::all_goals();
		$range_stats = $cls::completions_in_range( $start, $end );

		$out = [];
		foreach ( $goals as $g ) {
			$gid   = (int) $g->id;
			$stats = $range_stats[ $gid ] ?? [ 'completions' => 0, 'unique_completions' => 0, 'total_value' => 0.0 ];
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
		return $out;
	}


	/**
	 * @param array<string, mixed> $input
	 */
	public function exec_hourly( $input ): array {
		$today = vireo_site_date();
		$end   = $this->clamp_date( isset( $input['end'] ) ? (string) $input['end'] : '', $today );
		if ( '' === $end ) {
			$end = $today;
		}
		$start = $this->clamp_date( isset( $input['start'] ) ? (string) $input['start'] : '', $today );
		// The hourly rollup is meant for a day or two; a month of hours is
		// 720 rows of noise. Cut long ranges to the last two days.
		$floor = ( new \DateTimeImmutable( $end ) )->modify( '-1 day' )->format( 'Y-m-d' );
		if ( '' === $start || strcmp( $start, $floor ) < 0 ) {
			$start = $floor;
		}
		if ( strcmp( $start, $end ) > 0 ) {
			$start = $end;
		}

		return Dashboard::instance()->get_hour_chart_data( $start, $end );
	}

	/**
	 * The Overview's "what changed" card: hero numbers against the prior
	 * period, the drivers behind the movement, and the health checks.
	 *
	 * @param array<string, mixed> $input
	 */
	public function exec_changes( $input ): array {
		[ $start, $end ] = $this->resolve_range( $input );
		$limit           = $this->resolve_limit( $input, 3 );

		$dashboard = Dashboard::instance();
		$hero      = $dashboard->get_hero_stats( $start, $end, true );
		$drivers   = ( new Drivers( $dashboard ) )->get( $start, $end, true, $limit );
		$prior     = Dashboard::compare_range( $start, $end );

		$movers = static function ( array $rows ): array {
			$out = [];
			foreach ( $rows as $r ) {
				$out[] = [
					'label' => (string) ( $r['label'] ?? '' ),
					'url'   => (string) ( $r['url'] ?? '' ),
					'delta' => (int) ( $r['delta'] ?? 0 ),
				];
			}
			return $out;
		};

		$health = [];
		foreach ( (array) ( $drivers['health'] ?? [] ) as $h ) {
			$health[] = [
				'level' => (string) ( $h['level'] ?? '' ),
				'text'  => wp_strip_all_tags( (string) ( $h['text'] ?? '' ) ),
			];
		}

		$prior_pv = (int) ( $hero['prior']['pageviews'] ?? 0 );
		$pv       = (int) $hero['pageviews'];

		return [
			'start'     => $start,
			'end'       => $end,
			'prior'     => [
				'start'     => $prior[0],
				'end'       => $prior[1],
				'pageviews' => $prior_pv,
				'visitors'  => (int) ( $hero['prior']['visitors'] ?? 0 ),
			],
			'pageviews' => $pv,
			'visitors'  => (int) $hero['visitors'],
			'change_pct' => $prior_pv > 0 ? round( ( $pv - $prior_pv ) / $prior_pv * 100, 1 ) : null,
			'summary'   => wp_strip_all_tags( Insights::line( $hero, $drivers, true ) ),
			'headline'  => wp_strip_all_tags( (string) ( $drivers['headline'] ?? '' ) ),
			'detail'    => wp_strip_all_tags( (string) ( $drivers['detail'] ?? '' ) ),
			'mix'       => wp_strip_all_tags( (string) ( $drivers['mix'] ?? '' ) ),
			'pages'     => [
				'gained' => $movers( (array) ( $drivers['pages']['gained'] ?? [] ) ),
				'lost'   => $movers( (array) ( $drivers['pages']['lost'] ?? [] ) ),
			],
			'sources'   => [
				'gained' => $movers( (array) ( $drivers['sources']['gained'] ?? [] ) ),
				'lost'   => $movers( (array) ( $drivers['sources']['lost'] ?? [] ) ),
			],
			'health'    => $health,
		];
	}

	/**
	 * @param array<string, mixed> $input
	 */
	public function exec_highlights( $input ): array {
		$limit = $this->resolve_limit( $input, 3 );

		if ( empty( $input['start'] ) && empty( $input['end'] ) ) {
			// The week the email reports: the last seven finished days.
			$today = vireo_bucket_date();
			$start = gmdate( 'Y-m-d', (int) strtotime( $today . ' -7 days UTC' ) );
			$end   = gmdate( 'Y-m-d', (int) strtotime( $today . ' -1 day UTC' ) );
		} else {
			[ $start, $end ] = $this->resolve_range( $input );
		}

		$out = [];
		foreach ( Highlights::for_week( $start, $end, $limit ) as $h ) {
			$out[] = [
				'kind' => (string) ( $h['kind'] ?? '' ),
				'text' => wp_strip_all_tags( (string) ( $h['text'] ?? '' ) ),
			];
		}
		return [
			'start'      => $start,
			'end'        => $end,
			'highlights' => $out,
		];
	}

	/**
	 * @param array<string, mixed> $input
	 */
	public function exec_traffic_sources( $input ): array {
		[ $start, $end ] = $this->resolve_range( $input );

		$dashboard = Dashboard::instance();
		$direct    = $dashboard->get_direct_totals( $start, $end );
		$buckets   = $dashboard->get_referrer_bucket_totals( $start, $end );
		$labels    = [
			'direct'   => __( 'Direct', 'vireo-analytics' ),
			'search'   => __( 'Search', 'vireo-analytics' ),
			'ai'       => __( 'AI assistants', 'vireo-analytics' ),
			'social'   => __( 'Social', 'vireo-analytics' ),
			'referral' => __( 'Referral', 'vireo-analytics' ),
		];

		$rows  = [ 'direct' => $direct ] + $buckets;
		$total = 0;
		foreach ( $rows as $r ) {
			$total += (int) ( $r['pageviews'] ?? 0 );
		}

		$out = [];
		foreach ( $labels as $key => $label ) {
			$pv    = (int) ( $rows[ $key ]['pageviews'] ?? 0 );
			$out[] = [
				'source'    => $key,
				'label'     => $label,
				'pageviews' => $pv,
				'visitors'  => (int) ( $rows[ $key ]['visitors'] ?? 0 ),
				'share_pct' => $total > 0 ? round( $pv / $total * 100, 1 ) : 0.0,
			];
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|\WP_Error
	 */
	public function exec_referrer_detail( $input ) {
		[ $start, $end ] = $this->resolve_range( $input );
		$limit           = $this->resolve_limit( $input, 10 );

		$host = vireo_canonical_host( isset( $input['host'] ) ? (string) $input['host'] : '' );
		if ( '' === $host ) {
			return new \WP_Error( 'vireo_ability_bad_host', __( 'host must be a hostname or URL.', 'vireo-analytics' ) );
		}

		$detail = Referrer_Detail::instance();
		$pages  = [];
		foreach ( $detail->get_top_pages_from_referrer( $host, $start, $end, $limit ) as $p ) {
			$post_id = (int) ( $p['post_id'] ?? 0 );
			$public  = Dashboard::public_post( $post_id );
			$pages[] = [
				'post_id'   => $post_id,
				'path'      => (string) ( $p['path'] ?? '' ),
				'title'     => $public['title'],
				'url'       => $public['url'],
				'pageviews' => (int) ( $p['pageviews'] ?? 0 ),
			];
		}
		return [
			'host'   => $host,
			'label'  => vireo_referrer_label( $host ),
			'source' => vireo_source_type( $host ),
			'period' => $detail->get_period_totals( $host, $start, $end ),
			'daily'  => $detail->get_daily_series( $host, $start, $end ),
			'pages'  => $pages,
		];
	}

	/**
	 * @param array<string, mixed> $input
	 */
	public function exec_ai_referrals( $input ): array {
		[ $start, $end ] = $this->resolve_range( $input );

		// Same window as the dashboard card: everything starts on the first day
		// crawlers were counted, so the per-assistant rows sum to the headline.
		$exchange  = AI_Sources::exchange( $start, $end );
		$referrals = AI_Sources::referrals( $exchange['start'], $exchange['end'] );

		return [
			'start'                       => $exchange['start'],
			'end'                         => $exchange['end'],
			'since'                       => $exchange['since'],
			'referred_visitors'           => $exchange['referred'],
			'crawler_requests'            => $exchange['crawled'],
			'crawler_requests_per_reader' => $exchange['rate'],
			'by_source'                   => array_values( $referrals['by_source'] ),
		];
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|\WP_Error
	 */
	public function exec_search_console( $input ) {
		[ $start, $end ] = $this->resolve_range( $input );
		$limit           = $this->resolve_limit( $input, 10 );

		if ( ! Search_Console::configured() ) {
			return [
				'connected' => false,
				'message'   => __( 'Google Search Console is not connected. Connect it under Vireo Analytics, Settings, Search Console & API.', 'vireo-analytics' ),
				'summary'   => null,
				'queries'   => [],
			];
		}

		$path = isset( $input['path'] ) ? Search_Console::page_path( (string) $input['path'] ) : '';
		if ( ! empty( $input['post_id'] ) ) {
			$post_id = absint( $input['post_id'] );
			if ( ! $this->can_view_post( $post_id ) ) {
				return new \WP_Error( 'vireo_ability_not_found', __( 'Post not found.', 'vireo-analytics' ) );
			}
			$path = Search_Console::page_path( (string) get_permalink( $post_id ) );
		}

		if ( '' !== $path ) {
			return [
				'connected' => true,
				'path'      => $path,
				'summary'   => null,
				'queries'   => Search_Console::page_queries( $path, $start, $end, $limit ),
			];
		}

		$summary = Search_Console::summary( $start, $end );
		if ( null !== $summary ) {
			$summary['ctr'] = $summary['impressions'] > 0 ? round( $summary['clicks'] / $summary['impressions'] * 100, 1 ) : 0.0;
		}

		$queries = [];
		foreach ( Search_Console::top_queries( $start, $end, $limit ) as $q ) {
			unset( $q['query_hash'] );
			$queries[] = $q;
		}

		return [
			'connected' => true,
			'summary'   => $summary,
			'queries'   => $queries,
		];
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|\WP_Error
	 */
	public function exec_event_detail( $input ) {
		[ $start, $end ] = $this->resolve_range( $input );
		$limit           = $this->resolve_limit( $input, 20 );

		$name = isset( $input['name'] ) ? sanitize_key( (string) $input['name'] ) : '';
		if ( '' === $name ) {
			return new \WP_Error( 'vireo_ability_bad_name', __( 'name is required.', 'vireo-analytics' ) );
		}

		$detail = Event_Detail::instance();
		return [
			'name'         => $name,
			'period'       => $detail->get_totals( $name, $start, $end ),
			'daily'        => $detail->get_daily_series( $name, $start, $end ),
			'value_count'  => $detail->count_values( $name, $start, $end ),
			'values'       => $detail->get_top_values( $name, $start, $end, $limit ),
		];
	}

	/**
	 * @param array<string, mixed> $input
	 */
	public function exec_404s( $input ): array {
		[ $start, $end ] = $this->resolve_range( $input );
		$limit           = $this->resolve_limit( $input, 20 );

		$data = Dashboard::instance()->get_404_rows( $start, $end, $limit, false );

		$rows = [];
		foreach ( (array) $data['rows'] as $r ) {
			$rows[] = [
				'path'         => (string) $r->path,
				'hits'         => (int) $r->hits,
				'top_referrer' => (string) ( $r->top_referrer ?? '' ),
			];
		}
		$broken = [];
		foreach ( (array) ( $data['broken'] ?? [] ) as $r ) {
			$broken[] = [
				'path'          => (string) $r->path,
				'hits'          => (int) $r->hits,
				'linked_from'   => (string) ( $r->linked_from ?? '' ),
				'linking_pages' => (int) ( $r->linking_pages ?? 0 ),
			];
		}
		$probes = [];
		foreach ( (array) ( $data['probes'] ?? [] ) as $r ) {
			$probes[] = [
				'path'         => (string) $r->path,
				'hits'         => (int) $r->hits,
				'top_referrer' => (string) ( $r->top_referrer ?? '' ),
			];
		}

		return [
			'rows'         => $rows,
			'broken_links' => $broken,
			'probes'       => $probes,
		];
	}

	/**
	 * @param array<string, mixed> $input
	 */
	public function exec_bot_stats( $input ): array {
		[ $start, $end ] = $this->resolve_range( $input );

		$bots    = Bot_Stats::summary( $start, $end );
		$counted = (int) Dashboard::instance()->get_hero_stats( $start, $end, false )['pageviews'];
		$ai      = 0;
		foreach ( $bots['by_category'] as $row ) {
			if ( 'ai' === $row['category'] ) {
				$ai = (int) $row['hits'];
			}
		}

		return [
			'filtered_requests'         => (int) $bots['total'],
			'counted_pageviews'         => $counted,
			'share_of_all_requests_pct' => Bot_Stats::filtered_share( (int) $bots['total'], $counted ),
			'ai_crawler_requests'       => $ai,
			'by_category'               => array_values( $bots['by_category'] ),
		];
	}

	/**
	 * @param array<string, mixed> $input
	 */
	public function exec_vitals( $input ): array {
		[ $start, $end ] = $this->resolve_range( $input );

		$out = [];
		foreach ( Vitals::summary( $start, $end ) as $metric => $row ) {
			$out[] = [
				'metric'  => (string) $metric,
				'label'   => Vitals::label( (string) $metric ),
				'p75'     => (int) $row['p75'],
				'display' => (string) $row['display'],
				'rating'  => (string) $row['rating'],
				'samples' => (int) $row['samples'],
			];
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $input
	 */
	public function exec_annotations( $input ): array {
		[ $start, $end ] = $this->resolve_range( $input );
		return Annotations::for_range( $start, $end );
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|\WP_Error
	 */
	public function exec_year_review( $input ) {
		$today   = vireo_site_date();
		$current = (int) substr( $today, 0, 4 );
		$year    = isset( $input['year'] ) ? (int) $input['year'] : $current;
		if ( $year < 2000 || $year > $current ) {
			return new \WP_Error( 'vireo_ability_bad_year', __( 'year must be a calendar year with data, no later than the current year.', 'vireo-analytics' ) );
		}
		return Year_Review::build( $year, $today );
	}

	// ---------------------------------------------------------------------
	// Output guard
	// ---------------------------------------------------------------------

	/**
	 * The registered execute callback: the query, then the flattening pass.
	 * REST goes through run() too, so both surfaces hand an agent the same
	 * cleaned data.
	 */
	private function guarded( string $method ): \Closure {
		return function ( $input ) use ( $method ) {
			return $this->run( $method, is_array( $input ) ? $input : [] );
		};
	}

	/**
	 * Run one exec_* method and flatten every string in what it returns.
	 *
	 * @param array<string, mixed> $input
	 * @return mixed
	 */
	public function run( string $method, array $input ) {
		if ( 0 !== strpos( $method, 'exec_' ) || ! method_exists( $this, $method ) ) {
			return new \WP_Error( 'vireo_ability_unknown', __( 'Unknown ability.', 'vireo-analytics' ) );
		}
		$result = $this->$method( $input );
		return $result instanceof \WP_Error ? self::flatten( $result ) : self::as_object( self::flatten( $result ) );
	}

	/**
	 * A list of rows becomes `{ "rows": [...] }`.
	 *
	 * MCP's tool result puts the ability's return value in `structuredContent`,
	 * which the protocol says is an object, and a client built on the official
	 * SDK rejects the whole response when it is a list. Some sites run an MCP
	 * adapter old enough to have no filter for fixing that at the transport
	 * (WooCommerce bundles 0.3.0 and loads it first), so the abilities return
	 * objects themselves. The REST routes that predate this unwrap the rows
	 * again, so /stats/daily and friends keep their original shape.
	 *
	 * @param mixed $result
	 * @return mixed
	 */
	public static function as_object( $result ) {
		if ( ! is_array( $result ) ) {
			return $result;
		}
		if ( [] === $result || array_keys( $result ) === range( 0, count( $result ) - 1 ) ) {
			return [ 'rows' => $result ];
		}
		return $result;
	}

	/**
	 * Walk a result and flatten every string in it, keys included.
	 *
	 * @param mixed $data
	 * @return mixed
	 */
	public static function flatten( $data ) {
		if ( is_string( $data ) ) {
			return self::sanitize_for_agent( $data );
		}
		// An error's message and data reach the agent as text too, and can
		// carry what a value can (a path that was not found, say). The code
		// and the status in the data stay as they are.
		if ( $data instanceof \WP_Error ) {
			return new \WP_Error(
				(string) $data->get_error_code(),
				(string) self::flatten( (string) $data->get_error_message() ),
				self::flatten( $data->get_error_data() )
			);
		}
		if ( is_array( $data ) ) {
			$out = [];
			foreach ( $data as $k => $v ) {
				$out[ is_string( $k ) ? self::sanitize_for_agent( $k, 100 ) : $k ] = self::flatten( $v );
			}
			return $out;
		}
		if ( is_object( $data ) ) {
			return self::flatten( get_object_vars( $data ) );
		}
		return $data;
	}

	/**
	 * Flatten a visitor-supplied string before it reaches an agent. Mirrors
	 * Pro's Abilities_Support::sanitize_for_agent(), so both plugins treat
	 * the same data the same way.
	 *
	 * Paths, search terms, referrer hosts, event values and 404 paths can be
	 * set by anyone who sends one request. On the dashboard that is a cosmetic
	 * problem and escaping handles it. Over MCP these strings land directly in
	 * an LLM's context as part of the site's data, which makes them an
	 * injection channel. Newlines and control characters are the useful part
	 * of such a payload, since they let text pose as a new instruction rather
	 * than a value, so they go, along with the brackets and braces used to
	 * fake structure. What remains is what a real path, query or hostname is
	 * made of. Sentences the plugin itself composes (summaries, highlights)
	 * get a longer cap; they carry visitor strings too.
	 *
	 * The same in Unicode: every control (C0, DEL and the C1 range), every
	 * format character (zero-width spaces and joiners, bidirectional overrides
	 * and isolates, the soft hyphen, the byte-order mark), and the line and
	 * paragraph separators become a space; the fullwidth, small-form, CJK and
	 * ornamental look-alikes of the brackets, braces, angle brackets, backtick,
	 * pipe and backslash are removed with the ASCII ones.
	 */
	public static function sanitize_for_agent( string $value, int $max = 300 ): string {
		if ( '' === $value ) {
			return '';
		}

		$value = (string) preg_replace( '/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', ' ', $value );
		$value = (string) preg_replace( self::STRUCTURE_CHARS, '', $value );
		$value = trim( (string) preg_replace( '/\s+/u', ' ', $value ) );

		return mb_substr( $value, 0, $max );
	}

	/**
	 * Characters that fake structure to a model: ASCII brackets, braces, angle
	 * brackets, backtick, pipe and backslash, then their fullwidth forms
	 * (U+FF1C-FF5D), the single angle quotes, the angle, CJK corner, lenticular
	 * and tortoise-shell brackets (U+2329-232A, U+3008-3011, U+3014-301B), the
	 * small forms (U+FE59-FE5E, U+FE64-FE65) and the ornamental brackets
	 * (U+2768-2775). The same list as Pro's Abilities_Support.
	 */
	private const STRUCTURE_CHARS = '/[<>{}\[\]`|\\\\\x{FF1C}\x{FF1E}\x{FF3B}\x{FF3C}\x{FF3D}\x{FF40}\x{FF5B}\x{FF5C}\x{FF5D}\x{2039}\x{203A}\x{2329}\x{232A}\x{3008}-\x{3011}\x{3014}-\x{301B}\x{FE59}-\x{FE5E}\x{FE64}\x{FE65}\x{2768}-\x{2775}]+/u';

	/**
	 * A post the caller could see on the site, or open in the editor. The
	 * same rule as the post detail screen: holding the dashboard capability
	 * does not make a draft's title theirs to read.
	 */
	private function can_view_post( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}
		$post = get_post( $post_id );
		return $post instanceof \WP_Post && Post_Detail::can_view( $post, $post_id );
	}

	// ---------------------------------------------------------------------
	// Input helpers
	// ---------------------------------------------------------------------

	/**
	 * Wrap a set of property definitions into an object input schema.
	 *
	 * The top-level `default` of an empty object is what lets clients call an
	 * ability with no input at all: the Abilities API runs `normalize_input()`
	 * which returns this default when input is null, and an empty object then
	 * validates cleanly against a schema whose properties are all optional. Without
	 * it, a no-argument call would fail input validation before reaching the
	 * execute callback — defeating the "dates optional" defaults.
	 *
	 * `additionalProperties` is false so an argument nobody declared is a
	 * validation error rather than silently dropped. A `site` key on an
	 * install without Pro's connected-site support used to be ignored, and
	 * the caller got this site's numbers under another site's id. Pro adds
	 * `site` to the properties through core's wp_register_ability_args
	 * filter, so licensed installs keep accepting it.
	 *
	 * @param array<string, array<string, mixed>> $properties
	 * @param array<int, string>                  $required
	 * @return array<string, mixed>
	 */
	private function input_schema( array $properties, array $required = [] ): array {
		$schema = [
			'type'                 => 'object',
			'properties'           => $properties,
			'additionalProperties' => false,
			'default'              => [],
		];
		if ( ! empty( $required ) ) {
			$schema['required'] = $required;
		}
		return $schema;
	}

	/**
	 * Meta for an informational ability: the annotations, REST exposure, and
	 * the flag the MCP Adapter's default server discovers abilities by.
	 *
	 * @return array<string, mixed>
	 */
	private function read_meta(): array {
		return [
			'annotations'  => self::READ_ANNOTATIONS,
			'show_in_rest' => true,
			'mcp'          => [ 'public' => true ],
		];
	}

	/**
	 * Shared start/end schema fragment. Both optional — callers that omit them
	 * get the last 30 days.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function range_schema_props(): array {
		return [
			'start' => [
				'type'        => 'string',
				'format'      => 'date',
				'description' => __( 'Start date (YYYY-MM-DD). Defaults to 30 days ago.', 'vireo-analytics' ),
			],
			'end'   => [
				'type'        => 'string',
				'format'      => 'date',
				'description' => __( 'End date (YYYY-MM-DD). Defaults to today.', 'vireo-analytics' ),
			],
		];
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function limit_schema_prop( int $default ): array {
		return [
			'limit' => [
				'type'        => 'integer',
				'default'     => $default,
				'minimum'     => 1,
				'maximum'     => 100,
				'description' => __( 'Maximum number of rows to return (1-100).', 'vireo-analytics' ),
			],
		];
	}

	/**
	 * Resolve a validated, clamped [start, end] pair from ability input. Missing
	 * dates default to the last 30 days in the site timezone. Out-of-range dates
	 * are clamped to the 2-year lookback window and to today, mirroring the REST
	 * API so the two surfaces never disagree.
	 *
	 * @param array<string, mixed> $input
	 * @return array{0:string,1:string}
	 */
	private function resolve_range( array $input ): array {
		$today = vireo_site_date();

		$end   = $this->clamp_date( isset( $input['end'] ) ? (string) $input['end'] : '', $today );
		$start = $this->clamp_date( isset( $input['start'] ) ? (string) $input['start'] : '', $today );

		if ( '' === $end ) {
			$end = $today;
		}
		if ( '' === $start ) {
			$start = ( new \DateTimeImmutable( $end ) )->modify( '-29 days' )->format( 'Y-m-d' );
			$start = $this->clamp_date( $start, $today );
		}

		// Guarantee start <= end.
		if ( strcmp( $start, $end ) > 0 ) {
			$start = $end;
		}

		return [ $start, $end ];
	}

	/**
	 * Validate a YYYY-MM-DD string and clamp it to [today - MAX_RANGE_DAYS, today].
	 * Returns '' for anything that isn't a valid date so callers can apply a default.
	 */
	private function clamp_date( string $value, string $today ): string {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return '';
		}
		$tz     = new \DateTimeZone( 'UTC' );
		$parsed = \DateTimeImmutable::createFromFormat( 'Y-m-d', $value, $tz );
		if ( ! $parsed instanceof \DateTimeImmutable ) {
			return '';
		}
		$todayObj = \DateTimeImmutable::createFromFormat( 'Y-m-d', $today, $tz );
		if ( ! $todayObj instanceof \DateTimeImmutable ) {
			return $value;
		}
		$min = $todayObj->modify( '-' . self::MAX_RANGE_DAYS . ' days' );
		if ( $parsed < $min ) {
			return $min->format( 'Y-m-d' );
		}
		if ( $parsed > $todayObj ) {
			return $todayObj->format( 'Y-m-d' );
		}
		return $value;
	}

	/**
	 * @param array<string, mixed> $input
	 */
	private function resolve_limit( array $input, int $default ): int {
		$n = isset( $input['limit'] ) ? (int) $input['limit'] : $default;
		if ( $n < 1 ) {
			return 1;
		}
		if ( $n > 100 ) {
			return 100;
		}
		return $n;
	}
}
