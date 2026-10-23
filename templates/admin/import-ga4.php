<?php
/**
 * Import from Google Analytics 4 page template.
 *
 * Expected in scope:
 *   $importer           GA4_Importer
 *   $is_available       bool    openssl present?
 *   $saved              array   Previously-saved params (never includes the key).
 *   $has_key            bool    A key is already stored.
 *   $account_email      string  The stored key's service account address.
 *   $default_start      string  YYYY-MM-DD, 365 days ago.
 *   $default_end        string  YYYY-MM-DD, yesterday.
 *   $vireo_import_error string  Error from the form-post redirect.
 *   $own_since          ?string First day Vireo recorded itself, or null.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template partial included inside a render method; locals are method-scoped at runtime.

$start_val    = '' !== ( $saved['date_start'] ?? '' ) ? $saved['date_start'] : $default_start;
$end_val      = '' !== ( $saved['date_end'] ?? '' ) ? $saved['date_end'] : $default_end;
$property_val = $saved['property_id'] ?? '';
?>
<div class="wrap va-import">
	<h1><?php esc_html_e( 'Import from Google Analytics 4', 'vireo-analytics' ); ?></h1>

	<?php if ( '' !== $vireo_import_error ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( $vireo_import_error ); ?></p></div>
	<?php endif; ?>

	<?php if ( ! $is_available ) : ?>
		<div class="notice notice-error">
			<p>
				<?php esc_html_e( 'This server is missing the OpenSSL functions needed to authenticate with Google. Ask your host to enable the PHP openssl extension.', 'vireo-analytics' ); ?>
			</p>
		</div>
	<?php endif; ?>

	<div class="va-import-card">
		<p class="va-import-info">
			<?php esc_html_e( 'Brings across daily pageviews and visitors, your pages, referrers, which pages each referrer sent readers to, countries, devices, outbound clicks, downloads, form submissions and your own custom events. Everything is stored in your own database, the same as traffic recorded from here on.', 'vireo-analytics' ); ?>
		</p>

		<details class="va-import-help">
			<summary><?php esc_html_e( 'How to get the key file', 'vireo-analytics' ); ?></summary>
			<p><strong><?php esc_html_e( 'In Google Cloud Console', 'vireo-analytics' ); ?></strong> (console.cloud.google.com)</p>
			<ol>
				<li><?php esc_html_e( 'Create a project, or open one you already have.', 'vireo-analytics' ); ?></li>
				<li><?php esc_html_e( 'Under APIs and services, enable the Google Analytics Data API. The plain Analytics API is a different one and will not work.', 'vireo-analytics' ); ?></li>
				<li><?php esc_html_e( 'Under IAM and admin, then Service accounts, create a service account. Its address ends in .iam.gserviceaccount.com, and you need that address for the next part.', 'vireo-analytics' ); ?></li>
				<li><?php esc_html_e( 'Open the service account, go to Keys, add a key, and choose JSON. The file downloads once and Google will not give it to you again.', 'vireo-analytics' ); ?></li>
			</ol>
			<p><strong><?php esc_html_e( 'In Google Analytics', 'vireo-analytics' ); ?></strong> (analytics.google.com)</p>
			<ol start="5">
				<li><?php esc_html_e( 'Open Admin, then Property access management, and add the service account address with Viewer access. Grant it on the property you want to import, not on the account.', 'vireo-analytics' ); ?></li>
				<li><?php esc_html_e( 'Your property ID is the number under Admin, then Property details. It is digits only, not the G- measurement ID.', 'vireo-analytics' ); ?></li>
			</ol>
			<p>
				<?php esc_html_e( 'Then open the downloaded file in a text editor and paste all of it, braces included, into the box below. The key stays in your database and is only used to ask Google for your own data. It is never sent anywhere else.', 'vireo-analytics' ); ?>
			</p>
		</details>

		<form id="va-import-ga4-form" class="va-import-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="vireo_start_ga4_import" />
			<?php wp_nonce_field( 'vireo_start_ga4_import' ); ?>

			<p>
				<label for="va-ga4-key"><?php esc_html_e( 'Service account key (JSON)', 'vireo-analytics' ); ?></label><br />
				<textarea id="va-ga4-key" name="key_json" rows="6" class="large-text code" autocomplete="off"
					placeholder="<?php echo esc_attr( $has_key ? __( 'A key is saved. Leave this empty to keep it.', 'vireo-analytics' ) : '{ "type": "service_account", ... }' ); ?>"></textarea>
				<?php if ( $has_key && '' !== $account_email ) : ?>
					<span class="description">
						<?php
						printf(
							/* translators: %s: service account email address */
							esc_html__( 'Using %s. Grant that address Viewer access on the property.', 'vireo-analytics' ),
							'<code>' . esc_html( $account_email ) . '</code>'
						);
						?>
					</span>
				<?php endif; ?>
			</p>

			<p>
				<label for="va-ga4-property"><?php esc_html_e( 'Property ID', 'vireo-analytics' ); ?></label><br />
				<input type="text" id="va-ga4-property" name="property_id" value="<?php echo esc_attr( $property_val ); ?>"
					class="regular-text" inputmode="numeric" placeholder="123456789" required />
			</p>

			<div class="va-import-field-row">
				<div class="va-import-field">
					<label for="va-ga4-start"><strong><?php esc_html_e( 'Start date', 'vireo-analytics' ); ?></strong></label>
					<input type="date" id="va-ga4-start" name="date_start" value="<?php echo esc_attr( $start_val ); ?>" required />
				</div>
				<div class="va-import-field">
					<label for="va-ga4-end"><strong><?php esc_html_e( 'End date', 'vireo-analytics' ); ?></strong></label>
					<input type="date" id="va-ga4-end" name="date_end" value="<?php echo esc_attr( $end_val ); ?>" required />
				</div>
			</div>

			<p class="va-import-warning">
				<?php esc_html_e( 'Importing the same dates twice adds the numbers twice, and that cannot be undone. If a run stops partway, narrow the range to what is missing rather than starting again from the beginning.', 'vireo-analytics' ); ?>
			</p>

			<?php include VIREO_DIR . 'templates/admin/import-own-data.php'; ?>

			<?php
			$va_ga4_history = \VireoAnalytics\Import_History::all( 'ga4' );
			if ( ! empty( $va_ga4_history ) ) :
				?>
				<div class="va-import-history">
					<p><strong><?php esc_html_e( 'Already imported', 'vireo-analytics' ); ?></strong></p>
					<ul>
						<?php foreach ( array_slice( $va_ga4_history, 0, 5 ) as $va_ga4_run ) : ?>
							<li><?php echo esc_html( \VireoAnalytics\Import_History::summary( $va_ga4_run ) ); ?></li>
						<?php endforeach; ?>
					</ul>
					<p>
						<label>
							<input type="checkbox" name="confirm_overlap" value="1" />
							<?php esc_html_e( 'Import these dates again anyway, adding to what is already stored.', 'vireo-analytics' ); ?>
						</label>
					</p>
				</div>
			<?php endif; ?>

			<p>
				<button type="submit" class="button button-primary" id="va-import-start" <?php disabled( ! $is_available ); ?>>
					<?php esc_html_e( 'Start import', 'vireo-analytics' ); ?>
				</button>
				<button type="button" class="button" id="va-import-cancel" hidden>
					<?php esc_html_e( 'Cancel', 'vireo-analytics' ); ?>
				</button>
			</p>
		</form>

		<div id="va-import-progress" class="va-import-progress" hidden>
			<div class="va-import-progress-bar" id="va-import-progress-bar" role="progressbar"
				aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"
				aria-label="<?php esc_attr_e( 'Import progress', 'vireo-analytics' ); ?>">
				<div class="va-import-progress-fill" id="va-import-progress-fill"></div>
			</div>
			<p class="va-import-progress-text">
				<span id="va-import-progress-label"></span>
				<span id="va-import-progress-pct">0%</span>
			</p>
		</div>

		<div id="va-import-result" class="va-import-result" role="status" aria-live="polite" hidden></div>

		<?php if ( $has_key ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="va-import-forget">
				<input type="hidden" name="action" value="vireo_forget_ga4_key" />
				<?php wp_nonce_field( 'vireo_forget_ga4_key' ); ?>
				<button type="submit" class="button-link">
					<?php esc_html_e( 'Remove the saved key', 'vireo-analytics' ); ?>
				</button>
				<span class="description">
					<?php esc_html_e( 'The key is only needed while importing. Removing it does not affect data already brought across.', 'vireo-analytics' ); ?>
				</span>
			</form>
		<?php endif; ?>
	</div>
</div>
