<?php
/**
 * "Your <year> in review is ready", on the Overview from Dec 1 through January.
 *
 * Locals: $va_yir_notice (int year).
 */

defined( 'ABSPATH' ) || exit;

?>
<div class="va-yir-notice" data-va-yir-notice data-year="<?php echo esc_attr( (string) $va_yir_notice ); ?>">
	<p>
		<?php
		/* translators: %d: year */
		echo esc_html( sprintf( __( 'Your %d in review is ready.', 'vireo-analytics' ), $va_yir_notice ) );
		?>
		<a href="<?php echo esc_url( \VireoAnalytics\Year_Review::url( $va_yir_notice ) ); ?>"><?php esc_html_e( 'See it', 'vireo-analytics' ); ?></a>
	</p>
	<button type="button" class="va-yir-notice-dismiss" data-va-yir-dismiss aria-label="<?php esc_attr_e( 'Dismiss', 'vireo-analytics' ); ?>">&times;</button>
</div>
