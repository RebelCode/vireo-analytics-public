<?php
/**
 * Zero-dependency check of Form_Events: only the active form plugins get a
 * listener, an accepted submission records `form_success` with the form's id
 * and title and nothing else, and the beacon's exclusion gate applies.
 * Run: php tests/test-form-events.php
 *
 * Control: the same Contact Form 7 callback fired by a request whose
 * User-Agent is Googlebot records nothing.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}
if ( ! defined( 'VIREO_DIR' ) ) {
	define( 'VIREO_DIR', dirname( __DIR__ ) . '/' );
}

// Only Contact Form 7 is "active" in this process.
define( 'WPCF7_VERSION', '6.0' );

$GLOBALS['va_hooks']   = [];
$GLOBALS['va_tracked'] = [];

function add_action( $hook, $cb, $priority = 10, $args = 1 ) {
	$GLOBALS['va_hooks'][ $hook ] = $cb;
}
function apply_filters( $hook, $value, ...$a ) {
	return $value;
}
function sanitize_text_field( $s ) {
	return trim( (string) preg_replace( '/[\x00-\x1F\x7F]/', '', strip_tags( (string) $s ) ) );
}
function wp_strip_all_tags( $s ) {
	return trim( strip_tags( (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $s ) ) );
}
function wp_unslash( $s ) {
	return $s;
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}
function vireo_get_client_ip() {
	return '203.0.113.9';
}
function vireo_track_event( string $name, string $value = '' ): bool {
	$GLOBALS['va_tracked'][] = [ $name, $value ];
	return true;
}
function __( $s, $d = null ) {
	return $s;
}

require __DIR__ . '/../includes/class-collector.php';
require __DIR__ . '/../includes/class-bot-filter.php';
require __DIR__ . '/../includes/class-form-events.php';

use VireoAnalytics\Form_Events;

$fail = 0;
function ok( $cond, $msg ) {
	global $fail;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}

$chrome = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';

ok( [ 'cf7' => 'Contact Form 7' ] === Form_Events::active(), 'only the plugin whose constant is defined counts as active' );
ok( array_keys( Form_Events::selectors() ) === [ 'cf7', 'wpforms', 'gravity', 'fluent', 'elementor', 'breakdance' ], 'every supported plugin has a form selector for the tracker' );

Form_Events::boot();
ok( isset( $GLOBALS['va_hooks']['wpcf7_mail_sent'] ), 'Contact Form 7 success hook is registered' );
ok( ! isset( $GLOBALS['va_hooks']['wpforms_process_complete'] ) && ! isset( $GLOBALS['va_hooks']['gform_after_submission'] ) && ! isset( $GLOBALS['va_hooks']['fluentform/submission_inserted'] ) && ! isset( $GLOBALS['va_hooks']['elementor_pro/forms/new_record'] ), 'inactive plugins get no listener' );
ok( ! isset( $GLOBALS['va_hooks']['init'] ), 'Breakdance action is not registered without Breakdance' );

// A Contact Form 7 form object, as the hook hands it over.
final class Fake_CF7_Form {
	public function id() {
		return 3;
	}
	public function title() {
		return 'Contact us';
	}
}

$_SERVER['HTTP_USER_AGENT'] = $chrome;
$_SERVER['REQUEST_URI']     = '/contact/';
( $GLOBALS['va_hooks']['wpcf7_mail_sent'] )( new Fake_CF7_Form() );
ok( [ [ 'form_success', '#3 Contact us' ] ] === $GLOBALS['va_tracked'], 'an accepted submission records form_success with the id and title' );

// Control: the same hook from a crawler records nothing.
$GLOBALS['va_tracked']      = [];
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
( $GLOBALS['va_hooks']['wpcf7_mail_sent'] )( new Fake_CF7_Form() );
ok( [] === $GLOBALS['va_tracked'], 'control: a bot submitting the form is not recorded' );
$_SERVER['HTTP_USER_AGENT'] = $chrome;

// The value carries the form's id and title only.
ok( '#12 Newsletter' === Form_Events::value( 12, 'Newsletter' ), 'value is "#id title"' );
ok( '#12' === Form_Events::value( 12, '' ), 'a form with no title is "#id"' );
ok( 'Quote' === Form_Events::value( '', 'Quote' ), 'no id leaves just the title' );
ok( '#a1b2 Hero form' === Form_Events::value( 'a1b2', 'Hero form' ), 'Elementor-style hex ids are kept as given' );
ok( '#5 Hello' === Form_Events::value( 5, '<b>Hello</b><script>alert(1)</script>' ), 'markup in a title is stripped' );
ok( strlen( Form_Events::value( 1, str_repeat( 'x', 300 ) ) ) <= 100, 'the value fits the 100-byte column' );
ok( '' === Form_Events::value( '', '' ), 'nothing to say records nothing' );

// Record only sends when there is something to say.
$GLOBALS['va_tracked'] = [];
Form_Events::record( '', '' );
ok( [] === $GLOBALS['va_tracked'], 'record() with no id and no title is a no-op' );

echo $fail ? "\n$fail failed\n" : "\nall passed\n";
exit( $fail ? 1 : 0 );
