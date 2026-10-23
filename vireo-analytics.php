<?php
/**
 * Plugin Name: Vireo Analytics
 * Plugin URI: https://vireowp.com/
 * Description: Privacy-first WordPress analytics. Clean dashboard, no cookies, no consent banners, no third-party tracking.
 * Version: 0.17.0
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * Author: RebelCode
 * Author URI: https://rebelcode.com
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: vireo-analytics
 * Domain Path: /languages
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

define( 'VIREO_VERSION', '0.17.0' );

/**
 * Schema version, deliberately separate from the plugin version.
 *
 * The upgrade routine used to compare against VIREO_VERSION, which meant a
 * release that added a table only migrated if somebody also remembered to
 * bump the plugin version. Forget it once and every existing site runs new
 * code against an old schema: the writers insert into tables that are not
 * there, and because the rollups share one transaction that takes pageviews
 * down with them.
 *
 * Bump this whenever Installer::create_tables() changes. It has nothing to do
 * with what users see.
 */
define( 'VIREO_DB_VERSION', '13' );
define( 'VIREO_FILE', __FILE__ );
define( 'VIREO_DIR', plugin_dir_path( __FILE__ ) );
define( 'VIREO_URL', plugin_dir_url( __FILE__ ) );
define( 'VIREO_PRO', file_exists( __DIR__ . '/pro/loader.php' ) );

/**
 * Cross-plugin path helpers. When Pro ships as a separate plugin it still
 * needs shared paths from Free — the aggregator's buffer files live in
 * `wp-content/uploads/vireo-analytics/` so they survive plugin updates. Pro
 * resolves through these helpers rather than reaching into Free's internals.
 */
if ( ! function_exists( 'vireo_plugin_dir' ) ) {
	function vireo_plugin_dir(): string {
		return VIREO_DIR;
	}
}
if ( ! function_exists( 'vireo_plugin_url' ) ) {
	function vireo_plugin_url(): string {
		return VIREO_URL;
	}
}




// Shared filter / TZ helpers used by the tracker endpoint too; load early.
require_once __DIR__ . '/includes/va-filters.php';
require_once __DIR__ . '/includes/va-referrers.php';
require_once __DIR__ . '/includes/va-tz.php';
// Goal matcher — depends on Collector (loaded below in vireo_init). The functions
// are defined behind function_exists guards so loading order doesn't matter.
require_once __DIR__ . '/includes/va-goals-match.php';
// MCP server. Loaded here rather than in vireo_init() so Pro, which loads on
// plugins_loaded as well, finds the class whichever plugin WordPress ran first.
require_once __DIR__ . '/includes/class-mcp.php';

/**
 * Custom `vireo_every_minute` cron schedule. Registered unconditionally at
 * file-load time so it's available during activation (before our Aggregator
 * class is instantiated on plugins_loaded).
 *
 * The label is only translated once WordPress is ready to load a text
 * domain. WooCommerce's bundled Jetpack Connection calls wp_schedule_event()
 * during plugins_loaded, which runs this filter before after_setup_theme, and
 * a __() there makes WordPress log a "translation loading triggered too
 * early" notice on the first load after WooCommerce activates. The label only
 * ever shows in cron UIs, so the plain string loses nothing.
 */
add_filter(
	'cron_schedules',
	static function ( $schedules ): array {
		$schedules = (array) $schedules;
		if ( ! isset( $schedules['vireo_every_minute'] ) ) {
			$schedules['vireo_every_minute'] = [
				'interval' => 60,
				'display'  => did_action( 'after_setup_theme' )
					? __( 'Every minute (Vireo Analytics)', 'vireo-analytics' )
					: 'Every minute (Vireo Analytics)',
			];
		}
		return $schedules;
	}
);

/**
 * Bootstrap the plugin once WP has loaded. Guards against mid-deploy states
 * where an include file is briefly missing — we log once and bail rather than
 * fataling the whole admin.
 */
