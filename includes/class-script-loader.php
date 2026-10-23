<?php
/**
 * Frontend tracking script injector.
 *
 * Enqueues a tiny external tracker on the front end. It POSTs to admin-ajax
 * (action=vireo_collect) once per page load via navigator.sendBeacon() with a
 * fetch keepalive fallback. The dynamic per-request config (URL, current
 * path, post id) and the auto-event toggle flags are injected via
 * wp_add_inline_script() before the external file runs.
 */

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

final class Script_Loader {

	private const SCRIPT_HANDLE = 'va-tracker';

	private static ?self $instance = null;

	/** @var bool Whether the noscript pixel is already on the page, from either route. */
	private bool $pixel_printed = false;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Tokens an optimizer can match against to leave the tracker alone. Covers
	 * the script src, the registered handles, and the inline config var.
	 */
	private const OPTIMIZER_EXCLUSIONS = [
		'vireo-analytics',
		'va-tracker',
		'va-collect',
		'va-live',
		'va-outbound',
		'window.va',
	];

	private function __construct() {
		// Delay-JS optimizers hold every script until the first user
		// interaction. A delayed tracker never fires for visitors who read and
		// leave, so those pageviews vanish. Opt out wherever we can.
		$this->exclude_from_optimizers();

		// Frontend only — never inject on admin/login.
		if ( is_admin() ) {
			return;
		}
		// Late priority so the tracker enqueue happens after themes/plugins
		// have wired their own assets. WP still emits everything together at
		// wp_print_footer_scripts.
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_tracker' ], 99 );

		// No-JS fallback. Renders only for visitors whose browser never runs
		// the beacon, so it cannot double-count: noscript content is inert
		// whenever scripts are enabled. Safe under full-page caching, because
		// the pixel URL carries the same path the cached HTML was built for.
		add_action( 'wp_footer', [ $this, 'render_noscript_pixel' ], 99 );

		// Some themes, page builders and landing-page templates never call
		// wp_footer(), so footer scripts never print and nothing is counted.
		// They can call do_action( 'vireo_print_tracking_script' ) instead.
		add_action( 'vireo_print_tracking_script', [ $this, 'print_tracker' ] );

		// Crawlers are counted here rather than at the beacon. Almost none of
		// them run JavaScript, so a filter that only sees beacon traffic sees
		// the few agents that render pages and misses every one that just
		// fetches HTML. That skewed the categories against each other rather
		// than merely lowering them: on one site it reported AI crawlers at 22
		// times the search crawlers, where the server log for the same days had
		// them at about 1.3 times, because the agents that render JavaScript
		// happen to be the AI ones. Counting the page request fixes the shape.
		add_action( 'template_redirect', [ $this, 'record_bot_request' ], 1 );
	}

	/**
	 * Record a crawler that asked for a page.
	 *
	 * Runs before anything renders, and writes one line per page request, so
	 * the number means pages taken, which is what the dashboard says it is.
	 *
	 * Requests answered by a full-page cache never reach PHP and cannot be
	 * counted here. Nothing running inside WordPress can see those, which is
	 * why the dashboard states what it measured instead of implying a total.
	 */
	public function record_bot_request(): void {
		if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		// The AI visibility scan fetches pages as GPTBot, ClaudeBot and
		// PerplexityBot. Those requests are ours, not the crawlers'.
		if ( Bot_Filter::is_scan_request() ) {
			return;
		}

		// Crawlers fetch. Anything else is a form post or an API call, and
		// counting those would inflate the figure with traffic nobody means.
		if ( 'GET' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) {
			return;
		}

		$ua = isset( $_SERVER['HTTP_USER_AGENT'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) )
			: '';

		$category = Bot_Filter::classify( $ua, $_SERVER );

		if ( '' === $category ) {
			return;
		}

		Collector::append_bot(
			[
				'ts'       => time(),
				'category' => $category,
			]
		);
	}

	/**
	 * Where the beacon posts.
	 *
	 * endpoint.php boots WordPress with SHORTINIT, skipping every plugin and
	 * the theme, which on a thirty-plugin site is the difference between a
	 * ~380ms request and a ~5ms one. Hosts that refuse to execute PHP inside
	 * wp-content fail the install check, and those sites stay on admin-ajax.
	 */
	public static function collect_url(): string {
		if ( class_exists( Endpoint_Installer::class ) && Endpoint_Installer::is_usable() ) {
			return Endpoint_Installer::url();
		}
		return admin_url( 'admin-ajax.php' );
	}

