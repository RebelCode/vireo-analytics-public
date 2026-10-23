<?php
/**
 * Connected sites.
 *
 * A connected site is another site, usually not WordPress at all (a static
 * Astro or Hugo site, a landing page), whose visits this install counts and
 * reports on their own. It is not the headless front end in class-headless.php:
 * that one mirrors this site's posts and its pageviews are this site's own.
 * A connected site has its own numbers, its own pages keyed by path, and no
 * post matching.
 *
 * Each connected site gets its own copy of the stats tables, named
 * `{$wpdb->prefix}va_s{ID}_{table}`. The main site keeps the names it has
 * always had, so an existing install changes nothing. Every table name in the
 * plugin is resolved through table() below against the site in context, which
 * is set by the aggregator while it drains that site's buffer and by the
 * dashboard while it renders that site's reports. Code that never switches
 * context reads and writes the main site, as it always did.
 *
 * Loaded on the SHORTINIT endpoint as well as under full WordPress, so the
 * request-side methods only use get_option(), apply_filters() and plain string
 * work. The write methods (add, delete) run from the settings screen only.
 */

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

final class Sites {

	/** Option holding `['next' => int, 'sites' => [id => site]]`. Autoloaded; the beacon reads it. */
	public const OPTION = 'vireo_connected_sites';

	/** Per-user meta: the site id the dashboard last showed them. */
	public const USER_META = 'vireo_selected_site';

	/** How many connected sites an install may have. Free allows one; Pro raises it here. */
	public const LIMIT_FILTER = 'vireo_connected_sites_limit';

	/** The beacon parameter that carries a connected site's key. */
	public const BEACON_PARAM = 'site';

	/** The main site, whose tables carry no site segment. */
	public const MAIN = 0;

	/**
	 * Tables each connected site gets its own copy of.
	 *
	 * Everything else (the IP-to-country cache, Search Console rows, anything
	 * Pro creates) is shared and always resolves to the main site's table,
	 * whatever the context. Installer::create_tables() creates exactly these
	 * for a connected site and delete() drops exactly these, so the list is
	 * the one place that says what a connected site owns.
	 */
	public const PER_SITE = [
		'site_stats',
		'bot_stats',
		'annotations',
		'vitals_stats',
		'visitor_seen',
		'visitor_load',
		'paths',
		'post_stats',
		'referrer_urls',
		'referrer_stats',
		'geo_stats',
		'device_stats',
		'404_stats',
		'event_stats',
		'hour_stats',
		'post_referrer_stats',
		'page_time',
		'bounce_stats',
	];

	/** Longest name a site can have. */
	public const MAX_NAME = 80;

	/** The site in context. */
	private static int $current = self::MAIN;

	/** @var array<int,int> Contexts to restore, innermost last. */
	private static array $stack = [];

	/** @var array<int,array<string,mixed>>|null Sites by id, read once per request. */
	private static ?array $cache = null;

	// ---------------------------------------------------------------------
	//  Table names
	// ---------------------------------------------------------------------

	/**
	 * The full table name for a stats table, for the site in context or the
	 * site given.
	 *
	 * @param string   $name    Short name, without the `va_` prefix: `site_stats`.
	 * @param int|null $site_id A site id, or null for the one in context.
	 */
	public static function table( string $name, ?int $site_id = null ): string {
		global $wpdb;

		$site_id = null === $site_id ? self::$current : $site_id;

		if ( self::MAIN === $site_id || ! in_array( $name, self::PER_SITE, true ) ) {
			return $wpdb->prefix . 'va_' . $name;
		}

		// The id is the only variable part and it is an int, so the name can
		// never carry anything a caller did not put in PER_SITE.
		return $wpdb->prefix . 'va_s' . max( 1, (int) $site_id ) . '_' . $name;
	}

	// ---------------------------------------------------------------------
	//  Context
	// ---------------------------------------------------------------------

	public static function current(): int {
		return self::$current;
	}

	/** Whether the site in context is a connected site rather than this one. */
	public static function is_connected(): bool {
		return self::MAIN !== self::$current;
	}

