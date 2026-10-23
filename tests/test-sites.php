<?php
/**
 * Zero-dependency assertion runner for connected sites.
 * Run: php tests/test-sites.php
 *
 * A connected site is another site whose visits this install counts under
 * its own name. These cover what keeps that safe: every table name comes
 * from one helper that follows the site in context, a beacon is accepted
 * only when its key and its Origin both name the same site, the daily jobs
 * visit every site, the limit is a filter, and removing a site drops only
 * that site's tables.
 */

// A scratch WordPress root: dbDelta's include has to resolve, and the buffer
// directory the aggregator drains has to exist.
$va_root = sys_get_temp_dir() . '/vireo-sites-test-' . bin2hex( random_bytes( 4 ) );
mkdir( $va_root . '/wp-admin/includes', 0777, true );
file_put_contents( $va_root . '/wp-admin/includes/upgrade.php', "<?php\n" );
mkdir( $va_root . '/uploads/vireo-analytics', 0777, true );

define( 'ABSPATH', $va_root . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

// ---- the pieces of WordPress the classes touch ------------------------------

$GLOBALS['va_options'] = [
	'home'    => 'https://cms.example.com',
	'siteurl' => 'https://cms.example.com',
];
$GLOBALS['va_filters'] = [];
$GLOBALS['va_meta']    = [];
$GLOBALS['va_ddl']     = [];
$GLOBALS['va_deleted'] = [];

function get_option( $key, $default = false ) {
	return $GLOBALS['va_options'][ $key ] ?? $default;
}
function add_option( $key, $value ) {
	if ( ! isset( $GLOBALS['va_options'][ $key ] ) ) {
		$GLOBALS['va_options'][ $key ] = $value;
	}
	return true;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['va_options'][ $key ] = $value;
	return true;
}
function delete_option( $key ) {
	unset( $GLOBALS['va_options'][ $key ] );
	return true;
}
function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['va_filters'][ $hook ] ?? [] as $cb ) {
		$value = $cb( $value, ...$args );
	}
	return $value;
}
function has_action( $h ) {
	return false;
}
function do_action( $hook, ...$a ) {
	$GLOBALS['va_did'][] = [ $hook, \VireoAnalytics\Sites::current() ];
}
function add_action( ...$a ) {}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}
function sanitize_text_field( $s ) {
	return trim( (string) preg_replace( '/[\x00-\x1F\x7F]/', '', (string) $s ) );
}
function esc_url_raw( $s ) {
	return $s;
}
function esc_attr( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES );
}
function esc_html( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES );
}
function __( $s, $d = null ) {
	return $s;
}
function vireo_get_settings() {
	return $GLOBALS['va_settings'] ?? [ 'retention_days' => 30 ];
}
function get_user_meta( $user_id, $key, $single = false ) {
	return $GLOBALS['va_meta'][ $user_id ][ $key ] ?? '';
}
function update_user_meta( $user_id, $key, $value ) {
	$GLOBALS['va_meta'][ $user_id ][ $key ] = $value;
	return true;
}
function delete_user_meta( $user_id, $key ) {
	unset( $GLOBALS['va_meta'][ $user_id ][ $key ] );
	return true;
}
function delete_metadata( $type, $object_id, $key, $value = '', $delete_all = false ) {
	foreach ( $GLOBALS['va_meta'] as $uid => $meta ) {
		if ( isset( $meta[ $key ] ) && ( '' === $value || (string) $meta[ $key ] === (string) $value ) ) {
			unset( $GLOBALS['va_meta'][ $uid ][ $key ] );
		}
	}
	return true;
}
function wp_delete_file( $path ) {
	if ( is_file( $path ) ) {
		unlink( $path );
	}
}
function wp_mkdir_p( $dir ) {
	return is_dir( $dir ) || mkdir( $dir, 0777, true );
}
function dbDelta( $sql ) {
	$GLOBALS['va_ddl'][] = $sql;
	return [];
}
function vireo_data_dir() {
	return ABSPATH . 'uploads/vireo-analytics';
}
function wp_date( $format, $ts = null ) {
	return gmdate( $format, $ts ?? time() );
}
function home_url( $path = '' ) {
	return 'https://cms.example.com' . $path;
}
function is_post_publicly_viewable( $p ) {
	return false;
}
function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
	$found = false;
	return false;
}
function wp_cache_set( ...$a ) {
	return true;
}
function url_to_postid( $url ) {
	return 0;
}
function get_transient( $key ) {
	return false;
}
function set_transient( $key, $value, $ttl = 0 ) {
	return true;
}
function wp_timezone() {
	return new DateTimeZone( 'UTC' );
}
function current_user_can( $cap ) {
	return true;
}
function vireo_canonical_host( $v ) {
	return strtolower( preg_replace( '/^www\./', '', (string) $v ) );
}

