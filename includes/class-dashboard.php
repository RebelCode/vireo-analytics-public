<?php
/**
 * Dashboard — Overview page controller + data layer + SVG icon helpers.
 *
 * One class on purpose: keeping data/render/icons together keeps the include
 * list short and the hook wiring obvious. The data methods are pure — they
 * take ($start, $end) and return arrays — so they can be reused by the
 * weekly email digest (Agent B) without pulling in rendering.
 *
 * Country-name map + SVG icon helpers are static methods (used by templates
 * and also consumable by Agent B for posts column / admin bar).
 */

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

final class Dashboard {

	private const PAGE_SLUG = 'vireo-analytics';

	/**
	 * How long to cache dashboard query results (seconds). Short enough that
	 * the next flush (every minute) is reflected promptly, long enough to
	 * cover page reloads while someone is clicking through presets.
	 */
	private const CACHE_TTL = 30;

	private const CACHE_GROUP = 'vireo_dash_';

	private static ?Dashboard $instance = null;

	public static function instance(): Dashboard {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Nonce action for the dashboard's read-only view filters (date range,
	 * compare, metric, segment, drill-down). Every internal filter link carries
	 * this nonce as `_vrn`.
	 */
	private const VIEW_NONCE_ACTION = 'vireo_dashboard_view';

	/** Per-user flag: the "why your numbers look lower" note has been dismissed. */
	private const BOT_EXPLAINER_META  = 'va_bot_explainer_dismissed';
	private const BOT_EXPLAINER_NONCE = 'vireo_bot_explainer';

	/** Nonce action for writing this user's dashboard section order. */
	private const SECTION_ORDER_NONCE = 'vireo_section_order';

	/**
	 * Fresh nonce to stamp onto internal dashboard filter links.
	 */
	public static function view_nonce(): string {
		return wp_create_nonce( self::VIEW_NONCE_ACTION );
	}

	public const DEFAULT_TAB = 'overview';

	/**
	 * Overview tabs, in order. A tab with nothing to show for the range is
	 * left out when the page renders.
	 *
	 * @return array<string,string> Tab id => label.
	 */
	public static function tabs(): array {
		return [
			'overview' => __( 'Overview', 'vireo-analytics' ),
			'pages'    => __( 'Pages', 'vireo-analytics' ),
			'search'   => __( 'Search & AI', 'vireo-analytics' ),
			'speed'     => __( 'Page speed', 'vireo-analytics' ),
			'content'   => __( 'Content', 'vireo-analytics' ),
			'campaigns' => __( 'Campaigns', 'vireo-analytics' ),
			'issues'    => __( 'Issues', 'vireo-analytics' ),
		];
	}

	/**
	 * Which tab an add-on card belongs on, by the class that renders it.
	 *
	 * Cards hooked to `vireo_overview_after_core` used to land on one tab that
	 * held revenue, campaigns, content reports and broken links together. Each
	 * callback is now rendered on its own and sorted onto Content, Campaigns,
	 * Page speed or Issues. Known add-on classes have a default here; anything
	 * else stays on Issues, and `vireo_overview_card_tab` can move any card.
	 * New cards can skip all this and hook `vireo_overview_tab_{tab}` directly.
	 */
	private const CARD_TABS = [
		'Revenue'          => 'campaigns',
		'UTM_Tracker'      => 'campaigns',
		'Goals'            => 'campaigns',
		'Outbound_Tracker' => 'campaigns',
		'Search_Tracker'   => 'content',
		'Engagement'       => 'content',
		'Authors'          => 'content',
		'Post_Types'       => 'content',
		'Taxonomies'       => 'content',
		'Entry_Exit'       => 'content',
		'City_Geo'         => 'content',
		'Page_Vitals'      => 'speed',
	];

	/**
	 * Render every `vireo_overview_after_core` callback separately and group
	 * the output by tab.
	 *
	 * @return array<string,string> Tab id => concatenated card HTML.
	 */
	public static function render_addon_cards( string $start, string $end, bool $compare ): array {
		global $wp_filter;
		$out  = [];
		$hook = $wp_filter['vireo_overview_after_core'] ?? null;
		if ( ! $hook instanceof \WP_Hook ) {
			return $out;
		}

		$priorities = $hook->callbacks;
		ksort( $priorities );
		foreach ( $priorities as $callbacks ) {
			foreach ( $callbacks as $entry ) {
				$cb  = $entry['function'];
				$cls = is_array( $cb ) ? ( is_object( $cb[0] ) ? get_class( $cb[0] ) : (string) $cb[0] ) : '';
				$cls = substr( (string) strrchr( '\\' . $cls, '\\' ), 1 );
				$tab = self::CARD_TABS[ $cls ] ?? 'issues';

				/**
				 * Which Overview tab an add-on card renders on.
				 *
				 * @param string   $tab One of content, campaigns, speed, issues.
				 * @param callable $cb  The callback that renders the card.
				 */
				$tab = (string) apply_filters( 'vireo_overview_card_tab', $tab, $cb );
				if ( ! isset( self::tabs()[ $tab ] ) ) {
					$tab = 'issues';
				}

				ob_start();
				call_user_func_array( $cb, array_slice( [ $start, $end, $compare ], 0, (int) $entry['accepted_args'] ) );
				$out[ $tab ] = ( $out[ $tab ] ?? '' ) . (string) ob_get_clean();
			}
		}
		return $out;
	}

