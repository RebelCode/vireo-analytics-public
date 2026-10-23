<?php
/**
 * Time-zone helpers.
 *
 * Timestamps are UTC epochs. Day *buckets* are the site's day, because "today"
 * on a dashboard means the site owner's today: a visit at 11pm in Madrid
 * belongs to that Monday, not to Tuesday because UTC had already rolled over.
 * Bucketing in UTC while the date pickers ran in site time put evening traffic
 * on the wrong day for every site that isn't on UTC.
 *
 * So: derive every stored `date` / `hour` bucket from vireo_bucket_date() and
 * vireo_bucket_hour(), and derive "today" from vireo_bucket_date() too, so the
 * admin bar, the widget, the emails and the dashboard all agree.
 *
 * Loaded early so every class file can rely on these helpers.
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'vireo_display_date' ) ) {
	/**
	 * Format a UTC epoch for display in the site's timezone.
	 */
	function vireo_display_date( int $ts, string $format = 'M j, Y' ): string {
		return wp_date( $format, $ts );
	}
}

if ( ! function_exists( 'vireo_format_day' ) ) {
	/**
	 * Format a site day ("Y-m-d") for display.
	 *
	 * A range end is a day, not an instant. Converting it to UTC midnight and
	 * formatting that in site time printed the day before everywhere west of
	 * UTC. This anchors the day at noon in the site's own timezone, which is
	 * inside the same day in every zone and on every DST changeover.
	 */
	function vireo_format_day( string $ymd, string $format = 'M j, Y' ): string {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $ymd ) ) {
			return $ymd;
		}
		// The two everyday formats are translatable, so German reads "11. Sep.
		// 2026" rather than the American order with German month names.
		if ( function_exists( '_x' ) ) {
			if ( 'M j, Y' === $format ) {
				/* translators: Short date with year. See https://www.php.net/manual/datetime.format.php */
				$format = _x( 'M j, Y', 'short date with year', 'vireo-analytics' );
			} elseif ( 'M j' === $format ) {
				/* translators: Short date without year. See https://www.php.net/manual/datetime.format.php */
				$format = _x( 'M j', 'short date without year', 'vireo-analytics' );
			}
		}
		$tz  = wp_timezone();
		$day = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $ymd . ' 12:00', $tz );
		return $day ? wp_date( $format, $day->getTimestamp(), $tz ) : $ymd;
	}
}

if ( ! function_exists( 'vireo_utc_date' ) ) {
	/**
	 * UTC "Y-m-d" bucket key for the given epoch (default: now). All daily
	 * storage tables are bucketed against this to keep joins aligned.
	 */
	function vireo_utc_date( ?int $ts = null ): string {
		return gmdate( 'Y-m-d', $ts ?? time() );
	}
}

if ( ! function_exists( 'vireo_site_date' ) ) {
	/**
	 * Site-TZ "Y-m-d" bucket key. Use for comparisons the user interprets in
	 * local time (e.g. "today" in the spike detector).
	 */
	function vireo_site_date( ?int $ts = null ): string {
		return wp_date( 'Y-m-d', $ts ?? time() );
	}
}

if ( ! function_exists( 'vireo_bucket_date' ) ) {
	/**
	 * The `date` value every daily rollup row is keyed on, and the definition of
	 * "today" everywhere it's displayed. The site's day, not UTC's.
	 */
	function vireo_bucket_date( ?int $ts = null ): string {
		return wp_date( 'Y-m-d', $ts ?? time() );
	}
}

if ( ! function_exists( 'vireo_bucket_hour' ) ) {
	/**
	 * Hour-of-day (0-23) in the site's timezone, to match vireo_bucket_date().
	 */
	function vireo_bucket_hour( ?int $ts = null ): int {
		return (int) wp_date( 'G', $ts ?? time() );
	}
}