function vireo_init(): void {
	$includes = [
		'class-sites',
		'class-installer',
		'class-bot-filter',
		'class-bot-stats',
		'class-ai-sources',
		'class-ai-scan-robots-too-complex',
		'class-ai-scan-robots',
		'class-ai-scan-page-parser',
		'class-ai-scan-checks',
		'class-ai-scan',
		'class-integrations',
		'class-css-hash',
		'class-ua-parser',
		'class-geo-lookup',
		'class-endpoint-installer',
		'class-collector',
		'class-upserter',
		'class-aggregator',
		'class-salt-manager',
		'class-settings',
		'class-admin-bar',
		'class-post-columns',
		'class-csv-export',
		'class-email-reports',
		'class-dashboard',
		'class-screen-quiet',
		'class-dashboard-sections',
		'class-drivers',
		'class-insights',
		'class-dashboard-widget',
		'class-import-controller',
		'class-import-history',
		'class-404-tracker',
		'class-script-loader',
		'class-events',
		'class-filters',
		'class-saved-views',
		'class-post-detail',
		'class-referrer-detail',
		'class-retention',
		'class-event-detail',
		'class-rest-api',
		'class-abilities',
		'class-health',
		'class-vitals',
		'class-engagement-basics',
		'class-pages-report',
		'class-pagespeed',
		'class-consent',
		'class-roles',
		'class-annotations',
		'class-google-auth',
		'class-search-console',
		'class-network',
		'class-feedback',
		'class-mainwp',
		'class-year-review',
		'class-highlights',
		'class-referrer-spam',
		'class-pending',
		'class-partial-day',
		'class-automation',
		'class-country-coverage',
		'class-headless',
		'class-popular-posts',
		'class-popular-posts-widget',
		'class-comment-stats',
		'class-translations',
		'class-form-events',
		'class-backup',
	];

	static $missing_logged = [];
	foreach ( $includes as $slug ) {
		$path = VIREO_DIR . 'includes/' . $slug . '.php';
		if ( ! file_exists( $path ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && ! isset( $missing_logged[ $slug ] ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( "[vireo-analytics] Missing include: {$slug}.php — skipping init (likely mid-deploy)." );
				$missing_logged[ $slug ] = true;
			}
			return;
		}
	}

	foreach ( $includes as $slug ) {
		require_once VIREO_DIR . 'includes/' . $slug . '.php';
	}

	$required_classes = [
		'VireoAnalytics\\Sites',
		'VireoAnalytics\\Installer',
		'VireoAnalytics\\Bot_Filter',
		'VireoAnalytics\\Bot_Stats',
		'VireoAnalytics\\AI_Sources',
		'VireoAnalytics\\CSS_Hash',
		'VireoAnalytics\\UA_Parser',
		'VireoAnalytics\\Geo_Lookup',
		'VireoAnalytics\\Endpoint_Installer',
		'VireoAnalytics\\Collector',
		'VireoAnalytics\\Upserter',
		'VireoAnalytics\\Aggregator',
		'VireoAnalytics\\Salt_Manager',
		'VireoAnalytics\\Settings',
		'VireoAnalytics\\Admin_Bar',
		'VireoAnalytics\\Post_Columns',
		'VireoAnalytics\\CSV_Export',
		'VireoAnalytics\\Email_Reports',
		'VireoAnalytics\\Dashboard',
		'VireoAnalytics\\Dashboard_Sections',
		'VireoAnalytics\\Drivers',
		'VireoAnalytics\\Insights',
		'VireoAnalytics\\Dashboard_Widget',
		'VireoAnalytics\\Import_Controller',
		'VireoAnalytics\\Import_History',
		'VireoAnalytics\\NotFound_Tracker',
		'VireoAnalytics\\Script_Loader',
		'VireoAnalytics\\Events',
		'VireoAnalytics\\Filters',
		'VireoAnalytics\\Post_Detail',
		'VireoAnalytics\\Referrer_Detail',
		'VireoAnalytics\\Retention',
		'VireoAnalytics\\Event_Detail',
		'VireoAnalytics\\REST_API',
		'VireoAnalytics\\Abilities',
		'VireoAnalytics\\Health',
		'VireoAnalytics\\Vitals',
		'VireoAnalytics\\Engagement_Basics',
		'VireoAnalytics\\PageSpeed',
		'VireoAnalytics\\Consent',
		'VireoAnalytics\\Roles',
		'VireoAnalytics\\Annotations',
		'VireoAnalytics\\Google_Auth',
		'VireoAnalytics\\Search_Console',
		'VireoAnalytics\\Network',
		'VireoAnalytics\\Feedback',
		'VireoAnalytics\\MainWP',
		'VireoAnalytics\\Year_Review',
		'VireoAnalytics\\Highlights',
		'VireoAnalytics\\Headless',
		'VireoAnalytics\\Popular_Posts',
		'VireoAnalytics\\Popular_Posts_Widget',
		'VireoAnalytics\\Comment_Stats',
		'VireoAnalytics\\Form_Events',
		'VireoAnalytics\\Backup',
	];
	static $class_logged = [];
	foreach ( $required_classes as $cls ) {
		if ( ! class_exists( $cls ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && ! isset( $class_logged[ $cls ] ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( "[vireo-analytics] Missing class: {$cls} — skipping init." );
				$class_logged[ $cls ] = true;
			}
			return;
		}
	}

	// Upgrade path — pick up new tables added after initial activation without
	// forcing a deactivate/reactivate. Admin-only so we don't take the extra
	// hit on every frontend request; the check is a single get_option() when
	// versions already match.
	//
	// The exception is a site that has never been installed at all, which on a
	// network happens to every sub-site the activation loop did not reach and
	// to every site created afterwards. Waiting for someone to open wp-admin
	// there would mean not recording until they did. The extra condition is a
	// read of the same autoloaded option, so it costs nothing on the millions
	// of requests where it is already set.
	//
	// Cron requests run it too. The aggregator folds visits on cron, and a
	// rollup whose table the upgrade has not created yet is dropped rather
	// than failing the flush. Waiting for an admin visit after an update that
	// landed overnight dropped every time-on-page report until someone opened
	// wp-admin, and started bounce counting a day later than it needed to.
	if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) || '' === (string) get_option( 'vireo_db_version', '' ) ) {
		VireoAnalytics\Installer::maybe_upgrade();
	}

	// Aggregator registers the cron hook + custom schedule; must instantiate
	// on every request so WP finds the handler when the cron event fires.
	VireoAnalytics\Aggregator::instance();

	// ...and put the flush event back if it has gone missing. Has to come after
	// the Aggregator, which is what registers the every-minute schedule that
	// wp_schedule_event() validates against.
	VireoAnalytics\Installer::ensure_cron();

	// Site Health integration. Answers "is this recording right now" on demand,
	// which is the question nothing could answer when three sites went silent.
	VireoAnalytics\Health::boot();

	// When a wp.org language pack is present, the bundled catalogue underneath
	// it, so a pack approved at 55% doesn't leave the other 45% in English.
	VireoAnalytics\Translations::boot();

	// Declare consent-API compliance so consent plugins stop treating this as
	// an unknown quantity.
	VireoAnalytics\Consent::boot();

	// Chart notes: add/remove handlers.
	VireoAnalytics\Annotations::boot();

	// Search Console sync (daily cron).
	VireoAnalytics\Search_Console::boot();

	// Google's lab test for one page, on request only.
	VireoAnalytics\PageSpeed::boot();

	// What stops AI crawlers reading the site, on request only.
	VireoAnalytics\AI_Scan::boot();

	// Network-wide overview, multisite only.
	VireoAnalytics\Network::boot();

	// wordpress.org support and review links, and the one-time review request.
	VireoAnalytics\Feedback::boot();
	// Aggregates for a MainWP dashboard, over MainWP Child's signed channel.
	VireoAnalytics\MainWP::boot();
	// Popular posts block, shortcode and widget, and the view count display.
	VireoAnalytics\Popular_Posts::boot();
	// Accepted form submissions, from the form plugins' own hooks.
	VireoAnalytics\Form_Events::boot();
	// Export every report table as one file, and restore it on another install.
	VireoAnalytics\Backup::boot();

	VireoAnalytics\Year_Review::boot();

	// Salt_Manager registers the daily rotation cron handler. Same reasoning —
	// the hook callback must be bound on every request.
	VireoAnalytics\Salt_Manager::instance();

	// Admin-side wiring. All of these are admin-only and no-op on the front
	// end; instantiating unconditionally keeps the wiring simple.
	VireoAnalytics\Settings::instance();
	VireoAnalytics\Admin_Bar::instance();
	VireoAnalytics\Post_Columns::instance();
	VireoAnalytics\CSV_Export::instance();
	VireoAnalytics\Email_Reports::instance();
	VireoAnalytics\Dashboard::instance();
	VireoAnalytics\Saved_Views::instance();
	VireoAnalytics\Screen_Quiet::instance();
	VireoAnalytics\Dashboard_Widget::instance();
	VireoAnalytics\Import_Controller::instance();
	VireoAnalytics\NotFound_Tracker::boot();
	VireoAnalytics\Script_Loader::instance();
	VireoAnalytics\Post_Detail::instance();
	VireoAnalytics\Referrer_Detail::instance();
	VireoAnalytics\Retention::instance();
	VireoAnalytics\Event_Detail::instance();
	VireoAnalytics\REST_API::instance();

	// Abilities API integration. The class only binds its registration hooks;
	// they no-op when the Abilities API (WP 6.9+ / feature plugin) is absent.
	VireoAnalytics\Abilities::instance();

	// MCP server over those abilities. Must be listening on mcp_adapter_init
	// before anything can boot the adapter, which happens on init.
	VireoAnalytics\Mcp::boot();

	// Boot Pro features if the Pro build is installed. The loader handles
	// its own license check + graceful no-op when files are missing, so
	// the Free build (no pro/ directory) proceeds untouched.
	if ( VIREO_PRO ) {
		require_once VIREO_DIR . 'pro/loader.php';
	}
}
add_action( 'vireo_verify_endpoint', [ 'VireoAnalytics\\Endpoint_Installer', 'verify' ] );
add_action( 'plugins_loaded', 'vireo_init' );

