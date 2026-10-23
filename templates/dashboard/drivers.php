<?php
/**
 * Drivers of change — the answer to the question the hero row provokes.
 *
 * Locals: $drivers (array from Drivers::get), $compare (bool), $va_pro_active (bool).
 */

defined( 'ABSPATH' ) || exit;


// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template partial included inside a render method; locals are method-scoped at runtime.
use VireoAnalytics\Dashboard;

$va_d       = is_array( $drivers ?? null ) ? $drivers : [];
$va_pages   = $va_d['pages'] ?? [ 'gained' => [], 'lost' => [] ];
$va_sources = $va_d['sources'] ?? [ 'gained' => [], 'lost' => [] ];
$va_health  = $va_d['health'] ?? [];
$va_head    = (string) ( $va_d['headline'] ?? '' );
$va_detail  = (string) ( $va_d['detail'] ?? '' );
$va_mix     = (string) ( $va_d['mix'] ?? '' );

$va_has_pages   = ! empty( $va_pages['gained'] ) || ! empty( $va_pages['lost'] );
$va_has_sources = ! empty( $va_sources['gained'] ) || ! empty( $va_sources['lost'] );

// Nothing moved and nothing is broken: don't render an empty card telling the
// user we have nothing to say.
if ( '' === $va_head && ! $va_has_pages && ! $va_has_sources && empty( $va_health ) ) {
	return;
}

// Five a side by default. Ten in each of four lists turned the card into most
// of a screen; the rest is one click away.
$va_visible = 5;

$va_row = static function ( array $r, string $dir, int $scale = 0, bool $extra = false ): void {
	$delta = (int) $r['delta'];
	$label = (string) $r['label'];
	$url   = (string) ( $r['url'] ?? '' );

	// Gained and lost share one scale. Scaling each column to its own biggest
	// mover draws a full-width bar on both sides, which says the best gain and
	// the worst loss were the same size when one can be twice the other.
	$pct = $scale > 0 ? min( 100, (int) round( ( abs( $delta ) / $scale ) * 100 ) ) : 0;
	?>
	<li class="va-driver-row va-driver-<?php echo esc_attr( $dir ); ?><?php echo $scale > 0 ? ' has-bar' : ''; ?><?php echo $extra ? ' is-extra' : ''; ?>">
		<span class="va-driver-top">
			<span class="va-driver-label" title="<?php echo esc_attr( $label ); ?>">
				<?php if ( '' !== $url ) : ?>
					<a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php else : ?>
					<?php echo esc_html( $label ); ?>
				<?php endif; ?>
			</span>
			<span class="va-driver-delta va-driver-<?php echo esc_attr( $dir ); ?>">
				<?php echo esc_html( ( $delta > 0 ? '+' : '' ) . number_format_i18n( $delta ) ); ?>
			</span>
		</span>
		<?php if ( $scale > 0 ) : ?>
			<span class="va-driver-track">
				<span class="va-driver-fill" style="width:<?php echo esc_attr( (string) max( 2, $pct ) ); ?>%"></span>
			</span>
		<?php endif; ?>
	</li>
	<?php
};

