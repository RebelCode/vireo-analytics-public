<?php
/**
 * "Already imported" state for importers that take everything they find.
 *
 * The page used to look the same before and after a run: the same warning, the
 * same "Start import" button. Someone coming back to it read that as the first
 * run having failed, and the button invited the second run that would count
 * the history twice.
 *
 * Expected in scope:
 *   $last_run      array   Latest Import_History entry for this source.
 *   $source_name   string  Human name of the source plugin.
 *   $plugin_active bool    Is the source plugin still active?
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template partial included inside a render method; locals are method-scoped at runtime, not true globals.
use VireoAnalytics\Import_History;
?>
<div class="va-import-done" data-va-import-before>
	<p class="va-import-done-title">
		<span class="va-import-done-mark" aria-hidden="true">&#10003;</span>
		<?php
		printf(
			/* translators: %s: name of the analytics plugin that was imported from. */
			esc_html__( '%s history imported', 'vireo-analytics' ),
			esc_html( $source_name )
		);
		?>
	</p>
	<p><?php echo esc_html( Import_History::summary( $last_run ) ); ?></p>
	<p class="description">
		<?php esc_html_e( 'It is part of your Vireo Analytics reports now. Imports cannot be undone, and running this one again would add the same history a second time.', 'vireo-analytics' ); ?>
	</p>
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
