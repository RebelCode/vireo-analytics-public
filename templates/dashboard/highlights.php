<?php
/**
 * This week's highlights, on the default Overview only.
 *
 * Locals: $va_highlights (Highlights::for_week() output), $va_highlights_week
 * ([start, end] of the last seven full days). Empty highlights render nothing.
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $va_highlights ) ) {
	return;
}
?>
<div class="va-card va-highlights">
	<div class="va-highlights-head">
		<h2 class="va-yir-title"><?php esc_html_e( 'This week', 'vireo-analytics' ); ?></h2>
		<span class="va-highlights-range">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: start date, 2: end date */
					__( '%1$s to %2$s', 'vireo-analytics' ),
					vireo_format_day( $va_highlights_week[0], 'M j' ),
					vireo_format_day( $va_highlights_week[1], 'M j' )
				)
			);
			?>
		</span>
	</div>
	<ul class="va-highlights-list">
		<?php foreach ( $va_highlights as $va_highlight ) : ?>
			<li data-va-highlight="<?php echo esc_attr( $va_highlight['kind'] ); ?>"><?php echo esc_html( $va_highlight['text'] ); ?></li>
		<?php endforeach; ?>
	</ul>
</div>
