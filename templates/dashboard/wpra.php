<?php
/**
 * WP RSS Aggregator: how imported content did, per source.
 *
 * Locals: $va_wpra (array from Integrations::wpra_report, or null when the
 * plugin isn't active).
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template partial included inside a render method; locals are method-scoped at runtime, not true globals.

if ( empty( $va_wpra ) || ( (int) $va_wpra['views'] < 1 && (int) $va_wpra['clicks'] < 1 ) ) {
	return;
}

$va_wpra_share = $va_wpra['share'];
// Free WP RSS Aggregator links items straight to the original article, so
// only sites importing items as posts have views to report.
$va_wpra_views = (int) $va_wpra['views'] > 0;
?>
<div class="va-card va-ai-sources va-integration va-wpra">
	<h2 class="va-card-title"><?php esc_html_e( 'Aggregated content', 'vireo-analytics' ); ?></h2>

	<div class="va-ai-exchange">
		<?php if ( $va_wpra_views ) : ?>
			<div class="va-ai-stat">
				<span class="va-ai-num"><?php echo esc_html( number_format_i18n( (int) $va_wpra['views'] ) ); ?></span>
				<span class="va-ai-label"><?php esc_html_e( 'views of imported posts', 'vireo-analytics' ); ?></span>
			</div>
		<?php endif; ?>
		<div class="va-ai-stat">
			<span class="va-ai-num"><?php echo esc_html( number_format_i18n( (int) $va_wpra['clicks'] ) ); ?></span>
			<span class="va-ai-label"><?php esc_html_e( 'clicks through to the original articles', 'vireo-analytics' ); ?></span>
		</div>
		<?php if ( $va_wpra_views && null !== $va_wpra_share ) : ?>
			<div class="va-ai-stat">
				<span class="va-ai-num"><?php echo esc_html( number_format_i18n( 100 * (float) $va_wpra_share, 1 ) . '%' ); ?></span>
				<span class="va-ai-label"><?php esc_html_e( 'of all pageviews were imported posts', 'vireo-analytics' ); ?></span>
			</div>
		<?php endif; ?>
	</div>

	<?php if ( ! empty( $va_wpra['sources'] ) ) : ?>
		<table class="va-ai-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Feed source', 'vireo-analytics' ); ?></th>
					<?php if ( $va_wpra_views ) : ?>
						<th scope="col" class="va-num"><?php esc_html_e( 'Views here', 'vireo-analytics' ); ?></th>
					<?php endif; ?>
					<th scope="col" class="va-num"><?php esc_html_e( 'Clicks out', 'vireo-analytics' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $va_wpra['sources'] as $va_wpra_row ) : ?>
					<tr>
						<td><?php echo esc_html( (string) $va_wpra_row['name'] ); ?></td>
						<?php if ( $va_wpra_views ) : ?>
							<td class="va-num"><?php echo esc_html( number_format_i18n( (int) $va_wpra_row['views'] ) ); ?></td>
						<?php endif; ?>
						<td class="va-num"><?php echo esc_html( number_format_i18n( (int) $va_wpra_row['clicks'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<p class="va-ai-none">
		<?php esc_html_e( 'Clicks out count readers who followed an item to the site it came from, recorded from the day this version of Vireo was installed.', 'vireo-analytics' ); ?>
		<?php if ( $va_wpra_views ) : ?>
			<?php esc_html_e( 'Views here count visits to items imported as posts.', 'vireo-analytics' ); ?>
		<?php endif; ?>
	</p>
</div>
