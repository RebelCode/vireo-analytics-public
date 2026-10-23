<?php
/**
 * Overview page wrapper.
 *
 * Expected locals (set by Dashboard::render_overview_page):
 *   $start, $end, $preset, $compare
 *   $hero, $chart, $chart_prev, $pages, $referrers, $countries, $devices
 *   $va_page_title
 */

defined( 'ABSPATH' ) || exit;


// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template partial included inside a render method; locals are method-scoped at runtime, not true globals.
use VireoAnalytics\Dashboard;
use VireoAnalytics\Dashboard_Sections;
use VireoAnalytics\Filters;
use VireoAnalytics\Year_Review;

$presets = [
	'today'      => __( 'Today', 'vireo-analytics' ),
	'yesterday'  => __( 'Yesterday', 'vireo-analytics' ),
	'last_7'     => __( 'Last 7', 'vireo-analytics' ),
	'last_14'    => __( 'Last 14', 'vireo-analytics' ),
	'last_30'    => __( 'Last 30', 'vireo-analytics' ),
	'last_90'    => __( 'Last 90', 'vireo-analytics' ),
	'this_month' => __( 'This month', 'vireo-analytics' ),
	'last_month' => __( 'Last month', 'vireo-analytics' ),
	'ytd'        => __( 'YTD', 'vireo-analytics' ),
];

$base_url = admin_url( 'admin.php?page=vireo-analytics' );

// The tab rides along on every link that changes the range or filters, so
// switching from Last 30 to Last 90 on the Search tab stays on the Search tab.
// admin-tabs.js keeps these links current when the tab changes in place.
$va_tab      = Dashboard::requested_tab();
$va_tab_args = Dashboard::DEFAULT_TAB === $va_tab ? [] : [ 'tab' => $va_tab ];

// Nonce stamped onto every internal filter link so Dashboard honors the
// read-only view params (date range, compare, segments). See
// Dashboard::request_is_trusted().
$view_nonce = Dashboard::view_nonce();

// Carry the active filter chips + chart mode across preset clicks so the user
// doesn't silently lose them when switching date range.
$filter_args = ( isset( $va_filters ) && $va_filters instanceof Filters ) ? $va_filters->to_query_args() : [];
$chart_mode  = isset( $va_chart_mode ) ? (string) $va_chart_mode : 'day';

// The compare param as the URL carries it: off, the previous period, or the
// same period last year. Every link that keeps the comparison keeps the mode.
$va_compare_mode = isset( $va_compare_mode ) ? (string) $va_compare_mode : 'prior';
$compare_param   = $compare ? ( 'year' === $va_compare_mode ? 'year' : '1' ) : '';

$build_preset_url = static function ( string $key ) use ( $compare_param, $filter_args, $chart_mode, $view_nonce, $va_tab_args ): string {
	$args = [ 'page' => 'vireo-analytics', 'period' => $key, '_vrn' => $view_nonce ];
	if ( '' !== $compare_param ) {
		$args['compare'] = $compare_param;
	}
	if ( 'hour' === $chart_mode ) {
		$args['chart_mode'] = 'hour';
	}
	return add_query_arg( array_merge( $args, $filter_args, $va_tab_args ), admin_url( 'admin.php' ) );
};

// Build filter-chip URLs. Each chip's link toggles that dimension while
// keeping everything else stable.
$build_filter_url = static function ( array $overrides ) use ( $preset, $start, $end, $compare_param, $filter_args, $chart_mode, $view_nonce, $va_tab_args ): string {
	$base = [ 'page' => 'vireo-analytics', '_vrn' => $view_nonce ];
	if ( 'custom' === $preset ) {
		$base['period']     = 'custom';
		$base['start_date'] = $start;
		$base['end_date']   = $end;
	} else {
		$base['period'] = $preset;
	}
	if ( '' !== $compare_param ) {
		$base['compare'] = $compare_param;
	}
	if ( 'hour' === $chart_mode ) {
		$base['chart_mode'] = 'hour';
	}
	// Merge overrides — use null to clear a param.
	$merged = array_merge( $base, $filter_args, $va_tab_args, $overrides );
	foreach ( $merged as $k => $v ) {
		if ( null === $v || '' === $v ) {
			unset( $merged[ $k ] );
		}
	}
	return add_query_arg( $merged, admin_url( 'admin.php' ) );
};

$filters_obj = ( isset( $va_filters ) && $va_filters instanceof Filters ) ? $va_filters : null;