/**
 * Load the bundled translations.
 *
 * `Text Domain` and `Domain Path` in the header are metadata. They do not make
 * WordPress read a catalogue out of this plugin's own languages/ folder: the
 * automatic path covers wp-content/languages/plugins/, which is where
 * translate.wordpress.org delivers, and nothing else. Without this call the
 * nine catalogues shipped in the zip were never loaded on any site.
 *
 * On `init` rather than earlier, because WordPress 6.7 warns about textdomains
 * loaded before it. load_plugin_textdomain() still prefers a wp-content copy
 * when one exists, so a translation delivered from wp.org wins over ours.
 */
add_action(
	'init',
	static function (): void {
		load_plugin_textdomain( 'vireo-analytics', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
);

/**
 * Tracker endpoint. Routed through admin-ajax so the plugin lives entirely
 * inside its own folder — no shim files at ABSPATH. The handler is defined
 * in vireo-analytics-collect.php and kept loaded only on this action.
 *
 * INTENTIONALLY PUBLIC — NO NONCE, NO CAP CHECK.
 *
 * This is the front-end analytics beacon. It is meant to be called by any
 * site visitor (anonymous included) from any cached page, which is why
 * `wp_ajax_nopriv_*` is registered and no nonce is verified:
 *   1. CSRF nonces would have to be embedded in every cached HTML response
 *      and rotated on schedule — incompatible with full-page CDN caching.
 *   2. The endpoint writes only to the visitor-buffer table; it does not
 *      mutate options, user data, or anything privileged.
 *   3. Other defenses still apply: server-side bot-UA filter (Bot_Filter),
 *      exclusion rules (IP/path/CIDR), and a 4 KB request-body cap in
 *      vireo-analytics-collect.php to bound any abuse.
 */
function vireo_collect_ajax(): void {
	require_once VIREO_DIR . 'vireo-analytics-collect.php';
	vireo_collect_request();
}
add_action( 'wp_ajax_vireo_collect', 'vireo_collect_ajax' );
add_action( 'wp_ajax_nopriv_vireo_collect', 'vireo_collect_ajax' );

// The top-level menu is registered by VireoAnalytics\Dashboard::register_menu().
// Settings attaches as a submenu by VireoAnalytics\Settings::register_submenu().

/**
 * Activation — create tables, schedule cron, prepare the data directory.
 */
function vireo_activate( $network_wide = false ): void {
	// WordPress passes null on PHP 7.4 single-site activation; strict_types would fatal on a bool parameter.
	$network_wide = (bool) $network_wide;
	require_once VIREO_DIR . 'includes/class-bot-filter.php';
	require_once VIREO_DIR . 'includes/class-css-hash.php';
	require_once VIREO_DIR . 'includes/class-endpoint-installer.php';
	require_once VIREO_DIR . 'includes/class-salt-manager.php';
	require_once VIREO_DIR . 'includes/class-sites.php';
	require_once VIREO_DIR . 'includes/class-installer.php';

	// Network activation fires this once for the whole network, not once per
	// site, so every other site would come up with no tables. Sites the loop
	// does not reach still install themselves on their first request; this
	// just means they are ready before it rather than during it.
	if ( $network_wide && is_multisite() ) {
		VireoAnalytics\Installer::activate_network();
		return;
	}

	VireoAnalytics\Installer::activate();
}
register_activation_hook( VIREO_FILE, 'vireo_activate' );

/**
 * A site created after the plugin was network-activated starts with nothing.
 */
function vireo_new_site( $site ): void {
	// Site creation can happen well outside wp-admin, where this function is
	// not loaded.
	if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	if ( ! is_plugin_active_for_network( plugin_basename( VIREO_FILE ) ) ) {
		return;
	}

	$blog_id = is_object( $site ) ? (int) $site->blog_id : (int) $site;

	switch_to_blog( $blog_id );
	require_once VIREO_DIR . 'includes/class-sites.php';
	require_once VIREO_DIR . 'includes/class-installer.php';
	VireoAnalytics\Installer::activate();
	restore_current_blog();
}
add_action( 'wp_initialize_site', 'vireo_new_site', 20 );

/**
 * Deactivation — unschedule cron, clean up legacy artifacts.
 */
function vireo_deactivate(): void {
	require_once VIREO_DIR . 'includes/class-endpoint-installer.php';
	require_once VIREO_DIR . 'includes/class-salt-manager.php';
	require_once VIREO_DIR . 'includes/class-installer.php';
	VireoAnalytics\Installer::deactivate();
}
register_deactivation_hook( VIREO_FILE, 'vireo_deactivate' );
