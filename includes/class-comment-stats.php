<?php
/**
 * WordPress's own comment counts, for showing next to views.
 *
 * Nothing new is recorded: this reads wp_comments, approved reader comments
 * only, with one grouped query per screen. Results go through the object
 * cache keyed on core's comment "last changed" marker, the same way core
 * caches comment queries, so a new or moderated comment shows at once.
 */

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

final class Comment_Stats {

	/**
	 * Reader comments. '' is how comments were typed before WordPress 5.5,
	 * 'review' is WooCommerce product reviews. Pingbacks, trackbacks and the
	 * block editor's notes are left out.
	 */
	public const COUNTED_TYPES = [ 'comment', '', 'review' ];

	/** Below this many views a per-1,000 rate is noise. */
	public const MIN_VIEWS_FOR_RATE = 100;

	private const CACHE_GROUP = 'vireo_comments';

	/**
	 * Comments per 1,000 views, one decimal. Null when views are too few for
	 * the number to mean anything.
	 */
	public static function rate( int $comments, int $views ): ?float {
		if ( $comments < 0 || $views < self::MIN_VIEWS_FOR_RATE ) {
			return null;
		}
		return round( $comments * 1000 / $views, 1 );
	}

	/**
	 * Site-local datetimes covering every second of the given days.
	 *
	 * @return array{0:string,1:string}
	 */
	public static function local_bounds( string $start, string $end ): array {
		if ( strcmp( $end, $start ) < 0 ) {
			[ $start, $end ] = [ $end, $start ];
		}
		return [ $start . ' 00:00:00', $end . ' 23:59:59' ];
	}

	/**
	 * @param array<int|string,mixed> $ids
	 * @return int[]
	 */
	public static function normalize_ids( array $ids ): array {
		$out = [];
		foreach ( $ids as $id ) {
			if ( is_numeric( $id ) && (int) $id > 0 ) {
				$out[ (int) $id ] = (int) $id;
			}
		}
		sort( $out );
		return array_values( $out );
	}

	/**
	 * @param array<int|string,mixed> $ids
	 */
	public static function cache_key( array $ids, string $start, string $end, string $last_changed ): string {
		return 'counts_' . md5( implode( ',', self::normalize_ids( $ids ) ) . '|' . $start . '|' . $end . '|' . $last_changed );
	}

	/**
	 * Approved reader comments per post, dated within [start, end] in site
	 * days. Posts with none are absent from the result.
	 *
	 * @param array<int|string,mixed> $post_ids
	 * @return array<int,int> post_id => comments
	 */
	public static function counts( array $post_ids, string $start, string $end ): array {
		$ids = self::normalize_ids( $post_ids );
		if ( empty( $ids ) ) {
			return [];
		}

		$key    = self::cache_key( $ids, $start, $end, (string) wp_cache_get_last_changed( 'comment' ) );
		$cached = wp_cache_get( $key, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		// comment_date_gmt is indexed with comment_approved; the local day
		// edges are converted to it rather than comparing comment_date.
		[ $from, $to ] = self::local_bounds( $start, $end );
		$from          = get_gmt_from_date( $from );
		$to            = get_gmt_from_date( $to );

		global $wpdb;
		$id_ph   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$type_ph = implode( ',', array_fill( 0, count( self::COUNTED_TYPES ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders are generated above from fixed %d/%s lists
		$sql = $wpdb->prepare(
			"SELECT comment_post_ID AS post_id, COUNT(*) AS comments
			 FROM %i
			 WHERE comment_post_ID IN ({$id_ph})
			   AND comment_approved = '1'
			   AND comment_type IN ({$type_ph})
			   AND comment_date_gmt BETWEEN %s AND %s
			 GROUP BY comment_post_ID",
			...array_merge( [ $wpdb->comments ], $ids, self::COUNTED_TYPES, [ $from, $to ] )
		);
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- object-cached above; $sql is prepared
		$rows = (array) $wpdb->get_results( $sql, ARRAY_A );

		$out = [];
		foreach ( $rows as $row ) {
			$out[ (int) $row['post_id'] ] = (int) $row['comments'];
		}

		wp_cache_set( $key, $out, self::CACHE_GROUP, DAY_IN_SECONDS );
		return $out;
	}

	public static function comments_label( int $comments ): string {
		return sprintf(
			/* translators: %s: number of comments */
			_n( '%s comment', '%s comments', $comments, 'vireo-analytics' ),
			number_format_i18n( $comments )
		);
	}

	public static function rate_label( float $rate ): string {
		return sprintf(
			/* translators: %s: comments per 1,000 pageviews, e.g. 2.4 */
			__( '%s per 1,000 views', 'vireo-analytics' ),
			number_format_i18n( $rate, 1 )
		);
	}
}
