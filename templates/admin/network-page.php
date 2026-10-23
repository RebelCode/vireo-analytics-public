<?php
/**
 * Network overview — every site on the network, busiest first.
 *
 * Locals: $va_network_sites (array from Network::rollup)
 *         $va_network_days  (int)
 *         $va_network_start, $va_network_end (YYYY-MM-DD)
 *
 * Sites where the plugin has never run are left out rather than shown as
 * zero, because "no data" and "no traffic" are different answers.
 */

defined( 'ABSPATH' ) || exit;

$va_net_pageviews = 0;
$va_net_visitors  = 0;
foreach ( (array) $va_network_sites as $va_net_row ) {
	$va_net_pageviews += (int) $va_net_row['pageviews'];
	$va_net_visitors  += (int) $va_net_row['visitors'];
}

$va_net_base = add_query_arg( 'page', \VireoAnalytics\Network::PAGE, network_admin_url( 'admin.php' ) );
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Analytics across the network', 'vireo-analytics' ); ?></h1>

	<p>
		<?php foreach ( [ 7, 30, 90 ] as $va_net_choice ) : ?>
			<a class="button<?php echo $va_net_choice === $va_network_days ? ' button-primary' : ''; ?>"
				href="<?php echo esc_url( add_query_arg( 'days', $va_net_choice, $va_net_base ) ); ?>">
				<?php
				printf(
					/* translators: %d: number of days */
					esc_html( _n( 'Last %d day', 'Last %d days', $va_net_choice, 'vireo-analytics' ) ),
					(int) $va_net_choice
				);
				?>
			</a>
		<?php endforeach; ?>

		<a class="button"
			href="<?php echo esc_url( wp_nonce_url( add_query_arg( [ 'days' => $va_network_days, 'refresh' => 1 ], $va_net_base ), 'vireo_network_refresh' ) ); ?>">
			<?php esc_html_e( 'Refresh', 'vireo-analytics' ); ?>
		</a>
	</p>

	<?php if ( ! empty( $va_network_fanout['truncated'] ) ) : ?>
		<div class="notice notice-warning inline">
			<p>
				<?php
				printf(
					/* translators: 1: sites read, 2: sites on the network */
					esc_html__( 'This network has more sites than one page can query. Showing the first %1$s of %2$s.', 'vireo-analytics' ),
					esc_html( number_format_i18n( (int) $va_network_fanout['shown'] ) ),
					esc_html( number_format_i18n( (int) $va_network_fanout['total'] ) )
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( empty( $va_network_sites ) ) : ?>
		<p><?php esc_html_e( 'No site on the network has recorded anything yet.', 'vireo-analytics' ); ?></p>
	<?php else : ?>
		<p class="description">
			<?php
			// Three counts in one sentence, so each carries its own plural form
			// and the sentence only arranges them.
			$va_net_sites = count( $va_network_sites );
			printf(
				/* translators: 1: pageview count with its noun, 2: visitor count with its noun, 3: site count with its noun, 4: start date, 5: end date */
				esc_html__( '%1$s and %2$s across %3$s, %4$s to %5$s.', 'vireo-analytics' ),
				sprintf(
					/* translators: %s: pageview count. */
					esc_html( _n( '%s pageview', '%s pageviews', (int) $va_net_pageviews, 'vireo-analytics' ) ),
					'<strong>' . esc_html( number_format_i18n( $va_net_pageviews ) ) . '</strong>'
				),
				sprintf(
					/* translators: %s: visitor count. */
					esc_html( _n( '%s visitor', '%s visitors', (int) $va_net_visitors, 'vireo-analytics' ) ),
					'<strong>' . esc_html( number_format_i18n( $va_net_visitors ) ) . '</strong>'
				),
				esc_html(
					sprintf(
						/* translators: %s: site count. */
						_n( '%s site', '%s sites', $va_net_sites, 'vireo-analytics' ),
						number_format_i18n( $va_net_sites )
					)
				),
				esc_html( $va_network_start ),
				esc_html( $va_network_end )
			);
			?>
		</p>

		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Site', 'vireo-analytics' ); ?></th>
					<th scope="col" style="text-align:right"><?php esc_html_e( 'Visitors', 'vireo-analytics' ); ?></th>
					<th scope="col" style="text-align:right"><?php esc_html_e( 'Pageviews', 'vireo-analytics' ); ?></th>
					<th scope="col"></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $va_network_sites as $va_net_row ) : ?>
				<tr>
					<td>
						<strong><?php echo esc_html( '' !== $va_net_row['name'] ? $va_net_row['name'] : $va_net_row['url'] ); ?></strong><br />
						<span class="description"><?php echo esc_html( $va_net_row['url'] ); ?></span>
					</td>
					<td style="text-align:right"><?php echo esc_html( number_format_i18n( (int) $va_net_row['visitors'] ) ); ?></td>
					<td style="text-align:right"><?php echo esc_html( number_format_i18n( (int) $va_net_row['pageviews'] ) ); ?></td>
					<td>
						<a href="<?php echo esc_url( get_admin_url( (int) $va_net_row['blog_id'], 'admin.php?page=vireo-analytics' ) ); ?>">
							<?php esc_html_e( 'Open', 'vireo-analytics' ); ?>
						</a>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<p class="description">
			<?php esc_html_e( 'Totals are cached for fifteen minutes, since each one is a separate query against that site.', 'vireo-analytics' ); ?>
		</p>
	<?php endif; ?>
</div>
