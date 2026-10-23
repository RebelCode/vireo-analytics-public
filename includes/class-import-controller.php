<?php
/**
 * Import controller.
 *
 * Wires the Koko + Jetpack importers into the admin:
 *   - Registers two hidden submenu pages (linked to from the onboarding banner
 *     and the Settings footer).
 *   - Handles the "start Jetpack import" form post (saves params, redirects).
 *   - Exposes a single AJAX endpoint (`vireo_import_chunk`) that both importers
 *     share — the JS driver loops over this until `done: true`.
 *   - Handles the "dismiss onboarding" AJAX call.
 *   - Detects availability for the onboarding banner.
 *
 * Menu visibility: submenus are added under the Vireo Analytics top-level with
 * null parent slug so they don't show in the sidebar — they're linked to
 * explicitly from the onboarding banner and from Settings. Keeps the menu
 * uncluttered.
 */

namespace VireoAnalytics;

use VireoAnalytics\Importers\Importer_Base;
use VireoAnalytics\Importers\Jetpack_Importer;
use VireoAnalytics\Importers\Koko_Importer;
use VireoAnalytics\Importers\Statify_Importer;
use VireoAnalytics\Importers\WP_Statistics_Importer;
use VireoAnalytics\Importers\Burst_Importer;
use VireoAnalytics\Importers\Independent_Analytics_Importer;
use VireoAnalytics\Importers\SlimStat_Importer;
use VireoAnalytics\Importers\GA4_Importer;
use VireoAnalytics\Importers\Plausible_Importer;
use VireoAnalytics\Importers\Backup_Importer;

defined( 'ABSPATH' ) || exit;

final class Import_Controller {

	public const PAGE_KOKO            = 'vireo-analytics-import-koko';
	public const PAGE_JETPACK         = 'vireo-analytics-import-jetpack';
	public const PAGE_GA4             = 'vireo-analytics-import-ga4';
	public const PAGE_PLAUSIBLE       = 'vireo-analytics-import-plausible';
	public const PAGE_BACKUP          = 'vireo-analytics-import-backup';

	/**
	 * Importers whose data lives off the site (an API, an uploaded export), so
	 * is_available() says whether they can run, not whether there is anything
	 * to import. They are always offered and never trigger the first-run banner.
	 */
	public const EXTERNAL_SOURCES = [ 'ga4', 'plausible', 'backup' ];

	/**
	 * Importers that need no source-specific screen. They all satisfy the
	 * Importer_Base contract and nothing more, so one page template drives
	 * every one of them: name it, say whether data was found, run the same
	 * chunk loop. Keyed by page slug so the router can resolve back.
	 */
	public const GENERIC_PAGES = [
		'vireo-analytics-import-statify'               => 'statify',
		'vireo-analytics-import-wp-statistics'         => 'wp-statistics',
		'vireo-analytics-import-burst'                 => 'burst',
		'vireo-analytics-import-independent-analytics' => 'independent-analytics',
		'vireo-analytics-import-slimstat'              => 'slimstat',
	];
	public const ONBOARDING_USER_META = 'vireo_onboarding_dismissed';

	/**
	 * Plugin folders (wp.org slugs) that mean a source is in use on this site.
	 * Google Analytics has no plugin of its own, so the common ways of adding it
	 * stand in for it.
	 */
	private const SOURCE_PLUGINS = [
		'ga4'                   => [ 'google-site-kit', 'google-analytics-for-wordpress', 'google-analytics-dashboard-for-wp', 'ga-google-analytics' ],
		'koko'                  => [ 'koko-analytics' ],
		'jetpack'               => [ 'jetpack', 'jetpack-stats' ],
		'statify'               => [ 'statify' ],
		'wp-statistics'         => [ 'wp-statistics' ],
		'burst'                 => [ 'burst-statistics' ],
		'independent-analytics' => [ 'independent-analytics' ],
		'slimstat'              => [ 'wp-slimstat' ],
		'plausible'             => [ 'plausible-analytics' ],
	];

	private static ?self $instance = null;

	/** @var array<string, Importer_Base> */
	private array $importers = [];

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->register_importers();