// One labelled group (Pages or Sources) with its own gained/lost columns. Pages
// and sources are never mixed: they measure the same pageviews from two angles,
// so one merged list double-counts and confuses cause with effect.
$va_group = static function ( string $title, array $group, string $more_label ) use ( $va_row, $va_visible ): void {
	$va_scale = 0;
	foreach ( [ 'gained', 'lost' ] as $va_side ) {
		foreach ( (array) ( $group[ $va_side ] ?? [] ) as $va_r ) {
			$va_scale = max( $va_scale, abs( (int) ( $va_r['delta'] ?? 0 ) ) );
		}
	}
	?>
	<?php
	$va_more = max( count( (array) ( $group['gained'] ?? [] ) ), count( (array) ( $group['lost'] ?? [] ) ) ) > $va_visible;
	?>
	<div class="va-drivers-group" data-va-drivers-group>
		<h3 class="va-drivers-group-head"><?php echo esc_html( $title ); ?></h3>
		<div class="va-drivers-cols">
			<div class="va-drivers-col">
				<div class="va-drivers-col-head"><?php esc_html_e( 'Gained', 'vireo-analytics' ); ?></div>
				<?php if ( empty( $group['gained'] ) ) : ?>
					<div class="va-drivers-none"><?php esc_html_e( 'Nothing grew.', 'vireo-analytics' ); ?></div>
				<?php else : ?>
					<ul class="va-driver-list">
						<?php foreach ( array_values( $group['gained'] ) as $i => $r ) { $va_row( $r, 'up', $va_scale, $i >= $va_visible ); } ?>
					</ul>
				<?php endif; ?>
			</div>
			<div class="va-drivers-col">
				<div class="va-drivers-col-head"><?php esc_html_e( 'Lost', 'vireo-analytics' ); ?></div>
				<?php if ( empty( $group['lost'] ) ) : ?>
					<div class="va-drivers-none"><?php esc_html_e( 'Nothing fell.', 'vireo-analytics' ); ?></div>
				<?php else : ?>
					<ul class="va-driver-list">
						<?php foreach ( array_values( $group['lost'] ) as $i => $r ) { $va_row( $r, 'down', $va_scale, $i >= $va_visible ); } ?>
					</ul>
				<?php endif; ?>
			</div>
		</div>
		<?php if ( $va_more ) : ?>
			<button type="button" class="button-link va-drivers-more" data-va-drivers-more aria-expanded="false">
				<?php echo esc_html( $more_label ); ?>
			</button>
		<?php endif; ?>
	</div>
	<?php
};
?>
<div class="va-card va-drivers">
	<h2 class="va-card-title"><?php esc_html_e( 'What changed', 'vireo-analytics' ); ?></h2>

	<?php
	// The lede at the top of the page already says this when it had a headline
	// to say. Repeating it here put the same sentence on screen twice.
	$va_said_above = ! empty( $va_insight_promoted );
	?>
	<?php if ( '' !== $va_head && ! $va_said_above ) : ?>
		<p class="va-drivers-headline">
			<?php echo esc_html( $va_head ); ?>
			<?php if ( '' !== $va_detail ) : ?>
				<span class="va-drivers-detail"><?php echo esc_html( $va_detail ); ?></span>
			<?php endif; ?>
		</p>
	<?php endif; ?>

	<?php
	if ( $va_has_pages ) {
		$va_group( __( 'Pages', 'vireo-analytics' ), $va_pages, __( 'Show all pages', 'vireo-analytics' ) );
	}
	if ( $va_has_sources ) {
		$va_group( __( 'Sources', 'vireo-analytics' ), $va_sources, __( 'Show all sources', 'vireo-analytics' ) );
	}
	?>

	<?php if ( '' !== $va_mix ) : ?>
		<p class="va-drivers-mix"><?php echo esc_html( $va_mix ); ?></p>
	<?php endif; ?>

	<?php
	/**
	 * Pro hooks its narrative in here, underneath the rows it summarises.
	 *
	 * Args: ( array $drivers ).
	 */
	do_action( 'vireo_drivers_after_rows', $va_d );
	?>

	<?php if ( ! empty( $va_health ) ) : ?>
		<ul class="va-health-strip">
			<?php foreach ( $va_health as $h ) : ?>
				<li class="va-health-item va-health-<?php echo esc_attr( (string) $h['level'] ); ?>">
					<span class="va-health-icon" aria-hidden="true">&#9888;</span>
					<span class="va-health-text"><?php echo esc_html( (string) $h['text'] ); ?></span>
					<?php if ( '' !== (string) ( $h['url'] ?? '' ) ) : ?>
						<a class="va-health-link" href="<?php echo esc_url( (string) $h['url'] ); ?>"><?php esc_html_e( 'Fix this', 'vireo-analytics' ); ?></a>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
