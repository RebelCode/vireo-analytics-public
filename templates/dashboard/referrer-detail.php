<?php
/**
 * Per-referrer detail view.
 *
 * Expected locals (set by Referrer_Detail::render):
 *   $va_host, $va_back_url, $va_page_title
 *   $daily, $period, $top_pages, $start, $end, $preset
 */

defined( 'ABSPATH' ) || exit;


// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template partial included inside a render method; locals are method-scoped at runtime, not true globals.
use VireoAnalytics\Dashboard;

$period_pv  = (int) ( $period['pageviews'] ?? 0 );
$period_vis = (int) ( $period['visitors'] ?? 0 );
?>
<div class="wrap va-wrap">
	<div class="va-root va-referrer-detail" data-va-start="<?php echo esc_attr( $start ); ?>" data-va-end="<?php echo esc_attr( $end ); ?>">

		<header class="va-page-head">
			<div class="va-post-head-row">
				<a class="va-back-link" href="<?php echo esc_url( $va_back_url ); ?>">
					<?php esc_html_e( '&larr; Overview', 'vireo-analytics' ); ?>
				</a>
			</div>
			<h1 class="va-page-title"><?php echo esc_html( vireo_referrer_label( $va_host ) ); ?></h1>
			<div class="va-page-sub">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: start date, 2: end date */
						__( 'Traffic from this referrer · %1$s to %2$s', 'vireo-analytics' ),
						vireo_format_day( (string) $start, 'M j, Y' ),
						vireo_format_day( (string) $end, 'M j, Y' )
					)
				);
				echo \VireoAnalytics\Dashboard::site_subtitle(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside
				?>
			</div>
		</header>
		<hr class="wp-header-end">

		<?php
		$va_range_args = [ 'page' => 'vireo-analytics', 'view' => 'referrer', 'url' => (string) $va_host ];
		include VIREO_DIR . 'templates/dashboard/detail-range.php';
		?>

		<div class="va-row va-row-2 va-hero">

			<div class="va-card va-hero-card">
				<h2 class="va-card-title"><?php esc_html_e( 'Pageviews in range', 'vireo-analytics' ); ?></h2>
				<div class="va-hero-body">
					<div class="va-hero-num" title="<?php echo esc_attr( number_format_i18n( $period_pv ) ); ?>">
						<?php echo esc_html( Dashboard::abbrev( $period_pv ) ); ?>
					</div>
				</div>
			</div>

			<div class="va-card va-hero-card">
				<h2 class="va-card-title"><?php esc_html_e( 'Visitors in range', 'vireo-analytics' ); ?></h2>
				<div class="va-hero-body">
					<div class="va-hero-num" title="<?php echo esc_attr( number_format_i18n( $period_vis ) ); ?>">
						<?php echo esc_html( Dashboard::abbrev( $period_vis ) ); ?>
					</div>
				</div>
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
							<div class="va-empty-title"><?php esc_html_e( 'No traffic from this referrer in this range', 'vireo-analytics' ); ?></div>
							<div class="va-empty-sub"><?php esc_html_e( 'Pageviews sent by this source will plot here as they are recorded.', 'vireo-analytics' ); ?></div>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<?php if ( ! empty( $top_pages ) ) : ?>
		<div class="va-row va-row-1">
			<div class="va-card va-board">
				<h2 class="va-card-title"><?php esc_html_e( 'Posts it sent people to', 'vireo-analytics' ); ?></h2>
				<p class="va-board-sub"><?php esc_html_e( 'Pageviews on each post from this referrer. Home and archive pages aren\'t broken down this way.', 'vireo-analytics' ); ?></p>
				<ul class="va-board-list">
					<?php foreach ( $top_pages as $row ) :
						$title   = (string) $row['title'];
						$path    = (string) $row['path'];
						$url     = (string) $row['url'];
						$post_id = (int) $row['post_id'];
						$title   = \VireoAnalytics\Dashboard::page_label( $title, $path );

						$count     = (int) $row['pageviews'];
						// A connected site's pages are paths, never posts here.
						$stats_url = $post_id > 0 && ! \VireoAnalytics\Sites::is_connected()
							? add_query_arg(
								[ 'page' => 'vireo-analytics', 'view' => 'post', 'post_id' => $post_id, '_vrn' => \VireoAnalytics\Dashboard::view_nonce() ],
								admin_url( 'admin.php' )
							)
							: '';
					?>
						<li class="va-board-row">
							<span class="va-board-lead">
								<span class="va-board-icon va-board-icon-path" aria-hidden="true">/</span>
								<span class="va-board-label">
									<span class="va-board-label-main" title="<?php echo esc_attr( $title ); ?>">
										<?php if ( '' !== $stats_url ) : ?>
											<a href="<?php echo esc_url( $stats_url ); ?>"><?php echo esc_html( $title ); ?></a>
										<?php elseif ( $url ) : ?>
											<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $title ); ?></a>
										<?php else : ?>
											<?php echo esc_html( $title ); ?>
										<?php endif; ?>
									</span>
								</span>
							</span>
							<span class="va-board-tail">
								<span class="va-board-count" title="<?php echo esc_attr( number_format_i18n( $count ) ); ?>"><?php echo esc_html( Dashboard::abbrev( $count ) ); ?></span>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>
		<?php endif; ?>

	</div>
</div>
