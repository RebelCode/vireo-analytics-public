<?php

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * What has already been imported, and from where.
 *
 * Every importer writes with `pageviews = pageviews + VALUES(pageviews)`, so a
 * range imported twice is counted twice. Nothing recorded what had been brought
 * in, so the only protection was a sentence of warning text above the button
 * and whatever the person happened to remember.
 *
 * This does not make an import reversible. Undoing one means knowing which
 * source contributed which part of each row, across every rollup table, and
 * that is not recorded. What it does is stop the common way people get there:
 * running a range that overlaps one already imported, without being told.
 */
final class Import_History {

	private const OPTION = 'vireo_import_history';

	/** Keep the list from growing without bound on a site that imports often. */
	private const MAX_ENTRIES = 100;

	/**
	 * `start`/`end` are the range that was asked for, empty when the importer
	 * takes everything. `first`/`last`, `days` and `pageviews` are what was
	 * actually written. `own_from` is the first day Vireo had recorded itself
	 * when the run started. Entries recorded by earlier versions have none of
	 * those, and read as zero and empty.
	 *
	 * @return array<int,array{source:string,start:string,end:string,at:int,user:int,first:string,last:string,days:int,pageviews:int,own_from:string}>
	 */
	public static function all( string $source = '' ): array {
		$rows = get_option( self::OPTION, [] );
		$rows = is_array( $rows ) ? $rows : [];

		$out = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['source'] ) ) {
				continue;
			}
			if ( '' !== $source && $row['source'] !== $source ) {
				continue;
			}
			$out[] = [
				'source'    => (string) $row['source'],
				'start'     => (string) ( $row['start'] ?? '' ),
				'end'       => (string) ( $row['end'] ?? '' ),
				'at'        => (int) ( $row['at'] ?? 0 ),
				'user'      => (int) ( $row['user'] ?? 0 ),
				'first'     => (string) ( $row['first'] ?? '' ),
				'last'      => (string) ( $row['last'] ?? '' ),
				'days'      => (int) ( $row['days'] ?? 0 ),
				'pageviews' => (int) ( $row['pageviews'] ?? 0 ),
				'own_from'  => (string) ( $row['own_from'] ?? '' ),
			];
		}

		usort( $out, static fn( array $a, array $b ): int => $b['at'] <=> $a['at'] );

		return $out;
	}

	/** The most recent import from a source, or null when there has been none. */
	public static function latest( string $source ): ?array {
		return self::all( $source )[0] ?? null;
	}

	/**
	 * @param array{first?:string,last?:string,days?:int,pageviews?:int,own_from?:string} $written What the run wrote.
	 */
	public static function record( string $source, string $start, string $end, array $written = [] ): void {
		if ( '' === $source ) {
			return;
		}

		$rows = get_option( self::OPTION, [] );
		$rows = is_array( $rows ) ? $rows : [];

		array_unshift(
			$rows,
			[
				'source'    => $source,
				'start'     => $start,
				'end'       => $end,
				'at'        => time(),
				'user'      => get_current_user_id(),
				'first'     => (string) ( $written['first'] ?? '' ),
				'last'      => (string) ( $written['last'] ?? '' ),
				'days'      => (int) ( $written['days'] ?? 0 ),
				'pageviews' => (int) ( $written['pageviews'] ?? 0 ),
				'own_from'  => (string) ( $written['own_from'] ?? '' ),
			]
		);

		update_option( self::OPTION, array_slice( $rows, 0, self::MAX_ENTRIES ), false );
	}

	/**
	 * Previous imports from the same source whose dates overlap this range.
	 *
	 * An entry with no recorded range (an importer that takes everything it can
	 * find) counts as overlapping anything, because there is no way to show it
	 * does not.
	 *
	 * @return array<int,array{source:string,start:string,end:string,at:int,user:int}>
	 */
	public static function overlapping( string $source, string $start, string $end ): array {
		$hits = [];

		foreach ( self::all( $source ) as $row ) {
			if ( '' === $row['start'] || '' === $row['end'] ) {
				$hits[] = $row;
				continue;
			}
			if ( '' !== $start && '' !== $end && ( $row['end'] < $start || $row['start'] > $end ) ) {
				continue;
			}
			$hits[] = $row;
		}

		return $hits;
	}

	/**
	 * One sentence saying when a run happened and what it brought in.
	 *
	 * @param array{at:int,start:string,end:string,first:string,last:string,days:int,pageviews:int} $run
	 */
	public static function summary( array $run ): string {
		$format = (string) get_option( 'date_format', 'F j, Y' );
		// Every date in the sentence in the site's own format. The range used
		// to print as 2025-06-29 beside "April 1, 2026".
		$day = static fn( string $ymd ): string => vireo_format_day( $ymd, $format );

		$on = wp_date( $format, $run['at'] );
		$at = wp_date( (string) get_option( 'time_format', 'g:i a' ), $run['at'] );

		if ( $run['days'] > 0 && '' !== $run['first'] ) {
			return sprintf(
				/* translators: 1: date, 2: time, 3: number of days, 4: first day, 5: last day, 6: number of pageviews */
				_n(
					'Imported on %1$s at %2$s: %3$s day of history, %4$s to %5$s, %6$s pageviews.',
					'Imported on %1$s at %2$s: %3$s days of history, %4$s to %5$s, %6$s pageviews.',
					$run['days'],
					'vireo-analytics'
				),
				$on,
				$at,
				number_format_i18n( $run['days'] ),
				$day( $run['first'] ),
				$day( $run['last'] ),
				number_format_i18n( $run['pageviews'] )
			);
		}

		if ( '' !== $run['start'] && '' !== $run['end'] ) {
			return sprintf(
				/* translators: 1: date, 2: time, 3: first day of the range, 4: last day of the range */
				__( 'Imported on %1$s at %2$s, covering %3$s to %4$s.', 'vireo-analytics' ),
				$on,
				$at,
				$day( $run['start'] ),
				$day( $run['end'] )
			);
		}

		/* translators: 1: date, 2: time */
		return sprintf( __( 'Imported on %1$s at %2$s.', 'vireo-analytics' ), $on, $at );
	}

	/**
	 * One sentence naming what already covers this range.
	 */
	public static function describe( array $hits ): string {
		$parts  = [];
		$format = (string) get_option( 'date_format', 'F j, Y' );
		foreach ( array_slice( $hits, 0, 3 ) as $hit ) {
			$parts[] = ( '' !== $hit['start'] && '' !== $hit['end'] )
				? sprintf( '%s to %s', vireo_format_day( $hit['start'], $format ), vireo_format_day( $hit['end'], $format ) )
				: __( 'everything available', 'vireo-analytics' );
		}

		return implode( ', ', $parts );
	}

	/**
	 * The first day Vireo Analytics recorded itself, or null when it has
	 * recorded nothing yet.
	 *
	 * An import into a day Vireo also tracked adds the source's count on top
	 * of Vireo's own, so imports stop before this day unless told otherwise.
	 */
	public static function own_data_start(): ?string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- read once per import run and per import screen
		$dates = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT date FROM %i WHERE pageviews > 0 OR visitors > 0 ORDER BY date',
				Sites::table( 'site_stats' )
			)
		);

		return self::first_own_day( array_map( 'strval', (array) $dates ), self::all() );
	}

	/**
	 * The earliest stored day that no import accounts for.
	 *
	 * The stored totals don't say where they came from, so the history does:
	 * a run is placed by the days it actually wrote, or by the range it asked
	 * for when it was recorded before that was kept. A run with neither can't
	 * be placed and covers nothing, which can stop an import earlier than it
	 * needed to but never lets it count a day twice. A run that was told to
	 * include Vireo's own days wrote across them, but they stay Vireo's own, so
	 * it only covers the days before them.
	 *
	 * @param string[]                                                                           $dates Days with stored totals.
	 * @param array<int,array{start:string,end:string,first:string,last:string,own_from:string}> $runs  Import history entries.
	 */
	public static function first_own_day( array $dates, array $runs ): ?string {
		$covered = self::covered_ranges( $runs );

		sort( $dates, SORT_STRING );
		foreach ( $dates as $date ) {
			foreach ( $covered as $range ) {
				if ( $date >= $range['from'] && $date <= $range['to'] ) {
					continue 2;
				}
			}
			return $date;
		}

		return null;
	}

	/**
	 * The days each run accounts for, placed the way first_own_day() describes.
	 * The year in review uses the same placement to say which months were
	 * imported, so the two can never disagree about whose day it was.
	 *
	 * @param array<int,array{start:string,end:string,first:string,last:string,own_from:string,source?:string}> $runs Import history entries.
	 * @return array<int,array{from:string,to:string,source:string}>
	 */
	public static function covered_ranges( array $runs ): array {
		$covered = [];
		foreach ( $runs as $run ) {
			$from = '' !== $run['first'] ? $run['first'] : $run['start'];
			$to   = '' !== $run['last'] ? $run['last'] : $run['end'];
			if ( '' !== $run['own_from'] && '' !== $to ) {
				$to = min( $to, self::day_before( $run['own_from'] ) );
			}
			if ( '' !== $from && '' !== $to ) {
				$covered[] = [
					'from'   => $from,
					'to'     => $to,
					'source' => (string) ( $run['source'] ?? '' ),
				];
			}
		}

		return $covered;
	}

	/**
	 * The name of the tool whose import covers any day of a range, '' when
	 * the range is Vireo's own. The dashboard's year-over-year comparison
	 * says where last year's figures came from.
	 */
	public static function source_covering( string $from, string $to ): string {
		foreach ( self::covered_ranges( self::all() ) as $range ) {
			if ( $range['from'] <= $to && $range['to'] >= $from ) {
				$importer = class_exists( Import_Controller::class ) ? Import_Controller::instance()->get( $range['source'] ) : null;
				return $importer ? $importer->name() : $range['source'];
			}
		}
		return '';
	}

	public static function day_before( string $date ): string {
		return gmdate( 'Y-m-d', (int) strtotime( $date . ' -1 day UTC' ) );
	}

	public static function forget_all(): void {
		delete_option( self::OPTION );
	}
}
