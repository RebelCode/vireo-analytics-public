<?php
/**
 * Per-post detail view.
 *
 * Expected locals (set by Post_Detail::render):
 *   $va_post, $va_post_url, $va_post_title, $va_back_url, $va_page_title
 *   $lifetime, $daily, $period, $start, $end, $preset
 *   $va_comments, $va_comment_rate
 *   $va_psi (PageSpeed::view for this post)
 */

defined( 'ABSPATH' ) || exit;


// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template partial included inside a render method; locals are method-scoped at runtime, not true globals.
use VireoAnalytics\Dashboard;

$lifetime_pv    = (int) ( $lifetime['pageviews'] ?? 0 );
$lifetime_vis   = (int) ( $lifetime['visitors'] ?? 0 );
$first_seen     = $lifetime['first_seen'] ?? null;
$best_day       = $lifetime['best_day'] ?? null;

$period_pv  = (int) ( $period['pageviews'] ?? 0 );
$period_vis = (int) ( $period['visitors'] ?? 0 );
?>
<div class="wrap va-wrap">
	<div class="va-root va-post-detail" data-va-start="<?php echo esc_attr( $start ); ?>" data-va-end="<?php echo esc_attr( $end ); ?>">

		<header class="va-page-head">
			<div class="va-post-head-row">
				<a class="va-back-link" href="<?php echo esc_url( $va_back_url ); ?>">
					<?php esc_html_e( '&larr; Overview', 'vireo-analytics' ); ?>
				</a>
			</div>
			<h1 class="va-page-title"><?php echo esc_html( $va_post_title ); ?></h1>
			<div class="va-page-sub">
				<?php if ( '' !== $va_post_url ) : ?>
					<a href="<?php echo esc_url( $va_post_url ); ?>" target="_blank" rel="noopener">
						<?php echo esc_html( $va_post_url ); ?>
					</a>
				<?php else : ?>
					<?php echo esc_html( '/?p=' . (int) $va_post->ID ); ?>
				<?php endif; ?>
			</div>
		</header>
		<hr class="wp-header-end">

		<?php
		$va_range_args = [ 'page' => 'vireo-analytics', 'view' => 'post', 'post_id' => (int) $post_id ];
		include VIREO_DIR . 'templates/dashboard/detail-range.php';
		?>

		<div class="va-row va-row-3 va-hero">

			<div class="va-card va-hero-card">
				<h2 class="va-card-title"><?php esc_html_e( 'Lifetime pageviews', 'vireo-analytics' ); ?></h2>
				<div class="va-hero-body">
					<div class="va-hero-num" title="<?php echo esc_attr( number_format_i18n( $lifetime_pv ) ); ?>">
						<?php echo esc_html( Dashboard::abbrev( $lifetime_pv ) ); ?>
					</div>
				</div>
				<?php if ( $first_seen ) : ?>
					<div class="va-hero-sub">
						<?php
						printf(
							/* translators: %s: first-seen date */
							esc_html__( 'since %s', 'vireo-analytics' ),
							esc_html( vireo_format_day( (string) $first_seen, 'M j, Y' ) )
						);
						?>
					</div>
				<?php endif; ?>
			</div>

			<div class="va-card va-hero-card">
				<h2 class="va-card-title"><?php esc_html_e( 'Lifetime visitors', 'vireo-analytics' ); ?></h2>
				<div class="va-hero-body">
					<div class="va-hero-num" title="<?php echo esc_attr( number_format_i18n( $lifetime_vis ) ); ?>">
						<?php echo esc_html( Dashboard::abbrev( $lifetime_vis ) ); ?>
					</div>
				</div>
				<div class="va-hero-sub"><?php esc_html_e( 'daily visitors, added up', 'vireo-analytics' ); ?></div>
			</div>

			<div class="va-card va-hero-card">
				<h2 class="va-card-title"><?php esc_html_e( 'Best day', 'vireo-analytics' ); ?></h2>
				<div class="va-hero-body">
					<?php if ( $best_day ) : ?>
						<div class="va-hero-num" title="<?php echo esc_attr( number_format_i18n( (int) $best_day['pageviews'] ) ); ?>">
							<?php echo esc_html( Dashboard::abbrev( (int) $best_day['pageviews'] ) ); ?>
						</div>
					<?php else : ?>
						<div class="va-hero-num">&mdash;</div>
					<?php endif; ?>
				</div>
				<?php if ( $best_day ) : ?>
					<div class="va-hero-sub">
						<?php echo esc_html( vireo_format_day( (string) $best_day['date'], 'M j, Y' ) ); ?>
					</div>
				<?php else : ?>
					<div class="va-hero-sub"><?php esc_html_e( 'no traffic yet', 'vireo-analytics' ); ?></div>
				<?php endif; ?>
			</div>

		</div>

		<div class="va-row va-row-1">
			<div class="va-card va-chart-card">
				<div class="va-card-head">
					<h2 class="va-card-title">
						<?php
						printf(
							/* translators: 1: start date, 2: end date */
							esc_html__( 'Daily traffic · %1$s &ndash; %2$s', 'vireo-analytics' ),
							esc_html( vireo_format_day( (string) $start, 'M j' ) ),
							esc_html( vireo_format_day( (string) $end, 'M j, Y' ) )
						);
						?>
					</h2>
					<div class="va-chart-legend">
						<span class="va-legend-dot va-legend-pv"></span><?php esc_html_e( 'Pageviews', 'vireo-analytics' ); ?>
						<span class="va-legend-dot va-legend-vis"></span><?php esc_html_e( 'Visitors', 'vireo-analytics' ); ?>
					</div>
				</div>

				<?php
				$has_data = false;
				foreach ( $daily as $d ) {
					if ( (int) ( $d['pageviews'] ?? 0 ) > 0 ) { $has_data = true; break; }
				}
				?>
				<div class="va-chart-host" data-va-chart
					data-va-series="<?php echo esc_attr( wp_json_encode( $daily ) ); ?>">
					<?php if ( ! $has_data ) : ?>
						<div class="va-empty">
							<div class="va-empty-title"><?php esc_html_e( 'No traffic in this range', 'vireo-analytics' ); ?></div>
							<div class="va-empty-sub"><?php esc_html_e( 'Pageviews for this post will plot here as they are recorded.', 'vireo-analytics' ); ?></div>
						</div>
					<?php endif; ?>
				</div>

				<div class="va-post-period">
					<span>
						<strong><?php echo esc_html( number_format_i18n( $period_pv ) ); ?></strong>
						<?php esc_html_e( 'pageviews in range', 'vireo-analytics' ); ?>
					</span>
					<span>
						<strong><?php echo esc_html( number_format_i18n( $period_vis ) ); ?></strong>
						<?php esc_html_e( 'visitors in range', 'vireo-analytics' ); ?>
					</span>
					<span>
						<strong><?php echo esc_html( number_format_i18n( (int) $va_comments ) ); ?></strong>
						<?php echo esc_html( _n( 'comment in range', 'comments in range', (int) $va_comments, 'vireo-analytics' ) ); ?>
					</span>
					<?php if ( null !== $va_comment_rate ) : ?>
						<span>
							<strong><?php echo esc_html( number_format_i18n( (float) $va_comment_rate, 1 ) ); ?></strong>
							<?php esc_html_e( 'comments per 1,000 views', 'vireo-analytics' ); ?>
						</span>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<?php if ( ! empty( $va_psi ) ) : ?>
			<div class="va-card va-post-speed">
				<h2 class="va-card-title"><?php esc_html_e( 'Page speed for this post', 'vireo-analytics' ); ?></h2>
				<p class="va-vitals-footnote">
					<?php esc_html_e( 'Speed from your real visitors is measured for the whole site, not per post.', 'vireo-analytics' ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=vireo-analytics&tab=speed' ) ); ?>"><?php esc_html_e( 'See it on the Overview', 'vireo-analytics' ); ?></a>
				</p>
				<?php
				$va_psi_title     = __( 'This post, tested by Google', 'vireo-analytics' );
				$va_psi_cta_title = __( 'Test this post with Google', 'vireo-analytics' );
				include VIREO_DIR . 'templates/dashboard/pagespeed.php';
				?>
			</div>
		<?php endif; ?>

		<?php
		// Top referrers for this specific post. Data starts flowing from the
		// day the va_post_referrer_stats table is installed; the notice below
		// makes that explicit for historical posts.
		$va_referrers     = isset( $referrers ) && is_array( $referrers ) ? $referrers : [];
		$va_ref_info      = isset( $va_referrer_info ) && is_array( $va_referrer_info ) ? $va_referrer_info : [ 'has_data' => false, 'first_seen' => null ];
		?>
		<div class="va-card va-board">
			<div class="va-card-head">
				<h2 class="va-card-title"><?php esc_html_e( 'Top referrers for this post', 'vireo-analytics' ); ?></h2>
			</div>
			<?php if ( empty( $va_referrers ) ) : ?>
				<div class="va-empty va-empty-inline">
					<?php if ( $va_ref_info['has_data'] ) : ?>
						<div class="va-empty-title"><?php esc_html_e( 'No referrers in this range', 'vireo-analytics' ); ?></div>
						<div class="va-empty-sub"><?php esc_html_e( 'This post had no inbound referrers during the selected period.', 'vireo-analytics' ); ?></div>
					<?php else : ?>
						<div class="va-empty-title"><?php esc_html_e( 'Per-post referrer data is being collected', 'vireo-analytics' ); ?></div>
						<div class="va-empty-sub">
							<?php esc_html_e( 'Referrers per individual post started tracking with a recent update. Data will appear here as new visitors arrive.', 'vireo-analytics' ); ?>
						</div>
					<?php endif; ?>
				</div>
			<?php else :
				if ( ! empty( $va_ref_info['first_seen'] ) ) :
			?>
				<p class="va-board-note">
					<?php
					printf(
						/* translators: %s: date when per-post referrer tracking began */
						esc_html__( 'Referrer data for this post starts from %s.', 'vireo-analytics' ),
						esc_html( vireo_format_day( (string) $va_ref_info['first_seen'], 'M j, Y' ) )
					);
					?>
				</p>
			<?php endif; ?>
				<ul class="va-board-list">
					<?php foreach ( $va_referrers as $r ) :
						$host  = (string) $r['host'];
						$sub   = vireo_referrer_subtitle( $host );
						$count = (int) $r['pageviews'];
					?>
						<li class="va-board-row">
							<span class="va-board-lead">
								<span class="va-board-label">
									<span class="va-board-label-main" title="<?php echo esc_attr( $host ); ?>"><?php echo esc_html( vireo_referrer_label( $host ) ); ?></span>
									<?php if ( '' !== $sub ) : ?>
										<span class="va-board-label-sub"><?php echo esc_html( $sub ); ?></span>
									<?php endif; ?>
								</span>
							</span>
							<span class="va-board-tail">
								<span class="va-board-count" title="<?php echo esc_attr( number_format_i18n( $count ) ); ?>"><?php echo esc_html( Dashboard::abbrev( $count ) ); ?></span>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>

		<?php
		$va_post_queries = isset( $va_post_queries ) && is_array( $va_post_queries ) ? $va_post_queries : [];
		$va_gsc_on       = ! empty( $va_gsc_on );
		?>
		<div class="va-card va-board va-post-searches">
			<div class="va-card-head">
				<h2 class="va-card-title"><?php esc_html_e( 'Searches that found this post', 'vireo-analytics' ); ?></h2>
			</div>
			<?php if ( ! $va_gsc_on ) : ?>
				<div class="va-empty va-empty-inline">
					<div class="va-empty-title"><?php esc_html_e( 'See what people searched for', 'vireo-analytics' ); ?></div>
					<div class="va-empty-sub">
						<?php esc_html_e( 'Only Search Console knows which searches showed this post. Connect it to list them here.', 'vireo-analytics' ); ?>
					</div>
					<p class="va-empty-action">
						<a class="button" href="<?php echo esc_url( \VireoAnalytics\Settings::url( 'integrations' ) . '#vireo-gsc' ); ?>"><?php esc_html_e( 'Connect Search Console', 'vireo-analytics' ); ?></a>
					</p>
				</div>
			<?php elseif ( empty( $va_post_queries ) ) : ?>
				<div class="va-empty va-empty-inline">
					<div class="va-empty-title"><?php esc_html_e( 'No searches for this post in this range', 'vireo-analytics' ); ?></div>
					<div class="va-empty-sub"><?php esc_html_e( 'Google reports search data about three days late, so the most recent days are always empty.', 'vireo-analytics' ); ?></div>
				</div>
			<?php else : ?>
				<div class="va-board-colhead">
					<span><?php esc_html_e( 'Search', 'vireo-analytics' ); ?></span>
					<span><?php esc_html_e( 'Clicks · position', 'vireo-analytics' ); ?></span>
				</div>
				<ul class="va-board-list">
					<?php foreach ( $va_post_queries as $va_q ) : ?>
						<li class="va-board-row">
							<span class="va-board-lead">
								<span class="va-board-label">
									<span class="va-board-label-main" title="<?php echo esc_attr( $va_q['query'] ); ?>"><?php echo esc_html( $va_q['query'] ); ?></span>
								</span>
							</span>
							<span class="va-board-tail">
								<span class="va-board-count" title="<?php echo esc_attr( sprintf( /* translators: %s: number of impressions */ _n( 'Shown %s time', 'Shown %s times', (int) $va_q['impressions'], 'vireo-analytics' ), number_format_i18n( (int) $va_q['impressions'] ) ) ); ?>"><?php echo esc_html( number_format_i18n( (int) $va_q['clicks'] ) ); ?></span>
								<span class="va-board-pos"><?php echo esc_html( number_format_i18n( (float) $va_q['position'], 1 ) ); ?></span>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>

	</div>
</div>
