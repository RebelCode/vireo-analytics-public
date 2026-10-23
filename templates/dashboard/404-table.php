<?php
/**
 * Dashboard — 404 errors.
 *
 * Two reports, not one. A 404 reached from one of your own pages is a broken
 * link you can go and fix, and the referring page is where the fix goes.
 * Everything else is the internet knocking: someone else's stale link, or far
 * more often a vulnerability scanner walking a wordlist. Mixed together, the
 * handful of actionable rows are buried under thousands of probes.
 *
 * Locals: $rows (flat list, used when the split isn't available)
 *         $prior_by_path (array<string, int> keyed by path)
 *         $broken_links (rows with an internal referrer)
 *         $probe_rows (rows without one)
 *         $split_404 (bool — false on installs predating the internal_ref column)
 */

defined( 'ABSPATH' ) || exit;


// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template partial included inside a render method; locals are method-scoped at runtime, not true globals.
use VireoAnalytics\Dashboard;

$broken_links = isset( $broken_links ) ? (array) $broken_links : [];
$probe_rows   = isset( $probe_rows ) ? (array) $probe_rows : [];
$split_404    = ! empty( $split_404 );
?>
<div class="va-card va-table-card">
	<h2 class="va-card-title"><?php esc_html_e( 'Broken links', 'vireo-analytics' ); ?></h2>

	<?php if ( ! $split_404 ) : ?>

		<?php if ( empty( $rows ) ) : ?>
			<div class="va-empty va-empty-inline">
				<div class="va-empty-title"><?php esc_html_e( 'No 404 errors in this period', 'vireo-analytics' ); ?></div>
				<div class="va-empty-sub"><?php esc_html_e( 'Broken links and missing pages hit by visitors will appear here.', 'vireo-analytics' ); ?></div>
			</div>
		<?php else : ?>
			<table class="va-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Path', 'vireo-analytics' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Top referrer', 'vireo-analytics' ); ?></th>
						<th scope="col" class="va-num"><?php esc_html_e( 'Hits', 'vireo-analytics' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) :
						$path     = (string) $row->path;
						$hits     = (int) $row->hits;
						$referrer = isset( $row->top_referrer ) ? (string) $row->top_referrer : '';
						$prior    = isset( $prior_by_path[ $path ] ) ? (int) $prior_by_path[ $path ] : 0;
						// Rise in 404 hits is BAD, so direction = up_bad.
						$chip     = Dashboard::delta_chip( $hits - $prior, $prior, 'up_bad' );
					?>
						<tr>
							<td>
								<span title="<?php echo esc_attr( $path ); ?>"><?php echo esc_html( $path ); ?></span>
							</td>
							<td>
								<?php if ( '' !== $referrer ) : ?>
									<span title="<?php echo esc_attr( $referrer ); ?>"><?php echo esc_html( $referrer ); ?></span>
								<?php else : ?>
									<span class="va-muted">&mdash;</span>
								<?php endif; ?>
							</td>
							<td class="va-num">
								<?php echo esc_html( number_format_i18n( $hits ) ); ?>
								<?php if ( '' !== $chip ) { echo ' ' . wp_kses_post( $chip ); } ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

	<?php else : ?>

		<?php if ( empty( $broken_links ) ) : ?>
			<div class="va-empty va-empty-inline">
				<div class="va-empty-title"><?php esc_html_e( 'No broken links', 'vireo-analytics' ); ?></div>
				<div class="va-empty-sub">
					<?php esc_html_e( 'Nothing on your site links to a page that is missing.', 'vireo-analytics' ); ?>
				</div>
			</div>
		<?php else : ?>
			<p class="va-card-sub">
				<?php esc_html_e( 'Pages on your site linking somewhere missing. Fix the linking page.', 'vireo-analytics' ); ?>
			</p>
			<table class="va-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Missing page', 'vireo-analytics' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Linked from', 'vireo-analytics' ); ?></th>
						<th scope="col" class="va-num"><?php esc_html_e( 'Hits', 'vireo-analytics' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $broken_links as $row ) :
						$path   = (string) $row->path;
						$hits   = (int) $row->hits;
						$from   = isset( $row->linked_from ) ? (string) $row->linked_from : '';
						$others = isset( $row->linking_pages ) ? max( 0, (int) $row->linking_pages - 1 ) : 0;
					?>
						<tr>
							<td>
								<span title="<?php echo esc_attr( $path ); ?>"><?php echo esc_html( $path ); ?></span>
							</td>
							<td>
								<?php if ( '' !== $from ) : ?>
									<a href="<?php echo esc_url( home_url( $from ) ); ?>" title="<?php echo esc_attr( $from ); ?>"><?php echo esc_html( $from ); ?></a>
									<?php if ( $others > 0 ) : ?>
										<span class="va-muted">
											<?php
											printf(
												/* translators: %s: number of additional pages linking to the missing page */
												esc_html( _n( '+%s more page', '+%s more pages', $others, 'vireo-analytics' ) ),
												esc_html( number_format_i18n( $others ) )
											);
											?>
										</span>
									<?php endif; ?>
								<?php else : ?>
									<span class="va-muted">&mdash;</span>
								<?php endif; ?>
							</td>
							<td class="va-num"><?php echo esc_html( number_format_i18n( $hits ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ( ! empty( $probe_rows ) ) : ?>
			<h3 class="va-card-title" style="margin-top:1.5em;"><?php esc_html_e( 'Requests from elsewhere', 'vireo-analytics' ); ?></h3>
			<p class="va-card-sub">
				<?php esc_html_e( 'Nothing on your site links to these. Mostly automated scanners looking for software you do not run; occasionally a stale link elsewhere worth a redirect.', 'vireo-analytics' ); ?>
			</p>
			<table class="va-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Path', 'vireo-analytics' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Referrer', 'vireo-analytics' ); ?></th>
						<th scope="col" class="va-num"><?php esc_html_e( 'Hits', 'vireo-analytics' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $probe_rows as $row ) :
						$path     = (string) $row->path;
						$hits     = (int) $row->hits;
						$referrer = isset( $row->top_referrer ) ? (string) $row->top_referrer : '';
					?>
						<tr>
							<td>
								<span title="<?php echo esc_attr( $path ); ?>"><?php echo esc_html( $path ); ?></span>
							</td>
							<td>
								<?php if ( '' !== $referrer ) : ?>
									<span title="<?php echo esc_attr( $referrer ); ?>"><?php echo esc_html( $referrer ); ?></span>
								<?php else : ?>
									<span class="va-muted"><?php esc_html_e( 'no referrer', 'vireo-analytics' ); ?></span>
								<?php endif; ?>
							</td>
							<td class="va-num"><?php echo esc_html( number_format_i18n( $hits ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

	<?php endif; ?>
</div>