	/**
	 * Make a site the one every table name resolves to, until restore().
	 *
	 * Nested like switch_to_blog(): each switch_to() pairs with one restore().
	 * The id is not validated here, so the aggregator can drain a site that
	 * was deleted a moment ago without failing; table() still names tables
	 * that may no longer exist, and the writers probe before they write.
	 */
	public static function switch_to( int $site_id ): void {
		self::$stack[] = self::$current;
		self::$current = max( self::MAIN, $site_id );
	}

	public static function restore(): void {
		if ( [] === self::$stack ) {
			self::$current = self::MAIN;
			return;
		}
		self::$current = (int) array_pop( self::$stack );
	}

	// ---------------------------------------------------------------------
	//  The list
	// ---------------------------------------------------------------------

	/**
	 * Every connected site, keyed by id, in the order they were added.
	 *
	 * @return array<int,array{id:int,name:string,origin:string,key:string,created:int}>
	 */
	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$stored = get_option( self::OPTION, [] );
		$sites  = is_array( $stored ) && isset( $stored['sites'] ) && is_array( $stored['sites'] ) ? $stored['sites'] : [];

		$out = [];
		foreach ( $sites as $site ) {
			if ( ! is_array( $site ) ) {
				continue;
			}
			$id     = (int) ( $site['id'] ?? 0 );
			$origin = (string) ( $site['origin'] ?? '' );
			$key    = (string) ( $site['key'] ?? '' );
			// A row that cannot identify itself cannot accept a beacon or own a
			// table, so it is left out rather than half-shown.
			if ( $id <= 0 || '' === $origin || 1 !== preg_match( '/^[a-f0-9]{32}$/', $key ) ) {
				continue;
			}
			$out[ $id ] = [
				'id'      => $id,
				'name'    => (string) ( $site['name'] ?? '' ),
				'origin'  => $origin,
				'key'     => $key,
				'created' => (int) ( $site['created'] ?? 0 ),
			];
		}

