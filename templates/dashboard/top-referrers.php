<?php
/**
 * Leaderboard — top referrers.
 *
 * Locals: $referrers (array of rows from Dashboard::get_top_referrers).
 *
 * Rows may be either flat {host, pageviews, visitors, delta} or grouped by
 * source type via Dashboard::group_referrers_by_source(). We detect the shape
 * and render accordingly.
 */

defined( 'ABSPATH' ) || exit;


// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template partial included inside a render method; locals are method-scoped at runtime, not true globals.
use VireoAnalytics\Dashboard;

$va_direct = ( isset( $direct ) && is_array( $direct ) ) ? $direct : null;
$groups    = Dashboard::group_referrers_by_source(
	is_array( $referrers ) ? $referrers : [],
	$va_direct,
	( isset( $referrer_totals ) && is_array( $referrer_totals ) ) ? $referrer_totals : null
);

// Direct never has hosts to list, so its section carries the number itself.
$va_direct_delta = null;
if ( null !== $va_direct && isset( $va_direct['prior']['pageviews'] ) ) {
	$va_direct_delta = (int) $va_direct['pageviews'] - (int) $va_direct['prior']['pageviews'];
}

$section_meta = [
	'direct'   => __( 'Direct', 'vireo-analytics' ),
	'search'   => __( 'Search', 'vireo-analytics' ),
	'ai'       => __( 'AI assistants', 'vireo-analytics' ),
	'social'   => __( 'Social', 'vireo-analytics' ),
	'referral' => __( 'Referral', 'vireo-analytics' ),
];

$empty_messages = [
	'direct'   => __( 'Visitors who arrived with no referrer: typed the URL, used a bookmark, or came from an app or email client.', 'vireo-analytics' ),
	'search'   => __( 'No search traffic in this range.', 'vireo-analytics' ),
	'ai'       => __( 'No AI assistant traffic in this range.', 'vireo-analytics' ),
	'social'   => __( 'No social traffic in this range.', 'vireo-analytics' ),
	'referral' => __( 'No referral traffic in this range.', 'vireo-analytics' ),
];

// Direct contributes a number without contributing rows, so a site whose only
// traffic is direct still has something to show.
$any_rows = ( null !== $va_direct && (int) $va_direct['pageviews'] > 0 );
foreach ( $groups as $g ) {
	if ( ! empty( $g['rows'] ) ) {
		$any_rows = true;
		break;
	}
}

