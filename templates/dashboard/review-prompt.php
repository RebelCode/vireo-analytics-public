<?php
/**
 * One-time request for a wordpress.org review, on the Overview only.
 *
 * Locals: $va_review_days (int, days Vireo has recorded visits on). Rendered
 * only when Feedback::prompt_days() says it is due.
 *
 * Works without JavaScript: the review link and both buttons go through
 * admin-post. dashboard.js swaps the buttons for an in-place AJAX call.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template partial included inside a render method; locals are method-scoped at runtime, not true globals.
use VireoAnalytics\Feedback;
?>
<section class="va-review-prompt" data-va-review aria-labelledby="va-review-prompt-text">
	<p class="va-review-prompt-text" id="va-review-prompt-text">
		<?php
		echo esc_html(
			sprintf(
				/* translators: %s: number of days Vireo has recorded visits on this site. */
				_n(
					'Vireo has been counting here for %s day. If it has been useful, a review on WordPress.org helps other site owners find it.',
					'Vireo has been counting here for %s days. If it has been useful, a review on WordPress.org helps other site owners find it.',
					(int) $va_review_days,
					'vireo-analytics'
				),
				number_format_i18n( (int) $va_review_days )
			)
		);
		?>
	</p>
	<form class="va-review-prompt-actions" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( Feedback::ACTION ); ?>" />
		<?php wp_nonce_field( Feedback::ACTION, '_wpnonce', false ); ?>
		<a class="button button-primary" href="<?php echo esc_url( Feedback::review_link() ); ?>" target="_blank" rel="noopener" data-va-review-leave>
			<?php esc_html_e( 'Leave a review', 'vireo-analytics' ); ?>
			<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'vireo-analytics' ); ?></span>
		</a>
		<button type="submit" class="va-review-prompt-quiet" name="choice" value="later" data-va-review-choice="later"><?php esc_html_e( 'Not now', 'vireo-analytics' ); ?></button>
		<button type="submit" class="va-review-prompt-quiet" name="choice" value="never" data-va-review-choice="never"><?php esc_html_e( "Don't ask again", 'vireo-analytics' ); ?></button>
	</form>
</section>
