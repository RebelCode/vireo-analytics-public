<?php
/**
 * Date presets for the post and referrer screens, which used to be fixed at
 * ninety days with no way to change them.
 *
 * Locals: $va_range_args (array, the view's own query args), $preset (string).
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template partial included inside a render method; locals are method-scoped at runtime.
$va_detail_presets = [
	'last_7'     => __( 'Last 7', 'vireo-analytics' ),
	'last_30'    => __( 'Last 30', 'vireo-analytics' ),
	'last_90'    => __( 'Last 90', 'vireo-analytics' ),
	'this_month' => __( 'This month', 'vireo-analytics' ),
	'last_month' => __( 'Last month', 'vireo-analytics' ),
	'ytd'        => __( 'YTD', 'vireo-analytics' ),
];
?>
<?php include VIREO_DIR . 'templates/dashboard/print-meta.php'; ?>
<div class="va-controls">
	<div class="va-preset-bar" role="group" aria-label="<?php esc_attr_e( 'Date range', 'vireo-analytics' ); ?>">
		<?php foreach ( $va_detail_presets as $va_key => $va_label ) : ?>
			<?php $va_is_active = $va_key === (string) ( $preset ?? '' ); ?>
			<a class="va-preset-btn<?php echo $va_is_active ? ' is-active' : ''; ?>"<?php echo $va_is_active ? ' aria-current="true"' : ''; ?>
				href="<?php echo esc_url( add_query_arg( array_merge( (array) $va_range_args, [ 'period' => $va_key, '_vrn' => \VireoAnalytics\Dashboard::view_nonce() ] ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $va_label ); ?></a>
		<?php endforeach; ?>
	</div>
	<div class="va-controls-end">
		<button type="button" class="va-print-btn" data-va-print><?php esc_html_e( 'Print / Save as PDF', 'vireo-analytics' ); ?></button>
	</div>
</div>
