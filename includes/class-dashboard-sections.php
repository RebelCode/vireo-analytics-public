<?php
/**
 * Which sections the Overview shows, in which order, per user.
 *
 * Only the reorderable sections live here. The insight line, hero row, bot
 * note and the folded ops block are pinned in the template: they bracket the
 * page rather than compete for position within it.
 */

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

final class Dashboard_Sections {

	/** Per-user section order. A list of ids from self::definitions(). */
	public const META_KEY = 'vireo_section_order';

	/**
	 * The reorderable sections, in the order a user sees them before they
	 * touch anything. Keys are persisted, so they are never renamed.
	 *
	 * `width` drives grid placement: `full` spans every column, `third` takes
	 * one, so consecutive `third` sections form a row with no wrapper element.
	 *
	 * @return array<string, array{label:string, template:string, width:string}>
	 */
	public static function definitions(): array {
		// The trend comes first, straight under the totals, as in every
		// analytics tool people already know; what changed explains it below.
		return [
			'chart'         => [
				'label'    => __( 'Traffic chart', 'vireo-analytics' ),
				'template' => 'chart.php',
				'width'    => 'full',
			],
			'drivers'       => [
				'label'    => __( 'What changed', 'vireo-analytics' ),
				'template' => 'drivers.php',
				'width'    => 'full',
			],
			'top-pages'     => [
				'label'    => __( 'Top pages', 'vireo-analytics' ),
				'template' => 'top-pages.php',
				'width'    => 'third',
			],
			'top-referrers' => [
				'label'    => __( 'Top referrers', 'vireo-analytics' ),
				'template' => 'top-referrers.php',
				'width'    => 'third',
			],
			'countries'     => [
				'label'    => __( 'Top countries', 'vireo-analytics' ),
				'template' => 'countries.php',
				'width'    => 'third',
			],
			'devices'       => [
				'label'    => __( 'Technology', 'vireo-analytics' ),
				'template' => 'devices.php',
				'width'    => 'full',
			],
		];
	}

	/** @return string[] */
	public static function default_order(): array {
		return array_keys( self::definitions() );
	}

	/**
	 * Any stored value in, a complete valid order out.
	 *
	 * Unknown and duplicate ids are dropped. Ids the stored order never heard
	 * of are spliced back in at their default position, which is what keeps a
	 * release that adds a section from making it invisible to everyone who has
	 * ever dragged anything.
	 *
	 * @param mixed $stored
	 * @return string[]
	 */
	public static function normalize( $stored ): array {
		$default = self::default_order();

		if ( ! is_array( $stored ) ) {
			return $default;
		}

		$order = [];
		foreach ( $stored as $id ) {
			if ( is_string( $id ) && in_array( $id, $default, true ) && ! in_array( $id, $order, true ) ) {
				$order[] = $id;
			}
		}

		foreach ( $default as $index => $id ) {
			if ( ! in_array( $id, $order, true ) ) {
				array_splice( $order, min( $index, count( $order ) ), 0, [ $id ] );
			}
		}

		return $order;
	}

	/** @return string[] */
	public static function user_order( int $user_id ): array {
		return self::normalize( get_user_meta( $user_id, self::META_KEY, true ) );
	}

	/**
	 * @param mixed $ids
	 * @return string[] What was actually stored.
	 */
	public static function save_order( int $user_id, $ids ): array {
		$order = self::normalize( $ids );
		update_user_meta( $user_id, self::META_KEY, $order );
		return $order;
	}

	/**
	 * Delete rather than store the default, so a user who resets keeps
	 * following the default order if a later release changes it.
	 *
	 * @return string[]
	 */
	public static function reset_order( int $user_id ): array {
		delete_user_meta( $user_id, self::META_KEY );
		return self::default_order();
	}
}
