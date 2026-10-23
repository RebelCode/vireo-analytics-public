<?php
/**
 * Leaderboard — top countries. Renders as a heatmap by default with a
 * toggle to a precise-numbers table. The map is a vendored SVG coloured
 * by visitor quintile client-side.
 *
 * Locals: $countries     (array of rows from Dashboard::get_countries, top ten),
 *         $countries_map (every country in the range, for shading the map).
 */

defined( 'ABSPATH' ) || exit;


// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template partial included inside a render method; locals are method-scoped at runtime, not true globals.
use VireoAnalytics\Dashboard;
use VireoAnalytics\Geo_Lookup;
use VireoAnalytics\Settings;

// Without the MMDB every visitor resolves to XX, which matches no country on the
// map. Left alone that paints an empty world and reads as a broken card, so drop
// the unresolved rows and say what's actually wrong.
$geo_rows = [];
foreach ( (array) $countries as $row ) {
	if ( 'XX' !== strtoupper( (string) $row['code'] ) ) {
		$geo_rows[] = $row;
	}
}

$geo_missing = class_exists( Geo_Lookup::class ) && ! Geo_Lookup::resolves_countries();

$map_rows = [];
foreach ( (array) ( $countries_map ?? $geo_rows ) as $row ) {
	if ( 'XX' === strtoupper( (string) $row['code'] ) ) {
		continue;
	}
	$map_rows[] = [
		'code'      => strtoupper( (string) $row['code'] ),
		'name'      => (string) $row['name'],
		'visitors'  => (int) $row['visitors'],
		'pageviews' => (int) $row['pageviews'],
		'rank'      => count( $map_rows ) + 1,
	];
}
$map_src = VIREO_URL . 'assets/svg/world-map.svg';

$va_geo_blocked = isset( $va_unavailable['countries'] ) ? (string) $va_unavailable['countries'] : '';
$va_geo_filters = isset( $filters_obj ) && $filters_obj instanceof \VireoAnalytics\Filters ? $filters_obj : null;
?>
<div class="va-card va-board va-countries-card" data-va-map-toggle>
	<div class="va-card-head">
		<h2 class="va-card-title"><?php esc_html_e( 'Top countries', 'vireo-analytics' ); ?></h2>
		<?php // No rows, no views to switch between: the card shows its setup step instead. ?>
		<?php if ( ! empty( $geo_rows ) && '' === $va_geo_blocked ) : ?>
		<div class="va-view-toggle" role="group" aria-label="<?php esc_attr_e( 'View', 'vireo-analytics' ); ?>">
			<button type="button" class="va-view-btn is-active" data-va-view="map" aria-pressed="true"><?php esc_html_e( 'Map', 'vireo-analytics' ); ?></button>
			<button type="button" class="va-view-btn" data-va-view="table" aria-pressed="false"><?php esc_html_e( 'Table', 'vireo-analytics' ); ?></button>
		</div>
		<?php endif; ?>
	</div>

	<?php if ( '' !== $va_geo_blocked ) : ?>
		<?php $va_unavailable_card( $va_geo_blocked ); ?>
	<?php elseif ( empty( $geo_rows ) && $geo_missing ) : ?>
		<div class="va-empty va-empty-inline">
			<div class="va-empty-title"><?php esc_html_e( 'Countries are not being resolved', 'vireo-analytics' ); ?></div>
			<div class="va-empty-sub">
				<?php esc_html_e( 'The geo-location database is not installed, so every visitor is recorded as unknown. Your traffic is still being counted.', 'vireo-analytics' ); ?>
			</div>
			<?php // The one next step, as a button rather than a link at the end of a sentence. ?>
			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<p class="va-empty-action">
					<a class="button" href="<?php echo esc_url( Settings::url( 'tracking' ) . '#va-geo' ); ?>"><?php esc_html_e( 'Set it up', 'vireo-analytics' ); ?></a>
				</p>
			<?php endif; ?>
		</div>
	<?php elseif ( empty( $geo_rows ) ) : ?>
		<div class="va-empty va-empty-inline">
			<div class="va-empty-title"><?php esc_html_e( 'No country data yet', 'vireo-analytics' ); ?></div>
			<div class="va-empty-sub"><?php esc_html_e( 'Geo-located pageviews will appear here once visitors arrive.', 'vireo-analytics' ); ?></div>
		</div>
	<?php else : ?>

		<div class="va-world-map"
			data-va-map
			data-va-map-src="<?php echo esc_attr( esc_url( $map_src ) ); ?>"
			data-va-map-data="<?php echo esc_attr( wp_json_encode( $map_rows ) ); ?>"
			aria-label="<?php esc_attr_e( 'World map of visitor distribution', 'vireo-analytics' ); ?>"
			role="img">
		</div>

		<?php
		// Part of the range has no country data (imported history, or a lookup
		// that was not resolving): say so once, rather than ranking "Unknown".
		$va_cov = isset( $va_country_coverage ) && is_array( $va_country_coverage ) ? $va_country_coverage : null;
		if ( null !== $va_cov && null !== $va_cov['share'] && $va_cov['share'] < 99.5 ) :
		?>
			<p class="va-board-note">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: percentage of visitors whose country is known */
						__( 'Country known for %s%% of visitors.', 'vireo-analytics' ),
						number_format_i18n( (float) $va_cov['share'], $va_cov['share'] < 10 ? 1 : 0 )
					)
				);
				?>
			</p>
		<?php endif; ?>
		<?php // The list stays under the map: the map shows the spread, the list the numbers, and together they fill the column. ?>
		<ul class="va-board-list va-countries-table" data-va-map-table>
			<?php foreach ( $geo_rows as $row ) :
				$code  = strtoupper( (string) $row['code'] );
				$codeL = strtolower( $code );
				$name  = (string) $row['name'];
				$count = (int) $row['visitors'];
				$chip  = Dashboard::delta_chip( $row['delta'] ?? null, $row['prior'] ?? null );
				// A country stands alone as a filter, so the funnel only shows when nothing else is on.
				$va_filter_btn = ( isset( $va_row_filter ) && ( null === $va_geo_filters || ! $va_geo_filters->is_active() ) )
					/* translators: %s: a country name */
					? $va_row_filter( [ 'country' => $code ], sprintf( __( 'Filter by %s', 'vireo-analytics' ), $name ) )
					: '';
			?>
				<li class="va-board-row">
					<span class="va-board-lead">
						<span class="va-flag-wrap">
							<?php $flag = vireo_country_flag_emoji( $code ); ?>
							<?php if ( '' !== $flag ) : ?>
								<span class="va-flag va-flag-emoji" role="img" aria-label="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $flag ); ?></span>
							<?php else : ?>
								<span class="va-flag-fallback" aria-hidden="true"><?php echo esc_html( $code ); ?></span>
							<?php endif; ?>
						</span>
						<span class="va-board-label">
							<span class="va-board-label-main" title="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $name ); ?></span>
						</span>
					</span>
					<span class="va-board-tail">
						<?php echo $va_filter_btn; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where built ?>
						<?php if ( '' !== $chip ) : ?><?php echo wp_kses_post( $chip ); ?><?php endif; ?>
						<span class="va-board-count" title="<?php echo esc_attr( number_format_i18n( $count ) ); ?>"><?php echo esc_html( Dashboard::abbrev( $count ) ); ?></span>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>

	<?php endif; ?>
</div>
