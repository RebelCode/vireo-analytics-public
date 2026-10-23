<?php
/**
 * One line that only shows on paper: which site this is and when it was
 * printed, since a PDF has no address bar.
 */

defined( 'ABSPATH' ) || exit;

$va_print_site = (string) wp_parse_url( home_url(), PHP_URL_HOST );
?>
<p class="va-print-meta">
	<?php
	printf(
		/* translators: 1: site host name, 2: date and time */
		esc_html__( '%1$s. Printed %2$s from Vireo Analytics.', 'vireo-analytics' ),
		esc_html( $va_print_site ),
		esc_html( wp_date( (string) get_option( 'date_format', 'F j, Y' ) . ' ' . (string) get_option( 'time_format', 'g:i a' ) ) )
	);
	?>
</p>
