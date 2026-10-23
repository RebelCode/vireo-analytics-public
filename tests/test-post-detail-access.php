<?php
/**
 * Zero-dependency check of who may open a post's stats screen.
 * Run: php tests/test-post-detail-access.php
 *
 * The screen takes any post_id from the URL. Anyone holding
 * view_vireo_analytics (editors, clients with read-only dashboard access)
 * could type a draft's or a private post's ID and read its title, permalink
 * and traffic. The post has to be one the viewer could see on the site, or
 * one they could open in the editor.
 */

// PHP 8 string helpers, so the zero-dependency runner also works on 7.4,
// where WordPress itself provides these.
if ( ! function_exists( 'str_contains' ) ) {
	function str_contains( $h, $n ) { return '' === $n || false !== strpos( $h, $n ); }
}
if ( ! function_exists( 'str_starts_with' ) ) {
	function str_starts_with( $h, $n ) { return 0 === strncmp( $h, $n, strlen( $n ) ); }
}
if ( ! function_exists( 'str_ends_with' ) ) {
	function str_ends_with( $h, $n ) { return '' === $n || substr( $h, -strlen( $n ) ) === $n; }
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}
if ( ! function_exists( '__' ) ) {
	function __( $s, $d = null ) { return $s; }
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $s, $d = null ) { return htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $n ) { return abs( (int) $n ); }
}

// Post 1 is published; 3 is a draft the viewer cannot edit; 4 is a draft the
// viewer wrote.
$GLOBALS['va_posts']  = [ 1 => 'publish', 3 => 'draft', 4 => 'draft' ];
$GLOBALS['va_public'] = [ 1 => true ];
$GLOBALS['va_caps']   = [ 'view_vireo_analytics' => true, 'read_post' => [ 4 => true ] ];

if ( ! function_exists( 'get_post' ) ) {
	function get_post( $id ) {
		if ( ! isset( $GLOBALS['va_posts'][ (int) $id ] ) ) {
			return null;
		}
		$p = new WP_Post();
		$p->ID = (int) $id;
		$p->post_status = $GLOBALS['va_posts'][ (int) $id ];
		return $p;
	}
}
if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post { public int $ID = 0; public string $post_status = ''; }
}
if ( ! function_exists( 'is_post_publicly_viewable' ) ) {
	function is_post_publicly_viewable( $post ) {
		$id = is_object( $post ) ? (int) $post->ID : (int) $post;
		return ! empty( $GLOBALS['va_public'][ $id ] );
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap, ...$args ) {
		$grant = $GLOBALS['va_caps'][ $cap ] ?? false;
		return is_array( $grant ) ? ! empty( $grant[ (int) ( $args[0] ?? 0 ) ] ) : (bool) $grant;
	}
}

require __DIR__ . '/../includes/class-post-detail.php';

use VireoAnalytics\Post_Detail;

$fail = 0;
function ok( $cond, $msg ) {
	global $fail;
	echo ( $cond ? 'PASS: ' : 'FAIL: ' ) . $msg . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}

ok( Post_Detail::can_view( get_post( 1 ), 1 ), 'a published post can be opened by any dashboard viewer' );
ok( ! Post_Detail::can_view( get_post( 3 ), 3 ), 'a draft the viewer cannot read is refused' );
ok( Post_Detail::can_view( get_post( 4 ), 4 ), 'a draft the viewer could open in the editor is allowed' );

// render() stops at the not-found branch before touching the database.
$detail = ( new ReflectionClass( Post_Detail::class ) )->newInstanceWithoutConstructor();
$_GET['post_id'] = '3';
ob_start();
$detail->render();
$out = ob_get_clean();
ok( str_contains( $out, 'Post not found.' ) && ! str_contains( $out, 'va-page-title' ), 'the screen for a draft the viewer cannot read says "Post not found."' );

echo $fail ? "\n$fail failure(s)\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
