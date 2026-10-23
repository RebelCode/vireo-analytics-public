<?php
/**
 * Per-event detail view.
 *
 * Locals (set by Event_Detail::render):
 *   $va_event_name, $va_back_url, $va_page_title
 *   $totals, $daily, $values, $start, $end, $preset
 */

defined( 'ABSPATH' ) || exit;


// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template partial included inside a render method; locals are method-scoped at runtime, not true globals.
use VireoAnalytics\Dashboard;

$presets = [
	'today'     => __( 'Today', 'vireo-analytics' ),
	'yesterday' => __( 'Yesterday', 'vireo-analytics' ),
	'last_7'    => __( 'Last 7', 'vireo-analytics' ),
	'last_14'   => __( 'Last 14', 'vireo-analytics' ),
	'last_30'   => __( 'Last 30', 'vireo-analytics' ),
	'last_90'   => __( 'Last 90', 'vireo-analytics' ),
];

$build_preset_url = static function ( string $key ) use ( $va_event_name ): string {
	return add_query_arg(
		[
			'page'   => 'vireo-analytics',
			'view'   => 'event',
			'name'   => $va_event_name,
			'period' => $key,
			'_vrn'   => \VireoAnalytics\Dashboard::view_nonce(),
		],
		admin_url( 'admin.php' )
	);
};

$total_count  = (int) ( $totals['count'] ?? 0 );
$total_unique = (int) ( $totals['unique_count'] ?? 0 );

