<?php

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * Links to the wordpress.org support forum and review page, and a one-time
 * request for a review.
 *
 * The request appears only inside Vireo's own Overview, only to someone who can
 * manage the plugin, and only once there are two weeks of numbers and they have
 * opened the dashboard on several separate days. Whatever they pick is stored
 * against their user and never asked again sooner than they chose. It asks
 * everyone who qualifies the same way; nothing filters by how they feel about it.
 */
final class Feedback {

	public const SUPPORT_URL = 'https://wordpress.org/support/plugin/vireo-analytics/';
	public const REVIEW_URL  = 'https://wordpress.org/support/plugin/vireo-analytics/reviews/#new-post';

	public const MIN_DATA_DAYS  = 14;
	public const MIN_USAGE_DAYS = 5;
	public const SNOOZE_DAYS    = 30;

	/**
	 * Per-user state: days the Overview was opened (counting stops at the
	 * threshold), the last such day, snoozed-until timestamp, and whether the
	 * request is finished for good.
	 */
	private const META = 'vireo_review_prompt';
	private const CAP  = 'manage_options';

	/** Action name for admin-post and admin-ajax, and the nonce action. */
	public const ACTION = 'vireo_review_prompt';

	public static function boot(): void {
		add_filter( 'plugin_row_meta', [ __CLASS__, 'row_meta' ], 10, 2 );
		add_action( 'wp_ajax_' . self::ACTION, [ __CLASS__, 'handle_choice' ] );
		add_action( 'admin_post_' . self::ACTION, [ __CLASS__, 'handle_choice' ] );
	}

	/**
	 * @param string[] $links
	 * @param string   $file
	 * @return string[]
	 */
	public static function row_meta( $links, $file ): array {
		$links = (array) $links;
		if ( plugin_basename( VIREO_FILE ) !== $file ) {
			return $links;
		}

		$links[] = sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_url( self::SUPPORT_URL ),
			esc_html__( 'Support', 'vireo-analytics' )
		);
		$links[] = sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_url( self::REVIEW_URL ),
			esc_html__( 'Rate Vireo', 'vireo-analytics' )
		);

		return $links;
	}

	/**
	 * Called on every Overview render. Counts today as a day of use, then
	 * returns how many days of data to mention when the request is due, or 0.
	 */
	public static function prompt_days(): int {
		if ( is_network_admin() || ! current_user_can( self::CAP ) ) {
			return 0;
		}
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return 0;
		}

		$state   = self::normalize_state( get_user_meta( $user_id, self::META, true ) );
		$counted = self::record_visit( $state, wp_date( 'Y-m-d' ) );
		if ( $counted !== $state ) {
			update_user_meta( $user_id, self::META, $counted );
		}

		// Everything cheap first: most loads end here without touching the
		// stats table.
		if ( ! self::is_due( $counted, self::MIN_DATA_DAYS, time() ) ) {
			return 0;
		}
		if ( class_exists( Import_Controller::class ) && Import_Controller::instance()->should_show_onboarding() ) {
			return 0;
		}

		$days = self::days_counted();
		return self::is_due( $counted, $days, time() ) ? $days : 0;
	}

	/**
	 * Days Vireo itself recorded visits on. Imported history is left out, so
	 * the request never credits Vireo with counting that another tool did.
	 */
	private static function days_counted(): int {
		global $wpdb;

		$after = '';
		foreach ( Import_History::all() as $import ) {
			$last  = '' !== $import['last'] ? $import['last'] : $import['end'];
			$after = max( $after, $last );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one COUNT over a table keyed by date, only once the cheap checks pass.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE pageviews > 0 AND date > %s',
				Sites::table( 'site_stats' ),
				'' !== $after ? $after : '0000-00-00'
			)
		);
	}

	public static function handle_choice(): void {
		$ajax = wp_doing_ajax();

		if ( ! current_user_can( self::CAP ) ) {
			if ( $ajax ) {
				wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
			}
			wp_die( esc_html__( 'You do not have permission to do that.', 'vireo-analytics' ), 403 );
		}
		if ( $ajax ) {
			check_ajax_referer( self::ACTION, 'nonce' );
		} else {
			check_admin_referer( self::ACTION );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified above.
		$choice  = isset( $_REQUEST['choice'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['choice'] ) ) : '';
		$user_id = get_current_user_id();
		$state   = self::normalize_state( get_user_meta( $user_id, self::META, true ) );
		update_user_meta( $user_id, self::META, self::apply_choice( $state, $choice, time() ) );

		if ( $ajax ) {
			wp_send_json_success();
		}
		if ( 'review' === $choice ) {
			// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- fixed wordpress.org URL, not user input.
			wp_redirect( self::REVIEW_URL );
			exit;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=vireo-analytics' ) );
		exit;
	}

	/** Nonce-stamped link for "Leave a review": records the choice, then sends the browser to wordpress.org. */
	public static function review_link(): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION . '&choice=review' ), self::ACTION );
	}

	/**
	 * @param mixed $raw
	 * @return array{days:int,last:string,snooze:int,done:bool}
	 */
	public static function normalize_state( $raw ): array {
		$raw = is_array( $raw ) ? $raw : [];
		return [
			'days'   => max( 0, (int) ( $raw['days'] ?? 0 ) ),
			'last'   => (string) ( $raw['last'] ?? '' ),
			'snooze' => max( 0, (int) ( $raw['snooze'] ?? 0 ) ),
			'done'   => ! empty( $raw['done'] ),
		];
	}

	/**
	 * At most one write a day, and none at all once the threshold is reached.
	 *
	 * @param array{days:int,last:string,snooze:int,done:bool} $state
	 * @return array{days:int,last:string,snooze:int,done:bool}
	 */
	public static function record_visit( array $state, string $today ): array {
		if ( $state['done'] || $state['days'] >= self::MIN_USAGE_DAYS || $state['last'] === $today ) {
			return $state;
		}
		++$state['days'];
		$state['last'] = $today;
		return $state;
	}

	/**
	 * @param array{days:int,last:string,snooze:int,done:bool} $state
	 */
	public static function is_due( array $state, int $data_days, int $now ): bool {
		return ! $state['done']
			&& $now >= $state['snooze']
			&& $state['days'] >= self::MIN_USAGE_DAYS
			&& $data_days >= self::MIN_DATA_DAYS;
	}

	/**
	 * @param array{days:int,last:string,snooze:int,done:bool} $state
	 * @return array{days:int,last:string,snooze:int,done:bool}
	 */
	public static function apply_choice( array $state, string $choice, int $now ): array {
		if ( 'later' === $choice ) {
			$state['snooze'] = $now + self::SNOOZE_DAYS * DAY_IN_SECONDS;
		} elseif ( 'review' === $choice || 'never' === $choice ) {
			$state['done'] = true;
		}
		return $state;
	}
}
