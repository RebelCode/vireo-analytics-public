<?php
/**
 * Leaderboard — custom events.
 *
 * When no `event_name` drill-down is requested, renders the top event names
 * for the range. When a drill-down is active ($event_detail_name not empty),
 * renders per-value rows for that event.
 *
 * Locals: $events (rows from Events::top_events OR Events::events_by_value),
 *         $event_detail_name (string, empty if no drill-down active),
 *         $start, $end (for building filter URLs).
 */

defined( 'ABSPATH' ) || exit;


// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template partial included inside a render method; locals are method-scoped at runtime, not true globals.
use VireoAnalytics\Dashboard;

// Carry the active range into the events card's own links, stamping a fresh
// view nonce. Incoming range params are read only on a trusted request.
$base_args  = [ 'page' => 'vireo-analytics', '_vrn' => Dashboard::view_nonce() ];
$va_trusted = Dashboard::request_is_trusted();
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified in Dashboard::request_is_trusted()
if ( $va_trusted && isset( $_GET['period'] ) ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified in Dashboard::request_is_trusted()
	$base_args['period'] = sanitize_key( wp_unslash( (string) $_GET['period'] ) );
}
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified in Dashboard::request_is_trusted()
if ( $va_trusted && isset( $_GET['start_date'] ) ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified in Dashboard::request_is_trusted()
	$base_args['start_date'] = sanitize_text_field( wp_unslash( (string) $_GET['start_date'] ) );
}
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified in Dashboard::request_is_trusted()
if ( $va_trusted && isset( $_GET['end_date'] ) ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified in Dashboard::request_is_trusted()
	$base_args['end_date'] = sanitize_text_field( wp_unslash( (string) $_GET['end_date'] ) );
}
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified in Dashboard::request_is_trusted()
if ( $va_trusted && isset( $_GET['compare'] ) ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified in Dashboard::request_is_trusted()
	$base_args['compare'] = sanitize_text_field( wp_unslash( (string) $_GET['compare'] ) );
}

$clear_url = add_query_arg( $base_args, admin_url( 'admin.php' ) );
$is_detail = '' !== (string) ( $event_detail_name ?? '' );
?>
<div class="va-card va-board va-events-card">
	<div class="va-card-head">
		<h2 class="va-card-title">
			<?php if ( $is_detail ) : ?>
				<?php
				printf(
					/* translators: %s: event name */
					esc_html__( 'Event · %s', 'vireo-analytics' ),
					'<code>' . esc_html( (string) $event_detail_name ) . '</code>'
				);
				?>
				<a class="va-event-clear" href="<?php echo esc_url( $clear_url ); ?>">
					<?php esc_html_e( '&larr; back to all events', 'vireo-analytics' ); ?>
				</a>
			<?php else : ?>
				<?php esc_html_e( 'Top events', 'vireo-analytics' ); ?>
			<?php endif; ?>
		</h2>
		<div class="va-event-head-meta">
			<span class="va-event-col-head"><?php esc_html_e( 'Count', 'vireo-analytics' ); ?></span>
			<span class="va-event-col-head"><?php esc_html_e( 'Unique', 'vireo-analytics' ); ?></span>
		</div>
	</div>

	<?php if ( empty( $events ) ) : ?>
		<div class="va-empty va-empty-inline">
			<div class="va-empty-title"><?php esc_html_e( 'No events recorded', 'vireo-analytics' ); ?></div>
			<div class="va-empty-sub">
				<?php
				echo wp_kses(
					__( 'Fire events from your site with <code>vireo(\'event\', \'name\')</code> and they\'ll appear here.', 'vireo-analytics' ),
					[ 'code' => [] ]
				);
				?>
			</div>
		</div>
	<?php else : ?>
		<ul class="va-board-list va-event-list">
			<?php foreach ( $events as $row ) :
				$name   = (string) $row['name'];
				$value  = (string) ( $row['value'] ?? '' );
				$count  = (int) $row['count'];
				$unique = (int) ( $row['unique_count'] ?? 0 );

				if ( $is_detail ) {
					$label = '' !== $value ? $value : '<em>' . esc_html__( '(no value)', 'vireo-analytics' ) . '</em>';
					$href  = '';
				} else {
					$label = '<code>' . esc_html( $name ) . '</code>';
					// Link to the full-page event detail view. The old
					// `?event=X` drill-down (same page, inline per-value list)
					// is superseded by the dedicated view.
					$href  = add_query_arg(
						array_merge( $base_args, [ 'view' => 'event', 'name' => $name ] ),
						admin_url( 'admin.php' )
					);
				}
			?>
				<li class="va-board-row va-event-row">
					<span class="va-board-lead">
						<span class="va-board-icon va-event-icon" aria-hidden="true">&bull;</span>
						<span class="va-board-label">
							<span class="va-board-label-main">
								<?php if ( '' !== $href ) : ?>
									<a href="<?php echo esc_url( $href ); ?>"><?php echo wp_kses( $label, [ 'code' => [], 'em' => [] ] ); ?></a>
								<?php else : ?>
									<?php echo wp_kses( $label, [ 'code' => [], 'em' => [] ] ); ?>
								<?php endif; ?>
							</span>
						</span>
					</span>
					<span class="va-event-tail">
						<span class="va-event-count" title="<?php echo esc_attr( number_format_i18n( $count ) ); ?>"><?php echo esc_html( Dashboard::abbrev( $count ) ); ?></span>
						<span class="va-event-unique" title="<?php echo esc_attr( number_format_i18n( $unique ) ); ?>"><?php echo esc_html( Dashboard::abbrev( $unique ) ); ?></span>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