// The funnel on a card row: click a page, referrer, country or device to
// filter the whole overview by it. Rendered by the partials.
$va_row_filter = static function ( array $overrides, string $label ) use ( $build_filter_url ): string {
	return '<a class="va-row-filter" data-va-tab-carry href="' . esc_url( $build_filter_url( $overrides ) ) . '" title="' . esc_attr( $label ) . '">'
		. '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" width="14" height="14" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M2.5 3h11l-4.25 5v4.5l-2.5 1.25V8L2.5 3Z"/></svg>'
		. '<span class="screen-reader-text">' . esc_html( $label ) . '</span></a>';
};

// What a card prints when the filters on cannot be answered from its table.
$va_unavailable_card = static function ( string $reason ): void {
	?>
	<div class="va-empty va-empty-inline va-empty-unavailable">
		<div class="va-empty-title"><?php esc_html_e( 'Not shown with these filters', 'vireo-analytics' ); ?></div>
		<div class="va-empty-sub"><?php echo esc_html( $reason ); ?></div>
	</div>
	<?php
};

// The compare links keep the filters and the range. Turning compare off has to
// say so explicitly: dropping the param falls back to the on-by-default in
// Dashboard::compare_enabled(), which left the checkbox unable to uncheck itself.
$build_compare_url = static function ( string $value ) use ( $build_filter_url ): string {
	return $build_filter_url( [ 'compare' => $value ] );
};
$toggle_compare_url = $build_compare_url( $compare ? '0' : ( 'year' === $va_compare_mode ? 'year' : '1' ) );

$va_compare_modes = [
	'prior' => __( 'Previous period', 'vireo-analytics' ),
	'year'  => __( 'Same period last year', 'vireo-analytics' ),
];

// The chips name each active filter in words. A country or device filter
// comes from a select, a page or referrer from a row in its card.
$va_chips = [];
if ( $filters_obj ) {
	if ( '' !== $filters_obj->device ) {
		/* translators: %s: a device type, such as Mobile */
		$va_chips['device'] = sprintf( __( 'Device is %s', 'vireo-analytics' ), Dashboard::device_label( $filters_obj->device ) );
	}
	if ( '' !== $filters_obj->country ) {
		/* translators: %s: a country name */
		$va_chips['country'] = sprintf( __( 'Country is %s', 'vireo-analytics' ), Dashboard::country_name( $filters_obj->country ) );
	}
	if ( '' !== $filters_obj->source ) {
		$va_source_names = [
			'direct'   => __( 'Direct', 'vireo-analytics' ),
			'search'   => __( 'Search', 'vireo-analytics' ),
			'ai'       => __( 'AI assistants', 'vireo-analytics' ),
			'social'   => __( 'Social', 'vireo-analytics' ),
			'referral' => __( 'Referral', 'vireo-analytics' ),
		];
		/* translators: %s: a source type, such as Search */
		$va_chips['source'] = sprintf( __( 'Source is %s', 'vireo-analytics' ), $va_source_names[ $filters_obj->source ] ?? $filters_obj->source );
	}
	if ( '' !== $filters_obj->ref ) {
		/* translators: %s: a referrer host, such as google.com */
		$va_chips['ref'] = sprintf( __( 'Referrer is %s', 'vireo-analytics' ), vireo_referrer_label( $filters_obj->ref ) );
	}
	if ( '' !== $filters_obj->path ) {
		$va_chip_post  = $filters_obj->page_post_id();
		$va_chip_title = $va_chip_post > 0 ? Dashboard::public_post( $va_chip_post )['title'] : '';
		/* translators: %s: a page title or path */
		$va_chips['path'] = sprintf( __( 'Page is %s', 'vireo-analytics' ), Dashboard::page_label( $va_chip_title, $filters_obj->path ) );
	}
}

// Which selects can still be combined with what is on. Device and country
// stand alone: no rollup crosses them with anything else.
$va_dims         = $filters_obj ? $filters_obj->dims() : [];
$va_lock_reason  = __( 'Vireo keeps daily totals per dimension, so this cannot be combined with the filters already on.', 'vireo-analytics' );
$va_lock_device  = [] !== array_diff( $va_dims, [ 'device' ] );
$va_lock_country = [] !== array_diff( $va_dims, [ 'country' ] );
$va_lock_source  = in_array( 'device', $va_dims, true ) || in_array( 'country', $va_dims, true );

