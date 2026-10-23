<?php
/**
 * Core Web Vitals for the current range.
 *
 * Locals: $va_vitals (array from Vitals::summary), $va_psi (PageSpeed::view
 * for the homepage, or null)
 *
 * Shows the 75th percentile, which is what these are graded on. An average
 * would read better and mean less: a page that is quick for three readers in
 * four and dreadful for the fourth still fails, and the mean hides exactly
 * that reader.
 */

defined( 'ABSPATH' ) || exit;

use VireoAnalytics\Vitals;

$va_psi = isset( $va_psi ) && is_array( $va_psi ) ? $va_psi : null;

if ( empty( $va_vitals ) && null === $va_psi ) {
	return;
}

$va_vitals_samples = 0;
foreach ( $va_vitals as $va_metric_data ) {
	$va_vitals_samples = max( $va_vitals_samples, (int) $va_metric_data['samples'] );
}
?>
<div class="va-card va-vitals">
	<h2 class="va-card-title"><?php esc_html_e( 'Page speed from real visits', 'vireo-analytics' ); ?></h2>

	<?php if ( empty( $va_vitals ) ) : ?>
		<p class="va-vitals-footnote"><?php esc_html_e( 'No speed measurements from real visits in this range yet. They appear as people load your pages.', 'vireo-analytics' ); ?></p>
	<?php else : ?>
	<p class="va-vitals-basis"><?php esc_html_e( '75th percentile: three in four visits were at least this fast.', 'vireo-analytics' ); ?></p>
	<div class="va-vitals-grid">
		<?php foreach ( [ 'lcp', 'inp', 'cls' ] as $va_metric ) : ?>
			<?php
			if ( empty( $va_vitals[ $va_metric ] ) ) {
				continue;
			}
			$va_data = $va_vitals[ $va_metric ];
			?>
			<div class="va-vitals-metric va-vitals-<?php echo esc_attr( $va_data['rating'] ); ?>">
				<span class="va-vitals-value">
					<?php echo esc_html( $va_data['display'] ); ?>
					<span class="va-vitals-grade"><?php echo esc_html( Vitals::rating_label( $va_data['rating'] ) ); ?></span>
				</span>
				<span class="va-vitals-label">
					<?php echo esc_html( Vitals::label( $va_metric ) ); ?>
					<?php // Google's names, so these line up with PageSpeed Insights below. ?>
					<span class="va-vitals-abbr"><?php echo esc_html( strtoupper( $va_metric ) ); ?></span>
				</span>
				<span class="va-vitals-note"><?php echo esc_html( Vitals::explanation( $va_metric ) ); ?></span>
			</div>
		<?php endforeach; ?>
	</div>

	<p class="va-vitals-footnote">
		<?php esc_html_e( 'Google grades pages on this line rather than the average, so the slowest quarter of visits counts against you.', 'vireo-analytics' ); ?>
		<?php
		$va_vitals_count = '<strong>' . esc_html( number_format_i18n( $va_vitals_samples ) ) . '</strong>';
		if ( $va_vitals_samples < 30 ) {
			printf(
				/* translators: %s: formatted number of measured visits */
				esc_html( _n( 'Only %s real visit measured so far, so expect these to move.', 'Only %s real visits measured so far, so expect these to move.', $va_vitals_samples, 'vireo-analytics' ) ),
				$va_vitals_count // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above
			);
		} else {
			printf(
				/* translators: %s: formatted number of measured visits */
				esc_html( _n( 'Measured from %s real visit, not a lab test.', 'Measured from %s real visits, not a lab test.', $va_vitals_samples, 'vireo-analytics' ) ),
				$va_vitals_count // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above
			);
		}
		?>
	</p>
	<?php endif; ?>

	<?php
	if ( null !== $va_psi ) {
		$va_psi_title     = __( 'Your homepage, tested by Google', 'vireo-analytics' );
		$va_psi_cta_title = __( 'Test your homepage with Google', 'vireo-analytics' );
		include VIREO_DIR . 'templates/dashboard/pagespeed.php';
	}
	?>
</div>