		add_action( 'admin_menu', [ $this, 'register_pages' ] );
		add_action( 'admin_post_vireo_start_jetpack_import', [ $this, 'handle_start_jetpack' ] );
		add_action( 'admin_post_vireo_start_ga4_import', [ $this, 'handle_start_ga4' ] );
		add_action( 'admin_post_vireo_forget_ga4_key', [ $this, 'handle_forget_ga4' ] );
		add_action( 'admin_post_vireo_start_plausible_import', [ $this, 'handle_start_plausible' ] );
		add_action( 'admin_post_vireo_start_backup_import', [ $this, 'handle_start_backup_import' ] );
		add_action( 'wp_ajax_vireo_import_chunk', [ $this, 'ajax_import_chunk' ] );
		add_action( 'wp_ajax_vireo_import_dismiss_onboarding', [ $this, 'ajax_dismiss_onboarding' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	private function register_importers(): void {
		$base = VIREO_DIR . 'importers/';
		require_once VIREO_DIR . 'includes/class-google-auth.php';
		require_once VIREO_DIR . 'includes/class-importer-base.php';
		require_once $base . 'class-koko-importer.php';
		require_once $base . 'class-jetpack-importer.php';
		require_once $base . 'class-statify-importer.php';
		require_once $base . 'class-wp-statistics-importer.php';
		require_once $base . 'class-burst-importer.php';
		require_once $base . 'class-independent-analytics-importer.php';
		require_once $base . 'class-slimstat-importer.php';
		require_once $base . 'class-ga4-importer.php';
		require_once $base . 'class-plausible-importer.php';
		require_once $base . 'class-backup-importer.php';

		$this->importers['koko']          = new Koko_Importer();
		$this->importers['jetpack']       = new Jetpack_Importer();
		$this->importers['statify']       = new Statify_Importer();
		$this->importers['wp-statistics'] = new WP_Statistics_Importer();
		$this->importers['burst']         = new Burst_Importer();
		$this->importers['independent-analytics'] = new Independent_Analytics_Importer();
		$this->importers['slimstat']              = new SlimStat_Importer();
		$this->importers['ga4']                   = new GA4_Importer();
		$this->importers['plausible']             = new Plausible_Importer();
		$this->importers['backup']                = new Backup_Importer();
	}

	public function get( string $slug ): ?Importer_Base {
		return $this->importers[ $slug ] ?? null;
	}

	/**
	 * Every importer, keyed by slug, so the Settings screen can list what
	 * actually ships rather than a hand-maintained subset.
	 *
	 * @return array<string, Importer_Base>
	 */
	public function get_all(): array {
		return $this->importers;
	}

	/**
	 * Admin URL for an importer's screen, or '' when it has none.
	 */
	public function page_url_for( string $slug ): string {
		$map = [
			'koko'      => self::PAGE_KOKO,
			'jetpack'   => self::PAGE_JETPACK,
			'ga4'       => self::PAGE_GA4,
			'plausible' => self::PAGE_PLAUSIBLE,
			'backup'    => self::PAGE_BACKUP,
		] + array_flip( self::GENERIC_PAGES );

		$page = $map[ $slug ] ?? '';
		return '' === $page ? '' : add_query_arg( 'page', $page, admin_url( 'admin.php' ) );
	}

	/**
	 * Every source, with what this site says about it, ordered for the import
	 * screen: sources in use here and not yet imported first, then the ones
	 * already brought in, then the rest.
	 *
	 * @return array<int,array{slug:string,name:string,url:string,active:bool,has_data:bool,last:?array}>
	 */
	public function sources(): array {
		$rows = [];
		foreach ( $this->importers as $slug => $importer ) {
			$url = $this->page_url_for( $slug );
			// A backup is Vireo's own data coming back, not another plugin's
			// history; Settings offers it under Backup and restore instead.
			if ( '' === $url || 'backup' === $slug ) {
				continue;
			}
			$rows[] = [
				'slug'     => $slug,
				'name'     => $importer->name(),
				'url'      => $url,
				'active'   => $this->source_active( $slug ),
				// Google Analytics, Jetpack and Plausible keep their data
				// off-site, so there are no local tables to find.
				'has_data' => ! in_array( $slug, array_merge( self::EXTERNAL_SOURCES, [ 'jetpack' ] ), true ) && $importer->is_available(),
				'last'     => Import_History::latest( $slug ),
			];
		}

		$rank = static function ( array $row ): int {
			if ( null !== $row['last'] ) {
				return 1;
			}
			return ( $row['active'] || $row['has_data'] ) ? 0 : 2;
		};
		usort( $rows, static fn( array $a, array $b ): int => $rank( $a ) <=> $rank( $b ) );

		return $rows;
	}

	/** Is a plugin for this source active here (on this site or network wide)? */
	public function source_active( string $slug ): bool {
		static $active_dirs = null;
		if ( null === $active_dirs ) {
			$active_dirs = [];
			$active      = array_merge(
				(array) get_option( 'active_plugins', [] ),
				is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) : []
			);
			foreach ( $active as $plugin_file ) {
				$active_dirs[ dirname( (string) $plugin_file ) ] = true;
			}
		}

		foreach ( self::SOURCE_PLUGINS[ $slug ] ?? [] as $dir ) {
			if ( isset( $active_dirs[ $dir ] ) ) {
				return true;
			}
		}
		return false;
	}

	public function get_koko(): Koko_Importer {
		return $this->importers['koko'];
	}

	public function get_jetpack(): Jetpack_Importer {
		return $this->importers['jetpack'];
	}

	public function get_ga4(): GA4_Importer {
		return $this->importers['ga4'];
	}

	public function get_plausible(): Plausible_Importer {
		return $this->importers['plausible'];
	}

	public function get_backup(): Backup_Importer {
		return $this->importers['backup'];
	}

	public function register_pages(): void {
		// Empty parent => not visible in the sidebar; still reachable by slug.
		$pages = [
			[ self::PAGE_KOKO, __( 'Import from Koko Analytics', 'vireo-analytics' ), 'render_koko_page' ],
			[ self::PAGE_JETPACK, __( 'Import from Jetpack Stats', 'vireo-analytics' ), 'render_jetpack_page' ],
			[ self::PAGE_GA4, __( 'Import from Google Analytics 4', 'vireo-analytics' ), 'render_ga4_page' ],
			[ self::PAGE_PLAUSIBLE, __( 'Import from Plausible Analytics', 'vireo-analytics' ), 'render_plausible_page' ],
			[ self::PAGE_BACKUP, __( 'Restore a Vireo Analytics backup', 'vireo-analytics' ), 'render_backup_page' ],
		];

		foreach ( self::GENERIC_PAGES as $generic_slug => $importer_slug ) {
			$importer = $this->get( $importer_slug );
			if ( ! $importer ) {
				continue;
			}
			$pages[] = [
				$generic_slug,
				/* translators: %s: name of the analytics plugin being imported from. */
				sprintf( __( 'Import from %s', 'vireo-analytics' ), $importer->name() ),
				'render_generic_page',
			];
		}

		foreach ( $pages as list( $slug, $label, $callback ) ) {
			$hook = add_submenu_page( '', $label, $label, 'manage_options', $slug, [ $this, $callback ] );

			if ( ! is_string( $hook ) || '' === $hook ) {
				continue;
			}

			// A page with no parent never appears in the menu tree, so WordPress
			// cannot work out a title for it and leaves the global null. Core
			// then passes that null straight into strip_tags(), which PHP 8.1
			// deprecates, and the warning prints above the page on any site
			// showing errors. `load-` fires before the admin header is built,
			// which is the last point where setting this still helps.
			add_action(
				'load-' . $hook,
				static function () use ( $label, $slug ): void {
					$GLOBALS['title'] = $label;
					// Keep Vireo Analytics > Settings open and highlighted in the
					// menu, since that's where the import was started. Otherwise
					// the whole menu collapses and the page looks orphaned. The
					// hidden entry has to leave the menu tree too: core finds it
					// there after these filters run and resets the parent to ''.
					global $submenu;
					foreach ( (array) ( $submenu[''] ?? [] ) as $i => $item ) {
						if ( ( $item[2] ?? '' ) === $slug ) {
							unset( $submenu[''][ $i ] );
						}
					}
					add_filter( 'parent_file', static fn(): string => 'vireo-analytics' );
					add_filter( 'submenu_file', static fn(): string => Settings::PAGE_SLUG );
				}
			);
		}
	}

	public function enqueue_assets( $hook ): void {
		$hook = (string) $hook;
		// Only on our importer pages. WP's hook suffix for hidden submenus is
		// `admin_page_{slug}`.
		$import_hooks = [
			'admin_page_' . self::PAGE_KOKO,
			'admin_page_' . self::PAGE_JETPACK,
			'admin_page_' . self::PAGE_GA4,
			'admin_page_' . self::PAGE_PLAUSIBLE,
			'admin_page_' . self::PAGE_BACKUP,
		];
		foreach ( array_keys( self::GENERIC_PAGES ) as $generic_slug ) {
			$import_hooks[] = 'admin_page_' . $generic_slug;
		}
		if ( ! in_array( $hook, $import_hooks, true ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$js_ver  = @filemtime( VIREO_DIR . 'assets/js/importer.js' ) ?: VIREO_VERSION;
		$css_ver = @filemtime( VIREO_DIR . 'assets/css/importer.css' ) ?: VIREO_VERSION;

		wp_enqueue_style(
			'va-importer',
			VIREO_URL . 'assets/css/importer.css',
			[],
			$css_ver
		);

		wp_enqueue_script(
			'va-importer',
			VIREO_URL . 'assets/js/importer.js',
			[],
			$js_ver,
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

		// Per-page importer config (previously emitted inline at the bottom of
		// each import-*.php template).
		$page_config = $this->config_for_hook( $hook );
		if ( null !== $page_config ) {
			wp_localize_script( 'va-importer', 'vireoImportConfig', $page_config );
		}
	}

	/**
	 * Build the per-page `vireoImportConfig` payload consumed by importer.js.
	 * Returns null for any hook that isn't an importer page.
	 *
	 * @return array<string, array<string, mixed>>|null
	 */
	private function config_for_hook( string $hook ): ?array {
		$base = [
			'startBtnId'      => 'va-import-start',
			'cancelBtnId'     => 'va-import-cancel',
			'progressId'      => 'va-import-progress',
			'progressBarId'   => 'va-import-progress-bar',
			'progressFillId'  => 'va-import-progress-fill',
			'progressLabelId' => 'va-import-progress-label',
			'progressPctId'   => 'va-import-progress-pct',
			'resultId'        => 'va-import-result',
		];

		if ( 'admin_page_' . self::PAGE_KOKO === $hook ) {
			return [
				'koko' => array_merge(
					$base,
					[
						'slug'      => 'koko',
						'formId'    => 'va-import-koko-form',
						'footerId'  => 'va-import-footer',
						'autoStart' => false,
						'run'       => Importer_Base::new_run(),
					]
				),
			];
		}

		foreach ( self::GENERIC_PAGES as $generic_slug => $importer_slug ) {
			if ( 'admin_page_' . $generic_slug === $hook ) {
				return [
					$importer_slug => array_merge(
						$base,
						[
							'slug'      => $importer_slug,
							'formId'    => 'va-import-generic-form',
							'footerId'  => 'va-import-footer',
							'autoStart' => false,
							'run'       => Importer_Base::new_run(),
						]
					),
				];
			}
		}

		if ( 'admin_page_' . self::PAGE_JETPACK === $hook ) {
			$run = $this->running_run();
			return [
				'jetpack' => array_merge(
					$base,
					[
						'slug'         => 'jetpack',
						'formId'       => 'va-import-jetpack-form',
						'serverSubmit' => true,
						'autoStart'    => '' !== $run,
						'run'          => $run,
					]
				),
			];
		}

		if ( 'admin_page_' . self::PAGE_PLAUSIBLE === $hook ) {
			$run = $this->running_run();
			return [
				'plausible' => array_merge(
					$base,
					[
						'slug'         => 'plausible',
						'formId'       => 'va-import-plausible-form',
						'serverSubmit' => true,
						'autoStart'    => '' !== $run,
						'run'          => $run,
					]
				),
			];
		}

		if ( 'admin_page_' . self::PAGE_BACKUP === $hook ) {
			$run = $this->running_run();
			return [
				'backup' => array_merge(
					$base,
					[
						'slug'         => 'backup',
						'formId'       => 'va-import-backup-form',
						'serverSubmit' => true,
						'autoStart'    => '' !== $run,
						'run'          => $run,
					]
				),
			];
		}

		if ( 'admin_page_' . self::PAGE_GA4 === $hook ) {
			$run = $this->running_run();
			return [
				'ga4' => array_merge(
					$base,
					[
						'slug'         => 'ga4',
						'formId'       => 'va-import-ga4-form',
						'serverSubmit' => true,
						'autoStart'    => '' !== $run,
						'run'          => $run,
					]
				),
			];
		}

		return null;
	}

	/**
	 * The run a start handler redirected here to begin, or '' when this page
	 * load isn't that redirect. The nonce is bound to the run, so a crafted
	 * link can't start a run, and an old URL can't restart a different one.
	 */
	private function running_run(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the nonce is verified below, against this run
		$run   = Importer_Base::valid_run( isset( $_GET['run'] ) ? sanitize_key( wp_unslash( (string) $_GET['run'] ) ) : '' );
		$nonce = isset( $_GET['_vrn'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['_vrn'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( '' === $run || '' === $nonce || false === wp_verify_nonce( $nonce, 'vireo_import_running_' . $run ) ) {
			return '';
		}
		return $run;
	}

	/**
	 * Where a start handler sends the browser to begin the run.
	 */
	private static function running_url( string $redirect, string $run ): string {
		return add_query_arg(
			[
				'run'  => $run,
				'_vrn' => wp_create_nonce( 'vireo_import_running_' . $run ),
			],
			$redirect
		);
	}

	/** Where an importer's flash message waits for the current user. */
	private static function flash_key(): string {
		return 'vireo_import_flash_' . get_current_user_id();
	}

	/**
	 * Park a message for the next request instead of putting it in the URL.
	 *
	 * These were rendered from a query argument straight into an admin notice.
	 * Escaped, so nothing ran, but a crafted link could still put an
	 * attacker's words in a red box on an admin screen.
	 */
	private static function set_flash( string $message ): void {
		if ( '' !== $message ) {
			set_transient( self::flash_key(), $message, 60 );
		}
	}

	/**
	 * The Google Analytics and Jetpack forms post to admin-post.php and the run
	 * starts after a redirect, so their "include Vireo's own days" choice is
	 * held here for the first chunk to pick up.
	 */
	private static function remember_include_own( string $slug, string $run ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- callers check the nonce first
		if ( ! empty( $_POST['include_own_days'] ) ) {
			set_transient( Importer_Base::run_option( 'vireo_import_own_', $slug, $run ), 1, HOUR_IN_SECONDS );
		}
	}

	private static function take_include_own( string $slug, string $run ): bool {
		$key  = Importer_Base::run_option( 'vireo_import_own_', $slug, $run );
		$flag = (bool) get_transient( $key );
		delete_transient( $key );
		return $flag;
	}

	/**
	 * Drop the ledger and cutoff of runs of this importer that started over a
	 * day ago. A run that finishes removes its own; these are the ones that
	 * failed or were closed partway.
	 */
	private static function sweep_run_state( string $slug ): void {
		global $wpdb;
		$prefixes = [ 'vireo_import_done_', 'vireo_import_cutoff_' ];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- option names by prefix; no API for that
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( $prefixes[0] . $slug . '_' ) . '%',
				$wpdb->esc_like( $prefixes[1] . $slug . '_' ) . '%'
			)
		);
		foreach ( (array) $names as $name ) {
			$started = Importer_Base::run_started( substr( (string) $name, -19 ) );
			if ( $started > 0 && $started < time() - DAY_IN_SECONDS ) {
				delete_option( (string) $name );
			}
		}
	}

	private static function take_flash(): string {
		$message = get_transient( self::flash_key() );

		if ( ! is_string( $message ) || '' === $message ) {
			return '';
		}

		delete_transient( self::flash_key() );

		return $message;
	}

	// -------------------------------------------------------------------------
	// Page renderers
	// -------------------------------------------------------------------------

	public function render_koko_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'vireo-analytics' ), 403 );
		}
		$importer      = $this->get_koko();
		$is_available  = $importer->is_available();
		$summary       = $is_available ? $importer->get_summary() : null;
		$plugin_active = defined( 'KOKO_ANALYTICS_VERSION' ) || class_exists( '\\KokoAnalytics\\Collector' );
		$last_run      = Import_History::latest( 'koko' );

		$own_since = Import_History::own_data_start();

		include VIREO_DIR . 'templates/admin/import-koko.php';
	}

	/**
	 * Screen for any importer that needs no source-specific options.
	 *
	 * The page slug identifies the importer, so a crafted `page` value can
	 * only ever resolve to something in GENERIC_PAGES — anything else is a
	 * 403 rather than a lookup on user input.
	 */
	public function render_generic_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'vireo-analytics' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen routing; no state changes here.
		$page          = isset( $_GET['page'] ) ? sanitize_key( (string) wp_unslash( $_GET['page'] ) ) : '';
		$importer_slug = self::GENERIC_PAGES[ $page ] ?? '';
		$importer      = '' === $importer_slug ? null : $this->get( $importer_slug );

		if ( ! $importer ) {
			wp_die( esc_html__( 'Unknown importer.', 'vireo-analytics' ), 404 );
		}

		$is_available = $importer->is_available();
		$record_count = $is_available ? $importer->count_records() : 0;
		$source_name   = $importer->name();
		$carries       = $importer->carries();
		$form_id       = 'va-import-generic-form';
		$last_run      = Import_History::latest( $importer_slug );
		$plugin_active = $this->source_active( $importer_slug );

		$own_since = Import_History::own_data_start();

		include VIREO_DIR . 'templates/admin/import-generic.php';
	}

