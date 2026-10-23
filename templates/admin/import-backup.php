<?php
/**
 * Restore a Vireo Analytics backup.
 *
 * Expected in scope:
 *   $importer           Backup_Importer
 *   $staged             array|null  The staged file's details while a run is starting.
 *   $max_upload_size    int         Largest upload this server accepts, in bytes.
 *   $history            array       Import_History entries for backups.
 *   $vireo_import_error string      Error from the form-post redirect.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template partial included inside a render method; locals are method-scoped at runtime.

use VireoAnalytics\Backup;
use VireoAnalytics\Import_History;
use VireoAnalytics\Settings;

$va_date_format = (string) get_option( 'date_format', 'F j, Y' );
?>
<div class="wrap va-import">
	<h1><?php esc_html_e( 'Restore a Vireo Analytics backup', 'vireo-analytics' ); ?></h1>

	<?php if ( '' !== $vireo_import_error ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( $vireo_import_error ); ?></p></div>
	<?php endif; ?>

	<?php if ( null !== $staged && $staged['lines'] > 0 ) : ?>
		<div class="notice notice-info">
			<p>
				<?php
				printf(
					/* translators: 1: site address the backup came from, 2: first day, 3: last day, 4: number of rows, 5: "merge" or "replace" in words */
					esc_html__( 'Loading the backup of %1$s, %2$s to %3$s, %4$s rows. %5$s', 'vireo-analytics' ),
					esc_html( (string) wp_parse_url( $staged['site'], PHP_URL_HOST ) ?: $staged['site'] ),
					esc_html( '' === $staged['from'] ? '?' : vireo_format_day( $staged['from'], $va_date_format ) ),
					esc_html( '' === $staged['to'] ? '?' : vireo_format_day( $staged['to'], $va_date_format ) ),
					esc_html( number_format_i18n( $staged['rows'] ) ),
					'replace' === $staged['mode']
						? esc_html__( 'Everything recorded here is deleted first.', 'vireo-analytics' )
						: esc_html__( 'Days in the file replace the same days here; everything else is kept.', 'vireo-analytics' )
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<div class="va-import-card">
		<p class="va-import-info">
			<?php esc_html_e( 'Loads a file made with Download backup on another install, or on this one. Everything the reports are built from comes across: daily totals, pages, referrers, countries, devices, events, missing pages, bot traffic, Core Web Vitals, chart notes and the Search Console cache. Settings and connected sites are not in the file.', 'vireo-analytics' ); ?>
		</p>
		<p class="va-import-info">
			<?php
			printf(
				/* translators: %s: link to the settings screen, labelled "Settings, Import & export" */
				esc_html__( 'To make the file, open %s on the site you are copying from and use Download backup.', 'vireo-analytics' ),
				'<a href="' . esc_url( Settings::url( 'data' ) . '#vireo-backup' ) . '">' . esc_html__( 'Settings, Import & export', 'vireo-analytics' ) . '</a>'
			);
			?>
		</p>

		<form id="va-import-backup-form" class="va-import-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-va-import-before>
			<input type="hidden" name="action" value="vireo_start_backup_import" />
			<?php wp_nonce_field( 'vireo_start_backup_import' ); ?>

			<p>
				<label for="va-backup-file"><?php esc_html_e( 'Backup file (.jsonl.gz or .jsonl)', 'vireo-analytics' ); ?></label><br />
				<input type="file" id="va-backup-file" name="backup_file" required accept=".gz,.jsonl,application/gzip,application/x-gzip,application/x-ndjson" />
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

			<fieldset class="va-import-modes">
				<legend><strong><?php esc_html_e( 'What to do with the numbers already here', 'vireo-analytics' ); ?></strong></legend>
				<label class="va-import-checkbox">
					<input type="radio" name="mode" value="merge" checked data-va-backup-mode />
					<?php esc_html_e( 'Merge: days in the file replace the same days here, everything else is kept.', 'vireo-analytics' ); ?>
				</label>
				<label class="va-import-checkbox">
					<input type="radio" name="mode" value="replace" data-va-backup-mode />
					<?php esc_html_e( 'Replace: delete everything recorded here first, so this site ends up holding exactly what the file holds.', 'vireo-analytics' ); ?>
				</label>
			</fieldset>

			<div class="va-import-warnings" data-va-backup-replace hidden>
				<p class="va-import-warning">
					<?php esc_html_e( 'Replace deletes every report table on this site before loading the file, and that cannot be undone. Download a backup of this site first if there is anything here you might want back.', 'vireo-analytics' ); ?>
				</p>
				<label class="va-import-checkbox">
					<input type="checkbox" name="confirm_replace" value="1" />
					<?php esc_html_e( 'I understand that everything recorded here will be deleted.', 'vireo-analytics' ); ?>
				</label>
			</div>

			<p class="description">
				<?php esc_html_e( 'Loading the same file twice gives the same numbers, so a restore that stopped partway can simply be run again.', 'vireo-analytics' ); ?>
			</p>

			<?php if ( ! empty( $history ) ) : ?>
				<div class="va-import-history">
					<p><strong><?php esc_html_e( 'Earlier restores', 'vireo-analytics' ); ?></strong></p>
					<ul>
						<?php foreach ( array_slice( $history, 0, 5 ) as $va_backup_run ) : ?>
							<li><?php echo esc_html( Import_History::summary( $va_backup_run ) ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<p>
				<button type="submit" class="button button-primary" id="va-import-start">
					<?php esc_html_e( 'Restore', 'vireo-analytics' ); ?>
				</button>
				<button type="button" class="button" id="va-import-cancel" hidden>
					<?php esc_html_e( 'Cancel', 'vireo-analytics' ); ?>
				</button>
			</p>
		</form>

		<div id="va-import-progress" class="va-import-progress" hidden>
			<div class="va-import-progress-bar" id="va-import-progress-bar" role="progressbar"
				aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"
				aria-label="<?php esc_attr_e( 'Restore progress', 'vireo-analytics' ); ?>">
				<div class="va-import-progress-fill" id="va-import-progress-fill"></div>
			</div>
			<p class="va-import-progress-text">
				<span id="va-import-progress-label"></span>
				<span id="va-import-progress-pct">0%</span>
			</p>
		</div>

		<div id="va-import-result" class="va-import-result" role="status" aria-live="polite" hidden></div>

		<p class="va-import-footer" id="va-import-footer" hidden>
			<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=vireo-analytics' ) ); ?>"><?php esc_html_e( 'View your reports', 'vireo-analytics' ); ?></a>
		</p>
	</div>
</div>