	/**
	 * The tab asked for in the URL. Not one of the view params behind the
	 * nonce: it changes which part of the page is on screen, not what data
	 * is read, so a bare link to it is harmless.
	 */
	public static function requested_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which tab to show; read-only and whitelisted below
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : '';
		return isset( self::tabs()[ $tab ] ) ? $tab : self::DEFAULT_TAB;
	}

	/**
	 * Whether the current request carries a valid view nonce. View-filter GET
	 * params are honored only when this is true; a bare page load (from the
	 * admin menu, with no `_vrn`) or a stale nonce simply falls back to the
	 * default view rather than erroring — the screen is already gated by the
	 * `view_vireo_analytics` capability, so this guards against CSRF-style
	 * param injection, not access.
	 */
	public static function request_is_trusted(): bool {
		static $trusted = null;
		if ( null !== $trusted ) {
			return $trusted;
		}
		$nonce   = isset( $_GET['_vrn'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['_vrn'] ) ) : '';
		$trusted = '' !== $nonce && false !== wp_verify_nonce( $nonce, self::VIEW_NONCE_ACTION );
		return $trusted;
	}

	/**
	 * The read-only params `request_is_trusted()` guards.
	 */
	private const VIEW_PARAMS = [
		'period',
		'start_date',
		'end_date',
		'compare',
		'chart_metric',
		'chart_mode',
		'device',
		'country',
		'source',
		'ref',
		'path',
		'pg_s',
		'pg_by',
		'pg_dir',
		'pg_n',
	];

	/**
	 * Whether the request asked for a view we then refused to honor — i.e. it
	 * carries view params but no valid nonce (a bookmark, or a link shared with
	 * a colleague, whose nonce has since expired).
	 *
	 * Falling back to the default view is right; doing it silently is not. The
	 * URL goes on saying `period=last_month` while the screen shows the last 30
	 * days, so the numbers quietly stop matching the link that produced them.
	 * Callers use this to say so out loud.
	 */
	public static function view_request_was_ignored(): bool {
		if ( self::request_is_trusted() ) {
			return false;
		}
		foreach ( self::VIEW_PARAMS as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- testing for presence only; the value is never read here, which is the point.
			if ( isset( $_GET[ $key ] ) ) {
				return true;
			}
		}
		return false;
	}

	private function __construct() {
		// Priority 5 so the top-level menu is in place before Settings (default
		// priority 10) registers its submenu under the `vireo-analytics` parent.
		add_action( 'admin_menu', [ $this, 'register_menu' ], 5 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		add_action( 'wp_ajax_vireo_dismiss_bot_explainer', [ $this, 'ajax_dismiss_bot_explainer' ] );
		add_action( 'wp_ajax_vireo_save_section_order', [ $this, 'ajax_save_section_order' ] );
		add_action( 'wp_ajax_vireo_reset_section_order', [ $this, 'ajax_reset_section_order' ] );
		add_action( 'wp_ajax_vireo_block_referrer', [ $this, 'ajax_block_referrer' ] );

		// The site selector: which site this user's dashboard shows.
		add_action( 'admin_post_vireo_select_site', [ $this, 'handle_select_site' ] );
	}

	public const SELECT_SITE_NONCE = 'vireo_select_site';

	/**
	 * Remember the site a user picked and send them back to the dashboard.
	 *
	 * The choice is per user, so two people can look at two sites at once,
	 * and it survives the session: the dashboard opens on whatever they last
	 * looked at.
	 */
	public function handle_select_site(): void {
		if ( ! current_user_can( 'view_vireo_analytics' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'vireo-analytics' ), 403 );
		}
		check_admin_referer( self::SELECT_SITE_NONCE );

		$site_id = isset( $_POST['site_id'] ) ? absint( $_POST['site_id'] ) : 0;
		Sites::select_for( get_current_user_id(), $site_id );

		// Back to the range and filters they were on, as long as that was the
		// dashboard; a stale or missing referer lands on the default view.
		$referer = (string) wp_get_referer();
		$target  = '' !== $referer && false !== strpos( $referer, 'page=' . self::PAGE_SLUG ) && false === strpos( $referer, 'view=' )
			? $referer
			: admin_url( 'admin.php?page=' . self::PAGE_SLUG );

		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * Store this user's section order. The posted list is filtered against the
	 * registry rather than trusted, so a malformed or truncated POST can only
	 * ever produce a valid layout.
	 */
	public function ajax_save_section_order(): void {
		if ( ! current_user_can( 'view_vireo_analytics' ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}
		check_ajax_referer( self::SECTION_ORDER_NONCE, 'nonce' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by check_ajax_referer above.
		$posted = isset( $_POST['order'] ) ? (array) wp_unslash( $_POST['order'] ) : [];

		$order = Dashboard_Sections::save_order( get_current_user_id(), $posted );

		wp_send_json_success( [ 'order' => $order ] );
	}

	public function ajax_reset_section_order(): void {
		if ( ! current_user_can( 'view_vireo_analytics' ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}
		check_ajax_referer( self::SECTION_ORDER_NONCE, 'nonce' );

		$order = Dashboard_Sections::reset_order( get_current_user_id() );

		wp_send_json_success( [ 'order' => $order ] );
	}

	/**
	 * Persist that the current user has dismissed the bot-filtering note, so it
	 * doesn't reappear on every dashboard load.
	 */
	/**
	 * "Block this referrer": admins only, from a referrer row. Future hits
	 * from the host are filtered like a bot; its past rows leave the reports.
	 */
	public function ajax_block_referrer(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}
		check_ajax_referer( Referrer_Spam::NONCE, 'nonce' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by check_ajax_referer above.
		$host = isset( $_POST['host'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['host'] ) ) : '';
		$host = vireo_canonical_host( $host );
		if ( '' === $host ) {
			wp_send_json_error( [ 'message' => 'host' ], 400 );
		}
		Referrer_Spam::block( $host );
		wp_send_json_success( [ 'host' => $host, 'blocked' => Referrer_Spam::blocked() ] );
	}

	public function ajax_dismiss_bot_explainer(): void {
		if ( ! current_user_can( 'view_vireo_analytics' ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}
		check_ajax_referer( self::BOT_EXPLAINER_NONCE, 'nonce' );

		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			update_user_meta( $user_id, self::BOT_EXPLAINER_META, 1 );
		}
		wp_send_json_success();
	}

	// ---------------------------------------------------------------------
	// Menu registration
	// ---------------------------------------------------------------------

	public function register_menu(): void {
		// Pass null position to let WordPress append the menu at the bottom rather
		// than competing with core items for prime real estate. Reviewers from the
		// wp.org Plugin Directory flag any low position number (especially below
		// Posts) as visibility-grabbing — leave it to WordPress.
		add_menu_page(
			__( 'Vireo Analytics', 'vireo-analytics' ),
			__( 'Vireo Analytics', 'vireo-analytics' ),
			'view_vireo_analytics',
			self::PAGE_SLUG,
			[ $this, 'render_overview_page' ],
			'dashicons-chart-area',
			null
		);

		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Overview', 'vireo-analytics' ),
			__( 'Overview', 'vireo-analytics' ),
			'view_vireo_analytics',
			self::PAGE_SLUG,
			[ $this, 'render_overview_page' ]
		);
	}

	// ---------------------------------------------------------------------
	// Asset enqueue (scoped to our page only)
	// ---------------------------------------------------------------------

	public function enqueue_assets( $hook ): void {
		$hook = (string) $hook;
		// WP assigns this hook suffix for top-level menu pages registered with
		// add_menu_page( ..., 'vireo-analytics', ... ).
		if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		$js_ver = @filemtime( VIREO_DIR . 'assets/js/dashboard.js' ) ?: VIREO_VERSION;

		// Served from the plugin folder, same as the JS below, with the content
		// hash as the version. The stylesheet used to be served from a copy in
		// uploads, which meant a host rule or a CDN rewrite could take it out and
		// leave the whole dashboard unstyled.
		wp_enqueue_style(
			'va-dashboard',
			VIREO_URL . 'assets/css/dashboard.css',
			[],
			CSS_Hash::dashboard_version()
		);

		// Paper and "Save as PDF": the report without the chrome around it.
		wp_enqueue_style(
			'va-dashboard-print',
			VIREO_URL . 'assets/css/print.css',
			[ 'va-dashboard' ],
			@filemtime( VIREO_DIR . 'assets/css/print.css' ) ?: VIREO_VERSION,
			'print'
		);

		wp_enqueue_script(
			'va-dashboard-icons',
			VIREO_URL . 'assets/js/icons.js',
			[],
			@filemtime( VIREO_DIR . 'assets/js/icons.js' ) ?: VIREO_VERSION,
			true
		);
		wp_enqueue_script(
			'va-admin-tabs',
			VIREO_URL . 'assets/js/admin-tabs.js',
			[],
			@filemtime( VIREO_DIR . 'assets/js/admin-tabs.js' ) ?: VIREO_VERSION,
			true
		);
		wp_enqueue_script(
			'va-dashboard',
			VIREO_URL . 'assets/js/dashboard.js',
			[ 'va-dashboard-icons', 'va-admin-tabs', 'wp-i18n' ],
			$js_ver,
			true
		);
		wp_set_script_translations( 'va-dashboard', 'vireo-analytics', VIREO_DIR . 'languages' );

		wp_localize_script(
			'va-dashboard',
			'vireoDash',
			[
				'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
				// Which site the page shows, so writes made from it (notes) land
				// in that site's table. Verified again server-side.
				'site'              => Sites::selected_for( get_current_user_id() ),
				'botExplainerNonce' => wp_create_nonce( self::BOT_EXPLAINER_NONCE ),
				'sectionOrderNonce' => wp_create_nonce( self::SECTION_ORDER_NONCE ),
				'savedViewsNonce'   => wp_create_nonce( Saved_Views::NONCE ),
				'blockReferrerNonce' => current_user_can( 'manage_options' ) ? wp_create_nonce( Referrer_Spam::NONCE ) : '',
				'yearReviewNonce'   => Year_Review::dismiss_nonce(),
			]
		);

		// World map — fetches the vendored SVG + colours countries by
		// visitor volume. Needs Flag-Icons for the tooltip flag class.
		wp_enqueue_script(
			'va-world-map',
			VIREO_URL . 'assets/js/world-map.js',
			[ 'wp-i18n' ],
			@filemtime( VIREO_DIR . 'assets/js/world-map.js' ) ?: VIREO_VERSION,
			true
		);
		wp_set_script_translations( 'va-world-map', 'vireo-analytics', VIREO_DIR . 'languages' );

		// Onboarding banner dismissal lives in importer.js. Only load it
		// when the banner will actually render — keeps the hot Overview page
		// lean for users past the first-run flow.
		if ( class_exists( Import_Controller::class )
			&& Import_Controller::instance()->should_show_onboarding() ) {
			wp_enqueue_style(
				'va-importer',
				VIREO_URL . 'assets/css/importer.css',
				[],
				@filemtime( VIREO_DIR . 'assets/css/importer.css' ) ?: VIREO_VERSION
			);
			wp_enqueue_script(
				'va-importer',
				VIREO_URL . 'assets/js/importer.js',
				[],
				@filemtime( VIREO_DIR . 'assets/js/importer.js' ) ?: VIREO_VERSION,
				true
			);
			wp_localize_script(
				'va-importer',
				'vireoImport',
				[
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'vireo_import' ),
					'i18n'    => [
						'starting'  => __( 'Starting…', 'vireo-analytics' ),
						'complete'  => __( 'Import complete.', 'vireo-analytics' ),
						'errorBox'  => __( 'Something went wrong:', 'vireo-analytics' ),
						'cancelled' => __( 'Import cancelled.', 'vireo-analytics' ),
					],
				]
			);
		}
	}

	// ---------------------------------------------------------------------
	// Render
	// ---------------------------------------------------------------------

	public function render_overview_page(): void {
		if ( ! current_user_can( 'view_vireo_analytics' ) ) {
			return;
		}

		// View-filter params (drill-down view, chart mode, event) are honored
		// only when the request carries a valid view nonce — see
		// self::request_is_trusted(). Internal links stamp it as `_vrn`.
		$trusted = self::request_is_trusted();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified in self::request_is_trusted()
		$view    = $trusted && isset( $_GET['view'] ) ? sanitize_key( (string) $_GET['view'] ) : '';

		// Delegate to the per-post detail view when requested. Keeps the menu
		// tree flat (no separate submenu) while still giving each post its
		// own URL.
		if ( 'post' === $view && class_exists( Post_Detail::class ) ) {
			Post_Detail::instance()->render();
			return;
		}

		if ( 'year' === $view ) {
			Year_Review::render();
			return;
		}

		// Everything below reads the site this user picked: the overview and
		// the referrer and event drill-downs. The per-post view and the year
		// review above are this WordPress site's own and never switch.
		// Restored at the end so nothing rendered after the page (the admin
		// footer, a late widget) reads the wrong tables.
		Sites::switch_to( self::site_for_view( $view, Sites::selected_for( get_current_user_id() ) ) );
		try {
			if ( 'referrer' === $view && class_exists( Referrer_Detail::class ) ) {
				Referrer_Detail::instance()->render();
			} elseif ( 'event' === $view && class_exists( Event_Detail::class ) ) {
				Event_Detail::instance()->render();
			} else {
				$this->render_overview( $trusted, $view );
			}
		} finally {
			Sites::restore();
		}
	}

	/**
	 * Which site a dashboard view reads: the user's selection, except for the
	 * views that are about this WordPress site's posts and always read it.
	 */
	public static function site_for_view( string $view, int $selected ): int {
		return in_array( $view, [ 'post', 'year' ], true ) ? Sites::MAIN : $selected;
	}

	/**
	 * The subtitle fragment naming a connected site, '' on the main site. The
	 * overview and the drill-downs all print it the same way.
	 */
	public static function site_subtitle(): string {
		$site = Sites::get( Sites::current() );
		if ( null === $site ) {
			return '';
		}
		return ' · <span class="va-page-site">' . esc_html( $site['name'] ) . ' <code>' . esc_html( $site['origin'] ) . '</code></span>';
	}

	private function render_overview( bool $trusted, string $view ): void {
		// Which site this is, for the selector and for the parts of the page
		// that only make sense for this WordPress site.
		$va_sites     = Sites::all();
		$va_site      = Sites::get( Sites::current() );
		$va_connected = Sites::is_connected();

		[ $start, $end, $preset ] = $this->resolve_range_from_request();
		$compare                  = $this->compare_enabled();
		$filters                  = class_exists( Filters::class ) ? Filters::from_request() : null;

		// Chart mode: day (default) or hour. Hour is only meaningful for short
		// ranges. We render daily bars and flip back if the user lands on a
		// wider range with hour still in the URL.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified in self::request_is_trusted()
		$chart_mode = $trusted && isset( $_GET['chart_mode'] ) && 'hour' === sanitize_key( (string) $_GET['chart_mode'] ) ? 'hour' : 'day';
		if ( 'hour' === $chart_mode && self::days_between( $start, $end ) > 2 ) {
			$chart_mode = 'day';
		}
		// Hours are stored for the whole site only, so a filter means days.
		if ( 'hour' === $chart_mode && $filters instanceof Filters && ! $filters->supports( 'hours' ) ) {
			$chart_mode = 'day';
		}

		// What each card can answer under these filters, '' when it can. Each
		// card reads its own entry and says why it is blank.
		$va_unavailable = [];
		foreach ( [ 'totals', 'pages', 'referrers', 'countries', 'devices', 'hours' ] as $va_part ) {
			$va_unavailable[ $va_part ] = $filters instanceof Filters ? $filters->unavailable_reason( $va_part ) : '';
		}

		$hero       = $this->get_hero_stats( $start, $end, $compare, $filters );
		$chart      = 'hour' === $chart_mode
			? $this->get_hour_chart_data( $start, $end )
			: $this->get_chart_data( $start, $end, $filters );
		$chart_prev = ( $compare && 'day' === $chart_mode ) ? $this->get_chart_data_prior( $start, $end, $filters ) : [];
		$pages      = $this->get_top_pages( $start, $end, 10, $compare, $filters );
		$referrers  = $this->get_top_referrers( $start, $end, 10, $compare, $filters );
		$direct     = $this->get_direct_totals( $start, $end, $filters );
		if ( $compare ) {
			[ $dps, $dpe ]   = self::compare_range( $start, $end );
			$direct['prior'] = $this->get_direct_totals( $dps, $dpe, $filters );
		}

		// Comments on the same pages over the same days, from WordPress itself.
		$va_page_comments = Comment_Stats::counts( array_column( $pages, 'post_id' ), $start, $end );

		// Group headers count the whole range, not just the ten rows listed.
		$referrer_totals = $this->get_referrer_bucket_totals( $start, $end, $filters );

		// Direct follows the source filter like the other groups: it is all of
		// "direct" and none of "search".
		if ( $filters instanceof Filters && '' !== $filters->source && 'direct' !== $filters->source ) {
			$direct = [
				'pageviews' => 0,
				'visitors'  => 0,
			];
		}
		$countries  = $this->get_countries( $start, $end, 10, $compare, $filters );
		$devices    = $this->get_devices( $start, $end, $compare, $filters );
		$not_found  = $this->get_404_rows( $start, $end, 10, $compare );

		// The map shades every country with visitors, not only the ten listed
		// beside it, or most of the world reads as "nobody came".
		$countries_map = $this->get_countries( $start, $end, 300, false, $filters );

		// How much of the range has a country at all, for the card's note.
		$va_country_coverage = Country_Coverage::for_range( $start, $end );

		// Free shows the three biggest movers each way; Pro opens up the full list.
		$va_pro_active   = (bool) apply_filters( 'vireo_pro_is_active', false );
		$drivers_limit   = (int) apply_filters( 'vireo_drivers_limit', $va_pro_active ? 10 : 3 );
		$drivers         = ( new Drivers( $this ) )->get( $start, $end, $compare, $drivers_limit, $filters );

		// The one-sentence lede above the hero. Always non-empty, so a fresh or
		// quiet site opens with a point, not a wall of numbers.
		// With no totals to describe, the lede stays out rather than reporting
		// "collecting data" over a filter the rollups cannot answer.
		$insight = ! empty( $hero['unavailable'] ) ? '' : Insights::line( $hero, $drivers, $compare );

		// The last seven full days, the week the email reports, shown only on the
		// default view: under any other range or filter it would answer a
		// question nobody on that screen asked. Main site only: the facts lean
		// on post titles and the import history, neither of which a connected
		// site has.
		$va_highlights      = [];
		$va_highlights_week = [];
		if ( ! $va_connected && 'last_30' === $preset && ! ( $filters instanceof Filters && $filters->is_active() ) ) {
			$va_today           = vireo_bucket_date();
			$va_highlights_week = [
				gmdate( 'Y-m-d', (int) strtotime( $va_today . ' -7 days UTC' ) ),
				gmdate( 'Y-m-d', (int) strtotime( $va_today . ' -1 day UTC' ) ),
			];
			$va_highlights      = Highlights::for_week( $va_highlights_week[0], $va_highlights_week[1] );
		}

		// The "why your numbers look lower than other tools" note, until dismissed.
		$va_bot_explainer_dismissed = (bool) get_user_meta( get_current_user_id(), self::BOT_EXPLAINER_META, true );

		// What the filter rejected over the same range as the numbers above.
		$va_bots       = Bot_Stats::summary( $start, $end );

		// What the assistants took versus what they sent back.
		// One window for the whole card: the days crawlers were counted, which
		// may start later than the range. A per-assistant table over 90 days
		// beside a headline over 27 would not add up, and nobody reads a note.
		$va_ai_exchange = AI_Sources::exchange( $start, $end );
		$va_ai          = AI_Sources::referrals( $va_ai_exchange['start'], $end );
		$va_ai_crawled  = $va_ai_exchange['crawled'];
		$va_ai_referred = $va_ai_exchange['referred'];
		$va_ai_rate     = $va_ai_exchange['rate'];
		$va_ai_since    = $va_ai_exchange['since'];
		// The WP RSS Aggregator and Spotlight cards read this site's posts and
		// events; a connected site has neither.
		$va_wpra       = ! $va_connected && Integrations::wpra_active() ? Integrations::wpra_report( $start, $end ) : null;
		$va_spotlight  = ! $va_connected && Integrations::spotlight_active() ? Integrations::spotlight_report( $start, $end ) : null;
		$va_bot_share  = ! empty( $hero['unavailable'] ) ? null : Bot_Stats::filtered_share( (int) $va_bots['total'], (int) ( $hero['pageviews'] ?? 0 ) );

		// What the pages actually felt like to load, from the people loading
		// them. Empty until the first samples arrive.
		$va_vitals     = Vitals::summary( $start, $end );
		// Google's lab test of the homepage beside them, from the cache only.
		// Nothing is fetched until someone presses the button.
		$va_psi = PageSpeed::view( 'home', Sites::current() );

		// Notes pinned to dates in this range: the chart shows that something
		// changed, these say what.
		$va_annotations     = Annotations::for_range( $start, $end );
		$va_annotation_map  = Annotations::map_for_range( $start, $end );
		$va_can_annotate    = Annotations::can_write();
		$va_annotation_nonce = wp_create_nonce( 'vireo_annotations' );

		// What people searched for before they arrived. Empty until Search
		// Console is connected and has synced at least once. The property is
		// this WordPress site's, so a connected site shows nothing here.
		$va_gsc         = $va_connected ? null : Search_Console::summary( $start, $end );
		$va_gsc_queries = null === $va_gsc ? [] : Search_Console::top_queries( $start, $end, 10 );
		// Which page each of those searches reached. Empty until the per-page
		// table has data, in which case the card simply omits the line.
		$va_gsc_landing = empty( $va_gsc_queries ) ? [] : Search_Console::landing_pages( array_column( $va_gsc_queries, 'query_hash' ), $start, $end );

		// Custom events. When `event` is present in the URL, drill into values
		// for that event name; otherwise show the top events for the range.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified in self::request_is_trusted()
		$event_detail_name = $trusted && isset( $_GET['event'] ) ? sanitize_key( (string) $_GET['event'] ) : '';
		$events            = '' !== $event_detail_name
			? Events::events_by_value( $start, $end, $event_detail_name, 10 )
			: Events::top_events( $start, $end, 10 );

		// Template locals (available inside the include).
		$va_page_title   = $this->page_title_for( $preset, $start, $end );
		$va_filters      = $filters;
		$va_chart_mode   = $chart_mode;
		$va_hour_allowed = self::days_between( $start, $end ) <= 2 && ( ! $filters instanceof Filters || $filters->supports( 'hours' ) );

		// Which period the comparison reads, and where its numbers came from.
		// Last year's figures are often imported history, which is the point of
		// offering the comparison: the tile says so rather than leaving a reader
		// to wonder how a plugin installed in March knows about last January.
		$va_compare_mode  = self::compare_mode();
		$va_prior_range   = $hero['prior_range'] ?? null;
		$va_prior_import  = ( $compare && is_array( $va_prior_range ) && ! $va_connected )
			? Import_History::source_covering( $va_prior_range[0], $va_prior_range[1] )
			: '';

		// This user's saved views, for the picker in the filter bar.
		$va_saved_views = Saved_Views::for_user( get_current_user_id() );
		$va_view_params = Saved_Views::current_params( $preset, $start, $end, $compare, $va_compare_mode, $filters );

		// Onboarding banner: checked on every render so it disappears once
		// data lands or the user dismisses it. Controller handles the logic.
		$va_show_onboarding    = false;
		$va_onboarding_context = null;
		if ( ! $va_connected && class_exists( Import_Controller::class ) ) {
			$controller = Import_Controller::instance();
			if ( $controller->should_show_onboarding() ) {
				$va_show_onboarding    = true;
				$va_onboarding_context = $controller->onboarding_context();
			}
		}

		// The one-time review request. Also counts this visit as a day of use.
		$va_review_days = Feedback::prompt_days();

		// "Your <year> in review is ready", in December and January, until dismissed.
		$va_yir_notice = $va_connected ? null : Year_Review::notice_for_user( get_current_user_id() );

		include VIREO_DIR . 'templates/admin/dashboard-page.php';
	}

	// ---------------------------------------------------------------------
	// Request resolution
	// ---------------------------------------------------------------------

	/**
	 * @return array{0:string,1:string,2:string} [start, end, preset-key]
	 */
	private function resolve_range_from_request(): array {
		$trusted = self::request_is_trusted();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified in self::request_is_trusted()
		$period = $trusted && isset( $_GET['period'] ) ? sanitize_key( (string) $_GET['period'] ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified in self::request_is_trusted()
		$start  = $trusted && isset( $_GET['start_date'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['start_date'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified in self::request_is_trusted()
		$end    = $trusted && isset( $_GET['end_date'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['end_date'] ) ) : '';

		if ( 'custom' === $period && self::valid_date( $start ) && self::valid_date( $end ) ) {
			if ( strcmp( $end, $start ) < 0 ) {
				[ $start, $end ] = [ $end, $start ];
			}
			// Best-effort match against known presets for the active-pill UI.
			return [ $start, $end, self::detect_preset_key( $start, $end ) ];
		}

		$preset = '' !== $period ? $period : 'last_30';
		$range  = self::preset_range( $preset );
		if ( null === $range ) {
			$preset = 'last_30';
			$range  = self::preset_range( $preset );
		}
		return [ $range['start'], $range['end'], $preset ];
	}

	private static function valid_date( string $s ): bool {
		return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $s );
	}

	/**
	 * @return array{start:string,end:string}|null
	 */
	public static function preset_range( string $key ): ?array {
		$tz    = wp_timezone();
		$today = ( new \DateTimeImmutable( 'now', $tz ) )->setTime( 0, 0, 0 );
		$fmt   = 'Y-m-d';

		switch ( $key ) {
			case 'today':
				return [ 'start' => $today->format( $fmt ), 'end' => $today->format( $fmt ) ];
			case 'yesterday':
				$d = $today->modify( '-1 day' );
				return [ 'start' => $d->format( $fmt ), 'end' => $d->format( $fmt ) ];
			case 'last_7':
				return [ 'start' => $today->modify( '-6 days' )->format( $fmt ), 'end' => $today->format( $fmt ) ];
			case 'last_14':
				return [ 'start' => $today->modify( '-13 days' )->format( $fmt ), 'end' => $today->format( $fmt ) ];
			case 'last_30':
				return [ 'start' => $today->modify( '-29 days' )->format( $fmt ), 'end' => $today->format( $fmt ) ];
			case 'last_90':
				return [ 'start' => $today->modify( '-89 days' )->format( $fmt ), 'end' => $today->format( $fmt ) ];
			case 'this_month':
				return [ 'start' => $today->modify( 'first day of this month' )->format( $fmt ), 'end' => $today->format( $fmt ) ];
			case 'last_month':
				$s = $today->modify( 'first day of last month' );
				$e = $today->modify( 'last day of last month' );
				return [ 'start' => $s->format( $fmt ), 'end' => $e->format( $fmt ) ];
			case 'ytd':
				return [ 'start' => $today->modify( 'first day of January ' . $today->format( 'Y' ) )->format( $fmt ), 'end' => $today->format( $fmt ) ];
		}
		return null;
	}

	private static function detect_preset_key( string $start, string $end ): string {
		foreach ( [ 'today', 'yesterday', 'last_7', 'last_14', 'last_30', 'last_90', 'this_month', 'last_month', 'ytd' ] as $key ) {
			$r = self::preset_range( $key );
			if ( $r && $r['start'] === $start && $r['end'] === $end ) {
				return $key;
			}
		}
		return 'custom';
	}

	public static function compare_enabled(): bool {
		// Always on by default. Users can disable with `?compare=0` for a cleaner
		// view. Honored only on a trusted (nonce-stamped) request.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified in self::request_is_trusted()
		if ( self::request_is_trusted() && isset( $_GET['compare'] ) && '0' === sanitize_key( (string) $_GET['compare'] ) ) {
			return false;
		}
		return true;
	}

	/** The two things a range can be compared with. */
	public const COMPARE_MODES = [ 'prior', 'year' ];

	/**
	 * What the range is compared with: `prior`, the period just before it, or
	 * `year`, the same dates a year earlier. `?compare=year` picks the latter;
	 * anything else is the prior period, as it always was.
	 */
	public static function compare_mode(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified in self::request_is_trusted()
		if ( self::request_is_trusted() && isset( $_GET['compare'] ) && 'year' === sanitize_key( (string) $_GET['compare'] ) ) {
			return 'year';
		}
		return 'prior';
	}

	/**
	 * The range a comparison reads, in the request's compare mode unless one
	 * is given.
	 *
	 * Prior: the equal-length range immediately before. Year: the same dates
	 * one year earlier, which is where imported history earns its keep, since
	 * a site that imported last year's numbers can compare against them from
	 * the day Vireo is installed. A 29 February with no counterpart lands on
	 * the 28th.
	 *
	 * @return array{0:string,1:string} [prior_start, prior_end]
	 */
	public static function compare_range( string $start, string $end, ?string $mode = null ): array {
		$s = strtotime( $start . ' 00:00:00 UTC' );
		$e = strtotime( $end . ' 00:00:00 UTC' );
		if ( ! $s || ! $e || $e < $s ) {
			return [ $start, $end ];
		}

		if ( 'year' === ( $mode ?? self::compare_mode() ) ) {
			return [ self::year_earlier( $start ), self::year_earlier( $end ) ];
		}

		$len       = (int) ( ( ( $e - $s ) / DAY_IN_SECONDS ) + 1 );
		$prior_end = gmdate( 'Y-m-d', $s - DAY_IN_SECONDS );
		$prior_sta = gmdate( 'Y-m-d', $s - ( $len * DAY_IN_SECONDS ) );
		return [ $prior_sta, $prior_end ];
	}

	private static function year_earlier( string $date ): string {
		[ $y, $m, $d ] = array_map( 'intval', explode( '-', $date ) );
		$y--;
		$d = min( $d, (int) gmdate( 't', gmmktime( 0, 0, 0, $m, 1, $y ) ) );
		return sprintf( '%04d-%02d-%02d', $y, $m, $d );
	}

	/**
	 * How much of the previous period to weigh against a range that ends today.
	 *
	 * "Last 30 days" includes today, which is only part of a day until midnight,
	 * while the period it is compared with is 30 whole days. Unscaled, every
	 * comparison made in the morning reads as a decline. The previous period is
	 * scaled to the share of the range that has actually elapsed, assuming
	 * today's traffic is spread evenly; ranges that end before today get 1.
	 */
	public static function compare_weight( string $start, string $end ): float {
		$today = vireo_bucket_date();
		if ( $end < $today || $start > $today ) {
			return 1.0;
		}

		$s = strtotime( $start . ' 00:00:00 UTC' );
		$e = strtotime( $today . ' 00:00:00 UTC' );
		if ( ! $s || ! $e || $e < $s ) {
			return 1.0;
		}
		$len = (int) ( ( ( $e - $s ) / DAY_IN_SECONDS ) + 1 );

		$midnight = new \DateTimeImmutable( $today . ' 00:00:00', wp_timezone() );
		$elapsed  = ( time() - $midnight->getTimestamp() ) / DAY_IN_SECONDS;
		$elapsed  = max( 0.01, min( 1.0, $elapsed ) );

		return ( $len - 1 + $elapsed ) / $len;
	}

	private function page_title_for( string $preset, string $start, string $end ): string {
		$labels = [
			'today'      => __( 'today', 'vireo-analytics' ),
			'yesterday'  => __( 'yesterday', 'vireo-analytics' ),
			'last_7'     => __( 'last 7 days', 'vireo-analytics' ),
			'last_14'    => __( 'last 14 days', 'vireo-analytics' ),
			'last_30'    => __( 'last 30 days', 'vireo-analytics' ),
			'last_90'    => __( 'last 90 days', 'vireo-analytics' ),
			'this_month' => __( 'this month', 'vireo-analytics' ),
			'last_month' => __( 'last month', 'vireo-analytics' ),
			'ytd'        => __( 'year to date', 'vireo-analytics' ),
		];

		if ( isset( $labels[ $preset ] ) ) {
			/* translators: %s: a date-range label such as "last 7 days" or "this month". */
			return sprintf( __( 'Traffic overview · %s', 'vireo-analytics' ), $labels[ $preset ] );
		}
		$s = vireo_format_day( (string) $start, 'M j' );
		$e = vireo_format_day( (string) $end, 'M j, Y' );
		/* translators: 1: start date (short format), 2: end date (short format, with year). */
		return sprintf( __( 'Traffic overview · %1$s – %2$s', 'vireo-analytics' ), $s, $e );
	}

	// ---------------------------------------------------------------------
	// Data layer — each method returns a plain array, caches via transient.
	// ---------------------------------------------------------------------

	/**
	 * @return array{
	 *   pageviews:int, visitors:int, avg_daily:int, days:int,
	 *   prior: array{pageviews:int,visitors:int,avg_daily:int}|null,
	 *   sparkline: array<int, array{date:string,pageviews:int,visitors:int}>
	 * }
	 */
	public function get_hero_stats( string $start, string $end, bool $with_compare = false, ?Filters $filters = null ): array {
		$filter_frag = $filters ? $filters->cache_fragment() : 'none';
		$cache_key   = self::cache_key( 'hero', $start, $end, $with_compare, $filter_frag );
		$cached      = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		// Filter-aware totals, read from whichever rollup table carries the
		// active dimensions; see Filters::totals_query(). A combination no
		// table can answer comes back as unavailable, never as a guess.
		$unavailable = $filters instanceof Filters && ! $filters->supports( 'totals' );
		$totals      = $unavailable ? [ 'pageviews' => 0, 'visitors' => 0 ] : $this->filtered_site_totals( $start, $end, $filters );

		$days = self::days_between( $start, $end );

		$series = $unavailable ? [] : $this->get_chart_data( $start, $end, $filters );

		// Today's hits since the last flush are in the buffer, not the
		// rollups. Added here, and to the chart's last day in get_chart_data,
		// for the whole site only: a buffered hit carries no country or device.
		$today = vireo_bucket_date();
		if ( ! $unavailable && $end >= $today && $start <= $today && ! ( $filters instanceof Filters && $filters->is_active() ) ) {
			$pending             = Pending::today();
			$totals['pageviews'] = (int) ( $totals['pageviews'] ?? 0 ) + (int) $pending['pageviews'];
			$totals['visitors']  = (int) ( $totals['visitors'] ?? 0 ) + (int) $pending['visitors'];
		}

		// Time on page and bounce rate have no country, device or source in
		// their rollups, so under a filter they would answer for the whole
		// site beside totals that don't. Left out instead; the cards say why.
		$filtered = $filters instanceof Filters && $filters->is_active();
		$time     = $filtered ? null : Engagement_Basics::time_summary( $start, $end );
		$bounce   = $filtered ? null : Engagement_Basics::bounce( $start, $end );
		$daily    = $filtered ? [ 'time' => [], 'bounce' => [] ] : Engagement_Basics::daily( $start, $end );

		$result = [
			'pageviews'     => (int) ( $totals['pageviews'] ?? 0 ),
			'visitors'      => (int) ( $totals['visitors'] ?? 0 ),
			'avg_daily'     => $days > 0 ? (int) round( ( (int) ( $totals['pageviews'] ?? 0 ) ) / $days ) : 0,
			'days'          => $days,
			'filtered'      => $filtered,
			'unavailable'   => $unavailable,
			'avg_time'      => null === $time ? null : $time['avg_seconds'],
			'time_samples'  => null === $time ? 0 : $time['samples'],
			'bounce_rate'   => null === $bounce ? null : $bounce['rate'],
			// The range and the first timed day, so an empty card can say
			// whether the range predates the number or the number is still
			// to come. See Engagement_Basics::time_empty_state().
			'start'         => $start,
			'end'           => $end,
			'time_since'    => $filtered || null !== $time ? null : Engagement_Basics::first_timed_day(),
			'prior'         => null,
			'prior_range'   => null,
			'compare_mode'  => self::compare_mode(),
			'sparkline'     => $series,
			'time_series'   => $daily['time'],
			'bounce_series' => $daily['bounce'],
		];

		if ( $with_compare && ! $unavailable ) {
			[ $ps, $pe ] = self::compare_range( $start, $end );
			$prior       = $this->filtered_site_totals( $ps, $pe, $filters );
			$prior_days  = self::days_between( $ps, $pe );
			$weight      = self::compare_weight( $start, $end );
			$result['prior_range'] = [ $ps, $pe ];
			$result['prior'] = [
				'pageviews' => (int) round( (int) ( $prior['pageviews'] ?? 0 ) * $weight ),
				'visitors'  => (int) round( (int) ( $prior['visitors'] ?? 0 ) * $weight ),
				// Weighted like the pageviews above. Unweighted, Today showed the
				// same 2.6k as down 40% in one card and 59% in the next.
				'avg_daily' => $prior_days > 0 ? (int) round( ( (int) ( $prior['pageviews'] ?? 0 ) * $weight ) / $prior_days ) : 0,
			];
			// Averages and rates need no weighting: a partial day is still an
			// average of the views it had.
			if ( ! $filtered ) {
				$prior_time   = Engagement_Basics::time_summary( $ps, $pe );
				$prior_bounce = Engagement_Basics::bounce( $ps, $pe );

				$result['prior']['avg_time']    = null === $prior_time ? null : $prior_time['avg_seconds'];
				$result['prior']['bounce_rate'] = null === $prior_bounce ? null : $prior_bounce['rate'];
			}
		}

		set_transient( $cache_key, $result, self::CACHE_TTL );
		return $result;
	}

	/**
	 * @return array<int, array{date:string,pageviews:int,visitors:int}>
	 */
	public function get_chart_data( string $start, string $end, ?Filters $filters = null ): array {
		$filter_frag = $filters ? $filters->cache_fragment() : 'none';
		$cache_key   = self::cache_key( 'chart', $start, $end, $filter_frag );
		$cached      = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		// One day per row from whichever table carries the filter, the same
		// table the hero sums, so the bars always add up to the headline.
		$query = $filters ? $filters->totals_query() : null;
		if ( null === $query ) {
			if ( $filters instanceof Filters && $filters->is_active() ) {
				return [];
			}
			$query = ( new Filters() )->totals_query();
		}
		$by_date = $this->daily_for( $query, $start, $end );

		// Direct is what no referrer claims, day by day.
		if ( $filters instanceof Filters && $filters->is_direct() && ! $filters->has_ref() ) {
			$referred = $this->daily_for( $filters->referred_query(), $start, $end );
			foreach ( $by_date as $date => $row ) {
				$by_date[ $date ]['pageviews'] = max( 0, $row['pageviews'] - (int) ( $referred[ $date ]['pageviews'] ?? 0 ) );
				$by_date[ $date ]['visitors']  = max( 0, $row['visitors'] - (int) ( $referred[ $date ]['visitors'] ?? 0 ) );
			}
		}

		// Today's hits since the last flush, for the whole site only.
		$today = vireo_bucket_date();
		if ( $end >= $today && $start <= $today && ! ( $filters instanceof Filters && $filters->is_active() ) ) {
			$pending = Pending::today();
			if ( (int) $pending['pageviews'] > 0 ) {
				$row                = $by_date[ $today ] ?? [ 'date' => $today, 'pageviews' => 0, 'visitors' => 0 ];
				$row['pageviews']  += (int) $pending['pageviews'];
				$row['visitors']   += (int) $pending['visitors'];
				$by_date[ $today ]  = $row;
			}
		}

		// Fill zero-rows for missing dates so the chart bars align one-per-day.
		$series = [];
		try {
			$cursor = new \DateTimeImmutable( $start );
			$stop   = new \DateTimeImmutable( $end );
		} catch ( \Exception $e ) {
			return [];
		}
		while ( $cursor <= $stop ) {
			$key      = $cursor->format( 'Y-m-d' );
			$series[] = $by_date[ $key ] ?? [ 'date' => $key, 'pageviews' => 0, 'visitors' => 0 ];
			$cursor   = $cursor->modify( '+1 day' );
			if ( count( $series ) > 400 ) {
				break; // Safety — we never expect a range this large.
			}
		}

		set_transient( $cache_key, $series, self::CACHE_TTL );
		return $series;
	}

	/**
	 * @return array<int, array{date:string,pageviews:int,visitors:int}>
	 */
	public function get_chart_data_prior( string $start, string $end, ?Filters $filters = null ): array {
		[ $ps, $pe ] = self::compare_range( $start, $end );
		return $this->get_chart_data( $ps, $pe, $filters );
	}

	/**
	 * Pageviews and visitors per day for a Filters::totals_query() shape.
	 *
	 * @param array{from:string,tables:string[],where:string,args:array<int,string|int>} $query
	 * @return array<string, array{date:string,pageviews:int,visitors:int}> Keyed by date.
	 */
	private function daily_for( array $query, string $start, string $end ): array {
		global $wpdb;

		$sql  = 'SELECT s.date, COALESCE(SUM(s.pageviews),0) AS pageviews, COALESCE(SUM(s.visitors),0) AS visitors
		         FROM ' . $query['from'] . '
		         WHERE s.date BETWEEN %s AND %s' . $query['where'] . '
		         GROUP BY s.date ORDER BY s.date ASC';
		$args = array_merge( $query['tables'], [ $start, $end ], $query['args'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- caller caches; the fragments are literals from Filters with every value bound in $args
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );

		$by_date = [];
		foreach ( (array) $rows as $r ) {
			$by_date[ $r['date'] ] = [
				'date'      => (string) $r['date'],
				'pageviews' => (int) $r['pageviews'],
				'visitors'  => (int) $r['visitors'],
			];
		}
		return $by_date;
	}

	/**
	 * Pageviews and visitors summed over a range for a Filters::totals_query()
	 * shape.
	 *
	 * @param array{from:string,tables:string[],where:string,args:array<int,string|int>} $query
	 * @return array{pageviews:int, visitors:int}
	 */
	private function sum_for( array $query, string $start, string $end ): array {
		global $wpdb;

		$sql  = 'SELECT COALESCE(SUM(s.pageviews),0) AS pageviews, COALESCE(SUM(s.visitors),0) AS visitors
		         FROM ' . $query['from'] . '
		         WHERE s.date BETWEEN %s AND %s' . $query['where'];
		$args = array_merge( $query['tables'], [ $start, $end ], $query['args'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- caller caches; the fragments are literals from Filters with every value bound in $args
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $args ), ARRAY_A );

		return [
			'pageviews' => (int) ( $row['pageviews'] ?? 0 ),
			'visitors'  => (int) ( $row['visitors'] ?? 0 ),
		];
	}

	/**
	 * Sum pageviews + visitors for a range, respecting the active filter.
	 * With no filter this reads va_site_stats; otherwise the one table that
	 * carries every active dimension, from Filters::totals_query(). A
	 * combination no table carries is the caller's to refuse, via
	 * Filters::supports( 'totals' ); here it reads as zero.
	 *
	 * Direct is everything no referrer claims: the site (or the page) minus
	 * its referred part. Pageviews subtract exactly; visitors are approximate,
	 * since daily uniques don't subtract cleanly across tables.
	 *
	 * @return array{pageviews:int, visitors:int}
	 */
	private function filtered_site_totals( string $start, string $end, ?Filters $filters ): array {
		$filters = $filters ?? new Filters();
		$query   = $filters->totals_query();
		if ( null === $query ) {
			return [ 'pageviews' => 0, 'visitors' => 0 ];
		}

		$all = $this->sum_for( $query, $start, $end );
		if ( ! $filters->is_direct() || $filters->has_ref() ) {
			return $all;
		}

		$ref = $this->sum_for( $filters->referred_query(), $start, $end );
		return [
			'pageviews' => max( 0, $all['pageviews'] - $ref['pageviews'] ),
			'visitors'  => max( 0, $all['visitors'] - $ref['visitors'] ),
		];
	}

	/**
	 * Hourly chart data. Returns one row per hour in the range, zero-filled.
	 * Intended for ranges ≤ 48h — the caller guardrails this upstream.
	 *
	 * Shape matches get_chart_data() so the JS chart renders both. The `date`
	 * field becomes "YYYY-MM-DD HH:00" so tooltips show the hour.
	 *
	 * @return array<int, array{date:string,pageviews:int,visitors:int,hour:int}>
	 */
	public function get_hour_chart_data( string $start, string $end ): array {
		$cache_key = self::cache_key( 'hour_chart', $start, $end );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$table = Sites::table( 'hour_stats' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- schema probe, uncacheable
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return [];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- transient-cached above
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT date, hour, pageviews, visitors
				 FROM %i
				 WHERE date BETWEEN %s AND %s
				 ORDER BY date ASC, hour ASC',
				$table,
				$start,
				$end
			),
			ARRAY_A
		);

		$by_key = [];
		foreach ( (array) $rows as $r ) {
			$key = $r['date'] . ' ' . sprintf( '%02d:00', (int) $r['hour'] );
			$by_key[ $key ] = [
				'date'      => $key,
				'pageviews' => (int) $r['pageviews'],
				'visitors'  => (int) $r['visitors'],
				'hour'      => (int) $r['hour'],
			];
		}

		$series = [];
		try {
			$cursor = new \DateTimeImmutable( $start . ' 00:00:00', new \DateTimeZone( 'UTC' ) );
			$stop   = new \DateTimeImmutable( $end   . ' 23:00:00', new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception $e ) {
			return [];
		}
		while ( $cursor <= $stop ) {
			$key      = $cursor->format( 'Y-m-d H' ) . ':00';
			$series[] = $by_key[ $key ] ?? [
				'date'      => $key,
				'pageviews' => 0,
				'visitors'  => 0,
				'hour'      => (int) $cursor->format( 'G' ),
			];
			$cursor = $cursor->modify( '+1 hour' );
			if ( count( $series ) > 72 ) {
				break;
			}
		}

		set_transient( $cache_key, $series, self::CACHE_TTL );
		return $series;
	}

	/**
	 * Top pages for the range. Resolves permalinks when `post_id > 0`, falls
	 * back to the stored path (which may be `/some-archive/`, a term page, etc).
	 *
	 * @return array<int, array{post_id:int,path:string,title:string,url:string,pageviews:int,visitors:int,delta:int|null}>
	 */
	public function get_top_pages( string $start, string $end, int $limit = 10, bool $with_compare = false, ?Filters $filters = null ): array {
		$filter_frag = $filters ? $filters->cache_fragment() : 'none';
		$cache_key   = self::cache_key( 'pages', $start, $end, $with_compare, $limit, $filter_frag );
		$cached      = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		if ( $filters instanceof Filters && ! $filters->supports( 'pages' ) ) {
			return [];
		}

		// Under a source or referrer filter the list comes from the post ×
		// referrer table, keyed by post rather than path; see pages_by_post().
		$by_post = $filters instanceof Filters && ( '' !== $filters->source || $filters->has_ref() );

		global $wpdb;
		$stats = Sites::table( 'post_stats' );
		$paths = Sites::table( 'paths' );

		$page_where = '';
		$page_args  = [];
		if ( ! $by_post && $filters instanceof Filters && $filters->has_page() ) {
			$page_where = ' AND p.path = %s';
			$page_args  = [ $filters->path ];
		}

		if ( $by_post ) {
			$rows = $this->pages_by_post( $filters, $start, $end, $limit );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- transient-cached above; $page_where is a literal with its value bound in the args
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// Grouped by path alone. A path can be recorded both with its post ID
					// and without one (the front page, for instance, is often seen as
					// a bare "/"), and grouping by both listed one page twice.
					'SELECT MAX(s.post_id) AS post_id, p.path,
					        SUM(s.pageviews) AS pageviews,
					        SUM(s.visitors)  AS visitors
					 FROM %i s
					 JOIN %i p ON p.id = s.path_id
					 WHERE s.date BETWEEN %s AND %s' . $page_where . '
					 GROUP BY p.path
					 ORDER BY pageviews DESC
					 LIMIT %d',
					array_merge( [ $stats, $paths, $start, $end ], $page_args, [ $limit ] )
				),
				ARRAY_A
			);
		}

		$prior_by_key = [];
		if ( $with_compare ) {
			[ $ps, $pe ] = self::compare_range( $start, $end );
			if ( $by_post ) {
				foreach ( $this->pages_by_post( $filters, $ps, $pe, null ) as $pr ) {
					$prior_by_key[ 'p' . (int) $pr['post_id'] ] = (int) $pr['pageviews'];
				}
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- transient-cached above; $page_where is a literal with its value bound in the args
				$prior_rows = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT p.path, SUM(s.pageviews) AS pageviews
						 FROM %i s
						 JOIN %i p ON p.id = s.path_id
						 WHERE s.date BETWEEN %s AND %s' . $page_where . '
						 GROUP BY p.path',
						array_merge( [ $stats, $paths, $ps, $pe ], $page_args )
					),
					ARRAY_A
				);
				foreach ( (array) $prior_rows as $pr ) {
					$prior_by_key[ (string) $pr['path'] ] = (int) $pr['pageviews'];
				}
			}
		}

		$out = [];
		foreach ( (array) $rows as $r ) {
			$post_id = (int) $r['post_id'];
			$path    = (string) ( $r['path'] ?? '' );
			$title   = '';
			$url     = '';
			if ( $post_id > 0 ) {
				[ 'title' => $title, 'url' => $url ] = self::public_post( $post_id );
			}
			// Rows keyed by post carry no stored path; the permalink's stands in,
			// so the row can still be clicked into a page filter.
			if ( $by_post && '' === $path && '' !== $url ) {
				$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			}
			if ( '' === $title ) {
				$title = $path !== '' ? $path : __( '(no path)', 'vireo-analytics' );
			}
			$cur   = (int) $r['pageviews'];
			$delta = null;
			$prior = null;
			if ( $with_compare ) {
				$prior_key = $by_post ? 'p' . $post_id : $path;
				$prior     = (int) round( ( $prior_by_key[ $prior_key ] ?? 0 ) * self::compare_weight( $start, $end ) );
				$delta     = $cur - $prior;
			}
			$out[] = [
				'post_id'   => $post_id,
				'path'      => $path,
				'title'     => $title,
				'url'       => $url,
				'pageviews' => $cur,
				'visitors'  => (int) $r['visitors'],
				'delta'     => $delta,
				'prior'     => $prior,
				'avg_time'  => null,
			];
		}

		// Time is keyed by path, which is also what the rows are grouped on.
		// Time on page is not split by source, so a list filtered that way
		// leaves it out rather than showing the page's figure for all sources.
		if ( ! $by_post ) {
			$times = Engagement_Basics::time_by_path( array_column( $out, 'path' ), $start, $end );
			foreach ( $out as $i => $row ) {
				if ( isset( $times[ $row['path'] ] ) ) {
					$out[ $i ]['avg_time'] = $times[ $row['path'] ]['avg_seconds'];
				}
			}
		}

		set_transient( $cache_key, $out, self::CACHE_TTL );
		return $out;
	}

	/**
	 * Pages under a source or referrer filter, one row per post, from the
	 * post × referrer table. Direct is the post's total minus its referred
	 * part, post by post. Only pages recorded with a post ID exist there, so
	 * archives and other bare paths are not listed.
	 *
	 * @return array<int, array{post_id:int,pageviews:int,visitors:int}>
	 */
	private function pages_by_post( Filters $filters, string $start, string $end, ?int $limit ): array {
		global $wpdb;

		$limit_sql  = null === $limit ? '' : ' LIMIT %d';
		$limit_args = null === $limit ? [] : [ $limit ];

		if ( ! $filters->is_direct() ) {
			$q    = $filters->referrer_query( true );
			$sql  = 'SELECT s.post_id, SUM(s.pageviews) AS pageviews, SUM(s.visitors) AS visitors
			         FROM ' . $q['from'] . '
			         WHERE s.date BETWEEN %s AND %s' . $q['where'] . '
			         GROUP BY s.post_id ORDER BY pageviews DESC' . $limit_sql;
			$args = array_merge( $q['tables'], [ $start, $end ], $q['args'], $limit_args );
		} elseif ( $filters->has_ref() ) {
			return [];
		} else {
			$page_where = $filters->has_page() ? ' AND s.post_id = %d' : '';
			$page_args  = $filters->has_page() ? [ $filters->page_post_id() ] : [];
			$r          = $filters->referred_query( true );
			$sql        = 'SELECT a.post_id,
			                      a.pageviews - COALESCE(r.pageviews, 0) AS pageviews,
			                      a.visitors  - COALESCE(r.visitors, 0)  AS visitors
			               FROM ( SELECT s.post_id, SUM(s.pageviews) AS pageviews, SUM(s.visitors) AS visitors
			                      FROM %i s WHERE s.date BETWEEN %s AND %s AND s.post_id > 0' . $page_where . '
			                      GROUP BY s.post_id ) a
			               LEFT JOIN ( SELECT s.post_id, SUM(s.pageviews) AS pageviews, SUM(s.visitors) AS visitors
			                      FROM ' . $r['from'] . ' WHERE s.date BETWEEN %s AND %s' . $r['where'] . '
			                      GROUP BY s.post_id ) r ON r.post_id = a.post_id
			               WHERE a.pageviews - COALESCE(r.pageviews, 0) > 0
			               ORDER BY pageviews DESC' . $limit_sql;
			$args       = array_merge(
				[ Sites::table( 'post_stats' ), $start, $end ],
				$page_args,
				$r['tables'],
				[ $start, $end ],
				$r['args'],
				$limit_args
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- caller caches; the fragments are literals from Filters with every value bound in $args
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );

		$out = [];
		foreach ( (array) $rows as $r ) {
			$out[] = [
				'post_id'   => (int) $r['post_id'],
				'pageviews' => max( 0, (int) $r['pageviews'] ),
				'visitors'  => max( 0, (int) $r['visitors'] ),
			];
		}
		return $out;
	}

	/**
	 * Top referrers for the range, with hostname clustering so
	 * `www.example.com` and `example.com` roll up into one row.
	 *
	 * We pull a wider result set from the DB than $limit so that clustering
	 * (which may merge variants) doesn't starve the final list. The raw
	 * referrer_urls data is left untouched — clustering is a query-time view.
	 *
	 * @return array<int, array{host:string,pageviews:int,visitors:int,delta:int|null}>
	 */
	public function get_top_referrers( string $start, string $end, int $limit = 10, bool $with_compare = false, $filters = null ): array {
		// `$filters` is either a Filters value object (current) or the older
		// fingerprint-bearing filter object (compat). We use two fingerprints
		// for cache isolation: the canonical LA\Filters or the legacy method.
		if ( $filters instanceof Filters ) {
			$filters_fp = $filters->cache_fragment();
		} elseif ( is_object( $filters ) && method_exists( $filters, 'fingerprint' ) ) {
			$filters_fp = (string) $filters->fingerprint();
		} else {
			$filters_fp = '';
		}
		$cache_key  = self::cache_key( 'refs', $start, $end, $with_compare, $limit, $filters_fp );
		$cached     = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		if ( $filters instanceof Filters && ! $filters->supports( 'referrers' ) ) {
			return [];
		}

		global $wpdb;

		// Pull up to 5x the requested limit so clustering has enough material
		// to merge variants without dropping long-tail sources.
		$fetch_limit = max( 50, $limit * 5 );

		// The table and WHERE the filters select: referrer_stats, or the post ×
		// referrer table under a page filter, narrowed to a source bucket or a
		// host. Direct short-circuits to an empty set (no rows in referrer
		// tables can represent "direct traffic").
		$q = ( $filters instanceof Filters ? $filters : new Filters() )->referrer_query();

		$sql = 'SELECT u.url AS host,
		        SUM(s.pageviews) AS pageviews,
		        SUM(s.visitors)  AS visitors
		 FROM ' . $q['from'] . '
		 WHERE s.date BETWEEN %s AND %s' . $q['where'] . '
		 GROUP BY u.url
		 ORDER BY pageviews DESC
		 LIMIT %d';
		$args = array_merge( $q['tables'], [ $start, $end ], $q['args'], [ $fetch_limit ] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- safe; the fragments are literals from Filters with every value bound in $args
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );

		// A host whose visits show no engagement stays listed, tagged, for the
		// admin to block; see Referrer_Spam::looks_like_junk().
		$suspects = Referrer_Spam::suspects( array_column( (array) $rows, 'host' ), $start, $end );

		$prior_by_canonical = [];
		if ( $with_compare ) {
			[ $ps, $pe ] = self::compare_range( $start, $end );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- transient-cached above; same fragments as the query above
			$prior_rows  = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT u.url AS host, SUM(s.pageviews) AS pageviews
					 FROM ' . $q['from'] . '
					 WHERE s.date BETWEEN %s AND %s' . $q['where'] . '
					 GROUP BY u.url',
					array_merge( $q['tables'], [ $ps, $pe ], $q['args'] )
				),
				ARRAY_A
			);
			foreach ( (array) $prior_rows as $pr ) {
				$canon = vireo_canonical_host( (string) $pr['host'] );
				if ( '' === $canon ) {
					$canon = (string) $pr['host'];
				}
				$prior_by_canonical[ $canon ] = ( $prior_by_canonical[ $canon ] ?? 0 ) + (int) $pr['pageviews'];
			}
		}

		// Cluster variants by canonical host (e.g. strip `www.`).
		$by_canonical = [];
		foreach ( (array) $rows as $r ) {
			$host  = (string) $r['host'];
			$canon = vireo_canonical_host( $host );
			if ( '' === $canon ) {
				$canon = $host;
			}
			if ( ! isset( $by_canonical[ $canon ] ) ) {
				$by_canonical[ $canon ] = [
					'host'      => $canon,
					'pageviews' => 0,
					'visitors'  => 0,
				];
			}
			$by_canonical[ $canon ]['pageviews'] += (int) $r['pageviews'];
			$by_canonical[ $canon ]['visitors']  += (int) $r['visitors'];
		}

		// Sort by pageviews DESC, slice to $limit.
		uasort(
			$by_canonical,
			static fn( array $a, array $b ): int => ( (int) $b['pageviews'] ) <=> ( (int) $a['pageviews'] )
		);
		$by_canonical = array_slice( $by_canonical, 0, $limit, true );

		$out = [];
		foreach ( $by_canonical as $row ) {
			$canon = (string) $row['host'];
			$cur   = (int) $row['pageviews'];
			$prior = $with_compare ? (int) ( $prior_by_canonical[ $canon ] ?? 0 ) : null;
			$delta = $with_compare ? ( $cur - (int) $prior ) : null;
			$out[] = [
				'host'      => $canon,
				'pageviews' => $cur,
				'visitors'  => (int) $row['visitors'],
				'delta'     => $delta,
				'prior'     => $prior,
				'suspect'   => in_array( $canon, $suspects, true ),
			];
		}

		set_transient( $cache_key, $out, self::CACHE_TTL );
		return $out;
	}

	/**
	 * Group top-referrer rows by source type (direct / search / social /
	 * referral). Each bucket has {rows, pageviews}.
	 *
	 * "Direct" in a referrer list will always be empty (direct visits have an
	 * empty referrer URL and don't appear in va_referrer_stats at all) — we
	 * still include the key so the template can render an empty-state card
	 * for consistency.
	 *
	 * @param array<int, array{host:string,pageviews:int,visitors:int,delta:int|null}> $rows
	 * @return array<string, array{rows: array<int, array{host:string,pageviews:int,visitors:int,delta:int|null}>, pageviews:int}>
	 */
	/**
	 * Direct traffic for a range: everything the site served that no referrer
	 * claims. It gets its own query because it cannot be derived from the top-N
	 * referrer rows the dashboard renders (subtracting only the ten shown would
	 * count every long-tail referrer as direct).
	 *
	 * Visitors are approximate: unique counts don't subtract cleanly across
	 * tables. Pageviews are exact.
	 *
	 * @return array{pageviews:int, visitors:int}
	 */
	public function get_direct_totals( string $start, string $end, ?Filters $filters = null ): array {
		// Direct under the active filters: the site, or the page, minus what
		// referrers claim. A referrer-host filter leaves nothing direct, and a
		// device or country filter cannot be answered at all.
		$direct         = $filters instanceof Filters ? clone $filters : new Filters();
		$direct->source = 'direct';

		$cache_key = self::cache_key( 'direct', $start, $end, false, $direct->cache_fragment() );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$result = $direct->supports( 'totals' )
			? $this->filtered_site_totals( $start, $end, $direct )
			: [ 'pageviews' => 0, 'visitors' => 0 ];

		set_transient( $cache_key, $result, self::CACHE_TTL );
		return $result;
	}

	/**
	 * Pageviews and visitors per referrer bucket over the whole range, so the
	 * Top referrers group headers count every host and not just the ten listed.
	 * One GROUP BY over Filters::bucket_case(), the fragments the source filter
	 * runs, so a header always equals its filter's total.
	 *
	 * Referrer stats carry no device or country, so, as for the rows, only the
	 * source filter applies: it keeps its own bucket and zeroes the others.
	 *
	 * @return array<string, array{pageviews:int, visitors:int}>
	 */
	public function get_referrer_bucket_totals( string $start, string $end, ?Filters $filters = null ): array {
		$empty = [
			'pageviews' => 0,
			'visitors'  => 0,
		];
		$out   = array_fill_keys( [ 'search', 'ai', 'social', 'referral' ], $empty );

		$source = $filters instanceof Filters ? $filters->source : '';
		if ( '' !== $source && ! isset( $out[ $source ] ) ) {
			return $out;
		}
		if ( $filters instanceof Filters && ! $filters->supports( 'referrers' ) ) {
			return $out;
		}

		// The rows the page and referrer filters select, with every bucket in
		// them; the source filter is applied to the grouped result below.
		$scope         = $filters instanceof Filters ? clone $filters : new Filters();
		$scope->source = '';
		$q             = $scope->referrer_query();

		$cache_key = self::cache_key( 'ref_buckets', $start, $end, $scope->cache_fragment() );
		$rows      = get_transient( $cache_key );
		if ( ! is_array( $rows ) ) {
			global $wpdb;

			[ $case, $case_args ] = Filters::bucket_case( 'u.url' );

			$sql  = 'SELECT ' . $case . ' AS bucket,
			        COALESCE(SUM(s.pageviews),0) AS pageviews,
			        COALESCE(SUM(s.visitors),0)  AS visitors
			 FROM ' . $q['from'] . '
			 WHERE s.date BETWEEN %s AND %s' . $q['where'] . '
			 GROUP BY bucket';
			$args = array_merge( $case_args, $q['tables'], [ $start, $end ], $q['args'] );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- transient-cached; $case is built from Filters::source_where(), whose placeholders are bound in $case_args
			$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
			set_transient( $cache_key, $rows, self::CACHE_TTL );
		}

		foreach ( $rows as $row ) {
			$bucket = (string) ( $row['bucket'] ?? '' );
			if ( isset( $out[ $bucket ] ) && ( '' === $source || $source === $bucket ) ) {
				$out[ $bucket ] = [
					'pageviews' => (int) $row['pageviews'],
					'visitors'  => (int) $row['visitors'],
				];
			}
		}

		return $out;
	}

	/**
	 * @param array<int,array>                                     $rows   The listed rows.
	 * @param array{pageviews:int,visitors:int}|null               $direct Direct-traffic totals.
	 *        Direct has no referrer host, so it can never arrive as a row: it is
	 *        passed in, or the bucket renders the 0 it used to be stuck at.
	 * @param array<string,array{pageviews:int,visitors:int}>|null $totals Whole-range
	 *        totals per bucket from get_referrer_bucket_totals(). Without them a
	 *        header can only sum the rows it was handed.
	 */
	public static function group_referrers_by_source( array $rows, ?array $direct = null, ?array $totals = null ): array {
		$groups = [
			'direct'   => [ 'rows' => [], 'pageviews' => 0 ],
			'search'   => [ 'rows' => [], 'pageviews' => 0 ],
			'ai'       => [ 'rows' => [], 'pageviews' => 0 ],
			'social'   => [ 'rows' => [], 'pageviews' => 0 ],
			'referral' => [ 'rows' => [], 'pageviews' => 0 ],
		];

		if ( null !== $direct ) {
			$groups['direct']['pageviews'] = (int) ( $direct['pageviews'] ?? 0 );
			$groups['direct']['visitors']  = (int) ( $direct['visitors'] ?? 0 );
		}

		foreach ( $rows as $row ) {
			$host = (string) ( $row['host'] ?? '' );
			if ( '' === $host ) {
				continue;
			}
			$info = vireo_classify_source( $host );
			$type = $info['type'];
			if ( ! isset( $groups[ $type ] ) ) {
				$type = 'referral';
			}
			$groups[ $type ]['rows'][]      = $row;
			$groups[ $type ]['pageviews']  += (int) ( $row['pageviews'] ?? 0 );
		}

		if ( null !== $totals ) {
			foreach ( [ 'search', 'ai', 'social', 'referral' ] as $type ) {
				$groups[ $type ]['pageviews'] = (int) ( $totals[ $type ]['pageviews'] ?? 0 );
				$groups[ $type ]['visitors']  = (int) ( $totals[ $type ]['visitors'] ?? 0 );
			}
		}

		return $groups;
	}

	/**
	 * @return array<int, array{code:string,name:string,pageviews:int,visitors:int,delta:int|null}>
	 */
	public function get_countries( string $start, string $end, int $limit = 10, bool $with_compare = false, ?Filters $filters = null ): array {
		$filter_frag = $filters ? $filters->cache_fragment() : 'none';
		$cache_key   = self::cache_key( 'geo', $start, $end, $with_compare, $limit, $filter_frag );
		$cached      = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		if ( $filters instanceof Filters && ! $filters->supports( 'countries' ) ) {
			return [];
		}

		global $wpdb;
		$table = Sites::table( 'geo_stats' );

		// Geo stats carry nothing but the country, so the only filter that
		// applies is the country itself: the list is then that one row.
		$where = '';
		$wargs = [];
		if ( $filters instanceof Filters && '' !== $filters->country ) {
			$where = ' AND country_code = %s';
			$wargs = [ $filters->country ];
		}

		// An empty code is a visit whose country was never resolved, not a
		// country: imported history carries none, and a lookup can fail. It
		// is never ranked; Country_Coverage says how much of the range has one.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- transient-cached above; $where is a literal with its value bound in the args
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT country_code,
				        SUM(pageviews) AS pageviews,
				        SUM(visitors)  AS visitors
				 FROM %i
				 WHERE date BETWEEN %s AND %s AND country_code <> %s AND country_code <> %s' . $where . '
				 GROUP BY country_code
				 ORDER BY visitors DESC
				 LIMIT %d',
				array_merge( [ $table, $start, $end, '', 'XX' ], $wargs, [ $limit ] )
			),
			ARRAY_A
		);

		$prior_by_cc = [];
		if ( $with_compare ) {
			[ $ps, $pe ] = self::compare_range( $start, $end );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- transient-cached above; $where is a literal with its value bound in the args
			$prior_rows  = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT country_code, SUM(visitors) AS visitors
					 FROM %i
					 WHERE date BETWEEN %s AND %s' . $where . '
					 GROUP BY country_code',
					array_merge( [ $table, $ps, $pe ], $wargs )
				),
				ARRAY_A
			);
			foreach ( (array) $prior_rows as $pr ) {
				$prior_by_cc[ strtoupper( (string) $pr['country_code'] ) ] = (int) $pr['visitors'];
			}
		}

		$out = [];
		foreach ( (array) $rows as $r ) {
			$code  = strtoupper( (string) $r['country_code'] );
			$cur   = (int) $r['visitors'];
			$prior = $with_compare ? (int) ( $prior_by_cc[ $code ] ?? 0 ) : null;
			$delta = $with_compare ? ( $cur - (int) $prior ) : null;
			$out[] = [
				'code'      => $code,
				'name'      => self::country_name( $code ),
				'pageviews' => (int) $r['pageviews'],
				'visitors'  => $cur,
				'delta'     => $delta,
				'prior'     => $prior,
			];
		}

		set_transient( $cache_key, $out, self::CACHE_TTL );
		return $out;
	}

	/**
	 * Browsers / OS / device types for the period.
	 *
	 * @return array{
	 *   browsers: array<int, array{label:string,visitors:int,pct:float,delta:int|null}>,
	 *   os:       array<int, array{label:string,visitors:int,pct:float,delta:int|null}>,
	 *   devices:  array<int, array{label:string,visitors:int,pct:float,delta:int|null}>
	 * }
	 */
	/**
	 * Top 404 paths for the Overview card.
	 *
	 * @return array{rows: array<int,object>, prior_by_path: array<string,int>}
	 */
	public function get_404_rows( string $start, string $end, int $limit = 10, bool $with_compare = false ): array {
		global $wpdb;
		$table = Sites::table( '404_stats' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- schema probe, uncacheable
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return [ 'rows' => [], 'prior_by_path' => [] ];
		}
		// Older installs predate the internal_ref column; without it every row
		// looks like a probe, so fall back to the flat report rather than
		// showing an empty "broken links" section that is merely uninformed.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- schema probe
		$has_internal = (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'internal_ref' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- short-lived admin query
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT path, SUM(hits) AS hits,
			        SUBSTRING_INDEX(GROUP_CONCAT(referrer ORDER BY hits DESC SEPARATOR '|'), '|', 1) AS top_referrer,
			        MAX(hits) AS referred_hits
			 FROM %i WHERE date BETWEEN %s AND %s
			 GROUP BY path ORDER BY hits DESC LIMIT %d",
			$table, $start, $end, $limit
		) );

		// The split that makes this report usable. A 404 reached from one of
		// your own pages is a link you can go and fix, and the referring page
		// is the fix location. Everything else is the internet knocking: a
		// stranger's link, or far more often a vulnerability scanner walking a
		// wordlist. Same table, two different jobs.
		$broken = [];
		$probes = [];
		if ( $has_internal ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- short-lived admin query
			$broken = (array) $wpdb->get_results( $wpdb->prepare(
				"SELECT path, SUM(hits) AS hits,
				        SUBSTRING_INDEX(GROUP_CONCAT(internal_ref ORDER BY hits DESC SEPARATOR '|'), '|', 1) AS linked_from,
				        COUNT(DISTINCT internal_ref) AS linking_pages
				 FROM %i
				 WHERE date BETWEEN %s AND %s AND internal_ref <> ''
				 GROUP BY path ORDER BY hits DESC LIMIT %d",
				$table, $start, $end, $limit
			) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- short-lived admin query
			$probes = (array) $wpdb->get_results( $wpdb->prepare(
				"SELECT path, SUM(hits) AS hits,
				        SUBSTRING_INDEX(GROUP_CONCAT(referrer ORDER BY hits DESC SEPARATOR '|'), '|', 1) AS top_referrer
				 FROM %i
				 WHERE date BETWEEN %s AND %s AND internal_ref = ''
				 GROUP BY path ORDER BY hits DESC LIMIT %d",
				$table, $start, $end, $limit
			) );
		}
		$prior = [];
		if ( $with_compare ) {
			[ $ps, $pe ] = self::compare_range( $start, $end );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- short-lived admin query
			$p_rows = $wpdb->get_results( $wpdb->prepare(
				'SELECT path, SUM(hits) AS hits FROM %i WHERE date BETWEEN %s AND %s GROUP BY path',
				$table, $ps, $pe
			) );
			foreach ( $p_rows as $r ) {
				$prior[ (string) $r->path ] = (int) $r->hits;
			}
		}
		return [
			'rows'          => $rows ?: [],
			'prior_by_path' => $prior,
			'broken'        => $broken,
			'probes'        => $probes,
			'split'         => $has_internal,
		];
	}

	public function get_devices( string $start, string $end, bool $with_compare = false, ?Filters $filters = null ): array {
		$filter_frag = $filters ? $filters->cache_fragment() : 'none';
		$cache_key   = self::cache_key( 'dev', $start, $end, $with_compare, $filter_frag );
		$cached      = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		if ( $filters instanceof Filters && ! $filters->supports( 'devices' ) ) {
			return [ 'browsers' => [], 'os' => [], 'devices' => [] ];
		}

		global $wpdb;
		$table = Sites::table( 'device_stats' );

		// The device table carries browser, OS and device type together, so a
		// device filter narrows browsers and systems to that device type too.
		$device = $filters instanceof Filters ? $filters->device : '';

		$browsers = $this->device_slice( $table, 'browser', $start, $end, $device );
		$os       = $this->device_slice( $table, 'os', $start, $end, $device );
		$devices  = $this->device_slice( $table, 'device_type', $start, $end, $device );

		$prior = [ 'browser' => [], 'os' => [], 'device_type' => [] ];
		if ( $with_compare ) {
			[ $ps, $pe ] = self::compare_range( $start, $end );
			foreach ( array_keys( $prior ) as $col ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- transient-cached above; the device clause is a literal with its value bound in the args
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT %i AS label, SUM(visitors) AS visitors
						 FROM %i
						 WHERE date BETWEEN %s AND %s' . ( '' !== $device ? ' AND device_type = %s' : '' ) . '
						 GROUP BY %i',
						array_merge( [ $col, $table, $ps, $pe ], '' !== $device ? [ $device ] : [], [ $col ] )
					),
					ARRAY_A
				);
				foreach ( (array) $rows as $r ) {
					$prior[ $col ][ (string) $r['label'] ] = (int) $r['visitors'];
				}
			}
		}

		// These cards show a SHARE ("Chrome, 58.2% of visitors"), so the change that
		// belongs next to them is the change in that share, in percentage points.
		// They used to show the raw visitor delta instead, so a row could read
		// "Chrome  -1,568  58.2%", which invites reading it as a 1,568-point drop
		// in share. The visitor delta moves to the tooltip, where it can't mislead.
		$decorate = static function ( array $rows, array $prior_map ) use ( $with_compare ): array {
			$total       = 0;
			$prior_total = 0;
			foreach ( $rows as $r ) {
				$total += (int) $r['visitors'];
			}
			foreach ( $prior_map as $pv ) {
				$prior_total += (int) $pv;
			}

			$out = [];
			foreach ( $rows as $r ) {
				$label = (string) $r['label'];
				$v     = (int) $r['visitors'];
				$pct   = $total > 0 ? round( $v / $total * 100, 1 ) : 0.0;

				$pp_delta = null;
				$delta    = null;
				if ( $with_compare ) {
					$prior_v   = (int) ( $prior_map[ $label ] ?? 0 );
					$delta     = $v - $prior_v;
					$prior_pct = $prior_total > 0 ? round( $prior_v / $prior_total * 100, 1 ) : 0.0;
					$pp_delta  = round( $pct - $prior_pct, 1 );
				}

				$out[] = [
					'label'    => '' === $label ? __( 'Unknown', 'vireo-analytics' ) : $label,
					'visitors' => $v,
					'pct'      => $pct,
					'delta'    => $delta,
					'pp_delta' => $pp_delta,
				];
			}
			return $out;
		};

		// Device types are stored lowercase ("desktop"), unlike browsers and operating
		// systems which arrive already capitalised. Left alone they read as a typo in
		// the card, and worse in a sentence: "desktop lost 16pp of the mix."
		$device_rows = $decorate( $devices, $prior['device_type'] );
		foreach ( $device_rows as &$row ) {
			// The stored value stays as `type`: it is what a device filter takes.
			$row['type']  = (string) $row['label'];
			$row['label'] = self::device_label( (string) $row['label'] );
		}
		unset( $row );

		$result = [
			'browsers' => $decorate( $browsers, $prior['browser'] ),
			'os'       => $decorate( $os, $prior['os'] ),
			'devices'  => $device_rows,
		];

		set_transient( $cache_key, $result, self::CACHE_TTL );
		return $result;
	}

	/**
	 * @return array<int, array{label:string,visitors:int}>
	 */
	private function device_slice( string $table, string $column, string $start, string $end, string $device = '' ): array {
		global $wpdb;
		// $column is hard-coded from a whitelist in the caller — %i placeholder
		// still gives us identifier-escaping for free.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- caller transient-caches the wider result; the device clause is a literal with its value bound in the args
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT %i AS label, SUM(visitors) AS visitors
				 FROM %i
				 WHERE date BETWEEN %s AND %s' . ( '' !== $device ? ' AND device_type = %s' : '' ) . '
				 GROUP BY %i
				 ORDER BY visitors DESC
				 LIMIT 10',
				array_merge( [ $column, $table, $start, $end ], '' !== $device ? [ $device ] : [], [ $column ] )
			),
			ARRAY_A
		);
		$out = [];
		foreach ( (array) $rows as $r ) {
			$out[] = [
				'label'    => (string) $r['label'],
				'visitors' => (int) $r['visitors'],
			];
		}
		return $out;
	}

	// ---------------------------------------------------------------------
	// Cache helpers
	// ---------------------------------------------------------------------

	private static function cache_key( string $kind, ...$parts ): string {
		// The site in context is part of every key, or a connected site's
		// dashboard would serve this site's cached numbers for half a minute.
		array_unshift( $parts, 's' . Sites::current() );
		// So is the language: some cached rows carry translated labels (Home,
		// country names), and a German admin's cache leaked into English.
		$parts[] = determine_locale();
		// And what the range is compared with: the prior figures inside a cached
		// result belong to one mode, and switching to last year served the
		// period before for half a minute.
		$parts[] = self::compare_mode();
		return self::CACHE_GROUP . $kind . '_' . md5( implode( '|', array_map( 'strval', $parts ) ) );
	}

	private static function days_between( string $start, string $end ): int {
		$s = strtotime( $start . ' 00:00:00 UTC' );
		$e = strtotime( $end . ' 00:00:00 UTC' );
		if ( ! $s || ! $e || $e < $s ) {
			return 0;
		}
		return (int) ( ( ( $e - $s ) / DAY_IN_SECONDS ) + 1 );
	}

	// ---------------------------------------------------------------------
	// Delta chip helper (used by templates)
	// ---------------------------------------------------------------------

	/**
	 * Render a delta chip. Returns '' when compare is disabled or there's no
	 * meaningful change to show. $direction 'up_good' is the default; pass
	 * 'up_bad' for metrics where a rise is bad (bounce rate, etc).
	 */
	/**
	 * Chip for a metric that is itself a share of a total (device, browser, OS).
	 * Reports the move in percentage points, so "Mobile +3.1 pp" means mobile took
	 * 3.1 more points of the mix, which is the only reading that makes sense next
	 * to a share bar.
	 *
	 * @param float|null $pp    Change in percentage points.
	 * @param int|null   $delta Underlying visitor change, shown on hover.
	 */
	/**
	 * Display name for a stored device type. Falls back to the raw value so an
	 * unrecognised type still shows something rather than nothing.
	 */
	public static function device_label( string $type ): string {
		switch ( strtolower( $type ) ) {
			case 'desktop':
				return __( 'Desktop', 'vireo-analytics' );
			case 'mobile':
				return __( 'Mobile', 'vireo-analytics' );
			case 'tablet':
				return __( 'Tablet', 'vireo-analytics' );
		}
		return '' === $type ? __( 'Unknown', 'vireo-analytics' ) : ucfirst( $type );
	}

	public static function delta_chip_pp( ?float $pp, ?int $delta = null, string $direction = 'up_good' ): string {
		if ( null === $pp ) {
			return '';
		}
		// Sub-0.1pp moves are noise on a share bar; don't shout about them.
		if ( abs( $pp ) < 0.1 ) {
			return '';
		}

		$up   = $pp > 0;
		// A rising bounce rate is the bad direction, as on delta_chip().
		$cls  = ( 'up_bad' === $direction ? ! $up : $up ) ? 'va-chip-up' : 'va-chip-down';
		// The arrow carries the sign, as on delta_chip().
		$text = number_format_i18n( abs( $pp ), 1 ) . ' pp';

		$title = ( null !== $delta )
			? sprintf(
				/* translators: %s: signed change in visitors */
				_n( '%s visitor vs the prior period', '%s visitors vs the prior period', (int) abs( $delta ), 'vireo-analytics' ),
				( $delta > 0 ? '+' : '' ) . number_format_i18n( $delta )
			)
			: '';

		return sprintf(
			'<span class="va-chip %s"%s>%s<span>%s</span></span>',
			esc_attr( $cls ),
			'' !== $title ? ' title="' . esc_attr( $title ) . '"' : '',
			$up ? self::arrow_svg( 'up' ) : self::arrow_svg( 'down' ),
			esc_html( $text )
		);
	}

	public static function delta_chip( ?int $delta, ?int $prior = null, string $direction = 'up_good' ): string {
		if ( null === $delta ) {
			return '';
		}
		if ( 0 === $delta && ( null === $prior || 0 === $prior ) ) {
			return '';
		}

		// Percentage if we have a prior baseline; otherwise show raw signed count.
		if ( null !== $prior && $prior > 0 ) {
			$pct = (int) round( ( $delta / $prior ) * 100 );
			// A change too small to show as a whole percent is no change on
			// this chip. Reading "up" off the rounded figure while still drawing
			// it turned 3 views out of 1,400, either way, into a red "0%".
			if ( 0 === $pct ) {
				return '';
			}
			// The arrow is the sign. "↑+17%" said it twice, and "↓-17%" read as
			// a double negative; the signed figure stays in the title.
			$text = abs( $pct ) . '%';
			$up   = $pct > 0;
		} elseif ( null !== $prior && 0 === $prior && 0 !== $delta ) {
			return '<span class="va-chip va-chip-new" title="' . esc_attr__( 'No prior-period data', 'vireo-analytics' ) . '">' . esc_html__( 'new', 'vireo-analytics' ) . '</span>';
		} else {
			$text = number_format_i18n( abs( $delta ) );
			$up   = $delta > 0;
		}

		if ( 'up_bad' === $direction ) {
			$cls = $up ? 'va-chip-down' : 'va-chip-up';
		} else {
			$cls = $up ? 'va-chip-up' : 'va-chip-down';
		}
		$arrow = $up ? self::arrow_svg( 'up' ) : self::arrow_svg( 'down' );
		$title = ( null !== $prior )
			? sprintf(
				/* translators: 1: signed change, 2: prior-period value */
				__( '%1$s vs %2$s in the prior period', 'vireo-analytics' ),
				( $delta > 0 ? '+' : '' ) . number_format_i18n( $delta ),
				number_format_i18n( $prior )
			)
			: '';
		return sprintf(
			'<span class="va-chip %s"%s>%s<span>%s</span></span>',
			esc_attr( $cls ),
			'' !== $title ? ' title="' . esc_attr( $title ) . '"' : '',
			$arrow,
			esc_html( $text )
		);
	}

	/**
	 * The arrow on a change chip.
	 *
	 * A text glyph rather than an inline SVG: most chips reach the page through
	 * wp_kses_post(), which strips <svg>, so the hero, countries, devices, 404
	 * and referrer chips never showed their arrow and leaned on a "+" instead,
	 * while cards that echoed the chip raw showed both. The glyph survives
	 * kses, so every chip now reads arrow then figure.
	 */
	public static function arrow_svg( string $dir ): string {
		if ( 'up' === $dir ) {
			return '<span class="va-arrow va-arrow-up" aria-hidden="true">&#8593;</span>';
		}
		if ( 'down' === $dir ) {
			return '<span class="va-arrow va-arrow-down" aria-hidden="true">&#8595;</span>';
		}
		return '';
	}

	/**
	 * The name a page is listed under: its title, else its path, with the front
	 * page as "Home" rather than "/". Top pages, "What changed" and the weekly
	 * email all use it, so one page has one name.
	 */
	/**
	 * Title and permalink for a stored post ID, when the post is one a visitor
	 * could see.
	 *
	 * Stored IDs came from the visitor's browser, and before 0.9.1 any existing
	 * post was accepted, so a row can point at a draft, a private or scheduled
	 * post, or a reusable block. Those come back empty, and every renderer then
	 * shows the path with no link, the same as for a deleted post.
	 *
	 * @return array{title:string,url:string}
	 */
	public static function public_post( int $post_id ): array {
		if ( $post_id <= 0 || ! is_post_publicly_viewable( $post_id ) ) {
			return [ 'title' => '', 'url' => '' ];
		}
		$link = get_permalink( $post_id );
		return [
			'title' => (string) get_the_title( $post_id ),
			'url'   => is_string( $link ) ? $link : '',
		];
	}

	public static function page_label( string $title, string $path ): string {
		if ( function_exists( 'vireo_page_label' ) ) {
			return vireo_page_label( $title, $path );
		}
		// The zero-dependency runners load this class on its own.
		if ( '/' === $path && ( '' === $title || '/' === $title ) ) {
			return __( 'Home', 'vireo-analytics' );
		}
		return '' !== $title ? $title : $path;
	}

	/**
	 * Abbreviated integer (matches JS abbrev()): 1.2k, 12k, 1.2M.
	 */
	public static function abbrev( int $n ): string {
		// One decimal in the site's own notation ("1,2k" in German), dropped
		// when it's zero. The bare number_format() it replaced always used a dot.
		$short = static function ( float $v ): string {
			$r = round( $v, 1 );
			return floor( $r ) === $r ? number_format_i18n( $r ) : number_format_i18n( $r, 1 );
		};
		if ( $n >= 1_000_000 ) {
			return $short( $n / 1_000_000 ) . 'M';
		}
		if ( $n >= 10_000 ) {
			return (int) round( $n / 1000 ) . 'k';
		}
		if ( $n >= 1000 ) {
			return $short( $n / 1000 ) . 'k';
		}
		return number_format_i18n( $n );
	}

	// ---------------------------------------------------------------------
	// SVG icon helpers
	// ---------------------------------------------------------------------

	public static function browser_icon( string $label ): string {
		$key    = strtolower( trim( $label ) );
		$common = 'xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" width="16" height="16" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"';

		if ( false !== strpos( $key, 'chrome' ) ) {
			return '<svg ' . $common . '><circle cx="8" cy="8" r="6.25"/><circle cx="8" cy="8" r="2.25"/><path d="M8 5.75H14M5.9 7 3 2.2M10.1 9 6.7 13.7"/></svg>';
		}
		if ( false !== strpos( $key, 'firefox' ) ) {
			return '<svg ' . $common . '><circle cx="8" cy="8" r="6.25"/><path d="M4.25 5.5c1.2-1.6 3-2.25 4.75-1.75M11.75 10.5c-1.2 1.6-3 2.25-4.75 1.75"/><circle cx="9.5" cy="7" r="1"/></svg>';
		}
		if ( false !== strpos( $key, 'safari' ) ) {
			return '<svg ' . $common . '><circle cx="8" cy="8" r="6.25"/><path d="m10.5 5.5-1.75 4.25-4.25 1.75 1.75-4.25 4.25-1.75Z"/></svg>';
		}
		if ( false !== strpos( $key, 'edge' ) ) {
			return '<svg ' . $common . '><circle cx="8" cy="8" r="6.25"/><path d="M2.75 9.5c2-3.75 8-3.75 10.5-1M13.25 7.25c-.25 2.5-2.25 4.5-5 5"/></svg>';
		}
		if ( false !== strpos( $key, 'opera' ) ) {
			return '<svg ' . $common . '><circle cx="8" cy="8" r="6.25"/><ellipse cx="8" cy="8" rx="2.5" ry="4.25"/></svg>';
		}
		if ( false !== strpos( $key, 'brave' ) ) {
			return '<svg ' . $common . '><path d="M8 2.5 11.75 4l1.5 3-1 4.5-3 2.5-1.25.5-1.25-.5-3-2.5-1-4.5 1.5-3L8 2.5Z"/><path d="M8 6v3.25"/></svg>';
		}
		return '<svg ' . $common . '><circle cx="8" cy="8" r="6.25"/><path d="M2 8h12M8 2c2 2 2.5 4 2.5 6s-.5 4-2.5 6c-2-2-2.5-4-2.5-6S6 4 8 2Z"/></svg>';
	}

	public static function os_icon( string $label ): string {
		$key    = strtolower( trim( $label ) );
		$common = 'xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" width="16" height="16" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"';

		if ( false !== strpos( $key, 'windows' ) ) {
			return '<svg ' . $common . '><path d="M2.5 3.5 7 2.75V7.5H2.5V3.5ZM8 2.6l5.5-.85V7.5H8V2.6ZM2.5 8.5H7v4L2.5 11.75V8.5ZM8 8.5h5.5v4.75L8 12.4V8.5Z"/></svg>';
		}
		if ( false !== strpos( $key, 'mac' ) || false !== strpos( $key, 'osx' ) || false !== strpos( $key, 'os x' ) ) {
			return '<svg ' . $common . '><path d="M10.5 5.5c-.7 0-1.5.5-2 .5s-1.3-.5-2-.5c-1.25 0-2.75 1.25-2.75 3.5s1.5 4.25 2.5 4.25c.75 0 1-.4 1.75-.4s1 .4 1.75.4c1 0 2.5-1.5 2.5-3 0-.5-.75-1-1.25-1.5-.5-.5-.5-1.25 0-1.75.75-.5-.25-1.5-.5-1.5ZM9 4.5c.5-.5 1-1 1-2"/></svg>';
		}
		if ( false !== strpos( $key, 'ios' ) || false !== strpos( $key, 'iphone' ) || false !== strpos( $key, 'ipad' ) ) {
			return '<svg ' . $common . '><rect x="4.75" y="1.75" width="6.5" height="12.5" rx="1.5"/><path d="M7 12.5h2"/></svg>';
		}
		if ( false !== strpos( $key, 'android' ) ) {
			return '<svg ' . $common . '><path d="M3.5 7.25c0-2.5 2-4.5 4.5-4.5s4.5 2 4.5 4.5v.5h-9v-.5ZM5.25 4 4 2.5M10.75 4 12 2.5M6 5.75v.5M10 5.75v.5"/><rect x="3.5" y="8.5" width="9" height="5" rx="1"/></svg>';
		}
		if ( false !== strpos( $key, 'linux' ) || false !== strpos( $key, 'ubuntu' ) ) {
			return '<svg ' . $common . '><path d="M8 2.5c1.5 0 2.5 1.5 2.5 3.5 0 1-.5 2-1 2.5.5 1 2 2 2 3.5 0 1-1 1.5-3.5 1.5s-3.5-.5-3.5-1.5c0-1.5 1.5-2.5 2-3.5-.5-.5-1-1.5-1-2.5 0-2 1-3.5 2.5-3.5Z"/><circle cx="7" cy="5.75" r=".5" fill="currentColor"/><circle cx="9" cy="5.75" r=".5" fill="currentColor"/></svg>';
		}
		if ( false !== strpos( $key, 'chromeos' ) || false !== strpos( $key, 'chrome os' ) ) {
			return '<svg ' . $common . '><circle cx="8" cy="8" r="6.25"/><circle cx="8" cy="8" r="2.25"/></svg>';
		}
		return '<svg ' . $common . '><rect x="1.75" y="2.75" width="12.5" height="9" rx="1.25"/><path d="M5.5 14.25h5M8 11.75v2.5"/></svg>';
	}

	public static function device_icon( string $label ): string {
		$key    = strtolower( trim( $label ) );
		$common = 'xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" width="16" height="16" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"';

		if ( false !== strpos( $key, 'mobile' ) || false !== strpos( $key, 'phone' ) ) {
			return '<svg ' . $common . '><rect x="4.75" y="1.75" width="6.5" height="12.5" rx="1.5"/><path d="M7 12.5h2"/></svg>';
		}
		if ( false !== strpos( $key, 'tablet' ) ) {
			return '<svg ' . $common . '><rect x="2.75" y="1.75" width="10.5" height="12.5" rx="1.5"/><path d="M7 12.25h2"/></svg>';
		}
		if ( false !== strpos( $key, 'desktop' ) || false !== strpos( $key, 'pc' ) ) {
			return '<svg ' . $common . '><rect x="1.75" y="2.75" width="12.5" height="9" rx="1.25"/><path d="M5.5 14.25h5M8 11.75v2.5"/></svg>';
		}
		return '<svg ' . $common . '><rect x="1.75" y="2.75" width="12.5" height="9" rx="1.25"/><path d="M5.5 14.25h5M8 11.75v2.5"/></svg>';
	}

	/**
	 * ISO 3166-1 alpha-2 → human country name. Returns the raw code for
	 * unknown values so the UI still has *something* to show.
	 */
	public static function country_name( string $code ): string {
		static $countries = null;
		if ( null === $countries ) {
			$countries = [
				'AD' => 'Andorra', 'AE' => 'UAE', 'AF' => 'Afghanistan', 'AG' => 'Antigua & Barbuda',
				'AL' => 'Albania', 'AM' => 'Armenia', 'AO' => 'Angola', 'AR' => 'Argentina',
				'AT' => 'Austria', 'AU' => 'Australia', 'AZ' => 'Azerbaijan', 'BA' => 'Bosnia',
				'BB' => 'Barbados', 'BD' => 'Bangladesh', 'BE' => 'Belgium', 'BG' => 'Bulgaria',
				'BH' => 'Bahrain', 'BN' => 'Brunei', 'BO' => 'Bolivia', 'BR' => 'Brazil',
				'BS' => 'Bahamas', 'BT' => 'Bhutan', 'BW' => 'Botswana', 'BY' => 'Belarus',
				'BZ' => 'Belize', 'CA' => 'Canada', 'CD' => 'Congo (DRC)', 'CH' => 'Switzerland',
				'CL' => 'Chile', 'CM' => 'Cameroon', 'CN' => 'China', 'CO' => 'Colombia',
				'CR' => 'Costa Rica', 'CU' => 'Cuba', 'CY' => 'Cyprus', 'CZ' => 'Czechia',
				'DE' => 'Germany', 'DK' => 'Denmark', 'DO' => 'Dominican Rep.', 'DZ' => 'Algeria',
				'EC' => 'Ecuador', 'EE' => 'Estonia', 'EG' => 'Egypt', 'ES' => 'Spain',
				'ET' => 'Ethiopia', 'FI' => 'Finland', 'FJ' => 'Fiji', 'FR' => 'France',
				'GB' => 'United Kingdom', 'GE' => 'Georgia', 'GH' => 'Ghana', 'GR' => 'Greece',
				'GT' => 'Guatemala', 'HK' => 'Hong Kong', 'HN' => 'Honduras', 'HR' => 'Croatia',
				'HU' => 'Hungary', 'ID' => 'Indonesia', 'IE' => 'Ireland', 'IL' => 'Israel',
				'IN' => 'India', 'IQ' => 'Iraq', 'IR' => 'Iran', 'IS' => 'Iceland',
				'IT' => 'Italy', 'JM' => 'Jamaica', 'JO' => 'Jordan', 'JP' => 'Japan',
				'KE' => 'Kenya', 'KG' => 'Kyrgyzstan', 'KH' => 'Cambodia', 'KR' => 'South Korea',
				'KW' => 'Kuwait', 'KZ' => 'Kazakhstan', 'LA' => 'Laos', 'LB' => 'Lebanon',
				'LK' => 'Sri Lanka', 'LT' => 'Lithuania', 'LU' => 'Luxembourg', 'LV' => 'Latvia',
				'LY' => 'Libya', 'MA' => 'Morocco', 'MD' => 'Moldova', 'ME' => 'Montenegro',
				'MG' => 'Madagascar', 'MK' => 'North Macedonia', 'ML' => 'Mali', 'MM' => 'Myanmar',
				'MN' => 'Mongolia', 'MT' => 'Malta', 'MU' => 'Mauritius', 'MX' => 'Mexico',
				'MY' => 'Malaysia', 'MZ' => 'Mozambique', 'NA' => 'Namibia', 'NG' => 'Nigeria',
				'NI' => 'Nicaragua', 'NL' => 'Netherlands', 'NO' => 'Norway', 'NP' => 'Nepal',
				'NZ' => 'New Zealand', 'OM' => 'Oman', 'PA' => 'Panama', 'PE' => 'Peru',
				'PH' => 'Philippines', 'PK' => 'Pakistan', 'PL' => 'Poland', 'PR' => 'Puerto Rico',
				'PT' => 'Portugal', 'PY' => 'Paraguay', 'QA' => 'Qatar', 'RO' => 'Romania',
				'RS' => 'Serbia', 'RU' => 'Russia', 'RW' => 'Rwanda', 'SA' => 'Saudi Arabia',
				'SE' => 'Sweden', 'SG' => 'Singapore', 'SI' => 'Slovenia', 'SK' => 'Slovakia',
				'SN' => 'Senegal', 'SO' => 'Somalia', 'SV' => 'El Salvador', 'SY' => 'Syria',
				'TH' => 'Thailand', 'TN' => 'Tunisia', 'TR' => 'Turkey', 'TW' => 'Taiwan',
				'TZ' => 'Tanzania', 'UA' => 'Ukraine', 'UG' => 'Uganda', 'US' => 'United States',
				'UY' => 'Uruguay', 'UZ' => 'Uzbekistan', 'VE' => 'Venezuela', 'VN' => 'Vietnam',
				'YE' => 'Yemen', 'ZA' => 'South Africa', 'ZM' => 'Zambia', 'ZW' => 'Zimbabwe',
				'XX' => 'Unknown',
			];
		}
		return $countries[ $code ] ?? $code;
	}
}
