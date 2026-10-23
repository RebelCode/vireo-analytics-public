<?php

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * Today's hits that are still in the buffer.
 *
 * Rollups are written by the flush cron. On a host that runs wp-cron from
 * system cron every fifteen minutes the buffer holds up to a quarter of an
 * hour of visits, and just after midnight the new day has no rollup row at
 * all, so "pageviews today" read 0 beside a live count of 10. The buffer is
 * read here instead of flushed: a flush is a write with a transaction and a
 * lock, it can take seconds for a busy site's quarter hour, and tying it to
 * an admin page load would race the cron that may be running at the same
 * moment. A read is bounded, changes nothing, and is right as of now.
 *
 * Only live buffers are read. A snapshot the aggregator has renamed is
 * mid-flush and about to land in the rollups, so counting it here would
 * count it twice for a moment; leaving it out undercounts for that moment
 * instead, which is the safer error.
 */
final class Pending {

	/** Lines read per request before giving up; a quarter hour on a very busy site. */
	public const MAX_LINES = 20000;

	/**
	 * Today's buffered pageviews and visitors for the site in context.
	 *
	 * @return array{pageviews:int,visitors:int,capped:bool}
	 */
	public static function today(): array {
		$files = Collector::live_buffers();
		if ( [] === $files ) {
			return [ 'pageviews' => 0, 'visitors' => 0, 'capped' => false ];
		}

		// Keyed on the files' size and age, so a widget opened twice in a
		// minute parses once, and a flush in between invalidates it.
		$stamp = '';
		foreach ( $files as $file ) {
			$stamp .= $file . ':' . (int) @filesize( $file ) . ':' . (int) @filemtime( $file ) . ';'; // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- a vanished file is just absent
		}
		$key   = 'vireo_pending_' . md5( Sites::current() . '|' . $stamp );
		$found = get_transient( $key );
		if ( is_array( $found ) ) {
			return $found;
		}

		$rules    = function_exists( 'vireo_get_exclusions' ) ? vireo_get_exclusions() : [];
		$excluded = array_map( 'strtoupper', (array) ( $rules['countries'] ?? [] ) );
		$found    = self::count( $files, vireo_bucket_date(), $excluded, [ self::class, 'unseen' ] );

		set_transient( $key, $found, MINUTE_IN_SECONDS );
		return $found;
	}

	/**
	 * Count the pageviews and visitors of one day in buffer files.
	 *
	 * Pure apart from the file reads, so it can be tested on a file written
	 * by hand. Bots never reach the buffer as pageviews (the endpoint writes
	 * them as 'b' records), so the exclusions applied here are the ones the
	 * aggregator applies at fold time: a country on the owner's list, when
	 * the hit carries one. Each site has its own buffer directory, so the
	 * files already belong to the site in context. The per-visitor daily cap
	 * is not applied; it would cost a query per hit for a difference of a
	 * view or two.
	 *
	 * @param array<int,string> $files    Buffer paths.
	 * @param string            $date     The site day to count, Y-m-d.
	 * @param array<int,string> $excluded Upper-case country codes to leave out.
	 * @param callable|null     $unseen   Given the distinct visitor hashes, returns how many are not yet in the rollups.
	 * @return array{pageviews:int,visitors:int,capped:bool}
	 */
	public static function count( array $files, string $date, array $excluded = [], ?callable $unseen = null ): array {
		$pageviews = 0;
		$hashes    = [];
		$lines     = 0;
		$capped    = false;

		foreach ( $files as $file ) {
			$handle = @fopen( $file, 'rb' ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- read-only stream over our own buffer; it may have just been renamed away
			if ( ! $handle ) {
				continue;
			}
			while ( false !== ( $line = fgets( $handle ) ) ) {
				if ( ++$lines > self::MAX_LINES ) {
					$capped = true;
					break 2;
				}
				$line = trim( $line );
				// The record starts a:N:{i:0;s:1:"p"; so the type byte sits at a
				// fixed offset once N is one or two digits; anything else is
				// skipped without unserializing.
				if ( '' === $line || false === strpos( substr( $line, 0, 24 ), 's:1:"p";' ) ) {
					continue;
				}
				$record = @unserialize( $line, [ 'allowed_classes' => false ] ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- a torn line is skipped
				if ( ! is_array( $record ) || 'p' !== ( $record[0] ?? '' ) ) {
					continue;
				}
				if ( vireo_bucket_date( (int) ( $record[1] ?? 0 ) ) !== $date ) {
					continue;
				}
				$country = strtoupper( (string) ( $record[10] ?? '' ) );
				if ( '' !== $country && in_array( $country, $excluded, true ) ) {
					continue;
				}
				$pageviews++;
				$hash = (string) ( $record[9] ?? '' );
				if ( '' !== $hash ) {
					$hashes[ $hash ] = true;
				}
			}
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- pairs with the fopen above
		}

		$distinct = array_keys( $hashes );
		$visitors = null === $unseen ? count( $distinct ) : (int) $unseen( $distinct, $date );

		return [
			'pageviews' => $pageviews,
			'visitors'  => $visitors,
			'capped'    => $capped,
		];
	}

	/**
	 * How many of these visitors the rollups have not counted today. The
	 * aggregator marks each counted visitor in va_visitor_seen under the
	 * 'site' scope; a hash already there is a returning visitor, not a new one.
	 *
	 * @param array<int,string> $hashes
	 */
	public static function unseen( array $hashes, string $date ): int {
		global $wpdb;

		if ( [] === $hashes ) {
			return 0;
		}
		$table = Sites::table( 'visitor_seen' );
		if ( ! Aggregator::table_ready( $table ) ) {
			return count( $hashes );
		}

		$keys = [];
		foreach ( $hashes as $hash ) {
			$keys[] = md5( 'site||' . $hash );
		}

		$seen = 0;
		foreach ( array_chunk( $keys, 500 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built from a counted array; the caller caches for a minute
			$seen += (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i WHERE date = %s AND dedupe_key IN ($placeholders)",
					array_merge( [ $table, $date ], $chunk )
				)
			);
		}
		return max( 0, count( $hashes ) - $seen );
	}
}
