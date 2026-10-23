<?php
/**
 * IP -> country code lookup, backed by a self-hosted MaxMind GeoLite2-Country
 * database. Results are cached (hashed IP -> country) in wp_la_ip_cache so
 * repeat lookups skip the file read.
 *
 * Setup: site owner downloads GeoLite2-Country.mmdb from MaxMind (free
 * license key required) and places it at:
 *   wp-content/uploads/vireo-analytics-geoip/GeoLite2-Country.mmdb
 *
 * If the file is missing, every lookup returns 'XX' (unknown). Phase 2's
 * settings page will surface a one-time admin notice pointing to setup docs.
 * No HTTP calls are made from this class.
 */

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

final class Geo_Lookup {

	/** Cache TTL in seconds (30 days). */
	private const CACHE_TTL = 2592000;

	private static ?\VireoAnalytics\MMDB_Reader\Reader $reader = null;
	private static bool $reader_tried = false;

	/**
	 * Absolute filesystem path to the expected MMDB file.
	 */
	public static function mmdb_path(): string {
		$base = function_exists( 'vireo_uploads_basedir' ) ? vireo_uploads_basedir() : '';
		return '' === $base ? '' : $base . '/vireo-analytics-geoip/GeoLite2-Country.mmdb';
	}

	public static function mmdb_exists(): bool {
		$path = self::mmdb_path();
		return is_file( $path ) && is_readable( $path );
	}

	/**
	 * Whether countries are being resolved at all, by either route.
	 *
	 * The database is only one of them. A host that sends a country header
	 * resolves every visit without it, so "no database" is not the same as
	 * "no countries", and saying so on those sites contradicts the Settings
	 * screen. Reads the current request, which carries the same header a
	 * front-end request would.
	 */
	public static function resolves_countries(): bool {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only header presence is read; the value is normalised in country_from_headers()
		return self::mmdb_exists() || '' !== self::country_from_headers( $_SERVER );
	}

