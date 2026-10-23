<?php
/**
 * Vireo Analytics → Settings page markup.
 *
 * Expected in scope (provided by Settings::render_page):
 *   $settings  array  Merged settings from vireo_get_settings().
 *   $mmdb_path string Expected absolute path to the MMDB file.
 *   $mmdb_ok   bool   Whether the MMDB file is installed + readable.
 */

defined( 'ABSPATH' ) || exit;


// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template partial included inside a render method; locals are method-scoped at runtime, not true globals.
use VireoAnalytics\Settings;
use VireoAnalytics\Email_Reports;
use VireoAnalytics\Retention;
use VireoAnalytics\CSV_Export;
use VireoAnalytics\Feedback;

$day_labels = [
	1 => __( 'Monday', 'vireo-analytics' ),
	2 => __( 'Tuesday', 'vireo-analytics' ),
	3 => __( 'Wednesday', 'vireo-analytics' ),
	4 => __( 'Thursday', 'vireo-analytics' ),
	5 => __( 'Friday', 'vireo-analytics' ),
	6 => __( 'Saturday', 'vireo-analytics' ),
	7 => __( 'Sunday', 'vireo-analytics' ),
];

$recipients_default = get_option( 'admin_email' );
$recipients_value   = (string) ( $settings['email_recipients'] ?? '' );

// Surface the result of a Send-test redirect. The redirect (from a
// nonce-checked handler) stamps `_vrn`; honor the notice only when it verifies.
$test_nonce = isset( $_GET['_vrn'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['_vrn'] ) ) : '';
$test_flag  = ( '' !== $test_nonce && false !== wp_verify_nonce( $test_nonce, 'vireo_test_email_result' ) && isset( $_GET['vireo_test_email'] ) )
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified on the line above
	? sanitize_text_field( wp_unslash( (string) $_GET['vireo_test_email'] ) )
	: '';
