<?php
/**
 * Vireo Analytics uninstall handler.
 *
 * Drops all wp_va_* tables, deletes va_* options, removes the runtime data
 * directory under uploads, and sweeps any legacy artifacts left by older
 * versions of the plugin (the ABSPATH tracker shim and the in-plugin data
 * dir).
 *
 * Runs only when the user explicitly deletes the plugin via the WP UI.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove a directory and everything in it.
 *
 * glob() does not match dotfiles, and every directory this plugin creates is
 * hardened with a .htaccess. Sweeping with glob() therefore deleted the
 * contents, left the .htaccess, and rmdir() refused the now "not empty"
 * directory, so an uninstall left the folders and their web-access guards
 * behind on every site.
 */
function vireo_uninstall_rmdir( string $dir ): void {
	if ( '' === $dir || ! is_dir( $dir ) ) {
		return;
	}

	$entries = scandir( $dir );
	if ( ! is_array( $entries ) ) {
		return;
	}

	foreach ( $entries as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}

		$path = $dir . '/' . $entry;

		if ( is_dir( $path ) ) {
			vireo_uninstall_rmdir( $path );
		} else {
			wp_delete_file( $path );
		}
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	@rmdir( $dir );
}

/**
 * Wrapped so the cleanup locals stay function-scoped rather than polluting the
 * global namespace.
 */
