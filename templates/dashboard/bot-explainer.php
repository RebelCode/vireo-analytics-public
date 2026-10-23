<?php
/**
 * Shows what the bot filter rejected over the current range, and frames why
 * Vireo's totals sit below what a server-log tool reports.
 *
 * Locals: $va_bot_explainer_dismissed (bool), $va_bots (array from
 * Bot_Stats::summary), $va_bot_share (?float).
 */

defined( 'ABSPATH' ) || exit;

$va_bot_total = isset( $va_bots['total'] ) ? (int) $va_bots['total'] : 0;

// Nothing filtered yet on a quiet or freshly installed site. "0 filtered"
// reads as a broken filter, so say nothing until there's something to report.
if ( $va_bot_total < 1 ) {
	return;
}

$va_bot_rows = isset( $va_bots['by_category'] ) && is_array( $va_bots['by_category'] )
	? array_slice( $va_bots['by_category'], 0, 5 )
	: [];
?>
<div class="va-bot-explainer" data-va-bot-explainer>
	<p class="va-bot-explainer-headline">
		<?php
		printf(
			esc_html(
				/* translators: %s: formatted count of filtered requests. */
				_n( '%s request kept out of the numbers above.', '%s requests kept out of the numbers above.', $va_bot_total, 'vireo-analytics' )
			),
			'<strong>' . esc_html( number_format_i18n( $va_bot_total ) ) . '</strong>'
		);

		if ( null !== $va_bot_share ) {
			echo ' ';
			// The share is rounded to one decimal, so a handful of bots against a
			// busy site comes back as 0.0, which reads as none at all.
			if ( $va_bot_share < 0.1 ) {
				// Through printf so the percent sign is escaped the same way as the
				// string below, and translation tools don't read "% o" as a placeholder.
				printf( esc_html__( 'That is under 0.1%% of the requests Vireo saw.', 'vireo-analytics' ) );
			} else {
				printf(
					/* translators: %s: percentage of all requests that were filtered. */
					esc_html__( 'That is %s%% of the requests Vireo saw.', 'vireo-analytics' ),
					esc_html( number_format_i18n( (float) $va_bot_share, 1 ) )
				);
			}
		}
		?>
	</p>

	<?php // One line by default; the breakdown and the why sit behind a disclosure. ?>
	<details class="va-bot-explainer-more">
		<summary><?php esc_html_e( 'What was filtered and why', 'vireo-analytics' ); ?></summary>
	<?php if ( $va_bot_rows ) : ?>
		<ul class="va-bot-explainer-breakdown">
			<?php foreach ( $va_bot_rows as $va_bot_row ) : ?>
				<li>
					<span class="va-bot-cat"><?php echo esc_html( (string) $va_bot_row['label'] ); ?></span>
					<span class="va-bot-hits"><?php echo esc_html( number_format_i18n( (int) $va_bot_row['hits'] ) ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<p class="va-bot-explainer-text">
		<?php esc_html_e( 'None of these reached your visitor or pageview totals. It is also why those totals sit below what a server-log tool reports: log readers count every crawler, monitor and scraper as a visit. Crawlers are counted when they ask WordPress for a page, so on a site with full-page caching the real numbers are higher than these: a cached page is answered before WordPress runs, and nothing inside WordPress can see that request.', 'vireo-analytics' ); ?>
	</p>
	</details>

	<?php if ( empty( $va_bot_explainer_dismissed ) ) : ?>
		<button type="button" class="button-link va-bot-explainer-dismiss" data-va-bot-explainer-dismiss>
			<?php esc_html_e( 'Got it', 'vireo-analytics' ); ?>
		</button>
	<?php endif; ?>
</div>
