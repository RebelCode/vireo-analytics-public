<?php
/**
 * Leaderboard — top pages.
 *
 * Locals: $pages (array of rows from Dashboard::get_top_pages),
 *         $va_page_comments (post_id => approved comments in the range).
 */

defined( 'ABSPATH' ) || exit;


// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template partial included inside a render method; locals are method-scoped at runtime, not true globals.
use VireoAnalytics\Dashboard;
use VireoAnalytics\Comment_Stats;
use VireoAnalytics\Engagement_Basics;
?>
<?php
$va_pages_blocked = isset( $va_unavailable['pages'] ) ? (string) $va_unavailable['pages'] : '';
$va_pages_filters = isset( $filters_obj ) && $filters_obj instanceof \VireoAnalytics\Filters ? $filters_obj : null;
?>
<div class="va-card va-board">
	<h2 class="va-card-title"><?php esc_html_e( 'Top pages', 'vireo-analytics' ); ?></h2>
	<?php if ( '' !== $va_pages_blocked ) : ?>
		<?php $va_unavailable_card( $va_pages_blocked ); ?>
	<?php elseif ( empty( $pages ) ) : ?>
		<div class="va-empty va-empty-inline">
			<div class="va-empty-title"><?php esc_html_e( 'No pages tracked yet', 'vireo-analytics' ); ?></div>
			<div class="va-empty-sub"><?php esc_html_e( 'Top posts and pages will appear here after the first few visits.', 'vireo-analytics' ); ?></div>
		</div>
	<?php else : ?>
		<ul class="va-board-list">
			<?php foreach ( $pages as $row ) :
				$title   = (string) $row['title'];
				$path    = (string) $row['path'];
				$url     = (string) $row['url'];
				$post_id = (int) ( $row['post_id'] ?? 0 );
				$title   = Dashboard::page_label( $title, $path );

				$count     = (int) $row['pageviews'];
				$chip      = Dashboard::delta_chip( $row['delta'] ?? null, $row['prior'] ?? null );
				$stats_url = $post_id > 0
					? add_query_arg(
						[ 'page' => 'vireo-analytics', 'view' => 'post', 'post_id' => $post_id, '_vrn' => \VireoAnalytics\Dashboard::view_nonce() ] + ( 'custom' === ( $preset ?? '' ) ? [ 'start_date' => $start, 'end_date' => $end ] : [ 'period' => $preset ?? 'last_30' ] ),
						admin_url( 'admin.php' )
					)
					: '';

				// Deleted or non-public post fallback. When post_id > 0 but the post
				// is gone, or is a draft, private, scheduled or of a type without
				// a public URL, Dashboard::get_top_pages leaves the title empty and
				// the path stands in for it, making the row indistinguishable from
				// a live post whose slug happens to match the path. Detect that
				// state and render the path in muted style with a tag.
				$is_unpublished = ( $post_id > 0 && ( '' === $url || $title === $path ) && ! is_post_publicly_viewable( $post_id ) );

				$avg_time   = isset( $row['avg_time'] ) ? (int) $row['avg_time'] : null;
				$time_title = null === $avg_time ? '' : sprintf(
					/* translators: %s: a duration such as "1m 05s". */
					__( 'Average active time on this page: %s', 'vireo-analytics' ),
					Engagement_Basics::format_seconds( $avg_time )
				);

				// Click to filter the overview by this page. Under a source or
				// referrer filter only a post can be crossed with it.
				$va_can_filter = '' !== $path && isset( $va_row_filter )
					&& ( null === $va_pages_filters || ( ! $va_pages_filters->has_page() && ( $post_id > 0 || ! $va_pages_filters->is_active() ) ) );
				$va_filter_btn = $va_can_filter
					/* translators: %s: a page title */
					? $va_row_filter( [ 'path' => $path ], sprintf( __( 'Filter by %s', 'vireo-analytics' ), $title ) )
					: '';

				$comment_count = $post_id > 0 ? (int) ( $va_page_comments[ $post_id ] ?? 0 ) : 0;
				$comment_title = '';
				if ( $comment_count > 0 ) {
					$comment_rate  = Comment_Stats::rate( $comment_count, $count );
					$comment_title = Comment_Stats::comments_label( $comment_count )
						. ( null !== $comment_rate ? ' · ' . Comment_Stats::rate_label( $comment_rate ) : '' );
				}
			?>
				<li class="va-board-row<?php echo $is_unpublished ? ' va-board-row-unpublished' : ''; ?>">
					<span class="va-board-lead">
						<span class="va-board-icon va-board-icon-path" aria-hidden="true">/</span>
						<span class="va-board-label">
							<span class="va-board-label-main" title="<?php echo esc_attr( $is_unpublished ? $path : $title ); ?>">
								<?php if ( $is_unpublished ) : ?>
									<span class="va-board-unpublished-path"><?php echo esc_html( $path ); ?></span>
									<span class="va-board-unpublished-tag"><?php esc_html_e( '(unpublished)', 'vireo-analytics' ); ?></span>
								<?php elseif ( '' !== $stats_url ) : ?>
									<a href="<?php echo esc_url( $stats_url ); ?>"><?php echo esc_html( $title ); ?></a>
								<?php elseif ( $url ) : ?>
									<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $title ); ?></a>
								<?php else : ?>
									<?php echo esc_html( $title ); ?>
								<?php endif; ?>
							</span>
							<?php if ( ! $is_unpublished && '' !== $path && $path !== $title ) : ?>
								<span class="va-board-label-sub" title="<?php echo esc_attr( $path ); ?>">
									<?php if ( $url ) : ?>
										<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener" class="va-board-path-link"><?php echo esc_html( $path ); ?></a>
									<?php else : ?>
										<?php echo esc_html( $path ); ?>
									<?php endif; ?>
								</span>
							<?php endif; ?>
						</span>
					</span>
					<span class="va-board-tail">
						<?php echo $va_filter_btn; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where built ?>
						<?php if ( $comment_count > 0 ) : ?>
							<span class="va-board-comments" title="<?php echo esc_attr( $comment_title ); ?>">
								<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" width="12" height="12" aria-hidden="true" focusable="false"><path d="M2.5 2.5h11a1 1 0 0 1 1 1v7a1 1 0 0 1-1 1H7l-3.5 3v-3h-1a1 1 0 0 1-1-1v-7a1 1 0 0 1 1-1Z" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg>
								<span class="screen-reader-text"><?php echo esc_html( $comment_title ); ?></span>
								<span aria-hidden="true"><?php echo esc_html( number_format_i18n( $comment_count ) ); ?></span>
							</span>
						<?php endif; ?>
						<?php if ( null !== $avg_time ) : ?>
							<span class="va-board-time" title="<?php echo esc_attr( $time_title ); ?>">
								<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" width="12" height="12" aria-hidden="true" focusable="false"><circle cx="8" cy="8" r="6.3" fill="none" stroke="currentColor" stroke-width="1.4"/><path d="M8 4.6V8l2.3 1.5" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
								<span class="screen-reader-text"><?php echo esc_html( $time_title ); ?></span>
								<span aria-hidden="true"><?php echo esc_html( Engagement_Basics::format_seconds( $avg_time ) ); ?></span>
							</span>
						<?php endif; ?>
						<?php if ( '' !== $chip ) : ?><?php echo wp_kses_post( $chip ); ?><?php endif; ?>
						<span class="va-board-count" title="<?php echo esc_attr( number_format_i18n( $count ) ); ?>"><?php echo esc_html( Dashboard::abbrev( $count ) ); ?></span>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>
		<a class="va-board-more" href="<?php echo esc_url( \VireoAnalytics\Pages_Report::url( (string) ( $preset ?? 'last_30' ), (string) $start, (string) $end, (string) ( $compare_param ?? '' ) ) ); ?>"><?php esc_html_e( 'View all pages', 'vireo-analytics' ); ?></a>
	<?php endif; ?>
</div>
