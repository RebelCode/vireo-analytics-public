<?php
/**
 * Plain email digest — Free tier.
 *
 * Pipeline:
 *   Hourly cron `vireo_weekly_email` fires.
 *   run_weekly() self-gates on the configured frequency, day and hour (site
 *   TZ) and whether this period has already been sent.
 *   gather_data() pulls the finished period from the rollup tables.
 *   templates/report-email-plain.php renders the HTML.
 *   wp_mail() ships it.
 *
 * The hook and the handler keep their "weekly" names from when that was the
 * only frequency: Pro removes the handler by name to send its own report in
 * its place, and the cron event is already scheduled on installed sites.
 */

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

final class Email_Reports {

	public const CRON_HOOK       = 'vireo_weekly_email';
	public const HISTORY_OPTION  = 'vireo_weekly_email_history';
	public const HISTORY_LIMIT   = 10;

	public const FREQUENCIES = [ 'daily', 'weekly', 'monthly' ];

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( self::CRON_HOOK, [ $this, 'run_weekly' ] );
	}

	// ---------------------------------------------------------------------
	// Frequency: pure helpers, shared with Pro's report
	// ---------------------------------------------------------------------

	/** @param array<string,mixed> $settings */
	public static function frequency( array $settings ): string {
		$frequency = (string) ( $settings['email_frequency'] ?? 'weekly' );
		return in_array( $frequency, self::FREQUENCIES, true ) ? $frequency : 'weekly';
	}

	/** @return array<string,string> Frequency => label. */
	public static function frequency_labels(): array {
		return [
			'daily'   => __( 'Daily', 'vireo-analytics' ),
			'weekly'  => __( 'Weekly', 'vireo-analytics' ),
			'monthly' => __( 'Monthly', 'vireo-analytics' ),
		];
	}

	/**
	 * Is this the hour to send? Daily: every day at the hour. Weekly: the
	 * chosen weekday. Monthly: the first of the month. All in site time.
	 *
	 * @param array<string,mixed> $settings
	 */
	public static function is_due( array $settings, int $now_ts ): bool {
		$hour = max( 0, min( 23, (int) ( $settings['email_hour'] ?? 9 ) ) );
		if ( (int) wp_date( 'G', $now_ts ) !== $hour ) {
			return false;
		}
		switch ( self::frequency( $settings ) ) {
			case 'daily':
				return true;
			case 'monthly':
				return 1 === (int) wp_date( 'j', $now_ts );
		}
		$day = max( 1, min( 7, (int) ( $settings['email_day'] ?? 1 ) ) );
		return (int) wp_date( 'N', $now_ts ) === $day;
	}

	/** The key one send per period is deduplicated on. */
	public static function period_key( string $frequency, int $now_ts ): string {
		switch ( $frequency ) {
			case 'daily':
				return wp_date( 'Y-m-d', $now_ts );
			case 'monthly':
				return wp_date( 'Y-m', $now_ts );
		}
		return wp_date( 'o-W', $now_ts );
	}

	/**
	 * The finished period before $today and the one before that, as site days.
	 * Daily is yesterday; weekly the last seven full days; monthly the previous
	 * calendar month.
	 *
	 * @return array{start:string,end:string,prev_start:string,prev_end:string}
	 */
	public static function period( string $frequency, string $today ): array {
		$day = static function ( string $ymd, string $modify ): string {
			return gmdate( 'Y-m-d', (int) strtotime( $modify, (int) strtotime( $ymd . ' 00:00:00 UTC' ) ) );
		};

		switch ( $frequency ) {
			case 'daily':
				return [
					'start'      => $day( $today, '-1 day' ),
					'end'        => $day( $today, '-1 day' ),
					'prev_start' => $day( $today, '-2 days' ),
					'prev_end'   => $day( $today, '-2 days' ),
				];
			case 'monthly':
				$first = substr( $today, 0, 7 ) . '-01';
				return [
					'start'      => $day( $first, '-1 month' ),
					'end'        => $day( $first, '-1 day' ),
					'prev_start' => $day( $first, '-2 months' ),
					'prev_end'   => $day( $day( $first, '-1 month' ), '-1 day' ),
				];
		}
		return [
			'start'      => $day( $today, '-7 days' ),
			'end'        => $day( $today, '-1 day' ),
			'prev_start' => $day( $today, '-14 days' ),
			'prev_end'   => $day( $today, '-8 days' ),
		];
	}

	/** "Oct 13, 2026", "Sep 28 – Oct 4, 2026" or "September 2026". */
	public static function period_label( string $frequency, string $start, string $end ): string {
		if ( 'daily' === $frequency ) {
			return vireo_format_day( $end, 'M j, Y' );
		}
		if ( 'monthly' === $frequency ) {
			/* translators: Month and year. See https://www.php.net/manual/datetime.format.php */
			return vireo_format_day( $start, _x( 'F Y', 'month and year', 'vireo-analytics' ) );
		}
		return vireo_format_day( $start, 'M j' ) . ' – ' . vireo_format_day( $end, 'M j, Y' );
	}

	/** The subject line for a period, without the site name prefix. */
	public static function subject( string $frequency, string $site_name, string $period_label ): string {
		switch ( $frequency ) {
			case 'daily':
				/* translators: 1: site name, 2: the day covered. */
				$format = __( '[%1$s] Daily Analytics: %2$s', 'vireo-analytics' );
				break;
			case 'monthly':
				/* translators: 1: site name, 2: the month covered. */
				$format = __( '[%1$s] Monthly Analytics: %2$s', 'vireo-analytics' );
				break;
			default:
				/* translators: 1: site name, 2: period label. */
				$format = __( '[%1$s] Weekly Analytics: %2$s', 'vireo-analytics' );
		}
		return sprintf( $format, $site_name, $period_label );
	}

	// ---------------------------------------------------------------------
	// Sending
	// ---------------------------------------------------------------------

	/**
	 * Cron entry point. Fires hourly; self-gates so we only send at the
	 * configured time and at most once per period.
	 */
	public function run_weekly(): void {
		$settings = vireo_get_settings();
		if ( empty( $settings['email_enabled'] ) ) {
			return;
		}

		$now_ts = time();
		if ( ! self::is_due( $settings, $now_ts ) ) {
			return;
		}

		$frequency  = self::frequency( $settings );
		$period_key = self::period_key( $frequency, $now_ts );
		foreach ( $this->get_history() as $entry ) {
			if ( ! empty( $entry['week_key'] ) && $entry['week_key'] === $period_key && ! empty( $entry['success'] ) ) {
				return;
			}
		}

		$recipients = $this->effective_recipients( $settings );
		if ( empty( $recipients ) ) {
			$this->record_history( [
				'trigger'  => 'cron',
				'week_key' => $period_key,
				'success'  => false,
				'error'    => 'No recipients configured.',
			] );
			return;
		}

		$sent = $this->build_and_send( $recipients, $frequency );
		$this->record_history( [
			'trigger'  => 'cron',
			'week_key' => $period_key,
			'success'  => $sent,
			'error'    => $sent ? '' : 'wp_mail() returned false.',
		] );
	}

	/**
	 * "Send test email" button from settings — always sends to the current
	 * admin, bypasses schedule + dedupe. Returns wp_mail() success.
	 */
	public function send_test(): bool {
		$user  = wp_get_current_user();
		$email = ( $user && $user->user_email ) ? (string) $user->user_email : (string) get_option( 'admin_email' );
		if ( '' === $email ) {
			return false;
		}
		return $this->build_and_send( [ $email ], self::frequency( vireo_get_settings() ) );
	}

	/**
	 * @param string[] $recipients
	 */
	private function build_and_send( array $recipients, string $frequency ): bool {
		if ( empty( $recipients ) ) {
			return false;
		}

		$data = $this->gather_data( self::period( $frequency, vireo_bucket_date() ) );

		$period_label  = self::period_label( $frequency, (string) $data['period']['start'], (string) $data['period']['end'] );
		$site_name     = get_bloginfo( 'name' );
		$site_url      = home_url();
		$dashboard_url = admin_url( 'admin.php?page=vireo-analytics' );

		ob_start();
		include VIREO_DIR . 'templates/report-email-plain.php';
		$html = (string) ob_get_clean();

		$subject = self::subject( $frequency, $site_name, $period_label );
		$headers = [ 'Content-Type: text/html; charset=UTF-8' ];

		return (bool) wp_mail( $recipients, $subject, $html, $headers );
	}

	/**
	 * @return string[]
	 */
	private function effective_recipients( array $settings ): array {
		$raw = (string) ( $settings['email_recipients'] ?? '' );
		if ( '' === trim( $raw ) ) {
			$admin = (string) get_option( 'admin_email' );
			return $admin ? [ $admin ] : [];
		}
		$lines = preg_split( '/[\r\n,]+/', $raw );
		$out   = [];
		foreach ( (array) $lines as $line ) {
			$email = sanitize_email( trim( (string) $line ) );
			if ( $email ) {
				$out[] = $email;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Pull a finished period from the daily rollup tables and structure it for
	 * the template. Mirrors what the plain email template expects — the AI
	 * template in Pro reuses this shape.
	 *
	 * @param array{start:string,end:string,prev_start:string,prev_end:string} $period
	 * @return array<string,mixed>
	 */
	private function gather_data( array $period ): array {
		global $wpdb;

		$start      = $period['start'];
		$end        = $period['end'];
		$prev_start = $period['prev_start'];
		$prev_end   = $period['prev_end'];

		$site = Sites::table( 'site_stats' );
		$post = Sites::table( 'post_stats' );
		$path = Sites::table( 'paths' );
		$ref  = Sites::table( 'referrer_stats' );
		$refu = Sites::table( 'referrer_urls' );
		$geo  = Sites::table( 'geo_stats' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- digest, rendered once per period
		$curr = (array) $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(visitors),0) AS visitors,
					COALESCE(SUM(pageviews),0) AS pageviews
				 FROM %i WHERE date BETWEEN %s AND %s',
				$site,
				$start,
				$end
			),
			ARRAY_A
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- digest, rendered once per period
		$prev = (array) $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(visitors),0) AS visitors,
					COALESCE(SUM(pageviews),0) AS pageviews
				 FROM %i WHERE date BETWEEN %s AND %s',
				$site,
				$prev_start,
				$prev_end
			),
			ARRAY_A
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- digest, rendered once per period
		$top_pages = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT p.path, s.post_id,
					COALESCE(SUM(s.pageviews),0) AS pageviews,
					COALESCE(SUM(s.visitors),0) AS visitors
				 FROM %i s
				 JOIN %i p ON p.id = s.path_id
				 WHERE s.date BETWEEN %s AND %s
				 GROUP BY p.path, s.post_id
				 ORDER BY pageviews DESC
				 LIMIT 10',
				$post,
				$path,
				$start,
				$end
			),
			ARRAY_A
		);

		$top_pages_out = [];
		foreach ( $top_pages as $row ) {
			$post_id = (int) $row['post_id'];
			$title   = Dashboard::public_post( $post_id )['title'];
			$top_pages_out[] = [
				'path'      => (string) $row['path'],
				'title'     => Dashboard::page_label( wp_strip_all_tags( $title ), (string) $row['path'] ),
				'pageviews' => (int) $row['pageviews'],
				'visitors'  => (int) $row['visitors'],
			];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- digest, rendered once per period
		$top_refs = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT u.url,
					COALESCE(SUM(s.pageviews),0) AS pageviews,
					COALESCE(SUM(s.visitors),0) AS visitors
				 FROM %i s
				 JOIN %i u ON u.id = s.url_id
				 WHERE s.date BETWEEN %s AND %s
				 GROUP BY u.url
				 ORDER BY visitors DESC
				 LIMIT 10',
				$ref,
				$refu,
				$start,
				$end
			),
			ARRAY_A
		);

		$top_refs_out = [];
		foreach ( $top_refs as $row ) {
			$top_refs_out[] = [
				'host'     => (string) $row['url'],
				'visitors' => (int) $row['visitors'],
			];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- digest, rendered once per period
		$top_countries = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT country_code, COALESCE(SUM(visitors),0) AS visitors
				 FROM %i
				 WHERE date BETWEEN %s AND %s
				 GROUP BY country_code
				 ORDER BY visitors DESC
				 LIMIT 10',
				$geo,
				$start,
				$end
			),
			ARRAY_A
		);

		$top_countries_out = [];
		foreach ( $top_countries as $row ) {
			$top_countries_out[] = [
				'code'     => (string) $row['country_code'],
				'visitors' => (int) $row['visitors'],
			];
		}

		return [
			'period' => [
				'start' => $start,
				'end'   => $end,
			],
			'totals' => [
				'visitors'        => (int) ( $curr['visitors'] ?? 0 ),
				'pageviews'       => (int) ( $curr['pageviews'] ?? 0 ),
				'prior_visitors'  => (int) ( $prev['visitors'] ?? 0 ),
				'prior_pageviews' => (int) ( $prev['pageviews'] ?? 0 ),
			],
			'insight'       => $this->insight_line( $start, $end ),
			'highlights'    => Highlights::for_week( $start, $end ),
			'top_pages'     => $top_pages_out,
			'top_referrers' => $top_refs_out,
			'top_countries' => $top_countries_out,
		];
	}

	/**
	 * The sentence the Overview opens with, for this period against the last.
	 * Empty when there is no prior period: the composer then falls back to copy
	 * about data still arriving, which is noise in an inbox.
	 */
	private function insight_line( string $start, string $end ): string {
		$dashboard = Dashboard::instance();
		$hero      = $dashboard->get_hero_stats( $start, $end, true );

		if ( (int) ( $hero['prior']['pageviews'] ?? 0 ) <= 0 ) {
			return '';
		}

		$drivers           = ( new Drivers( $dashboard ) )->get( $start, $end, true );
		[ $head, $detail ] = Insights::lede( Insights::line( $hero, $drivers, true ), $drivers );

		return trim( $head . ' ' . $detail );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function get_history(): array {
		$raw = get_option( self::HISTORY_OPTION, [] );
		return is_array( $raw ) ? $raw : [];
	}

	private function record_history( array $entry ): void {
		$entry['timestamp'] = time();
		$history            = $this->get_history();
		array_unshift( $history, $entry );
		if ( count( $history ) > self::HISTORY_LIMIT ) {
			$history = array_slice( $history, 0, self::HISTORY_LIMIT );
		}
		update_option( self::HISTORY_OPTION, $history, false );
	}

	/**
	 * Minimal ISO-8859-1 country code → name map. Templates use this to show
	 * human labels in the Top Countries section. Kept small (top ~60 codes)
	 * to avoid shipping a big static file just for email rendering.
	 */
	public static function country_name( string $code ): string {
		static $map = null;
		if ( null === $map ) {
			$map = [
				'US' => 'United States', 'GB' => 'United Kingdom', 'DE' => 'Germany',
				'FR' => 'France', 'IT' => 'Italy', 'ES' => 'Spain', 'NL' => 'Netherlands',
				'BE' => 'Belgium', 'SE' => 'Sweden', 'NO' => 'Norway', 'FI' => 'Finland',
				'DK' => 'Denmark', 'IE' => 'Ireland', 'CH' => 'Switzerland', 'AT' => 'Austria',
				'PT' => 'Portugal', 'PL' => 'Poland', 'CZ' => 'Czechia', 'GR' => 'Greece',
				'HU' => 'Hungary', 'RO' => 'Romania', 'MT' => 'Malta', 'LU' => 'Luxembourg',
				'CA' => 'Canada', 'MX' => 'Mexico', 'BR' => 'Brazil', 'AR' => 'Argentina',
				'CL' => 'Chile', 'CO' => 'Colombia', 'AU' => 'Australia', 'NZ' => 'New Zealand',
				'JP' => 'Japan', 'KR' => 'South Korea', 'CN' => 'China', 'IN' => 'India',
				'SG' => 'Singapore', 'HK' => 'Hong Kong', 'TW' => 'Taiwan', 'ID' => 'Indonesia',
				'TH' => 'Thailand', 'VN' => 'Vietnam', 'PH' => 'Philippines', 'MY' => 'Malaysia',
				'AE' => 'United Arab Emirates', 'SA' => 'Saudi Arabia', 'IL' => 'Israel',
				'TR' => 'Turkey', 'RU' => 'Russia', 'UA' => 'Ukraine', 'ZA' => 'South Africa',
				'NG' => 'Nigeria', 'EG' => 'Egypt', 'KE' => 'Kenya', 'MA' => 'Morocco',
				'XX' => 'Unknown',
			];
		}
		return $map[ strtoupper( $code ) ] ?? $code;
	}
}
