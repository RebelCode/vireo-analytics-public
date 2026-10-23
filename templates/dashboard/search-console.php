<?php
/**
 * What people searched for before they arrived.
 *
 * Locals: $va_gsc         (array from Search_Console::summary, or null)
 *         $va_gsc_queries (array from Search_Console::top_queries)
 *
 * Positions are averaged weighted by impressions, so a query seen twice at
 * position 90 does not drag the number as hard as one seen ten thousand times
 * at three.
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $va_gsc ) ) {
	return;
}

$va_gsc_ctr = $va_gsc['impressions'] > 0
	? round( ( $va_gsc['clicks'] / $va_gsc['impressions'] ) * 100, 1 )
	: 0.0;
?>
<div class="va-card va-gsc">
	<h2 class="va-card-title"><?php esc_html_e( 'Searches that found you', 'vireo-analytics' ); ?></h2>

	<div class="va-ai-exchange">
		<div class="va-ai-stat">
			<span class="va-ai-num"><?php echo esc_html( number_format_i18n( (int) $va_gsc['clicks'] ) ); ?></span>
			<span class="va-ai-label"><?php esc_html_e( 'clicks from search', 'vireo-analytics' ); ?></span>
		</div>
		<div class="va-ai-stat">
			<span class="va-ai-num"><?php echo esc_html( number_format_i18n( (int) $va_gsc['impressions'] ) ); ?></span>
			<span class="va-ai-label"><?php esc_html_e( 'times you appeared', 'vireo-analytics' ); ?></span>
		</div>
		<div class="va-ai-stat va-ai-rate">
			<span class="va-ai-num"><?php echo esc_html( number_format_i18n( $va_gsc_ctr, 1 ) ); ?>%</span>
			<span class="va-ai-label"><?php esc_html_e( 'click-through rate', 'vireo-analytics' ); ?></span>
		</div>
		<div class="va-ai-stat">
			<span class="va-ai-num"><?php echo esc_html( number_format_i18n( (float) $va_gsc['position'], 1 ) ); ?></span>
			<span class="va-ai-label"><?php esc_html_e( 'average position', 'vireo-analytics' ); ?></span>
		</div>
	</div>

	<?php if ( ! empty( $va_gsc_queries ) ) : ?>
		<table class="va-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Search', 'vireo-analytics' ); ?></th>
					<th scope="col" class="va-num"><?php esc_html_e( 'Clicks', 'vireo-analytics' ); ?></th>
					<th scope="col" class="va-num"><?php esc_html_e( 'Impressions', 'vireo-analytics' ); ?></th>
					<th scope="col" class="va-num"><?php esc_html_e( 'Position', 'vireo-analytics' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $va_gsc_queries as $va_gsc_row ) : ?>
				<tr>
					<td class="va-gsc-query">
						<?php echo esc_html( $va_gsc_row['query'] ); ?>
						<?php
						$va_gsc_page = (string) ( ( $va_gsc_landing ?? [] )[ $va_gsc_row['query_hash'] ] ?? '' );
						if ( '' !== $va_gsc_page ) :
							?>
							<span class="va-gsc-landing" title="<?php esc_attr_e( 'The page Google most often showed for this search', 'vireo-analytics' ); ?>"><?php echo esc_html( $va_gsc_page ); ?></span>
						<?php endif; ?>
					</td>
					<td class="va-num"><?php echo esc_html( number_format_i18n( (int) $va_gsc_row['clicks'] ) ); ?></td>
					<td class="va-num"><?php echo esc_html( number_format_i18n( (int) $va_gsc_row['impressions'] ) ); ?></td>
					<td class="va-num"><?php echo esc_html( number_format_i18n( (float) $va_gsc_row['position'], 1 ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<p class="va-vitals-footnote">
		<?php esc_html_e( 'From Search Console, which reports a few days behind and leaves out rare searches.', 'vireo-analytics' ); ?>
	</p>
</div>
