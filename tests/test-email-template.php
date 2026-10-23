<?php
/**
 * Zero-dependency assertion runner for the plain weekly email template.
 * Run: php tests/test-email-template.php
 *
 * Renders the template with the escaping helpers shimmed, to check the "what
 * changed" sentence leads the email, is escaped, and disappears cleanly when
 * the payload has none (no prior week, or Pro rendering its own payload).
 */

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $n ) { return number_format( (float) $n ); }
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( '__' ) ) {
	function __( $s, $d = null ) { return $s; }
}
if ( ! function_exists( '_x' ) ) {
	function _x( $s, $c, $d = null ) { return $s; }
}
// The branding filter: tests swap the callback to brand the email.
$GLOBALS['va_test_brand'] = null;
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) {
		return 'vireo_email_brand' === $hook && is_array( $GLOBALS['va_test_brand'] ) ? $GLOBALS['va_test_brand'] : $value;
	}
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

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

function render_email( array $data, string $frequency = 'weekly' ): string {
	$period_label  = 'Sep 28 – Oct 4, 2026';
	$site_name     = 'Example';
	$site_url      = 'https://example.com';
	$dashboard_url = 'https://example.com/wp-admin/admin.php?page=vireo-analytics';
	ob_start();
	include __DIR__ . '/../templates/report-email-plain.php';
	return (string) ob_get_clean();
}

$base = [
	'period' => [ 'start' => '2026-09-28', 'end' => '2026-10-04' ],
	'totals' => [ 'visitors' => 900, 'pageviews' => 1200, 'prior_visitors' => 800, 'prior_pageviews' => 1000 ],
];

// 1. The sentence leads the body, ahead of the numbers.
$html = render_email( $base + [ 'insight' => 'Pageviews are up 20%. Most of the rise is new traffic from Google app.' ] );
$at   = strpos( $html, 'Most of the rise is new traffic from Google app.</div>' );
ok( false !== $at && $at < strpos( $html, '>Visitors</div>' ), 'insight renders above the stats block' );

// 2. It doubles as the inbox preview text.
ok( 2 === substr_count( $html, 'Pageviews are up 20%.' ), 'insight is also the preheader' );

// 3. A page title in the sentence is escaped.
$html = render_email( $base + [ 'insight' => 'Most of the rise is <script>x</script> & co.' ] );
ok( false === strpos( $html, '<script>x' ) && false !== strpos( $html, '&lt;script&gt;x' ), 'insight is escaped' );

// 4. No insight (no prior week, or Pro's own payload) → no empty row, old preheader.
$html = render_email( $base );
ok( false === strpos( $html, 'font-size:16px;line-height:1.5' ), 'no insight row when the payload has none' );
ok( false !== strpos( $html, 'Your weekly snapshot' ), 'preheader falls back to the period' );

// 5. Whitespace-only insight counts as none.
$html = render_email( $base + [ 'insight' => '   ' ] );
ok( false === strpos( $html, 'font-size:16px;line-height:1.5' ), 'blank insight is omitted' );

// 6. Highlights sit under the "what changed" line, escaped, three at most.
$hl   = [
	[ 'kind' => 'best_week', 'text' => 'Your best week so far: 2,100 pageviews.' ],
	[ 'kind' => 'gainer', 'text' => 'Tips & <b>tricks</b> gained the most.' ],
	[ 'kind' => 'new_referrer', 'text' => 'news.ycombinator.com sent visitors for the first time.' ],
	[ 'kind' => 'best_day', 'text' => 'Thursday was the busiest day.' ],
];
$html = render_email( $base + [ 'insight' => 'Pageviews are up 20%.', 'highlights' => $hl ] );
$ins  = strpos( $html, 'Pageviews are up 20%.</div>' );
$hl1  = strpos( $html, 'Your best week so far' );
ok( false !== $hl1 && $hl1 > $ins && $hl1 < strpos( $html, '>Visitors</div>' ), 'highlights render under the insight, above the stats' );
ok( false !== strpos( $html, 'Tips &amp; &lt;b&gt;tricks' ), 'highlight text is escaped' );
ok( false === strpos( $html, 'Thursday was the busiest day' ), 'no more than three highlights' );

// 7. No highlights (a quiet week, or Pro's own payload) → no list at all.
$html = render_email( $base + [ 'insight' => 'Pageviews are up 20%.' ] );
ok( false === strpos( $html, 'data-va-highlights' ), 'no highlights block without highlights' );
$html = render_email( $base + [ 'highlights' => [] ] );
ok( false === strpos( $html, 'data-va-highlights' ), 'empty highlights render nothing' );

// 8. Highlights still show when there is no insight line.
$html = render_email( $base + [ 'highlights' => [ $hl[0] ] ] );
ok( false !== strpos( $html, 'Your best week so far' ), 'highlights without an insight still render' );

// 9. No uppercase kicker label above the heading; the heading says what it is.
$html = render_email( $base );
ok( false === stripos( $html, '>Weekly Report</div>' ), 'no "Weekly Report" eyebrow' );
ok( false !== strpos( $html, '>Your week on Example</div>' ), 'heading names the week and the site' );
ok( false === strpos( $html, 'text-transform:uppercase;color:#64748b;">Weekly' ), 'nothing uppercase above the heading' );

// 10. Daily and monthly digests say so in the heading, the preview and the chip.
$html = render_email( $base, 'daily' );
ok( false !== strpos( $html, '>Your day on Example</div>' ), 'daily heading' );
ok( false !== strpos( $html, 'Your daily snapshot' ) && false !== strpos( $html, '% DoD' ), 'daily preheader and chip' );
$html = render_email( $base, 'monthly' );
ok( false !== strpos( $html, '>Your month on Example</div>' ) && false !== strpos( $html, '% MoM' ), 'monthly heading and chip' );
$html = render_email( $base, 'hourly' );
ok( false !== strpos( $html, '>Your week on Example</div>' ), 'an unknown frequency renders as weekly' );

// 11. Branding: default is Vireo's; the filter can add a logo, recolour the
// button and drop the Vireo name.
$html = render_email( $base );
ok( false !== strpos( $html, 'background:#0f172a;color:#ffffff' ) && false !== strpos( $html, 'Vireo Analytics' ) && false === strpos( $html, '<img' ), 'unbranded: dark button, Vireo footer, no logo' );
$GLOBALS['va_test_brand'] = [ 'logo_url' => 'https://agency.example/logo.png', 'accent' => '#b91c1c', 'show_vireo' => false ];
$html = render_email( $base );
ok( false !== strpos( $html, 'src="https://agency.example/logo.png"' ), 'the agency logo is shown' );
ok( false !== strpos( $html, 'background:#b91c1c;color:#ffffff' ), 'the button takes the accent colour' );
ok( false === strpos( $html, 'Vireo Analytics' ), 'the Vireo name is gone from the footer' );
$GLOBALS['va_test_brand'] = [ 'logo_url' => '', 'accent' => 'red;background:url(x)', 'show_vireo' => true ];
$html = render_email( $base );
ok( false !== strpos( $html, 'background:#0f172a;color:#ffffff' ), 'an accent that is not a hex colour is ignored' );
$GLOBALS['va_test_brand'] = null;

echo $fail ? "\n$fail failure(s)\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
