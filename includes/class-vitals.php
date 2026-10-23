<?php

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * Core Web Vitals, read back.
 *
 * Reports the 75th percentile, because that is what Google grades on and what
 * everyone else quotes. An average is the wrong number here and comfortably
 * the more flattering one: a page that is fast for three readers in four and
 * dreadful for the fourth averages out fine and still fails.
 *
 * The percentile comes out of the stored histogram, so its resolution is one
 * bucket: 100ms for LCP, 25ms for INP, 0.01 for CLS. That is finer than the
 * thresholds it is judged against, and the alternative, keeping every sample,
 * means a row per visit forever.
 */
final class Vitals {

	public const TABLE = 'vitals_stats';

	/** Thresholds Google grades on: at or below [0] is good, above [1] is poor. */
	private const THRESHOLDS = [
		'lcp' => [ 2500, 4000 ],
		'inp' => [ 200, 500 ],
		'cls' => [ 100, 250 ],
	];

	/**
	 * The three metrics for a range.
	 *
	 * @return array<string, array{p75:int, samples:int, rating:string, display:string}>
	 */
	public static function summary( string $start, string $end ): array {
		global $wpdb;

		$table = Sites::table( self::TABLE );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- schema probe, uncacheable
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return [];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- reporting read
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT metric, bucket, SUM(samples) AS samples
				 FROM {$table}
				 WHERE date BETWEEN %s AND %s
				 GROUP BY metric, bucket
				 ORDER BY metric, bucket",
				$start,
				$end
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) || [] === $rows ) {
			return [];
		}

		$histograms = [];
		foreach ( $rows as $row ) {
			$histograms[ (string) $row['metric'] ][ (int) $row['bucket'] ] = (int) $row['samples'];
		}

		$out = [];
		foreach ( [ 'lcp', 'inp', 'cls' ] as $metric ) {
			if ( empty( $histograms[ $metric ] ) ) {
				continue;
			}

			$buckets = $histograms[ $metric ];
			$total   = array_sum( $buckets );
			if ( $total < 1 ) {
				continue;
			}

			$p75 = self::percentile( $metric, $buckets, $total, 0.75 );

			$out[ $metric ] = [
				'p75'     => $p75,
				'samples' => $total,
				'rating'  => self::rating( $metric, $p75 ),
				'display' => self::display( $metric, $p75 ),
			];
		}

		return $out;
	}

	/**
	 * Walk the histogram until the requested share of samples is behind us, and
	 * report the top of the bucket we land in. Reporting the bottom would
	 * flatter every result by a bucket width.
	 *
	 * @param array<int,int> $buckets
	 */
	private static function percentile( string $metric, array $buckets, int $total, float $share ): int {
		ksort( $buckets );

		$target     = $total * $share;
		$cumulative = 0;

		foreach ( $buckets as $bucket => $samples ) {
			$cumulative += $samples;
			if ( $cumulative >= $target ) {
				return Aggregator::vitals_bucket_ceiling( $metric, (int) $bucket );
			}
		}

		$last = array_key_last( $buckets );

		return Aggregator::vitals_bucket_ceiling( $metric, (int) $last );
	}

	public static function rating( string $metric, int $value ): string {
		$bounds = self::THRESHOLDS[ $metric ] ?? null;
		if ( null === $bounds ) {
			return 'unknown';
		}

		if ( $value <= $bounds[0] ) {
			return 'good';
		}

		return $value <= $bounds[1] ? 'needs-improvement' : 'poor';
	}

	/** Human-facing value: seconds for LCP, milliseconds for INP, a ratio for CLS. */
	public static function display( string $metric, int $value ): string {
		if ( 'cls' === $metric ) {
			return number_format_i18n( $value / 1000, 2 );
		}

		if ( 'lcp' === $metric ) {
			return sprintf(
				/* translators: %s: a duration in seconds, e.g. "2.4" */
				__( '%ss', 'vireo-analytics' ),
				number_format_i18n( $value / 1000, 1 )
			);
		}

		return sprintf(
			/* translators: %s: a duration in milliseconds */
			__( '%sms', 'vireo-analytics' ),
			number_format_i18n( $value )
		);
	}

	public static function label( string $metric ): string {
		switch ( $metric ) {
			case 'lcp':
				return __( 'Load time', 'vireo-analytics' );
			case 'inp':
				return __( 'Response to clicks', 'vireo-analytics' );
			case 'cls':
				return __( 'Layout shift', 'vireo-analytics' );
			default:
				return $metric;
		}
	}

	/**
	 * Google's grade in words, so the value is not left to colour alone.
	 */
	public static function rating_label( string $rating ): string {
		switch ( $rating ) {
			case 'good':
				return __( 'Good', 'vireo-analytics' );
			case 'needs-improvement':
				return __( 'Needs work', 'vireo-analytics' );
			case 'poor':
				return __( 'Poor', 'vireo-analytics' );
			default:
				return '';
		}
	}

	public static function explanation( string $metric ): string {
		switch ( $metric ) {
			case 'lcp':
				return __( 'How long until the main content appeared.', 'vireo-analytics' );
			case 'inp':
				return __( 'How long the page took to respond when someone interacted with it.', 'vireo-analytics' );
			case 'cls':
				return __( 'How much the page moved around while it loaded.', 'vireo-analytics' );
			default:
				return '';
		}
	}
}
