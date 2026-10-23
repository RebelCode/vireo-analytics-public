<?php
/**
 * Year in review.
 *
 * Locals (set by Year_Review::render): $va_yir (Year_Review::build() output),
 * $va_yir_years (int[] newest first).
 */

defined( 'ABSPATH' ) || exit;


// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template partial included inside a render method; locals are method-scoped at runtime, not true globals.
use VireoAnalytics\Dashboard;
use VireoAnalytics\Settings;
use VireoAnalytics\Year_Review;

$review = $va_yir['review'];
$va_year   = (int) $review['year'];
$va_totals = $review['totals'];
$prior  = $review['prior'];

$va_title = $review['partial']
	/* translators: %d: year */
	? sprintf( __( '%d so far', 'vireo-analytics' ), $va_year )
	/* translators: %d: year */
	: sprintf( __( '%d in review', 'vireo-analytics' ), $va_year );

$range = sprintf(
	/* translators: 1: start date, 2: end date */
	__( '%1$s to %2$s', 'vireo-analytics' ),
	vireo_format_day( $review['start'], 'M j, Y' ),
	vireo_format_day( $review['end'], 'M j, Y' )
);

$pv_change  = null === $prior ? null : Year_Review::pct_change( (int) $va_totals['pageviews'], (int) $prior['pageviews'] );
$vis_change = null === $prior ? null : Year_Review::pct_change( (int) $va_totals['visitors'], (int) $prior['visitors'] );

$compare_note = '';
if ( null !== $prior ) {
	$compare_note = $review['partial']
		/* translators: %d: the previous year */
		? sprintf( __( 'vs the same days of %d', 'vireo-analytics' ), $va_year - 1 )
		/* translators: %d: the previous year */
		: sprintf( __( 'vs %d', 'vireo-analytics' ), $va_year - 1 );
} elseif ( $review['has_data'] ) {
	/* translators: %d: the previous year */
	$compare_note = sprintf( __( 'No full record of %d to compare with', 'vireo-analytics' ), $va_year - 1 );
}

$chip = static function ( ?int $pct ): string {
	if ( null === $pct || 0 === $pct ) {
		return '';
	}
	return sprintf(
		'<span class="va-chip %s">%s<span>%s</span></span>',
		$pct > 0 ? 'va-chip-up' : 'va-chip-down',
		Dashboard::arrow_svg( $pct > 0 ? 'up' : 'down' ),
		esc_html( sprintf( '%+d%%', $pct ) )
	);
};

$max_month = 0;
foreach ( $review['months'] as $month ) {
	$max_month = max( $max_month, (int) $month['pageviews'] );
}
$any_imported = (int) $review['imported']['days'] > 0;

$source_labels = [
	'direct'   => __( 'Direct', 'vireo-analytics' ),
	'search'   => __( 'Search', 'vireo-analytics' ),
	'ai'       => __( 'AI assistants', 'vireo-analytics' ),
	'social'   => __( 'Social', 'vireo-analytics' ),
	'referral' => __( 'Referral', 'vireo-analytics' ),
];