function vireo_uninstall(): void {
	global $wpdb;

	$tables = [
		'va_site_stats',
		'va_post_stats',
		'va_paths',
		'va_referrer_stats',
		'va_referrer_urls',
		'va_geo_stats',
		'va_device_stats',
		'va_ip_cache',
		'va_404_stats',
		'va_event_stats',
		'va_hour_stats',
		'va_post_referrer_stats',
		'va_visitor_seen',
		'va_visitor_load',
		// Pro — Goals. Other Pro tables are dropped by their owners' unregister_tables().
		'va_goals',
		'va_goal_completions',
		// Pro — WooCommerce.
		'va_woo_orders',
		'va_woo_products',
		'va_woo_events',
		// Added after the original list and missed by it: deleting the plugin
		// left these behind on every install that had them.
		'va_annotations',
		'va_bot_stats',
		'va_gsc_stats',
		'va_gsc_pages',
		'va_vitals_stats',
		'va_page_time',
		'va_bounce_stats',
	];

	foreach ( $tables as $table ) {
		$full = $wpdb->prefix . $table;
		// Table names are code-controlled; pass through %i for safety.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $full ) );
	}

	// Connected sites: each had its own copy of the stats tables, named
	// va_s{ID}_{table}. Found by name rather than from the option, so a site
	// whose row was lost still has its tables removed.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall sweep
	$site_tables = (array) $wpdb->get_col(
		$wpdb->prepare(
			'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s',
			$wpdb->esc_like( $wpdb->prefix . 'va_s' ) . '%'
		)
	);
	foreach ( $site_tables as $full ) {
		if ( 1 !== preg_match( '/^' . preg_quote( $wpdb->prefix, '/' ) . 'va_s\d+_[a-z0-9_]+$/', (string) $full ) ) {
			continue;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', (string) $full ) );
	}
	delete_option( 'vireo_connected_sites' );
	delete_metadata( 'user', 0, 'vireo_selected_site', '', true );

	delete_option( 'vireo_settings' );
	delete_option( 'vireo_db_version' );
	delete_option( 'vireo_bounce_since' );
	delete_option( 'vireo_tracking_since' );
	// Holds a WordPress.com API key on installs that ran a Jetpack import.
	delete_option( 'vireo_jetpack_import_params' );
	delete_transient( 'vireo_jetpack_import_params' );
	delete_option( 'vireo_import_done_jetpack' );
	delete_option( 'vireo_import_done_koko' );
	delete_option( 'vireo_css_hash' );
	delete_option( 'vireo_last_aggregation_at' );
	delete_option( 'vireo_visitor_salt' );
	delete_option( 'vireo_pro_schema_version' );

	// These two hold a Google service-account PRIVATE KEY. Leaving them meant
	// deleting the plugin left a working credential sitting in wp_options,
	// where it outlives the plugin, travels in every database export, and is
	// readable by anything that runs later on the site.
	delete_option( 'vireo_ga4_import_params' );
	delete_option( 'vireo_gsc_settings' );
	delete_option( 'vireo_gsc_state' );
	delete_option( 'vireo_gsc_backfill' );
	delete_option( 'vireo_psi_settings' );
	delete_option( 'vireo_ai_scan' );
	delete_transient( 'vireo_ai_scan_token' );

	// Plausible uploads staged for import and never finished, one per run.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall sweep, no caching layer to use
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( 'vireo_plausible_import_' ) . '%'
		)
	);

	delete_option( 'vireo_aggregator_stall' );
	delete_option( 'vireo_endpoint_checked_at' );
	delete_option( 'vireo_endpoint_usable' );
	delete_option( 'vireo_geo_source' );
	delete_option( 'vireo_weekly_email_history' );
	delete_option( 'vireo_dash_cache_gen' );
	delete_option( 'vireo_retention_last_run' );
	delete_option( 'vireo_uploads_basedir' );
	delete_option( 'vireo_popular_gen' );
	delete_option( 'widget_vireo_popular_posts' );

	// Popular posts lists and view counts, one transient per query.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall sweep, no caching layer to use
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_vireo_pop_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_vireo_pop_' ) . '%',
			$wpdb->esc_like( '_transient_vireo_views_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_vireo_views_' ) . '%'
		)
	);

	delete_metadata( 'user', 0, 'vireo_review_prompt', '', true );
	delete_metadata( 'user', 0, 'va_year_review_dismissed', '', true );

	// Cached Google access tokens are keyed by a hash of the key and scope, so
	// they cannot be named individually. They expire within the hour, but an
	// uninstall should not leave a usable bearer token behind for that hour.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall sweep, no caching layer to use
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_vireo_gtoken_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_vireo_gtoken_' ) . '%'
		)
	);

	// PageSpeed results and run locks, one per page and device.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall sweep, no caching layer to use
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_vireo_psi_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_vireo_psi_' ) . '%'
		)
	);

	// Per-run import state, keyed by importer and run.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall sweep, no caching layer to use
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( 'vireo_import_done_' ) . '%',
			$wpdb->esc_like( 'vireo_import_cutoff_' ) . '%',
			$wpdb->esc_like( '_transient_vireo_import_own_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_vireo_import_own_' ) . '%'
		)
	);

	wp_clear_scheduled_hook( 'vireo_flush_buffer' );
	wp_clear_scheduled_hook( 'vireo_purge_ip_cache' );
	wp_clear_scheduled_hook( 'vireo_rotate_visitor_salt' );
	wp_clear_scheduled_hook( 'vireo_weekly_email' );
	wp_clear_scheduled_hook( 'vireo_purge_hour_stats' );
	wp_clear_scheduled_hook( 'vireo_purge_old_data' );
	wp_clear_scheduled_hook( 'vireo_woo_purge_events' );
	wp_clear_scheduled_hook( 'vireo_gsc_sync' );
	wp_clear_scheduled_hook( 'vireo_gsc_backfill' );
	wp_clear_scheduled_hook( 'vireo_purge_page_vitals' );
	wp_clear_scheduled_hook( 'vireo_verify_endpoint' );
	wp_clear_scheduled_hook( 'vireo_purge_attribution' );

	// Legacy: older plugin versions dropped a tracker shim at the WordPress root.
	$legacy_shim = rtrim( ABSPATH, '/\\' ) . '/vireo-analytics-collect.php';
	if ( is_file( $legacy_shim ) ) {
		wp_delete_file( $legacy_shim );
	}

	// Legacy: older plugin versions wrote runtime data inside the plugin folder.
	vireo_uninstall_rmdir( __DIR__ . '/data' );

	// Runtime data directory (aggregator buffer files, hashed dashboard CSS).
	$uploads = wp_upload_dir( null, false );
	$data_dir = ( is_array( $uploads ) && empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) )
		? rtrim( (string) $uploads['basedir'], '/\\' ) . '/vireo-analytics'
		: '';
	vireo_uninstall_rmdir( $data_dir );

	// Geo databases. These are the largest thing the plugin ever writes: the
	// city database alone is well over a hundred megabytes, and leaving it
	// behind means an uninstall frees almost nothing.
	$geo_dir = ( is_array( $uploads ) && empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) )
		? rtrim( (string) $uploads['basedir'], '/\\' ) . '/vireo-analytics-geoip'
		: '';
	vireo_uninstall_rmdir( $geo_dir );

	// Pageview buffer directory (jsonl files queued for the aggregator).
	$buffer_base = ( is_array( $uploads ) && empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) )
		? rtrim( (string) $uploads['basedir'], '/\\' )
		: '';
	$buffer_dir = '' === $buffer_base ? '' : $buffer_base . '/vireo-analytics-buffer';
	if ( ! is_dir( $buffer_dir ) ) {
		// Older builds put the buffer at uploads/vireo-analytics — only sweep that
		// path if we already removed the data dir above (best-effort).
		$buffer_dir = '';
	}
	vireo_uninstall_rmdir( $buffer_dir );
}

/**
 * Uninstall runs once for the whole install, so on a network every other
 * site's tables, options and uploaded databases would survive deletion. Each
 * site has its own of all three.
 */
function vireo_uninstall_everywhere(): void {
	if ( ! is_multisite() ) {
		vireo_uninstall();
		return;
	}

	$sites = get_sites(
		[
			'number' => 10000,
			'fields' => 'ids',
		]
	);

	foreach ( (array) $sites as $site_id ) {
		switch_to_blog( (int) $site_id );
		vireo_uninstall();
		restore_current_blog();
	}

	delete_site_option( 'vireo_network_rollup' );
}

vireo_uninstall_everywhere();
