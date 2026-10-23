<?php
/**
 * WP admin dashboard widget — Vireo Analytics snapshot.
 * Today's totals, a daily chart (14 days in a column, 30 at full width),
 * period deltas, top pages and top referrers.
 */

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

final class Dashboard_Widget {

	private const WIDGET_ID = 'vireo_analytics_overview';
	private const CACHE_KEY = 'vireo_dashboard_widget_data_v6';
	private const CACHE_TTL = 300;

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_dashboard_setup', [ $this, 'register' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Enqueue the widget stylesheet on the WP admin dashboard page only.
	 * Capability-gated to the same check that register() uses for visibility —
	 * non-eligible users never see the widget so they don't need the CSS.
	 */
	public function enqueue_assets( $hook ): void {
		$hook = (string) $hook;
		if ( 'index.php' !== $hook ) {
			return;
		}
		if ( ! current_user_can( Installer::CAPABILITY ) ) {
			return;
		}
		$ver = (int) @filemtime( VIREO_DIR . 'assets/css/dashboard-widget.css' ) ?: VIREO_VERSION;
		wp_enqueue_style(
			'va-dashboard-widget',
			VIREO_URL . 'assets/css/dashboard-widget.css',
			[],
			$ver
		);
	}

	public function register(): void {
		if ( ! current_user_can( Installer::CAPABILITY ) ) {
			return;
		}
		wp_add_dashboard_widget(
			self::WIDGET_ID,
			__( 'Vireo Analytics', 'vireo-analytics' ),
			[ $this, 'render' ]
		);

		// Move our widget to the wide "normal" column and hoist it to the top
		// so it takes advantage of the full width when the user is on the main
		// column layout. Falls back gracefully if user has customised order.
		global $wp_meta_boxes;
		if ( isset( $wp_meta_boxes['dashboard']['side']['core'][ self::WIDGET_ID ] ) ) {
			$box = $wp_meta_boxes['dashboard']['side']['core'][ self::WIDGET_ID ];
			unset( $wp_meta_boxes['dashboard']['side']['core'][ self::WIDGET_ID ] );
			$wp_meta_boxes['dashboard']['normal']['core'][ self::WIDGET_ID ] = $box;
		}
	}

