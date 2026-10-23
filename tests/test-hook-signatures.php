<?php
/**
 * Zero-dependency check: a callback on a hook the plugin doesn't own takes its
 * arguments untyped (or nullable) and casts them itself.
 * Run: php tests/test-hook-signatures.php
 *
 * Core, WP-CLI, MainWP, WooCommerce and other plugins fire those hooks.
 * WP_Hook calls callbacks in coercive mode, so a scalar of the wrong type is
 * converted, but null, an array or an object reaching a non-nullable typed
 * parameter is a TypeError that takes the request down. vireo_activate( bool
 * $network_wide ) did exactly that on PHP 7.4: WP-CLI passes null on a single
 * site. Hooks the plugin fires itself (vireo_*) keep their types, since the
 * plugin passes typed values.
 *
 * Control: VA_ROOT=<a tree of 0.12.2> fails on vireo_activate and the
 * admin_enqueue_scripts callbacks typed string $hook:
 *   mkdir /tmp/0122 && git archive 60c93db vireo-analytics.php includes importers uninstall.php | tar -x -C /tmp/0122
 *   VA_ROOT=/tmp/0122 php tests/test-hook-signatures.php
 */

$root  = getenv( 'VA_ROOT' ) ?: dirname( __DIR__ );
$files = array_merge(
	glob( "$root/includes/*.php" ),
	glob( "$root/includes/*/*.php" ),
	glob( "$root/importers/*.php" ),
	[ "$root/vireo-analytics.php", "$root/uninstall.php" ]
);
$files = array_values( array_filter( $files, 'is_file' ) );

// Every function signature, per file: name => parameter list.
$sigs = [];
foreach ( $files as $f ) {
	preg_match_all( '/function\s+&?\s*([a-zA-Z0-9_]+)\s*\(([^)]*)\)/s', file_get_contents( $f ), $m, PREG_SET_ORDER );
	foreach ( $m as $x ) {
		$sigs[ $f ][ $x[1] ] = trim( preg_replace( '/\s+/', ' ', $x[2] ) );
	}
}

$checked    = 0;
$external   = 0;
$unresolved = [];
$bad        = [];
foreach ( $files as $f ) {
	$src  = file_get_contents( $f );
	$base = substr( $f, strlen( $root ) + 1 );
	preg_match_all( '/(?:add_action|add_filter|register_activation_hook|register_deactivation_hook|register_uninstall_hook)\s*\(\s*(.*?)\)\s*;/s', $src, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
	foreach ( $m as $x ) {
		$checked++;
		$args = preg_replace( '/\s+/', ' ', $x[1][0] );
		$line = substr_count( substr( $src, 0, $x[0][1] ), "\n" ) + 1;
		// A hook named by a class constant (self::CRON_HOOK) resolves through the constant's value in the same file.
		if ( preg_match( '/^(?:self|static|__CLASS__)::([A-Z_]+)/', $args, $k ) && preg_match( "/const\s+{$k[1]}\s*=\s*['\"]([^'\"]+)['\"]/", $src, $v ) ) {
			$hook = $v[1];
		} else {
			$hook = preg_match( "/^['\"]([^'\"]+)['\"]/", $args, $h ) ? $h[1] : '';
		}
		if ( 0 === strpos( $hook, 'vireo_' ) ) {
			continue;
		}
		$external++;
		if ( preg_match( "/,\s*'__return_[a-z_]+'/", $args ) ) {
			continue; // Core's own helpers take no typed arguments.
		}
		if ( preg_match( '/,\s*(?:static\s+)?(?:function|fn)\s*\(([^)]*)\)/', $args, $c ) ) {
			$params = $c[1];
			$name   = 'closure';
		} elseif ( preg_match( '/,\s*\$([a-z_]+)\s*(?:,|$)/', $args, $c ) && preg_match( '/\$' . $c[1] . '\s*=\s*(?:static\s+)?(?:function|fn)\s*\(([^)]*)\)/', $src, $d ) ) {
			// A closure kept in a variable and registered later ($merge, $capture).
			$params = $d[1];
			$name   = 'closure $' . $c[1];
		} elseif ( preg_match( "/['\"]([a-zA-Z0-9_]+)['\"]\s*\]/", $args, $c ) || preg_match( "/,\s*['\"]([a-zA-Z0-9_]+)['\"]/", $args, $c ) ) {
			$name = $c[1];
			// Methods on another class ([ Other::class, 'm' ]) live in another file; search all.
			$params = $sigs[ $f ][ $name ] ?? null;
			if ( null === $params ) {
				foreach ( $sigs as $per_file ) {
					if ( isset( $per_file[ $name ] ) ) {
						$params = $per_file[ $name ];
						break;
					}
				}
			}
			if ( null === $params ) {
				$unresolved[] = "$base:$line $hook -> $name";
				continue;
			}
		} else {
			$unresolved[] = "$base:$line $hook -> " . substr( $args, 0, 60 );
			continue;
		}
		foreach ( array_filter( array_map( 'trim', explode( ',', $params ) ) ) as $p ) {
			// A type before the variable that isn't nullable (?T, T|null) or mixed.
			if ( preg_match( '/^([?A-Za-z_\\\\|]+)\s+&?\.{0,3}\$/', $p, $t ) && '?' !== $t[1][0] && false === stripos( $t[1], 'null' ) && 'mixed' !== $t[1] ) {
				$bad[] = "$base:$line $hook -> $name( $params )";
				break;
			}
		}
	}
}

$fail = 0;
function ok( $cond, $msg ) {
	global $fail;
	echo ( $cond ? 'PASS: ' : 'FAIL: ' ), $msg, "\n";
	if ( ! $cond ) {
		$fail++;
	}
}

ok( $checked >= 100 && $external >= 60, "harness: found $checked hook registrations, $external on hooks the plugin doesn't own" );
ok( [] === $unresolved, 'every external-hook callback could be resolved to a signature' . ( $unresolved ? ":\n  " . implode( "\n  ", $unresolved ) : '' ) );
ok( [] === $bad, 'no callback on a hook the plugin does not own has a non-nullable typed parameter' . ( $bad ? ":\n  " . implode( "\n  ", $bad ) : '' ) );

echo "\n";
if ( $fail ) {
	echo "$fail test(s) failed.\n";
	exit( 1 );
}
echo "All tests passed.\n";
