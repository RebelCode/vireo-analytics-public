<?php
/**
 * AI visibility scan: what could be stopping AI crawlers reading the site.
 *
 * Locals: $va_scan (array from AI_Scan::last()), and from the AI assistants
 * card above it $va_ai_crawled and $va_ai_referred (ints, may be unset).
 *
 * The scan runs when an administrator presses the button and stores its last
 * result; nothing here fetches anything.
 */

defined( 'ABSPATH' ) || exit;

use VireoAnalytics\AI_Scan;
use VireoAnalytics\AI_Scan_Checks;

$va_scan        = isset( $va_scan ) && is_array( $va_scan ) ? $va_scan : [];
$va_scan_checks = isset( $va_scan['checks'] ) && is_array( $va_scan['checks'] ) ? $va_scan['checks'] : [];
$va_scan_at     = (int) ( $va_scan['scanned_at'] ?? 0 );
$va_scan_url    = (string) ( $va_scan['url'] ?? home_url( '/' ) );
$va_scan_can    = current_user_can( 'manage_options' );
$va_scan_seen   = ( isset( $va_ai_crawled ) ? (int) $va_ai_crawled : 0 ) > 0 || ( isset( $va_ai_referred ) ? (int) $va_ai_referred : 0 ) > 0;

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice key, whitelisted below
$va_scan_notice = isset( $_GET['va_scan'] ) ? sanitize_key( wp_unslash( (string) $_GET['va_scan'] ) ) : '';

