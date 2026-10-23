<?php
/**
 * Saved views: a name for a date range, a comparison and a set of filters,
 * kept per user so the view they check every Monday is one pick away.
 *
 * A view stores the params, not a URL. The URL carries a nonce that expires
 * within a day, so a view is turned back into a link each time the page
 * renders, with a fresh nonce, and its params go through the same
 * validation as a request's.
 */

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

final class Saved_Views {

	/** Per-user list of views. */
	public const META_KEY = 'vireo_saved_views';

	public const NONCE = 'vireo_saved_views';

	/** Enough for anyone's week; a bound so the picker stays a picker. */
	private const MAX_VIEWS = 30;

	private const MAX_NAME = 60;

	/** The params a view may carry, beyond the filters. */
	private const RANGE_PARAMS = [ 'period', 'start_date', 'end_date', 'compare' ];

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_vireo_save_view', [ $this, 'ajax_save' ] );
		add_action( 'wp_ajax_vireo_delete_view', [ $this, 'ajax_delete' ] );
	}

	/**
	 * @return array<int, array{id:string,name:string,params:array<string,string>}>
	 */
	public static function for_user( int $user_id ): array {
		$stored = get_user_meta( $user_id, self::META_KEY, true );
		if ( ! is_array( $stored ) ) {
			return [];
		}
		$out = [];
		foreach ( $stored as $row ) {
			if ( ! is_array( $row ) || empty( $row['id'] ) || empty( $row['name'] ) || ! is_array( $row['params'] ?? null ) ) {
				continue;
			}
			$out[] = [
				'id'     => (string) $row['id'],
				'name'   => (string) $row['name'],
				'params' => self::clean_params( $row['params'] ),
			];
		}
		return $out;
	}

	/**
	 * The params of the view on screen, in the shape a saved view stores, so
	 * the picker can tell whether the current view is already saved.
	 *
	 * @return array<string,string>
	 */
	public static function current_params( string $preset, string $start, string $end, bool $compare, string $compare_mode, ?Filters $filters ): array {
		$params = [ 'period' => $preset ];
		if ( 'custom' === $preset ) {
			$params['start_date'] = $start;
			$params['end_date']   = $end;
		}
		$params['compare'] = $compare ? ( 'year' === $compare_mode ? 'year' : '1' ) : '0';
		if ( $filters instanceof Filters ) {
			$params += $filters->to_query_args();
		}
		return self::clean_params( $params );
	}

	/**
	 * Only known params with well-formed values survive, whether they came
	 * from the request or from storage.
	 *
	 * @param array<string,mixed> $params
	 * @return array<string,string>
	 */
	public static function clean_params( array $params ): array {
		$out = [];

		$period = isset( $params['period'] ) ? sanitize_key( (string) $params['period'] ) : '';
		if ( 'custom' === $period ) {
			$start = isset( $params['start_date'] ) ? sanitize_text_field( (string) $params['start_date'] ) : '';
			$end   = isset( $params['end_date'] ) ? sanitize_text_field( (string) $params['end_date'] ) : '';
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end ) ) {
				$out['period']     = 'custom';
				$out['start_date'] = $start;
				$out['end_date']   = $end;
			}
		} elseif ( '' !== $period && null !== Dashboard::preset_range( $period ) ) {
			$out['period'] = $period;
		}

		$compare = isset( $params['compare'] ) ? sanitize_key( (string) $params['compare'] ) : '';
		if ( in_array( $compare, [ '0', '1', 'year' ], true ) ) {
			$out['compare'] = $compare;
		}

		return $out + Filters::from_params( $params )->to_query_args();
	}

	/**
	 * The dashboard link a view opens, stamped with a fresh view nonce.
	 */
	public static function url( array $view ): string {
		return add_query_arg(
			[ 'page' => 'vireo-analytics', '_vrn' => Dashboard::view_nonce() ] + $view['params'],
			admin_url( 'admin.php' )
		);
	}

	/**
	 * The id of the saved view whose params match these, '' when none does.
	 *
	 * @param array<int, array{id:string,name:string,params:array<string,string>}> $views
	 * @param array<string,string>                                                $params
	 */
	public static function match( array $views, array $params ): string {
		ksort( $params );
		foreach ( $views as $view ) {
			$stored = $view['params'];
			ksort( $stored );
			if ( $stored === $params ) {
				return $view['id'];
			}
		}
		return '';
	}

	/**
	 * @param array<string,mixed> $params
	 * @return array{id:string,name:string,params:array<string,string>}|null The stored view, or null when the list is full.
	 */
	public static function save( int $user_id, string $name, array $params ): ?array {
		$views = self::for_user( $user_id );
		if ( count( $views ) >= self::MAX_VIEWS ) {
			return null;
		}
		$view    = [
			// Lowercase: the id comes back through sanitize_key(), which lowercases.
			'id'     => substr( md5( uniqid( (string) $user_id, true ) ), 0, 8 ),
			'name'   => $name,
			'params' => self::clean_params( $params ),
		];
		$views[] = $view;
		update_user_meta( $user_id, self::META_KEY, $views );
		return $view;
	}

	public static function delete( int $user_id, string $id ): void {
		$views = array_values( array_filter( self::for_user( $user_id ), static fn( array $v ): bool => $v['id'] !== $id ) );
		if ( [] === $views ) {
			delete_user_meta( $user_id, self::META_KEY );
			return;
		}
		update_user_meta( $user_id, self::META_KEY, $views );
	}

	public function ajax_save(): void {
		if ( ! current_user_can( 'view_vireo_analytics' ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by check_ajax_referer above.
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['name'] ) ) : '';
		$name = trim( mb_substr( $name, 0, self::MAX_NAME ) );
		if ( '' === $name ) {
			wp_send_json_error( [ 'message' => __( 'Give the view a name.', 'vireo-analytics' ) ], 400 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by check_ajax_referer above.
		$params = isset( $_POST['params'] ) && is_array( $_POST['params'] ) ? (array) wp_unslash( $_POST['params'] ) : [];

		$view = self::save( get_current_user_id(), $name, $params );
		if ( null === $view ) {
			wp_send_json_error(
				[
					'message' => sprintf(
						/* translators: %d: the most views one user can keep */
						__( 'You can keep up to %d saved views. Delete one to save another.', 'vireo-analytics' ),
						self::MAX_VIEWS
					),
				],
				400
			);
		}

		wp_send_json_success( [ 'view' => $view, 'url' => self::url( $view ) ] );
	}

	public function ajax_delete(): void {
		if ( ! current_user_can( 'view_vireo_analytics' ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by check_ajax_referer above.
		$id = isset( $_POST['id'] ) ? sanitize_key( (string) $_POST['id'] ) : '';
		if ( '' !== $id ) {
			self::delete( get_current_user_id(), $id );
		}
		wp_send_json_success();
	}
}
