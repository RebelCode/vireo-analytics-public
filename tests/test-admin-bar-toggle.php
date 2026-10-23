<?php
/**
 * Zero-dependency check of the toolbar on/off setting.
 * Run: php tests/test-admin-bar-toggle.php
 *
 * The sparkline is on by default. Site owners can switch it off in Settings,
 * and the vireo_show_admin_bar filter can decide per user. Users without
 * access never get it, whatever the setting says.
 */

namespace VireoAnalytics {
	if ( ! class_exists( Installer::class ) ) {
		final class Installer { public const CAPABILITY = 'view_vireo_analytics'; }
	}
}

namespace {
	define( 'ABSPATH', __DIR__ );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'VIREO_DIR', __DIR__ . '/../' );
	define( 'VIREO_URL', 'https://example.com/wp-content/plugins/vireo-analytics/' );
	define( 'VIREO_VERSION', 'test' );

	$GLOBALS['va_settings'] = [];
	$GLOBALS['va_caps']     = [ 'view_vireo_analytics' => true ];
	$GLOBALS['va_filters']  = [];
	$GLOBALS['va_styles']   = [];

	function __( $s, $d = null ) { return $s; }
	function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
	function esc_html__( $s, $d = null ) { return esc_html( $s ); }
	function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
	function admin_url( $p = '' ) { return 'https://example.com/wp-admin/' . $p; }
	function number_format_i18n( $n ) { return number_format( (float) $n ); }
	function add_action( ...$a ) {}
	function is_admin_bar_showing() { return true; }
	function get_current_user_id() { return 7; }
	function current_user_can( $cap ) { return ! empty( $GLOBALS['va_caps'][ $cap ] ); }
	function get_transient( $k ) { return [ 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 42 ]; }
	function wp_enqueue_style( $h, ...$a ) { $GLOBALS['va_styles'][] = $h; }
	function apply_filters( $tag, $value, ...$args ) {
		foreach ( $GLOBALS['va_filters'][ $tag ] ?? [] as $cb ) {
			$value = $cb( $value, ...$args );
		}
		return $value;
	}
	function vireo_get_settings() {
		return array_merge( [ 'admin_bar_enabled' => true ], $GLOBALS['va_settings'] );
	}

	class Fake_Bar {
		public array $nodes = [];
		public function add_node( $n ) { $this->nodes[] = $n['id']; }
	}

	require __DIR__ . '/../includes/class-admin-bar.php';

	$fails = 0;
	$pass  = 0;
	function check( $label, $cond ) {
		global $fails, $pass;
		if ( $cond ) { $pass++; echo "PASS {$label}\n"; } else { $fails++; echo "FAIL {$label}\n"; }
	}
	function shown(): bool {
		$GLOBALS['va_styles'] = [];
		$bar = new Fake_Bar();
		$ab  = \VireoAnalytics\Admin_Bar::instance();
		$ab->add_menu( $bar );
		$ab->enqueue_assets();
		$node = in_array( 'va-stats', $bar->nodes, true );
		$css  = in_array( 'va-admin-bar', $GLOBALS['va_styles'], true );
		if ( $node !== $css ) {
			echo "  (node and stylesheet disagree)\n";
		}
		return $node && $css;
	}

	check( 'on by default when the setting was never saved', shown() );

	$GLOBALS['va_settings'] = [ 'admin_bar_enabled' => false ];
	check( 'switched off in Settings hides node and stylesheet', ! shown() );

	$GLOBALS['va_filters']['vireo_show_admin_bar'] = [ fn( $v, $uid ) => 7 === $uid ];
	check( 'filter can turn it back on for one user', shown() );

	$GLOBALS['va_settings']                        = [ 'admin_bar_enabled' => true ];
	$GLOBALS['va_filters']['vireo_show_admin_bar'] = [ fn( $v, $uid ) => false ];
	check( 'filter can hide it for one user while the setting is on', ! shown() );

	$GLOBALS['va_filters'] = [];
	$GLOBALS['va_caps']    = [];
	check( 'users without access never get it', ! shown() );

	$GLOBALS['va_filters']['vireo_show_admin_bar'] = [ fn() => true ];
	check( 'the filter cannot grant it to users without access', ! shown() );

	echo "\n{$pass} passed, {$fails} failed\n";
	exit( $fails ? 1 : 0 );
}
