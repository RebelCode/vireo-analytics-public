<?php
/**
 * Technology — browsers, operating systems and device types.
 *
 * These were three separate cards taking a full row of the overview. They are the
 * same shape, drawn from the same table, and they are the cards a site owner looks
 * at least often. One card, three tabs, and the row goes back to the work.
 *
 * Locals: $devices (keys: browsers, os, devices) from Dashboard::get_devices().
 */

defined( 'ABSPATH' ) || exit;


// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template partial included inside a render method; locals are method-scoped at runtime, not true globals.
use VireoAnalytics\Dashboard;

$panels = [
	'devices'  => [ 'title' => __( 'Device types', 'vireo-analytics' ),      'rows' => $devices['devices']  ?? [], 'icon' => 'device' ],
	'browsers' => [ 'title' => __( 'Browsers', 'vireo-analytics' ),          'rows' => $devices['browsers'] ?? [], 'icon' => 'browser' ],
	'os'       => [ 'title' => __( 'Operating systems', 'vireo-analytics' ), 'rows' => $devices['os']       ?? [], 'icon' => 'os' ],
];

$va_tech_blocked = isset( $va_unavailable['devices'] ) ? (string) $va_unavailable['devices'] : '';
$va_tech_filters = isset( $filters_obj ) && $filters_obj instanceof \VireoAnalytics\Filters ? $filters_obj : null;

// The filters on cannot be answered from the device table: say so and stop.
if ( '' !== $va_tech_blocked ) {
	?>
	<div class="va-card va-tech-card">
		<div class="va-card-head">
			<h2 class="va-card-title"><?php esc_html_e( 'Technology', 'vireo-analytics' ); ?></h2>
		</div>
		<?php $va_unavailable_card( $va_tech_blocked ); ?>
	</div>
	<?php
	return;
}

$va_has_any = false;
foreach ( $panels as $p ) {
	if ( ! empty( $p['rows'] ) ) {
		$va_has_any = true;
		break;
	}
}
if ( ! $va_has_any ) {
	return;
}

$va_first = 'devices';
?>
<div class="va-card va-tech-card" data-va-tech>
	<div class="va-card-head">
		<h2 class="va-card-title"><?php esc_html_e( 'Technology', 'vireo-analytics' ); ?></h2>
		<div class="va-view-toggle" role="tablist" aria-label="<?php esc_attr_e( 'Technology breakdown', 'vireo-analytics' ); ?>">
			<?php foreach ( $panels as $key => $panel ) : ?>
				<button
					type="button"
					role="tab"
					id="va-tech-tab-<?php echo esc_attr( $key ); ?>"
					class="va-view-btn<?php echo $key === $va_first ? ' is-active' : ''; ?>"
					aria-selected="<?php echo $key === $va_first ? 'true' : 'false'; ?>"
					aria-controls="va-tech-panel-<?php echo esc_attr( $key ); ?>"
					tabindex="<?php echo $key === $va_first ? '0' : '-1'; ?>"
					data-va-tech-tab="<?php echo esc_attr( $key ); ?>"
				><?php echo esc_html( $panel['title'] ); ?></button>
			<?php endforeach; ?>
		</div>
	</div>

	<?php foreach ( $panels as $key => $panel ) : ?>
		<div
			class="va-tech-panel"
			role="tabpanel"
			id="va-tech-panel-<?php echo esc_attr( $key ); ?>"
			aria-labelledby="va-tech-tab-<?php echo esc_attr( $key ); ?>"
			data-va-tech-panel="<?php echo esc_attr( $key ); ?>"
			<?php echo $key === $va_first ? '' : 'hidden'; ?>
		>
			<?php if ( empty( $panel['rows'] ) ) : ?>
				<div class="va-empty va-empty-inline">
					<div class="va-empty-sub"><?php esc_html_e( 'No data for this period.', 'vireo-analytics' ); ?></div>
				</div>
			<?php else : ?>
				<div class="va-bars">
					<?php foreach ( $panel['rows'] as $r ) :
						$label = (string) $r['label'];
						$pct   = (float) ( $r['pct'] ?? 0 );
						$v     = (int) ( $r['visitors'] ?? 0 );
						$chip  = Dashboard::delta_chip_pp( isset( $r['pp_delta'] ) ? (float) $r['pp_delta'] : null, $r['delta'] ?? null );

						switch ( $panel['icon'] ) {
							case 'browser': $icon = Dashboard::browser_icon( $label ); break;
							case 'os':      $icon = Dashboard::os_icon( $label );      break;
							default:        $icon = Dashboard::device_icon( $label );
						}

						// Device type is the one of these that is a filter. Browsers and
						// systems have no rollup of their own to filter the page by.
						$va_filter_btn = '';
						if ( 'device' === $panel['icon'] && isset( $va_row_filter ) && ( null === $va_tech_filters || ! $va_tech_filters->is_active() ) ) {
							$va_type = strtolower( (string) ( $r['type'] ?? '' ) );
							if ( in_array( $va_type, \VireoAnalytics\Filters::DEVICES, true ) ) {
								/* translators: %s: a device type, such as Mobile */
								$va_filter_btn = $va_row_filter( [ 'device' => $va_type ], sprintf( __( 'Filter by %s', 'vireo-analytics' ), $label ) );
							}
						}
					?>
						<div class="va-bar-row" title="<?php echo esc_attr( number_format_i18n( $v ) . ' ' . _n( 'visitor', 'visitors', $v, 'vireo-analytics' ) ); ?>">
							<span class="va-bar-icon" aria-hidden="true"><?php
								// Inline SVG returned by Dashboard::*_icon() — server-generated
								// from a hardcoded set of strings, no user data.
								echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							?></span>
							<span class="va-bar-body">
								<span class="va-bar-label">
									<?php echo esc_html( $label ); ?>
									<?php if ( '' !== $chip ) : ?><?php echo wp_kses_post( $chip ); ?><?php endif; ?>
								</span>
								<span class="va-bar-track">
									<span class="va-bar-fill" style="width: <?php echo esc_attr( (string) $pct ); ?>%"></span>
								</span>
							</span>
							<span class="va-bar-value"><?php echo esc_html( number_format_i18n( $pct, 1 ) ); ?>%</span>
							<?php echo $va_filter_btn; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where built ?>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>
</div>
