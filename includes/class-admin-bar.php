<?php
/**
 * Admin-bar sparkline — last 14 days of pageviews + today's count.
 *
 * adapted to Vireo Analytics.
 */

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

final class Admin_Bar {

	private const CACHE_KEY = 'vireo_admin_bar_stats';
	private const CACHE_TTL = HOUR_IN_SECONDS;

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// No is_admin_bar_showing() check here. This constructor runs on `init`, and
		// that function reads conditional query tags (is_embed) before the query has
		// run, so WordPress logs a doing_it_wrong notice on every front-end request.
		// With WP_DEBUG_DISPLAY on, that notice prints before headers and breaks the
		// login redirect outright.
		//
		// The guard was not buying anything either: both callbacks below already ask
		// the question at a point where it can be answered. `admin_bar_menu` only
		// fires when the bar is actually rendering, and enqueue_assets() re-checks
		// is_admin_bar_showing() itself. Registering two hooks is cheap.
		add_action( 'admin_bar_menu', [ $this, 'add_menu' ], 100 );
		// Admin bar shows on both front-end (when toolbar is enabled) and
		// admin — enqueue on both, gated on the same capability check
		// add_menu() uses so users who can't see the sparkline don't get
		// the CSS either.
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Whether the current user should get the toolbar item. Site owners switch it
	 * off in Settings; the `vireo_show_admin_bar` filter can decide per user.
	 */
	private function should_show(): bool {
		if ( ! current_user_can( Installer::CAPABILITY ) && ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		$enabled = ! empty( vireo_get_settings()['admin_bar_enabled'] );
		return (bool) apply_filters( 'vireo_show_admin_bar', $enabled, get_current_user_id() );
	}

	public function enqueue_assets(): void {
		if ( ! is_admin_bar_showing() ) {
			return;
		}
		if ( ! $this->should_show() ) {
			return;
		}
		$ver = (int) @filemtime( VIREO_DIR . 'assets/css/admin-bar.css' ) ?: VIREO_VERSION;
		wp_enqueue_style(
			'va-admin-bar',
			VIREO_URL . 'assets/css/admin-bar.css',
			[],
			$ver
		);
	}

	/**
	 * Render the sparkline node into the admin bar.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar
	 */
	public function add_menu( $wp_admin_bar ): void {
		if ( ! $this->should_show() ) {
			return;
		}

		$stats = $this->get_stats();
		if ( empty( $stats ) ) {
			return;
		}

		// Fourteen empty days drew a flat line that looked like a broken feed;
		// a fresh site shows just the count.
		$svg       = array_sum( $stats ) > 0 ? $this->render_sparkline( $stats ) : '';
		$today_val = (int) end( $stats );
		$label     = number_format_i18n( $today_val );

		$wp_admin_bar->add_node(
			[
				'id'    => 'va-stats',
				// The number alone told a screen reader nothing; the title
				// attribute isn't read reliably.
				'title' => '<span class="va-ab-wrap">' . $svg . '<span class="va-ab-count">' . esc_html( $label ) . '</span><span class="screen-reader-text"> ' . esc_html__( 'pageviews today', 'vireo-analytics' ) . '</span></span>',
				'href'  => admin_url( 'admin.php?page=vireo-analytics' ),
				'meta'  => [ 'title' => __( 'Pageviews today (Vireo Analytics)', 'vireo-analytics' ) ],
			]
		);
	}

	/**
	 * Fetch the last 14 days of pageviews. Cached for an hour — admin-bar
	 * loads on every admin page view and this is the most common query.
	 *
	 * @return int[] 14 values, oldest first, today last.
	 */
	private function get_stats(): array {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) && count( $cached ) === 14 ) {
			return $cached;
		}

		global $wpdb;
		$table = Sites::table( 'site_stats' );
		$today = vireo_bucket_date();
		$start = gmdate( 'Y-m-d', strtotime( '-13 days', strtotime( $today ) ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- aggregator output, transient-cached at caller
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT date, pageviews FROM %i WHERE date BETWEEN %s AND %s ORDER BY date ASC',
				$table,
				$start,
				$today
			),
			OBJECT_K
		);

		$stats = [];
		for ( $i = 13; $i >= 0; $i-- ) {
			$d       = gmdate( 'Y-m-d', strtotime( "-{$i} days", strtotime( $today ) ) );
			$stats[] = isset( $rows[ $d ] ) ? (int) $rows[ $d ]->pageviews : 0;
		}

		set_transient( self::CACHE_KEY, $stats, self::CACHE_TTL );
		return $stats;
	}

	/**
	 * @param int[] $data
	 */
	private function render_sparkline( array $data ): string {
		$w     = 120;
		$h     = 24;
		$count = count( $data );
		$max   = max( 1, max( $data ) );
		$step  = $w / max( 1, $count - 1 );

		$points = [];
		$coords = [];
		foreach ( $data as $i => $val ) {
			$x        = round( $i * $step, 1 );
			$y        = round( $h - ( $val / $max * ( $h - 2 ) ) - 1, 1 );
			$points[] = "{$x},{$y}";
			$coords[] = [ $x, $y ];
		}
		$polyline = implode( ' ', $points );

		// Closed area path for the fill underneath the line.
		$area = '';
		if ( ! empty( $coords ) ) {
			$area = 'M ' . $coords[0][0] . ' ' . $h;
			foreach ( $coords as $c ) {
				$area .= ' L ' . $c[0] . ' ' . $c[1];
			}
			$area .= ' L ' . $coords[ count( $coords ) - 1 ][0] . ' ' . $h . ' Z';
		}

		return sprintf(
			'<svg class="va-ab-spark" width="%d" height="%d" viewBox="0 0 %d %d" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">'
			. '<path d="%s" fill="#72aee6" fill-opacity="0.18"/>'
			. '<polyline points="%s" fill="none" stroke="#72aee6" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round" />'
			. '</svg>',
			$w,
			$h,
			$w,
			$h,
			esc_attr( $area ),
			esc_attr( $polyline )
		);
	}

}
