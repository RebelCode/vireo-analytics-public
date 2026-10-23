<?php

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * Notes pinned to a date on the chart.
 *
 * The chart is very good at showing that something changed and completely
 * silent about why. Six weeks later nobody remembers whether the jump on the
 * 14th was the newsletter, the redesign, or a bot that got past the filter.
 * A one-line note costs nothing and answers it.
 *
 * Reading follows the dashboard capability. Writing needs `edit_others_posts`
 * by default, so somebody who has merely been given sight of the numbers
 * cannot scribble on them, while editors who run the campaigns can.
 */
final class Annotations {

	public const TABLE = 'annotations';

	private const MAX_LABEL = 160;

	public static function boot(): void {
		add_action( 'wp_ajax_vireo_annotation_add', [ __CLASS__, 'ajax_add' ] );
		add_action( 'wp_ajax_vireo_annotation_delete', [ __CLASS__, 'ajax_delete' ] );
	}

	public static function table_name(): string {
		return Sites::table( self::TABLE );
	}

	/** Who may write notes. Reading is the dashboard capability. */
	public static function write_capability(): string {
		$cap = (string) apply_filters( 'vireo_annotation_capability', 'edit_others_posts' );
		return '' === $cap ? 'edit_others_posts' : $cap;
	}

	public static function can_write(): bool {
		return current_user_can( self::write_capability() );
	}

	/**
	 * Notes falling inside a range, oldest first.
	 *
	 * @return array<int,array{id:int,date:string,label:string}>
	 */
	public static function for_range( string $start, string $end ): array {
		global $wpdb;

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- schema probe, uncacheable
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return [];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- reporting read
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, date, label FROM {$table} WHERE date BETWEEN %s AND %s ORDER BY date ASC, id ASC",
				$start,
				$end
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return [];
		}

		return array_map(
			static function ( array $r ): array {
				return [
					'id'    => (int) $r['id'],
					'date'  => (string) $r['date'],
					'label' => (string) $r['label'],
				];
			},
			$rows
		);
	}

	/**
	 * Notes for a range keyed by date, for the chart tooltip.
	 *
	 * @return array<string,string>
	 */
	public static function map_for_range( string $start, string $end ): array {
		$out = [];

		foreach ( self::for_range( $start, $end ) as $note ) {
			// More than one note on a day is unusual but allowed; the tooltip
			// has room for one line, so they join.
			$out[ $note['date'] ] = isset( $out[ $note['date'] ] )
				? $out[ $note['date'] ] . ' · ' . $note['label']
				: $note['label'];
		}

		return $out;
	}

	/**
	 * @return int|\WP_Error The new note's id.
	 */
	public static function add( string $date, string $label ) {
		global $wpdb;

		$date  = trim( $date );
		$label = trim( wp_strip_all_tags( $label ) );

		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || ! self::is_real_date( $date ) ) {
			return new \WP_Error( 'vireo_annotation_date', __( 'Pick a real date.', 'vireo-analytics' ) );
		}

		if ( '' === $label ) {
			return new \WP_Error( 'vireo_annotation_label', __( 'Add a short note to go with the date.', 'vireo-analytics' ) );
		}

		$label = mb_substr( $label, 0, self::MAX_LABEL );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- write
		$ok = $wpdb->insert(
			self::table_name(),
			[
				'date'       => $date,
				'label'      => $label,
				'created_by' => get_current_user_id(),
				'created_at' => time(),
			],
			[ '%s', '%s', '%d', '%d' ]
		);

		if ( ! $ok ) {
			return new \WP_Error( 'vireo_annotation_write', __( 'Could not save that note.', 'vireo-analytics' ) );
		}

		return (int) $wpdb->insert_id;
	}

	public static function delete( int $id ): bool {
		global $wpdb;

		if ( $id <= 0 ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- write
		return (bool) $wpdb->delete( self::table_name(), [ 'id' => $id ], [ '%d' ] );
	}

	/** Rejects 2026-02-31 and friends, which the pattern alone lets through. */
	private static function is_real_date( string $date ): bool {
		$parts = explode( '-', $date );

		return 3 === count( $parts ) && checkdate( (int) $parts[1], (int) $parts[2], (int) $parts[0] );
	}

	/**
	 * Put the site the dashboard was showing in context, so the note lands in
	 * that site's table. An id that is not a connected site means the main
	 * site; a request cannot name a site that does not exist.
	 */
	private static function switch_to_requested_site(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked by the caller's check_ajax_referer()
		$site_id = isset( $_POST['site'] ) ? absint( $_POST['site'] ) : 0;
		if ( $site_id > 0 && null === Sites::get( $site_id ) ) {
			wp_send_json_error( [ 'message' => __( 'That site is no longer connected.', 'vireo-analytics' ) ], 404 );
		}
		Sites::switch_to( $site_id );
	}

	public static function ajax_add(): void {
		check_ajax_referer( 'vireo_annotations', 'nonce' );

		if ( ! self::can_write() ) {
			wp_send_json_error( [ 'message' => __( 'You are not allowed to add notes.', 'vireo-analytics' ) ], 403 );
		}
		self::switch_to_requested_site();

		$date  = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['date'] ) ) : '';
		$label = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['label'] ) ) : '';

		$result = self::add( $date, $label );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ], 400 );
		}

		wp_send_json_success(
			[
				'id'    => $result,
				'date'  => $date,
				'label' => mb_substr( trim( wp_strip_all_tags( $label ) ), 0, self::MAX_LABEL ),
			]
		);
	}

	public static function ajax_delete(): void {
		check_ajax_referer( 'vireo_annotations', 'nonce' );

		if ( ! self::can_write() ) {
			wp_send_json_error( [ 'message' => __( 'You are not allowed to remove notes.', 'vireo-analytics' ) ], 403 );
		}
		self::switch_to_requested_site();

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		if ( ! self::delete( $id ) ) {
			wp_send_json_error( [ 'message' => __( 'That note is already gone.', 'vireo-analytics' ) ], 404 );
		}

		wp_send_json_success( [ 'id' => $id ] );
	}
}