	public function render_jetpack_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'vireo-analytics' ), 403 );
		}
		$importer     = $this->get_jetpack();
		$is_available = $importer->is_available();
		$home_host    = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );

		// If a previous run saved params (e.g. user reloaded the page mid-run),
		// expose them back to the form so they can resume / reconfigure.
		$saved = $importer->get_saved_params();

		$default_end   = gmdate( 'Y-m-d' );
		$default_start = gmdate( 'Y-m-d', strtotime( '-365 days' ) );

		// Read from a short-lived transient, not the URL, so a crafted link
		// cannot put words in this notice.
		$vireo_import_error = self::take_flash();

		$own_since = Import_History::own_data_start();
		$running   = '' !== $this->running_run();

		include VIREO_DIR . 'templates/admin/import-jetpack.php';
	}

	public function render_ga4_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'vireo-analytics' ), 403 );
		}

		$importer      = $this->get_ga4();
		$is_available  = $importer->is_available();
		$saved         = $importer->get_saved_params();
		$has_key       = $importer->has_saved_key();
		$account_email = $importer->service_account_email();

		$default_end   = gmdate( 'Y-m-d', strtotime( '-1 day' ) );
		$default_start = gmdate( 'Y-m-d', strtotime( '-365 days' ) );

		// Read from a short-lived transient, not the URL, so a crafted link
		// cannot put words in this notice.
		$vireo_import_error = self::take_flash();

		$own_since = Import_History::own_data_start();

		include VIREO_DIR . 'templates/admin/import-ga4.php';
	}

	public function render_plausible_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'vireo-analytics' ), 403 );
		}

		$importer = $this->get_plausible();
		$run      = $this->running_run();

		// A stage left behind by a closed tab, a cancel or a failure is
		// dropped once it has sat idle for an hour, so the export doesn't stay
		// in the database. Not sooner: this is a plain GET, and dropping a
		// stage here on sight let any link to this screen cancel a running
		// import. The next upload replaces it in any case.
		$importer->abort_stale();

		$staged = null;
		if ( '' !== $run ) {
			$importer->set_run( $run );
			$staged = $importer->get_saved_params();
		}
		$can_read_zip    = $importer->can_read_zip();
		$max_upload_size = (int) wp_max_upload_size();
		$own_since       = Import_History::own_data_start();
		$last_run        = Import_History::latest( 'plausible' );
		$source_name     = $importer->name();
		$plugin_active   = $this->source_active( 'plausible' );

		// Read from a short-lived transient, not the URL, so a crafted link
		// cannot put words in this notice.
		$vireo_import_error = self::take_flash();

		include VIREO_DIR . 'templates/admin/import-plausible.php';
	}

	public function render_backup_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'vireo-analytics' ), 403 );
		}

		$importer = $this->get_backup();
		$run      = $this->running_run();

		// Same rule as Plausible: a stage left behind is dropped after an hour,
		// not on sight, since this is a GET.
		$importer->abort_stale();

		$staged = null;
		if ( '' !== $run ) {
			$importer->set_run( $run );
			$staged = $importer->get_saved_params();
		}
		$max_upload_size    = (int) wp_max_upload_size();
		$history            = Import_History::all( 'backup' );
		$vireo_import_error = self::take_flash();

		include VIREO_DIR . 'templates/admin/import-backup.php';
	}

	/**
	 * Keep the uploaded backup for the run and send the browser back to begin.
	 */
	public function handle_start_backup_import(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'vireo-analytics' ), 403 );
		}
		check_admin_referer( 'vireo_start_backup_import' );
		$run = Importer_Base::new_run();
		// A backup carries Vireo's own days on purpose: the file is the record.
		set_transient( Importer_Base::run_option( 'vireo_import_own_', 'backup', $run ), 1, HOUR_IN_SECONDS );

		$redirect = add_query_arg( [ 'page' => self::PAGE_BACKUP ], admin_url( 'admin.php' ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above
		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( (string) $_POST['mode'] ) ) : 'merge';
		if ( ! in_array( $mode, Backup_Importer::MODES, true ) ) {
			$mode = 'merge';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above
		if ( 'replace' === $mode && empty( $_POST['confirm_replace'] ) ) {
			self::set_flash( __( 'Replacing deletes everything Vireo Analytics has recorded here before loading the file. Tick the box to confirm, or choose Merge.', 'vireo-analytics' ) );
			wp_safe_redirect( $redirect );
			exit;
		}

		$files = self::uploaded_files( 'backup_file' );
		if ( empty( $files ) ) {
			self::set_flash( __( 'Choose the backup file to begin.', 'vireo-analytics' ) );
			wp_safe_redirect( $redirect );
			exit;
		}

		$importer = $this->get_backup();
		$importer->set_run( $run );
		$staged = $importer->stage_upload( $files[0], $mode );

		if ( is_wp_error( $staged ) ) {
			self::set_flash( $staged->get_error_message() );
			wp_safe_redirect( $redirect );
			exit;
		}

		wp_safe_redirect( self::running_url( $redirect, $run ) );
		exit;
	}

	/**
	 * Forget the stored service-account key.
	 *
	 * Once pasted there was no way to remove it short of deleting the plugin,
	 * so a key handed over for a one-off import stayed in the database for the
	 * life of the site. Anyone who can reach this screen can revoke it.
	 */
	public function handle_forget_ga4(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'vireo-analytics' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( 'vireo_forget_ga4_key' );

		$this->get_ga4()->clear_params();

		self::set_flash( __( 'The saved key has been removed.', 'vireo-analytics' ) );
		wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE_GA4 ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// -------------------------------------------------------------------------
	// Form handler (Google Analytics start)
	// -------------------------------------------------------------------------

	public function handle_start_ga4(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'vireo-analytics' ), 403 );
		}
		check_admin_referer( 'vireo_start_ga4_import' );
		$run = Importer_Base::new_run();
		self::remember_include_own( 'ga4', $run );

		$redirect = add_query_arg( [ 'page' => self::PAGE_GA4 ], admin_url( 'admin.php' ) );

		// The key is JSON with newlines inside the private key, so it must not
		// go through sanitize_text_field(), which would flatten it and break
		// the signature. It is validated by being parsed instead.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above
		$key_json = isset( $_POST['key_json'] ) ? trim( wp_unslash( (string) $_POST['key_json'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above
		$property = isset( $_POST['property_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['property_id'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above
		$date_start = isset( $_POST['date_start'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['date_start'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above
		$date_end = isset( $_POST['date_end'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['date_end'] ) ) : '';

		$importer = $this->get_ga4();

		if ( '' !== $key_json ) {
			$parsed = json_decode( $key_json, true );
			if ( ! is_array( $parsed ) || empty( $parsed['client_email'] ) || empty( $parsed['private_key'] ) ) {
				self::set_flash( __( 'That key file could not be read. Paste the whole JSON file, including the private key.', 'vireo-analytics' ) );
				wp_safe_redirect( $redirect );
				exit;
			}
		} elseif ( ! $importer->has_saved_key() ) {
			self::set_flash( __( 'Paste the service account key file to begin.', 'vireo-analytics' ) );
			wp_safe_redirect( $redirect );
			exit;
		}

		if ( '' === preg_replace( '/\D+/', '', $property ) ) {
			self::set_flash( __( 'Enter the numeric GA4 property ID.', 'vireo-analytics' ) );
			wp_safe_redirect( $redirect );
			exit;
		}

		if ( '' === $date_start || '' === $date_end || $date_end < $date_start ) {
			self::set_flash( __( 'Choose a start date on or before the end date.', 'vireo-analytics' ) );
			wp_safe_redirect( $redirect );
			exit;
		}

		// Imported figures are added to what is already stored, so a range that
		// overlaps one already brought in counts those days twice, with no way
		// to take it back afterwards. Say which run overlaps and make the person
		// confirm rather than discovering it in the totals.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above
		$confirmed = ! empty( $_POST['confirm_overlap'] );
		$overlaps  = Import_History::overlapping( 'ga4', $date_start, $date_end );

		if ( ! empty( $overlaps ) && ! $confirmed ) {
			self::set_flash(
				sprintf(
					/* translators: %s: list of date ranges already imported */
					__( 'You have already imported Google Analytics data covering %s. Importing it again adds those numbers on top of the ones already stored, and that cannot be undone. Narrow the dates to what is missing, or tick the box to import anyway.', 'vireo-analytics' ),
					Import_History::describe( $overlaps )
				)
			);
			wp_safe_redirect( add_query_arg( 'overlap', '1', $redirect ) );
			exit;
		}

		$importer->save_params(
			[
				'key_json'    => $key_json,
				'property_id' => $property,
				'date_start'  => $date_start,
				'date_end'    => $date_end,
			]
		);

		wp_safe_redirect( self::running_url( $redirect, $run ) );
		exit;
	}

	// -------------------------------------------------------------------------
	// Form handler (Plausible upload)
	// -------------------------------------------------------------------------

	public function handle_start_plausible(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'vireo-analytics' ), 403 );
		}
		check_admin_referer( 'vireo_start_plausible_import' );
		$run = Importer_Base::new_run();
		self::remember_include_own( 'plausible', $run );

		$redirect = add_query_arg( [ 'page' => self::PAGE_PLAUSIBLE ], admin_url( 'admin.php' ) );

		$date_start = isset( $_POST['date_start'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['date_start'] ) ) : '';
		$date_end   = isset( $_POST['date_end'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['date_end'] ) ) : '';

		foreach ( [ $date_start, $date_end ] as $date ) {
			if ( '' !== $date && 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
				self::set_flash( __( 'Dates must be in YYYY-MM-DD format.', 'vireo-analytics' ) );
				wp_safe_redirect( $redirect );
				exit;
			}
		}
		if ( '' !== $date_start && '' !== $date_end && $date_end < $date_start ) {
			self::set_flash( __( 'Choose a start date on or before the end date.', 'vireo-analytics' ) );
			wp_safe_redirect( $redirect );
			exit;
		}

		$files = self::uploaded_files( 'plausible_export' );
		if ( empty( $files ) ) {
			self::set_flash( __( 'Choose the export file from Plausible to begin.', 'vireo-analytics' ) );
			wp_safe_redirect( $redirect );
			exit;
		}

		$importer = $this->get_plausible();
		$importer->set_run( $run );
		$summary = $importer->stage_uploads( $files, $date_start, $date_end );

		if ( is_wp_error( $summary ) ) {
			self::set_flash( $summary->get_error_message() );
			wp_safe_redirect( $redirect );
			exit;
		}

		// Same guard as Google Analytics: imported figures add to what is
		// stored, so ask before bringing in days that are already there.
		$confirmed = ! empty( $_POST['confirm_overlap'] );
		$overlaps  = Import_History::overlapping( 'plausible', $summary['date_start'], $summary['date_end'] );

		if ( ! empty( $overlaps ) && ! $confirmed ) {
			$importer->clear_params();
			self::set_flash(
				sprintf(
					/* translators: %s: list of date ranges already imported */
					__( 'You have already imported Plausible data covering %s. Importing it again adds those numbers on top of the ones already stored, and that cannot be undone. Narrow the dates to what is missing, or tick the box to import anyway.', 'vireo-analytics' ),
					Import_History::describe( $overlaps )
				)
			);
			wp_safe_redirect( add_query_arg( 'overlap', '1', $redirect ) );
			exit;
		}

		wp_safe_redirect( self::running_url( $redirect, $run ) );
		exit;
	}

	/**
	 * Files posted under one field name, as a flat list. Anything PHP did not
	 * receive as an upload this request is dropped, so tmp_name can never point
	 * at a path the browser chose.
	 *
	 * @return array<int, array{name:string, tmp_name:string, error:int, size:int}>
	 */
	private static function uploaded_files( string $field ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked by the caller; tmp_name is verified with is_uploaded_file() below and name is only used for its extension
		$raw = isset( $_FILES[ $field ] ) && is_array( $_FILES[ $field ] ) ? $_FILES[ $field ] : [];
		if ( empty( $raw['tmp_name'] ) ) {
			return [];
		}

		$out = [];
		foreach ( (array) $raw['tmp_name'] as $i => $tmp_name ) {
			$error = (int) ( ( (array) $raw['error'] )[ $i ] ?? UPLOAD_ERR_NO_FILE );
			if ( UPLOAD_ERR_NO_FILE === $error ) {
				continue;
			}
			$tmp_name = (string) $tmp_name;
			if ( UPLOAD_ERR_OK === $error && ! is_uploaded_file( $tmp_name ) ) {
				continue;
			}
			$out[] = [
				'name'     => sanitize_file_name( (string) ( ( (array) $raw['name'] )[ $i ] ?? '' ) ),
				'tmp_name' => $tmp_name,
				'error'    => $error,
				'size'     => (int) ( ( (array) $raw['size'] )[ $i ] ?? 0 ),
			];
		}

		return $out;
	}

	// -------------------------------------------------------------------------
	// Form handler (Jetpack start)
	// -------------------------------------------------------------------------

	public function handle_start_jetpack(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'vireo-analytics' ), 403 );
		}
		check_admin_referer( 'vireo_start_jetpack_import' );
		$run = Importer_Base::new_run();
		self::remember_include_own( 'jetpack', $run );

		$api_key    = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['api_key'] ) ) : '';
		$blog_uri   = isset( $_POST['blog_uri'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['blog_uri'] ) ) : '';
		$date_start = isset( $_POST['date_start'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['date_start'] ) ) : '';
		$date_end   = isset( $_POST['date_end'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['date_end'] ) ) : '';
		$api_key    = trim( $api_key );
		$blog_uri   = trim( $blog_uri );
		$date_start = trim( $date_start );
		$date_end   = trim( $date_end );

		$redirect = add_query_arg(
			[ 'page' => self::PAGE_JETPACK ],
			admin_url( 'admin.php' )
		);

		if ( '' === $api_key || '' === $blog_uri || '' === $date_start || '' === $date_end ) {
			self::set_flash( __( 'All fields are required.', 'vireo-analytics' ) );
			wp_safe_redirect( $redirect );
			exit;
		}

		// Normalize blog_uri — accept either a bare host or a URL.
		if ( false === strpos( $blog_uri, '://' ) ) {
			$blog_uri = 'http://' . $blog_uri;
		}
		$host = wp_parse_url( $blog_uri, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			self::set_flash( __( 'Invalid Blog URI.', 'vireo-analytics' ) );
			wp_safe_redirect( $redirect );
			exit;
		}
		$blog_uri = $host;

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_start )
			|| ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_end ) ) {
			self::set_flash( __( 'Dates must be in YYYY-MM-DD format.', 'vireo-analytics' ) );
			wp_safe_redirect( $redirect );
			exit;
		}

		if ( strcmp( $date_end, $date_start ) < 0 ) {
			[ $date_start, $date_end ] = [ $date_end, $date_start ];
		}

		// Same guard as Google Analytics: a range that overlaps one already
		// imported counts those days twice, with no way to take it back.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above
		$confirmed = ! empty( $_POST['confirm_overlap'] );
		$overlaps  = Import_History::overlapping( 'jetpack', $date_start, $date_end );

		if ( ! empty( $overlaps ) && ! $confirmed ) {
			self::set_flash(
				sprintf(
					/* translators: %s: list of date ranges already imported */
					__( 'You have already imported Jetpack Stats covering %s. Importing it again adds those numbers on top of the ones already stored, and that cannot be undone. Narrow the dates to what is missing, or tick the box to import anyway.', 'vireo-analytics' ),
					Import_History::describe( $overlaps )
				)
			);
			wp_safe_redirect( $redirect );
			exit;
		}

		// The form no longer echoes the saved key back into the page, so a blank
		// field means "reuse the one you're holding", not "clear it".
		if ( '' === $api_key ) {
			$api_key = $this->get_jetpack()->get_saved_params()['api_key'];
		}

		$this->get_jetpack()->save_params(
			[
				'api_key'    => $api_key,
				'blog_uri'   => $blog_uri,
				'date_start' => $date_start,
				'date_end'   => $date_end,
			]
		);

		// Send the user back to the page; the JS driver sees the run and starts
		// polling. The nonce is bound to the run, so the redirect-back read is
		// verifiable (the redirect originates from this nonce-checked handler).
		wp_safe_redirect( self::running_url( $redirect, $run ) );
		exit;
	}

	// -------------------------------------------------------------------------
	// AJAX: chunk processor (shared by both importers)
	// -------------------------------------------------------------------------

	public function ajax_import_chunk(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}
		check_ajax_referer( 'vireo_import', 'nonce' );

		$slug     = isset( $_POST['importer'] ) ? sanitize_key( (string) wp_unslash( $_POST['importer'] ) ) : '';
		$importer = $this->get( $slug );
		if ( ! $importer ) {
			wp_send_json_error( [ 'message' => 'unknown_importer' ], 400 );
		}

		$run = Importer_Base::valid_run( isset( $_POST['run'] ) ? sanitize_key( (string) wp_unslash( $_POST['run'] ) ) : '' );
		if ( '' === $run ) {
			wp_send_json_error( [ 'message' => __( 'This import screen is out of date. Reload it and start the import again.', 'vireo-analytics' ) ], 200 );
		}
		$importer->set_run( $run );

		$offset        = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON blob; values are validated per-key by the importer
		$params_raw    = isset( $_POST['params'] ) ? wp_unslash( (string) $_POST['params'] ) : '';
		$params        = [];
		if ( '' !== $params_raw ) {
			$decoded = json_decode( $params_raw, true );
			if ( is_array( $decoded ) ) {
				$params = $decoded;
			}
		}

		// Captured before the run, because an importer clears its stored
		// parameters when it finishes and the range would be gone by the time
		// there is something to record.
		$range = [ 'start' => '', 'end' => '' ];
		if ( isset( $params['date_start'] ) || isset( $params['date_end'] ) ) {
			$range['start'] = sanitize_text_field( (string) ( $params['date_start'] ?? '' ) );
			$range['end']   = sanitize_text_field( (string) ( $params['date_end'] ?? '' ) );
		} elseif ( method_exists( $importer, 'get_saved_params' ) ) {
			$saved          = (array) $importer->get_saved_params();
			$range['start'] = sanitize_text_field( (string) ( $saved['date_start'] ?? '' ) );
			$range['end']   = sanitize_text_field( (string) ( $saved['date_end'] ?? '' ) );
		}

		// An importer that takes everything it finds has no range to narrow, so
		// a second run is the same history again. The page only offers it
		// behind a confirmation; refuse it here too, so a stale tab or a
		// double click can't get round that.
		if ( 0 === $offset && '' === $range['start'] && '' === $range['end']
			&& null !== Import_History::latest( $slug ) && empty( $params['confirm_overlap'] ) ) {
			wp_send_json_error(
				[
					'message' => sprintf(
						/* translators: %s: name of the analytics plugin */
						__( '%s has already been imported. Running it again would count the same history twice, so it needs confirming first. Reload the page to see what was imported.', 'vireo-analytics' ),
						$importer->name()
					),
				],
				200
			);
		}

		// The offset comes from the browser and the importer's upserts are
		// additive, so replaying a chunk adds its numbers a second time. That's a
		// live risk: the client retries on a dropped response, and the response
		// can drop *after* the writes committed. Keep a server-side ledger of
		// offsets already imported for this run and refuse to apply one twice.
		$ledger_key = Importer_Base::run_option( 'vireo_import_done_', $slug, $run );
		$done       = get_option( $ledger_key, [] );
		$done       = is_array( $done ) ? $done : [];

		// Offset 0 means a fresh run: forget what a previous run imported.
		if ( 0 === $offset ) {
			$done = [];
			self::sweep_run_state( $slug );
		}

		if ( isset( $done[ (string) $offset ] ) ) {
			// Already applied. Don't import again — just move the client along.
			//
			// The step has to come from the ledger, not from a constant. It
			// used to add a flat 100, which is not any importer's chunk size:
			// the date-windowed ones advance 14 days (Analytics) or 30
			// (Jetpack), so replaying one chunk would have skipped months
			// without a word. Nothing retries today, so this never fired, but
			// it would have been silent when it did.
			$recorded = $done[ (string) $offset ];

			wp_send_json_success(
				[
					'imported'    => 0,
					'next_offset' => (int) ( $recorded['next_offset'] ?? $offset ),
					'total'       => (int) ( $recorded['total'] ?? 0 ),
					'done'        => (bool) ( $recorded['done'] ?? false ),
					'replayed'    => true,
				]
			);
		}

		// Days Vireo Analytics tracked itself already hold a count, and an
		// import adds to it, so a day both plugins recorded would be counted
		// twice. Stop the run the day before Vireo's own data begins unless
		// the person chose to include those days. Worked out once, when the
		// run starts: its own writes would move the answer if it were redone
		// for every chunk.
		$cutoff_key = Importer_Base::run_option( 'vireo_import_cutoff_', $slug, $run );
		$run_state  = get_option( $cutoff_key, null );
		if ( 0 === $offset || ! is_array( $run_state ) ) {
			$include   = ! empty( $params['include_own_days'] ) || self::take_include_own( $slug, $run );
			$own_from  = (string) Import_History::own_data_start();
			$run_state = [
				'own_from' => $own_from,
				'cutoff'   => $include ? '' : $own_from,
			];
			update_option( $cutoff_key, $run_state, false );
		}
		$cutoff = (string) ( $run_state['cutoff'] ?? '' );
		$importer->set_cutoff( $cutoff );

		try {
			$result = $importer->import_chunk( $params, $offset, 100 );
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[vireo-analytics] import error: ' . $e->getMessage() );
			}
			// A failed import used to leave the WordPress.com API key stashed
			// server-side with nothing to clean it up.
			if ( 'jetpack' === $slug ) {
				$this->get_jetpack()->clear_params();
			}
			if ( 'plausible' === $slug ) {
				$this->get_plausible()->abort();
			}
			if ( 'backup' === $slug ) {
				$this->get_backup()->abort();
			}
			wp_send_json_error( [ 'message' => $e->getMessage() ], 200 );
		}

		// A failed chunk still returns done => true, so the client stops looping
		// rather than hammering an endpoint that cannot succeed. It is not a
		// finished import though, and everything below treats done as finished:
		// it would file the range in the history, clear the replay ledger, and
		// hand the browser a success envelope the JS renders as "Import
		// complete". The error message, which says exactly what to fix, never
		// reached the screen. Bail before any of that.
		if ( '' !== (string) ( $result['error'] ?? '' ) ) {
			wp_send_json_error( [ 'message' => (string) $result['error'] ], 200 );
		}

		$done[ (string) $offset ] = [
			'total'       => (int) ( $result['total'] ?? 0 ),
			'done'        => (bool) ( $result['done'] ?? false ),
			// Remembered so a replay resumes where this chunk actually ended,
			// whatever window size the importer uses.
			'next_offset' => (int) ( $result['next_offset'] ?? $offset ),
			'written'     => $importer->written(),
		];
		update_option( $ledger_key, $done, false );

		if ( ! empty( $result['done'] ) ) {
			$written = [
				'first'     => '',
				'last'      => '',
				'days'      => 0,
				'pageviews' => 0,
				'own_from'  => (string) ( $run_state['own_from'] ?? '' ),
			];
			foreach ( $done as $chunk ) {
				$w = (array) ( $chunk['written'] ?? [] );
				if ( '' !== (string) ( $w['first'] ?? '' ) && ( '' === $written['first'] || $w['first'] < $written['first'] ) ) {
					$written['first'] = (string) $w['first'];
				}
				if ( (string) ( $w['last'] ?? '' ) > $written['last'] ) {
					$written['last'] = (string) $w['last'];
				}
				$written['days']      += (int) ( $w['days'] ?? 0 );
				$written['pageviews'] += (int) ( $w['pageviews'] ?? 0 );
			}

			delete_option( $ledger_key );
			delete_option( $cutoff_key );

			Import_History::record( $slug, $range['start'], $range['end'], $written );

			$latest            = Import_History::latest( $slug );
			$result['summary'] = null === $latest ? '' : Import_History::summary( $latest );
		}

		wp_send_json_success( $result );
	}

	// -------------------------------------------------------------------------
	// AJAX: dismiss onboarding banner
	// -------------------------------------------------------------------------

	public function ajax_dismiss_onboarding(): void {
		if ( ! current_user_can( 'view_vireo_analytics' ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}
		check_ajax_referer( 'vireo_import', 'nonce' );

		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			update_user_meta( $user_id, self::ONBOARDING_USER_META, 1 );
		}
		wp_send_json_success();
	}

	// -------------------------------------------------------------------------
	// Onboarding detection (called from Dashboard)
	// -------------------------------------------------------------------------

	/**
	 * Should we show the onboarding banner on the Overview page?
	 * Memoized per-request so the dashboard's render + enqueue paths don't
	 * each hit the DB.
	 */
	public function should_show_onboarding(): bool {
		static $cached = null;
		if ( null !== $cached ) {
			return $cached;
		}

		$user_id = get_current_user_id();
		if ( $user_id > 0 && get_user_meta( $user_id, self::ONBOARDING_USER_META, true ) ) {
			$cached = false;
			return false;
		}

		// Only show when LA has no data yet — we don't want to nag people
		// who have been running LA for months and just happen to also have
		// Koko installed.
		if ( $this->vireo_has_data() ) {
			$cached = false;
			return false;
		}

		// Any source with data here is worth offering, not just the two this
		// banner originally knew about. Short-circuits on the first hit, so a
		// site with nothing to import still pays only the cheap table checks.
		$cached = false;
		foreach ( $this->importers as $slug => $importer ) {
			if ( in_array( $slug, self::EXTERNAL_SOURCES, true ) ) {
				continue; // Nothing local to detect.
			}
			if ( $importer->is_available() ) {
				$cached = true;
				break;
			}
		}
		return $cached;
	}

	/**
	 * @return array{koko:bool, jetpack:bool, koko_summary:?array}
	 */
	public function onboarding_context(): array {
		$koko         = $this->get_koko();
		$jetpack      = $this->get_jetpack();
		$koko_avail   = $koko->is_available();
		$koko_summary = $koko_avail ? $koko->get_summary() : null;
		// Every source we actually found, so the banner can name them instead
		// of guessing. Koko keeps its day count because the copy uses it.
		$sources = [];
		foreach ( $this->importers as $slug => $importer ) {
			if ( in_array( $slug, self::EXTERNAL_SOURCES, true ) || ! $importer->is_available() ) {
				continue;
			}
			$url = $this->page_url_for( $slug );
			if ( '' === $url ) {
				continue;
			}
			$sources[] = [
				'slug' => $slug,
				'name' => $importer->name(),
				'url'  => $url,
			];
		}

		return [
			'koko'         => $koko_avail,
			'jetpack'      => $jetpack->is_available(),
			'koko_summary' => $koko_summary,
			'sources'      => $sources,
		];
	}

	private function vireo_has_data(): bool {
		global $wpdb;
		$table = Sites::table( 'site_stats' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- aggregator output
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i LIMIT 1', $table ) );
		return $count > 0;
	}
}
