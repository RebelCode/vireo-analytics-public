<?php
/**
 * Notes pinned to dates in the current range.
 *
 * Locals: $va_annotations      (array from Annotations::for_range)
 *         $va_can_annotate     (bool)
 *         $va_annotation_nonce (string)
 *         $start, $end         (YYYY-MM-DD)
 *
 * Someone who can only read the dashboard sees the notes and no form. The
 * chart tooltip carries the same text, so this list is for adding, removing,
 * and for anyone scanning the range without hovering every bar.
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $va_annotations ) && empty( $va_can_annotate ) ) {
	return;
}
?>
<div class="va-annotations" data-va-annotations-ui
	data-va-nonce="<?php echo esc_attr( (string) $va_annotation_nonce ); ?>">

	<h3 class="va-annotations-title"><?php esc_html_e( 'Notes', 'vireo-analytics' ); ?></h3>

	<ul class="va-annotations-list" data-va-annotations-list>
		<?php foreach ( (array) $va_annotations as $va_note ) : ?>
			<li data-va-annotation="<?php echo esc_attr( (string) $va_note['id'] ); ?>">
				<span class="va-annotation-date"><?php echo esc_html( vireo_format_day( (string) $va_note['date'] ) ); ?></span>
				<span class="va-annotation-label"><?php echo esc_html( $va_note['label'] ); ?></span>
				<?php if ( ! empty( $va_can_annotate ) ) : ?>
					<button type="button" class="va-annotation-remove" data-va-annotation-remove
						aria-label="<?php
						/* translators: %s: the date a note is pinned to. */
						echo esc_attr( sprintf( __( 'Remove the note for %s', 'vireo-analytics' ), vireo_format_day( (string) $va_note['date'] ) ) );
						?>">&times;</button>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>

	<?php if ( empty( $va_annotations ) ) : ?>
		<p class="va-annotations-empty" data-va-annotations-empty>
			<?php esc_html_e( 'Nothing noted in this range yet.', 'vireo-analytics' ); ?>
		</p>
	<?php endif; ?>

	<?php if ( ! empty( $va_can_annotate ) ) : ?>
		<form class="va-annotation-form" data-va-annotation-form>
			<label class="screen-reader-text" for="va-annotation-date">
				<?php esc_html_e( 'Date', 'vireo-analytics' ); ?>
			</label>
			<input type="date" id="va-annotation-date" name="date"
				value="<?php echo esc_attr( (string) $end ); ?>"
				min="<?php echo esc_attr( (string) $start ); ?>"
				max="<?php echo esc_attr( (string) $end ); ?>" required />

			<label class="screen-reader-text" for="va-annotation-label">
				<?php esc_html_e( 'What happened', 'vireo-analytics' ); ?>
			</label>
			<input type="text" id="va-annotation-label" name="label" maxlength="160"
				placeholder="<?php esc_attr_e( 'Newsletter went out', 'vireo-analytics' ); ?>" required />

			<button type="submit" class="button"><?php esc_html_e( 'Add note', 'vireo-analytics' ); ?></button>
			<span class="va-annotation-error" data-va-annotation-error role="alert"></span>
		</form>
	<?php endif; ?>
</div>
