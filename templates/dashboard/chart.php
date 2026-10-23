<?php
/**
 * Row 2 — Main daily chart. The heavy lifting (bars, axes, tooltip) happens in
 * dashboard.js via VaChart; this template just emits a host node with the
 * data serialised as JSON.
 *
 * Locals: $chart (array of {date,pageviews,visitors}), $chart_prev (same shape
 * or []), $compare (bool).
 */

defined( 'ABSPATH' ) || exit;


// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template partial included inside a render method; locals are method-scoped at runtime, not true globals.
use VireoAnalytics\Dashboard;

$has_data = false;
foreach ( $chart as $row ) {
	if ( (int) ( $row['pageviews'] ?? 0 ) > 0 ) {
		$has_data = true;
		break;
	}
}

// Metric toggle. Persisted via URL (with the view nonce) so links retain the
// chosen view; honored only on a trusted request.
$metric_allowed = [ 'pv', 'vis', 'both' ];
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified in Dashboard::request_is_trusted()
$metric         = ( Dashboard::request_is_trusted() && isset( $_GET['chart_metric'] ) ) ? sanitize_key( (string) $_GET['chart_metric'] ) : 'both';
if ( ! in_array( $metric, $metric_allowed, true ) ) {
	$metric = 'both';
}

$metric_labels = [
	'pv'   => __( 'Pageviews', 'vireo-analytics' ),
	'vis'  => __( 'Visitors', 'vireo-analytics' ),
	'both' => __( 'Both', 'vireo-analytics' ),
];

$build_metric_url = static function ( string $m ): string {
	$args = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- building a URL, not mutating state.
	$args = is_array( $args ) ? array_map( 'sanitize_text_field', wp_unslash( $args ) ) : [];
	$args['page']         = 'vireo-analytics';
	$args['chart_metric'] = $m;
	$args['_vrn']         = Dashboard::view_nonce();
	return add_query_arg( $args, admin_url( 'admin.php' ) );
};

$chart_mode   = isset( $va_chart_mode ) ? (string) $va_chart_mode : 'day';
$hour_allowed = ! empty( $va_hour_allowed );

// A filter combination no rollup can answer leaves the chart blank, with the
// reason, rather than drawing the whole site under a filter chip.
$chart_unavailable = isset( $va_unavailable['totals'] ) ? (string) $va_unavailable['totals'] : '';
$prior_legend      = 'year' === (string) ( $hero['compare_mode'] ?? 'prior' )
	? __( 'Same period last year', 'vireo-analytics' )
	: __( 'Prior period', 'vireo-analytics' );