	public function render(): void {
		$d = $this->get_data();

		// Today's rollup row plus what is still in the buffer. On a host that
		// runs wp-cron from system cron the buffer holds up to the cron
		// interval of hits, and just after midnight the new day has no row at
		// all, so this read 0 beside a live count of 10. The cache above is
		// five minutes; the buffer is read fresh and cheaply each time.
		$pending  = Pending::today();
		$today_pv = (int) $d['today']['pageviews'] + (int) $pending['pageviews'];
		$today_v  = (int) $d['today']['visitors'] + (int) $pending['visitors'];

		echo '<div class="va-dw"><div class="va-dw-body">';

		// ---------- Row 1 — hero (signals that change every day) ----------
		$va_pro_active = (bool) apply_filters( 'vireo_pro_is_active', false );
		$has_live      = $va_pro_active && class_exists( '\\VireoAnalytics\\Pro\\Live_Tracker' );
		$live          = $has_live ? (int) \VireoAnalytics\Pro\Live_Tracker::get_live_visitors( 300 ) : null;

		echo '<div class="va-dw-hero' . ( $has_live ? ' va-dw-hero-3' : ' va-dw-hero-2' ) . '">';

		echo '<div class="va-dw-stat">';
		echo '<span class="va-dw-stat-label">' . esc_html__( 'Pageviews today', 'vireo-analytics' ) . '</span>';
		echo '<div class="va-dw-stat-row"><span class="va-dw-stat-value">' . esc_html( number_format_i18n( $today_pv ) ) . '</span>';
		// Today is only part of a day, so it is compared with yesterday up to
		// the same time: the hourly rollups, hour by hour, rather than the
		// whole of yesterday scaled by the share of the day gone. The scaled
		// version read -100% at nineteen minutes past midnight.
		$va_same_time = $this->yesterday_to_now( $d['yesterday_hours'], $d['yesterday'] );
		$va_tip       = __( 'Compared with yesterday up to the same time of day.', 'vireo-analytics' );
		$va_yday      = gmdate( 'Y-m-d', strtotime( vireo_bucket_date() . ' 00:00:00 UTC' ) - DAY_IN_SECONDS );
		$va_yday_note = self::untracked_note( Drivers::tracking_since(), $va_yday, (int) $va_same_time['pageviews'] );
		if ( '' === $va_yday_note ) {
			$this->render_delta( $today_pv, (int) $va_same_time['pageviews'], $va_tip );
		}
		echo '</div>';
		echo '</div>';

		echo '<div class="va-dw-stat">';
		echo '<span class="va-dw-stat-label">' . esc_html__( 'Visitors today', 'vireo-analytics' ) . '</span>';
		echo '<div class="va-dw-stat-row"><span class="va-dw-stat-value">' . esc_html( number_format_i18n( $today_v ) ) . '</span>';
		if ( '' === $va_yday_note ) {
			$this->render_delta( $today_v, (int) $va_same_time['visitors'], $va_tip );
		}
		echo '</div>';
		echo '</div>';

		if ( $has_live ) {
			echo '<div class="va-dw-stat va-dw-stat-live">';
			echo '<span class="va-dw-stat-label"><span class="va-dw-live-dot" aria-hidden="true"></span>' . esc_html__( 'Right now', 'vireo-analytics' ) . '</span>';
			echo '<div class="va-dw-stat-row"><span class="va-dw-stat-value">' . esc_html( number_format_i18n( $live ) ) . '</span></div>';
			echo '</div>';
		}

		echo '</div>';

		// ---------- Row 2 — daily chart ----------
		// The chart's last day is today, with the buffer added so it agrees
		// with the figure above it.
		$days_30 = $d['days_30'];
		$last    = count( $days_30 ) - 1;
		if ( $last >= 0 && $days_30[ $last ]['date'] === vireo_bucket_date() ) {
			$days_30[ $last ]['pageviews'] += (int) $pending['pageviews'];
			$days_30[ $last ]['visitors']  += (int) $pending['visitors'];
		}
		// Both ranges are drawn and the stylesheet shows the one that fits:
		// fourteen days in a dashboard column, thirty when the widget has the
		// whole width. The server can't know which it will be.
		echo '<div class="va-dw-trend">';
		echo '<div class="va-dw-trend-14">' . $this->render_chart( array_slice( $days_30, -14 ), __( 'Last 14 days', 'vireo-analytics' ) ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div class="va-dw-trend-30">' . $this->render_chart( $days_30, __( 'Last 30 days', 'vireo-analytics' ) ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';

		// ---------- Row 3 — period summaries (compact) ----------
		// Says what the three totals count and what the percentages compare with.
		echo '<div class="va-dw-periods-block">';
		echo '<div class="va-dw-chart-title va-dw-periods-title">' . esc_html__( 'Pageviews, compared with the period before', 'vireo-analytics' ) . '</div>';
		echo '<div class="va-dw-periods">';
		$this->render_period( __( '7 days', 'vireo-analytics' ), $d['week'], $d['week_prior'], 7 );
		$this->render_period( __( '30 days', 'vireo-analytics' ), $d['month'], $d['month_prior'], 30 );
		$this->render_period( __( '90 days', 'vireo-analytics' ), $d['quarter'], $d['quarter_prior'], 90 );
		echo '</div>';
		echo '</div>';

		// ---------- Row 4 — top pages + top referrers ----------
		echo '<div class="va-dw-tops">';
		echo '<div class="va-dw-top">';
		echo '<div class="va-dw-top-title">' . esc_html__( 'Top pages · last 7 days', 'vireo-analytics' ) . '</div>';
		$this->render_top_list( $d['top_pages'], 'path' );
		echo '</div>';
		echo '<div class="va-dw-top">';
		echo '<div class="va-dw-top-title">' . esc_html__( 'Top referrers · last 7 days', 'vireo-analytics' ) . '</div>';
		$this->render_top_list( $d['top_referrers'], 'url' );
		echo '</div>';
		echo '</div>';

		echo '</div>';

		echo '<p class="va-dw-link"><a href="' . esc_url( admin_url( 'admin.php?page=vireo-analytics' ) ) . '">' . esc_html__( 'View full dashboard →', 'vireo-analytics' ) . '</a></p>';

		echo '</div>';
	}

	private function render_delta( int $current, int $prior, string $title = '' ): void {
		// Nothing to compare with draws nothing. An empty grey "—" pill beside
		// the figure read as a badge that had failed to load.
		if ( $prior <= 0 ) {
			return;
		}
		echo self::delta_html( $current, $prior, $title ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in delta_html()
	}

	/**
	 * Short neutral note for a comparison whose earlier window reaches back
	 * before Vireo recorded, when imported history gives that window numbers
	 * of its own. The same rule as the Overview headline; '' means compare.
	 * An imported September against an untracked October read "-99.5%".
	 */
	public static function untracked_note( string $since, string $prior_start, int $prior ): string {
		if ( '' === Drivers::untracked_headline( $since, $prior_start, $prior ) ) {
			return '';
		}
		/* translators: %s: the first day Vireo recorded, e.g. Oct 21 */
		return sprintf( __( 'Recording since %s', 'vireo-analytics' ), vireo_format_day( $since, 'M j' ) );
	}

	/**
	 * Yesterday up to this time of day, from the hourly rollups. Without
	 * them (an install older than the hour table, or a purged day) the whole
	 * of yesterday is scaled by the share of today gone, as before.
	 *
	 * @param array<int,array{pageviews:int,visitors:int}> $hours
	 * @param array{pageviews:int,visitors:int}            $whole
	 * @return array{pageviews:int,visitors:int}
	 */
	private function yesterday_to_now( array $hours, array $whole ): array {
		if ( [] !== $hours ) {
			$now     = new \DateTimeImmutable( 'now', wp_timezone() );
			$elapsed = ( (int) $now->format( 'i' ) * 60 + (int) $now->format( 's' ) ) / HOUR_IN_SECONDS;
			return Partial_Day::to_same_time( $hours, (int) $now->format( 'G' ), $elapsed );
		}
		$weight = Dashboard::compare_weight( vireo_bucket_date(), vireo_bucket_date() );
		return [
			'pageviews' => (int) round( (int) $whole['pageviews'] * $weight ),
			'visitors'  => (int) round( (int) $whole['visitors'] * $weight ),
		];
	}

	/**
	 * The change as shown, to one decimal. Colour and sign follow the rounded
	 * figure, so a change that rounds to nothing reads "0.0%" and flat rather
	 * than a red "-0.0%".
	 */
	private static function delta_html( int $current, int $prior, string $title = '' ): string {
		$delta = round( ( ( $current - $prior ) / $prior ) * 100, 1 );
		$cls   = $delta > 0 ? 'va-dw-up' : ( $delta < 0 ? 'va-dw-down' : 'va-dw-flat' );
		$sign  = $delta > 0 ? '+' : '';
		$attr  = '' !== $title ? ' title="' . esc_attr( $title ) . '"' : '';
		return '<span class="va-dw-delta ' . esc_attr( $cls ) . '"' . $attr . '>' . esc_html( $sign . number_format_i18n( abs( $delta ) < 0.05 ? 0 : $delta, 1 ) . '%' ) . '</span>';
	}

	private function render_period( string $label, array $current, array $prior, int $days ): void {
		// These windows end today, which is partial; weigh the prior window the
		// same way the Overview does.
		$today  = vireo_bucket_date();
		$start  = gmdate( 'Y-m-d', strtotime( $today . ' 00:00:00 UTC' ) - ( $days - 1 ) * DAY_IN_SECONDS );
		$weight = Dashboard::compare_weight( $start, $today );
		$base   = (int) round( (int) $prior['pageviews'] * $weight );
		// The prior window starts earliest, so it is the one that meets the
		// untracked gap first.
		$since       = Drivers::tracking_since();
		$prior_start = gmdate( 'Y-m-d', strtotime( $start . ' 00:00:00 UTC' ) - $days * DAY_IN_SECONDS );
		$note        = self::untracked_note( $since, $prior_start, (int) $prior['pageviews'] );
		echo '<div class="va-dw-period">';
		echo '<span class="va-dw-period-label">' . esc_html( $label ) . '</span>';
		echo '<div class="va-dw-stat-row"><span class="va-dw-period-value">' . esc_html( number_format_i18n( $current['pageviews'] ) ) . '</span>';
		if ( '' !== $note ) {
			echo '<span class="va-dw-note" title="' . esc_attr( Drivers::untracked_headline( $since, $prior_start, (int) $prior['pageviews'] ) ) . '">' . esc_html( $note ) . '</span>';
		} elseif ( $base > 0 ) {
			echo self::delta_html( (int) $current['pageviews'], $base ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in delta_html()
		}
		echo '</div>';
		echo '</div>';
	}

	/**
	 * @param array<int,object> $rows
	 */
	private function render_top_list( array $rows, string $col ): void {
		if ( empty( $rows ) ) {
			echo '<p class="va-dw-no-data">' . esc_html__( 'No data yet.', 'vireo-analytics' ) . '</p>';
			return;
		}
		echo '<ol class="va-dw-list">';
		foreach ( $rows as $row ) {
			$label = (string) ( $row->$col ?? '' );
			$count = (int) ( $row->pageviews ?? 0 );
			if ( $col === 'url' ) {
				$label_short = preg_replace( '#^https?://(www\.)?#', '', $label );
			} elseif ( $col === 'path' && $label === '/' ) {
				$label_short = __( 'Home', 'vireo-analytics' );
			} elseif ( $col === 'path' && (int) ( $row->post_id ?? 0 ) > 0 && ! Sites::is_connected() ) {
				// Titles, as on the Overview. A connected site's ids aren't posts here.
				$label_short = Dashboard::page_label( get_the_title( (int) $row->post_id ), $label );
			} else {
				$label_short = $label;
			}
			$label_short = mb_strimwidth( (string) $label_short, 0, 50, '…' );
			echo '<li>';
			echo '<span class="va-dw-li-label" title="' . esc_attr( $label ) . '">' . esc_html( $label_short ) . '</span>';
			echo '<span class="va-dw-li-count">' . esc_html( number_format_i18n( $count ) ) . '</span>';
			echo '</li>';
		}
		echo '</ol>';
	}

	/**
	 * @param array<int,array{date:string,pageviews:int,visitors:int}> $days
	 */
	private function render_chart( array $days, string $title ): string {
		// Bars and gridlines only. Text inside a 900-unit SVG shrank with it, to
		// about 4px on a phone, so the axis labels are HTML around the drawing.
		$w      = 900;
		$h      = 150;
		$padT   = 6;
		$innerH = $h - $padT;

		$count = count( $days );
		// All-zero days are still no data: a fresh site got an empty grid.
		$va_any = array_filter( $days, static fn( $day ): bool => (int) ( is_array( $day ) ? ( $day['pageviews'] ?? 0 ) : ( $day->pageviews ?? 0 ) ) > 0 );
		if ( $count === 0 || ! $va_any ) {
			return '<div class="va-dw-chart-head"><div class="va-dw-chart-title">' . esc_html( $title ) . '</div></div>'
				. '<p class="va-dw-no-data">' . esc_html__( 'No traffic data yet.', 'vireo-analytics' ) . '</p>';
		}

		$head = '<div class="va-dw-chart-head">'
			. '<div class="va-dw-chart-title">' . esc_html( $title ) . '</div>'
			. '<div class="va-dw-chart-legend" aria-hidden="true">'
			. '<span class="va-dw-legend-item"><span class="va-dw-legend-key va-dw-legend-pv"></span>' . esc_html__( 'Pageviews', 'vireo-analytics' ) . '</span>'
			. '<span class="va-dw-legend-item"><span class="va-dw-legend-key va-dw-legend-vis"></span>' . esc_html__( 'Visitors', 'vireo-analytics' ) . '</span>'
			. '</div></div>';

		$max = 1;
		foreach ( $days as $d ) {
			$max = max( $max, (int) $d['pageviews'], (int) $d['visitors'] );
		}

		// Scale to a round number rather than to the tallest bar. Reading the
		// axis straight off the data put the busiest day's exact total at the
		// top and half of it in the middle, so a peak of 1,396 printed "1.4k"
		// against a midpoint of "698". The top label was abbreviated and the
		// one below it was not, which reads as a glitch rather than a scale.
		$axis_max = $this->nice_axis_max( $max );
		$half     = intdiv( $axis_max, 2 );

		// Pageviews are the bars and visitors a line over them. The two used to
		// be bars of two greens drawn one over the other, which read as one bar
		// with a stripe in it.
		$slot = $w / $count;
		$barW = max( 4, $slot * 0.64 );
		$gap  = ( $slot - $barW ) / 2;
		$base = $padT + $innerH;

		$gridlines = '';
		$labels    = '';
		foreach ( [ 0, $half, $axis_max ] as $tick ) {
			$y = $padT + $innerH - ( $tick / $axis_max ) * $innerH;
			$gridlines .= sprintf(
				'<line x1="0" x2="%d" y1="%s" y2="%s" class="va-dw-grid%s" vector-effect="non-scaling-stroke"/>',
				$w, round( $y, 1 ), round( $y, 1 ), 0 === $tick ? ' va-dw-grid-base' : ''
			);
			$labels .= sprintf(
				'<span class="va-dw-ylabel" style="top:%s%%">%s</span>',
				round( $y / $h * 100, 2 ), esc_html( $this->abbrev( (int) round( $tick ) ) )
			);
		}

		$bars   = '';
		$points = [];
		$xlabel = '';
		$table  = '';
		foreach ( $days as $i => $d ) {
			$pv  = (int) $d['pageviews'];
			$vis = (int) $d['visitors'];
			$x   = $i * $slot;
			$hPv = ( $pv / $axis_max ) * $innerH;

			// A day with nothing keeps a short stub on the baseline, so it reads
			// as a day with zero rather than a day missing from the chart.
			$bar = $pv > 0
				? sprintf( '<rect class="va-dw-bar" x="%s" y="%s" width="%s" height="%s"/>', round( $x + $gap, 1 ), round( $base - $hPv, 1 ), round( $barW, 1 ), round( max( 1, $hPv ), 1 ) )
				: sprintf( '<rect class="va-dw-bar-zero" x="%s" y="%s" width="%s" height="2"/>', round( $x + $gap, 1 ), $base - 2, round( $barW, 1 ) );

			$points[] = round( $x + $slot / 2, 1 ) . ',' . round( $base - ( $vis / $axis_max ) * $innerH, 1 );

			// The whole day slot carries a native tooltip with that day's
			// numbers.
			$va_tip = sprintf(
				/* translators: 1: date, 2: pageviews, 3: visitors */
				__( '%1$s: %2$s pageviews, %3$s visitors', 'vireo-analytics' ),
				$this->fmt_full_date( $d['date'] ),
				number_format_i18n( $pv ),
				number_format_i18n( $vis )
			);
			$bars .= sprintf(
				'<g class="va-dw-day">%s<rect class="va-dw-hit" x="%s" y="0" width="%s" height="%d"><title>%s</title></rect></g>',
				$bar, round( $x, 1 ), round( $slot, 1 ), $h, esc_html( $va_tip )
			);

			// Ticks a week apart counting back from today, so they always fall
			// on today's weekday. Alternate days are marked too, for a fourteen-
			// day chart with room for them; the stylesheet picks the set.
			$back = $count - 1 - $i;
			$cls  = [];
			if ( 0 === $back % 7 ) {
				$cls[] = 'is-week';
			}
			if ( 0 === $back % 2 ) {
				$cls[] = 'is-alt';
			}
			if ( 0 === $i ) {
				$cls[] = 'is-first';
			}
			if ( $cls ) {
				$cls[] = 0 === $i ? 'is-start' : ( 0 === $back ? 'is-end' : '' );
				$xlabel .= sprintf(
					'<span class="%s" style="left:%s%%">%s</span>',
					esc_attr( trim( implode( ' ', $cls ) ) ),
					round( ( $x + $slot / 2 ) / $w * 100, 2 ),
					esc_html( $this->fmt_short_date( $d['date'] ) )
				);
			}
			$table .= '<tr><th scope="row">' . esc_html( $this->fmt_full_date( $d['date'] ) ) . '</th><td>' . esc_html( number_format_i18n( $pv ) ) . '</td><td>' . esc_html( number_format_i18n( $vis ) ) . '</td></tr>';
		}

		$line = sprintf(
			'<polyline class="va-dw-line" points="%s" vector-effect="non-scaling-stroke"/>',
			esc_attr( implode( ' ', $points ) )
		);

		// Screen readers get the days as a table; the drawing is hidden.
		$sr_table = '<table class="screen-reader-text"><caption>' . esc_html(
			/* translators: %s: the chart's range, e.g. Last 14 days */
			sprintf( __( 'Pageviews and visitors, %s', 'vireo-analytics' ), mb_strtolower( $title ) )
		) . '</caption>'
			. '<thead><tr><th scope="col">' . esc_html__( 'Day', 'vireo-analytics' ) . '</th><th scope="col">' . esc_html__( 'Pageviews', 'vireo-analytics' ) . '</th><th scope="col">' . esc_html__( 'Visitors', 'vireo-analytics' ) . '</th></tr></thead>'
			. '<tbody>' . $table . '</tbody></table>';

		return $head . sprintf(
			'<div class="va-dw-chart-wrap">'
			. '<div class="va-dw-plot"><div class="va-dw-ylabels" aria-hidden="true">%s</div>'
			. '<svg class="va-dw-chart" viewBox="0 0 %d %d" preserveAspectRatio="none" aria-hidden="true" focusable="false">%s%s%s</svg></div>'
			. '<div class="va-dw-xlabels" aria-hidden="true">%s</div>%s'
			. '</div>',
			$labels, $w, $h, $gridlines, $bars, $line, $xlabel, $sr_table
		);
	}

	private function fmt_full_date( string $ymd ): string {
		return vireo_format_day( $ymd, 'l, M j' );
	}

	private function fmt_short_date( string $ymd ): string {
		return vireo_format_day( $ymd, 'M j' );
	}

	/**
	 * Round the top of the axis up so both labels read as round numbers.
	 *
	 * Works on the midpoint rather than the maximum, because the midpoint is
	 * the label most likely to come out ugly, and doubling a tidy midpoint
	 * always yields a tidy top. The ladder includes 3, 6 and 7 so a peak like
	 * 1,396 lands on 1,400 and not on the 1,500 a plain 1/2/5 ladder forces,
	 * which would leave the tallest bar visibly short of the top gridline.
	 */
	private function nice_axis_max( int $max ): int {
		if ( $max < 2 ) {
			return 2;
		}

		$half = $max / 2;
		$mag  = pow( 10, floor( log10( $half ) ) );
		$norm = $half / $mag;
		$step = 10 * $mag;

		foreach ( [ 1, 1.2, 1.4, 1.5, 1.6, 1.8, 2, 2.5, 3, 3.5, 4, 5, 6, 7, 8, 9, 10 ] as $candidate ) {
			if ( $norm <= $candidate + 0.000001 ) {
				$step = $candidate * $mag;
				break;
			}
		}

		return max( 2, (int) ceil( $step ) * 2 );
	}

	private function abbrev( int $n ): string {
		if ( $n >= 1_000_000 ) return rtrim( rtrim( number_format( $n / 1_000_000, 1 ), '0' ), '.' ) . 'M';
		if ( $n >= 10_000 )    return (string) round( $n / 1000 ) . 'k';
		if ( $n >= 1000 )      return rtrim( rtrim( number_format( $n / 1000, 1 ), '0' ), '.' ) . 'k';
		return (string) $n;
	}

	private function get_data(): array {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		// All queries below use $wpdb->prepare() with %i for table identifiers
		// (table names are code-controlled wp_la_* constants). Aggregate widget
		// data is cached via the surrounding transient — no per-query caching.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		global $wpdb;
		$t = Sites::table( 'site_stats' );

		$today    = vireo_bucket_date();
		$yday     = gmdate( 'Y-m-d', strtotime( '-1 day', strtotime( $today ) ) );
		$w_start  = gmdate( 'Y-m-d', strtotime( '-6 days',  strtotime( $today ) ) );
		$wp_start = gmdate( 'Y-m-d', strtotime( '-13 days', strtotime( $today ) ) );
		$wp_end   = gmdate( 'Y-m-d', strtotime( '-7 days',  strtotime( $today ) ) );
		$m_start  = gmdate( 'Y-m-d', strtotime( '-29 days', strtotime( $today ) ) );
		$mp_start = gmdate( 'Y-m-d', strtotime( '-59 days', strtotime( $today ) ) );
		$mp_end   = gmdate( 'Y-m-d', strtotime( '-30 days', strtotime( $today ) ) );
		$q_start  = gmdate( 'Y-m-d', strtotime( '-89 days', strtotime( $today ) ) );
		$qp_start = gmdate( 'Y-m-d', strtotime( '-179 days', strtotime( $today ) ) );
		$qp_end   = gmdate( 'Y-m-d', strtotime( '-90 days', strtotime( $today ) ) );

		$period = function ( string $start, string $end ) use ( $wpdb, $t ) {
			$r = $wpdb->get_row( $wpdb->prepare(
				'SELECT COALESCE(SUM(pageviews),0) pv, COALESCE(SUM(visitors),0) v FROM %i WHERE date BETWEEN %s AND %s',
				$t, $start, $end
			) );
			return [ 'pageviews' => (int) ( $r->pv ?? 0 ), 'visitors' => (int) ( $r->v ?? 0 ) ];
		};

		// Thirty-day daily series for the chart; the narrow chart takes the
		// last fourteen of it.
		$spark_start = gmdate( 'Y-m-d', strtotime( '-29 days', strtotime( $today ) ) );
		$rows30      = $wpdb->get_results( $wpdb->prepare(
			'SELECT date, pageviews, visitors FROM %i WHERE date BETWEEN %s AND %s',
			$t, $spark_start, $today
		), OBJECT_K );
		$days_30 = [];
		$ts      = strtotime( $spark_start );
		for ( $i = 0; $i < 30; $i++ ) {
			$d          = gmdate( 'Y-m-d', $ts );
			$row        = $rows30[ $d ] ?? null;
			$days_30[]  = [
				'date'      => $d,
				'pageviews' => $row ? (int) $row->pageviews : 0,
				'visitors'  => $row ? (int) $row->visitors : 0,
			];
			$ts += DAY_IN_SECONDS;
		}

		// Best day ever.
		$best_row = $wpdb->get_row( $wpdb->prepare(
			'SELECT date, pageviews, visitors FROM %i ORDER BY pageviews DESC LIMIT 1',
			$t
		) );
		$best = $best_row
			? [
				'pageviews' => (int) $best_row->pageviews,
				'visitors'  => (int) $best_row->visitors,
				'date'      => vireo_format_day( (string) $best_row->date, 'M j, Y' ),
			]
			: [ 'pageviews' => 0, 'visitors' => 0, 'date' => '—' ];

		// Lifetime totals.
		$life = $wpdb->get_row( $wpdb->prepare(
			'SELECT COALESCE(SUM(pageviews),0) pv, COALESCE(SUM(visitors),0) v FROM %i',
			$t
		) );

		// Top pages + referrers last 7 days.
		$post_t    = Sites::table( 'post_stats' );
		$paths_t   = Sites::table( 'paths' );
		$top_pages = $wpdb->get_results( $wpdb->prepare(
			'SELECT p.path AS path, MAX(ps.post_id) AS post_id, SUM(ps.pageviews) AS pageviews FROM %i ps JOIN %i p ON ps.path_id = p.id WHERE ps.date BETWEEN %s AND %s GROUP BY ps.path_id ORDER BY pageviews DESC LIMIT 5',
			$post_t, $paths_t, $w_start, $today
		) );
		$ref_s         = Sites::table( 'referrer_stats' );
		$ref_u         = Sites::table( 'referrer_urls' );
		// The site's own host and internal addresses are not referrers; rows
		// recorded before the aggregator refused them are left out here.
		[ $ref_xw, $ref_xa ] = Filters::excluded_where( 'r.id' );
		$top_referrers = $wpdb->get_results( $wpdb->prepare(
			'SELECT r.url AS url, SUM(rs.pageviews) AS pageviews FROM %i rs JOIN %i r ON rs.url_id = r.id WHERE rs.date BETWEEN %s AND %s' . $ref_xw . ' GROUP BY rs.url_id ORDER BY pageviews DESC LIMIT 5', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the fragment is a literal with its ids bound below
			array_merge( [ $ref_s, $ref_u, $w_start, $today ], $ref_xa )
		) );

		// Yesterday by the hour, for "up to the same time of day".
		$yesterday_hours = [];
		$hour_t          = Sites::table( 'hour_stats' );
		if ( Aggregator::table_ready( $hour_t ) ) {
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT hour, pageviews, visitors FROM %i WHERE date = %s', $hour_t, $yday ) ) as $h ) {
				$yesterday_hours[ (int) $h->hour ] = [ 'pageviews' => (int) $h->pageviews, 'visitors' => (int) $h->visitors ];
			}
		}

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$data = [
			'today'         => $period( $today, $today ),
			'yesterday'     => $period( $yday, $yday ),
			'yesterday_hours' => $yesterday_hours,
			'week'          => $period( $w_start, $today ),
			'week_prior'    => $period( $wp_start, $wp_end ),
			'month'         => $period( $m_start, $today ),
			'month_prior'   => $period( $mp_start, $mp_end ),
			'quarter'       => $period( $q_start, $today ),
			'quarter_prior' => $period( $qp_start, $qp_end ),
			'best'          => $best,
			'lifetime'      => [ 'pageviews' => (int) ( $life->pv ?? 0 ), 'visitors' => (int) ( $life->v ?? 0 ) ],
			'days_30'       => $days_30,
			'top_pages'     => $top_pages ?: [],
			'top_referrers' => $top_referrers ?: [],
		];

		set_transient( self::CACHE_KEY, $data, self::CACHE_TTL );
		return $data;
	}

}
