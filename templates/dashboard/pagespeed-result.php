<?php
/**
 * One PageSpeed Insights result. Rendered on page load from the cache and
 * returned by the run request, so both look the same.
 *
 * Locals: $va_psi_result (array from PageSpeed::run)
 */

defined( 'ABSPATH' ) || exit;

use VireoAnalytics\PageSpeed;
use VireoAnalytics\Vitals;

if ( empty( $va_psi_result ) || ! is_array( $va_psi_result ) ) {
	return;
}

$va_psi_score    = isset( $va_psi_result['score'] ) && is_numeric( $va_psi_result['score'] ) ? (int) $va_psi_result['score'] : null;
$va_psi_metrics  = is_array( $va_psi_result['metrics'] ?? null ) ? $va_psi_result['metrics'] : [];
$va_psi_opps     = is_array( $va_psi_result['opportunities'] ?? null ) ? $va_psi_result['opportunities'] : [];
$va_psi_url      = (string) ( $va_psi_result['url'] ?? '' );
$va_psi_strategy = 'desktop' === ( $va_psi_result['strategy'] ?? '' ) ? 'desktop' : 'mobile';
$va_psi_when     = (int) ( $va_psi_result['fetched'] ?? 0 );
?>
<div class="va-psi-result">
	<div class="va-psi-summary">
		<?php if ( null !== $va_psi_score ) : ?>
			<div class="va-psi-score va-psi-score-<?php echo esc_attr( PageSpeed::score_rating( $va_psi_score ) ); ?>">
				<span class="va-psi-score-num"><?php echo esc_html( number_format_i18n( $va_psi_score ) ); ?></span>
				<span class="va-psi-score-label"><?php esc_html_e( 'Performance score', 'vireo-analytics' ); ?></span>
			</div>
		<?php endif; ?>

		<div class="va-vitals-grid va-psi-metrics">
			<?php foreach ( [ 'lcp', 'cls', 'tbt', 'fcp' ] as $va_metric ) : ?>
				<?php
				if ( ! isset( $va_psi_metrics[ $va_metric ] ) || ! is_numeric( $va_psi_metrics[ $va_metric ] ) ) {
					continue;
				}
				$va_value  = (float) $va_psi_metrics[ $va_metric ];
				$va_rating = PageSpeed::lab_rating( $va_metric, $va_value );
				?>
				<div class="va-vitals-metric va-vitals-<?php echo esc_attr( $va_rating ); ?>">
					<span class="va-vitals-value">
						<?php echo esc_html( PageSpeed::lab_display( $va_metric, $va_value ) ); ?>
						<span class="va-vitals-grade"><?php echo esc_html( Vitals::rating_label( $va_rating ) ); ?></span>
					</span>
					<span class="va-vitals-label"><?php echo esc_html( PageSpeed::lab_label( $va_metric ) ); ?> <abbr class="va-psi-abbr"><?php echo esc_html( strtoupper( $va_metric ) ); ?></abbr></span>
					<span class="va-vitals-note"><?php echo esc_html( PageSpeed::lab_explanation( $va_metric ) ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
	</div>

	<h4 class="va-psi-subtitle"><?php esc_html_e( 'What Google suggests fixing first', 'vireo-analytics' ); ?></h4>
	<?php if ( empty( $va_psi_opps ) ) : ?>
		<p class="va-psi-note"><?php esc_html_e( 'Nothing with a measurable saving. This page passed the checks that matter most.', 'vireo-analytics' ); ?></p>
	<?php else : ?>
		<ol class="va-psi-opps">
			<?php foreach ( $va_psi_opps as $va_opp ) : ?>
				<?php
				$va_saving = PageSpeed::savings_label( (array) $va_opp );
				$va_extra  = (string) ( $va_opp['display'] ?? '' );
				// Google's own text repeats the time saving in other words; keep
				// it only when it adds a size (KiB, MiB) or there is nothing else.
				if ( '' !== $va_saving && false === strpos( $va_extra, 'iB' ) ) {
					$va_extra = '';
				}
				?>
				<li>
					<span class="va-psi-opp-title"><?php echo esc_html( (string) ( $va_opp['title'] ?? '' ) ); ?></span>
					<?php if ( '' !== $va_saving || '' !== $va_extra ) : ?>
						<span class="va-psi-opp-saving">
							<?php echo esc_html( implode( ' · ', array_filter( [ $va_saving, $va_extra ] ) ) ); ?>
						</span>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ol>
	<?php endif; ?>

	<p class="va-psi-meta">
		<?php
		if ( $va_psi_when > 0 ) {
			if ( 'desktop' === $va_psi_strategy ) {
				printf(
					/* translators: %s: human-readable time, e.g. "3 hours" */
					esc_html__( 'Tested as a desktop visitor %s ago.', 'vireo-analytics' ),
					esc_html( human_time_diff( $va_psi_when ) )
				);
			} else {
				printf(
					/* translators: %s: human-readable time, e.g. "3 hours" */
					esc_html__( 'Tested as a phone visitor %s ago.', 'vireo-analytics' ),
					esc_html( human_time_diff( $va_psi_when ) )
				);
			}
		}
		?>
		<button type="button" class="button-link" data-va-psi-run data-va-psi-force><?php esc_html_e( 'Run again', 'vireo-analytics' ); ?></button>
		<?php if ( '' !== $va_psi_url ) : ?>
			<a href="<?php echo esc_url( PageSpeed::report_url( $va_psi_url, $va_psi_strategy ) ); ?>" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Full report on PageSpeed Insights', 'vireo-analytics' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'vireo-analytics' ); ?></span>
			</a>
		<?php endif; ?>
	</p>
</div>
