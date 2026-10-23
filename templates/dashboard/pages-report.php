<?php
/**
 * Pages tab: every page with traffic in the range.
 *
 * Locals: $start, $end, $preset, $compare_param, $filters_obj.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template partial included inside a render method; locals are method-scoped at runtime, not true globals.
use VireoAnalytics\Dashboard;
use VireoAnalytics\Engagement_Basics;
use VireoAnalytics\Pages_Report;

$va_pg_view   = Pages_Report::request();
$va_pg_result = Pages_Report::query( (string) $start, (string) $end, $va_pg_view );
$va_pg_rows   = Pages_Report::with_titles( $va_pg_result['rows'] );
$va_pg_total  = $va_pg_result['total'];
$va_pg_n      = $va_pg_result['n'];
$va_pg_last   = $va_pg_result['pages'];
$va_pg_from   = $va_pg_total > 0 ? ( $va_pg_n - 1 ) * Pages_Report::PER_PAGE + 1 : 0;
$va_pg_to     = $va_pg_total > 0 ? $va_pg_from + count( $va_pg_rows ) - 1 : 0;

$va_pg_url = static function ( array $override ) use ( $preset, $start, $end, $compare_param, $va_pg_view ): string {
	return Pages_Report::url( (string) $preset, (string) $start, (string) $end, (string) $compare_param, array_merge( $va_pg_view, $override ) );
};

$va_pg_cols     = [
	'pageviews' => __( 'Pageviews', 'vireo-analytics' ),
	'visitors'  => __( 'Visitors', 'vireo-analytics' ),
	'avg_time'  => __( 'Avg. time', 'vireo-analytics' ),
];
$va_pg_filtered = isset( $filters_obj ) && $filters_obj instanceof \VireoAnalytics\Filters && $filters_obj->is_active();
?>
<div class="va-card va-pages-report">
	<div class="va-pages-head">
		<h2 class="va-card-title"><?php esc_html_e( 'All pages', 'vireo-analytics' ); ?></h2>
		<form class="va-pages-search" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" role="search">
			<input type="hidden" name="page" value="vireo-analytics" />
			<input type="hidden" name="tab" value="pages" />
			<input type="hidden" name="_vrn" value="<?php echo esc_attr( Dashboard::view_nonce() ); ?>" />
			<input type="hidden" name="period" value="<?php echo esc_attr( (string) $preset ); ?>" />
			<?php if ( 'custom' === $preset ) : ?>
				<input type="hidden" name="start_date" value="<?php echo esc_attr( $start ); ?>" />
				<input type="hidden" name="end_date" value="<?php echo esc_attr( $end ); ?>" />
			<?php endif; ?>
			<?php if ( '' !== $compare_param ) : ?><input type="hidden" name="compare" value="<?php echo esc_attr( $compare_param ); ?>" /><?php endif; ?>
			<?php if ( Pages_Report::DEFAULT_SORT !== $va_pg_view['by'] ) : ?><input type="hidden" name="pg_by" value="<?php echo esc_attr( $va_pg_view['by'] ); ?>" /><?php endif; ?>
			<?php if ( 'desc' !== $va_pg_view['dir'] ) : ?><input type="hidden" name="pg_dir" value="<?php echo esc_attr( $va_pg_view['dir'] ); ?>" /><?php endif; ?>
			<label class="screen-reader-text" for="va-pages-s"><?php esc_html_e( 'Search pages', 'vireo-analytics' ); ?></label>
			<input type="search" id="va-pages-s" name="pg_s" value="<?php echo esc_attr( $va_pg_view['s'] ); ?>" placeholder="<?php esc_attr_e( 'Search title or path', 'vireo-analytics' ); ?>" maxlength="100" />
			<button type="submit" class="button"><?php esc_html_e( 'Search', 'vireo-analytics' ); ?></button>
			<?php if ( '' !== $va_pg_view['s'] ) : ?>
				<a class="va-pages-clear" href="<?php echo esc_url( $va_pg_url( [ 's' => '', 'n' => 1 ] ) ); ?>"><?php esc_html_e( 'Clear', 'vireo-analytics' ); ?></a>
			<?php endif; ?>
		</form>
	</div>

	<?php if ( $va_pg_filtered ) : ?>
		<p class="va-pages-note"><?php esc_html_e( 'This list covers all traffic. The filters above apply to the Overview.', 'vireo-analytics' ); ?></p>
	<?php endif; ?>

	<?php if ( 0 === $va_pg_total ) : ?>
		<div class="va-empty va-empty-inline">
			<?php if ( '' !== $va_pg_view['s'] ) : ?>
				<div class="va-empty-title"><?php esc_html_e( 'No pages match your search', 'vireo-analytics' ); ?></div>
				<div class="va-empty-sub"><a href="<?php echo esc_url( $va_pg_url( [ 's' => '', 'n' => 1 ] ) ); ?>"><?php esc_html_e( 'Clear the search', 'vireo-analytics' ); ?></a></div>
			<?php else : ?>
				<div class="va-empty-title"><?php esc_html_e( 'No pages with traffic in this range', 'vireo-analytics' ); ?></div>
				<div class="va-empty-sub"><?php esc_html_e( 'Pick a wider date range, or check back after the first few visits.', 'vireo-analytics' ); ?></div>
			<?php endif; ?>
		</div>
	<?php else : ?>
		<div class="va-pages-scroll">
			<table class="va-table va-pages-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Page', 'vireo-analytics' ); ?></th>
						<?php foreach ( $va_pg_cols as $va_pg_key => $va_pg_label ) :
							$va_pg_active = $va_pg_key === $va_pg_view['by'];
							$va_pg_next   = ( $va_pg_active && 'desc' === $va_pg_view['dir'] ) ? 'asc' : 'desc';
							$va_pg_sort   = $va_pg_active ? ( 'asc' === $va_pg_view['dir'] ? 'ascending' : 'descending' ) : 'none';
						?>
							<th scope="col" class="va-num" aria-sort="<?php echo esc_attr( $va_pg_sort ); ?>">
								<a class="va-sort<?php echo $va_pg_active ? ' is-active' : ''; ?>" href="<?php echo esc_url( $va_pg_url( [ 'by' => $va_pg_key, 'dir' => $va_pg_next, 'n' => 1 ] ) ); ?>">
									<?php echo esc_html( $va_pg_label ); ?>
									<span class="va-sort-arrow" aria-hidden="true"><?php echo $va_pg_active ? ( 'asc' === $va_pg_view['dir'] ? '&#9650;' : '&#9660;' ) : ''; ?></span>
								</a>
							</th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $va_pg_rows as $va_pg_row ) : ?>
						<tr>
							<td class="va-pages-page">
								<?php if ( '' !== $va_pg_row['url'] ) : ?>
									<a class="va-pages-title" title="<?php echo esc_attr( $va_pg_row['title'] ); ?>" href="<?php echo esc_url( $va_pg_row['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $va_pg_row['title'] ); ?></a>
								<?php else : ?>
									<span class="va-pages-title" title="<?php echo esc_attr( $va_pg_row['title'] ); ?>"><?php echo esc_html( $va_pg_row['title'] ); ?></span>
								<?php endif; ?>
								<?php if ( $va_pg_row['title'] !== $va_pg_row['path'] ) : ?>
									<span class="va-pages-path" title="<?php echo esc_attr( $va_pg_row['path'] ); ?>"><?php echo esc_html( $va_pg_row['path'] ); ?></span>
								<?php endif; ?>
							</td>
							<td class="va-num"><?php echo esc_html( number_format_i18n( $va_pg_row['pageviews'] ) ); ?></td>
							<td class="va-num"><?php echo esc_html( number_format_i18n( $va_pg_row['visitors'] ) ); ?></td>
							<td class="va-num<?php echo null === $va_pg_row['avg_time'] ? ' va-muted' : ''; ?>"><?php echo null === $va_pg_row['avg_time'] ? '&ndash;' : esc_html( Engagement_Basics::format_seconds( $va_pg_row['avg_time'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<nav class="va-pages-pager" aria-label="<?php esc_attr_e( 'Pages pagination', 'vireo-analytics' ); ?>">
			<span class="va-pages-count">
				<?php
				printf(
					/* translators: 1: first row number, 2: last row number, 3: total pages with traffic. */
					esc_html__( 'Showing %1$s–%2$s of %3$s', 'vireo-analytics' ),
					esc_html( number_format_i18n( $va_pg_from ) ),
					esc_html( number_format_i18n( $va_pg_to ) ),
					esc_html( number_format_i18n( $va_pg_total ) )
				);
				?>
			</span>
			<?php if ( $va_pg_last > 1 ) : ?>
				<span class="va-pages-steps">
					<?php if ( $va_pg_n > 1 ) : ?>
						<a class="va-pages-step" rel="prev" href="<?php echo esc_url( $va_pg_url( [ 'n' => $va_pg_n - 1 ] ) ); ?>"><?php esc_html_e( 'Previous', 'vireo-analytics' ); ?></a>
					<?php endif; ?>
					<span class="va-pages-of">
						<?php
						printf(
							/* translators: 1: current page number, 2: number of pages. */
							esc_html__( 'Page %1$s of %2$s', 'vireo-analytics' ),
							esc_html( number_format_i18n( $va_pg_n ) ),
							esc_html( number_format_i18n( $va_pg_last ) )
						);
						?>
					</span>
					<?php if ( $va_pg_n < $va_pg_last ) : ?>
						<a class="va-pages-step" rel="next" href="<?php echo esc_url( $va_pg_url( [ 'n' => $va_pg_n + 1 ] ) ); ?>"><?php esc_html_e( 'Next', 'vireo-analytics' ); ?></a>
					<?php endif; ?>
				</span>
			<?php else : ?>
				<span class="va-pages-end"><?php esc_html_e( 'That is every page', 'vireo-analytics' ); ?></span>
			<?php endif; ?>
		</nav>
	<?php endif; ?>
</div>
