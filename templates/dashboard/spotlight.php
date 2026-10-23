<?php
/**
 * Spotlight: what visitors did with the Instagram feeds on the site.
 *
 * Locals: $va_spotlight (array from Integrations::spotlight_report, or null
 * when the plugin isn't active).
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template partial included inside a render method; locals are method-scoped at runtime, not true globals.

if ( empty( $va_spotlight ) || array_sum( $va_spotlight['totals'] ) < 1 ) {
	return;
}

$va_sl_labels = [
	'spotlight_post_click' => __( 'posts opened', 'vireo-analytics' ),
	'spotlight_popup_link' => __( 'links followed from a post', 'vireo-analytics' ),
	'spotlight_follow'     => __( 'Follow clicks', 'vireo-analytics' ),
	'spotlight_load_more'  => __( 'Load more clicks', 'vireo-analytics' ),
];
?>
<div class="va-card va-ai-sources va-integration va-spotlight">
	<h2 class="va-card-title"><?php esc_html_e( 'Instagram feeds', 'vireo-analytics' ); ?></h2>

	<div class="va-ai-exchange">
		<?php foreach ( $va_sl_labels as $va_sl_event => $va_sl_label ) : ?>
			<div class="va-ai-stat">
				<span class="va-ai-num"><?php echo esc_html( number_format_i18n( (int) ( $va_spotlight['totals'][ $va_sl_event ] ?? 0 ) ) ); ?></span>
				<span class="va-ai-label"><?php echo esc_html( $va_sl_label ); ?></span>
			</div>
		<?php endforeach; ?>
	</div>

	<?php if ( ! empty( $va_spotlight['pages'] ) ) : ?>
		<table class="va-ai-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Page with a feed', 'vireo-analytics' ); ?></th>
					<th scope="col" class="va-num"><?php esc_html_e( 'Posts opened', 'vireo-analytics' ); ?></th>
					<th scope="col" class="va-num"><?php esc_html_e( 'Follow clicks', 'vireo-analytics' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $va_spotlight['pages'] as $va_sl_row ) : ?>
					<tr>
						<td><?php echo esc_html( (string) $va_sl_row['path'] ); ?></td>
						<td class="va-num"><?php echo esc_html( number_format_i18n( (int) $va_sl_row['by_event']['spotlight_post_click'] ) ); ?></td>
						<td class="va-num"><?php echo esc_html( number_format_i18n( (int) $va_sl_row['by_event']['spotlight_follow'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
