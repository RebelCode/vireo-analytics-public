<?php
/**
 * Import page for any source that needs no options beyond "go".
 *
 * Expected in scope:
 *   $importer      Importer_Base
 *   $is_available  bool    Source tables found in this database?
 *   $record_count  int     Units of work the importer reports.
 *   $source_name   string  Human name of the source plugin.
 *   $form_id       string  Form element id the JS driver binds to.
 *   $last_run      ?array  Latest Import_History entry for this source.
 *   $plugin_active bool    Is the source plugin still active?
 *   $own_since     ?string First day Vireo recorded itself, or null.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template partial included inside a render method; locals are method-scoped at runtime, not true globals.
?>
<div class="wrap va-import">
	<h1>
		<?php
		printf(
			/* translators: %s: name of the analytics plugin being imported from. */
			esc_html__( 'Import from %s', 'vireo-analytics' ),
			esc_html( $source_name )
		);
		?>
	</h1>

	<?php if ( ! $is_available ) : ?>
		<div class="notice notice-warning">
			<p>
				<?php
				printf(
					/* translators: %s: name of the analytics plugin being imported from. */
					esc_html__( 'No %s data found in this database. The plugin needs to have been installed on this site and collected some data before there is anything to bring across.', 'vireo-analytics' ),
					esc_html( $source_name )
				);
				?>
			</p>
		</div>
		<p>
			<a class="button" href="<?php echo esc_url( \VireoAnalytics\Settings::url( 'data' ) ); ?>">
				<?php esc_html_e( 'Back to import & export', 'vireo-analytics' ); ?>
			</a>
		</p>
	<?php else : ?>
		<div class="va-import-card">
			<?php if ( null !== $last_run ) : ?>
				<?php include VIREO_DIR . 'templates/admin/import-done.php'; ?>
			<?php else : ?>
				<div class="va-import-summary" data-va-import-before>
					<div class="va-import-summary-row">
						<span class="va-import-summary-label"><?php esc_html_e( 'Records found', 'vireo-analytics' ); ?></span>
						<strong><?php echo esc_html( number_format_i18n( (int) $record_count ) ); ?></strong>
					</div>
				</div>

				<div class="va-import-warnings" data-va-import-before>
					<p>
						<strong><?php esc_html_e( 'What this does:', 'vireo-analytics' ); ?></strong>
						<?php
						printf(
							/* translators: %s: name of the analytics plugin being imported from. */
							esc_html__( 'Copies your history from %s into Vireo Analytics. The source data is read only and is never modified.', 'vireo-analytics' ),
							esc_html( $source_name )
						);
						if ( '' !== $carries ) {
							echo ' ' . esc_html( $carries );
						}
						?>
					</p>
					<p>
						<strong><?php esc_html_e( 'Run it once.', 'vireo-analytics' ); ?></strong>
						<?php esc_html_e( 'An import adds what it reads to what Vireo Analytics already holds, so a second run would count the same history twice. Imports cannot be undone.', 'vireo-analytics' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<form id="<?php echo esc_attr( $form_id ); ?>" class="va-import-form" data-va-import-before>
				<?php include VIREO_DIR . 'templates/admin/import-own-data.php'; ?>

				<?php if ( null !== $last_run ) : ?>
					<details class="va-import-again">
						<summary><?php esc_html_e( 'Import again anyway', 'vireo-analytics' ); ?></summary>
						<p>
							<?php
							printf(
								/* translators: %s: name of the analytics plugin being imported from. */
								esc_html__( 'Only if Vireo Analytics no longer holds the history from %s, for example after its data was reset. Otherwise every day imported above is counted twice, and there is no way to take it back out.', 'vireo-analytics' ),
								esc_html( $source_name )
							);
							?>
						</p>
						<label class="va-import-checkbox">
							<input type="checkbox" name="confirm_overlap" value="1" data-va-import-confirm />
							<?php esc_html_e( 'I understand this adds the same history a second time.', 'vireo-analytics' ); ?>
						</label>
						<div class="va-import-actions">
							<button type="submit" class="button" id="va-import-start" disabled>
								<?php esc_html_e( 'Import again', 'vireo-analytics' ); ?>
							</button>
							<button type="button" class="button" id="va-import-cancel" hidden>
								<?php esc_html_e( 'Cancel', 'vireo-analytics' ); ?>
							</button>
						</div>
					</details>
				<?php else : ?>
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
				<?php endif; ?>
			</form>

			<div id="va-import-progress" class="va-import-progress" hidden>
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

			<div class="va-import-footer" id="va-import-footer" hidden>
				<?php if ( $plugin_active ) : ?>
					<p>
						<?php
						printf(
							/* translators: %s: name of the analytics plugin being imported from. */
							esc_html__( 'You can deactivate %s whenever you are ready. Vireo Analytics is tracking on its own now.', 'vireo-analytics' ),
							esc_html( $source_name )
						);
						?>
					</p>
				<?php endif; ?>
				<div class="va-import-actions">
					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=vireo-analytics' ) ); ?>">
						<?php esc_html_e( 'View your reports', 'vireo-analytics' ); ?>
					</a>
					<?php if ( $plugin_active ) : ?>
						<a class="button" href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>">
							<?php esc_html_e( 'Go to Plugins', 'vireo-analytics' ); ?>
						</a>
					<?php endif; ?>
				</div>
			</div>
		</div>
	<?php endif; ?>
</div>
