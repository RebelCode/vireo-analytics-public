<?php
/**
 * Tracker endpoint.
 *
 * The beacon used to post to admin-ajax.php, which boots every active plugin
 * and the theme to record one row. On a site with thirty plugins that was
 * measured at ~380ms per pageview against ~134ms to serve the cached page it
 * was tracking. It never slowed the reader down, since the beacon fires after
 * load, but it burned a PHP worker and a database connection per view, and
 * those run out before bandwidth does.
 *
 * SHORTINIT loads the database layer and core functions while skipping plugins,
 * the theme, and the REST and admin stacks. Everything the collect path needs
 * survives it except the three functions shimmed below.
 *
 * Falls back to admin-ajax when this file cannot be reached: some hosts block
 * direct PHP execution inside wp-content, so the tracker self-tests and keeps
 * using the old route where that happens. See Endpoint_Installer::verify().
 */

// This file is a front controller: it bootstraps WordPress rather than being
// loaded by it, so the usual `defined( 'ABSPATH' ) || exit;` guard cannot
// apply here. Plugin Check flags it for that reason, as it does for the
// equivalent files in other analytics plugins. Everything below runs only
// after wp-load.php has succeeded.
// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

define( 'SHORTINIT', true );

$vireo_base = dirname( __DIR__, 3 );
if ( ! file_exists( $vireo_base . '/wp-load.php' ) ) {
	// Symlinked plugin directory, or a non-standard layout.
	$vireo_real = realpath( __DIR__ . '/../../..' );
	if ( $vireo_real && file_exists( $vireo_real . '/wp-load.php' ) ) {
		$vireo_base = $vireo_real;
	} else {
		http_response_code( 500 );
		exit;
	}
}

require_once $vireo_base . '/wp-load.php';

// SHORTINIT returns before l10n.php and before the pluggable/password helpers.
// The collect path only needs these three, and loading l10n.php here would
// pull the whole translation stack back in for strings nobody reads on a
// beacon request.
if ( ! function_exists( '__' ) ) {
	/**
	 * @param string $text
	 * @return string
	 */
	function __( $text, $domain = 'default' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return $text;
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
	}
}

// The beacon lives in cached HTML, so it keeps firing for a while after the
// plugin is switched off. Bail rather than record into tables that may be gone.
$vireo_active = (array) get_option( 'active_plugins', [] );
if ( is_multisite() ) {
	$vireo_active = array_merge( $vireo_active, array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) );
}
if ( ! in_array( basename( __DIR__ ) . '/vireo-analytics.php', $vireo_active, true ) ) {
	http_response_code( 204 );
	exit;
}

define( 'VIREO_DIR', __DIR__ . '/' );

require_once __DIR__ . '/includes/va-filters.php';
require_once __DIR__ . '/includes/va-tz.php';
require_once __DIR__ . '/includes/va-goals-match.php';
require_once __DIR__ . '/includes/class-bot-filter.php';
require_once __DIR__ . '/includes/class-headless.php';
require_once __DIR__ . '/includes/class-sites.php';
require_once __DIR__ . '/includes/class-salt-manager.php';
require_once __DIR__ . '/includes/class-collector.php';
require_once __DIR__ . '/includes/class-geo-lookup.php';
require_once __DIR__ . '/vireo-analytics-collect.php';

vireo_collect_request();
