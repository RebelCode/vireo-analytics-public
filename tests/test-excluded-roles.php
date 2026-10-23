<?php
/**
 * Zero-dependency check of role-based exclusion: which logged-in visitors the
 * tracker leaves out, how a site saved before roles could be chosen reads,
 * and what the settings screen is allowed to store.
 * Run: php tests/test-excluded-roles.php
 *
 * vireo_get_exclusions() caches per request, so each stored-settings scenario
 * runs in its own PHP process: the file re-runs itself with VA_SCENARIO set
 * and reads the child's verdicts.
 *
 * Control: with `excluded_roles` = [ 'editor' ], an editor's visit is left out
 * and an author's is counted; swap the two roles in the child's expectations
 * and the test fails.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

$GLOBALS['va_options'] = [];
$GLOBALS['va_user']    = null;

function get_option( $key, $default = false ) {
	return $GLOBALS['va_options'][ $key ] ?? $default;
}
function wp_parse_args( $args, $defaults = [] ) {
	return array_merge( $defaults, (array) $args );
}
function is_user_logged_in() {
	return null !== $GLOBALS['va_user'];
}
function wp_get_current_user() {
	return $GLOBALS['va_user'];
}
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}
function __( $s, $d = null ) {
	return $s;
}
function apply_filters( $hook, $value, ...$a ) {
	return $value;
}
function add_action( ...$a ) {}
function add_filter( ...$a ) {}

require __DIR__ . '/../includes/va-filters.php';
require __DIR__ . '/../includes/class-settings.php';

$fail = 0;
function ok( $cond, $msg ) {
	global $fail;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}
function user_with( array $roles ) {
	$u        = new stdClass();
	$u->roles = $roles;
	return $u;
}

$scenario = getenv( 'VA_SCENARIO' ) ?: '';

// ---- child scenarios: one stored-settings shape per process -----------------

if ( 'editor' === $scenario ) {
	$GLOBALS['va_options']['vireo_settings'] = [ 'excluded_roles' => [ 'editor' ] ];

	$GLOBALS['va_user'] = user_with( [ 'editor' ] );
	ok( vireo_user_is_excluded(), 'editor excluded: an editor is left out' );
	$GLOBALS['va_user'] = user_with( [ 'author' ] );
	ok( ! vireo_user_is_excluded(), 'editor excluded: an author is counted (control)' );
	$GLOBALS['va_user'] = user_with( [ 'administrator' ] );
	ok( ! vireo_user_is_excluded(), 'editor excluded: an administrator is counted when not ticked' );
	$GLOBALS['va_user'] = user_with( [ 'subscriber', 'editor' ] );
	ok( vireo_user_is_excluded(), 'editor excluded: a user with two roles, one of them excluded, is left out' );
	ok( false === vireo_get_settings()['exclude_logged_in_admins'], 'editor excluded: the legacy admin flag Pro reads is false' );
	exit( $fail ? 1 : 0 );
}

if ( 'none' === $scenario ) {
	$GLOBALS['va_options']['vireo_settings'] = [ 'excluded_roles' => [] ];
	$GLOBALS['va_user']                      = user_with( [ 'administrator' ] );
	ok( ! vireo_user_is_excluded(), 'nothing excluded: an administrator is counted' );
	ok( true === vireo_get_settings()['track_logged_in_admins'], 'nothing excluded: track_logged_in_admins reads true' );
	exit( $fail ? 1 : 0 );
}

// ---- parent: defaults, then the children --------------------------------------

$GLOBALS['va_user'] = null;
ok( ! vireo_user_is_excluded(), 'default: an anonymous visitor is counted' );
$GLOBALS['va_user'] = user_with( [ 'administrator' ] );
ok( vireo_user_is_excluded(), 'default: an administrator is left out' );
$GLOBALS['va_user'] = user_with( [ 'editor' ] );
ok( ! vireo_user_is_excluded(), 'default: an editor is counted (control)' );
$GLOBALS['va_user'] = user_with( [] );
ok( ! vireo_user_is_excluded(), 'default: a logged-in user with no role is counted' );

$s = vireo_get_settings();
ok( [ 'administrator' ] === $s['excluded_roles'], 'default: excluded_roles is [administrator]' );
ok( true === $s['exclude_logged_in_admins'] && false === $s['track_logged_in_admins'], 'default: both legacy admin flags agree with the list' );

// A site that saved its settings before roles existed.
$GLOBALS['va_options']['vireo_settings'] = [ 'track_logged_in_admins' => true ];
$s = vireo_get_settings();
ok( [] === $s['excluded_roles'], 'legacy "track admins" on reads as no excluded roles' );
ok( false === $s['exclude_logged_in_admins'], 'legacy "track admins" on keeps the admin flag false' );

$GLOBALS['va_options']['vireo_settings'] = [ 'track_logged_in_admins' => false ];
$s = vireo_get_settings();
ok( [ 'administrator' ] === $s['excluded_roles'], 'legacy "track admins" off reads as administrators excluded' );

$GLOBALS['va_options']['vireo_settings'] = [ 'excluded_roles' => [ 'editor', 'editor', 'shop_manager' ] ];
$s = vireo_get_settings();
ok( [ 'editor', 'shop_manager' ] === $s['excluded_roles'], 'a stored list is de-duplicated' );
ok( false === $s['exclude_logged_in_admins'] && true === $s['track_logged_in_admins'], 'a list without administrator flips both legacy flags' );

// What the settings screen may store.
$known = [ 'administrator', 'editor', 'author', 'contributor', 'subscriber', 'shop_manager' ];
ok( [ 'editor', 'shop_manager' ] === \VireoAnalytics\Settings::sanitize_roles( [ 'editor', 'shop_manager' ], $known ), 'sanitize_roles keeps known roles in order' );
ok( [ 'editor' ] === \VireoAnalytics\Settings::sanitize_roles( [ 'editor', 'ghost_role', 'Editor<script>' ], $known ), 'sanitize_roles drops roles this site does not have' );
ok( [ 'editor' ] === \VireoAnalytics\Settings::sanitize_roles( 'editor', $known ), 'sanitize_roles reads a lone string as one role' );
ok( [] === \VireoAnalytics\Settings::sanitize_roles( null, $known ), 'sanitize_roles with nothing posted stores nothing' );
ok( [ 'author' ] === \VireoAnalytics\Settings::sanitize_roles( [ 'author', 'author' ], $known ), 'sanitize_roles de-duplicates' );

// Scenarios that need a fresh process.
foreach ( [ 'editor', 'none' ] as $child ) {
	$out  = [];
	$code = 1;
	exec( 'VA_SCENARIO=' . $child . ' ' . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' 2>&1', $out, $code );
	echo implode( "\n", $out ), "\n";
	ok( 0 === $code, "scenario '$child' passed in its own process" );
}

echo $fail ? "\n$fail failed\n" : "\nall passed\n";
exit( $fail ? 1 : 0 );