$overview_url = admin_url( 'admin.php?page=vireo-analytics' );
?>
<div class="wrap va-wrap">
	<div class="va-root va-yir" data-va-yir>

		<header class="va-page-head">
			<div class="va-yir-head-row">
				<a class="va-back-link" href="<?php echo esc_url( $overview_url ); ?>"><?php esc_html_e( '&larr; Overview', 'vireo-analytics' ); ?></a>
			</div>
			<h1 class="va-page-title"><?php echo esc_html( $va_title ); ?></h1>
			<div class="va-page-sub"><?php echo esc_html( $range ); ?></div>
		</header>
		<hr class="wp-header-end">

		<div class="va-controls va-yir-controls">
			<?php if ( count( $va_yir_years ) > 1 ) : ?>
				<nav class="va-preset-bar" aria-label="<?php esc_attr_e( 'Year', 'vireo-analytics' ); ?>">
					<?php foreach ( $va_yir_years as $va_y ) : ?>
						<a class="va-preset-btn<?php echo $va_y === $va_year ? ' is-active' : ''; ?>"
							href="<?php echo esc_url( Year_Review::url( $va_y ) ); ?>"
							<?php echo $va_y === $va_year ? 'aria-current="page"' : ''; ?>><?php echo esc_html( (string) $va_y ); ?></a>
					<?php endforeach; ?>
				</nav>
			<?php else : ?>
				<span></span>
			<?php endif; ?>

			<?php if ( '' !== $va_yir['text'] ) : ?>
				<div class="va-yir-actions">
					<span class="va-yir-copied" data-va-yir-copied role="status" aria-live="polite"></span>
					<button type="button" class="button" data-va-yir-copy><?php esc_html_e( 'Copy summary', 'vireo-analytics' ); ?></button>
					<button type="button" class="button" data-va-yir-print><?php esc_html_e( 'Print', 'vireo-analytics' ); ?></button>
					<textarea class="va-sr-only" data-va-yir-text readonly tabindex="-1" aria-hidden="true"><?php echo esc_textarea( $va_yir['text'] ); ?></textarea>
				</div>
			<?php endif; ?>
		</div>

		<?php if ( ! $review['has_data'] ) : ?>
			<div class="va-card">
				<div class="va-empty">
					<div class="va-empty-title">
						<?php
						/* translators: %d: year */
						echo esc_html( sprintf( __( 'Nothing recorded for %d yet', 'vireo-analytics' ), $va_year ) );
						?>
					</div>
					<p class="va-empty-sub">
						<?php esc_html_e( 'This review fills in on its own as visits are recorded; the first ones show up within a minute. If another analytics tool counted this year, its history can be imported and shows here marked as imported.', 'vireo-analytics' ); ?>
						<a href="<?php echo esc_url( Settings::url( 'data' ) ); ?>"><?php esc_html_e( 'Import history', 'vireo-analytics' ); ?></a>
					</p>
				</div>
			</div>
		<?php else : ?>

			<div class="va-row va-row-3">
				<div class="va-card va-yir-stat">
					<h2 class="va-yir-stat-label"><?php esc_html_e( 'Pageviews', 'vireo-analytics' ); ?></h2>
					<div class="va-yir-stat-num"><?php echo esc_html( number_format_i18n( (int) $va_totals['pageviews'] ) ); ?></div>
					<div class="va-yir-stat-sub">
						<?php echo $chip( $pv_change ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above ?>
						<?php echo esc_html( $compare_note ); ?>
					</div>
					<?php if ( null !== $prior && ! $prior['same_origin'] ) : ?>
						<p class="va-yir-note"><?php esc_html_e( 'Part of one year was imported from another tool, which counts in its own way, so read the change as a rough guide.', 'vireo-analytics' ); ?></p>
					<?php endif; ?>
				</div>

				<div class="va-card va-yir-stat">
					<h2 class="va-yir-stat-label"><?php esc_html_e( 'Visitors', 'vireo-analytics' ); ?></h2>
					<div class="va-yir-stat-num"><?php echo esc_html( number_format_i18n( (int) $va_totals['visitors'] ) ); ?></div>
					<div class="va-yir-stat-sub">
						<?php echo $chip( $vis_change ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above ?>
						<?php echo esc_html( $compare_note ); ?>
					</div>
					<p class="va-yir-note"><?php esc_html_e( 'Daily visitors added up. Someone who came on three different days counts three times, because Vireo can\'t recognise anyone from one day to the next.', 'vireo-analytics' ); ?></p>
				</div>

				<div class="va-card va-yir-stat">
					<h2 class="va-yir-stat-label"><?php esc_html_e( 'Busiest month', 'vireo-analytics' ); ?></h2>
					<div class="va-yir-stat-num"><?php echo esc_html( Year_Review::month_name( (int) $review['busiest_month']['month'] ) ); ?></div>
					<div class="va-yir-stat-sub">
						<?php
						/* translators: %s: number of pageviews */
						echo esc_html( sprintf( _n( '%s pageview', '%s pageviews', (int) $review['busiest_month']['pageviews'], 'vireo-analytics' ), number_format_i18n( (int) $review['busiest_month']['pageviews'] ) ) );
						?>
					</div>
					<p class="va-yir-note">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: date such as "Mar 14", 2: number of pageviews */
								_n( 'Busiest day: %1$s, with %2$s pageview.', 'Busiest day: %1$s, with %2$s pageviews.', (int) $review['busiest_day']['pageviews'], 'vireo-analytics' ),
								Year_Review::short_date( (string) $review['busiest_day']['date'] ),
								number_format_i18n( (int) $review['busiest_day']['pageviews'] )
							)
						);
						?>
					</p>
				</div>
			</div>

			<?php
			/**
			 * After the headline numbers. Pro attaches its AI narrative of the year
			 * here; free renders nothing extra.
			 *
			 * @param array $va_yir Everything the screen shows: the summarised review,
			 *                      top lists, source split and the plain-text recap.
			 */
			do_action( 'vireo_year_in_review_after_summary', $va_yir );
			?>

			<div class="va-card">
				<h2 class="va-yir-title"><?php esc_html_e( 'Pageviews by month', 'vireo-analytics' ); ?></h2>
				<div class="va-yir-chart" role="list">
					<?php foreach ( $review['months'] as $month ) : ?>
						<?php
						$va_pv     = (int) $month['pageviews'];
						$va_imp    = (int) $month['imported_pv'];
						$va_height = $max_month > 0 ? round( $va_pv / $max_month * 100, 1 ) : 0;
						$va_split  = $va_pv > 0 ? round( $va_imp / $va_pv * 100, 1 ) : 0;
						$va_name   = Year_Review::month_name( (int) $month['month'] );
						if ( $month['future'] ) {
							$va_desc = __( 'still to come', 'vireo-analytics' );
						} elseif ( ! empty( $month['partial'] ) ) {
							/* translators: %s: number of pageviews */
							$va_desc = sprintf( __( '%s pageviews so far this month', 'vireo-analytics' ), number_format_i18n( $va_pv ) );
						} elseif ( 'imported' === $month['origin'] ) {
							/* translators: %s: number of pageviews */
							$va_desc = sprintf( __( '%s pageviews, imported', 'vireo-analytics' ), number_format_i18n( $va_pv ) );
						} elseif ( 'mixed' === $month['origin'] ) {
							/* translators: 1: number of pageviews, 2: how many of them were imported */
							$va_desc = sprintf( __( '%1$s pageviews, %2$s of them imported', 'vireo-analytics' ), number_format_i18n( $va_pv ), number_format_i18n( $va_imp ) );
						} else {
							/* translators: %s: number of pageviews */
							$va_desc = sprintf( __( '%s pageviews', 'vireo-analytics' ), number_format_i18n( $va_pv ) );
						}
						?>
						<div class="va-yir-col<?php echo $month['future'] ? ' is-future' : ''; ?><?php echo ! empty( $month['partial'] ) ? ' is-partial' : ''; ?>" role="listitem" aria-label="<?php echo esc_attr( $va_name . ': ' . $va_desc ); ?>" title="<?php echo esc_attr( $va_name . ': ' . $va_desc ); ?>">
							<div class="va-yir-val" aria-hidden="true"><?php echo $va_pv > 0 ? esc_html( Dashboard::abbrev( $va_pv ) ) : ''; ?></div>
							<div class="va-yir-track" aria-hidden="true">
								<div class="va-yir-barcol" style="--va-yir-h: <?php echo esc_attr( (string) $va_height ); ?>%;">
									<?php if ( $va_imp > 0 ) : ?>
										<div class="va-yir-bar-imported" style="flex-basis: <?php echo esc_attr( (string) $va_split ); ?>%;"></div>
									<?php endif; ?>
									<?php if ( $va_pv > $va_imp ) : ?>
										<div class="va-yir-bar-own"></div>
									<?php endif; ?>
								</div>
							</div>
							<div class="va-yir-month" aria-hidden="true">
								<?php echo esc_html( wp_date( 'M', (int) gmmktime( 12, 0, 0, (int) $month['month'], 15, 2000 ) ) ); ?>
								<?php if ( ! empty( $month['partial'] ) ) : ?>
									<?php // The month in progress, so its short bar doesn't read as a collapse. ?>
									<span class="va-yir-sofar"><?php esc_html_e( 'so far', 'vireo-analytics' ); ?></span>
								<?php endif; ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
				<?php if ( $any_imported ) : ?>
					<div class="va-yir-legend">
						<span><i class="va-yir-swatch va-yir-bar-own"></i><?php esc_html_e( 'Counted by Vireo Analytics', 'vireo-analytics' ); ?></span>
						<span><i class="va-yir-swatch va-yir-bar-imported"></i><?php esc_html_e( 'Imported', 'vireo-analytics' ); ?></span>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( $va_yir['origin_lines'] ) : ?>
				<div class="va-card">
					<h2 class="va-yir-title"><?php esc_html_e( 'Where these numbers come from', 'vireo-analytics' ); ?></h2>
					<ul class="va-yir-origins">
						<?php foreach ( $va_yir['origin_lines'] as $va_line ) : ?>
							<li><?php echo esc_html( $va_line ); ?></li>
						<?php endforeach; ?>
					</ul>
					<p class="va-yir-note">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: number of imported pageviews, 2: number counted by Vireo */
								__( '%1$s pageviews this year came from imports and %2$s were counted by Vireo. Imported months are as the other tool counted them, which rarely matches Vireo exactly: tools filter bots and count visitors differently.', 'vireo-analytics' ),
								number_format_i18n( (int) $review['imported']['pageviews'] ),
								number_format_i18n( (int) $review['own']['pageviews'] )
							)
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<div class="va-row va-row-3">
				<div class="va-card">
					<h2 class="va-yir-title"><?php esc_html_e( 'Top pages', 'vireo-analytics' ); ?></h2>
					<?php if ( $va_yir['pages'] ) : ?>
						<ol class="va-yir-list">
							<?php foreach ( $va_yir['pages'] as $va_row ) : ?>
								<li>
									<span class="va-yir-list-label">
										<?php if ( $va_row['post_id'] > 0 ) : ?>
											<a href="<?php echo esc_url( add_query_arg( [ 'page' => 'vireo-analytics', 'view' => 'post', 'post_id' => $va_row['post_id'], '_vrn' => Dashboard::view_nonce() ], admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $va_row['label'] ); ?></a>
										<?php else : ?>
											<?php echo esc_html( $va_row['label'] ); ?>
										<?php endif; ?>
									</span>
									<span class="va-yir-list-num"><?php echo esc_html( number_format_i18n( $va_row['pageviews'] ) ); ?></span>
								</li>
							<?php endforeach; ?>
						</ol>
					<?php else : ?>
						<p class="va-yir-none"><?php esc_html_e( 'No page-level data for this year.', 'vireo-analytics' ); ?></p>
					<?php endif; ?>
				</div>

				<div class="va-card">
					<h2 class="va-yir-title">
						<?php
						/* translators: %d: year */
						echo esc_html( sprintf( __( 'Published in %d', 'vireo-analytics' ), $va_year ) );
						?>
					</h2>
					<?php if ( $va_yir['new_posts'] ) : ?>
						<ol class="va-yir-list">
							<?php foreach ( $va_yir['new_posts'] as $va_row ) : ?>
								<li>
									<span class="va-yir-list-label">
										<a href="<?php echo esc_url( add_query_arg( [ 'page' => 'vireo-analytics', 'view' => 'post', 'post_id' => $va_row['post_id'], '_vrn' => Dashboard::view_nonce() ], admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $va_row['label'] ); ?></a>
										<span class="va-yir-list-meta"><?php echo esc_html( $va_row['published'] ); ?></span>
									</span>
									<span class="va-yir-list-num"><?php echo esc_html( number_format_i18n( $va_row['pageviews'] ) ); ?></span>
								</li>
							<?php endforeach; ?>
						</ol>
					<?php else : ?>
						<p class="va-yir-none"><?php esc_html_e( 'Nothing published this year has been viewed yet.', 'vireo-analytics' ); ?></p>
					<?php endif; ?>
				</div>

				<?php
				$va_cov      = isset( $va_yir['coverage'] ) && is_array( $va_yir['coverage'] ) ? $va_yir['coverage'] : null;
				$va_cov_note = '';
				if ( null !== $va_cov && '' !== $va_cov['from'] && $va_cov['from'] > $review['start'] ) {
					/* translators: %s: a date such as "Aug 1, 2026" */
					$va_cov_note = sprintf( __( 'Countries from %s onward. Earlier history has no country data.', 'vireo-analytics' ), vireo_format_day( (string) $va_cov['from'], 'M j, Y' ) );
				} elseif ( null !== $va_cov && null !== $va_cov['share'] && $va_cov['share'] < 99.5 ) {
					/* translators: %s: percentage of visitors whose country is known */
					$va_cov_note = sprintf( __( 'Country known for %s%% of visitors.', 'vireo-analytics' ), number_format_i18n( (float) $va_cov['share'], 0 ) );
				}
				?>
				<div class="va-card">
					<h2 class="va-yir-title" title="<?php esc_attr_e( 'Visitors, added up day by day.', 'vireo-analytics' ); ?>"><?php esc_html_e( 'Top countries', 'vireo-analytics' ); ?></h2>
					<?php if ( $va_yir['countries'] ) : ?>
						<?php if ( '' !== $va_cov_note ) : ?>
							<p class="va-yir-note va-yir-note-top"><?php echo esc_html( $va_cov_note ); ?></p>
						<?php endif; ?>
						<ol class="va-yir-list">
							<?php foreach ( $va_yir['countries'] as $va_row ) : ?>
								<li>
									<span class="va-yir-list-label">
										<?php echo esc_html( (string) $va_row['name'] ); ?>
										<?php if ( isset( $va_row['share'] ) && null !== $va_row['share'] ) : ?>
											<span class="va-yir-list-meta"><?php echo esc_html( number_format_i18n( (float) $va_row['share'], (float) $va_row['share'] < 10 ? 1 : 0 ) . '%' ); ?></span>
										<?php endif; ?>
									</span>
									<span class="va-yir-list-num"><?php echo esc_html( number_format_i18n( (int) $va_row['visitors'] ) ); ?></span>
								</li>
							<?php endforeach; ?>
						</ol>
					<?php else : ?>
						<p class="va-yir-none"><?php esc_html_e( 'No country data for this year.', 'vireo-analytics' ); ?></p>
					<?php endif; ?>
				</div>
			</div>

			<div class="va-row va-row-3">
				<div class="va-card">
					<h2 class="va-yir-title"><?php esc_html_e( 'How people arrived, in pageviews', 'vireo-analytics' ); ?></h2>
					<?php if ( $va_yir['sources'] ) : ?>
						<ul class="va-yir-list">
							<?php foreach ( $va_yir['sources'] as $va_row ) : ?>
								<li>
									<span class="va-yir-list-label">
										<?php echo esc_html( $source_labels[ $va_row['type'] ] ?? $va_row['type'] ); ?>
										<?php if ( '' !== $va_row['top'] ) : ?>
											<span class="va-yir-list-meta">
												<?php
												/* translators: 1: the biggest referrer in this group, 2: its pageviews */
												echo esc_html( sprintf( __( 'mostly %1$s (%2$s)', 'vireo-analytics' ), $va_row['top'], number_format_i18n( $va_row['top_views'] ) ) );
												?>
											</span>
										<?php endif; ?>
									</span>
									<span class="va-yir-list-num"><?php echo esc_html( number_format_i18n( $va_row['pageviews'] ) ); ?></span>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php else : ?>
						<p class="va-yir-none"><?php esc_html_e( 'No referrer data for this year.', 'vireo-analytics' ); ?></p>
					<?php endif; ?>
				</div>

				<?php if ( null !== $va_yir['gsc'] ) : ?>
					<div class="va-card">
						<h2 class="va-yir-title"><?php esc_html_e( 'Top Google searches', 'vireo-analytics' ); ?></h2>
						<p class="va-yir-note va-yir-note-top">
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: clicks from Google search, 2: impressions */
									__( '%1$s clicks from %2$s appearances in Google results.', 'vireo-analytics' ),
									number_format_i18n( (int) $va_yir['gsc']['clicks'] ),
									number_format_i18n( (int) $va_yir['gsc']['impressions'] )
								)
							);
							?>
						</p>
						<ol class="va-yir-list">
							<?php foreach ( $va_yir['queries'] as $va_row ) : ?>
								<li>
									<span class="va-yir-list-label"><?php echo esc_html( (string) $va_row['query'] ); ?></span>
									<span class="va-yir-list-num"><?php echo esc_html( number_format_i18n( (int) $va_row['clicks'] ) ); ?></span>
								</li>
							<?php endforeach; ?>
						</ol>
					</div>
				<?php endif; ?>

				<?php if ( $va_yir['ai']['crawled'] > 0 || $va_yir['ai']['referred'] > 0 ) : ?>
					<div class="va-card">
						<h2 class="va-yir-title"><?php esc_html_e( 'AI assistants', 'vireo-analytics' ); ?></h2>
						<div class="va-yir-ai">
							<div>
								<div class="va-yir-stat-num"><?php echo esc_html( number_format_i18n( (int) $va_yir['ai']['crawled'] ) ); ?></div>
								<div class="va-yir-stat-sub"><?php esc_html_e( 'requests from AI crawlers', 'vireo-analytics' ); ?></div>
							</div>
							<div>
								<div class="va-yir-stat-num"><?php echo esc_html( number_format_i18n( (int) $va_yir['ai']['referred'] ) ); ?></div>
								<div class="va-yir-stat-sub"><?php esc_html_e( 'readers sent back', 'vireo-analytics' ); ?></div>
							</div>
						</div>
						<?php if ( '' !== $va_yir['ai']['from'] ) : ?>
							<p class="va-yir-note">
								<?php
								/* translators: %s: date Vireo started counting, such as "Dec 15" */
								echo esc_html( sprintf( __( 'Since %s, when Vireo started counting. Imported history doesn\'t record crawlers, so earlier months are left out of both numbers.', 'vireo-analytics' ), Year_Review::short_date( $va_yir['ai']['from'] ) ) );
								?>
							</p>
						<?php endif; ?>
						<?php if ( null !== $va_yir['ai']['rate'] && $va_yir['ai']['referred'] > 0 ) : ?>
							<p class="va-yir-note">
								<?php
								/* translators: %s: pages crawled per reader referred */
								echo esc_html( sprintf( __( '%s crawler requests for every reader sent.', 'vireo-analytics' ), number_format_i18n( (float) $va_yir['ai']['rate'], $va_yir['ai']['rate'] >= 10 ? 0 : 1 ) ) );
								?>
							</p>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</div>

		<?php endif; ?>

	</div>
</div>