// The saved-views picker: this user's views, and whether the current view is
// one of them.
$va_saved_views = isset( $va_saved_views ) && is_array( $va_saved_views ) ? $va_saved_views : [];
$va_view_params = isset( $va_view_params ) && is_array( $va_view_params ) ? $va_view_params : [];
$va_view_match  = \VireoAnalytics\Saved_Views::match( $va_saved_views, $va_view_params );
?>
<div class="wrap va-wrap">
	<div class="va-root" data-va-start="<?php echo esc_attr( $start ); ?>" data-va-end="<?php echo esc_attr( $end ); ?>" data-va-compare="<?php echo $compare ? '1' : '0'; ?>">

		<header class="va-page-head">
			<div class="va-brand">
				<img class="va-brand-mark" src="<?php echo esc_url( VIREO_URL . 'assets/img/vireo-mark.png' ); ?>" width="28" height="28" alt="">
				<span class="va-brand-name">Vireo <span>Analytics</span></span>
				<?php if ( ! empty( $va_sites ) ) : ?>
					<?php
					// Which site the page shows. Only here once a site has been
					// connected; a single-site install never sees it.
					?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="va-site-switch" data-va-site-switch>
						<input type="hidden" name="action" value="vireo_select_site">
						<?php wp_nonce_field( Dashboard::SELECT_SITE_NONCE ); ?>
						<label>
							<span class="screen-reader-text"><?php esc_html_e( 'Site', 'vireo-analytics' ); ?></span>
							<select name="site_id" data-va-site-select>
								<option value="0" <?php selected( ! $va_connected ); ?>><?php echo esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ?: __( 'This site', 'vireo-analytics' ) ); ?></option>
								<?php foreach ( $va_sites as $va_other ) : ?>
									<option value="<?php echo (int) $va_other['id']; ?>" <?php selected( $va_site && (int) $va_site['id'] === (int) $va_other['id'] ); ?>><?php echo esc_html( $va_other['name'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<noscript><button type="submit" class="button"><?php esc_html_e( 'Switch', 'vireo-analytics' ); ?></button></noscript>
					</form>
				<?php endif; ?>
			</div>
			<h1 class="va-page-title"><?php echo esc_html( $va_page_title ); ?></h1>
			<div class="va-page-sub">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: start date, 2: end date */
						__( '%1$s to %2$s', 'vireo-analytics' ),
						vireo_format_day( (string) $start, 'M j, Y' ),
						vireo_format_day( (string) $end, 'M j, Y' )
					)
				);
				echo Dashboard::site_subtitle(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside
				?>
			</div>
			<?php include VIREO_DIR . 'templates/dashboard/print-meta.php'; ?>
		</header>
		<?php // WordPress puts admin notices after this rule. Without it they landed
		// between the page title and its dates. ?>
		<hr class="wp-header-end">

		<?php if ( Dashboard::view_request_was_ignored() ) : ?>
			<div class="va-stale-view" role="status">
				<?php esc_html_e( 'This link asked for a view that has expired, so the default range is shown instead. Dashboard links stop working after about a day. Pick a range below to carry on.', 'vireo-analytics' ); ?>
			</div>
		<?php endif; ?>

		<?php
		$device_opts  = [ '' => __( 'All devices', 'vireo-analytics' ) ] + [
			'mobile'  => __( 'Mobile',  'vireo-analytics' ),
			'desktop' => __( 'Desktop', 'vireo-analytics' ),
			'tablet'  => __( 'Tablet',  'vireo-analytics' ),
		];
		$source_opts  = [ '' => __( 'All sources', 'vireo-analytics' ) ] + [
			'direct'   => __( 'Direct',   'vireo-analytics' ),
			'search'   => __( 'Search',   'vireo-analytics' ),
			'ai'       => __( 'AI assistants', 'vireo-analytics' ),
			'social'   => __( 'Social',   'vireo-analytics' ),
			'referral' => __( 'Referral', 'vireo-analytics' ),
		];
		// Country list — build a Top 20 from whatever's in $countries.
		$country_opts = [ '' => __( 'All countries', 'vireo-analytics' ) ];
		foreach ( array_slice( (array) $countries, 0, 20 ) as $c ) {
			$country_opts[ strtoupper( (string) $c['code'] ) ] = (string) $c['name'];
		}
		if ( $filters_obj && '' !== $filters_obj->country && ! isset( $country_opts[ $filters_obj->country ] ) ) {
			$country_opts[ $filters_obj->country ] = Dashboard::country_name( $filters_obj->country );
		}
		$active_device  = $filters_obj ? $filters_obj->device  : '';
		$active_country = $filters_obj ? $filters_obj->country : '';
		$active_source  = $filters_obj ? $filters_obj->source  : '';
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="va-filter-bar" data-va-filter-bar>
			<input type="hidden" name="page" value="vireo-analytics" />
			<input type="hidden" name="_vrn" value="<?php echo esc_attr( $view_nonce ); ?>" />
			<input type="hidden" name="period" value="<?php echo esc_attr( $preset ); ?>" />
			<?php if ( 'custom' === $preset ) : ?>
				<input type="hidden" name="start_date" value="<?php echo esc_attr( $start ); ?>" />
				<input type="hidden" name="end_date"   value="<?php echo esc_attr( $end ); ?>" />
			<?php endif; ?>
			<?php if ( '' !== $compare_param ) : ?><input type="hidden" name="compare" value="<?php echo esc_attr( $compare_param ); ?>" /><?php endif; ?>
			<?php if ( 'hour' === $chart_mode ) : ?><input type="hidden" name="chart_mode" value="hour" /><?php endif; ?>
			<input type="hidden" name="tab" value="<?php echo esc_attr( $va_tab ); ?>" data-va-tab-carry<?php disabled( Dashboard::DEFAULT_TAB, $va_tab ); ?> />

			<?php // A page or referrer filter has no select; it rides along as a hidden field so changing another select keeps it. ?>
			<?php if ( $filters_obj && '' !== $filters_obj->ref ) : ?><input type="hidden" name="ref" value="<?php echo esc_attr( $filters_obj->ref ); ?>" /><?php endif; ?>
			<?php if ( $filters_obj && '' !== $filters_obj->path ) : ?><input type="hidden" name="path" value="<?php echo esc_attr( $filters_obj->path ); ?>" /><?php endif; ?>

			<span class="va-filter-label"><?php esc_html_e( 'Filter:', 'vireo-analytics' ); ?></span>

			<label class="va-filter-chip<?php echo $va_lock_device ? ' is-locked' : ''; ?>"<?php echo $va_lock_device ? ' title="' . esc_attr( $va_lock_reason ) . '"' : ''; ?>>
				<span class="va-filter-chip-label"><?php esc_html_e( 'Device', 'vireo-analytics' ); ?></span>
				<select name="device" data-va-filter<?php disabled( $va_lock_device ); ?>>
					<?php foreach ( $device_opts as $val => $label ) : ?>
						<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $active_device, $val ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>

			<label class="va-filter-chip<?php echo $va_lock_country ? ' is-locked' : ''; ?>"<?php echo $va_lock_country ? ' title="' . esc_attr( $va_lock_reason ) . '"' : ''; ?>>
				<span class="va-filter-chip-label"><?php esc_html_e( 'Country', 'vireo-analytics' ); ?></span>
				<select name="country" data-va-filter<?php disabled( $va_lock_country ); ?>>
					<?php foreach ( $country_opts as $val => $label ) : ?>
						<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $active_country, $val ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>

			<label class="va-filter-chip<?php echo $va_lock_source ? ' is-locked' : ''; ?>"<?php echo $va_lock_source ? ' title="' . esc_attr( $va_lock_reason ) . '"' : ''; ?>>
				<span class="va-filter-chip-label"><?php esc_html_e( 'Source', 'vireo-analytics' ); ?></span>
				<select name="source" data-va-filter<?php disabled( $va_lock_source ); ?>>
					<?php foreach ( $source_opts as $val => $label ) : ?>
						<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $active_source, $val ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>

			<noscript><button type="submit" class="button"><?php esc_html_e( 'Apply', 'vireo-analytics' ); ?></button></noscript>

			<?php if ( [] !== $va_chips ) :
				$clear_url = $build_filter_url( array_fill_keys( Filters::PARAMS, null ) );
			?>
				<span class="va-filter-active">
					<?php foreach ( $va_chips as $va_chip_key => $va_chip_text ) : ?>
						<a class="va-filter-tag" data-va-tab-carry href="<?php echo esc_url( $build_filter_url( [ $va_chip_key => null ] ) ); ?>">
							<?php echo esc_html( $va_chip_text ); ?>
							<span class="va-filter-tag-x" aria-hidden="true">&times;</span>
							<span class="screen-reader-text"><?php esc_html_e( 'Remove this filter', 'vireo-analytics' ); ?></span>
						</a>
					<?php endforeach; ?>
					<?php if ( count( $va_chips ) > 1 ) : ?>
						<a class="va-filter-clear" data-va-tab-carry href="<?php echo esc_url( $clear_url ); ?>"><?php esc_html_e( 'Clear all', 'vireo-analytics' ); ?></a>
					<?php endif; ?>
				</span>
			<?php endif; ?>

			<?php // Saved views: pick one, save this one, or forget the one on screen. ?>
			<span class="va-views" data-va-views data-va-view-match="<?php echo esc_attr( $va_view_match ); ?>">
				<?php if ( [] !== $va_saved_views ) : ?>
					<label class="va-filter-chip va-views-pick">
						<span class="screen-reader-text"><?php esc_html_e( 'Saved views', 'vireo-analytics' ); ?></span>
						<select data-va-view-select>
							<option value=""><?php esc_html_e( 'Saved views…', 'vireo-analytics' ); ?></option>
							<?php foreach ( $va_saved_views as $va_view ) : ?>
								<option value="<?php echo esc_url( \VireoAnalytics\Saved_Views::url( $va_view ) ); ?>" <?php selected( $va_view['id'], $va_view_match ); ?>><?php echo esc_html( $va_view['name'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				<?php endif; ?>
				<?php if ( '' !== $va_view_match ) : ?>
					<button type="button" class="va-views-btn" data-va-view-delete="<?php echo esc_attr( $va_view_match ); ?>"><?php esc_html_e( 'Forget view', 'vireo-analytics' ); ?></button>
				<?php else : ?>
					<button type="button" class="va-views-btn" data-va-view-save aria-expanded="false"><?php esc_html_e( 'Save view', 'vireo-analytics' ); ?></button>
					<span class="va-views-form" data-va-view-form hidden>
						<label>
							<span class="screen-reader-text"><?php esc_html_e( 'View name', 'vireo-analytics' ); ?></span>
							<input type="text" maxlength="60" placeholder="<?php esc_attr_e( 'Name this view', 'vireo-analytics' ); ?>" data-va-view-name />
						</label>
						<button type="button" class="button button-primary button-small" data-va-view-confirm><?php esc_html_e( 'Save', 'vireo-analytics' ); ?></button>
						<button type="button" class="button button-small" data-va-view-cancel><?php esc_html_e( 'Cancel', 'vireo-analytics' ); ?></button>
					</span>
				<?php endif; ?>
				<script type="application/json" data-va-view-params><?php echo wp_json_encode( $va_view_params ); ?></script>
				<span class="va-views-status" data-va-view-status role="status" aria-live="polite"></span>
			</span>
		</form>

		<?php if ( [] !== $va_chips ) : ?>
			<?php // What the filter cannot answer, said once, above the numbers it blanks. ?>
			<?php if ( ! empty( $va_unavailable['totals'] ) ) : ?>
				<div class="va-filter-note va-filter-note-blocked" role="status">
					<?php echo esc_html( $va_unavailable['totals'] ); ?>
					<a data-va-tab-carry href="<?php echo esc_url( $build_filter_url( array_fill_keys( Filters::PARAMS, null ) ) ); ?>"><?php esc_html_e( 'Clear the filters', 'vireo-analytics' ); ?></a>
				</div>
			<?php else : ?>
				<div class="va-filter-note" role="status"><?php esc_html_e( 'Filters apply to the Overview tab.', 'vireo-analytics' ); ?></div>
			<?php endif; ?>
		<?php endif; ?>

		<div class="va-controls">
			<?php // Phones get one select instead of ten buttons wrapping over three rows. ?>
			<label class="va-range-select">
				<span class="screen-reader-text"><?php esc_html_e( 'Date range', 'vireo-analytics' ); ?></span>
				<select data-va-range-select>
					<?php foreach ( $presets as $key => $label ) : ?>
						<option value="<?php echo esc_url( $build_preset_url( $key ) ); ?>" <?php selected( $key, $preset ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
					<option value="custom" <?php selected( 'custom', $preset ); ?>><?php esc_html_e( 'Custom', 'vireo-analytics' ); ?></option>
				</select>
			</label>
			<div class="va-preset-bar" role="group" aria-label="<?php esc_attr_e( 'Date range', 'vireo-analytics' ); ?>">
				<?php foreach ( $presets as $key => $label ) :
					$is_active = ( $key === $preset );
					$url       = $build_preset_url( $key );
				?>
					<a class="va-preset-btn<?php echo $is_active ? ' is-active' : ''; ?>" data-va-tab-carry href="<?php echo esc_url( $url ); ?>"<?php echo $is_active ? ' aria-current="true"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
				<a class="va-preset-btn<?php echo 'custom' === $preset ? ' is-active' : ''; ?>" href="#" data-va-custom role="button" aria-expanded="false" aria-controls="va-custom-panel"<?php echo 'custom' === $preset ? ' aria-current="true"' : ''; ?>>
					<?php esc_html_e( 'Custom', 'vireo-analytics' ); ?>
				</a>
			</div>

			<?php
			// Comparison is free. The control used to be locked behind Pro while the
			// data rendered for everyone anyway, so the padlock guarded nothing and
			// left free users unable to switch off a view they could already see.
			?>
			<div class="va-controls-end">
				<?php if ( ! $va_connected ) : ?>
					<a class="va-yir-link" href="<?php echo esc_url( Year_Review::url() ); ?>"><?php esc_html_e( 'Year in review', 'vireo-analytics' ); ?></a>
				<?php endif; ?>
				<button type="button" class="va-print-btn" data-va-print><?php esc_html_e( 'Print / Save as PDF', 'vireo-analytics' ); ?></button>
				<a class="va-compare-toggle<?php echo $compare ? ' is-on' : ''; ?>" data-va-tab-carry href="<?php echo esc_url( $toggle_compare_url ); ?>" role="switch" aria-checked="<?php echo $compare ? 'true' : 'false'; ?>" data-va-switch>
					<span class="va-compare-check" aria-hidden="true"></span>
					<span class="va-compare-label"><?php esc_html_e( 'Compare', 'vireo-analytics' ); ?></span>
				</a>
				<?php if ( $compare ) : ?>
					<?php // Which period: the one before, or the same dates last year. Imported history makes the second one answerable from day one. ?>
					<label class="va-compare-mode">
						<span class="screen-reader-text"><?php esc_html_e( 'Compare with', 'vireo-analytics' ); ?></span>
						<select data-va-compare-mode>
							<?php foreach ( $va_compare_modes as $va_mode_key => $va_mode_label ) : ?>
								<option value="<?php echo esc_url( $build_compare_url( 'year' === $va_mode_key ? 'year' : '1' ) ); ?>" <?php selected( $va_mode_key, $va_compare_mode ); ?>><?php echo esc_html( $va_mode_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				<?php endif; ?>
			</div>
		</div>

		<div class="va-custom-panel" id="va-custom-panel" data-va-custom-panel hidden>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="vireo-analytics" />
				<input type="hidden" name="_vrn" value="<?php echo esc_attr( $view_nonce ); ?>" />
				<input type="hidden" name="period" value="custom" />
				<label><span><?php esc_html_e( 'Start', 'vireo-analytics' ); ?></span><input type="date" name="start_date" value="<?php echo esc_attr( $start ); ?>" required /></label>
				<label><span><?php esc_html_e( 'End', 'vireo-analytics' ); ?></span><input type="date" name="end_date" value="<?php echo esc_attr( $end ); ?>" required /></label>
				<?php if ( '' !== $compare_param ) : ?><input type="hidden" name="compare" value="<?php echo esc_attr( $compare_param ); ?>" /><?php endif; ?>
				<input type="hidden" name="tab" value="<?php echo esc_attr( $va_tab ); ?>" data-va-tab-carry<?php disabled( Dashboard::DEFAULT_TAB, $va_tab ); ?> />
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Apply', 'vireo-analytics' ); ?></button>
			</form>
		</div>

		<?php
		if ( ! empty( $va_show_onboarding ) && is_array( $va_onboarding_context ?? null ) ) {
			$ctx = $va_onboarding_context;
			include VIREO_DIR . 'templates/admin/import-onboarding.php';
		}

		if ( ! empty( $va_review_days ) && ! $va_connected ) {
			include VIREO_DIR . 'templates/dashboard/review-prompt.php';
		}

		if ( ! empty( $va_yir_notice ) ) {
			include VIREO_DIR . 'templates/dashboard/year-review-notice.php';
		}

		// Each tab is rendered into a buffer first, so a tab with nothing to
		// show for this range (no Search Console, no vitals yet) is left out of
		// the bar instead of opening onto a blank panel.
		$va_panels = [];

		ob_start();
		// The one-sentence lede: what matters, before any number.
		include VIREO_DIR . 'templates/dashboard/insight-line.php';

		/**
		 * After the free insight line, before the hero. Pro attaches its AI
		 * narrative panel here; free renders nothing extra.
		 *
		 * @param array $context Range, comparison flag, computed drivers and hero
		 *                       arrays, and the free insight line as a fallback.
		 */
		// Not for a connected site: an add-on's panel reads its own tables,
		// which only hold this WordPress site, and would put those numbers
		// under the other site's name.
		if ( ! $va_connected ) {
			do_action(
				'vireo_dashboard_after_insight_line',
				[
					'start'    => $start,
					'end'      => $end,
					'compare'  => $compare,
					'drivers'  => $drivers,
					'hero'     => $hero,
					'fallback' => $insight,
				]
			);
		}

		include VIREO_DIR . 'templates/dashboard/highlights.php';

		include VIREO_DIR . 'templates/dashboard/hero-stats.php';

		// Why these totals sit below server-log/pixel tools: bots that never run
		// JavaScript are never counted. Frames the numbers just read.
		include VIREO_DIR . 'templates/dashboard/bot-explainer.php';

		// Everything from here to the ops block is user-orderable. The loop stays
		// in the template rather than moving into a class method because each
		// partial reads the render method's locals ($drivers, $pages, $chart …),
		// which only exist in this scope.
		$va_section_defs  = Dashboard_Sections::definitions();
		$va_section_order = Dashboard_Sections::user_order( get_current_user_id() );
		?>

		<div id="va-sections-live" class="va-sr-only" role="status" aria-live="polite"></div>

		<div class="va-row va-row-3 va-sections" data-va-sections>
			<?php
			foreach ( $va_section_order as $va_section_id ) :
				$va_section_def = $va_section_defs[ $va_section_id ];

				// A partial can bow out entirely — devices.php returns early when
				// the range has no technology rows. Capture first so a silent
				// partial doesn't leave an empty grid cell with a drag handle on it.
				ob_start();
				include VIREO_DIR . 'templates/dashboard/' . $va_section_def['template'];
				$va_section_html = trim( (string) ob_get_clean() );

				if ( '' === $va_section_html ) {
					continue;
				}
				?>
				<section class="va-section"
					data-va-section="<?php echo esc_attr( $va_section_id ); ?>"
					data-va-width="<?php echo esc_attr( $va_section_def['width'] ); ?>"
					aria-label="<?php echo esc_attr( $va_section_def['label'] ); ?>">
					<button type="button" class="va-section-handle" data-va-section-handle
						aria-label="<?php
						/* translators: %s: section name, e.g. Top pages */
						echo esc_attr( sprintf( __( 'Move %s. Drag, or focus and use the arrow keys.', 'vireo-analytics' ), $va_section_def['label'] ) );
						?>">
						<svg viewBox="0 0 20 8" width="20" height="8" aria-hidden="true" focusable="false">
							<rect x="2" y="1" width="16" height="1.5" rx="0.75" fill="currentColor" />
							<rect x="2" y="5" width="16" height="1.5" rx="0.75" fill="currentColor" />
						</svg>
					</button>
					<?php
					// Already-escaped markup from our own partial.
					echo $va_section_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
				</section>
				<?php
			endforeach;
			?>
		</div>

		<p class="va-sections-reset">
			<button type="button" class="button-link" data-va-sections-reset hidden<?php echo $va_section_order !== Dashboard_Sections::default_order() ? ' data-va-sections-custom' : ''; ?>>
				<?php esc_html_e( 'Reset layout', 'vireo-analytics' ); ?>
			</button>
		</p>

		<?php
		// What happened to aggregated items and Instagram feeds on the site.
		include VIREO_DIR . 'templates/dashboard/wpra.php';
		include VIREO_DIR . 'templates/dashboard/spotlight.php';

		// Notes pinned to dates in this range, below the sections a reader can
		// reorder: they read as a footnote to the chart, so they stay on its tab.
		include VIREO_DIR . 'templates/dashboard/annotations.php';

		$va_panels['overview'] = (string) ob_get_clean();

		ob_start();
		include VIREO_DIR . 'templates/dashboard/pages-report.php';
		$va_panels['pages'] = trim( (string) ob_get_clean() );

		// Cards that can name their tab directly.
		$va_tab_action = static function ( string $tab ) use ( $start, $end, $compare, $va_connected ): string {
			if ( $va_connected ) {
				return '';
			}
			ob_start();
			/**
			 * Cards for one Overview tab: content, campaigns, speed or issues.
			 *
			 * Args: ( string $start, string $end, bool $compare ).
			 */
			do_action( 'vireo_overview_tab_' . $tab, $start, $end, $compare );
			return (string) ob_get_clean();
		};

		ob_start();
		// What the AI assistants took, and what they sent back.
		include VIREO_DIR . 'templates/dashboard/ai-sources.php';
		// What could be stopping them: this WordPress site's own pages, so a
		// connected site has no scan.
		if ( ! $va_connected ) {
			$va_scan = \VireoAnalytics\AI_Scan::last();
			include VIREO_DIR . 'templates/dashboard/ai-scan.php';
			echo $va_tab_action( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- add-on card markup
		}
		// What people searched for before they arrived.
		include VIREO_DIR . 'templates/dashboard/search-console.php';
		$va_panels['search'] = trim( (string) ob_get_clean() );

		// Add-on cards (Pro) render one callback at a time and are sorted onto
		// Content, Campaigns, Page speed or Issues; see Dashboard::render_addon_cards().
		// A connected site's page leaves them out: they cover this WordPress site only.
		$va_addon = $va_connected ? [] : Dashboard::render_addon_cards( (string) $start, (string) $end, (bool) $compare );

		ob_start();
		// How the pages actually felt to the people loading them.
		include VIREO_DIR . 'templates/dashboard/vitals.php';
		echo $va_addon['speed'] ?? ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- add-on card markup
		echo $va_tab_action( 'speed' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- add-on card markup
		$va_panels['speed'] = trim( (string) ob_get_clean() );

		// What people read and how: site search, engagement, authors, sections.
		$va_panels['content'] = trim( ( $va_addon['content'] ?? '' ) . $va_tab_action( 'content' ) );

		// What brought people and what they did: events, campaigns, goals, revenue.
		ob_start();
		// The events card only appears once there's an event; the how-to lives
		// in Settings.
		if ( isset( $events ) && is_array( $events ) && ! empty( $events ) ) {
			include VIREO_DIR . 'templates/dashboard/events.php';
		}
		echo $va_addon['campaigns'] ?? ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- add-on card markup
		echo $va_tab_action( 'campaigns' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- add-on card markup
		$va_panels['campaigns'] = trim( (string) ob_get_clean() );

		// Things to fix: missing pages, plus any add-on card without a home.
		ob_start();
		if ( isset( $not_found ) && is_array( $not_found ) && ! empty( $not_found['rows'] ) ) {
			$rows          = $not_found['rows'] ?? [];
			$prior_by_path = $not_found['prior_by_path'] ?? [];
			$broken_links  = $not_found['broken'] ?? [];
			$probe_rows    = $not_found['probes'] ?? [];
			$split_404     = ! empty( $not_found['split'] );
			include VIREO_DIR . 'templates/dashboard/404-table.php';
		}
		echo $va_addon['issues'] ?? ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- add-on card markup
		echo $va_tab_action( 'issues' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- add-on card markup
		$va_panels['issues'] = trim( (string) ob_get_clean() );

		$va_panels = array_filter( $va_panels, static fn( string $html ): bool => '' !== $html );
		if ( ! isset( $va_panels[ $va_tab ] ) ) {
			$va_tab = Dashboard::DEFAULT_TAB;
		}
		?>

		<?php $va_has_tabs = count( $va_panels ) > 1; ?>
		<?php if ( $va_has_tabs ) : ?>
			<nav class="va-tabs" data-va-tabs data-va-tabs-default="<?php echo esc_attr( Dashboard::DEFAULT_TAB ); ?>"
				role="tablist" aria-label="<?php esc_attr_e( 'Report sections', 'vireo-analytics' ); ?>">
				<?php foreach ( Dashboard::tabs() as $va_tab_id => $va_tab_label ) : ?>
					<?php
					if ( ! isset( $va_panels[ $va_tab_id ] ) ) {
						continue;
					}
					?>
					<a class="va-tab<?php echo $va_tab_id === $va_tab ? ' is-active' : ''; ?>"
						href="<?php echo esc_url( Dashboard::DEFAULT_TAB === $va_tab_id ? remove_query_arg( 'tab' ) : add_query_arg( 'tab', $va_tab_id ) ); ?>"
						id="va-tab-<?php echo esc_attr( $va_tab_id ); ?>"
						data-va-tab="<?php echo esc_attr( $va_tab_id ); ?>"
						role="tab"
						aria-controls="va-panel-<?php echo esc_attr( $va_tab_id ); ?>"
						aria-selected="<?php echo $va_tab_id === $va_tab ? 'true' : 'false'; ?>"
						tabindex="<?php echo $va_tab_id === $va_tab ? '0' : '-1'; ?>"><?php echo esc_html( $va_tab_label ); ?></a>
				<?php endforeach; ?>
			</nav>
		<?php endif; ?>

		<?php foreach ( $va_panels as $va_tab_id => $va_panel_html ) : ?>
			<div class="va-tab-panel" id="va-panel-<?php echo esc_attr( $va_tab_id ); ?>"
				data-va-tab-panel="<?php echo esc_attr( $va_tab_id ); ?>"
				<?php if ( $va_has_tabs ) : ?>
					role="tabpanel" aria-labelledby="va-tab-<?php echo esc_attr( $va_tab_id ); ?>"
				<?php endif; ?>
				<?php echo $va_tab_id === $va_tab ? '' : 'hidden'; ?>>
				<?php
				// Already-escaped markup from our own partials and Pro's cards.
				echo $va_panel_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
			</div>
		<?php endforeach; ?>

	</div>
</div>
