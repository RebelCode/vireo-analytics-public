<?php
/**
 * Onboarding banner shown on the Overview page on first run.
 *
 * Expected in scope:
 *   $ctx  array  { koko: bool, jetpack: bool, koko_summary: ?array,
 *                  sources: array<int, {slug,name,url}> }
 */

defined( 'ABSPATH' ) || exit;


// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template partial included inside a render method; locals are method-scoped at runtime, not true globals.

$sources = isset( $ctx['sources'] ) && is_array( $ctx['sources'] ) ? $ctx['sources'] : [];
if ( empty( $sources ) ) {
	return;
}

$koko_summary = $ctx['koko_summary'] ?? null;
$koko_days    = is_array( $koko_summary ) ? (int) ( $koko_summary['days'] ?? 0 ) : 0;

$names     = wp_list_pluck( $sources, 'name' );
$name_list = wp_sprintf( '%l', $names );
?>
<div class="va-card va-onboarding" data-va-onboarding>
	<button type="button" class="va-onboarding-dismiss" aria-label="<?php esc_attr_e( 'Dismiss', 'vireo-analytics' ); ?>" data-va-onboarding-dismiss>&times;</button>

	<h2 class="va-onboarding-title"><?php esc_html_e( 'Welcome to Vireo Analytics.', 'vireo-analytics' ); ?></h2>

	<p class="va-onboarding-body">
		<?php
		if ( $ctx['koko'] && $koko_days > 0 && 1 === count( $sources ) ) {
			printf(
				esc_html(
					/* translators: %d: number of days of Koko Analytics data available to import. */
					_n(
						'We found Koko Analytics here with %d day of data. Bring it across in one click, rather than starting at zero.',
						'We found Koko Analytics here with %d days of data. Bring it across in one click, rather than starting at zero.',
						$koko_days,
						'vireo-analytics'
					)
				),
				(int) $koko_days
			);
		} else {
			printf(
				/* translators: %s: comma-separated list of analytics plugins found on the site. */
				esc_html__( 'We found %s on this site. Import that history so your numbers do not start at zero. Your existing data is read only and is never changed.', 'vireo-analytics' ),
				esc_html( $name_list )
			);
		}
		?>
	</p>

	<div class="va-onboarding-actions">
		<?php foreach ( $sources as $va_index => $va_source ) : ?>
			<a class="button <?php echo 0 === $va_index ? 'button-primary' : ''; ?>" href="<?php echo esc_url( $va_source['url'] ); ?>">
				<?php
				printf(
					/* translators: %s: name of the analytics plugin being imported from. */
					esc_html__( 'Import from %s', 'vireo-analytics' ),
					esc_html( $va_source['name'] )
				);
				?>
			</a>
		<?php endforeach; ?>
		<?php // Not `button button-link`: core's `.button` paints a bordered box and
		// `.button-link` tries to undo it, and the two fight. The result was a
		// blue-bordered box with underlined link text sitting next to the real
		// button. Own class, own styles, nothing to argue with. ?>
		<button type="button" class="va-onboarding-skip" data-va-onboarding-dismiss><?php esc_html_e( 'Start fresh', 'vireo-analytics' ); ?></button>
	</div>
</div>
