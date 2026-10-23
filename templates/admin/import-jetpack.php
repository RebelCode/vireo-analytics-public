<?php
/**
 * Import from Jetpack Stats — page template.
 *
 * Expected in scope:
 *   $importer         Jetpack_Importer
 *   $is_available     bool        Jetpack plugin currently active?
 *   $home_host        string      Default value for blog_uri.
 *   $saved            array       Previously-saved params (if any).
 *   $default_start    string      YYYY-MM-DD, 365 days ago.
 *   $default_end      string      YYYY-MM-DD, today.
 *   $vireo_import_error  string      Error from the form-post redirect.
 *   $own_since           ?string     First day Vireo recorded itself, or null.
 *   $running             bool        This load is the redirect that starts a run.
 */

defined( 'ABSPATH' ) || exit;


// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template partial included inside a render method; locals are method-scoped at runtime, not true globals.
use VireoAnalytics\Import_Controller;

$blog_uri  = '' !== $saved['blog_uri'] ? $saved['blog_uri'] : $home_host;
// Never render the key back into the page. It's a live WordPress.com API key,
// and a password input still carries its value in the HTML source. Show only
// whether one is held.
$has_key   = '' !== $saved['api_key'];
$start_val = '' !== $saved['date_start'] ? $saved['date_start'] : $default_start;
$end_val   = '' !== $saved['date_end'] ? $saved['date_end'] : $default_end;
?>
<div class="wrap va-import">
	<h1><?php esc_html_e( 'Import from Jetpack Stats', 'vireo-analytics' ); ?></h1>

	<?php if ( '' !== $vireo_import_error ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $vireo_import_error ); ?></p></div>
	<?php endif; ?>

	<?php if ( ! $is_available ) : ?>
		<?php // Not a problem, just the route this import takes, so no warning colour. ?>
		<p class="va-import-note">
			<?php esc_html_e( 'Jetpack isn\'t active here, so the import uses your WordPress.com API key.', 'vireo-analytics' ); ?>
		</p>
	<?php endif; ?>

	<div class="va-import-card">
		<p class="va-import-info">
			<?php esc_html_e( 'Pulls daily pageviews and referrers from the Jetpack / WordPress.com stats API. Country and device data isn\'t available in this API — Vireo Analytics will start tracking those from installation onward.', 'vireo-analytics' ); ?>
			<?php esc_html_e( 'Jetpack Stats has no visitor counts, so imported days show pageviews only.', 'vireo-analytics' ); ?>
		</p>

		<form id="va-import-jetpack-form" class="va-import-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="vireo_start_jetpack_import" />
			<?php wp_nonce_field( 'vireo_start_jetpack_import' ); ?>

			<div class="va-import-field">
				<label for="va-jetpack-api-key">
					<strong><?php esc_html_e( 'WordPress.com API key', 'vireo-analytics' ); ?></strong>
				</label>
				<input type="password" id="va-jetpack-api-key" name="api_key" value="" class="regular-text" autocomplete="off"
					placeholder="<?php echo $has_key ? esc_attr__( 'A key is saved for this import — leave blank to reuse it', 'vireo-analytics' ) : ''; ?>"
					<?php echo $has_key ? '' : 'required'; ?> />
				<p class="description">
					<?php
					echo wp_kses(
						__( 'Find this under <strong>Jetpack → Settings → Security → Akismet</strong>, or in your WordPress.com account under <strong>Security → Application Passwords</strong>.', 'vireo-analytics' ),
						[ 'strong' => [] ]
					);
					?>
				</p>
			</div>

			<div class="va-import-field">
				<label for="va-jetpack-blog-uri">
					<strong><?php esc_html_e( 'Blog URI', 'vireo-analytics' ); ?></strong>
				</label>
				<input type="text" id="va-jetpack-blog-uri" name="blog_uri" value="<?php echo esc_attr( $blog_uri ); ?>" class="regular-text" required />
				<p class="description"><?php esc_html_e( 'The domain Jetpack is tracking. Usually your site\'s host.', 'vireo-analytics' ); ?></p>
			</div>

			<div class="va-import-field-row">
				<div class="va-import-field">
					<label for="va-jetpack-start"><strong><?php esc_html_e( 'Start date', 'vireo-analytics' ); ?></strong></label>
					<input type="date" id="va-jetpack-start" name="date_start" value="<?php echo esc_attr( $start_val ); ?>" required />
				</div>
				<div class="va-import-field">
					<label for="va-jetpack-end"><strong><?php esc_html_e( 'End date', 'vireo-analytics' ); ?></strong></label>
					<input type="date" id="va-jetpack-end" name="date_end" value="<?php echo esc_attr( $end_val ); ?>" required />
				</div>
			</div>

			<div class="va-import-warnings">
				<p>
					<strong><?php esc_html_e( 'Re-running is additive.', 'vireo-analytics' ); ?></strong>
					<?php esc_html_e( 'Running the import over an overlapping date range will double-count those days.', 'vireo-analytics' ); ?>
				</p>
			</div>

			<?php include VIREO_DIR . 'templates/admin/import-own-data.php'; ?>

			<?php
			$va_jp_history = \VireoAnalytics\Import_History::all( 'jetpack' );
			if ( ! empty( $va_jp_history ) ) :
				?>
				<div class="va-import-history">
					<p><strong><?php esc_html_e( 'Already imported', 'vireo-analytics' ); ?></strong></p>
					<ul>
						<?php foreach ( array_slice( $va_jp_history, 0, 5 ) as $va_jp_run ) : ?>
							<li><?php echo esc_html( \VireoAnalytics\Import_History::summary( $va_jp_run ) ); ?></li>
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

			<div class="va-import-actions">
				<button type="submit" class="button button-primary" id="va-import-start">
					<?php esc_html_e( 'Start import', 'vireo-analytics' ); ?>
				</button>
				<button type="button" class="button" id="va-import-cancel" hidden>
					<?php esc_html_e( 'Cancel', 'vireo-analytics' ); ?>
				</button>
				<a class="button" href="<?php echo esc_url( \VireoAnalytics\Settings::url( 'data' ) ); ?>">
					<?php esc_html_e( 'Back to import & export', 'vireo-analytics' ); ?>
				</a>
			</div>
		</form>

		<div id="va-import-progress" class="va-import-progress" <?php echo $running ? '' : 'hidden'; ?>>
			<div class="va-import-progress-bar" id="va-import-progress-bar" role="progressbar"
				aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"
				aria-label="<?php esc_attr_e( 'Import progress', 'vireo-analytics' ); ?>">
				<div class="va-import-progress-fill" id="va-import-progress-fill"></div>
			</div>
			<div class="va-import-progress-text">
				<span id="va-import-progress-label"></span>
				<span id="va-import-progress-pct">0%</span>
			</div>
		</div>

		<div id="va-import-result" class="va-import-result" role="status" aria-live="polite" hidden></div>
	</div>
</div>