	/**
	 * Look up a single IP. Returns a two-letter country code, or 'XX' if unknown.
	 */
	/**
	 * Country the host already worked out for us, if it did.
	 *
	 * Cloudflare, and several managed hosts and CDNs, resolve the visitor's
	 * country at the edge and hand it over in a request header. On those sites
	 * that's better than anything we can do locally: it's free, it's current,
	 * and it needs no database. Requiring every user to go and fetch a MaxMind
	 * licence key before they can see a single country was a poor default.
	 *
	 * Only valid during the request that produced it, so the collector stores it
	 * with the pageview rather than leaving it for the cron to figure out.
	 *
	 * @param array<string, mixed> $server $_SERVER, or an equivalent map.
	 */
	public static function country_from_headers( array $server ): string {
		$headers = (array) apply_filters(
			'vireo_geo_country_headers',
			[
				'HTTP_CF_IPCOUNTRY',      // Cloudflare
				'HTTP_X_GEOIP_COUNTRY',   // various CDNs / reverse proxies
				'HTTP_X_COUNTRY_CODE',
				'HTTP_GEOIP_COUNTRY_CODE',// nginx GeoIP module
				'HTTP_CLOUDFRONT_VIEWER_COUNTRY', // AWS CloudFront
			]
		);

		foreach ( $headers as $key ) {
			if ( empty( $server[ $key ] ) ) {
				continue;
			}

			$code = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) $server[ $key ] ) ?? '', 0, 2 ) );

			// Cloudflare sends XX for unknown and T1 for Tor. Neither is a country.
			if ( 2 !== strlen( $code ) || in_array( $code, [ 'XX', 'T1' ], true ) ) {
				continue;
			}

			return $code;
		}

		return '';
	}

	/** Option recording which database is installed and when. */
	private const SOURCE_OPTION = 'vireo_geo_source';

	/**
	 * How the installed database got there: 'dbip', 'maxmind', or '' if none.
	 *
	 * @return array{source:string, updated:int}
	 */
	public static function db_source(): array {
		$v = get_option( self::SOURCE_OPTION, [] );
		return [
			'source'  => is_array( $v ) ? (string) ( $v['source'] ?? '' ) : '',
			'updated' => is_array( $v ) ? (int) ( $v['updated'] ?? 0 ) : 0,
		];
	}

	/**
	 * Download the free DB-IP Country Lite database and install it.
	 *
	 * DB-IP's Lite data is CC BY 4.0, so unlike MaxMind's GeoLite2 there's no
	 * account, no licence key, and their terms permit fetching it directly. That
	 * turns the old four-step chore into one button. The file is the same MMDB
	 * format the reader already speaks, so it drops straight in.
	 *
	 * Attribution (a link back to db-ip.com) is required by the licence and is
	 * rendered on the settings page and the countries card.
	 *
	 * @return true|\WP_Error
	 */
	public static function download_dbip() {
		$dest = self::mmdb_path();
		if ( '' === $dest ) {
			return new \WP_Error( 'vireo_geo_no_uploads', __( 'The uploads directory is not writable, so the database cannot be saved.', 'vireo-analytics' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		// DB-IP publishes one file per month. Early in a month the new file can lag
		// by a day, so fall back to the previous month.
		$months = [ gmdate( 'Y-m' ), gmdate( 'Y-m', strtotime( 'first day of last month' ) ) ];

		$tmp      = '';
		$last_err = '';
		foreach ( $months as $ym ) {
			$url = "https://download.db-ip.com/free/dbip-country-lite-{$ym}.mmdb.gz";
			$dl  = download_url( $url, 120 );
			if ( ! is_wp_error( $dl ) ) {
				$tmp = $dl;
				break;
			}
			$last_err = $dl->get_error_message();
		}

		if ( '' === $tmp ) {
			return new \WP_Error( 'vireo_geo_download_failed', sprintf( /* translators: %s: error detail */ __( 'Could not download the database: %s', 'vireo-analytics' ), $last_err ) );
		}

		$gz = file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local temp file from download_url
		wp_delete_file( $tmp );
		if ( false === $gz || '' === $gz ) {
			return new \WP_Error( 'vireo_geo_empty', __( 'The downloaded database was empty.', 'vireo-analytics' ) );
		}

		$mmdb = function_exists( 'gzdecode' ) ? @gzdecode( $gz ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- checked below
		if ( false === $mmdb || strlen( $mmdb ) < 100000 ) {
			return new \WP_Error( 'vireo_geo_decompress', __( 'The downloaded database could not be unpacked.', 'vireo-analytics' ) );
		}

		$dir = dirname( $dest );
		if ( ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'vireo_geo_mkdir', __( 'Could not create the database directory.', 'vireo-analytics' ) );
		}

		// The database itself is a public dataset, so nothing here is secret —
		// but the directory should not be browsable on a host that leaves
		// autoindex on, and the data dir next door is hardened the same way.
		if ( class_exists( __NAMESPACE__ . '\\Salt_Manager' ) ) {
			Salt_Manager::harden_directory( $dir );
		}

		if ( false === file_put_contents( $dest, $mmdb ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents -- writing binary DB to uploads
			return new \WP_Error( 'vireo_geo_write', __( 'Could not write the database file.', 'vireo-analytics' ) );
		}

		// Reset the cached reader so the new file is picked up this request.
		self::$reader      = null;
		self::$reader_tried = false;

		// Every lookup made before this point resolved to nothing and was
		// cached as a miss. Left alone, a site that installs the database after
		// its first visitors keeps reporting them as unknown until those rows
		// expire, which looks exactly like a database that does not work.
		// Hits stay: they were right when they were written.
		self::flush_cached_misses();

		update_option( self::SOURCE_OPTION, [ 'source' => 'dbip', 'updated' => time() ], false );
		return true;
	}

	/**
	 * Drop cached lookups that resolved to nothing, so they are retried against
	 * whatever database is installed now.
	 */
	public static function flush_cached_misses(): void {
		global $wpdb;

		$table = Sites::table( 'ip_cache' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- maintenance delete
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- maintenance delete
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE country_code = %s', $table, '' ) );
	}

	public static function lookup( string $ip ): string {
		if ( '' === $ip ) {
			return 'XX';
		}

		$cached = self::get_cached( $ip );
		if ( false !== $cached ) {
			return '' === $cached ? 'XX' : $cached;
		}

		$country = self::resolve( $ip );
		// Cache both hits and misses so we don't re-read the file for repeat IPs.
		self::set_cache( $ip, $country );
		return '' === $country ? 'XX' : $country;
	}

	/**
	 * @param string[] $ips
	 * @return array<string,string> ip => country_code (may be 'XX' for unknown)
	 */
	public static function lookup_batch( array $ips ): array {
		if ( empty( $ips ) ) {
			return [];
		}
		$out = [];
		foreach ( array_unique( $ips ) as $ip ) {
			$out[ $ip ] = self::lookup( $ip );
		}
		return $out;
	}

	private static function resolve( string $ip ): string {
		$reader = self::reader();
		if ( null === $reader ) {
			return '';
		}

		try {
			$record = $reader->get( $ip );
		} catch ( \Throwable $e ) {
			// Corrupt file, bad IP family, etc. Don't fatal — unknown is fine.
			return '';
		}

		if ( ! is_array( $record ) ) {
			return '';
		}

		// GeoLite2-Country record shape: [ 'country' => [ 'iso_code' => 'US', ... ], 'registered_country' => [...] ]
		// Prefer 'country'; fall back to 'registered_country' for anonymous proxies.
		$code = $record['country']['iso_code']
			?? $record['registered_country']['iso_code']
			?? '';

		return is_string( $code ) ? strtoupper( $code ) : '';
	}

	private static function reader(): ?\VireoAnalytics\MMDB_Reader\Reader {
		if ( self::$reader_tried ) {
			return self::$reader;
		}
		self::$reader_tried = true;

		if ( ! self::mmdb_exists() ) {
			return null;
		}

		$reader_file = VIREO_DIR . 'lib/mmdb-reader/class-reader.php';
		if ( ! file_exists( $reader_file ) ) {
			return null;
		}
		require_once $reader_file;

		try {
			self::$reader = new \VireoAnalytics\MMDB_Reader\Reader( self::mmdb_path() );
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[vireo-analytics] failed to open GeoLite2 MMDB — ' . $e->getMessage() );
			}
			self::$reader = null;
		}
		return self::$reader;
	}

	/**
	 * @return string|false Country code (possibly empty for a cached miss), or false if not cached/expired.
	 */
	private static function get_cached( string $ip ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- this IS the cache layer
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT country_code, expires FROM %i WHERE ip_hash = %s',
				Sites::table( 'ip_cache' ),
				self::hash_ip( $ip )
			)
		);
		if ( ! $row || (int) $row->expires < time() ) {
			return false;
		}
		return (string) $row->country_code;
	}

	private static function set_cache( string $ip, string $country_code ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- this IS the cache layer
		$wpdb->replace(
			Sites::table( 'ip_cache' ),
			[
				'ip_hash'      => self::hash_ip( $ip ),
				'country_code' => $country_code,
				'expires'      => time() + self::CACHE_TTL,
			],
			[ '%s', '%s', '%d' ]
		);
	}

	/**
	 * Cache key for an address: a hash keyed with the site's secret, so the
	 * cached rows cannot be turned back into addresses (see Salt_Manager).
	 */
	private static function hash_ip( string $ip ): string {
		return Salt_Manager::hash_ip( $ip );
	}

	/**
	 * Purge expired entries from the cache table. Called by the daily cron.
	 */
	public static function purge_expired(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- cache table cleanup
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE expires < %d',
				Sites::table( 'ip_cache' ),
				time()
			)
		);
	}
}

add_action( 'vireo_purge_ip_cache', [ Geo_Lookup::class, 'purge_expired' ] );
