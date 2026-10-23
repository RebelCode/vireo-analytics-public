<?php

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * Browsers that are not people.
 *
 * Scrapers drive real browser engines, so the user-agent filter never sees
 * them, but they give themselves away: they run a Chrome a year or more out
 * of date, from hundreds of addresses, and they arrive with no referrer or
 * with the site's own host as one (they follow internal links). On one site
 * the three together were most of a country's "visitors".
 *
 * One signal is never enough. A reader on an old Chrome is a reader; a
 * returning reader opening a bookmark has no referrer. Only a stale browser
 * on a landing pageview with no outside referrer is counted as automated,
 * and the Firefox ESR line is exempt because it is old by design.
 */
final class Automation {

	/**
	 * The current major versions, updated at release. A browser this far
	 * behind, or further, counts as stale.
	 */
	public const CURRENT = [
		'chrome'  => 150,
		'edge'    => 150,
		'firefox' => 154,
	];

	public const STALE_MAJORS = 12;

	/** Firefox ESR majors still in support; enterprises run these on purpose. */
	public const FIREFOX_ESR = [ 140, 128 ];

	/**
	 * Whether the user agent is a Chrome, Edge or Firefox far behind current.
	 * Safari is left out: its version follows the operating system, and an
	 * old iPhone is an old iPhone. Anything unrecognised is not stale.
	 */
	public static function stale_browser( string $ua ): bool {
		[ $family, $major ] = self::browser( $ua );
		if ( '' === $family ) {
			return false;
		}
		if ( 'firefox' === $family && in_array( $major, self::FIREFOX_ESR, true ) ) {
			return false;
		}
		return self::CURRENT[ $family ] - $major >= self::STALE_MAJORS;
	}

	/**
	 * The browser family and major version, or ['', 0]. Edge is checked
	 * before Chrome because an Edge user agent carries both tokens.
	 *
	 * @return array{0:string,1:int}
	 */
	public static function browser( string $ua ): array {
		if ( preg_match( '~\b(?:Edg|EdgA|EdgiOS)/(\d+)~', $ua, $m ) ) {
			return [ 'edge', (int) $m[1] ];
		}
		if ( preg_match( '~\b(?:Firefox|FxiOS)/(\d+)~', $ua, $m ) ) {
			return [ 'firefox', (int) $m[1] ];
		}
		// Opera, Brave and the rest ship a Chrome token of their own engine's
		// vintage, which is the thing being measured, so they count as Chrome.
		if ( preg_match( '~\b(?:Chrome|CriOS)/(\d+)~', $ua, $m ) ) {
			return [ 'chrome', (int) $m[1] ];
		}
		return [ '', 0 ];
	}

	/**
	 * Whether a pageview is an automated browser's: a stale browser, on the
	 * visitor's first pageview of the day, that arrived with no referrer or
	 * with this site as its referrer. Pure.
	 *
	 * @param string $ua           The user agent.
	 * @param bool   $landing      First pageview for this visitor today.
	 * @param bool   $no_referrer  The hit carried no referrer at all.
	 * @param bool   $own_referrer The referrer was this site's own host.
	 */
	public static function looks_automated( string $ua, bool $landing, bool $no_referrer, bool $own_referrer ): bool {
		if ( ! $landing ) {
			return false;
		}
		if ( ! $no_referrer && ! $own_referrer ) {
			return false;
		}
		return self::stale_browser( $ua );
	}
}