$globe_svg = '<svg class="va-globe-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" width="16" height="16" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="8" r="6.25"/><path d="M1.75 8h12.5M8 1.75c2 2 2.5 4 2.5 6.25S10 12.25 8 14.25M8 1.75c-2 2-2.5 4-2.5 6.25S6 12.25 8 14.25"/></svg>';
?>
<?php
$va_refs_blocked = isset( $va_unavailable['referrers'] ) ? (string) $va_unavailable['referrers'] : '';
$va_refs_filters = isset( $filters_obj ) && $filters_obj instanceof \VireoAnalytics\Filters ? $filters_obj : null;
?>
<div class="va-card va-board va-board-referrers">
	<h2 class="va-card-title"><?php esc_html_e( 'Top referrers', 'vireo-analytics' ); ?></h2>
	<?php if ( '' !== $va_refs_blocked ) : ?>
		<?php $va_unavailable_card( $va_refs_blocked ); ?>
	<?php elseif ( ! $any_rows ) : ?>
		<div class="va-empty va-empty-inline">
			<div class="va-empty-title"><?php esc_html_e( 'No referrers yet', 'vireo-analytics' ); ?></div>
			<div class="va-empty-sub"><?php esc_html_e( 'When someone clicks through from another site, they\'ll show up here.', 'vireo-analytics' ); ?></div>
		</div>
	<?php else :
		foreach ( $section_meta as $type => $label ) :
			$rows  = $groups[ $type ]['rows'] ?? [];
			$total = (int) ( $groups[ $type ]['pageviews'] ?? 0 );
			$is_empty = empty( $rows );

			// A bucket with nothing in it and nothing to say about it is just a row
			// reading "Social 0". Direct is the exception: it has no hosts to list by
			// nature, so its number stands on its own.
			if ( $is_empty && 0 === $total && 'direct' !== $type ) {
				continue;
			}
	?>
		<details class="va-ref-section"<?php echo $is_empty ? '' : ' open'; ?>>
			<summary class="va-ref-section-head">
				<span class="va-ref-section-label"><?php echo esc_html( $label ); ?></span>
				<?php
				if ( 'direct' === $type && null !== $va_direct_delta ) {
					$va_direct_chip = Dashboard::delta_chip( $va_direct_delta, (int) ( $va_direct['prior']['pageviews'] ?? 0 ) );
					if ( '' !== $va_direct_chip ) {
						echo wp_kses_post( $va_direct_chip );
					}
				}
				?>
				<span class="va-ref-section-count" title="<?php echo esc_attr( number_format_i18n( $total ) ); ?>"><?php echo esc_html( Dashboard::abbrev( $total ) ); ?></span>
			</summary>
			<?php if ( $is_empty && $total > 0 && 'direct' !== $type ) : ?>
				<div class="va-ref-section-empty"><?php esc_html_e( 'None of these made the top ten. Filter by this source to list them.', 'vireo-analytics' ); ?></div>
			<?php elseif ( $is_empty ) : ?>
				<div class="va-ref-section-empty"><?php echo esc_html( $empty_messages[ $type ] ); ?></div>
			<?php else : ?>
				<ul class="va-board-list">
					<?php foreach ( $rows as $row ) :
						$host      = (string) $row['host'];
						$name      = vireo_referrer_label( $host );
						$sub       = vireo_referrer_subtitle( $host );
						$count     = (int) $row['pageviews'];
						$chip      = Dashboard::delta_chip( $row['delta'] ?? null, $row['prior'] ?? null );
						$is_direct = 'direct' === $type;
						$va_filter_btn = ( ! $is_direct && isset( $va_row_filter ) && ( null === $va_refs_filters || ! $va_refs_filters->has_ref() ) )
							/* translators: %s: a referrer name, such as Google */
							? $va_row_filter( [ 'ref' => $host ], sprintf( __( 'Filter by %s', 'vireo-analytics' ), $name ) )
							: '';
						$drill_url = $is_direct ? '' : add_query_arg(
							[ 'page' => 'vireo-analytics', 'view' => 'referrer', 'url' => $host, '_vrn' => \VireoAnalytics\Dashboard::view_nonce() ] + ( 'custom' === ( $preset ?? '' ) ? [ 'start_date' => $start, 'end_date' => $end ] : [ 'period' => $preset ?? 'last_30' ] ),
							admin_url( 'admin.php' )
						);
					?>
						<li class="va-board-row">
							<span class="va-board-lead">
								<span class="va-board-icon-globe" aria-hidden="true"><?php echo $globe_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hard-coded SVG above. ?></span>
								<span class="va-board-label">
									<span class="va-board-label-main" title="<?php echo esc_attr( $host ); ?>">
										<?php if ( '' !== $drill_url ) : ?>
											<a href="<?php echo esc_url( $drill_url ); ?>"><?php echo esc_html( $name ); ?></a>
										<?php else : ?>
											<?php echo esc_html( $name ); ?>
										<?php endif; ?>
									</span>
									<?php if ( '' !== $sub ) : ?>
										<span class="va-board-label-sub"><?php echo esc_html( $sub ); ?></span>
									<?php endif; ?>
								</span>
							</span>
							<span class="va-board-tail">
								<?php if ( ! empty( $row['suspect'] ) ) : ?>
									<?php // Visits with no engagement at all: one page each, none on a post, from a host that just appeared. ?>
									<span class="va-ref-suspect" title="<?php esc_attr_e( 'Every visit from this site viewed one page and none landed on a post, over a burst of days. That is what a compromised site or a spoofed referrer looks like, not readers. Nothing is removed until you block it.', 'vireo-analytics' ); ?>"><?php esc_html_e( 'suspected junk', 'vireo-analytics' ); ?></span>
									<?php if ( current_user_can( 'manage_options' ) ) : ?>
										<button type="button" class="button-link va-ref-block" data-va-block-referrer="<?php echo esc_attr( $host ); ?>"><?php esc_html_e( 'Block this referrer', 'vireo-analytics' ); ?></button>
									<?php endif; ?>
								<?php endif; ?>
								<?php echo $va_filter_btn; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where built ?>
								<?php if ( '' !== $chip ) : ?><?php echo wp_kses_post( $chip ); ?><?php endif; ?>
								<span class="va-board-count" title="<?php echo esc_attr( number_format_i18n( $count ) ); ?>"><?php echo esc_html( Dashboard::abbrev( $count ) ); ?></span>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</details>
	<?php
		endforeach;
	endif; ?>
</div>
