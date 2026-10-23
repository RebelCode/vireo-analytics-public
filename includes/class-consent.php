<?php

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * WP Consent API integration.
 *
 * Two separate things, and they are worth keeping apart.
 *
 * The first is declaring compliance. Consent plugins (Complianz, Cookiebot,
 * Real Cookie Banner and others) read a per-plugin flag to decide whether a
 * plugin can be trusted to respect consent. Without it, a cautious setup
 * treats us as an unknown quantity. Declaring it costs one filter and is
 * honest: this plugin sets no cookies and stores nothing that identifies a
 * person, so there is nothing here for a banner to gate.
 *
 * The second is actually gating on consent, which is off by default. A
 * cookieless tracker does not need statistics consent in most jurisdictions,
 * and turning it on by default would throw away a quarter of everyone's data
 * for no legal gain. Sites with a stricter policy than the law can switch it
 * on and we will honour it.
 *
 * The check happens in the browser, not here. The tracker's markup gets baked
 * into full-page caches and replayed to every later visitor, so a server-side
 * decision made for the visitor who happened to fill the cache would be
 * applied to everybody. Consent is per-person, so it has to be asked where
 * caching cannot reach.
 */
final class Consent {

	/** The consent category analytics falls under in the WP Consent API. */
	public const CATEGORY = 'statistics';

	public static function boot(): void {
		// Tell consent plugins this one plays by the rules.
		add_filter( 'wp_consent_api_registered_' . plugin_basename( VIREO_FILE ), '__return_true' );
	}

	/**
	 * Is a consent plugin present that we could defer to?
	 */
	public static function api_active(): bool {
		return function_exists( 'wp_has_consent' );
	}

	/**
	 * Should the tracker wait for consent before recording?
	 *
	 * Off unless the site asks for it. Filter rather than a setting for now:
	 * a site strict enough to want this has someone who can add a filter, and
	 * a checkbox implying the plugin needs consent to be lawful would be
	 * misleading on a tracker that sets no cookies.
	 */
	public static function required(): bool {
		if ( ! self::api_active() ) {
			return false;
		}

		return (bool) apply_filters( 'vireo_require_consent', false );
	}

	/**
	 * The category the tracker should wait on, or an empty string to record
	 * without asking.
	 */
	public static function category_for_tracker(): string {
		return self::required() ? self::CATEGORY : '';
	}
}