		self::$cache = $out;
		return $out;
	}

	/** @return array{id:int,name:string,origin:string,key:string,created:int}|null */
	public static function get( int $site_id ): ?array {
		return self::all()[ $site_id ] ?? null;
	}

	/**
	 * The site a beacon's key names, or null.
	 *
	 * The key alone is not enough to accept a beacon: the caller also checks
	 * the request Origin against the site's origin. Both have to agree.
	 *
	 * @return array{id:int,name:string,origin:string,key:string,created:int}|null
	 */
	public static function find_by_key( string $key ): ?array {
		$key = strtolower( trim( $key ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $key ) ) {
			return null;
		}
		foreach ( self::all() as $site ) {
			if ( hash_equals( $site['key'], $key ) ) {
				return $site;
			}
		}
		return null;
	}

	/**
	 * Whether a beacon carrying a site's key may be counted for that site.
	 *
	 * The request Origin has to be the site's registered address, exactly:
	 * same scheme, host and port, the way the headless front end is checked.
	 * No Origin at all, an opaque one, this WordPress site's own host or any
	 * other address is refused, so a key copied into another page records
	 * nothing.
	 *
	 * @param array<string,mixed>  $server $_SERVER or an equivalent map.
	 * @param array{origin:string} $site   From find_by_key().
	 */
	public static function request_matches( array $server, array $site ): bool {
		$verdict = Headless::classify_request( $server, [ (string) $site['origin'] ], [] );
		return 'headless' === $verdict['kind'];
	}

	/** Forget the per-request copy of the list after a write. */
	public static function flush_cache(): void {
		self::$cache = null;
	}

	/**
	 * The most connected sites this install may have.
	 *
	 * Free allows one. Pro raises it through the filter; there is no license
	 * check here, this plugin only reads the number.
	 */
	public static function limit(): int {
		/**
		 * How many connected sites may be added.
		 *
		 * @param int $limit Default 1.
		 */
		$limit = (int) apply_filters( self::LIMIT_FILTER, 1 );
		return max( 0, min( 100, $limit ) );
	}

	public static function can_add(): bool {
		return count( self::all() ) < self::limit();
	}

	// ---------------------------------------------------------------------
	//  Adding and removing
	// ---------------------------------------------------------------------

	/**
	 * Add a site and create its tables.
	 *
	 * @return int|\WP_Error The new site's id.
	 */
	public static function add( string $name, string $origin ) {
		$name = trim( sanitize_text_field( $name ) );
		if ( '' === $name ) {
			return new \WP_Error( 'vireo_site_name', __( 'Give the site a name.', 'vireo-analytics' ) );
		}
		$name = mb_substr( $name, 0, self::MAX_NAME );

		$origin = Headless::normalize_origin( $origin );
		if ( '' === $origin ) {
			return new \WP_Error( 'vireo_site_origin', __( 'Enter the full address of the site, starting with http:// or https://, for example https://www.example.com.', 'vireo-analytics' ) );
		}

		if ( ! self::can_add() ) {
			return new \WP_Error( 'vireo_site_limit', __( 'This install has reached its number of connected sites.', 'vireo-analytics' ) );
		}

		// One address, one set of numbers. The same origin as another connected
		// site, as the headless front end, or as this WordPress site itself
		// would have two places claiming the same visits.
		foreach ( self::all() as $site ) {
			if ( $site['origin'] === $origin ) {
				return new \WP_Error( 'vireo_site_duplicate', __( 'That address is already connected.', 'vireo-analytics' ) );
			}
		}
		if ( in_array( $origin, Headless::allowed_origins(), true ) ) {
			return new \WP_Error( 'vireo_site_headless', __( 'That address is set as the headless front end. A site is either the front end for this WordPress site or a connected site with its own numbers, not both.', 'vireo-analytics' ) );
		}
		$origin_host = (string) substr( $origin, strpos( $origin, '://' ) + 3 );
		if ( in_array( (string) preg_replace( '/:(80|443)$/', '', $origin_host ), Headless::own_hosts( [] ), true ) ) {
			return new \WP_Error( 'vireo_site_self', __( 'That is this site\'s own address. Its pages are already counted.', 'vireo-analytics' ) );
		}

		$stored = get_option( self::OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];
		$sites  = isset( $stored['sites'] ) && is_array( $stored['sites'] ) ? $stored['sites'] : [];

		// Ids are never reused: a deleted site's number stays retired so a
		// buffer or a selection that still names it cannot land on a new site.
		$id = max( 1, (int) ( $stored['next'] ?? 1 ) );
		foreach ( $sites as $site ) {
			$id = max( $id, (int) ( $site['id'] ?? 0 ) + 1 );
		}

		$sites[ $id ] = [
			'id'      => $id,
			'name'    => $name,
			'origin'  => $origin,
			'key'     => bin2hex( random_bytes( 16 ) ),
			'created' => time(),
		];

		update_option(
			self::OPTION,
			[
				'next'  => $id + 1,
				'sites' => $sites,
			],
			true
		);
		self::flush_cache();

		Installer::create_tables( $id );

		return $id;
	}

	/**
	 * Remove a site, drop its tables and delete its buffer.
	 *
	 * Only this site's tables: the names come from table() with the id given,
	 * and that only ever names `va_s{ID}_` tables from PER_SITE.
	 */
	public static function delete( int $site_id ): bool {
		global $wpdb;

		if ( $site_id <= 0 || null === self::get( $site_id ) ) {
			return false;
		}

		$stored = get_option( self::OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];
		$sites  = isset( $stored['sites'] ) && is_array( $stored['sites'] ) ? $stored['sites'] : [];
		unset( $sites[ $site_id ] );
		update_option(
			self::OPTION,
			[
				'next'  => max( (int) ( $stored['next'] ?? 1 ), $site_id + 1 ),
				'sites' => $sites,
			],
			true
		);
		self::flush_cache();

		foreach ( self::PER_SITE as $name ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- explicit teardown of this site's tables
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::table( $name, $site_id ) ) );
		}

		self::remove_data_dir( $site_id );

		// Anyone who had this site selected goes back to the main site.
		delete_metadata( 'user', 0, self::USER_META, (string) $site_id, true );

		return true;
	}

	// ---------------------------------------------------------------------
	//  Dashboard selection
	// ---------------------------------------------------------------------

	/**
	 * The site a user's dashboard shows: what they last picked, if it still
	 * exists, otherwise the main site.
	 */
	public static function selected_for( int $user_id ): int {
		if ( $user_id <= 0 ) {
			return self::MAIN;
		}
		$site_id = (int) get_user_meta( $user_id, self::USER_META, true );
		return null === self::get( $site_id ) ? self::MAIN : $site_id;
	}

	/** Remember which site a user's dashboard shows. Returns false for an unknown site. */
	public static function select_for( int $user_id, int $site_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		if ( self::MAIN === $site_id ) {
			delete_user_meta( $user_id, self::USER_META );
			return true;
		}
		if ( null === self::get( $site_id ) ) {
			return false;
		}
		update_user_meta( $user_id, self::USER_META, $site_id );
		return true;
	}

	// ---------------------------------------------------------------------
	//  Buffer files
	// ---------------------------------------------------------------------

	/**
	 * Where a site's buffer files live: the main data directory for the main
	 * site, a `site-{ID}` directory inside it for a connected site.
	 *
	 * A separate directory per site is what keeps one site's visits out of
	 * another's tables: the aggregator drains each directory in that site's
	 * context, and a record never moves between directories.
	 */
	public static function data_dir( ?int $site_id = null ): string {
		$site_id = null === $site_id ? self::$current : $site_id;
		$base    = function_exists( 'vireo_data_dir' ) ? vireo_data_dir() : '';
		if ( '' === $base || self::MAIN === $site_id ) {
			return $base;
		}
		return $base . '/site-' . max( 1, (int) $site_id );
	}

	/**
	 * Remove `site-{ID}` directories whose site no longer exists.
	 *
	 * delete() removes the directory itself, but a beacon in flight can
	 * recreate it a moment later, and those lines hold raw IPs and UAs that
	 * nothing drains. Ids are never reused, so a directory for an id that is
	 * not in the list is an orphan for good. Only directories older than a
	 * few minutes go, so a site added by another request just now, before
	 * this request's copy of the list, keeps its first buffer. Run from the
	 * aggregator, once per tick.
	 *
	 * @return array<int,int> The ids whose directories were removed.
	 */
	public static function remove_orphan_dirs(): array {
		$base = self::data_dir( self::MAIN );
		if ( '' === $base || ! is_dir( $base ) ) {
			return [];
		}

		// Read the list afresh: the per-request cache may predate an add.
		self::flush_cache();
		$known   = self::all();
		$settled = time() - ( 10 * MINUTE_IN_SECONDS );
		$removed = [];

		foreach ( (array) scandir( $base ) as $entry ) {
			if ( 1 !== preg_match( '/^site-(\d+)$/', (string) $entry, $m ) ) {
				continue;
			}
			$site_id = (int) $m[1];
			if ( isset( $known[ $site_id ] ) ) {
				continue;
			}
			// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- a vanished directory is nothing to clean
			if ( (int) @filemtime( $base . '/' . $entry ) > $settled ) {
				continue;
			}
			self::remove_data_dir( $site_id );
			if ( ! is_dir( $base . '/' . $entry ) ) {
				$removed[] = $site_id;
			}
		}

		return $removed;
	}

	private static function remove_data_dir( int $site_id ): void {
		$dir = self::data_dir( $site_id );
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$path = $dir . '/' . $name;
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,Generic.PHP.NoSilencedErrors.Discouraged -- best effort; a leftover empty dir is harmless
		@rmdir( $dir );
	}

	// ---------------------------------------------------------------------
	//  The snippet
	// ---------------------------------------------------------------------

	/**
	 * The tags to paste into the connected site: the tracker, and the Core Web
	 * Vitals collector on its own line so it can be left out.
	 *
	 * Same shape as the headless tag, plus `data-site` carrying the key. The
	 * key is not a secret on its own: the endpoint also requires the request
	 * Origin to be the site's registered address, so a copied key sent from
	 * anywhere else is refused.
	 *
	 * @param array{key:string} $site
	 */
	public static function snippet( array $site, string $endpoint_url, string $tracker_url, string $vitals_url = '' ): string {
		$key  = esc_attr( (string) ( $site['key'] ?? '' ) );
		$tags = sprintf(
			'<script defer src="%s" data-endpoint="%s" data-site="%s"></script>', // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- text for the owner to paste into another site, not output on this one.
			esc_url_raw( $tracker_url ),
			esc_url_raw( $endpoint_url ),
			$key
		);
		if ( '' !== $vitals_url ) {
			$tags .= "\n" . sprintf(
				'<script defer src="%s" data-endpoint="%s" data-site="%s"></script>', // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- same, pasted elsewhere.
				esc_url_raw( $vitals_url ),
				esc_url_raw( $endpoint_url ),
				$key
			);
		}
		return $tags;
	}
}
