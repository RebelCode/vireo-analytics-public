<?php
/**
 * Vireo Analytics → Settings submenu.
 *
 * Uses the WordPress Settings API: a single option row `vireo_settings` holds
 * every field, sanitized through one `sanitize_callback`. The tracker reads
 * exclusion rules straight from this option on each request.
 */

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION_KEY = 'vireo_settings';
	public const PAGE_SLUG  = 'vireo-analytics-settings';

	/** Default tab: what someone opening Settings for the first time needs. */
	public const DEFAULT_TAB = 'general';

	private static ?self $instance = null;

	/**
	 * The screen was one page of twelve sections. These group them, in the
	 * order someone setting the plugin up would reach for them.
	 *
	 * @return array<string,string> Tab id => label.
	 */
	public static function tabs(): array {
		return [
			'general'      => __( 'General', 'vireo-analytics' ),
			'tracking'     => __( 'Tracking', 'vireo-analytics' ),
			'email'        => __( 'Email digest', 'vireo-analytics' ),
			'integrations' => __( 'Search Console & API', 'vireo-analytics' ),
			'data'         => __( 'Import & export', 'vireo-analytics' ),
		];
	}

	public static function current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which tab to show; read-only and whitelisted below
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : '';
		return isset( self::tabs()[ $tab ] ) ? $tab : self::DEFAULT_TAB;
	}

	/** Settings screen URL, opened on a tab. */
	public static function url( string $tab = self::DEFAULT_TAB, array $args = [] ): string {
		$args = [ 'page' => self::PAGE_SLUG ] + ( self::DEFAULT_TAB === $tab ? [] : [ 'tab' => $tab ] ) + $args;
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Priority 20 — appears after Live (10).
		add_action( 'admin_menu', [ $this, 'register_submenu' ], 20 );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_notices', [ $this, 'mmdb_missing_notice' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		add_action( 'admin_post_vireo_send_test_email', [ $this, 'handle_send_test_email' ] );
		add_action( 'admin_post_vireo_download_geodb', [ $this, 'handle_download_geodb' ] );
		add_action( 'admin_post_vireo_purge_excluded_countries', [ $this, 'handle_purge_excluded_countries' ] );

		// Connected sites: add one, remove one. Each is its own form outside
		// the options form, so they can carry their own nonce and never run
		// twice the way the options sanitizer does.
		add_action( 'admin_post_vireo_add_connected_site', [ $this, 'handle_add_connected_site' ] );
		add_action( 'admin_post_vireo_delete_connected_site', [ $this, 'handle_delete_connected_site' ] );

		// Bot-filter test AJAX. Paste a UA, hear back whether it'd be filtered.
		add_action( 'wp_ajax_vireo_test_bot_filter', [ $this, 'handle_test_bot_filter' ] );
	}

	/**
	 * Enqueue settings-page assets. Only fires on the Settings screen — WP
	 * supplies the screen hook string and we match it against the slug WP
	 * assigns for an `add_submenu_page()` under our top-level menu.
	 */
	public function enqueue_assets( $hook ): void {
		$hook = (string) $hook;
		// Hook suffix WP assigns to `add_submenu_page( 'vireo-analytics', ... )`.
		if ( 'vireo-analytics_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$css_ver  = (int) @filemtime( VIREO_DIR . 'assets/css/settings-page.css' ) ?: VIREO_VERSION;
		$js_ver   = (int) @filemtime( VIREO_DIR . 'assets/js/settings-page.js' ) ?: VIREO_VERSION;
		$tabs_ver = (int) @filemtime( VIREO_DIR . 'assets/js/admin-tabs.js' ) ?: VIREO_VERSION;

		wp_enqueue_script( 'va-admin-tabs', VIREO_URL . 'assets/js/admin-tabs.js', [], $tabs_ver, true );

		wp_enqueue_style(
			'va-settings-page',
			VIREO_URL . 'assets/css/settings-page.css',
			[],
			$css_ver
		);

		wp_register_script(
			'va-settings-page',
			VIREO_URL . 'assets/js/settings-page.js',
			[ 'wp-i18n' ],
			$js_ver,
			true
		);
		wp_set_script_translations( 'va-settings-page', 'vireo-analytics', VIREO_DIR . 'languages' );

		$config = wp_json_encode(
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'vireo_test_bot_filter' ),
			],
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		);
		if ( false === $config ) {
			$config = '{}';
		}
		wp_add_inline_script( 'va-settings-page', 'window.vireoSettingsPage=' . $config . ';', 'before' );
		wp_enqueue_script( 'va-settings-page' );
	}

	public function register_submenu(): void {
		add_submenu_page(
			'vireo-analytics',
			__( 'Vireo Analytics Settings', 'vireo-analytics' ),
			__( 'Settings', 'vireo-analytics' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Register the single option + sanitize callback. The settings page uses
	 * custom section/field renders (see template) rather than
	 * add_settings_field for each — the polished markup is easier to keep
	 * consistent when rendered directly.
	 */
	public function register_settings(): void {
		register_setting(
			'vireo_settings_group',
			self::OPTION_KEY,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize' ],
				'default'           => [],
			]
		);
		// The referrers the admin blocked, one host per line in the form.
		register_setting(
			'vireo_settings_group',
			Referrer_Spam::OPTION,
			[
				'type'              => 'array',
				'sanitize_callback' => [ self::class, 'sanitize_blocked_referrers' ],
				'default'           => [],
			]
		);
	}

	/**
	 * @param mixed $input The textarea, or an array from the REST API.
	 * @return array<int,string> Canonical hosts.
	 */
	public static function sanitize_blocked_referrers( $input ): array {
		$lines = is_array( $input ) ? $input : vireo_parse_lines( self::sanitize_lines( (string) $input ) );
		$out   = [];
		foreach ( $lines as $line ) {
			$host = vireo_canonical_host( (string) $line );
			if ( '' !== $host && 1 === preg_match( '/^[a-z0-9.-]+$/', $host ) && ! in_array( $host, $out, true ) ) {
				$out[] = $host;
			}
		}
		// Reports cache the exclusion; a change here has to show at once.
		delete_transient( 'vireo_ref_excluded_' . md5( Sites::current() . '|' . implode( ',', Referrer_Spam::blocked() ) ) );
		return $out;
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'vireo-analytics' ) );
		}

		$settings  = vireo_get_settings();
		$mmdb_path = Geo_Lookup::mmdb_path();
		$mmdb_ok   = Geo_Lookup::mmdb_exists();

		// If the host already resolves the country at the edge, this admin request
		// carries the same header a front-end request would, so we can tell the
		// user geo works with no database at all rather than nagging them.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading a request header for a status display, not acting on input
		$geo_header  = Geo_Lookup::country_from_headers( $_SERVER );
		$geo_source  = Geo_Lookup::db_source();
		$geodb_url   = wp_nonce_url( admin_url( 'admin-post.php?action=vireo_download_geodb' ), 'vireo_download_geodb' );

		// Drives the "remove what was already counted" button, which only makes
		// sense to offer when something is actually excluded.
		$va_excluded_country_codes = function_exists( 'vireo_get_exclusions' )
			? (array) ( vireo_get_exclusions()['countries'] ?? [] )
			: [];

		include VIREO_DIR . 'templates/admin/settings-page.php';
	}

	/**
	 * Sanitize + merge submitted fields into the stored settings array.
	 * Invoked by register_setting() when the form posts to options.php.
	 *
	 * @param array<string,mixed>|null $input
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ): array {
		$input   = is_array( $input ) ? $input : [];
		$current = vireo_get_settings();

		$email_day = isset( $input['email_day'] ) ? absint( $input['email_day'] ) : 1;
		$email_day = max( 1, min( 7, $email_day ) );

		$email_hour = isset( $input['email_hour'] ) ? absint( $input['email_hour'] ) : 9;
		$email_hour = max( 0, min( 23, $email_hour ) );

		$email_frequency = isset( $input['email_frequency'] ) ? sanitize_key( (string) $input['email_frequency'] ) : 'weekly';
		if ( ! in_array( $email_frequency, Email_Reports::FREQUENCIES, true ) ) {
			$email_frequency = 'weekly';
		}

		$retention = isset( $input['retention_days'] ) ? (int) $input['retention_days'] : 730;
		// 0 is a valid sentinel for "Never purge" — users pick it from the UI
		// dropdown. Other values are clamped to [30, 3650] to protect the
		// daily purge job from typo'd ranges.
		if ( 0 === $retention ) {
			// allow; stored as 0.
		} else {
			$retention = max( 30, min( 3650, absint( $retention ) ) );
		}

		// Unticking every role submits no field at all, which looks the same as
		// a form without the section, so the form sends a marker to say it was
		// there. Without it the stored list is kept.
		$excluded_roles = (array) ( $current['excluded_roles'] ?? [ 'administrator' ] );
		if ( ! empty( $input['excluded_roles_present'] ) ) {
			$excluded_roles = self::sanitize_roles( $input['excluded_roles'] ?? [], array_keys( wp_roles()->get_names() ) );
		}
		$exclude_admins = in_array( 'administrator', $excluded_roles, true );

		$new = array_merge(
			$current,
			[
				'excluded_roles'           => $excluded_roles,
				// The two admin flags follow the list; Pro's trackers still read
				// `exclude_logged_in_admins`.
				'track_logged_in_admins'   => ! $exclude_admins,
				'exclude_logged_in_admins' => $exclude_admins,
				'retention_days'           => $retention,
				'admin_bar_enabled'        => ! empty( $input['admin_bar_enabled'] ),

				'excluded_ips'             => self::sanitize_lines( $input['excluded_ips'] ?? '' ),
				'excluded_paths'           => self::sanitize_lines( $input['excluded_paths'] ?? '' ),
				'excluded_countries'       => self::sanitize_country_lines( $input['excluded_countries'] ?? '' ),

				'email_enabled'            => ! empty( $input['email_enabled'] ),
				'email_recipients'         => self::sanitize_email_lines( $input['email_recipients'] ?? '' ),
				'email_frequency'          => $email_frequency,
				'email_day'                => $email_day,
				'email_hour'               => $email_hour,

				'auto_event_downloads'     => ! empty( $input['auto_event_downloads'] ),
				'auto_event_forms'         => ! empty( $input['auto_event_forms'] ),
				'auto_event_mailto'        => ! empty( $input['auto_event_mailto'] ),
				'auto_event_tel'           => ! empty( $input['auto_event_tel'] ),
				'auto_event_outbound'      => ! empty( $input['auto_event_outbound'] ),

				'headless_origin'          => $this->sanitize_headless_origin( $input, (string) ( $current['headless_origin'] ?? '' ) ),
			]
		);

		// Capabilities live on the roles rather than in this option, so granting
		// them is a side effect here.
		//
		// Two things make that harder than it looks. WordPress runs this
		// callback more than once per save, so the result has to carry the
		// chosen roles forward or the second pass sees none posted and revokes
		// what the first just granted. And unticking every box submits no field
		// at all, which is indistinguishable from the form not having the
		// section on it, so the form sends a marker to say it did.
		if ( class_exists( Roles::class ) && ! empty( $input['analytics_roles_present'] ) ) {
			$posted_roles = isset( $input['analytics_roles'] ) && is_array( $input['analytics_roles'] )
				? array_map( 'sanitize_key', $input['analytics_roles'] )
				: [];

			Roles::apply( $posted_roles );

			$new['analytics_roles']         = Roles::with_access();
			$new['analytics_roles_present'] = 1;
		}

		add_settings_error(
			self::OPTION_KEY,
			'vireo_settings_saved',
			__( 'Settings saved.', 'vireo-analytics' ),
			'updated'
		);

		return $new;
	}

	/**
	 * Admin-bar wide notice when the MMDB file is missing. Throttled to once
	 * per week per user so it doesn't become noise — dismissing dismisses
	 * for the week; the file being installed clears the notice entirely.
	 */
	public function mmdb_missing_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! class_exists( Geo_Lookup::class ) || Geo_Lookup::resolves_countries() ) {
			return;
		}

		// Don't nag on the settings page itself — the status is shown there
		// prominently already.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && false !== strpos( (string) $screen->id, self::PAGE_SLUG ) ) {
			return;
		}

		$user_id      = get_current_user_id();
		$meta_key     = 'vireo_mmdb_notice_last_seen';
		$last_seen    = $user_id ? (int) get_user_meta( $user_id, $meta_key, true ) : 0;
		$one_week_ago = time() - WEEK_IN_SECONDS;

		if ( $last_seen > $one_week_ago ) {
			return;
		}

		if ( $user_id ) {
			update_user_meta( $user_id, $meta_key, time() );
		}

		$url = self::url( 'tracking' ) . '#va-geo';
		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Vireo Analytics:', 'vireo-analytics' ) . '</strong> '
			. esc_html__( 'Geo-location database is missing. Countries will show as unknown until you install it.', 'vireo-analytics' )
			. ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Setup instructions', 'vireo-analytics' ) . '</a></p></div>';
	}

	/**
	 * admin-post handler for the "Send test email" button on the settings page.
	 */
	public function handle_send_test_email(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'vireo-analytics' ), 403 );
		}
		check_admin_referer( 'vireo_send_test_email' );

		// Stamp a nonce so the redirect-back notice read on the settings page is
		// verifiable (this handler is already nonce-checked above).
		$result_nonce = wp_create_nonce( 'vireo_test_email_result' );
		$redirect     = self::url( 'email', [ '_vrn' => $result_nonce ] );

		if ( ! class_exists( Email_Reports::class ) ) {
			wp_safe_redirect( add_query_arg( 'vireo_test_email', 'missing', $redirect ) );
			exit;
		}

		// wp_mail() only says true or false. Keep the reason it gives on failure
		// so the notice can show it, rather than pointing at "error logs" most
		// site owners cannot open.
		$mail_error = '';
		$capture    = static function ( $error ) use ( &$mail_error ): void {
			if ( is_wp_error( $error ) ) {
				$mail_error = $error->get_error_message();
			}
		};
		add_action( 'wp_mail_failed', $capture );
		$result = Email_Reports::instance()->send_test();
		remove_action( 'wp_mail_failed', $capture );

		$flag = $result ? 'ok' : 'fail';
		if ( ! $result && '' !== $mail_error ) {
			set_transient( 'vireo_test_email_error_' . get_current_user_id(), $mail_error, 5 * MINUTE_IN_SECONDS );
		}

		wp_safe_redirect( add_query_arg( 'vireo_test_email', $flag, $redirect ) );
		exit;
	}

	/**
	 * admin-post handler for "Remove what was already counted".
	 *
	 * Excluding a country has always been prospective: it stops the traffic
	 * being counted from that moment, and says nothing about what is already
	 * stored. A site owner who excludes a country and then looks at a 30-day
	 * report still sees it, reasonably concludes the setting is broken, and
	 * has no way to clear it. This is that way.
	 *
	 * It clears the countries report and the lookup cache, which are the only
	 * two places a country is recorded. Pageview and visitor totals keep those
	 * visits, because nothing else stores which country a visit came from and
	 * inventing an attribution after the fact would be worse than leaving it.
	 * The button says so rather than implying a clean sweep.
	 */
	public function handle_purge_excluded_countries(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'vireo-analytics' ), 403 );
		}
		check_admin_referer( 'vireo_purge_excluded_countries' );

		global $wpdb;

		$codes = function_exists( 'vireo_get_exclusions' )
			? (array) ( vireo_get_exclusions()['countries'] ?? [] )
			: [];

		$removed = 0;
		if ( ! empty( $codes ) ) {
			$va_geo_stats = Sites::table( 'geo_stats' );
			$va_ip_cache  = Sites::table( 'ip_cache' );
			$placeholders = implode( ',', array_fill( 0, count( $codes ), '%s' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off maintenance action
			$removed = (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$va_geo_stats} WHERE country_code IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built from a counted array above
					$codes
				)
			);

			// Drop cached lookups for those countries too, so a later change of
			// mind re-resolves rather than replaying a stale answer.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off maintenance action
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$va_ip_cache} WHERE country_code IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built from a counted array above
					$codes
				)
			);
		}

		wp_safe_redirect(
			self::url(
				'tracking',
				[
					'vireo_purged' => $removed,
					'_vrn'         => wp_create_nonce( 'vireo_geodb_result' ),
				]
			) . '#va-geo'
		);
		exit;
	}

	/**
	 * admin-post handler for "Connect site": name and address from the form.
	 */
	public function handle_add_connected_site(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'vireo-analytics' ), 403 );
		}
		check_admin_referer( 'vireo_add_connected_site' );

		$name   = isset( $_POST['site_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['site_name'] ) ) : '';
		$origin = isset( $_POST['site_origin'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['site_origin'] ) ) : '';

		$result = Sites::add( $name, $origin );

		$this->redirect_after_site_change( is_wp_error( $result ) ? $result : 'added' );
	}

	/**
	 * admin-post handler for "Remove": drops that site's tables and buffer.
	 *
	 * The confirmation is the form's own: the button only submits once the
	 * person has typed the site's name back, and that name is checked here
	 * too so a request without it does nothing.
	 */
	public function handle_delete_connected_site(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'vireo-analytics' ), 403 );
		}
		check_admin_referer( 'vireo_delete_connected_site' );

		$site_id = isset( $_POST['site_id'] ) ? absint( $_POST['site_id'] ) : 0;
		$typed   = isset( $_POST['confirm_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['confirm_name'] ) ) : '';
		$site    = Sites::get( $site_id );

		if ( null === $site ) {
			$this->redirect_after_site_change( new \WP_Error( 'vireo_site_missing', __( 'That site is no longer connected.', 'vireo-analytics' ) ) );
		}
		if ( trim( $typed ) !== $site['name'] ) {
			$this->redirect_after_site_change( new \WP_Error( 'vireo_site_confirm', __( 'Nothing was removed. Type the site\'s name exactly as shown to confirm.', 'vireo-analytics' ) ) );
		}

		Sites::delete( $site_id );

		$this->redirect_after_site_change( 'deleted' );
	}

	/**
	 * Back to the connected sites section with a result flag, or the error
	 * message parked in a short-lived transient for the notice to read.
	 *
	 * @param string|\WP_Error $outcome
	 */
	private function redirect_after_site_change( $outcome ): void {
		$redirect = self::url( 'tracking', [ '_vrn' => wp_create_nonce( 'vireo_site_result' ) ] );

		if ( is_wp_error( $outcome ) ) {
			set_transient( 'vireo_site_error_' . get_current_user_id(), $outcome->get_error_message(), MINUTE_IN_SECONDS );
			wp_safe_redirect( add_query_arg( 'vireo_site', 'error', $redirect ) . '#va-connected-sites' );
			exit;
		}

		wp_safe_redirect( add_query_arg( 'vireo_site', $outcome, $redirect ) . '#va-connected-sites' );
		exit;
	}

	/**
	 * admin-post handler for the one-click "Download country database" button.
	 * Fetches DB-IP Country Lite (free, no account) and installs it.
	 */
	public function handle_download_geodb(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'vireo-analytics' ), 403 );
		}
		check_admin_referer( 'vireo_download_geodb' );

		$result_nonce = wp_create_nonce( 'vireo_geodb_result' );
		$redirect     = self::url( 'tracking', [ '_vrn' => $result_nonce ] );

		$result = Geo_Lookup::download_dbip();
		if ( is_wp_error( $result ) ) {
			set_transient( 'vireo_geodb_error', $result->get_error_message(), 60 );
			wp_safe_redirect( add_query_arg( 'vireo_geodb', 'fail', $redirect ) . '#va-geo' );
			exit;
		}

		wp_safe_redirect( add_query_arg( 'vireo_geodb', 'ok', $redirect ) . '#va-geo' );
		exit;
	}

	/**
	 * Whitelist textarea input to a conservative character set, one entry per
	 * line. Paths / IPs / CIDRs work but raw
	 * HTML or shell metacharacters don't.
	 */
	/**
	 * Role slugs from the form, kept only when the site has that role. A slug
	 * that does not exist here would sit in the option excluding nobody.
	 *
	 * @param mixed    $raw   Posted value.
	 * @param string[] $known Role slugs that exist on this site.
	 * @return string[]
	 */
	public static function sanitize_roles( $raw, array $known ): array {
		$out = [];
		foreach ( (array) $raw as $slug ) {
			$slug = sanitize_key( (string) $slug );
			if ( '' !== $slug && in_array( $slug, $known, true ) && ! in_array( $slug, $out, true ) ) {
				$out[] = $slug;
			}
		}
		return $out;
	}

	/**
	 * Two-letter country codes, one per line. Anything that is not a plausible
	 * ISO 3166-1 alpha-2 code is dropped rather than stored, so a typo cannot
	 * silently exclude nothing while looking like it works.
	 */
	public static function sanitize_country_lines( string $text ): string {
		$out = [];
		foreach ( explode( "\n", str_replace( "\r\n", "\n", wp_unslash( $text ) ) ) as $line ) {
			$line = strtoupper( trim( $line ) );
			if ( 1 === preg_match( '/^[A-Z]{2}$/', $line ) && ! in_array( $line, $out, true ) ) {
				$out[] = $line;
			}
		}
		return implode( "\n", $out );
	}

	public static function sanitize_lines( string $text ): string {
		$text  = wp_unslash( $text );
		$text  = str_replace( "\r\n", "\n", $text );
		$lines = explode( "\n", $text );
		$out   = [];
		foreach ( $lines as $line ) {
			$line = trim( $line );
			// Allow alphanumerics, /, :, ., -, _, *, ?, [, ], # (for comments).
			$line = preg_replace( '~[^A-Za-z0-9/:\.\-_\*\?\[\]#]~', '', $line );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}
		return implode( "\n", $out );
	}

	/**
	 * AJAX: test a pasted UA against the bot filter and return a human-
	 * readable verdict. Used by the Settings page "Bot filter" tester.
	 */
	public function handle_test_bot_filter(): void {
		check_ajax_referer( 'vireo_test_bot_filter', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Forbidden.', 'vireo-analytics' ) ], 403 );
		}

		$ua = isset( $_POST['ua'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['ua'] ) ) : '';
		$ua = substr( trim( $ua ), 0, 500 ); // UA headers are bounded by most stacks.

		if ( '' === $ua ) {
			wp_send_json_success(
				[
					'is_bot'  => true,
					'verdict' => __( 'Filtered as bot.', 'vireo-analytics' ),
					'reason'  => __( 'Empty User-Agent is treated as a bot by Bot_Filter::is_bot_request.', 'vireo-analytics' ),
					'pattern' => '',
				]
			);
		}

		// Detect which part of the default pattern matched, if any, so the
		// response can explain the decision.
		$matched_pattern = '';
		$pattern         = (string) apply_filters( 'vireo_bot_ua_pattern', Bot_Filter::DEFAULT_UA_PATTERN );
		if ( preg_match( '~(' . $pattern . ')~i', $ua, $m ) ) {
			$matched_pattern = (string) $m[1];
		}

		// Pass an empty header map so we don't pick up the admin's current
		// Cloudflare headers — the test is specifically about the UA.
		$is_bot = Bot_Filter::is_bot_request( $ua, [] );

		$verdict = $is_bot
			? __( 'Filtered as bot. This request would NOT be tracked.', 'vireo-analytics' )
			: __( 'Would be tracked. This request passes the bot filter.', 'vireo-analytics' );

		$reason = $is_bot
			? ( '' !== $matched_pattern
				? sprintf(
					/* translators: %s: substring of the User-Agent that matched the bot regex. */
					__( 'Matched the bot-UA pattern: %s', 'vireo-analytics' ),
					$matched_pattern
				)
				: __( 'Matched via heuristic (e.g. empty UA) rather than the UA regex.', 'vireo-analytics' ) )
			: __( 'No bot-UA pattern matched, and no Cloudflare bot signals would fire for a typical request.', 'vireo-analytics' );

		wp_send_json_success(
			[
				'is_bot'  => $is_bot,
				'verdict' => $verdict,
				'reason'  => $reason,
				'pattern' => $matched_pattern,
			]
		);
	}

	/**
	 * The one front-end origin allowed to send beacons from another site.
	 *
	 * Reduced to scheme://host[:port]. Something that is not an origin at all
	 * keeps the previous value and says so, rather than silently storing
	 * nothing while the field looks saved.
	 *
	 * @param array<string,mixed> $input
	 */
	private function sanitize_headless_origin( array $input, string $previous ): string {
		if ( ! array_key_exists( 'headless_origin', $input ) ) {
			return $previous;
		}

		$raw = trim( sanitize_text_field( wp_unslash( (string) $input['headless_origin'] ) ) );
		if ( '' === $raw ) {
			return '';
		}

		$origin = Headless::normalize_origin( $raw );
		if ( '' === $origin ) {
			add_settings_error(
				self::OPTION_KEY,
				'vireo_headless_origin',
				__( 'The front-end address was not saved. Enter the full address of the site, starting with http:// or https://, for example https://www.example.com.', 'vireo-analytics' ),
				'error'
			);
			return $previous;
		}

		return $origin;
	}

	/**
	 * Textarea of emails, one per line. Drops lines that don't validate.
	 */
	public static function sanitize_email_lines( string $text ): string {
		$text = wp_unslash( $text );
		$text = str_replace( "\r\n", "\n", $text );
		$out  = [];
		foreach ( explode( "\n", $text ) as $line ) {
			$email = sanitize_email( trim( $line ) );
			if ( '' !== $email ) {
				$out[] = $email;
			}
		}
		return implode( "\n", array_unique( $out ) );
	}
}
