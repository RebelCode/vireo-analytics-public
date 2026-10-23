<?php

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * Who is allowed to look at the numbers.
 *
 * The dashboard has always been gated on `view_vireo_analytics` rather than on
 * being an administrator, which was the right shape, but only administrators
 * ever got the capability and nothing could hand it out. In practice that
 * meant showing a client or an editor their own traffic required making them
 * an administrator, which is a considerably larger grant than "can read a
 * chart".
 *
 * Reading analytics is separate from changing how analytics works. Settings,
 * imports and anything destructive stay on `manage_options`; this only ever
 * grants the read.
 */
final class Roles {

	public const CAPABILITY = 'view_vireo_analytics';

	/** Administrators always have it and it is never taken away from them. */
	public const ALWAYS = 'administrator';

	/**
	 * Roles a site owner can grant this to, as slug => display name.
	 *
	 * @return array<string,string>
	 */
	public static function assignable(): array {
		$roles = wp_roles()->get_names();
		unset( $roles[ self::ALWAYS ] );

		return array_map( 'translate_user_role', $roles );
	}

	/**
	 * Role slugs that currently hold the capability, administrators aside.
	 *
	 * Read from the roles themselves rather than from a stored setting, so a
	 * capability granted by another plugin or by WP-CLI shows up here honestly
	 * instead of the screen claiming otherwise.
	 *
	 * @return string[]
	 */
	public static function with_access(): array {
		$out = [];

		foreach ( array_keys( self::assignable() ) as $slug ) {
			$role = get_role( $slug );
			if ( $role && $role->has_cap( self::CAPABILITY ) ) {
				$out[] = $slug;
			}
		}

		return $out;
	}

	/**
	 * Make the granted set exactly $slugs. Roles not listed lose it.
	 *
	 * @param string[] $slugs
	 */
	public static function apply( array $slugs ): void {
		$valid = array_keys( self::assignable() );
		$want  = array_values( array_intersect( $slugs, $valid ) );

		foreach ( $valid as $slug ) {
			$role = get_role( $slug );
			if ( ! $role ) {
				continue;
			}

			$should_have = in_array( $slug, $want, true );
			$has         = $role->has_cap( self::CAPABILITY );

			// add_cap() and remove_cap() both write an option, so only touch a
			// role that is actually changing.
			if ( $should_have && ! $has ) {
				$role->add_cap( self::CAPABILITY );
			} elseif ( ! $should_have && $has ) {
				$role->remove_cap( self::CAPABILITY );
			}
		}

		// Whatever else happened, administrators keep it. Removing it from
		// themselves would lock the last person out of their own dashboard.
		$admin = get_role( self::ALWAYS );
		if ( $admin && ! $admin->has_cap( self::CAPABILITY ) ) {
			$admin->add_cap( self::CAPABILITY );
		}
	}
}
