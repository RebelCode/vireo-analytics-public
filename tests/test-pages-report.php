<?php
/**
 * Zero-dependency check of the Pages tab's request handling and links.
 * Run: php tests/test-pages-report.php
 *
 * The SQL needs a database and is covered by tests/wp/pages-report.php.
 *
 * Control: let normalize() pass any sort key through and the unknown-sort
 * checks fail.
 */

namespace VireoAnalytics {
	class Dashboard {
		public static function view_nonce(): string {
			return 'nonce123';
		}
	}
}

namespace {
	define( 'ABSPATH', __DIR__ );

	function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
	function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
	function admin_url( $p = '' ) { return 'https://example.com/wp-admin/' . $p; }
	function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }

	require __DIR__ . '/../includes/class-pages-report.php';

	use VireoAnalytics\Pages_Report;

	$fail = 0;
	function ok( $cond, $msg ) {
		global $fail;
		echo ( $cond ? 'PASS' : 'FAIL' ) . ": $msg\n";
		if ( ! $cond ) {
			$fail++;
		}
	}

	$d = Pages_Report::normalize( [] );
	ok( [ 's' => '', 'by' => 'pageviews', 'dir' => 'desc', 'n' => 1 ] === $d, 'defaults: pageviews, descending, page 1, no search' );

	ok( 'visitors' === Pages_Report::normalize( [ 'by' => 'visitors' ] )['by'], 'visitors is a sort column' );
	ok( 'avg_time' === Pages_Report::normalize( [ 'by' => 'AVG_TIME' ] )['by'], 'the sort column is case-folded' );
	ok( 'pageviews' === Pages_Report::normalize( [ 'by' => 'path; DROP TABLE x' ] )['by'], 'an unknown sort column falls back' );
	ok( 'pageviews' === Pages_Report::normalize( [ 'by' => 'post_id' ] )['by'], 'a real column that is not offered falls back' );
	ok( 'asc' === Pages_Report::normalize( [ 'dir' => 'ASC' ] )['dir'], 'asc is accepted in any case' );
	ok( 'desc' === Pages_Report::normalize( [ 'dir' => 'rand()' ] )['dir'], 'an unknown direction falls back' );
	ok( 1 === Pages_Report::normalize( [ 'n' => '0' ] )['n'] && 1 === Pages_Report::normalize( [ 'n' => '-3' ] )['n'] && 1 === Pages_Report::normalize( [ 'n' => 'x' ] )['n'], 'page numbers below 1 or not numeric become 1' );
	ok( 7 === Pages_Report::normalize( [ 'n' => '7' ] )['n'], 'a page number is kept' );
	ok( 'hello' === Pages_Report::normalize( [ 's' => '  <b>hello</b> ' ] )['s'], 'search is stripped and trimmed' );
	ok( 100 === strlen( Pages_Report::normalize( [ 's' => str_repeat( 'a', 500 ) ] )['s'] ), 'search is capped at 100 characters' );

	$u = Pages_Report::url( 'last_30', '2026-01-01', '2026-01-30', '' );
	ok( false !== strpos( $u, 'tab=pages' ) && false !== strpos( $u, '_vrn=nonce123' ) && false !== strpos( $u, 'period=last_30' ), 'link carries the tab, the view nonce and the period' );
	ok( false === strpos( $u, 'pg_' ) && false === strpos( $u, 'compare' ), 'default view and no comparison add nothing' );

	$u = Pages_Report::url( 'custom', '2026-01-01', '2026-01-30', '1', [ 's' => 'x', 'by' => 'visitors', 'dir' => 'asc', 'n' => 3 ] );
	ok( false !== strpos( $u, 'start_date=2026-01-01' ) && false !== strpos( $u, 'end_date=2026-01-30' ) && false !== strpos( $u, 'period=custom' ), 'a custom range carries its dates' );
	ok( false !== strpos( $u, 'compare=1' ) && false !== strpos( $u, 'pg_s=x' ) && false !== strpos( $u, 'pg_by=visitors' ) && false !== strpos( $u, 'pg_dir=asc' ) && false !== strpos( $u, 'pg_n=3' ), 'comparison and the non-default view params are carried' );

	$u = Pages_Report::url( 'last_7', '', '', '', [ 'by' => 'pageviews', 'dir' => 'desc', 'n' => 1, 's' => '' ] );
	ok( false === strpos( $u, 'pg_' ), 'values equal to the defaults are left out of the link' );

	echo $fail ? "\n$fail failed\n" : "\nall passed\n";
	exit( $fail ? 1 : 0 );
}
