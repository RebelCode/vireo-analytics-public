<?php
/**
 * Daily-rotating salt for visitor-uniqueness hashing.
 *
 * Privacy posture: the tracker hashes `ip|ua` with a salt that rotates every
 * 24 hours and keeps the previous day's salt around for one more day so
 * requests that straddle midnight still dedupe correctly. Raw IPs never
 * survive the hot-path request.
 *
 * Storage:
 *   option `vireo_visitor_salt` => [ 'current' => string, 'previous' => string, 'date' => 'Y-m-d' ]
 *   autoload=false; the tracker reads it on demand through get_salts().
 */

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

final class Salt_Manager {

	public const OPTION   = 'vireo_visitor_salt';
	public const CRON_HOOK = 'vireo_rotate_visitor_salt';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( self::CRON_HOOK, [ $this, 'rotate' ] );
	}

	/**
	 * Current + previous salts. Rotates lazily if the stored bundle is stale
	 * or missing (first install, dropped option, etc.).
	 *
	 * @return array{current:string, previous:string, date:string}
	 */
	public function get_salts(): array {
		$today     = vireo_bucket_date();
		$yesterday = gmdate( 'Y-m-d', strtotime( $today . ' -1 day' ) );

		return [
			'current'  => $this->salt_for( $today ),
			// Never minted on demand. If yesterday has no salt the site was not
			// recording then, and inventing one now would not dedupe anything.
			'previous' => (string) get_option( self::day_option( $yesterday ), '' ),
			'date'     => $today,
		];
	}

	private static function day_option( string $date ): string {
		return self::OPTION . '_' . $date;
	}

	/**
	 * The salt for one date, minting it once across every concurrent request.
	 *
	 * Rotation used to be a read, a generate and a write. Around the day
	 * boundary several first-requests could each find the bundle stale, each
	 * generate a different salt and each write it, and each then hashed its own
	 * visitor with the salt it had just invented. Only the last write survived,
	 * so the others hashed under a salt nobody stored and those visitors were
	 * counted again as new.
	 *
	 * The claim is now a single INSERT against the unique index on
	 * option_name, so exactly one request creates the row and everyone else
	 * reads what that one wrote. add_option() cannot do this: core implements
	 * it as INSERT ... ON DUPLICATE KEY UPDATE option_value = VALUES(...),
	 * which overwrites rather than refusing.
	 */
	private function salt_for( string $date ): string {
		$key = self::day_option( $date );

		$existing = (string) get_option( $key, '' );
		if ( '' !== $existing ) {
			return $existing;
		}

		// Reached at most once a day. If this site is mid-upgrade and still has
		// the old single-bundle option, adopt its salt for the day it belongs
		// to rather than minting a second one and recounting everyone already
		// seen today.
		$this->migrate_legacy_bundle();

		$existing = (string) get_option( $key, '' );
		if ( '' !== $existing ) {
			return $existing;
		}

		global $wpdb;

		$candidate = bin2hex( random_bytes( 24 ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic claim; add_option() upserts and cannot express this
		$claimed = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$key,
				$candidate
			)
		);

		if ( 1 === (int) $claimed ) {
			$this->prune( $date );
			return $candidate;
		}

		// Someone else claimed it. Their row is committed, so read it rather
		// than trusting a cache entry written before the insert landed.
		wp_cache_delete( $key, 'options' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- must bypass the cache, see above
		$stored = (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $key )
		);

		// If the row still cannot be read the database is refusing writes, and
		// returning the candidate keeps this one request hashing consistently.
		return '' !== $stored ? $stored : $candidate;
	}

	/**
	 * Drop salts older than yesterday.
	 *
	 * Keeping them would undo the point of rotating: once a day's salt is gone
	 * its hashes cannot be recomputed from an IP, even by someone holding the
	 * database.
	 */
	private function prune( string $today ): void {
		global $wpdb;

		$keep = [ self::day_option( $today ), self::day_option( gmdate( 'Y-m-d', strtotime( $today . ' -1 day' ) ) ) ];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- both placeholders are bound below
		$stale = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT IN (%s, %s)",
				$wpdb->esc_like( self::OPTION . '_' ) . '%',
				$keep[0],
				$keep[1]
			)
		);

		foreach ( (array) $stale as $name ) {
			delete_option( (string) $name );
		}
	}

	/**
	 * Generate a new salt, keep yesterday's as `previous`, write to DB.
	 * Called by the daily cron at UTC midnight and lazily from get_salts().
	 *
	 * @return array{current:string, previous:string, date:string}
	 */
	public function rotate(): array {
		// Kept for the daily cron hook. Minting today's salt ahead of the first
		// beacon also prunes anything older than yesterday. random_bytes()
		// rather than wp_generate_password(): the first beacon after the day
		// flips reaches the SHORTINIT endpoint, where pluggable.php is never
		// loaded. This is an HMAC key, so 48 hex characters is at least as
		// strong as the password it replaces.
		return $this->get_salts();
	}

	/**
	 * Carry a pre-existing bundle over to the per-day key once.
	 *
	 * Without this, upgrading mid-day would mint a fresh salt for a day that
	 * already had one, and every visitor already seen that day would be counted
	 * a second time.
	 */
	private function migrate_legacy_bundle(): void {
		$bundle = get_option( self::OPTION );
		if ( ! is_array( $bundle ) || empty( $bundle['current'] ) || empty( $bundle['date'] ) ) {
			return;
		}

		global $wpdb;

		foreach ( [ 'current' => (string) $bundle['date'], 'previous' => gmdate( 'Y-m-d', strtotime( (string) $bundle['date'] . ' -1 day' ) ) ] as $slot => $date ) {
			if ( empty( $bundle[ $slot ] ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration, must not overwrite a claim
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
					self::day_option( $date ),
					(string) $bundle[ $slot ]
				)
			);
		}

		delete_option( self::OPTION );
	}

	/**
	 * Hash `ip|ua` with the supplied salt. Pure helper shared by the tracker
	 * endpoint and the aggregator.
	 */
	public static function hash_with_salt( string $ip, string $ua, string $salt ): string {
		if ( '' === $salt || '' === $ip ) {
			return '';
		}
		return substr( hash_hmac( 'sha256', $ip . '|' . $ua, $salt ), 0, 32 );
	}

	/**
	 * A stable, keyed hash of an IP address on its own: the key for the
	 * country-lookup cache (kept 30 days) and the fallback token for telling
	 * visitors apart when no visitor hash exists.
	 *
	 * Keyed with the site's own auth salt rather than a fixed string: an
	 * unkeyed md5 of an IPv4 address is reversible by trying all four billion
	 * of them, so a copy of the table was a list of visitor addresses. With
	 * the site's secret in the HMAC, the stored value says nothing about the
	 * address to anyone without that secret. Daily salt rotation would break
	 * the 30-day cache, which is why this uses the fixed site key, not the
	 * rotating visitor salt.
	 *
	 * 32 hex characters, to fit the existing char(32) column.
	 */
	public static function hash_ip( string $ip ): string {
		if ( '' === $ip ) {
			return '';
		}
		return substr( hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ), 0, 32 );
	}

	/**
	 * Drop silencing files into a directory so its contents can't be served
	 * directly by the webserver. Safe to call repeatedly — only writes when
	 * the guards are missing.
	 */
	public static function harden_directory( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		$webconfig = $dir . '/web.config';
		if ( ! file_exists( $webconfig ) ) {
			file_put_contents( $webconfig, '<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*"/></authorization></system.webServer></configuration>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}

	/**
	 * Scheduled by the installer. Idempotent — safe to call on activation
	 * even if the event already exists.
	 */
	public static function schedule_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			// Fire at the next midnight in the site's timezone, because that is
			// when vireo_bucket_date() rolls over and the salt is considered
			// stale. Scheduling at UTC midnight instead left a window every day
			// where the lazy rotation in get_salts() did the work.
			$tz            = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
			$tomorrow      = new \DateTimeImmutable( 'tomorrow', $tz );
			$next_midnight = $tomorrow->getTimestamp();
			wp_schedule_event( $next_midnight ?: ( time() + DAY_IN_SECONDS ), 'daily', self::CRON_HOOK );
		}
	}

	public static function unschedule_cron(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Remove the legacy salt mirror file if present. Called on deactivation.
	 */
	public static function remove_mirror(): void {
		$dir = vireo_data_dir();
		if ( '' === $dir ) {
			return;
		}
		$legacy = rtrim( $dir, '/\\' ) . '/va-salt.php';
		if ( is_file( $legacy ) ) {
			wp_delete_file( $legacy );
		}
	}
}