	/**
	 * Print the tracker where the action is called, for templates without
	 * wp_footer().
	 *
	 * Printed scripts are marked done, so a wp_footer() later on the same page
	 * skips them, and one that already ran leaves nothing to print here.
	 * Without wp_head() the enqueue never ran, so it runs now, behind the same
	 * gates, including the `vireo_load_tracking_script` filter.
	 */
	public function print_tracker(): void {
		if ( ! did_action( 'wp_enqueue_scripts' ) ) {
			$this->enqueue_tracker();
		}

		$handles = array_filter(
			[ self::SCRIPT_HANDLE, 'va-vitals' ],
			static fn( string $handle ): bool => wp_script_is( $handle, 'enqueued' )
		);
		if ( ! $handles ) {
			return;
		}

		wp_print_scripts( array_values( $handles ) );
		$this->render_noscript_pixel();
	}

	/**
	 * Emit a 1x1 pixel inside <noscript> so a pageview still lands when
	 * JavaScript is unavailable, blocked, or stripped in transit.
	 */
	public function render_noscript_pixel(): void {
		if ( $this->pixel_printed || ! $this->should_track() ) {
			return;
		}

		$settings = vireo_get_settings();
		if ( ! empty( $settings['disable_noscript_pixel'] ) ) {
			return;
		}

		$path = isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) )
			: '';
		$path = (string) wp_parse_url( $path, PHP_URL_PATH );
		if ( '' === $path ) {
			$path = '/';
		}

		if ( function_exists( 'vireo_path_matches_rules' ) ) {
			$excluded_paths = vireo_parse_lines( (string) ( $settings['excluded_paths'] ?? '' ) );
			if ( $excluded_paths && vireo_path_matches_rules( $path, $excluded_paths ) ) {
				return;
			}
		}

		$src = add_query_arg(
			[
				'action'  => 'vireo_collect',
				'kind'    => 'pageview',
				'nojs'    => 1,
				'path'    => $path,
				'post_id' => is_singular() ? (int) get_queried_object_id() : 0,
			],
			self::collect_url()
		);

		printf(
			'<noscript><img src="%s" alt="" width="1" height="1" style="position:absolute;left:-9999px;top:-9999px" /></noscript>',
			esc_url( $src )
		);
		$this->pixel_printed = true;
	}

	private function exclude_from_optimizers(): void {
		$merge = static function ( $excluded ) {
			return array_merge( (array) $excluded, self::OPTIMIZER_EXCLUSIONS );
		};

		// Perfmatters.
		add_filter( 'perfmatters_delay_js_exclusions', $merge );
		add_filter( 'perfmatters_defer_js_exclusions', $merge );

		// WP Rocket.
		add_filter( 'rocket_delay_js_exclusions', $merge );
		add_filter( 'rocket_exclude_defer_js', $merge );
		add_filter( 'rocket_exclude_js', [ self::class, 'rocket_minify_exclusions' ] );

		// LiteSpeed Cache.
		add_filter( 'litespeed_optm_js_defer_exc', $merge );

		// FlyingPress.
		add_filter( 'flying_press_delay_js:exclude_keywords', $merge );
	}

	/**
	 * Keep WP Rocket from minifying the front-end scripts.
	 *
	 * WP Rocket names a minified copy after the file's path alone and serves
	 * it for as long as it exists, so the ?ver= that changes with every
	 * release never reaches it, and it only empties that folder on a theme
	 * update or a manual clear. A site that minified the tracker once kept
	 * that copy through every later Vireo update, and whatever the tracker
	 * gained since (the WP RSS Aggregator and Spotlight listeners among it)
	 * never ran there. The files are a few KB, so serving them as shipped
	 * costs nothing.
	 *
	 * Entries are regex fragments matched against the script's URL path.
	 *
	 * @param mixed $excluded
	 * @return array<int, string>
	 */
	public static function rocket_minify_exclusions( $excluded ): array {
		$excluded = (array) $excluded;
		$path     = (string) wp_parse_url( VIREO_URL . 'assets/js/', PHP_URL_PATH );
		if ( '' !== $path ) {
			// phpcs:ignore WordPress.PHP.PregQuoteDelimiter.Missing -- WP Rocket escapes "#" itself before wrapping the list in #...#.
			$excluded[] = preg_quote( $path ) . '(.*)\.js';
		}
		return $excluded;
	}

	/**
	 * Enqueue the external tracker + inject the per-request dynamic config.
	 * Filter-, bot-, exclusion-gated identically to the previous inline
	 * implementation; if any gate trips, we don't enqueue at all so the
	 * tracker file is never even fetched.
	 */
	public function enqueue_tracker(): void {
		if ( ! $this->should_track() ) {
			return;
		}

		$settings = vireo_get_settings();

		// Deliberately NOT gated on the bot filter here.
		//
		// Whether this particular request is a bot is a fact about the request,
		// but the HTML we print gets cached and replayed to everyone else. On a
		// full-page-cached site, if a crawler is the one that fills the cache,
		// omitting the tracker for it omits the tracker for every human served
		// that cached page afterwards, and the site silently stops counting.
		//
		// So the page always carries the tracker, and the per-visitor decision
		// happens where it can't be cached: the beacon endpoint runs the bot
		// filter on the real request and drops it there. The only cost is that
		// bots fetch a static JS file they never execute.

		$path = isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) )
			: '';
		$path = (string) wp_parse_url( $path, PHP_URL_PATH );
		if ( '' === $path ) {
			$path = '/';
		}
		if ( function_exists( 'vireo_path_matches_rules' ) ) {
			$excluded_paths = vireo_parse_lines( (string) ( $settings['excluded_paths'] ?? '' ) );
			if ( $excluded_paths && vireo_path_matches_rules( $path, $excluded_paths ) ) {
				return;
			}
		}

		$post_id = is_singular() ? (int) get_queried_object_id() : 0;

		$config = [
			'url'    => esc_url_raw( self::collect_url() ),
			'path'   => $path,
			'postId' => $post_id,
		];

		// Empty unless the site has opted into waiting for consent. The value
		// travels into cached HTML, which is fine: it is a site-wide policy,
		// not a per-visitor decision. The per-visitor part happens in the
		// browser where caching cannot reach it.
		$consent_category = class_exists( Consent::class ) ? Consent::category_for_tracker() : '';
		if ( '' !== $consent_category ) {
			$config['consent'] = $consent_category;
		}

		$auto_events = $this->resolve_auto_events( $settings );

		// JSON_HEX_* flags so the encoded blob can't break out of the <script>
		// tag even if a config value ever contains quotes or ampersands.
		$config_json = wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		if ( false === $config_json ) {
			return;
		}

		// Register + enqueue the external tracker. The queue stub on
		// `window.vireo` plus the dynamic config + auto-event flags are
		// injected with `before` position so they're guaranteed to run
		// before tracker.js executes.
		$ver = (int) @filemtime( VIREO_DIR . 'assets/js/tracker.js' ) ?: VIREO_VERSION;
		wp_register_script(
			self::SCRIPT_HANDLE,
			VIREO_URL . 'assets/js/tracker.js',
			[],
			$ver,
			true
		);

		// Queue stub — lets earlier-in-page la('event', …) calls queue until
		// tracker.js boots and drains them. Mirrors the previous inline shim.
		$stub = 'window.vireo=window.vireo||function(){(window.vireo.q=window.vireo.q||[]).push(arguments)};';

		$inline = $stub . 'window.vireoTracker=' . $config_json . ';';
		if ( null !== $auto_events ) {
			$flags_json = wp_json_encode( $auto_events, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
			if ( false !== $flags_json ) {
				$inline .= 'window.vireoAutoEvents=' . $flags_json . ';';
			}
		}

		wp_add_inline_script( self::SCRIPT_HANDLE, $inline, 'before' );
		wp_enqueue_script( self::SCRIPT_HANDLE );

		$this->enqueue_vitals( $config );
	}

	/**
	 * Core Web Vitals collection, as a separate file from the tracker.
	 *
	 * Kept separate because it is the one part a site owner might reasonably
	 * not want: it costs a second beacon at the end of a visit, and a site that
	 * only cares about traffic counts has no use for it. Off is a filter away
	 * and the file is then never requested.
	 *
	 * @param array<string,mixed> $config
	 */
	private function enqueue_vitals( array $config ): void {
		if ( ! (bool) apply_filters( 'vireo_collect_web_vitals', true ) ) {
			return;
		}

		$handle = 'va-vitals';
		$ver    = (int) @filemtime( VIREO_DIR . 'assets/js/vitals.js' ) ?: VIREO_VERSION;

		wp_register_script( $handle, VIREO_URL . 'assets/js/vitals.js', [], $ver, true );

		$vitals_config = wp_json_encode(
			array_filter(
				[
					'url'     => (string) ( $config['url'] ?? '' ),
					'path'    => (string) ( $config['path'] ?? '/' ),
					'consent' => (string) ( $config['consent'] ?? '' ),
				]
			),
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		);

		if ( false === $vitals_config ) {
			return;
		}

		wp_add_inline_script( $handle, 'window.vireoVitals=' . $vitals_config . ';', 'before' );
		wp_enqueue_script( $handle );
	}

	/**
	 * Resolve which auto-event handlers should run for this request. Returns
	 * null when every handler is off — tracker.js skips the entire delegation
	 * block in that case.
	 *
	 * @param array<string,mixed> $settings
	 * @return array<string,mixed>|null
	 */
	private function resolve_auto_events( array $settings ): ?array {
		$pro_on = (bool) apply_filters( 'vireo_pro_is_active', false );

		$downloads = ! empty( $settings['auto_event_downloads'] );
		$forms     = ! empty( $settings['auto_event_forms'] );
		$mailto    = ! empty( $settings['auto_event_mailto'] );
		$tel       = ! empty( $settings['auto_event_tel'] );
		// Pro's Outbound_Tracker writes to a dedicated table when active —
		// skip the Free handler to avoid double-fires.
		$outbound = ! empty( $settings['auto_event_outbound'] ) && ! $pro_on;

		/**
		 * Listen for clicks on WP RSS Aggregator items and Spotlight feeds.
		 * On by default whenever the plugin is active, since the dashboard
		 * cards that read these events have nothing else to show.
		 *
		 * @param bool $on
		 */
		$wpra      = Integrations::wpra_active() && (bool) apply_filters( 'vireo_track_wpra', true );
		$spotlight = Integrations::spotlight_active() && (bool) apply_filters( 'vireo_track_spotlight', true );

		if ( ! $downloads && ! $forms && ! $mailto && ! $tel && ! $outbound && ! $wpra && ! $spotlight ) {
			return null;
		}

		// Forms whose accepted submissions the server records (Form_Events).
		// The generic submit listener leaves those alone, or an attempt that
		// bounced on validation would still count beside the real one.
		$served = [];
		if ( $forms && class_exists( Form_Events::class ) && Form_Events::enabled() ) {
			$served = array_values( array_intersect_key( Form_Events::selectors(), Form_Events::active() ) );
		}

		return [
			'dl'   => $downloads,
			'fm'   => $forms,
			'fs'   => $served,
			'ml'   => $mailto,
			'tl'   => $tel,
			'ob'   => $outbound,
			'wr'   => $wpra,
			'sl'   => $spotlight,
			// File-extension regex surfaced to JS so admins can override via
			// the vireo_auto_event_download_exts filter without patching JS.
			'exts' => (string) apply_filters(
				'vireo_auto_event_download_exts',
				'pdf|zip|rar|7z|doc|docx|xls|xlsx|ppt|pptx|csv|mp3|mp4|mov|wav|dmg|pkg|exe|apk'
			),
		];
	}

	/**
	 * Filter-gate for deciding whether to enqueue the script at all. Site
	 * owners can override via the `vireo_load_tracking_script` filter.
	 */
	private function should_track(): bool {
		if ( is_preview() || is_feed() || is_robots() || is_trackback() ) {
			return false;
		}

		// Do Not Track is checked at the beacon and again in tracker.js, not here,
		// for the same reason as the bot filter above: this decision would be
		// baked into cached HTML. One visitor sending DNT would otherwise strip
		// the tracker from the cached page for everybody. The opt-out is still
		// honoured — the script refuses to fire for them, and the endpoint
		// refuses the pageview even if it somehow arrives.

		// Logged-in users with an excluded role, administrators by default.
		if ( function_exists( 'vireo_user_is_excluded' ) && vireo_user_is_excluded() ) {
			return false;
		}

		return (bool) apply_filters( 'vireo_load_tracking_script', true );
	}
}