$build_mode_url = static function ( string $mode ): string {
	$args = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- building a URL only.
	$args = is_array( $args ) ? array_map( 'sanitize_text_field', wp_unslash( $args ) ) : [];
	$args['page'] = 'vireo-analytics';
	if ( 'hour' === $mode ) {
		$args['chart_mode'] = 'hour';
	} else {
		unset( $args['chart_mode'] );
	}
	$args['_vrn'] = Dashboard::view_nonce();
	return add_query_arg( $args, admin_url( 'admin.php' ) );
};
?>
<div class="va-card va-chart-card">
	<div class="va-card-head">
		<h2 class="va-card-title"><?php echo 'hour' === $chart_mode ? esc_html__( 'Hourly traffic', 'vireo-analytics' ) : esc_html__( 'Daily traffic', 'vireo-analytics' ); ?></h2>
		<div class="va-chart-head-right">
			<?php
			// Day/Hour only appears when there's a choice. A greyed-out Hour that
			// explained itself in a hover title was invisible on phones and to
			// keyboards; picking Today or Yesterday brings the switch back.
			?>
			<?php if ( $hour_allowed ) : ?>
			<div class="va-chart-mode" role="group" aria-label="<?php esc_attr_e( 'Chart granularity', 'vireo-analytics' ); ?>">
				<a class="va-chart-mode-btn<?php echo 'day' === $chart_mode ? ' is-active' : ''; ?>"
				   href="<?php echo esc_url( $build_mode_url( 'day' ) ); ?>"
				   aria-pressed="<?php echo 'day' === $chart_mode ? 'true' : 'false'; ?>"><?php esc_html_e( 'Day', 'vireo-analytics' ); ?></a>
				<a class="va-chart-mode-btn<?php echo 'hour' === $chart_mode ? ' is-active' : ''; ?>"
				   href="<?php echo esc_url( $build_mode_url( 'hour' ) ); ?>"
				   aria-pressed="<?php echo 'hour' === $chart_mode ? 'true' : 'false'; ?>"><?php esc_html_e( 'Hour', 'vireo-analytics' ); ?></a>
			</div>
			<?php endif; ?>
			<div class="va-chart-metric" role="group" aria-label="<?php esc_attr_e( 'Chart metric', 'vireo-analytics' ); ?>">
				<?php foreach ( $metric_labels as $k => $label ) :
					$is_active = ( $k === $metric );
				?>
					<a class="va-chart-metric-btn<?php echo $is_active ? ' is-active' : ''; ?>"
					   href="<?php echo esc_url( $build_metric_url( $k ) ); ?>"
					   data-va-metric="<?php echo esc_attr( $k ); ?>"
					   aria-pressed="<?php echo $is_active ? 'true' : 'false'; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</div>
			<div class="va-chart-legend">
				<span class="va-legend-dot va-legend-pv"></span><?php esc_html_e( 'Pageviews', 'vireo-analytics' ); ?>
				<span class="va-legend-dot va-legend-vis"></span><?php esc_html_e( 'Visitors', 'vireo-analytics' ); ?>
				<?php if ( $compare && ! empty( $chart_prev ) ) : ?>
					<span class="va-legend-dot va-legend-prior"></span><?php
					// The line is whole prior days even when the range ends today,
					// which the headline above compares only up to this hour.
					echo \VireoAnalytics\Dashboard::compare_weight( (string) $start, (string) $end ) < 1.0 && 'day' === $chart_mode
						/* translators: %s: the compared period, such as "Prior period" */
						? esc_html( sprintf( __( '%s, whole days', 'vireo-analytics' ), $prior_legend ) )
						: esc_html( $prior_legend );
					?>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<?php if ( '' !== $chart_unavailable ) : ?>
	<div class="va-chart-host">
		<?php $va_unavailable_card( $chart_unavailable ); ?>
	</div>
	<?php else : ?>
	<div class="va-chart-host" data-va-chart
		data-va-annotations="<?php echo esc_attr( (string) wp_json_encode( isset( $va_annotation_map ) ? $va_annotation_map : [] ) ); ?>"
		data-va-series="<?php echo esc_attr( wp_json_encode( $chart ) ); ?>"
		data-va-metric="<?php echo esc_attr( $metric ); ?>"
		data-va-mode="<?php echo esc_attr( $chart_mode ); ?>"
		data-va-prior-label="<?php echo esc_attr( $prior_legend ); ?>"
		<?php if ( $compare && ! empty( $chart_prev ) ) : ?>
			data-va-series-prev="<?php echo esc_attr( wp_json_encode( $chart_prev ) ); ?>"
		<?php endif; ?>>
		<?php if ( ! $has_data ) : ?>
			<div class="va-empty">
				<div class="va-empty-art">
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="48" height="48" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
						<path d="M4 52h56" opacity="0.55"/>
						<path d="M12 42v10M22 36v16M32 28v24M42 32v20M52 22v30" opacity="0.6"/>
					</svg>
				</div>
				<div class="va-empty-title"><?php esc_html_e( 'No traffic yet', 'vireo-analytics' ); ?></div>
				<div class="va-empty-sub"><?php esc_html_e( 'Pageviews for the selected range will plot here as they are recorded.', 'vireo-analytics' ); ?></div>
			</div>
		<?php endif; ?>
	</div>
	<?php endif; ?>
</div>