$va_scan_status_labels = [
	'fail' => __( 'Fail', 'vireo-analytics' ),
	'warn' => __( 'Warning', 'vireo-analytics' ),
	'pass' => __( 'Pass', 'vireo-analytics' ),
	'skip' => __( 'Not run', 'vireo-analytics' ),
];
?>
<div class="va-card va-ai-scan" id="va-ai-scan">
	<h2 class="va-card-title"><?php esc_html_e( 'AI visibility scan', 'vireo-analytics' ); ?></h2>

	<p class="va-ai-scan-intro">
		<?php
		echo esc_html(
			$va_scan_seen
				? __( 'The card above shows what AI took from your site and what it sent back. This checks what could be getting in the way.', 'vireo-analytics' )
				: __( 'Checks what could stop AI crawlers and assistants from reading and citing your site.', 'vireo-analytics' )
		);
		?>
	</p>

	<?php if ( 'invalid' === $va_scan_notice ) : ?>
		<div class="notice notice-error inline"><p><?php esc_html_e( 'Enter a path like /about/ or a full address on this site.', 'vireo-analytics' ); ?></p></div>
	<?php elseif ( 'busy' === $va_scan_notice ) : ?>
		<div class="notice notice-warning inline"><p><?php esc_html_e( 'A scan is already running. Wait a minute and try again.', 'vireo-analytics' ); ?></p></div>
	<?php endif; ?>

	<?php if ( $va_scan_can ) : ?>
		<form class="va-ai-scan-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( AI_Scan::ACTION ); ?>" />
			<?php wp_nonce_field( AI_Scan::ACTION ); ?>
			<label for="va-scan-url"><?php esc_html_e( 'Page to scan', 'vireo-analytics' ); ?></label>
			<input type="text" id="va-scan-url" name="va_scan_url" class="regular-text" value="<?php echo esc_attr( $va_scan_url ); ?>" />
			<button type="submit" class="button button-primary"><?php echo esc_html( $va_scan_at > 0 ? __( 'Run the scan again', 'vireo-analytics' ) : __( 'Run the scan', 'vireo-analytics' ) ); ?></button>
		</form>
		<p class="va-ai-scan-note">
			<?php esc_html_e( 'Your server fetches the page, robots.txt, llms.txt and your sitemap from your public address, logged out, the way a crawler does. It also requests the page as GPTBot, ClaudeBot and PerplexityBot. The scan takes up to half a minute, and its requests aren\'t counted in your reports. Hosts that verify real crawlers by IP address may treat these test requests differently from real ones.', 'vireo-analytics' ); ?>
		</p>
	<?php elseif ( 0 === $va_scan_at ) : ?>
		<p class="va-ai-scan-note"><?php esc_html_e( 'An administrator can run the scan from this card.', 'vireo-analytics' ); ?></p>
	<?php endif; ?>

	<?php if ( $va_scan_at > 0 ) : ?>
		<p class="va-ai-scan-when">
			<?php
			printf(
				/* translators: 1: how long ago, such as "3 hours", 2: the scanned address */
				esc_html__( 'Scanned %1$s ago: %2$s', 'vireo-analytics' ),
				esc_html( human_time_diff( $va_scan_at, time() ) ),
				esc_html( $va_scan_url )
			);
			?>
		</p>
	<?php endif; ?>

	<?php if ( ! empty( $va_scan['error'] ) ) : ?>
		<p class="va-ai-scan-error"><?php echo esc_html( (string) $va_scan['error'] ); ?></p>
	<?php endif; ?>
	<?php if ( ! empty( $va_scan['warning'] ) ) : ?>
		<div class="notice notice-warning inline"><p><?php echo esc_html( (string) $va_scan['warning'] ); ?></p></div>
	<?php endif; ?>

	<?php
	if ( empty( $va_scan_checks ) ) {
		echo '</div>';
		return;
	}

	$va_scan_score = (int) ( $va_scan['score'] ?? 0 );
	if ( $va_scan_score >= 80 ) {
		$va_scan_tier = 'good';
	} elseif ( $va_scan_score >= 50 ) {
		$va_scan_tier = 'mid';
	} else {
		$va_scan_tier = 'poor';
	}
	$va_scan_cats = AI_Scan_Checks::categories();
	?>

	<div class="va-ai-scan-summary">
		<div class="va-ai-scan-score va-ai-scan-score-<?php echo esc_attr( $va_scan_tier ); ?>">
			<span class="va-ai-scan-score-num"><?php echo esc_html( (string) $va_scan_score ); ?></span>
			<span class="va-ai-scan-score-label"><?php esc_html_e( 'out of 100', 'vireo-analytics' ); ?></span>
		</div>
		<ul class="va-ai-scan-cats">
			<?php foreach ( $va_scan_cats as $va_scan_key => $va_scan_label ) : ?>
				<?php $va_scan_val = $va_scan['categories'][ $va_scan_key ] ?? null; ?>
				<li>
					<span><?php echo esc_html( $va_scan_label ); ?></span>
					<span class="va-num"><?php echo null === $va_scan_val ? '&ndash;' : esc_html( (int) $va_scan_val . '%' ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>

	<?php
	$va_scan_rank = array_flip( array_keys( $va_scan_status_labels ) );
	foreach ( $va_scan_cats as $va_scan_key => $va_scan_label ) :
		$va_scan_group = array_values(
			array_filter(
				$va_scan_checks,
				static function ( $check ) use ( $va_scan_key ): bool {
					return is_array( $check ) && ( $check['category'] ?? '' ) === $va_scan_key;
				}
			)
		);
		if ( empty( $va_scan_group ) ) {
			continue;
		}
		usort(
			$va_scan_group,
			static function ( array $a, array $b ) use ( $va_scan_rank ): int {
				return ( $va_scan_rank[ $a['status'] ] ?? 3 ) <=> ( $va_scan_rank[ $b['status'] ] ?? 3 );
			}
		);
		?>
		<h3 class="va-ai-scan-heading"><?php echo esc_html( $va_scan_label ); ?></h3>
		<ul class="va-ai-scan-checks">
			<?php foreach ( $va_scan_group as $va_scan_check ) : ?>
				<?php $va_scan_state = isset( $va_scan_status_labels[ $va_scan_check['status'] ] ) ? $va_scan_check['status'] : 'warn'; ?>
				<li class="va-ai-scan-check va-ai-scan-check-<?php echo esc_attr( $va_scan_state ); ?>">
					<span class="va-ai-scan-dot" aria-hidden="true"></span>
					<div class="va-ai-scan-body">
						<span class="va-ai-scan-title">
							<?php echo esc_html( (string) $va_scan_check['title'] ); ?>
							<span class="va-ai-scan-status"><?php echo esc_html( $va_scan_status_labels[ $va_scan_state ] ); ?></span>
						</span>
						<span class="va-ai-scan-text"><?php echo esc_html( (string) $va_scan_check['summary'] ); ?></span>
						<?php if ( '' !== (string) ( $va_scan_check['fix'] ?? '' ) ) : ?>
							<span class="va-ai-scan-fix"><?php echo esc_html( (string) $va_scan_check['fix'] ); ?></span>
						<?php endif; ?>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endforeach; ?>
</div>
