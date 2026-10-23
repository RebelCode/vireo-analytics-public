<?php

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * Prepares the runtime data directory at `wp-content/uploads/vireo-analytics/`.
 *
 * The directory holds only the aggregator's buffer files (serialized hit data,
 * never executable code). It lives in uploads so plugin updates don't wipe it.
 * On activation we create + harden the directory and sweep up artifacts from
 * older builds: the ABSPATH tracker shim and the compiled PHP files
 * (salt / exclusions / goals) earlier versions wrote here.
 */
final class Endpoint_Installer {

	/**
	 * Compiled PHP files written by older builds. Deleted on activation —
	 * the tracker now reads salt, exclusions and goals straight from the DB.
	 */
	private const LEGACY_FILES = [ 'va-salt.php', 'va-exclusions.php', 'va-goals.php' ];

	/** Whether endpoint.php answered a test ping. */
	private const STATUS_OPTION = 'vireo_endpoint_usable';

	/** Unix time of the last check, so the recheck cron can back off. */
	private const CHECKED_OPTION = 'vireo_endpoint_checked_at';

	/**
	 * Filters a site can set that the SHORTINIT endpoint would never see,
	 * because the code registering them is never loaded. Using any of them
	 * means the fast path would quietly behave differently from the documented
	 * behaviour, so the site stays on admin-ajax instead.
	 */
	private const SHORTINIT_BLIND_FILTERS = [
		'vireo_trust_proxy_headers',
		'vireo_beacon_rate_limit_per_minute',
		'vireo_honor_opt_out',
		'vireo_bot_ua_pattern',
		'vireo_geo_country_headers',
		'vireo_beacon_rate_limit_shard_keys',
		'vireo_headless_allowed_origins',
		'vireo_buffer_dir_max_bytes',
		'vireo_bot_signatures',
	];

