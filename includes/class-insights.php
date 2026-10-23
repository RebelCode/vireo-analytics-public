<?php

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * Composes the one-sentence lede shown above the hero row.
 *
 * Pure by design: it turns the already-computed hero and drivers arrays into a
 * sentence and never returns empty, so the dashboard always opens with an
 * insight instead of a wall of numbers. The dashboard states the facts; this
 * states the point.
 */
final class Insights {

	/**
	 * @param array $hero    Output of Dashboard::get_hero_stats(); reads
	 *                        'pageviews' and 'prior'['pageviews'] ('prior' may be null).
	 * @param array $drivers Output of Drivers::get(); reads 'headline' and the
	 *                        'pages'/'sources' gained rows (label, delta).
	 * @param bool  $compare Whether a comparison baseline was requested.
	 */
	public static function line( array $hero, array $drivers, bool $compare ): string {
		$now       = (int) ( $hero['pageviews'] ?? 0 );
		$prior_arr = is_array( $hero['prior'] ?? null ) ? $hero['prior'] : [];
		$prior     = (int) ( $prior_arr['pageviews'] ?? 0 );

		// No baseline to compare against: honest collecting state, whatever the
		// current count is. Never dress the current total up as movement.
		if ( ! $compare || $prior <= 0 ) {
			if ( $now <= 0 ) {
				return __( 'Collecting data. Your first pageviews appear within a minute; trends need a day to compare against.', 'vireo-analytics' );
			}

			/* translators: %s: number of pageviews so far */
			return sprintf( _n( 'Collecting data, %s view so far. Trends appear once there is a prior day to compare against.', 'Collecting data, %s views so far. Trends appear once there is a prior day to compare against.', $now, 'vireo-analytics' ), number_format_i18n( $now ) );
		}

		$headline = trim( (string) ( $drivers['headline'] ?? '' ) );
		$mover    = self::top_mover( $drivers );
		$has_pct  = str_contains( $headline, '%' );

		// A fall is explained by what lost traffic. Naming the biggest gainer
		// after "down 30%" would credit the one thing that went the other way.
		$cause = $now < $prior ? self::top_mover( $drivers, 'lost' ) : $mover;

		// Real movement with a single driver worth naming.
		if ( $has_pct && '' !== $cause ) {
			/* translators: 1: headline such as "Pageviews are up 18%.", 2: the top page or source */
			return sprintf( __( '%1$s Mostly %2$s.', 'vireo-analytics' ), $headline, $cause );
		}

		// Movement, but no single driver clear enough to name.
		if ( $has_pct ) {
			return $headline;
		}

		// Flat: state the level and the standout, no invented percentage.
		if ( '' !== $mover ) {
			/* translators: 1: pageviews, 2: the top page or source */
			return sprintf( _n( 'Steady, %1$s view, in line with the prior period. Top: %2$s.', 'Steady, %1$s views, in line with the prior period. Top: %2$s.', $now, 'vireo-analytics' ), number_format_i18n( $now ), $mover );
		}

		/* translators: %s: pageviews */
		return sprintf( _n( 'Steady, %s view, in line with the prior period.', 'Steady, %s views, in line with the prior period.', $now, 'vireo-analytics' ), number_format_i18n( $now ) );
	}

	/**
	 * What the Overview opens with: the drivers headline and its cause when there
	 * is a comparison, otherwise the composed line on its own. Shared so the
	 * weekly email leads with the same sentence the dashboard does.
	 *
	 * @return array{0:string,1:string} [headline, detail]. An empty headline means there is nothing to say.
	 */
	public static function lede( string $insight, array $drivers ): array {
		$head = trim( (string) ( $drivers['headline'] ?? '' ) );

		if ( '' === $head ) {
			return [ trim( $insight ), '' ];
		}

		return [ $head, trim( (string) ( $drivers['detail'] ?? '' ) ) ];
	}

	/**
	 * The single biggest gaining (or, with $side 'lost', losing) page or source,
	 * by size of delta. Pages and sources are pooled here on purpose: the lede
	 * names whichever one moved the needle most, regardless of which kind it is.
	 * Returns '' when nothing moved that way.
	 */
	private static function top_mover( array $drivers, string $side = 'gained' ): string {
		$best = '';
		$max  = 0;

		foreach ( [ 'pages', 'sources' ] as $group ) {
			foreach ( (array) ( $drivers[ $group ][ $side ] ?? [] ) as $row ) {
				$delta = abs( (int) ( $row['delta'] ?? 0 ) );
				if ( $delta > $max ) {
					$max  = $delta;
					$best = (string) ( $row['label'] ?? '' );
				}
			}
		}

		return $best;
	}
}
