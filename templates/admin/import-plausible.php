<?php
/**
 * Import from Plausible Analytics page template.
 *
 * Expected in scope:
 *   $importer           Plausible_Importer
 *   $staged             array|null  What the upload staged, while a run is starting.
 *   $can_read_zip       bool        ZipArchive is available.
 *   $max_upload_size    int         Largest upload this server accepts, in bytes.
 *   $vireo_import_error string      Error from the form-post redirect.
 *   $own_since          ?string     First day Vireo recorded itself, or null.
 *   $last_run           ?array      Latest Import_History entry for Plausible.
 *   $source_name        string      Human name of the source.
 *   $plugin_active      bool        Is the Plausible plugin active here?
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template partial included inside a render method; locals are method-scoped at runtime.

$report_labels = [
	'visitors'          => __( 'daily visitors and pageviews', 'vireo-analytics' ),
	'pages'             => __( 'pages', 'vireo-analytics' ),
	'sources'           => __( 'referrers', 'vireo-analytics' ),
	'locations'         => __( 'countries', 'vireo-analytics' ),
	'devices'           => __( 'device types', 'vireo-analytics' ),
	'entry_pages'       => __( 'entry pages', 'vireo-analytics' ),
	'exit_pages'        => __( 'exit pages', 'vireo-analytics' ),
	'browsers'          => __( 'browsers', 'vireo-analytics' ),
	'operating_systems' => __( 'operating systems', 'vireo-analytics' ),
	'custom_events'     => __( 'goals and custom events', 'vireo-analytics' ),
	'custom_props'      => __( 'custom properties', 'vireo-analytics' ),
	'sources_by_name'   => __( 'referrers, which this export names without their addresses', 'vireo-analytics' ),
];
$label_list    = static function ( array $reports ) use ( $report_labels ): string {
	return implode( ', ', array_map( static fn( string $r ): string => $report_labels[ $r ] ?? $r, $reports ) );
};
?>
<div class="wrap va-import">
	<h1><?php esc_html_e( 'Import from Plausible Analytics', 'vireo-analytics' ); ?></h1>

	<?php if ( '' !== $vireo_import_error ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( $vireo_import_error ); ?></p></div>
	<?php endif; ?>

	<?php if ( null !== $staged && $staged['batches'] > 0 ) : ?>
		<div class="notice notice-info">
			<p>
				<?php
				printf(
					/* translators: 1: start date, 2: end date, 3: list of reports */
					esc_html__( 'Importing %1$s to %2$s: %3$s.', 'vireo-analytics' ),
					esc_html( $staged['date_start'] ),
					esc_html( $staged['date_end'] ),
					esc_html( $label_list( $staged['imported'] ) )
				);
				?>
			</p>
			<?php if ( ! empty( $staged['skipped'] ) ) : ?>
				<p>
					<?php
					printf(
						/* translators: %s: list of reports */
						esc_html__( 'Not imported, because Vireo Analytics has nowhere to keep them: %s.', 'vireo-analytics' ),
						esc_html( $label_list( $staged['skipped'] ) )
					);
					?>
				</p>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<div class="va-import-card">
		<?php
		// An export can cover any range, so after an import the screen leads with
		// what was brought in and keeps the upload behind a second step, for an
		// export covering other dates.
		$va_plausible_done = null !== $last_run && null === $staged;
		if ( $va_plausible_done ) {
			include VIREO_DIR . 'templates/admin/import-done.php';
		}
		?>

		<?php if ( $va_plausible_done ) : ?>
			<details class="va-import-again" data-va-import-before>
				<summary><?php esc_html_e( 'Import another export', 'vireo-analytics' ); ?></summary>
				<p><?php esc_html_e( 'For an export covering dates not imported yet. Dates already imported are refused unless you confirm them below.', 'vireo-analytics' ); ?></p>
		<?php endif; ?>

		<p class="va-import-info">
			<?php esc_html_e( 'Brings across daily pageviews and visitors, your pages, referrers, countries, device types, and goals and custom events from a Plausible export. Outbound link clicks, file downloads and form submissions become the same events Vireo Analytics records itself, and 404 events are listed under missing pages. Browsers and operating systems are not imported: Plausible exports them separately from device type, and Vireo Analytics stores the three together, so for imported days they show as Unknown.', 'vireo-analytics' ); ?>
		</p>

		<details class="va-import-help">
			<summary><?php esc_html_e( 'How to get the export', 'vireo-analytics' ); ?></summary>
			<ol>
				<li><?php esc_html_e( 'In Plausible, open the site, then Site settings, then Imports & Exports.', 'vireo-analytics' ); ?></li>
				<li><?php esc_html_e( 'Under Export data, start an export. Plausible emails a link to a zip file when it is ready.', 'vireo-analytics' ); ?></li>
				<li><?php esc_html_e( 'Download the zip and upload it here as it is. If you have already unzipped it, select the CSV files instead.', 'vireo-analytics' ); ?></li>
			</ol>
			<p>
				<?php esc_html_e( 'The file is read once and deleted. Nothing is sent to Plausible and no account details are needed.', 'vireo-analytics' ); ?>
			</p>
		</details>

		<?php if ( ! $can_read_zip ) : ?>
			<div class="notice notice-warning inline">
				<p><?php esc_html_e( 'This server cannot open zip files. Unzip the export on your computer and upload the CSV files instead.', 'vireo-analytics' ); ?></p>
			</div>
		<?php endif; ?>

		<form id="va-import-plausible-form" class="va-import-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-va-import-before>
			<input type="hidden" name="action" value="vireo_start_plausible_import" />
			<?php wp_nonce_field( 'vireo_start_plausible_import' ); ?>

			<p>
				<label for="va-plausible-file"><?php esc_html_e( 'Plausible export (.zip or .csv files)', 'vireo-analytics' ); ?></label><br />
				<input type="file" id="va-plausible-file" name="plausible_export[]" multiple required
					accept="<?php echo esc_attr( $can_read_zip ? '.zip,.csv,application/zip,text/csv' : '.csv,text/csv' ); ?>" />
				<span class="description">
					<?php
					printf(
						/* translators: %s: maximum upload size, e.g. 64 MB */
						esc_html__( 'Maximum upload size: %s.', 'vireo-analytics' ),
						esc_html( size_format( $max_upload_size ) )
					);
					?>
				</span>
			</p>

			<div class="va-import-field-row">
				<div class="va-import-field">
					<label for="va-plausible-start"><strong><?php esc_html_e( 'Start date', 'vireo-analytics' ); ?></strong></label>
					<input type="date" id="va-plausible-start" name="date_start" value="" />
				</div>
				<div class="va-import-field">
					<label for="va-plausible-end"><strong><?php esc_html_e( 'End date', 'vireo-analytics' ); ?></strong></label>
					<input type="date" id="va-plausible-end" name="date_end" value="" />
				</div>
			</div>
			<p class="description"><?php esc_html_e( 'Optional. Leave both empty to import every day in the export.', 'vireo-analytics' ); ?></p>

			<?php include VIREO_DIR . 'templates/admin/import-own-data.php'; ?>

			<p class="va-import-warning">
				<?php esc_html_e( 'Importing the same dates twice adds the numbers twice, and that cannot be undone. If a run stops partway, set the dates to what is missing rather than starting again from the beginning.', 'vireo-analytics' ); ?>
			</p>

			<?php
			$va_plausible_history = \VireoAnalytics\Import_History::all( 'plausible' );
			if ( ! empty( $va_plausible_history ) ) :
				?>
				<div class="va-import-history">
					<p><strong><?php esc_html_e( 'Already imported', 'vireo-analytics' ); ?></strong></p>
					<ul>
						<?php foreach ( array_slice( $va_plausible_history, 0, 5 ) as $va_plausible_run ) : ?>
							<li><?php echo esc_html( \VireoAnalytics\Import_History::summary( $va_plausible_run ) ); ?></li>
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
				<button type="submit" class="button button-primary" id="va-import-start">
					<?php esc_html_e( 'Start import', 'vireo-analytics' ); ?>
				</button>
				<button type="button" class="button" id="va-import-cancel" hidden>
					<?php esc_html_e( 'Cancel', 'vireo-analytics' ); ?>
				</button>
			</p>
		</form>

		<?php if ( $va_plausible_done ) : ?>
			</details>
		<?php endif; ?>

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
	</div>
</div>