	/**
	 * Create + harden the data directory and remove legacy artifacts.
	 * Idempotent — safe on every activation.
	 */
	public static function install(): bool {
		$dir = vireo_data_dir();
		if ( '' === $dir ) {
			return false;
		}
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) && ! is_dir( $dir ) ) {
			return false;
		}

		Salt_Manager::harden_directory( $dir );

		self::cleanup_legacy_shim();
		self::purge_legacy_files();

		// The beacon runs under SHORTINIT and can't call wp_upload_dir(), so
		// resolve the path once here, while the full stack is loaded, and leave
		// it in an option for the endpoint to read.
		vireo_uploads_basedir();

		self::verify();
		self::schedule_recheck();

		return true;
	}

	/**
	 * URL of the direct tracker endpoint.
	 */
	public static function url(): string {
		return (string) apply_filters(
			'vireo_endpoint_url',
			plugins_url( 'endpoint.php', VIREO_DIR . 'vireo-analytics.php' )
		);
	}

	/**
	 * True when the endpoint URL points somewhere other than this site.
	 *
	 * CDN plugins rewrite plugins_url() to their own host. A beacon sent there
	 * either never reaches PHP or is answered from an edge cache, and a cached
	 * tracking pixel counts one pageview for every reader who shares that cache
	 * entry. Better to notice and stay on admin-ajax.
	 */
	private static function url_is_offsite(): bool {
		$endpoint_host = wp_parse_url( self::url(), PHP_URL_HOST );
		$site_host     = wp_parse_url( home_url( '/' ), PHP_URL_HOST );

		if ( ! is_string( $endpoint_host ) || ! is_string( $site_host ) ) {
			return false;
		}

		return strtolower( $endpoint_host ) !== strtolower( $site_host );
	}

	/**
	 * True when the tracker should post to endpoint.php rather than admin-ajax.
	 */
	public static function is_usable(): bool {
		return (bool) get_option( self::STATUS_OPTION, false );
	}

	/**
	 * Check that endpoint.php is reachable and behaving, and record the answer.
	 *
	 * Plenty of hosts refuse to execute PHP inside wp-content, and some WAFs
	 * block requests to a plugin file outright. Both fail closed here: the
	 * option stays false and the tracker keeps using admin-ajax, which is
	 * slower but always reachable. Better a slow beacon than no data.
	 */
	public static function verify(): bool {
		// Escape hatch for sites where loopback is blocked but the endpoint is
		// fine. Without it those sites would sit on the slow path forever,
		// because a failed loopback says nothing about whether a real visitor
		// can reach the file.
		// Nothing else loads under SHORTINIT, so no plugin, mu-plugin or theme
		// gets to register a hook. Every filter below would silently return its
		// default on the fast path, which is not a small difference: with
		// vireo_trust_proxy_headers set, a site behind Cloudflare would fall back
		// to the edge IP, and the rate limit and visitor hash would key on that
		// instead of on the visitor. A site that customises any of these keeps
		// the slower admin-ajax route, where its filters actually run.
		if ( self::url_is_offsite() ) {
			update_option( self::STATUS_OPTION, false, false );
			update_option( self::CHECKED_OPTION, time(), false );
			return false;
		}

		foreach ( self::SHORTINIT_BLIND_FILTERS as $hook ) {
			if ( has_filter( $hook ) ) {
				update_option( self::STATUS_OPTION, false, false );
				update_option( self::CHECKED_OPTION, time(), false );
				return false;
			}
		}

		$forced = apply_filters( 'vireo_force_direct_endpoint', null );
		if ( null !== $forced ) {
			update_option( self::STATUS_OPTION, (bool) $forced, false );
			update_option( self::CHECKED_OPTION, time(), false );
			return (bool) $forced;
		}

		$response = wp_remote_post(
			self::url(),
			[
				'timeout'   => 5,
				'blocking'  => true,
				'sslverify' => false,
				'body'      => [ 'test' => '1' ],
			]
		);

		if ( is_wp_error( $response ) ) {
			// No HTTP response at all. That is a loopback failure, not evidence
			// about the endpoint, so it stays inconclusive: keep using
			// admin-ajax, which always works, and let the recheck try again.
			update_option( self::STATUS_OPTION, false, false );
			update_option( self::CHECKED_OPTION, time(), false );
			return false;
		}

		$ok = 204 === (int) wp_remote_retrieve_response_code( $response );

		update_option( self::STATUS_OPTION, $ok, false );
		update_option( self::CHECKED_OPTION, time(), false );

		return $ok;
	}

	/**
	 * Re-test periodically. A host can start or stop allowing direct PHP in
	 * wp-content without warning, and a WAF rule can appear overnight, so a
	 * one-time check at activation would go stale in both directions.
	 */
	public static function schedule_recheck(): void {
		if ( ! wp_next_scheduled( 'vireo_verify_endpoint' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'vireo_verify_endpoint' );
		}
	}

	/**
	 * Called on plugin deletion (via uninstall.php). Removes the runtime data
	 * dir and any leftover legacy artifacts.
	 */
	public static function uninstall(): void {
		wp_clear_scheduled_hook( 'vireo_verify_endpoint' );
		self::cleanup_legacy_shim();

		$dir = vireo_data_dir();
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return;
		}

		// scandir() rather than glob(), because glob() skips dotfiles and the
		// hardening .htaccess is one. Leaving it behind meant the directory was
		// never empty and rmdir() warned on every deactivation. GLOB_BRACE would
		// also work but is not available on every platform PHP builds for.
		$entries = scandir( $dir );
		if ( is_array( $entries ) ) {
			foreach ( $entries as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				$path = $dir . '/' . $entry;
				if ( is_file( $path ) ) {
					wp_delete_file( $path );
				}
			}
		}

		// Still not empty means something we do not own is in there, so leave it
		// alone rather than forcing it. Silenced because a non-empty directory
		// is an expected outcome here, not a fault worth logging.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,Generic.PHP.NoSilencedErrors.Discouraged
		@rmdir( $dir );
	}

	/**
	 * Older versions of the plugin dropped a `vireo-analytics-collect.php` shim
	 * at the WordPress root for a hot-path tracker that bypassed wp-load.php.
	 * The shim is no longer used and existing copies are deleted on upgrade.
	 */
	public static function cleanup_legacy_shim(): void {
		$legacy = rtrim( ABSPATH, '/\\' ) . '/vireo-analytics-collect.php';
		if ( is_file( $legacy ) ) {
			wp_delete_file( $legacy );
		}
	}

	/**
	 * Delete the compiled salt / exclusions / goals PHP files written by older
	 * builds, from both the uploads data dir and the in-plugin `data/` dir.
	 * Best-effort, delete-only.
	 */
	private static function purge_legacy_files(): void {
		$dirs = [ vireo_data_dir(), rtrim( VIREO_DIR, '/\\' ) . '/data' ];
		foreach ( $dirs as $dir ) {
			if ( '' === $dir || ! is_dir( $dir ) ) {
				continue;
			}
			foreach ( self::LEGACY_FILES as $name ) {
				$file = rtrim( $dir, '/\\' ) . '/' . $name;
				if ( is_file( $file ) ) {
					wp_delete_file( $file );
				}
			}
		}
	}
}
