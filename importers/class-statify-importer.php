<?php

namespace VireoAnalytics\Importers;

defined( 'ABSPATH' ) || exit;

/**
 * Import history from Statify.
 *
 * Statify keeps one row per view with three columns: the date, the referrer,
 * and the path. There is no visitor concept at all, which is deliberate on
 * their part, so visitors are imported as zero rather than invented. Pageviews
 * and referrers come across intact.
 */
final class Statify_Importer extends Importer_Base {

	public function slug(): string {
		return 'statify';
	}

	public function name(): string {
		return __( 'Statify', 'vireo-analytics' );
	}

	public function is_available(): bool {
		global $wpdb;
		return self::table_has_rows( $wpdb->prefix . 'statify' );
	}

	public function count_records(): int {
		global $wpdb;
		if ( ! $this->is_available() ) {
			return 0;
		}
		$table = $wpdb->prefix . 'statify';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- import source
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT created) FROM %i', $table ) );
	}

	/**
	 * One chunk is a run of days. Statify stores a row per view, so the work is
	 * grouping by date and by path.
	 *
	 * @param array<string,mixed> $params
	 * @return array<string,mixed>
	 */
	public function import_chunk( array $params, int $offset, int $limit ): array {
		global $wpdb;

		$total = $this->count_records();
		if ( 0 === $total ) {
			return [
				'done'        => true,
				'processed'   => 0,
				'total'       => 0,
				'next_offset' => 0,
				'label'       => __( 'No Statify data found.', 'vireo-analytics' ),
			];
		}

		$table     = $wpdb->prefix . 'statify';
		$limit     = max( 1, min( 30, $limit ) );
		$site_host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- import source
		$dates = (array) $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT created FROM %i ORDER BY created ASC LIMIT %d OFFSET %d',
				$table,
				$limit,
				$offset
			)
		);

		if ( ! $dates ) {
			return [
				'done'        => true,
				'processed'   => $offset,
				'total'       => $total,
				'next_offset' => $offset,
				'label'       => __( 'Statify import complete.', 'vireo-analytics' ),
			];
		}

		foreach ( $dates as $date ) {
			$date = (string) $date;

			// Daily totals. Statify has no visitor identity, so visitors stay 0
			// rather than being guessed at from row counts.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- import source
			$pageviews = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE created = %s', $table, $date )
			);
			$this->upsert_site_stats( $date, 0, $pageviews );

			// Per-path totals.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- import source
			$paths = (array) $wpdb->get_results(
				$wpdb->prepare(
					'SELECT target AS path, COUNT(*) AS views FROM %i WHERE created = %s AND target <> %s GROUP BY target',
					$table,
					$date,
					''
				),
				ARRAY_A
			);

			$rows = [];
			foreach ( $paths as $row ) {
				$rows[] = [
					'date'      => $date,
					'path'      => (string) $row['path'],
					'post_id'   => (int) url_to_postid( home_url( (string) $row['path'] ) ),
					'pageviews' => (int) $row['views'],
					'visitors'  => 0,
				];
			}
			if ( $rows ) {
				$this->upsert_post_stats_bulk( $rows );
			}

			// Referrers, external only. Statify already excludes internal ones.
			// Each row also has the page it led to, so referrers per post too.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- import source
			$referrers = (array) $wpdb->get_results(
				$wpdb->prepare(
					'SELECT referrer, target, COUNT(*) AS views FROM %i WHERE created = %s AND referrer <> %s GROUP BY referrer, target',
					$table,
					$date,
					''
				),
				ARRAY_A
			);

			$post_ids = [];
			foreach ( $rows as $r ) {
				$post_ids[ $r['path'] ] = $r['post_id'];
			}
			$refs = self::referrer_rows( $date, $referrers, $post_ids, $site_host );
			$this->upsert_referrer_stats_bulk( $refs['site'] );
			$this->upsert_post_referrer_stats_bulk( $refs['posts'] );

			$this->assert_no_db_error();
		}

		$next = $offset + count( $dates );

		return [
			'done'        => $next >= $total,
			'processed'   => $next,
			'total'       => $total,
			'next_offset' => $next,
			/* translators: 1: days imported, 2: total days. */
			'label'       => sprintf( _n( 'Imported %1$d of %2$d day from Statify.', 'Imported %1$d of %2$d days from Statify.', $total, 'vireo-analytics' ), $next, $total ),
		];
	}

	public function carries(): string {
		return __( 'Brings across daily pageviews, your pages, referrers and which posts each referrer sent readers to. Statify does not identify visitors, so imported days have no visitor counts.', 'vireo-analytics' );
	}

	/**
	 * Visitors stay 0: Statify has no visitor to count.
	 *
	 * @param array<int, array{referrer:string, target:string, views:int|string}> $raw
	 * @param array<string,int>                                                   $post_ids Path => post id, from the day's pages.
	 * @return array{site: array<int,array<string,mixed>>, posts: array<int,array<string,mixed>>}
	 */
	public static function referrer_rows( string $date, array $raw, array $post_ids, string $self_host ): array {
		$site  = [];
		$posts = [];
		foreach ( $raw as $r ) {
			$host = self::referrer_host( (string) $r['referrer'], $self_host );
			if ( '' === $host ) {
				continue;
			}
			$site[]  = [
				'date'      => $date,
				'host'      => $host,
				'pageviews' => (int) $r['views'],
				'visitors'  => 0,
			];
			$posts[] = [
				'date'      => $date,
				'post_id'   => (int) ( $post_ids[ (string) $r['target'] ] ?? 0 ),
				'host'      => $host,
				'pageviews' => (int) $r['views'],
				'visitors'  => 0,
			];
		}
		return [
			'site'  => self::merge_rows( $site, [ 'host' ], [ 'pageviews' ] ),
			'posts' => self::merge_rows( $posts, [ 'post_id', 'host' ], [ 'pageviews' ] ),
		];
	}
}
