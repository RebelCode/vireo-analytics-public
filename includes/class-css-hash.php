<?php
/**
 * Cache-busting for the dashboard stylesheet.
 *
 * This used to copy `{plugin}/assets/css/dashboard.css` into the uploads dir
 * under a content-hashed filename and serve it from there, to cache-bust on
 * CDNs that ignore query strings. That traded a rare cosmetic problem (a stale
 * stylesheet for a while) for a catastrophic one: serving the dashboard's only
 * stylesheet from uploads means it depends on uploads being writable, publicly
 * readable, and correctly URL-mapped. Break any one of those (a deny rule, a
 * CDN rewrite, tightened permissions) and the admin page renders as raw
 * unstyled HTML, which looks like a broken plugin.
 *
 * So we serve the plugin's own file, which is always there and always served
 * from the same place as the plugin's JS, and hang the content hash off ?ver=.
 * If a CDN drops the query string, the worst case is a stale stylesheet until
 * it expires. That's a far better failure than no stylesheet at all.
 */

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

final class CSS_Hash {

	private const OPTION = 'vireo_css_hash';

	/** Directories the old uploads-copy scheme left behind. */
	private const LEGACY_DIRS = [ 'vireo-analytics-assets', 'vireo-analytics/css' ];

	/**
	 * URL of the dashboard stylesheet. Always the plugin's own copy.
	 */
	public static function dashboard_url(): string {
		return VIREO_URL . 'assets/css/dashboard.css';
	}

	/**
	 * Content hash to pass as the enqueue version, so a changed stylesheet busts
	 * caches without the file having to move. Falls back to the plugin version
	 * when the file can't be read.
	 */
	public static function dashboard_version(): string {
		$source = VIREO_DIR . 'assets/css/dashboard.css';

		if ( ! is_readable( $source ) ) {
			return VIREO_VERSION;
		}

		// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- unreadable file falls back below
		$hash = substr( (string) @md5_file( $source ), 0, 8 );

		return '' === $hash ? VIREO_VERSION : $hash;
	}

	/**
	 * Remove the stylesheet copies the old scheme wrote into uploads, and the
	 * option that tracked them. Called on upgrade.
	 */
	public static function cleanup_legacy(): void {
		delete_option( self::OPTION );

		$base = function_exists( 'vireo_uploads_basedir' ) ? vireo_uploads_basedir() : '';
		if ( '' === $base ) {
			return;
		}

		foreach ( self::LEGACY_DIRS as $rel ) {
			$dir = $base . '/' . $rel;
			if ( ! is_dir( $dir ) ) {
				continue;
			}

			foreach ( (array) glob( $dir . '/*' ) as $file ) {
				if ( is_file( $file ) ) {
					wp_delete_file( $file );
				}
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,Generic.PHP.NoSilencedErrors.Discouraged -- best effort; a non-empty dir simply stays
			@rmdir( $dir );
		}
	}

	/**
	 * Kept so an upgrade mid-request can't fatal on an old call site.
	 *
	 * @deprecated since 0.2.8, the stylesheet is served from the plugin folder.
	 */
	public static function rebuild(): void {
		self::cleanup_legacy();
	}
}
