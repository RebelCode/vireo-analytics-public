<?php
/**
 * The lede. What matters, before any number.
 *
 * This used to render a single grey sentence between the filters and the hero
 * row, while the fuller version of the same thought sat inside the "What
 * changed" card far below the fold. Both are generated from the same range, so
 * the richer one leads here and the card no longer repeats it.
 *
 * Locals: $insight (string), $drivers (array), $start, $end (Y-m-d), $compare (bool).
 */

defined( 'ABSPATH' ) || exit;


// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template partial included inside a render method; locals are method-scoped at runtime.

// Drivers only produces a headline when there is a comparison to draw on. With
// no comparison, or on a brand new install, the one-line insight is all there
// is and it still deserves the top of the page.
[ $va_head, $va_detail ] = \VireoAnalytics\Insights::lede(
	(string) ( $insight ?? '' ),
	is_array( $drivers ?? null ) ? $drivers : []
);

if ( '' === $va_head ) {
	return;
}

// Tell the drivers card its headline has already been said up here, so the two
// don't print the same sentence twice on one screen.
$va_insight_promoted = true;

// The dates are already printed under the page title, by a helper that resolves
// them in the site's timezone. Repeating them here produced a second, different
// range on the same screen, so this line says only the thing the header doesn't:
// what the numbers are measured against.
// With nothing recorded yet, the line is a status note, not a finding: no
// comparison caption over it, and none of the headline size.
$va_lede_has_data = (int) ( $hero['pageviews'] ?? 0 ) > 0 && (int) ( $hero['prior']['pageviews'] ?? 0 ) > 0;
// A range that ends today is compared with the same share of the period before
// it, not the whole of it; say so, or a morning's numbers look like a collapse
// next to a chart that draws whole days.
$va_partial       = \VireoAnalytics\Dashboard::compare_weight( (string) $start, (string) $end ) < 1.0;
$va_year          = 'year' === \VireoAnalytics\Dashboard::compare_mode();
if ( empty( $compare ) || ! $va_lede_has_data ) {
	$va_range = '';
} elseif ( $va_year ) {
	$va_range = $va_partial
		? __( 'Compared with the same period last year, up to the same time of day.', 'vireo-analytics' )
		: __( 'Compared with the same period last year.', 'vireo-analytics' );
} else {
	$va_range = $va_partial
		? __( 'Compared with the period before, up to the same time of day.', 'vireo-analytics' )
		: __( 'Compared with the period before.', 'vireo-analytics' );
}
?>
<div class="va-card va-lede<?php echo (int) ( $hero['pageviews'] ?? 0 ) > 0 ? '' : ' va-lede-quiet'; ?>">
	<h2 class="va-lede-head"><?php echo esc_html( $va_head ); ?></h2>
	<?php if ( '' !== $va_detail ) : ?>
		<p class="va-lede-detail"><?php echo esc_html( $va_detail ); ?></p>
	<?php endif; ?>
	<?php // The basis sits under the finding as a plain note, not as an eyebrow over it. ?>
	<?php if ( '' !== $va_range ) : ?>
		<p class="va-lede-range"><?php echo esc_html( $va_range ); ?></p>
	<?php endif; ?>
</div>