$has_data = false;
foreach ( $daily as $row ) {
	if ( (int) ( $row['pageviews'] ?? 0 ) > 0 ) { $has_data = true; break; }
}
?>
<div class="wrap va-wrap">
	<div class="va-root va-event-detail" data-va-start="<?php echo esc_attr( $start ); ?>" data-va-end="<?php echo esc_attr( $end ); ?>">

		<header class="va-page-head">
			<div class="va-post-head-row">
				<a class="va-back-link" href="<?php echo esc_url( $va_back_url ); ?>">
					<?php esc_html_e( '&larr; Overview', 'vireo-analytics' ); ?>
				</a>
			</div>
			<h1 class="va-page-title">
				<?php
				printf(
					/* translators: %s: event name */
					esc_html__( 'Event · %s', 'vireo-analytics' ),
					'<code>' . esc_html( $va_event_name ) . '</code>'
				);
				?>
			</h1>
			<div class="va-page-sub">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: start, 2: end */
						__( '%1$s to %2$s', 'vireo-analytics' ),
						vireo_format_day( (string) $start, 'M j, Y' ),
						vireo_format_day( (string) $end, 'M j, Y' )
					)
				);
				echo \VireoAnalytics\Dashboard::site_subtitle(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside
				?>
			</div>
		</header>
		<hr class="wp-header-end">

		<div class="va-controls">
			<div class="va-preset-bar" role="group" aria-label="<?php esc_attr_e( 'Date range', 'vireo-analytics' ); ?>">
				<?php foreach ( $presets as $key => $label ) :
					$is_active = ( $key === $preset );
				?>
					<a class="va-preset-btn<?php echo $is_active ? ' is-active' : ''; ?>" href="<?php echo esc_url( $build_preset_url( $key ) ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</div>
		</div>

		<div class="va-row va-row-3 va-hero">
			<div class="va-card va-hero-card">
				<h2 class="va-card-title"><?php esc_html_e( 'Total count', 'vireo-analytics' ); ?></h2>
				<div class="va-hero-body">
					<div class="va-hero-num" title="<?php echo esc_attr( number_format_i18n( $total_count ) ); ?>">
						<?php echo esc_html( Dashboard::abbrev( $total_count ) ); ?>
					</div>
				</div>
				<div class="va-hero-sub"><?php esc_html_e( 'times recorded in this range', 'vireo-analytics' ); ?></div>
			</div>
			<div class="va-card va-hero-card">
				<h2 class="va-card-title"><?php esc_html_e( 'Unique visitors', 'vireo-analytics' ); ?></h2>
				<div class="va-hero-body">
					<div class="va-hero-num" title="<?php echo esc_attr( number_format_i18n( $total_unique ) ); ?>">
						<?php echo esc_html( Dashboard::abbrev( $total_unique ) ); ?>
					</div>
				</div>
				<div class="va-hero-sub"><?php esc_html_e( 'daily visitors, added up', 'vireo-analytics' ); ?></div>
			</div>
			<div class="va-card va-hero-card">
				<h2 class="va-card-title"><?php esc_html_e( 'Distinct values', 'vireo-analytics' ); ?></h2>
				<div class="va-hero-body">
					<div class="va-hero-num"><?php echo esc_html( Dashboard::abbrev( (int) ( $va_value_count ?? count( (array) $values ) ) ) ); ?></div>
				</div>
				<div class="va-hero-sub">
					<?php
					echo (int) ( $va_value_count ?? 0 ) > count( (array) $values )
						? esc_html( sprintf(
							/* translators: %d: number of values listed below */
							__( 'in this range, top %d listed below', 'vireo-analytics' ),
							count( (array) $values )
						) )
						: esc_html__( 'in this range', 'vireo-analytics' );
					?>
				</div>
			</div>
		</div>

		<div class="va-row va-row-1">
			<div class="va-card va-chart-card">
				<div class="va-card-head">
					<h2 class="va-card-title"><?php esc_html_e( 'Daily counts', 'vireo-analytics' ); ?></h2>
					<div class="va-chart-legend">
						<span class="va-legend-dot va-legend-pv"></span><?php esc_html_e( 'Count', 'vireo-analytics' ); ?>
						<span class="va-legend-dot va-legend-vis"></span><?php esc_html_e( 'Unique', 'vireo-analytics' ); ?>
					</div>
				</div>
				<div class="va-chart-host" data-va-chart
					data-va-unit="events"
					data-va-series="<?php echo esc_attr( wp_json_encode( $daily ) ); ?>">
					<?php if ( ! $has_data ) : ?>
						<div class="va-empty">
							<div class="va-empty-title"><?php esc_html_e( 'Nothing recorded in this range', 'vireo-analytics' ); ?></div>
							<div class="va-empty-sub"><?php esc_html_e( 'Event counts for the selected range will plot here as they are recorded.', 'vireo-analytics' ); ?></div>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<div class="va-card va-board">
			<div class="va-card-head">
				<h2 class="va-card-title"><?php esc_html_e( 'Top values', 'vireo-analytics' ); ?></h2>
				<div class="va-event-head-meta">
					<span class="va-event-col-head"><?php esc_html_e( 'Count', 'vireo-analytics' ); ?></span>
					<span class="va-event-col-head"><?php esc_html_e( 'Unique', 'vireo-analytics' ); ?></span>
				</div>
			</div>
			<?php if ( empty( $values ) ) : ?>
				<div class="va-empty va-empty-inline">
					<div class="va-empty-title"><?php esc_html_e( 'No values recorded', 'vireo-analytics' ); ?></div>
					<div class="va-empty-sub"><?php esc_html_e( 'Send a value with this event to see a breakdown here.', 'vireo-analytics' ); ?></div>
				</div>
			<?php else : ?>
				<ul class="va-board-list va-event-list">
					<?php foreach ( $values as $row ) :
						$value  = (string) $row['value'];
						$count  = (int) $row['count'];
						$unique = (int) $row['unique_count'];
						$label  = '' !== $value ? esc_html( $value ) : '<em>' . esc_html__( '(no value)', 'vireo-analytics' ) . '</em>';
					?>
						<li class="va-board-row va-event-row">
							<span class="va-board-lead">
								<span class="va-board-icon va-event-icon" aria-hidden="true">&bull;</span>
								<span class="va-board-label">
									<span class="va-board-label-main"><?php echo wp_kses( $label, [ 'em' => [] ] ); ?></span>
								</span>
							</span>
							<span class="va-event-tail">
								<span class="va-event-count" title="<?php echo esc_attr( number_format_i18n( $count ) ); ?>"><?php echo esc_html( Dashboard::abbrev( $count ) ); ?></span>
								<span class="va-event-unique" title="<?php echo esc_attr( number_format_i18n( $unique ) ); ?>"><?php echo esc_html( Dashboard::abbrev( $unique ) ); ?></span>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>

	</div>
</div>
