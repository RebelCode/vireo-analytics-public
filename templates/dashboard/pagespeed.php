<?php
/**
 * Google's lab test for one page, run on request.
 *
 * Locals: $va_psi (array from PageSpeed::view), $va_psi_title (string),
 * $va_psi_cta_title (string, the heading until a result is shown here)
 *
 * Both strategies are rendered, one hidden, so switching between them shows
 * whatever is already cached without another request. Without an API key the
 * panels hold a link to pagespeed.web.dev instead, and nothing runs here.
 */

defined( 'ABSPATH' ) || exit;

use VireoAnalytics\PageSpeed;

if ( empty( $va_psi ) || ! is_array( $va_psi ) ) {
	return;
}

$va_psi_has_key  = PageSpeed::has_key();
$va_psi_strategy = 'mobile';
$va_psi_labels   = [
	'mobile'  => __( 'Mobile', 'vireo-analytics' ),
	'desktop' => __( 'Desktop', 'vireo-analytics' ),
];
?>
<div class="va-psi" data-va-psi
	data-va-psi-target="<?php echo esc_attr( $va_psi['target'] ); ?>"
	data-va-psi-id="<?php echo esc_attr( (string) (int) $va_psi['id'] ); ?>"
	data-va-psi-nonce="<?php echo esc_attr( wp_create_nonce( PageSpeed::NONCE ) ); ?>">

	<div class="va-psi-head">
		<h3 class="va-psi-title">
			<?php
			// "Tested by Google" and the lab pill only once a result is on
			// screen for the device shown. The script swaps the two when the
			// device changes or a run comes back.
			$va_psi_shown = $va_psi_has_key && ! empty( $va_psi['results'][ $va_psi_strategy ] );
			?>
			<span data-va-psi-heading="cta"<?php echo $va_psi_shown ? ' hidden' : ''; ?>><?php echo esc_html( isset( $va_psi_cta_title ) ? (string) $va_psi_cta_title : $va_psi_title ); ?></span>
			<?php if ( $va_psi_has_key ) : ?>
				<span data-va-psi-heading="done"<?php echo $va_psi_shown ? '' : ' hidden'; ?>>
					<?php echo esc_html( $va_psi_title ); ?>
					<span class="va-speed-source va-speed-source-lab"><?php esc_html_e( 'Lab: one test by Google', 'vireo-analytics' ); ?></span>
				</span>
			<?php endif; ?>
		</h3>
		<?php if ( '' === $va_psi['error'] ) : ?>
			<div class="va-psi-strategy" role="group" aria-label="<?php esc_attr_e( 'Device to test as', 'vireo-analytics' ); ?>">
				<?php foreach ( PageSpeed::STRATEGIES as $va_s ) : ?>
					<button type="button" class="va-psi-strategy-btn" data-va-psi-strategy="<?php echo esc_attr( $va_s ); ?>"
						aria-pressed="<?php echo $va_s === $va_psi_strategy ? 'true' : 'false'; ?>">
						<?php echo esc_html( $va_psi_labels[ $va_s ] ); ?>
					</button>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>

	<?php if ( '' !== $va_psi['error'] ) : ?>
		<p class="va-psi-note"><?php echo esc_html( $va_psi['error'] ); ?></p>
	<?php elseif ( ! $va_psi_has_key ) : ?>
		<?php
		// No key: the button is a link to Google's own site, opened in the
		// browser. The server sends nothing anywhere and stores nothing.
		foreach ( PageSpeed::STRATEGIES as $va_s ) :
			?>
			<div class="va-psi-panel" data-va-psi-panel="<?php echo esc_attr( $va_s ); ?>"<?php echo $va_s === $va_psi_strategy ? '' : ' hidden'; ?>>
				<div class="va-psi-empty">
					<a class="button button-primary" href="<?php echo esc_url( PageSpeed::report_url( $va_psi['url'], $va_s ) ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Test this page on PageSpeed Insights', 'vireo-analytics' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'vireo-analytics' ); ?></span>
					</a>
				</div>
			</div>
		<?php endforeach; ?>
		<p class="va-psi-note va-psi-keynote">
			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<a href="<?php echo esc_url( \VireoAnalytics\Settings::url( 'integrations' ) . '#vireo-psi' ); ?>"><?php esc_html_e( 'Add a free Google API key in Settings to see the results right here instead.', 'vireo-analytics' ); ?></a>
			<?php else : ?>
				<?php esc_html_e( 'An administrator can add a free Google API key in Settings to show the results right here instead.', 'vireo-analytics' ); ?>
			<?php endif; ?>
		</p>
	<?php else : ?>
		<?php foreach ( PageSpeed::STRATEGIES as $va_s ) : ?>
			<div class="va-psi-panel" data-va-psi-panel="<?php echo esc_attr( $va_s ); ?>"<?php echo $va_s === $va_psi_strategy ? '' : ' hidden'; ?>>
				<?php if ( ! empty( $va_psi['results'][ $va_s ] ) ) : ?>
					<?php echo PageSpeed::render_result( $va_psi['results'][ $va_s ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the partial ?>
				<?php else : ?>
					<div class="va-psi-empty">
						<p>
							<?php
							if ( 'desktop' === $va_s ) {
								esc_html_e( 'Google loads this page once on its own servers, as a desktop visitor, and lists what would make it faster. It takes up to a minute.', 'vireo-analytics' );
							} else {
								esc_html_e( 'Google loads this page once on its own servers, as a phone visitor on a slow connection, and lists what would make it faster. It takes up to a minute.', 'vireo-analytics' );
							}
							?>
						</p>
						<button type="button" class="button button-primary" data-va-psi-run><?php esc_html_e( 'Run test', 'vireo-analytics' ); ?></button>
					</div>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
		<p class="va-psi-status" data-va-psi-status role="status" aria-live="polite"></p>
	<?php endif; ?>
</div>