?>
<div class="wrap va-root va-settings">

	<h1 class="va-settings-title">
		<img class="va-brand-mark" src="<?php echo esc_url( VIREO_URL . 'assets/img/vireo-mark.png' ); ?>" width="28" height="28" alt="">
		<?php esc_html_e( 'Vireo Analytics Settings', 'vireo-analytics' ); ?>
	</h1>

	<?php settings_errors( Settings::OPTION_KEY ); ?>

	<?php
	$va_test_to = wp_get_current_user()->user_email ?: (string) get_option( 'admin_email' );
	?>
	<?php if ( 'ok' === $test_flag ) : ?>
		<div class="notice notice-success is-dismissible">
			<p>
				<?php
				/* translators: %s: email address the test was sent to. */
				echo esc_html( sprintf( __( 'WordPress handed the test email for %s to your server\'s mail system.', 'vireo-analytics' ), $va_test_to ) );
				?>
			</p>
			<p><?php esc_html_e( 'If it has not arrived within a few minutes, check your spam folder. If it is not there either, your host is not delivering mail sent from WordPress, which affects password resets and every other email the site sends. An SMTP plugin such as WP Mail SMTP or FluentSMTP usually fixes it.', 'vireo-analytics' ); ?></p>
		</div>
	<?php elseif ( 'fail' === $test_flag ) : ?>
		<?php
		$va_mail_error = (string) get_transient( 'vireo_test_email_error_' . get_current_user_id() );
		delete_transient( 'vireo_test_email_error_' . get_current_user_id() );
		?>
		<div class="notice notice-error is-dismissible">
			<p>
				<?php
				/* translators: %s: email address the test was sent to. */
				echo esc_html( sprintf( __( 'WordPress could not send the test email to %s.', 'vireo-analytics' ), $va_test_to ) );
				?>
			</p>
			<?php if ( '' !== $va_mail_error ) : ?>
				<p>
					<?php
					/* translators: %s: error message returned by the mail system. */
					echo esc_html( sprintf( __( 'The mail system said: %s', 'vireo-analytics' ), $va_mail_error ) );
					?>
				</p>
			<?php endif; ?>
			<p><?php esc_html_e( 'This is a problem with how the site sends mail, not with the digest. An SMTP plugin such as WP Mail SMTP or FluentSMTP usually fixes it.', 'vireo-analytics' ); ?></p>
		</div>
	<?php elseif ( 'missing' === $test_flag ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Email reports class is not loaded.', 'vireo-analytics' ); ?></p></div>
	<?php endif; ?>

	<?php
	$geodb_flag = ( '' !== $test_nonce && false !== wp_verify_nonce( $test_nonce, 'vireo_geodb_result' ) && isset( $_GET['vireo_geodb'] ) )
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified on the line above
		? sanitize_text_field( wp_unslash( (string) $_GET['vireo_geodb'] ) )
		: '';
	if ( 'ok' === $geodb_flag ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Country database installed. Countries will resolve from now on.', 'vireo-analytics' ); ?></p></div>
	<?php elseif ( 'fail' === $geodb_flag ) :
		$geodb_err = get_transient( 'vireo_geodb_error' );
		delete_transient( 'vireo_geodb_error' );
		?>
		<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $geodb_err ? (string) $geodb_err : __( 'The country database could not be installed.', 'vireo-analytics' ) ); ?></p></div>
	<?php endif; ?>

	<?php
	$va_site_flag = ( '' !== $test_nonce && false !== wp_verify_nonce( $test_nonce, 'vireo_site_result' ) && isset( $_GET['vireo_site'] ) )
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified on the line above
		? sanitize_key( wp_unslash( (string) $_GET['vireo_site'] ) )
		: '';
	if ( 'added' === $va_site_flag ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Site connected. Paste its tag into the site and its visits will start showing on the dashboard.', 'vireo-analytics' ); ?></p></div>
	<?php elseif ( 'deleted' === $va_site_flag ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Site removed, along with everything recorded for it.', 'vireo-analytics' ); ?></p></div>
	<?php elseif ( 'error' === $va_site_flag ) :
		$va_site_error = get_transient( 'vireo_site_error_' . get_current_user_id() );
		delete_transient( 'vireo_site_error_' . get_current_user_id() );
		?>
		<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $va_site_error ? (string) $va_site_error : __( 'That did not work.', 'vireo-analytics' ) ); ?></p></div>
	<?php endif; ?>

	<?php
	$va_purged = ( '' !== $test_nonce && false !== wp_verify_nonce( $test_nonce, 'vireo_geodb_result' ) && isset( $_GET['vireo_purged'] ) )
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified on the line above
		? max( 0, (int) $_GET['vireo_purged'] )
		: null;
	if ( null !== $va_purged ) : ?>
		<div class="notice notice-success is-dismissible">
			<p>
				<?php
				printf(
					/* translators: %s: number of removed daily country rows. */
					esc_html( _n( '%s stored day removed from the countries report.', '%s stored days removed from the countries report.', $va_purged, 'vireo-analytics' ) ),
					esc_html( number_format_i18n( $va_purged ) )
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<?php
	$va_tab = Settings::current_tab();

	// Each block names the tabs it shows on. A tab can be split around the
	// options form, so one id can appear on two blocks.
	$va_panel = static function ( string $tabs ) use ( $va_tab ): void {
		echo ' data-va-tab-panel="' . esc_attr( $tabs ) . '"';
		// Named by its tab for screen readers. A block shared by several tabs
		// (the Save button) stays a plain group.
		if ( false === strpos( $tabs, ' ' ) ) {
			echo ' role="tabpanel" aria-labelledby="va-settings-tab-' . esc_attr( $tabs ) . '"';
		}
		if ( ! in_array( $va_tab, explode( ' ', $tabs ), true ) ) {
			echo ' hidden';
		}
	};
	?>
	<nav class="nav-tab-wrapper va-settings-tabs" data-va-tabs data-va-tabs-default="<?php echo esc_attr( Settings::DEFAULT_TAB ); ?>"
		data-va-tabs-strip="settings-updated vireo_test_email vireo_geodb vireo_purged vireo_gsc vireo_psi vireo_city_db vireo_site _vrn"
		role="tablist" aria-label="<?php esc_attr_e( 'Settings sections', 'vireo-analytics' ); ?>">
		<?php foreach ( Settings::tabs() as $va_tab_id => $va_tab_label ) : ?>
			<a class="nav-tab<?php echo $va_tab_id === $va_tab ? ' nav-tab-active' : ''; ?>"
				href="<?php echo esc_url( Settings::url( $va_tab_id ) ); ?>"
				data-va-tab="<?php echo esc_attr( $va_tab_id ); ?>"
				id="va-settings-tab-<?php echo esc_attr( $va_tab_id ); ?>"
				role="tab"
				aria-selected="<?php echo $va_tab_id === $va_tab ? 'true' : 'false'; ?>"
				tabindex="<?php echo $va_tab_id === $va_tab ? '0' : '-1'; ?>"><?php echo esc_html( $va_tab_label ); ?></a>
		<?php endforeach; ?>
	</nav>

	<form method="post" action="options.php" novalidate>
		<?php settings_fields( 'vireo_settings_group' ); ?>

		<div class="va-tab-panel"<?php $va_panel( 'general' ); ?>>
			<h2 class="va-section-title"><?php esc_html_e( 'General', 'vireo-analytics' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Do not count', 'vireo-analytics' ); ?></th>
					<td>
						<?php
						$va_excluded_roles = (array) ( $settings['excluded_roles'] ?? [] );
						$va_all_roles      = array_map( 'translate_user_role', wp_roles()->get_names() );
						?>
						<fieldset class="va-roles">
							<legend class="screen-reader-text"><?php esc_html_e( 'Roles whose visits are not counted', 'vireo-analytics' ); ?></legend>
							<input type="hidden" name="vireo_settings[excluded_roles_present]" value="1" />
							<?php foreach ( $va_all_roles as $va_role_slug => $va_role_name ) : ?>
								<label>
									<input type="checkbox"
										name="vireo_settings[excluded_roles][]"
										value="<?php echo esc_attr( $va_role_slug ); ?>"
										<?php checked( in_array( $va_role_slug, $va_excluded_roles, true ) ); ?> />
									<?php echo esc_html( $va_role_name ); ?>
								</label><br />
							<?php endforeach; ?>
						</fieldset>
						<p class="description"><?php esc_html_e( 'Visits from people logged in with a ticked role are left out of every report. Administrators are ticked by default so your own browsing does not pollute the stats; tick editors, authors or shop managers to leave out the rest of the team.', 'vireo-analytics' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Toolbar', 'vireo-analytics' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="vireo_settings[admin_bar_enabled]" value="1" <?php checked( ! empty( $settings['admin_bar_enabled'] ) ); ?> />
							<?php esc_html_e( "Show today's pageviews in the admin toolbar.", 'vireo-analytics' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="va_retention_days"><?php esc_html_e( 'Data retention', 'vireo-analytics' ); ?></label></th>
					<td>
						<?php
						$retention_current = (int) ( $settings['retention_days'] ?? 730 );
						$retention_presets = class_exists( Retention::class ) ? Retention::presets() : [ 730 => __( '2 years', 'vireo-analytics' ) ];
						$db_usage          = class_exists( Retention::class ) ? Retention::database_usage() : [ 'mb' => 0.0, 'table_count' => 0 ];
						// If the stored value isn't one of the presets, include it as
						// a "Custom (N days)" option so the dropdown round-trips.
						$has_preset = array_key_exists( $retention_current, $retention_presets );
						?>
						<select id="va_retention_days" name="vireo_settings[retention_days]">
							<?php foreach ( $retention_presets as $days => $label ) : ?>
								<option value="<?php echo (int) $days; ?>" <?php selected( $retention_current, $days ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
							<?php if ( ! $has_preset ) : ?>
								<option value="<?php echo (int) $retention_current; ?>" selected>
									<?php
									/* translators: %d: number of days. */
									echo esc_html( sprintf( _n( 'Custom (%d day)', 'Custom (%d days)', (int) $retention_current, 'vireo-analytics' ), (int) $retention_current ) );
									?>
								</option>
							<?php endif; ?>
						</select>
						<p class="description">
							<?php esc_html_e( 'Rows in daily rollup tables older than this are purged by a daily cron job.', 'vireo-analytics' ); ?>
							<br />
							<?php
							/* translators: 1: disk usage in MB, 2: number of tables. */
							echo esc_html( sprintf( _n( 'Current usage: %1$s MB across %2$d analytics table.', 'Current usage: %1$s MB across %2$d analytics tables.', (int) $db_usage['table_count'], 'vireo-analytics' ), number_format_i18n( (float) $db_usage['mb'], 1 ), (int) $db_usage['table_count'] ) );
							?>
						</p>
					</td>
				</tr>
			</table>

			<h2 class="va-section-title"><?php esc_html_e( 'Who can see the numbers', 'vireo-analytics' ); ?></h2>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Roles with access', 'vireo-analytics' ); ?></th>
					<td>
						<?php
						$va_role_names   = \VireoAnalytics\Roles::assignable();
						$va_roles_active = \VireoAnalytics\Roles::with_access();
						?>
						<fieldset class="va-roles">
							<legend class="screen-reader-text">
								<?php esc_html_e( 'Roles that can view analytics', 'vireo-analytics' ); ?>
							</legend>

							<?php // Unticking everything submits no checkbox at all; this says the section was on the form. ?>
							<input type="hidden" name="vireo_settings[analytics_roles_present]" value="1" />

							<label class="va-role-locked">
								<input type="checkbox" checked disabled aria-describedby="va-role-admin-note" />
								<?php esc_html_e( 'Administrator', 'vireo-analytics' ); ?>
								<span class="description" id="va-role-admin-note">
									<?php esc_html_e( '(always)', 'vireo-analytics' ); ?>
									<span class="screen-reader-text"><?php esc_html_e( 'Administrators can always read the dashboard. This cannot be changed.', 'vireo-analytics' ); ?></span>
								</span>
							</label><br />

							<?php foreach ( $va_role_names as $va_role_slug => $va_role_name ) : ?>
								<label>
									<input type="checkbox"
										name="vireo_settings[analytics_roles][]"
										value="<?php echo esc_attr( $va_role_slug ); ?>"
										<?php checked( in_array( $va_role_slug, $va_roles_active, true ) ); ?> />
									<?php echo esc_html( $va_role_name ); ?>
								</label><br />
							<?php endforeach; ?>
						</fieldset>

						<p class="description">
							<?php esc_html_e( 'Lets someone read the dashboard without making them an administrator. Changing settings, running imports and everything destructive still needs full administrator rights.', 'vireo-analytics' ); ?>
						</p>
					</td>
				</tr>
			</table>
		</div>

		<div class="va-tab-panel"<?php $va_panel( 'tracking' ); ?>>
			<h2 class="va-section-title" id="va-headless"><?php esc_html_e( 'Headless front end', 'vireo-analytics' ); ?></h2>
			<p class="description"><?php esc_html_e( 'If this WordPress site is only the editor and another site (Astro, Next.js, anything else) shows the pages to readers, enter that site\'s address. Its pages can then send pageviews here, and each one is matched to the WordPress post it shows.', 'vireo-analytics' ); ?></p>
			<?php
			$va_headless_origin   = (string) ( $settings['headless_origin'] ?? '' );
			$va_headless_endpoint = \VireoAnalytics\Script_Loader::collect_url();
			$va_headless_tracker  = VIREO_URL . 'assets/js/tracker.js';
			?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="va_headless_origin"><?php esc_html_e( 'Front-end site address', 'vireo-analytics' ); ?></label></th>
					<td>
						<input type="url" id="va_headless_origin" name="vireo_settings[headless_origin]" class="regular-text code" value="<?php echo esc_attr( $va_headless_origin ); ?>" placeholder="https://www.example.com" spellcheck="false">
						<p class="description"><?php esc_html_e( 'The address readers see in their browser, including https://. One site; pageviews sent from any other address are refused. Leave empty if this WordPress site serves its own pages.', 'vireo-analytics' ); ?></p>
					</td>
				</tr>
				<?php if ( '' !== $va_headless_origin ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Add to the front end', 'vireo-analytics' ); ?></th>
					<td>
						<p class="description" style="margin-top:0;"><?php esc_html_e( 'Paste this before </body> on every page. Pages that know their WordPress post can add <meta name="vireo:post-id" content="123">; for a site that changes pages without reloading, add data-spa="history" to the tag.', 'vireo-analytics' ); ?></p>
						<pre class="va-code-block"><code><?php echo esc_html( \VireoAnalytics\Headless::snippet( $va_headless_endpoint, $va_headless_tracker ) ); ?></code></pre>
						<p class="description">
							<?php
							/* translators: %s: the URL beacons are sent to. */
							echo esc_html( sprintf( __( 'Pageviews are sent to %s. Pages are matched to posts by their path, so a front end that keeps the WordPress permalink structure needs nothing else; the vireo_headless_post_id filter maps anything that differs.', 'vireo-analytics' ), $va_headless_endpoint ) );
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'What this cannot see', 'vireo-analytics' ); ?></th>
					<td>
						<p class="description" style="margin-top:0;"><?php esc_html_e( 'Crawlers and AI bots are counted when they request a page from this WordPress site. Requests they make to the front end never reach it, so the crawler figures on the dashboard cover WordPress only; your front-end host\'s logs have the rest.', 'vireo-analytics' ); ?></p>
						<p class="description"><?php esc_html_e( 'Connect Search Console for the front-end address, since that is the site Google indexes. Core Web Vitals are not collected from the front end in this version.', 'vireo-analytics' ); ?></p>
					</td>
				</tr>
				<?php endif; ?>
			</table>

			<h2 class="va-section-title"><?php esc_html_e( 'Exclusions', 'vireo-analytics' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Skip pageviews from known bots, your own IPs, or internal paths. Bot UAs are filtered automatically; these rules run on top.', 'vireo-analytics' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="va_excluded_ips"><?php esc_html_e( 'Excluded IPs / CIDR', 'vireo-analytics' ); ?></label></th>
					<td>
						<textarea id="va_excluded_ips" name="vireo_settings[excluded_ips]" rows="4" cols="50" class="large-text code" placeholder="203.0.113.42&#10;198.51.100.0/24"><?php echo esc_textarea( (string) $settings['excluded_ips'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One entry per line. IPv4, IPv6, and CIDR ranges are supported.', 'vireo-analytics' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="va_excluded_paths"><?php esc_html_e( 'Excluded paths', 'vireo-analytics' ); ?></label></th>
					<td>
						<textarea id="va_excluded_paths" name="vireo_settings[excluded_paths]" rows="4" cols="50" class="large-text code" placeholder="/wp-admin/*&#10;/wp-login.php"><?php echo esc_textarea( (string) $settings['excluded_paths'] ); ?></textarea>
						<p class="description"><?php echo wp_kses( __( 'One path pattern per line. Use <code>*</code> for any chars except <code>/</code>, <code>**</code> for any chars.', 'vireo-analytics' ), [ 'code' => [] ] ); ?></p>
					</td>
				</tr>
			</table>

			<h2 class="va-section-title" id="va-referrers"><?php esc_html_e( 'Blocked referrers', 'vireo-analytics' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Sites whose visits are filtered like bots and hidden from reports. Known referrer spam is blocked already; these are the ones you added from a referrer row.', 'vireo-analytics' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="va_blocked_referrers"><?php esc_html_e( 'Blocked hosts', 'vireo-analytics' ); ?></label></th>
					<td>
						<textarea id="va_blocked_referrers" name="vireo_blocked_referrers" rows="4" cols="50" class="large-text code" placeholder="example-spam.net"><?php echo esc_textarea( implode( "\n", \VireoAnalytics\Referrer_Spam::blocked() ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One host per line; subdomains are included. Remove a line to unblock it. Blocking stops counting from now on and hides earlier rows; nothing is deleted.', 'vireo-analytics' ); ?></p>
					</td>
				</tr>
			</table>

			<h2 class="va-section-title" id="va-geo"><?php esc_html_e( 'Geo-location', 'vireo-analytics' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Countries', 'vireo-analytics' ); ?></th>
					<td>
						<?php if ( '' !== $geo_header ) : ?>

							<?php /* The host resolves the country at the edge, so nothing to install. */ ?>
							<span class="va-status va-status--ok"><?php esc_html_e( 'Resolving', 'vireo-analytics' ); ?></span>
							<p class="description">
								<?php
								/* translators: %s: the visitor country code the host reported, e.g. US. */
								echo esc_html( sprintf( __( 'Your host or CDN already tells us each visitor\'s country (it reported %s for this request), so countries resolve automatically. No database needed.', 'vireo-analytics' ), $geo_header ) );
								?>
							</p>

						<?php elseif ( $mmdb_ok ) : ?>

							<span class="va-status va-status--ok"><?php esc_html_e( 'Installed', 'vireo-analytics' ); ?></span>
							<p class="description"><?php esc_html_e( 'Country lookups run against the local database on this server.', 'vireo-analytics' ); ?></p>
							<?php if ( 'dbip' === $geo_source['source'] ) : ?>
								<p class="description">
									<?php echo wp_kses( __( 'Geolocation by <a href="https://db-ip.com" target="_blank" rel="noopener">DB-IP</a>.', 'vireo-analytics' ), [ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ] ); ?>
								</p>
							<?php endif; ?>

						<?php else : ?>

							<span class="va-status va-status--missing"><?php esc_html_e( 'Not resolving', 'vireo-analytics' ); ?></span>
							<p class="description"><?php esc_html_e( 'Every visitor is recorded as unknown until a country source is available. The one-click database below fixes it.', 'vireo-analytics' ); ?></p>

							<p style="margin-top:12px;">
								<a href="<?php echo esc_url( $geodb_url ); ?>" class="button button-primary"><?php esc_html_e( 'Download country database', 'vireo-analytics' ); ?></a>
							</p>
							<p class="description">
								<?php echo wp_kses( __( 'Free, no account, installed for you. Data by <a href="https://db-ip.com" target="_blank" rel="noopener">DB-IP</a> (CC BY 4.0).', 'vireo-analytics' ), [ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ] ); ?>
							</p>

							<details class="va-geo-advanced" style="margin-top:14px;">
								<summary style="cursor:pointer;"><?php esc_html_e( 'Prefer MaxMind GeoLite2? (manual)', 'vireo-analytics' ); ?></summary>
								<ol class="va-setup-list" style="margin-top:10px;">
									<li><?php echo wp_kses( __( 'Create a free account at <a href="https://www.maxmind.com/en/geolite2/signup" target="_blank" rel="noopener">maxmind.com</a> and generate a license key.', 'vireo-analytics' ), [ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ] ); ?></li>
									<li><?php echo wp_kses( __( 'Download <code>GeoLite2-Country.mmdb</code> (the Country edition is enough).', 'vireo-analytics' ), [ 'code' => [] ] ); ?></li>
									<li>
										<?php
										/* translators: %s: filesystem path. */
										echo wp_kses( sprintf( __( 'Upload it to <code>%s/</code> on this server.', 'vireo-analytics' ), esc_html( dirname( $mmdb_path ) ) ), [ 'code' => [] ] );
										?>
									</li>
								</ol>
								<p class="description"><?php esc_html_e( 'MaxMind requires an account and prohibits automatic downloads, which is why it is manual.', 'vireo-analytics' ); ?></p>
							</details>

						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="va_excluded_countries"><?php esc_html_e( 'Excluded countries', 'vireo-analytics' ); ?></label></th>
					<td>
						<textarea id="va_excluded_countries" name="vireo_settings[excluded_countries]" rows="3" cols="50" class="large-text code"><?php echo esc_textarea( (string) ( $settings['excluded_countries'] ?? '' ) ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'One two-letter country code per line. Traffic from these countries is discarded instead of counted, the same as an excluded IP. Bots that announce themselves are already filtered; this is for the traffic that does not.', 'vireo-analytics' ); ?>
						</p>
						<p class="description">
							<?php esc_html_e( 'Needs the country to be known, so it requires either a host that resolves it or the database above.', 'vireo-analytics' ); ?>
						</p>
						<p class="description">
							<?php esc_html_e( 'Excluding a country stops it being counted from now on. It does not remove what was counted before, so a report covering earlier dates still shows it.', 'vireo-analytics' ); ?>
						</p>
						<?php if ( ! empty( $va_excluded_country_codes ) ) : ?>
							<?php
							// A link rather than a form: this whole page is already one
							// form posting to options.php, HTML forbids nesting another
							// inside it, and the browser drops the inner tag while
							// keeping its button, which then silently saves settings
							// instead of purging. The geo database download alongside
							// this uses the same nonce-link pattern.
							$va_purge_url = wp_nonce_url(
								admin_url( 'admin-post.php?action=vireo_purge_excluded_countries' ),
								'vireo_purge_excluded_countries'
							);
							?>
							<p class="va-purge-countries">
								<a href="<?php echo esc_url( $va_purge_url ); ?>" class="button">
									<?php
									printf(
										/* translators: %s: comma-separated list of two-letter country codes. */
										esc_html__( 'Remove stored data for %s', 'vireo-analytics' ),
										esc_html( implode( ', ', $va_excluded_country_codes ) )
									);
									?>
								</a>
								<span class="description">
									<?php esc_html_e( 'Clears them from the countries report. Pageview and visitor totals keep those visits, because nothing else records which country a visit came from.', 'vireo-analytics' ); ?>
								</span>
							</p>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<?php
			/**
			 * Room for an add-on to offer its own geo database. Pro's city lookup
			 * uses a separate download from the country one above, and without a
			 * slot here there was nowhere to put the button, so the feature could
			 * not be switched on at all.
			 */
			do_action( 'vireo_settings_after_geo' );
			?>

			<?php $pro_active = (bool) apply_filters( 'vireo_pro_is_active', false ); ?>
			<h2 class="va-section-title"><?php esc_html_e( 'Auto-events', 'vireo-analytics' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Record common clicks and form sends as events, with no code to add. Each one shows up on the Campaigns tab.', 'vireo-analytics' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'File downloads', 'vireo-analytics' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="vireo_settings[auto_event_downloads]" value="1" <?php checked( ! empty( $settings['auto_event_downloads'] ) ); ?> />
							<?php esc_html_e( 'Record clicks on links to files (PDF, ZIP, DOC, MP3, MP4, etc.).', 'vireo-analytics' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Fires a "download" event with the filename as the value.', 'vireo-analytics' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Form submissions', 'vireo-analytics' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="vireo_settings[auto_event_forms]" value="1" <?php checked( ! empty( $settings['auto_event_forms'] ) ); ?> />
							<?php esc_html_e( 'Record every form submit.', 'vireo-analytics' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Fires a "form_submit" event with the form id, name, or action URL.', 'vireo-analytics' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Email links', 'vireo-analytics' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="vireo_settings[auto_event_mailto]" value="1" <?php checked( ! empty( $settings['auto_event_mailto'] ) ); ?> />
							<?php esc_html_e( 'Record clicks on mailto: links.', 'vireo-analytics' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Only the part after the @ is kept, so no email address is stored.', 'vireo-analytics' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Phone links', 'vireo-analytics' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="vireo_settings[auto_event_tel]" value="1" <?php checked( ! empty( $settings['auto_event_tel'] ) ); ?> />
							<?php esc_html_e( 'Record clicks on tel: links.', 'vireo-analytics' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Outbound links', 'vireo-analytics' ); ?></th>
					<td>
						<label<?php echo $pro_active ? ' style="opacity:.55;"' : ''; ?>>
							<input type="checkbox" name="vireo_settings[auto_event_outbound]" value="1" <?php checked( ! empty( $settings['auto_event_outbound'] ) ); ?> <?php disabled( $pro_active ); ?> />
							<?php esc_html_e( 'Record clicks on links to external domains.', 'vireo-analytics' ); ?>
						</label>
						<?php if ( $pro_active ) : ?>
							<p class="description"><?php esc_html_e( 'Pro records outbound clicks with more detail, so this option is off to avoid counting each click twice.', 'vireo-analytics' ); ?></p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'Fires an "outbound" event with the external host as the value.', 'vireo-analytics' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			</table>
		</div>

		<div class="va-tab-panel"<?php $va_panel( 'email' ); ?>>
			<?php
			$tz_label = function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : 'UTC';
			?>
			<h2 class="va-section-title"><?php esc_html_e( 'Email digest', 'vireo-analytics' ); ?></h2>
			<p class="description"><?php esc_html_e( 'The last day, week or month in one email: top pages, referrers and countries. It\'s built on your server and sent with your site\'s own mail.', 'vireo-analytics' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable', 'vireo-analytics' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="vireo_settings[email_enabled]" value="1" <?php checked( ! empty( $settings['email_enabled'] ) ); ?> />
							<?php esc_html_e( 'Send the digest', 'vireo-analytics' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="va_email_frequency"><?php esc_html_e( 'Frequency', 'vireo-analytics' ); ?></label></th>
					<td>
						<?php $va_email_frequency = Email_Reports::frequency( $settings ); ?>
						<select id="va_email_frequency" name="vireo_settings[email_frequency]" data-va-email-frequency>
							<?php foreach ( Email_Reports::frequency_labels() as $va_freq => $va_freq_label ) : ?>
								<option value="<?php echo esc_attr( $va_freq ); ?>" <?php selected( $va_email_frequency, $va_freq ); ?>><?php echo esc_html( $va_freq_label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<span data-va-frequency-note="daily"<?php echo 'daily' === $va_email_frequency ? '' : ' hidden'; ?>><?php esc_html_e( 'Every morning, covering the day before.', 'vireo-analytics' ); ?></span>
							<span data-va-frequency-note="weekly"<?php echo 'weekly' === $va_email_frequency ? '' : ' hidden'; ?>><?php esc_html_e( 'Once a week, covering the last seven full days.', 'vireo-analytics' ); ?></span>
							<span data-va-frequency-note="monthly"<?php echo 'monthly' === $va_email_frequency ? '' : ' hidden'; ?>><?php esc_html_e( 'On the first of each month, covering the month before.', 'vireo-analytics' ); ?></span>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="va_email_recipients"><?php esc_html_e( 'Recipients', 'vireo-analytics' ); ?></label></th>
					<td>
						<textarea id="va_email_recipients" name="vireo_settings[email_recipients]" rows="3" cols="50" class="large-text code" placeholder="<?php echo esc_attr( (string) $recipients_default ); ?>"><?php echo esc_textarea( $recipients_value ); ?></textarea>
						<p class="description">
							<?php
							/* translators: %s: site admin email. */
							echo esc_html( sprintf( __( 'One email per line. Leave blank to fall back to the site admin (%s).', 'vireo-analytics' ), (string) $recipients_default ) );
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'When', 'vireo-analytics' ); ?></th>
					<td>
						<?php // Day and hour are one decision, so they share a row. ?>
						<div class="va-inline-fields">
							<label for="va_email_day" data-va-email-day<?php echo 'weekly' === $va_email_frequency ? '' : ' hidden'; ?>>
								<span><?php esc_html_e( 'Day', 'vireo-analytics' ); ?></span>
								<select id="va_email_day" name="vireo_settings[email_day]">
									<?php foreach ( $day_labels as $d => $label ) : ?>
										<option value="<?php echo (int) $d; ?>" <?php selected( (int) $settings['email_day'], $d ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</label>
							<label for="va_email_hour">
								<span><?php esc_html_e( 'Hour', 'vireo-analytics' ); ?></span>
								<select id="va_email_hour" name="vireo_settings[email_hour]">
									<?php for ( $h = 0; $h <= 23; $h++ ) : ?>
										<option value="<?php echo (int) $h; ?>" <?php selected( (int) $settings['email_hour'], $h ); ?>><?php echo esc_html( sprintf( '%02d:00', $h ) ); ?></option>
									<?php endfor; ?>
								</select>
							</label>
						</div>
						<p class="description">
							<?php
							/* translators: %s: site timezone string. */
							echo esc_html( sprintf( __( 'Site timezone (%s).', 'vireo-analytics' ), $tz_label ) );
							?>
						</p>
					</td>
				</tr>
			</table>
		</div>

		<div class="va-tab-panel va-save-panel"<?php $va_panel( 'general tracking email' ); ?>>
			<?php submit_button( __( 'Save settings', 'vireo-analytics' ) ); ?>
		</div>
	</form>

	<div class="va-tab-panel"<?php $va_panel( 'tracking' ); ?>>
		<h2 class="va-section-title" id="va-connected-sites"><?php esc_html_e( 'Connected sites', 'vireo-analytics' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Count visits to other sites you run, whether or not they use WordPress: a static site, a docs site, a landing page. Each connected site keeps its own numbers, and the dashboard switches between them. Unlike the headless front end above, its pages are not matched to posts here.', 'vireo-analytics' ); ?></p>
		<?php
		$va_sites          = \VireoAnalytics\Sites::all();
		$va_sites_limit    = \VireoAnalytics\Sites::limit();
		$va_sites_endpoint = \VireoAnalytics\Script_Loader::collect_url();
		$va_sites_tracker  = VIREO_URL . 'assets/js/tracker.js';
		$va_sites_vitals   = VIREO_URL . 'assets/js/vitals.js';
		?>
		<?php foreach ( $va_sites as $va_site ) : ?>
			<div class="va-connected-site" id="va-site-<?php echo (int) $va_site['id']; ?>">
				<h3 class="va-connected-site-name"><?php echo esc_html( $va_site['name'] ); ?> <code><?php echo esc_html( $va_site['origin'] ); ?></code></h3>
				<p class="description" style="margin-top:0;"><?php esc_html_e( 'Paste this before </body> on every page of the site. The second line collects Core Web Vitals and can be left out. For a site that changes pages without reloading, add data-spa="history" to the first tag.', 'vireo-analytics' ); ?></p>
				<pre class="va-code-block"><code><?php echo esc_html( \VireoAnalytics\Sites::snippet( $va_site, $va_sites_endpoint, $va_sites_tracker, $va_sites_vitals ) ); ?></code></pre>
				<p class="description">
					<?php
					/* translators: %s: the site's address. */
					echo esc_html( sprintf( __( 'Pageviews are accepted only when the browser sends them from %s, so the tag records nothing if it is pasted anywhere else.', 'vireo-analytics' ), $va_site['origin'] ) );
					?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="va-connected-site-remove" data-va-confirm-remove>
					<input type="hidden" name="action" value="vireo_delete_connected_site">
					<input type="hidden" name="site_id" value="<?php echo (int) $va_site['id']; ?>">
					<?php wp_nonce_field( 'vireo_delete_connected_site' ); ?>
					<button type="button" class="button-link va-connected-site-remove-open" data-va-confirm-open><?php esc_html_e( 'Remove this site…', 'vireo-analytics' ); ?></button>
					<div class="va-connected-site-confirm" data-va-confirm-panel hidden>
						<p>
							<?php
							/* translators: %s: the site's name. */
							echo esc_html( sprintf( __( 'This deletes every number recorded for %s. There is no undo. Type the site\'s name to confirm.', 'vireo-analytics' ), $va_site['name'] ) );
							?>
						</p>
						<label>
							<span class="screen-reader-text"><?php esc_html_e( 'Site name', 'vireo-analytics' ); ?></span>
							<input type="text" name="confirm_name" class="regular-text" autocomplete="off" data-va-confirm-name="<?php echo esc_attr( $va_site['name'] ); ?>" placeholder="<?php echo esc_attr( $va_site['name'] ); ?>">
						</label>
						<?php submit_button( __( 'Remove site and its data', 'vireo-analytics' ), 'delete', 'submit', false, [ 'disabled' => 'disabled', 'data-va-confirm-submit' => '1' ] ); ?>
						<button type="button" class="button-link" data-va-confirm-cancel><?php esc_html_e( 'Keep it', 'vireo-analytics' ); ?></button>
					</div>
				</form>
			</div>
		<?php endforeach; ?>

		<?php if ( count( $va_sites ) < $va_sites_limit ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="va-connected-site-add">
				<input type="hidden" name="action" value="vireo_add_connected_site">
				<?php wp_nonce_field( 'vireo_add_connected_site' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="va_site_name"><?php esc_html_e( 'Site name', 'vireo-analytics' ); ?></label></th>
						<td><input type="text" id="va_site_name" name="site_name" class="regular-text" maxlength="<?php echo (int) \VireoAnalytics\Sites::MAX_NAME; ?>" required placeholder="<?php esc_attr_e( 'Docs site', 'vireo-analytics' ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="va_site_origin"><?php esc_html_e( 'Site address', 'vireo-analytics' ); ?></label></th>
						<td>
							<input type="url" id="va_site_origin" name="site_origin" class="regular-text code" required placeholder="https://docs.example.com" spellcheck="false">
							<p class="description"><?php esc_html_e( 'The address readers see in their browser, including https://. Visits sent from any other address are refused.', 'vireo-analytics' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Connect site', 'vireo-analytics' ), 'secondary', 'submit', false ); ?>
			</form>
		<?php else : ?>
			<p class="description">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: how many connected sites this install allows. */
						_n( 'This install allows %d connected site.', 'This install allows %d connected sites.', $va_sites_limit, 'vireo-analytics' ),
						$va_sites_limit
					)
				);
				?>
			</p>
		<?php endif; ?>
	</div>

	<div class="va-tab-panel"<?php $va_panel( 'tracking' ); ?>>
		<h2 class="va-section-title"><?php esc_html_e( 'Bot filter test', 'vireo-analytics' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Paste a User-Agent to see whether Vireo Analytics would filter it as a bot. Useful when debugging why a visitor (or monitoring tool) isn\'t showing up in stats.', 'vireo-analytics' ); ?>
		</p>
		<div class="va-bot-test">
			<label for="va-bot-test-ua"><strong><?php esc_html_e( 'User-Agent', 'vireo-analytics' ); ?></strong></label>
			<textarea id="va-bot-test-ua" rows="2" cols="80" class="large-text code" placeholder="Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)"></textarea>
			<p>
				<button type="button" class="button" id="va-bot-test-run"><?php esc_html_e( 'Test User-Agent', 'vireo-analytics' ); ?></button>
			</p>
			<div id="va-bot-test-result" class="va-bot-test-result" role="status" aria-live="polite" hidden>
				<strong id="va-bot-test-verdict"></strong>
				<span class="va-bot-test-reason" id="va-bot-test-reason"></span>
			</div>
		</div>
	</div>

	<div class="va-tab-panel"<?php $va_panel( 'email' ); ?>>
		<h2 class="va-section-title"><?php esc_html_e( 'Test email', 'vireo-analytics' ); ?></h2>
		<p class="description">
			<?php
			$current_user_email = wp_get_current_user()->user_email ?: (string) $recipients_default;
			/* translators: %s: current user email. */
			echo esc_html( sprintf( __( 'Sends the digest for the last finished period to %s right now (bypasses the schedule).', 'vireo-analytics' ), $current_user_email ) );
			?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="vireo_send_test_email">
			<?php wp_nonce_field( 'vireo_send_test_email' ); ?>
			<?php submit_button( __( 'Send test email', 'vireo-analytics' ), 'secondary', 'submit', false ); ?>
		</form>
	</div>

	<div class="va-tab-panel"<?php $va_panel( 'integrations' ); ?>>
		<h2 class="va-section-title" id="vireo-gsc"><?php esc_html_e( 'Search Console', 'vireo-analytics' ); ?></h2>

		<?php
		$va_gsc_state  = \VireoAnalytics\Search_Console::state();
		$va_gsc_on     = \VireoAnalytics\Search_Console::configured();
		$va_gsc_email  = $va_gsc_on ? \VireoAnalytics\Search_Console::account_email() : '';
		$va_gsc_site   = \VireoAnalytics\Search_Console::settings()['site_url'];

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash after an admin-post redirect; the page itself is capability-gated
		$va_gsc_notice = isset( $_GET['vireo_gsc'] ) ? sanitize_key( (string) $_GET['vireo_gsc'] ) : '';
		// The message itself comes from a short-lived transient set by the handler,
		// not from the URL, so a crafted link cannot put words in this notice.
		$va_gsc_msg = \VireoAnalytics\Search_Console::take_flash();
		?>

		<?php if ( 'error' === $va_gsc_notice ) : ?>
			<div class="notice notice-error"><p><?php echo esc_html( $va_gsc_msg ); ?></p></div>
		<?php elseif ( 'saved' === $va_gsc_notice ) : ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Search Console connected. The first sync runs shortly.', 'vireo-analytics' ); ?></p></div>
		<?php elseif ( 'synced' === $va_gsc_notice ) : ?>
			<div class="notice notice-success"><p>
				<?php
				if ( \VireoAnalytics\Search_Console::backfill_pending() ) {
					esc_html_e( 'The last week is in. Older history is loading in the background a couple of weeks at a time, and should be complete in a few minutes.', 'vireo-analytics' );
				} else {
					esc_html_e( 'Search Console data refreshed.', 'vireo-analytics' );
				}
				?>
			</p></div>
		<?php elseif ( 'disconnected' === $va_gsc_notice ) : ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Search Console disconnected.', 'vireo-analytics' ); ?></p></div>
		<?php endif; ?>

		<p class="description">
			<?php esc_html_e( 'Brings in the searches people ran before they arrived. Google strips the query from the referrer, so this is the only place it exists. Uses the same kind of service account key as the Analytics importer, with read-only access.', 'vireo-analytics' ); ?>
		</p>

		<details class="va-setup-help">
			<summary><?php esc_html_e( 'How to get the key file', 'vireo-analytics' ); ?></summary>
			<p><strong><?php esc_html_e( 'In Google Cloud Console', 'vireo-analytics' ); ?></strong> (console.cloud.google.com)</p>
			<ol>
				<li><?php esc_html_e( 'Create a project, or open one you already have.', 'vireo-analytics' ); ?></li>
				<li><?php esc_html_e( 'Under APIs and services, enable the Google Search Console API.', 'vireo-analytics' ); ?></li>
				<li><?php esc_html_e( 'Under IAM and admin, then Service accounts, create a service account. Its address ends in .iam.gserviceaccount.com. You need that address for the next part.', 'vireo-analytics' ); ?></li>
				<li><?php esc_html_e( 'Open the service account, go to Keys, add a key, and choose JSON. The file downloads once and Google will not give it to you again.', 'vireo-analytics' ); ?></li>
			</ol>
			<p><strong><?php esc_html_e( 'In Search Console', 'vireo-analytics' ); ?></strong> (search.google.com/search-console)</p>
			<ol start="5">
				<li><?php esc_html_e( 'Open Settings, then Users and permissions, and add the service account address with Restricted access.', 'vireo-analytics' ); ?></li>
				<li><?php esc_html_e( 'Copy the property exactly as Search Console lists it. A domain property looks like sc-domain:example.com. A URL-prefix property is the full address including https:// and any trailing slash. The two are different properties and the wrong one returns nothing.', 'vireo-analytics' ); ?></li>
			</ol>
			<p><?php esc_html_e( 'Then open the downloaded file in a text editor and paste all of it, braces included, into the box below.', 'vireo-analytics' ); ?></p>
		</details>

		<?php if ( $va_gsc_on && ! empty( $va_gsc_state['error'] ) ) : ?>
			<div class="notice notice-warning inline">
				<p>
					<?php
					printf(
						/* translators: %s: error message from Google */
						esc_html__( 'The last sync failed: %s', 'vireo-analytics' ),
						esc_html( (string) $va_gsc_state['error'] )
					);
					?>
				</p>
			</div>
		<?php elseif ( $va_gsc_on && ! empty( $va_gsc_state['synced'] ) ) : ?>
			<p class="description">
				<?php
				printf(
					/* translators: 1: human-readable duration, 2: first date, 3: last date */
					esc_html__( 'Last refreshed %1$s ago, covering %2$s to %3$s.', 'vireo-analytics' ),
					esc_html( human_time_diff( (int) $va_gsc_state['synced'] ) ),
					esc_html( (string) ( $va_gsc_state['from'] ?? '' ) ),
					esc_html( (string) ( $va_gsc_state['to'] ?? '' ) )
				);
				?>
			</p>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="vireo_gsc_save" />
			<?php wp_nonce_field( 'vireo_gsc_save' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="va_gsc_key"><?php esc_html_e( 'Service account key (JSON)', 'vireo-analytics' ); ?></label></th>
					<td>
						<textarea id="va_gsc_key" name="key_json" rows="4" class="large-text code" autocomplete="off"
							placeholder="<?php echo esc_attr( $va_gsc_on ? __( 'A key is saved. Leave this empty to keep it.', 'vireo-analytics' ) : '{ "type": "service_account", ... }' ); ?>"></textarea>
						<?php if ( '' !== $va_gsc_email ) : ?>
							<p class="description">
								<?php
								printf(
									/* translators: %s: service account email address */
									esc_html__( 'Add %s as a user on the property in Search Console, with Restricted access.', 'vireo-analytics' ),
									'<code>' . esc_html( $va_gsc_email ) . '</code>'
								);
								?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="va_gsc_site"><?php esc_html_e( 'Property', 'vireo-analytics' ); ?></label></th>
					<td>
						<input type="text" id="va_gsc_site" name="site_url" class="regular-text"
							value="<?php echo esc_attr( $va_gsc_site ); ?>"
							placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>" />
						<p class="description">
							<?php esc_html_e( 'Copy it exactly as Search Console shows it. A domain property looks like sc-domain:example.com.', 'vireo-analytics' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save Search Console settings', 'vireo-analytics' ); ?></button></p>
		</form>

		<?php if ( $va_gsc_on ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
				<input type="hidden" name="action" value="vireo_gsc_sync" />
				<input type="hidden" name="days" value="90" />
				<?php wp_nonce_field( 'vireo_gsc_sync' ); ?>
				<button type="submit" class="button"><?php esc_html_e( 'Fetch the last 90 days', 'vireo-analytics' ); ?></button>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
				<input type="hidden" name="action" value="vireo_gsc_disconnect" />
				<?php wp_nonce_field( 'vireo_gsc_disconnect' ); ?>
				<button type="submit" class="button"><?php esc_html_e( 'Disconnect', 'vireo-analytics' ); ?></button>
			</form>
		<?php endif; ?>

		<h2 class="va-section-title" id="vireo-psi"><?php esc_html_e( 'PageSpeed Insights', 'vireo-analytics' ); ?></h2>

		<?php
		$va_psi_on = \VireoAnalytics\PageSpeed::has_key();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flag after an admin-post redirect; each value maps to a fixed message
		$va_psi_notice = isset( $_GET['vireo_psi'] ) ? sanitize_key( (string) $_GET['vireo_psi'] ) : '';
		?>

		<?php if ( 'saved' === $va_psi_notice ) : ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'API key saved. Tests now run from the dashboard and show their results there.', 'vireo-analytics' ); ?></p></div>
		<?php elseif ( 'invalid' === $va_psi_notice ) : ?>
			<div class="notice notice-error"><p><?php esc_html_e( 'That does not look like a Google API key. It is a single line of letters, numbers, dashes and underscores, usually starting with AIza.', 'vireo-analytics' ); ?></p></div>
		<?php elseif ( 'removed' === $va_psi_notice ) : ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'API key removed.', 'vireo-analytics' ); ?></p></div>
		<?php endif; ?>

		<p class="description">
			<?php esc_html_e( 'The Page speed tab and each post\'s stats have a button for Google\'s lab test. Without a key it opens the test on the PageSpeed Insights website. With your own free Google API key, the test runs from here and the results show on the dashboard, next to the speed your real visitors got. A test only runs when someone presses the button.', 'vireo-analytics' ); ?>
		</p>

		<?php
		$va_psi_link = static function ( string $url, string $text ): string {
			return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $text ) . '</a>';
		};
		?>
		<ol class="va-psi-steps">
			<li>
				<?php
				printf(
					/* translators: %s: link to Google Cloud console, text "Create a Google Cloud project" */
					esc_html__( '%s, or pick one you already have. Any name works.', 'vireo-analytics' ),
					$va_psi_link( 'https://console.cloud.google.com/projectcreate', __( 'Create a Google Cloud project', 'vireo-analytics' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure
				);
				?>
			</li>
			<li>
				<?php
				printf(
					/* translators: %s: link to the API's page in Google Cloud console, text "Enable the PageSpeed Insights API" */
					esc_html__( '%s for that project.', 'vireo-analytics' ),
					$va_psi_link( 'https://console.cloud.google.com/apis/library/pagespeedonline.googleapis.com', __( 'Enable the PageSpeed Insights API', 'vireo-analytics' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure
				);
				?>
			</li>
			<li>
				<?php
				printf(
					/* translators: %s: link to the Credentials page in Google Cloud console, text "Credentials" */
					esc_html__( 'On the %s page, choose Create credentials, then API key.', 'vireo-analytics' ),
					$va_psi_link( 'https://console.cloud.google.com/apis/credentials', __( 'Credentials', 'vireo-analytics' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure
				);
				?>
			</li>
			<li><?php esc_html_e( 'Open the new key. Under API restrictions, choose Restrict key, select PageSpeed Insights API, and save.', 'vireo-analytics' ); ?></li>
			<li><?php esc_html_e( 'Copy the key and paste it below.', 'vireo-analytics' ); ?></li>
		</ol>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="vireo_psi_save" />
			<?php wp_nonce_field( 'vireo_psi_save' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="va_psi_key"><?php esc_html_e( 'Google API key (optional)', 'vireo-analytics' ); ?></label></th>
					<td>
						<input type="password" id="va_psi_key" name="api_key" class="regular-text" autocomplete="off" spellcheck="false"
							placeholder="<?php echo esc_attr( $va_psi_on ? __( 'A key is saved.', 'vireo-analytics' ) : 'AIza...' ); ?>" />
					</td>
				</tr>
			</table>

			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save API key', 'vireo-analytics' ); ?></button></p>
		</form>

		<?php if ( $va_psi_on ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="vireo_psi_remove" />
				<?php wp_nonce_field( 'vireo_psi_remove' ); ?>
				<button type="submit" class="button"><?php esc_html_e( 'Remove API key', 'vireo-analytics' ); ?></button>
			</form>
		<?php endif; ?>

		<h2 class="va-section-title"><?php esc_html_e( 'REST API', 'vireo-analytics' ); ?></h2>
		<?php // Developer reference, folded so it doesn't take over the tab. ?>
		<details class="va-rest-details">
			<summary><?php esc_html_e( 'Endpoints and an example request', 'vireo-analytics' ); ?></summary>
		<p class="description">
			<?php esc_html_e( 'Read-only endpoints under /wp-json/vireo-analytics/v1. Authenticate with WordPress Application Passwords or a logged-in session nonce. Requires the view_vireo_analytics capability.', 'vireo-analytics' ); ?>
		</p>
		<?php
		$rest_base = esc_url( rest_url( \VireoAnalytics\REST_API::NAMESPACE ) );
		$rest_example_start = gmdate( 'Y-m-d', strtotime( '-29 days' ) );
		$rest_example_end   = gmdate( 'Y-m-d' );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Base URL', 'vireo-analytics' ); ?></th>
				<td>
					<code class="va-code-block"><?php echo esc_html( $rest_base ); ?></code>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Endpoints', 'vireo-analytics' ); ?></th>
				<td>
<pre class="va-code-block va-rest-endpoints-block"><code>GET /stats/totals?start=YYYY-MM-DD&amp;end=YYYY-MM-DD
GET /stats/daily?start=...&amp;end=...
GET /stats/top-pages?start=...&amp;end=...&amp;limit=10
GET /stats/top-referrers?start=...&amp;end=...&amp;limit=10
GET /stats/countries?start=...&amp;end=...&amp;limit=20
GET /stats/devices?start=...&amp;end=...
GET /stats/events?start=...&amp;end=...&amp;name=X&amp;limit=10
GET /stats/event/{name}?start=...&amp;end=...&amp;limit=20
GET /stats/post/{id}?start=...&amp;end=...
GET /stats/hourly?start=...&amp;end=...
GET /stats/changes?start=...&amp;end=...&amp;limit=3
GET /stats/highlights?start=...&amp;end=...
GET /stats/channels?start=...&amp;end=...
GET /stats/referrer?host=example.com&amp;start=...&amp;end=...
GET /stats/ai-referrals?start=...&amp;end=...
GET /stats/search-console?start=...&amp;end=...&amp;post_id=123
GET /stats/404s?start=...&amp;end=...&amp;limit=20
GET /stats/bots?start=...&amp;end=...
GET /stats/vitals?start=...&amp;end=...
GET /stats/annotations?start=...&amp;end=...
GET /stats/year-review?year=2026</code></pre>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Example', 'vireo-analytics' ); ?></th>
				<td>
<pre class="va-code-block"><code>curl -u user:app_password \
  "<?php echo esc_html( $rest_base . '/stats/totals?start=' . $rest_example_start . '&end=' . $rest_example_end ); ?>"</code></pre>
				</td>
			</tr>
		</table>
		</details>

		<h2 class="va-section-title" id="vireo-mcp"><?php esc_html_e( 'Connect an AI assistant', 'vireo-analytics' ); ?></h2>
		<p class="description">
			<?php esc_html_e( "Ask Claude, Cursor or any other MCP client about this site's traffic in plain language: what changed this week, which pages are growing, what people searched for. The assistant signs in as you with an application password and sees exactly what you can see in the dashboard, read-only.", 'vireo-analytics' ); ?>
		</p>
		<?php if ( ! \VireoAnalytics\Mcp::available() ) : ?>
			<p class="description"><?php esc_html_e( 'The bundled MCP adapter is missing from this install, so the endpoint is off. Reinstall the plugin from its release package.', 'vireo-analytics' ); ?></p>
		<?php else : ?>
		<?php
		$va_mcp_url      = \VireoAnalytics\Mcp::endpoint_url();
		$va_mcp_user     = wp_get_current_user()->user_login;
		$va_mcp_pw_url   = admin_url( 'profile.php#application-passwords-section' );
		$va_mcp_claude   = sprintf(
			'claude mcp add --transport http vireo-analytics %1$s --header "Authorization: Basic $(printf %2$s | base64)"',
			$va_mcp_url,
			"'" . $va_mcp_user . ":APP_PASSWORD'"
		);
		$va_mcp_json     = wp_json_encode(
			[
				'mcpServers' => [
					'vireo-analytics' => [
						'command' => 'npx',
						'args'    => [ '-y', '@automattic/mcp-wordpress-remote@latest' ],
						'env'     => [
							'WP_API_URL'      => $va_mcp_url,
							'WP_API_USERNAME' => $va_mcp_user,
							'WP_API_PASSWORD' => 'APP_PASSWORD',
						],
					],
				],
			],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);
		?>
		<table class="form-table va-mcp-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'MCP endpoint', 'vireo-analytics' ); ?></th>
				<td>
					<div class="va-copy-wrap">
						<code class="va-code-block" id="va-mcp-url"><?php echo esc_html( $va_mcp_url ); ?></code>
						<button type="button" class="button va-copy" data-va-copy="#va-mcp-url"><?php esc_html_e( 'Copy', 'vireo-analytics' ); ?></button>
					</div>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Application password', 'vireo-analytics' ); ?></th>
				<td>
					<p>
						<a href="<?php echo esc_url( $va_mcp_pw_url ); ?>"><?php esc_html_e( 'Create one on your profile', 'vireo-analytics' ); ?></a>
						<?php esc_html_e( 'and name it after the assistant. Paste it where the snippets below say APP_PASSWORD; the spaces WordPress shows in it can stay. You can revoke it from the same place at any time.', 'vireo-analytics' ); ?>
					</p>
					<p class="description"><?php esc_html_e( "Only users who can open the Vireo dashboard can connect. A password for anyone else is refused.", 'vireo-analytics' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Claude Code', 'vireo-analytics' ); ?></th>
				<td>
					<div class="va-copy-wrap">
<pre class="va-code-block" id="va-mcp-claude"><code><?php echo esc_html( $va_mcp_claude ); ?></code></pre>
						<button type="button" class="button va-copy" data-va-copy="#va-mcp-claude"><?php esc_html_e( 'Copy', 'vireo-analytics' ); ?></button>
					</div>
					<p class="description"><?php esc_html_e( 'Run it in a terminal, then ask Claude Code about your traffic.', 'vireo-analytics' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Claude Desktop, Cursor and others', 'vireo-analytics' ); ?></th>
				<td>
					<div class="va-copy-wrap">
<pre class="va-code-block" id="va-mcp-json"><code><?php echo esc_html( (string) $va_mcp_json ); ?></code></pre>
						<button type="button" class="button va-copy" data-va-copy="#va-mcp-json"><?php esc_html_e( 'Copy', 'vireo-analytics' ); ?></button>
					</div>
					<p class="description"><?php esc_html_e( "Add it to the client's MCP settings file (claude_desktop_config.json, Cursor's mcp.json). Needs Node.js on that computer; the proxy runs locally and talks to this site over HTTPS.", 'vireo-analytics' ); ?></p>
				</td>
			</tr>
		</table>
		<?php endif; ?>
	</div>

	<div class="va-tab-panel"<?php $va_panel( 'data' ); ?>>
		<h2 class="va-section-title" id="vireo-import"><?php esc_html_e( 'Import data', 'vireo-analytics' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Bring your history into Vireo Analytics. Your source data is never modified. An import adds to what is already stored and can\'t be undone, so import any range only once.', 'vireo-analytics' ); ?>
		</p>
		<?php
		// Sources in use on this site lead, as the one obvious next step. The rest
		// stay visible as secondary buttons so it's clear what the plugin supports.
		$va_sources  = \VireoAnalytics\Import_Controller::instance()->sources();
		$va_found    = array_filter( $va_sources, static fn( array $row ): bool => null === $row['last'] && ( $row['active'] || $row['has_data'] ) );
		$va_imported = array_filter( $va_sources, static fn( array $row ): bool => null !== $row['last'] );
		$va_other    = array_filter( $va_sources, static fn( array $row ): bool => null === $row['last'] && ! $row['active'] && ! $row['has_data'] );
		?>
		<?php if ( ! empty( $va_found ) ) : ?>
			<div class="va-import-found">
				<p class="va-import-found-title"><?php esc_html_e( 'Found on this site', 'vireo-analytics' ); ?></p>
				<ul class="va-import-sources">
					<?php foreach ( $va_found as $va_row ) : ?>
						<li>
							<a class="button button-primary" href="<?php echo esc_url( $va_row['url'] ); ?>">
								<?php
								printf(
									/* translators: %s: name of the analytics plugin being imported from. */
									esc_html__( 'Import from %s', 'vireo-analytics' ),
									esc_html( $va_row['name'] )
								);
								?>
							</a>
							<span class="description">
								<?php
								if ( 'ga4' === $va_row['slug'] ) {
									esc_html_e( 'A Google Analytics plugin is active on this site.', 'vireo-analytics' );
								} elseif ( $va_row['active'] && $va_row['has_data'] ) {
									esc_html_e( 'Active, with data stored on this site.', 'vireo-analytics' );
								} elseif ( $va_row['active'] ) {
									esc_html_e( 'Active on this site.', 'vireo-analytics' );
								} else {
									esc_html_e( 'No longer active, but its data is still stored on this site.', 'vireo-analytics' );
								}
								?>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $va_imported ) ) : ?>
			<p class="va-import-found-title"><?php esc_html_e( 'Already imported', 'vireo-analytics' ); ?></p>
			<ul class="va-import-sources">
				<?php foreach ( $va_imported as $va_row ) : ?>
					<li>
						<span class="va-import-done-mark" aria-hidden="true">&#10003;</span>
						<a href="<?php echo esc_url( $va_row['url'] ); ?>"><?php echo esc_html( $va_row['name'] ); ?></a>
						<span class="description"><?php echo esc_html( \VireoAnalytics\Import_History::summary( $va_row['last'] ) ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<?php if ( ! empty( $va_other ) ) : ?>
			<p class="description">
				<?php
				if ( empty( $va_found ) && empty( $va_imported ) ) {
					esc_html_e( 'None of the analytics plugins Vireo Analytics imports from is active on this site, and none has left data here. If you have used one of these, or still have its data elsewhere:', 'vireo-analytics' );
				} else {
					esc_html_e( 'Vireo Analytics can also import from:', 'vireo-analytics' );
				}
				?>
			</p>
			<?php
			// Sources that live off the site (an account, an export file) can
			// always run. Plugin sources need their data in this database, and
			// none was found, so they're listed as such rather than as buttons
			// that lead to "nothing to import".
			$va_external = array_filter( $va_other, static fn( array $r ): bool => in_array( $r['slug'], array_merge( \VireoAnalytics\Import_Controller::EXTERNAL_SOURCES, [ 'jetpack' ] ), true ) );
			$va_local    = array_filter( $va_other, static fn( array $r ): bool => ! in_array( $r['slug'], array_merge( \VireoAnalytics\Import_Controller::EXTERNAL_SOURCES, [ 'jetpack' ] ), true ) );
			?>
			<?php if ( $va_external ) : ?>
				<p class="va-import-links">
					<?php foreach ( $va_external as $va_row ) : ?>
						<a class="button" href="<?php echo esc_url( $va_row['url'] ); ?>">
							<?php echo esc_html( $va_row['name'] ); ?>
						</a>
					<?php endforeach; ?>
				</p>
			<?php endif; ?>
			<?php if ( $va_local ) : ?>
				<p class="description va-import-local">
					<?php esc_html_e( 'These import from the data a plugin leaves on this site. None was found yet:', 'vireo-analytics' ); ?>
					<?php
					echo implode(
						', ',
						array_map(
							static fn( array $r ): string => '<a href="' . esc_url( $r['url'] ) . '">' . esc_html( $r['name'] ) . '</a>',
							$va_local
						)
					); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped per item above
					?>
				</p>
			<?php endif; ?>
		<?php endif; ?>

		<h2 class="va-section-title" id="vireo-backup"><?php esc_html_e( 'Backup and restore', 'vireo-analytics' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Download everything Vireo Analytics has recorded for this site as one file, and load it on another install, or back onto this one. Loading the same file twice gives the same numbers, so a restore can be repeated safely. Settings, connected sites and the live visitor buffer are not included.', 'vireo-analytics' ); ?>
		</p>
		<p class="va-backup-actions">
			<a class="button" href="<?php echo esc_url( \VireoAnalytics\Backup::url() ); ?>"><?php esc_html_e( 'Download backup', 'vireo-analytics' ); ?></a>
			<a class="button" href="<?php echo esc_url( \VireoAnalytics\Import_Controller::instance()->page_url_for( 'backup' ) ); ?>"><?php esc_html_e( 'Restore from a backup', 'vireo-analytics' ); ?></a>
		</p>

		<h2 class="va-section-title"><?php esc_html_e( 'Export CSV', 'vireo-analytics' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Download your analytics for the dates below as a CSV file. It\'s built on your server and goes straight to your browser.', 'vireo-analytics' ); ?>
		</p>
		<?php
		$csv_end   = gmdate( 'Y-m-d', strtotime( '-1 day' ) );
		$csv_start = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
		$csv_types = [
			'site'      => __( 'Site totals', 'vireo-analytics' ),
			'pages'     => __( 'Pages', 'vireo-analytics' ),
			'referrers' => __( 'Referrers', 'vireo-analytics' ),
			'countries' => __( 'Countries', 'vireo-analytics' ),
			'devices'   => __( 'Devices', 'vireo-analytics' ),
			'404s'      => __( 'Broken links', 'vireo-analytics' ),
			'events'    => __( 'Events', 'vireo-analytics' ),
		];
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="va-csv-export-form">
			<input type="hidden" name="action" value="vireo_export_csv">
			<?php wp_nonce_field( 'vireo_export_csv', '_wpnonce', false ); ?>
			<label><span><?php esc_html_e( 'Start', 'vireo-analytics' ); ?></span>
				<input type="date" name="start" value="<?php echo esc_attr( $csv_start ); ?>" />
			</label>
			<label><span><?php esc_html_e( 'End', 'vireo-analytics' ); ?></span>
				<input type="date" name="end" value="<?php echo esc_attr( $csv_end ); ?>" />
			</label>
			<label><span><?php esc_html_e( 'Type', 'vireo-analytics' ); ?></span>
				<select name="type">
					<?php foreach ( $csv_types as $type_key => $type_label ) : ?>
						<option value="<?php echo esc_attr( $type_key ); ?>"><?php echo esc_html( $type_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<?php submit_button( __( 'Download CSV', 'vireo-analytics' ), 'secondary', 'submit', false ); ?>
		</form>
	</div>

	<p class="va-settings-help">
		<?php
		printf(
			/* translators: %s: link to the wordpress.org support forum, labelled "Ask in the support forum". */
			esc_html__( 'Questions or a bug? %s.', 'vireo-analytics' ),
			'<a href="' . esc_url( Feedback::SUPPORT_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Ask in the support forum', 'vireo-analytics' ) . '</a>'
		);
		?>
	</p>
</div>