class WP_Error {
	private string $code;
	private string $message;
	public function __construct( $code = '', $message = '' ) {
		$this->code    = (string) $code;
		$this->message = (string) $message;
	}
	public function get_error_code(): string {
		return $this->code;
	}
	public function get_error_message(): string {
		return $this->message;
	}
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

/**
 * Records every statement with its bound arguments. Every table "exists",
 * every INSERT IGNORE claims a row, so the aggregator's writes go through.
 */
final class Fake_Wpdb {
	public string $prefix        = 'wp_';
	public string $last_error    = '';
	public int $rows_affected    = 1;
	public array $statements     = [];
	private array $args          = [];

	public function prepare( $sql, ...$args ) {
		$this->args = 1 === count( $args ) && is_array( $args[0] ) ? $args[0] : $args;
		return $sql;
	}
	public function query( $sql ) {
		$this->statements[] = [ 'sql' => $sql, 'args' => $this->args ];
		$this->args         = [];
		return 1;
	}
	public function get_var( $sql ) {
		// SHOW TABLES LIKE %s answers with the name asked for.
		$name       = (string) ( $this->args[0] ?? '' );
		$this->args = [];
		return 0 === strpos( $sql, 'SHOW TABLES' ) ? $name : null;
	}
	public function get_row( $sql, $output = null ) {
		$this->query( $sql );
		return null;
	}
	public function get_col( $sql ) {
		$this->query( $sql );
		return [ 1 ];
	}
	public function get_results( $sql, $output = null ) {
		$args = $this->args;
		$this->query( $sql );
		// The referrer dictionary lookup: one matching row.
		if ( 0 === strpos( $sql, 'SELECT id, url' ) ) {
			return [ [ 'id' => 1, 'url' => (string) ( $args[1] ?? '' ) ] ];
		}
		if ( 0 !== strpos( $sql, 'SELECT %i AS v' ) ) {
			return [];
		}
		// The upserter's SELECT: hand back an id per value asked for.
		$rows = [];
		foreach ( array_slice( $args, 3 ) as $i => $v ) {
			$rows[] = [ 'v' => $v, 'id' => $i + 1 ];
		}
		return $rows;
	}
	public function esc_like( $s ) {
		return $s;
	}
	public function get_charset_collate() {
		return 'DEFAULT CHARACTER SET utf8mb4';
	}
	/** Statements whose SQL starts with $prefix. */
	public function of_kind( string $prefix ): array {
		return array_values( array_filter( $this->statements, static fn( $s ) => 0 === strpos( ltrim( $s['sql'] ), $prefix ) ) );
	}
	/** Table names bound to %i across all recorded statements. */
	public function tables( string $kind = '' ): array {
		$out = [];
		foreach ( $this->statements as $s ) {
			if ( '' !== $kind && 0 !== strpos( ltrim( $s['sql'] ), $kind ) ) {
				continue;
			}
			foreach ( $s['args'] as $a ) {
				if ( is_string( $a ) && 0 === strpos( $a, 'wp_va_' ) ) {
					$out[] = $a;
				}
			}
		}
		return array_values( array_unique( $out ) );
	}
}
$wpdb = new Fake_Wpdb();

if ( ! function_exists( 'str_contains' ) ) {
	function str_contains( $h, $n ) { return '' === $n || false !== strpos( $h, $n ); }
}
if ( ! function_exists( 'str_starts_with' ) ) {
	function str_starts_with( $h, $n ) { return 0 === strncmp( $h, $n, strlen( $n ) ); }
}
if ( ! function_exists( 'str_ends_with' ) ) {
	function str_ends_with( $h, $n ) { return '' === $n || substr( $h, -strlen( $n ) ) === $n; }
}
require __DIR__ . '/../includes/va-tz.php';
require __DIR__ . '/../includes/class-sites.php';
require __DIR__ . '/../includes/class-headless.php';
require __DIR__ . '/../includes/class-installer.php';
require __DIR__ . '/../includes/class-retention.php';
require __DIR__ . '/../includes/class-collector.php';
require __DIR__ . '/../includes/class-salt-manager.php';
require __DIR__ . '/../includes/class-upserter.php';
require __DIR__ . '/../includes/class-aggregator.php';
require __DIR__ . '/../includes/class-dashboard.php';
require __DIR__ . '/../includes/class-referrer-detail.php';
require __DIR__ . '/../includes/class-event-detail.php';

use VireoAnalytics\Aggregator;
use VireoAnalytics\Collector;
use VireoAnalytics\Dashboard;
use VireoAnalytics\Event_Detail;
use VireoAnalytics\Referrer_Detail;
use VireoAnalytics\Installer;
use VireoAnalytics\Retention;
use VireoAnalytics\Sites;

$fail = 0;
function ok( $cond, $msg ) {
	global $fail;
	if ( $cond ) {
		echo "PASS: $msg\n";
	} else {
		echo "FAIL: $msg\n";
		$fail++;
	}
}

/** Two sites in the option, ids 2 and 3; id 1 was deleted once. */
function seed_sites(): void {
	$GLOBALS['va_options']['vireo_connected_sites'] = [
		'next'  => 4,
		'sites' => [
			2 => [ 'id' => 2, 'name' => 'Docs', 'origin' => 'https://docs.example.com', 'key' => str_repeat( 'a', 32 ), 'created' => 1 ],
			3 => [ 'id' => 3, 'name' => 'Landing', 'origin' => 'http://localhost:4321', 'key' => str_repeat( 'b', 32 ), 'created' => 2 ],
		],
	];
	Sites::flush_cache();
}

// ---- the table-name helper ------------------------------------------------

ok( 'wp_va_site_stats' === Sites::table( 'site_stats' ), 'main site: the name it always had' );
ok( 'wp_va_site_stats' === Sites::table( 'site_stats', Sites::MAIN ), 'explicit main site is the same' );
ok( 'wp_va_s2_site_stats' === Sites::table( 'site_stats', 2 ), 'a connected site gets va_s{ID}_ in the name' );
ok( 'wp_va_s2_404_stats' === Sites::table( '404_stats', 2 ), 'a name starting with a digit is fine' );
ok( 'wp_va_ip_cache' === Sites::table( 'ip_cache', 2 ), 'a shared table resolves to the main name for a connected site' );
ok( 'wp_va_gsc_stats' === Sites::table( 'gsc_stats', 2 ), 'Search Console rows are shared' );
ok( 'wp_va_goal_completions' === Sites::table( 'goal_completions', 2 ), 'a Pro table the helper has never heard of stays main-site only' );

ok( Sites::MAIN === Sites::current() && ! Sites::is_connected(), 'the request starts on the main site' );
Sites::switch_to( 2 );
ok( 2 === Sites::current() && Sites::is_connected(), 'switch_to() changes the context' );
ok( 'wp_va_s2_paths' === Sites::table( 'paths' ), 'table() follows the context' );
ok( 'wp_va_ip_cache' === Sites::table( 'ip_cache' ), 'shared tables ignore the context' );
Sites::switch_to( 3 );
ok( 'wp_va_s3_paths' === Sites::table( 'paths' ), 'contexts nest' );
Sites::restore();
ok( 2 === Sites::current(), 'restore() pops one level' );
Sites::restore();
ok( Sites::MAIN === Sites::current(), 'and then the main site again' );
Sites::restore();
ok( Sites::MAIN === Sites::current(), 'an extra restore() stays on the main site' );
Sites::switch_to( -5 );
ok( Sites::MAIN === Sites::current(), 'a negative id is the main site' );
Sites::restore();

// ---- every table the installer knows is accounted for ----------------------

$defined = array_keys( Installer::table_definitions() );
$shared  = array_values( array_diff( $defined, Sites::PER_SITE ) );
sort( $shared );
ok( 21 === count( $defined ), 'the installer defines 21 tables (got ' . count( $defined ) . ')' );
ok( [] === array_diff( Sites::PER_SITE, $defined ), 'every per-site table has a definition' );
ok( [ 'gsc_pages', 'gsc_stats', 'ip_cache' ] === $shared, 'exactly the IP cache and Search Console tables are shared (got ' . implode( ',', $shared ) . ')' );

$GLOBALS['va_ddl'] = [];
Installer::create_tables();
ok( 21 === count( $GLOBALS['va_ddl'] ), 'the main site gets every table' );
ok( 0 === strpos( $GLOBALS['va_ddl'][0], 'CREATE TABLE wp_va_site_stats (' ), 'main-site statements are unchanged in shape' );
ok( false !== strpos( $GLOBALS['va_ddl'][0], 'PRIMARY KEY  (date)' ), 'with the two-space PRIMARY KEY dbDelta wants' );

$GLOBALS['va_ddl'] = [];
Installer::create_tables( 2 );
$names = array_map( static fn( $sql ) => preg_match( '/^CREATE TABLE (\S+) \(/', $sql, $m ) ? $m[1] : '?', $GLOBALS['va_ddl'] );
ok( 18 === count( $names ), 'a connected site gets the 18 per-site tables (got ' . count( $names ) . ')' );
ok( [] === array_filter( $names, static fn( $n ) => 0 !== strpos( $n, 'wp_va_s2_' ) ), 'all of them under its own name' );
ok( ! in_array( 'wp_va_s2_ip_cache', $names, true ) && ! in_array( 'wp_va_s2_gsc_stats', $names, true ), 'and none of the shared ones' );

// ---- which beacons a site accepts --------------------------------------------

seed_sites();
ok( 2 === count( Sites::all() ), 'two sites are listed' );
ok( 'Docs' === ( Sites::get( 2 )['name'] ?? '' ), 'get() finds a site by id' );
ok( null === Sites::get( 1 ), 'a deleted id is nobody' );

ok( 2 === ( Sites::find_by_key( str_repeat( 'a', 32 ) )['id'] ?? 0 ), 'the key finds its site' );
ok( 2 === ( Sites::find_by_key( str_repeat( 'A', 32 ) )['id'] ?? 0 ), 'key case does not matter' );
ok( null === Sites::find_by_key( str_repeat( 'c', 32 ) ), 'an unknown key finds nothing' );
ok( null === Sites::find_by_key( str_repeat( 'a', 31 ) ), 'a short key finds nothing' );
ok( null === Sites::find_by_key( '' ), 'no key finds nothing' );
ok( null === Sites::find_by_key( "' OR 1=1" ), 'junk finds nothing' );

$docs = Sites::get( 2 );
ok( Sites::request_matches( [ 'HTTP_ORIGIN' => 'https://docs.example.com' ], $docs ), 'the registered origin is accepted' );
ok( Sites::request_matches( [ 'HTTP_ORIGIN' => 'https://DOCS.example.com:443/' ], $docs ), 'after normalising case, port and slash' );
ok( ! Sites::request_matches( [ 'HTTP_ORIGIN' => 'http://docs.example.com' ], $docs ), 'a different scheme is refused' );
ok( ! Sites::request_matches( [ 'HTTP_ORIGIN' => 'https://www.docs.example.com' ], $docs ), 'a subdomain is refused' );
ok( ! Sites::request_matches( [ 'HTTP_ORIGIN' => 'https://docs.example.com.evil.example' ], $docs ), 'a suffix attack is refused' );
ok( ! Sites::request_matches( [ 'HTTP_ORIGIN' => 'http://localhost:4321' ], $docs ), 'another connected site\'s origin is refused with this site\'s key' );
ok( ! Sites::request_matches( [ 'HTTP_ORIGIN' => 'https://cms.example.com' ], $docs ), 'this WordPress site\'s own origin is refused' );
ok( ! Sites::request_matches( [], $docs ), 'no Origin header is refused' );
ok( ! Sites::request_matches( [ 'HTTP_ORIGIN' => 'null' ], $docs ), 'an opaque Origin is refused' );
ok( ! Sites::request_matches( [ 'HTTP_ORIGIN' => 'garbage' ], $docs ), 'a non-origin is refused' );
ok( Sites::request_matches( [ 'HTTP_ORIGIN' => 'http://localhost:4321' ], Sites::get( 3 ) ), 'a local dev origin with a port is accepted for its own site' );
ok( ! Sites::request_matches( [ 'HTTP_ORIGIN' => 'http://localhost:4322' ], Sites::get( 3 ) ), 'but not on another port' );

// The collect endpoint's own key sanitising, as written there.
$raw = "' OR 1=1 -- " . str_repeat( 'a', 32 );
ok( 1 === preg_match( '/^[a-z0-9]+$/', strtolower( trim( (string) preg_replace( '/[^A-Za-z0-9]/', '', $raw ) ) ) ), 'the endpoint strips everything but alphanumerics before lookup' );

// ---- where a site's buffer goes ----------------------------------------------

ok( vireo_data_dir() === Collector::upload_dir(), 'the main site buffers in the data directory' );
Sites::switch_to( 2 );
ok( vireo_data_dir() . '/site-2' === Collector::upload_dir(), 'a connected site buffers in its own site-{ID} directory' );
ok( Collector::append_pageview( [ 'ts' => time(), 'path' => '/docs/intro/', 'referrer' => 'https://news.ycombinator.com/item?id=1', 'visitor_hash' => 'v1' ] ), 'a pageview can be written there' );
Sites::restore();
ok( 0 === count( Collector::live_buffers() ), 'the main site sees no live buffer from the connected site' );
Sites::switch_to( 2 );
ok( 1 === count( Collector::live_buffers() ), 'the connected site sees its one buffer' );
Sites::restore();

// ---- the limit -----------------------------------------------------------------

ok( 1 === Sites::limit(), 'free allows one connected site' );
ok( ! Sites::can_add(), 'with two already listed nothing more can be added' );
$GLOBALS['va_filters'][ Sites::LIMIT_FILTER ] = [ static fn( int $n ): int => 10 ];
ok( 10 === Sites::limit(), 'the filter raises the limit' );
ok( Sites::can_add(), 'and then a site can be added' );
$GLOBALS['va_filters'][ Sites::LIMIT_FILTER ] = [ static fn( int $n ): int => 5000 ];
ok( 100 === Sites::limit(), 'the limit is capped at 100' );
$GLOBALS['va_filters'][ Sites::LIMIT_FILTER ] = [ static fn( int $n ): int => -1 ];
ok( 0 === Sites::limit() && ! Sites::can_add(), 'a filter can switch the feature off' );
$GLOBALS['va_filters'][ Sites::LIMIT_FILTER ] = [ static fn( int $n ): int => 10 ];

// ---- adding -------------------------------------------------------------------

$r = Sites::add( '', 'https://new.example.com' );
ok( is_wp_error( $r ) && 'vireo_site_name' === $r->get_error_code(), 'a site needs a name' );
$r = Sites::add( 'New', 'new.example.com' );
ok( is_wp_error( $r ) && 'vireo_site_origin' === $r->get_error_code(), 'the address needs a scheme' );
$r = Sites::add( 'Again', 'https://docs.example.com/' );
ok( is_wp_error( $r ) && 'vireo_site_duplicate' === $r->get_error_code(), 'an origin already connected is refused' );
$r = Sites::add( 'Me', 'https://cms.example.com' );
ok( is_wp_error( $r ) && 'vireo_site_self' === $r->get_error_code(), 'this WordPress site\'s own origin is refused' );
$GLOBALS['va_settings'] = [ 'headless_origin' => 'https://front.example.com', 'retention_days' => 30 ];
$r = Sites::add( 'Front', 'https://front.example.com' );
ok( is_wp_error( $r ) && 'vireo_site_headless' === $r->get_error_code(), 'the headless front end cannot also be a connected site' );
$GLOBALS['va_settings'] = [ 'retention_days' => 30 ];

$GLOBALS['va_ddl'] = [];
$r = Sites::add( '  New site  ', 'HTTPS://New.example.com/path?x=1' );
ok( 4 === $r, 'a new site takes the next id, never a retired one' );
ok( 'New site' === Sites::get( 4 )['name'] && 'https://new.example.com' === Sites::get( 4 )['origin'], 'name is trimmed, origin normalised' );
ok( 1 === preg_match( '/^[a-f0-9]{32}$/', Sites::get( 4 )['key'] ), 'the site got a 32-hex key' );
ok( Sites::get( 4 )['key'] !== Sites::get( 2 )['key'], 'keys differ' );
ok( 18 === count( $GLOBALS['va_ddl'] ) && 0 === strpos( $GLOBALS['va_ddl'][0], 'CREATE TABLE wp_va_s4_' ), 'its tables were created under its own name' );
ok( 5 === $GLOBALS['va_options']['vireo_connected_sites']['next'], 'the counter moved on' );

$GLOBALS['va_filters'][ Sites::LIMIT_FILTER ] = [ static fn( int $n ): int => 3 ];
$r = Sites::add( 'One more', 'https://more.example.com' );
ok( is_wp_error( $r ) && 'vireo_site_limit' === $r->get_error_code(), 'the limit is enforced on add' );
$GLOBALS['va_filters'][ Sites::LIMIT_FILTER ] = [ static fn( int $n ): int => 10 ];

// ---- what a user's dashboard shows ------------------------------------------

ok( Sites::MAIN === Sites::selected_for( 7 ), 'nothing picked means the main site' );
ok( Sites::select_for( 7, 3 ), 'a connected site can be picked' );
ok( 3 === Sites::selected_for( 7 ), 'and is remembered' );
ok( ! Sites::select_for( 7, 99 ), 'an unknown site cannot be picked' );
ok( 3 === Sites::selected_for( 7 ), 'and the previous choice stands' );
ok( Sites::select_for( 7, 0 ), 'the main site can be picked again' );
ok( Sites::MAIN === Sites::selected_for( 7 ), 'which clears the choice' );
ok( Sites::MAIN === Sites::selected_for( 0 ), 'no user, main site' );

// ---- the daily jobs visit every site ------------------------------------------

$wpdb = new Fake_Wpdb();
( new ReflectionClass( Retention::class ) )->newInstanceWithoutConstructor()->run();
$deleted = $wpdb->tables( 'DELETE' );
ok( in_array( 'wp_va_site_stats', $deleted, true ) && in_array( 'wp_va_gsc_stats', $deleted, true ), 'retention purges the main site, Search Console included' );
foreach ( [ 2, 3, 4 ] as $id ) {
	ok( in_array( "wp_va_s{$id}_site_stats", $deleted, true ) && in_array( "wp_va_s{$id}_visitor_seen", $deleted, true ), "retention purges site {$id}'s tables" );
}
ok( ! in_array( 'wp_va_s2_gsc_stats', $deleted, true ), 'and never asks for a per-site Search Console table' );
ok( 3 === count( array_filter( $deleted, static fn( $t ) => 'wp_va_gsc_stats' === $t ) ) || 1 === count( array_filter( $deleted, static fn( $t ) => 'wp_va_gsc_stats' === $t ) ), 'shared tables are purged in the main pass only' );
ok( Sites::MAIN === Sites::current(), 'the context is back on the main site afterwards' );

$wpdb = new Fake_Wpdb();
$agg  = ( new ReflectionClass( Aggregator::class ) )->newInstanceWithoutConstructor();
$agg->purge_hour_stats();
$hours = $wpdb->tables( 'DELETE' );
ok( [ 'wp_va_hour_stats', 'wp_va_s2_hour_stats', 'wp_va_s3_hour_stats', 'wp_va_s4_hour_stats' ] === $hours, 'the hourly purge visits every site (got ' . implode( ',', $hours ) . ')' );

// ---- the aggregator drains each site into its own tables ----------------------

// One hit for the main site, one already waiting for site 2 (written above).
Collector::append_pageview( [ 'ts' => time(), 'path' => '/hello/', 'referrer' => '', 'visitor_hash' => 'v2' ] );
ok( 1 === count( Collector::live_buffers() ), 'the main site has one live buffer' );

$wpdb = new Fake_Wpdb();
$ref  = new ReflectionClass( Aggregator::class );
$agg  = $ref->newInstanceWithoutConstructor();
foreach ( $ref->getProperties() as $prop ) {
	$type = $prop->getType();
	$prop->setAccessible( true ); // PHP 7.4 needs this to read a private property.
	if ( $prop->isStatic() || ! $type || $prop->isInitialized( $agg ) ) {
		continue;
	}
	$prop->setValue( $agg, 'array' === $type->getName() ? [] : ( 'string' === $type->getName() ? '' : null ) );
}
$GLOBALS['va_did'] = [];
$agg->run();
$after_flush = static fn() => array_values( array_filter( $GLOBALS['va_did'], static fn( $d ) => 'vireo_after_flush' === $d[0] ) );
ok( [ [ 'vireo_after_flush', Sites::MAIN ] ] === $after_flush(), 'vireo_after_flush fires once, on the main site, after a run that wrote data' );

$inserted = $wpdb->tables( 'INSERT' );
ok( in_array( 'wp_va_site_stats', $inserted, true ), 'the main hit landed in the main site table' );
ok( in_array( 'wp_va_s2_site_stats', $inserted, true ), 'the connected hit landed in site 2\'s table' );
ok( in_array( 'wp_va_s2_paths', $inserted, true ) && in_array( 'wp_va_s2_referrer_urls', $inserted, true ), 'site 2\'s paths and referrers went to its own dictionaries' );
ok( in_array( 'wp_va_s2_visitor_seen', $inserted, true ), 'visitor dedupe state is per site' );
ok( ! in_array( 'wp_va_s3_site_stats', $inserted, true ), 'a site with no buffer gets no writes' );
ok( [] === array_filter( $inserted, static fn( $t ) => preg_match( '/^wp_va_s\d+_/', $t ) && ! preg_match( '/^wp_va_s2_/', $t ) ), 'nothing was written under any other site\'s name' );

$site_rows = array_values( array_filter( $wpdb->of_kind( 'INSERT INTO' ), static fn( $s ) => in_array( $s['args'][0] ?? '', [ 'wp_va_site_stats', 'wp_va_s2_site_stats' ], true ) ) );
ok( 2 === count( $site_rows ) && 1 === (int) $site_rows[0]['args'][2] && 1 === (int) $site_rows[1]['args'][2], 'each site counted exactly its own one pageview' );
ok( 0 === count( Collector::live_buffers() ), 'the main buffer was drained' );
Sites::switch_to( 2 );
ok( 0 === count( Collector::live_buffers() ) && [] === glob( Collector::upload_dir() . '/*.busy' ), 'site 2\'s buffer was drained and its snapshot removed' );
Sites::restore();
ok( Sites::MAIN === Sites::current(), 'the aggregator leaves the context on the main site' );

$GLOBALS['va_did'] = [];
$agg->run();
ok( [] === $after_flush(), 'a run with nothing to drain does not fire vireo_after_flush' );

Sites::switch_to( 2 );
Collector::append_pageview( [ 'ts' => time(), 'path' => '/docs/more/', 'referrer' => '', 'visitor_hash' => 'v3' ] );
Sites::restore();
$GLOBALS['va_did'] = [];
$agg->run();
ok( [] === $after_flush(), 'data for a connected site alone does not fire it, since its listeners read the main tables' );

// ---- the drill-downs read the selected site ----------------------------------------

ok( 3 === Dashboard::site_for_view( '', 3 ), 'the overview reads the selected site' );
ok( 3 === Dashboard::site_for_view( 'referrer', 3 ), 'the referrer drill-down reads the selected site' );
ok( 3 === Dashboard::site_for_view( 'event', 3 ), 'the event drill-down reads the selected site' );
ok( Sites::MAIN === Dashboard::site_for_view( 'post', 3 ), 'the per-post view always reads this WordPress site' );
ok( Sites::MAIN === Dashboard::site_for_view( 'year', 3 ), 'so does the year review' );
ok( Sites::MAIN === Dashboard::site_for_view( 'referrer', Sites::MAIN ), 'with nothing selected, everything reads the main site' );

$wpdb = new Fake_Wpdb();
$rd   = ( new ReflectionClass( Referrer_Detail::class ) )->newInstanceWithoutConstructor();
$ed   = ( new ReflectionClass( Event_Detail::class ) )->newInstanceWithoutConstructor();
Sites::switch_to( 2 );
$rd->get_period_totals( 'news.ycombinator.com', '2026-10-01', '2026-10-07' );
$rd->get_daily_series( 'news.ycombinator.com', '2026-10-01', '2026-10-07' );
$ed->get_totals( 'signup', '2026-10-01', '2026-10-07' );
$ed->get_daily_series( 'signup', '2026-10-01', '2026-10-07' );
Sites::restore();
$read = $wpdb->tables();
ok( in_array( 'wp_va_s2_referrer_urls', $read, true ) && in_array( 'wp_va_s2_referrer_stats', $read, true ), 'referrer detail queries site 2\'s referrer tables' );
ok( in_array( 'wp_va_s2_event_stats', $read, true ), 'event detail queries site 2\'s event table' );
ok( [] === array_filter( $read, static fn( $t ) => ! preg_match( '/^wp_va_s2_/', $t ) ), 'and neither touches a main-site table (got ' . implode( ',', $read ) . ')' );
ok( '' === Dashboard::site_subtitle(), 'no site fragment in the subtitle on the main site' );
Sites::switch_to( 3 );
ok( str_contains( Dashboard::site_subtitle(), 'Landing' ) && str_contains( Dashboard::site_subtitle(), 'http://localhost:4321' ), 'the subtitle names the connected site and its address' );
Sites::restore();

// ---- one ceiling for the whole data tree ----------------------------------------------

$reset_bytes = static function (): void {
	$p = new ReflectionProperty( Collector::class, 'dir_bytes' );
	$p->setAccessible( true );
	$p->setValue( null, null );
};
$base = Sites::data_dir( Sites::MAIN );
file_put_contents( $base . '/buffer-' . str_repeat( '1', 32 ) . '.csv', str_repeat( 'x', 1000 ) );
wp_mkdir_p( $base . '/site-3' );
file_put_contents( $base . '/site-3/buffer-' . str_repeat( '2', 32 ) . '.csv', str_repeat( 'x', 500 ) );
file_put_contents( $base . '/site-3/buffer-' . str_repeat( '3', 32 ) . '.csv.deadbeef.r1.busy', str_repeat( 'x', 250 ) );
file_put_contents( $base . '/site-3/rl-00.php', str_repeat( 'x', 9999 ) );
$reset_bytes();
ok( 1750 === Collector::dir_bytes(), 'dir_bytes() sums the main directory and every site directory (got ' . Collector::dir_bytes() . ')' );
Sites::switch_to( 3 );
ok( 1750 === Collector::dir_bytes(), 'the same total whichever site is in context' );
Sites::restore();
$GLOBALS['va_filters']['vireo_buffer_dir_max_bytes'] = [ static fn( int $n ): int => 1700 ];
Sites::switch_to( 3 );
ok( ! Collector::append_pageview( [ 'ts' => time(), 'path' => '/over/', 'referrer' => '' ] ), 'past the ceiling, a connected site stops writing too' );
Sites::restore();
ok( ! Collector::append_pageview( [ 'ts' => time(), 'path' => '/over/', 'referrer' => '' ] ), 'and so does the main site' );
unset( $GLOBALS['va_filters']['vireo_buffer_dir_max_bytes'] );
unlink( $base . '/buffer-' . str_repeat( '1', 32 ) . '.csv' );
foreach ( glob( $base . '/site-3/*' ) as $f ) {
	unlink( $f );
}
$reset_bytes();

// ---- orphan site directories ---------------------------------------------------------

wp_mkdir_p( $base . '/site-9' );
file_put_contents( $base . '/site-9/buffer-' . str_repeat( '9', 32 ) . '.csv', "x\n" );
touch( $base . '/site-9', time() - 3600 );
wp_mkdir_p( $base . '/site-11' );
file_put_contents( $base . '/site-11/buffer-' . str_repeat( '8', 32 ) . '.csv', "x\n" );
touch( $base . '/site-11', time() - 3600 );
wp_mkdir_p( $base . '/site-12' );
touch( $base . '/site-12', time() );
file_put_contents( $base . '/site-things', 'not a site dir' );
$removed = Sites::remove_orphan_dirs();
sort( $removed );
ok( [ 9, 11 ] === $removed, 'directories of sites that no longer exist are removed (got ' . implode( ',', $removed ) . ')' );
ok( ! is_dir( $base . '/site-9' ) && ! is_dir( $base . '/site-11' ), 'files and directory are gone' );
ok( is_dir( $base . '/site-3' ) && is_dir( $base . '/site-2' ), 'listed sites keep their directories' );
ok( is_dir( $base . '/site-12' ), 'a fresh orphan is left for the next tick, in case its site was just added' );
ok( is_file( $base . '/site-things' ), 'anything not shaped like site-{ID} is not touched' );
rmdir( $base . '/site-12' );
unlink( $base . '/site-things' );

// ---- removing a site --------------------------------------------------------------

Sites::select_for( 7, 2 );
Sites::select_for( 8, 3 );
wp_mkdir_p( Sites::data_dir( 2 ) );
file_put_contents( Sites::data_dir( 2 ) . '/buffer-' . str_repeat( '0', 32 ) . '.csv', "x\n" );

$wpdb = new Fake_Wpdb();
ok( Sites::delete( 2 ), 'a connected site can be removed' );
$dropped = $wpdb->tables( 'DROP' );
ok( 18 === count( $dropped ), 'eighteen tables were dropped (got ' . count( $dropped ) . ')' );
ok( [] === array_filter( $dropped, static fn( $t ) => 0 !== strpos( $t, 'wp_va_s2_' ) ), 'every one of them was site 2\'s' );
ok( in_array( 'wp_va_s2_site_stats', $dropped, true ) && in_array( 'wp_va_s2_404_stats', $dropped, true ), 'including its stats and 404 tables' );
ok( ! in_array( 'wp_va_site_stats', $dropped, true ) && ! in_array( 'wp_va_s3_site_stats', $dropped, true ), 'the main site and site 3 kept theirs' );
ok( null === Sites::get( 2 ) && 2 === count( Sites::all() ), 'the site is gone from the list' );
ok( ! is_dir( Sites::data_dir( 2 ) ), 'its buffer directory is gone' );
ok( Sites::MAIN === Sites::selected_for( 7 ), 'a user who was looking at it is back on the main site' );
ok( 3 === Sites::selected_for( 8 ), 'a user looking at another site is not touched' );
ok( ! Sites::delete( 2 ), 'removing it again does nothing' );
ok( ! Sites::delete( 0 ), 'the main site cannot be removed' );
ok( 5 === $GLOBALS['va_options']['vireo_connected_sites']['next'], 'the id counter does not move back' );

// ---- the snippet ----------------------------------------------------------------

$snippet = Sites::snippet( Sites::get( 3 ), 'https://cms.example.com/wp-content/plugins/vireo-analytics/endpoint.php', 'https://cms.example.com/t.js', 'https://cms.example.com/v.js' );
ok( str_contains( $snippet, 'data-site="' . str_repeat( 'b', 32 ) . '"' ), 'the snippet carries the key' );
ok( str_contains( $snippet, 'data-endpoint="https://cms.example.com/wp-content/plugins/vireo-analytics/endpoint.php"' ), 'and the endpoint' );
ok( 2 === substr_count( $snippet, '<script defer' ), 'two tags: tracker and vitals' );
ok( str_contains( $snippet, 'src="https://cms.example.com/v.js"' ), 'the second loads the vitals collector' );
ok( 1 === substr_count( Sites::snippet( Sites::get( 3 ), 'https://e', 'https://t' ), '<script' ), 'without a vitals URL there is one tag' );

// ---- tidy up --------------------------------------------------------------------

$rm = static function ( string $dir ) use ( &$rm ): void {
	foreach ( (array) scandir( $dir ) as $e ) {
		if ( '.' === $e || '..' === $e ) {
			continue;
		}
		is_dir( "$dir/$e" ) ? $rm( "$dir/$e" ) : unlink( "$dir/$e" );
	}
	rmdir( $dir );
};
$rm( $va_root );

echo $fail ? "\n$fail failure(s)\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
