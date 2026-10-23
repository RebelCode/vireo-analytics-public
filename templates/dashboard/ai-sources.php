<?php
/**
 * AI assistants: what they took, and what they sent back.
 *
 * Locals: $va_ai (array from AI_Sources::referrals over the counted days),
 * and from AI_Sources::exchange: $va_ai_crawled (int), $va_ai_referred (int),
 * $va_ai_rate (?float), $va_ai_since (string, the first counted day when it is
 * later than the range's start, else ''). The whole card covers one window.
 */

defined( 'ABSPATH' ) || exit;

$va_ai_total = isset( $va_ai['total'] ) ? (int) $va_ai['total'] : 0;
$va_ai_rows  = isset( $va_ai['by_source'] ) && is_array( $va_ai['by_source'] ) ? $va_ai['by_source'] : [];
$va_crawled  = isset( $va_ai_crawled ) ? (int) $va_ai_crawled : 0;
$va_referred = isset( $va_ai_referred ) ? (int) $va_ai_referred : $va_ai_total;
$va_since    = isset( $va_ai_since ) ? (string) $va_ai_since : '';

// Nothing to say on a site no assistant has touched yet.
if ( $va_ai_total < 1 && $va_crawled < 1 ) {
	return;
}
?>
<div class="va-card va-ai-sources">
	<h2 class="va-card-title"><?php esc_html_e( 'AI assistants', 'vireo-analytics' ); ?></h2>

	<div class="va-ai-exchange">
		<div class="va-ai-stat">
			<span class="va-ai-num"><?php echo esc_html( number_format_i18n( $va_crawled ) ); ?></span>
			<span class="va-ai-label"><?php esc_html_e( 'requests from their crawlers', 'vireo-analytics' ); ?></span>
		</div>
		<div class="va-ai-stat">
			<span class="va-ai-num"><?php echo esc_html( number_format_i18n( $va_referred ) ); ?></span>
			<span class="va-ai-label"><?php esc_html_e( 'readers they sent back', 'vireo-analytics' ); ?></span>
		</div>
		<?php
		if ( null !== $va_ai_rate ) :
			// "361.8" is false precision on a number that size; a decimal only
			// earns its place below ten, where 2.4 and 2 are different stories.
			$va_ai_rate_shown = round( (float) $va_ai_rate, 1 );
			?>
			<div class="va-ai-stat va-ai-rate">
				<span class="va-ai-num"><?php echo esc_html( number_format_i18n( $va_ai_rate_shown, $va_ai_rate_shown >= 10 ? 0 : 1 ) ); ?></span>
				<span class="va-ai-label"><?php esc_html_e( 'crawler requests per reader sent', 'vireo-analytics' ); ?></span>
			</div>
		<?php endif; ?>
	</div>
	<?php if ( '' !== $va_since ) : ?>
		<p class="va-ai-since">
			<?php
			/* translators: %s: a date such as "15 Sep 2026" */
			echo esc_html( sprintf( __( 'Since %s, when Vireo started counting crawlers. Earlier days in this range have readers but no crawler figures, so this card leaves them out.', 'vireo-analytics' ), vireo_format_day( $va_since, 'j M Y' ) ) );
			?>
		</p>
	<?php endif; ?>

	<?php if ( $va_ai_rows ) : ?>
		<table class="va-ai-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Assistant', 'vireo-analytics' ); ?></th>
					<th scope="col" class="va-num"><?php esc_html_e( 'Readers sent', 'vireo-analytics' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $va_ai_rows as $va_ai_row ) : ?>
					<tr>
						<td><?php echo esc_html( (string) $va_ai_row['source'] ); ?></td>
						<td class="va-num"><?php echo esc_html( number_format_i18n( (int) $va_ai_row['visitors'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php else : ?>
		<p class="va-ai-none">
			<?php esc_html_e( 'No assistant has sent a reader to this site in this period, though their crawlers have been reading it.', 'vireo-analytics' ); ?>
		</p>
	<?php endif; ?>

	<p class="va-ai-note">
		<?php esc_html_e( 'Crawler figures count requests the bot filter held back. Readers sent counts people who arrived by following a link inside an assistant, which is the closest thing there is to being recommended by one.', 'vireo-analytics' ); ?>
	</p>
</div>
