<?php
/**
 * Where an import stops, because Vireo Analytics has already recorded those
 * days itself. Included inside each import form.
 *
 * Expected in scope:
 *   $own_since  ?string  First day Vireo recorded itself, YYYY-MM-DD, or null.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template partial included inside a render method; locals are method-scoped at runtime.

if ( null === $own_since ) {
	return;
}

$va_own_from = mysql2date( (string) get_option( 'date_format', 'F j, Y' ), $own_since );
$va_own_stop = mysql2date( (string) get_option( 'date_format', 'F j, Y' ), \VireoAnalytics\Import_History::day_before( $own_since ) );
?>
<div class="va-import-warnings va-import-own-data">
	<p>
		<?php
		printf(
			/* translators: 1: first day Vireo Analytics recorded, 2: the day before it */
			esc_html__( 'Vireo Analytics has its own data from %1$s, so the import stops on %2$s. Days from %1$s on keep the counts Vireo recorded.', 'vireo-analytics' ),
			esc_html( $va_own_from ),
			esc_html( $va_own_stop )
		);
		?>
	</p>
	<label class="va-import-checkbox">
		<input type="checkbox" name="include_own_days" value="1" />
		<?php
		printf(
			/* translators: %s: first day Vireo Analytics recorded */
			esc_html__( 'Also import %s onwards. Those days already hold what Vireo counted, so the imported numbers are added on top and the days are counted twice.', 'vireo-analytics' ),
			esc_html( $va_own_from )
		);
		?>
	</label>
</div>
