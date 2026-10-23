<?php
/**
 * Keeps other plugins' admin notices off Vireo's own screens.
 *
 * A dashboard is a reading surface, and WordPress lets every installed plugin
 * print banners onto it. Freemius-based plugins in particular register sticky
 * opt-in prompts with no screen check, so an unrelated plugin's marketing sits
 * above your traffic numbers.
 *
 * Only foreign notices go. Vireo's own warnings stay, because some of them say
 * the thing has stopped recording, and a plugin that hides its own alarm to
 * look tidy is worse than one that looks untidy.
 */

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

final class Screen_Quiet {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// `in_admin_header` fires before both notice hooks are run, which is the
		// last point where removing a callback still prevents its output.
		add_action( 'in_admin_header', [ $this, 'silence_foreign_notices' ] );
	}

	/**
	 * True when the current request is one of our admin screens.
	 */
	private function is_vireo_screen(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! isset( $screen->id ) ) {
			return false;
		}
		return false !== strpos( (string) $screen->id, 'vireo-analytics' );
	}

	public function silence_foreign_notices(): void {
		if ( ! $this->is_vireo_screen() ) {
			return;
		}

		foreach ( [ 'admin_notices', 'all_admin_notices', 'user_admin_notices', 'network_admin_notices' ] as $hook ) {
			$this->strip_hook( $hook );
		}
	}

	/**
	 * Drop every callback on $hook whose code lives outside this plugin.
	 */
	private function strip_hook( string $hook ): void {
		global $wp_filter;

		if ( empty( $wp_filter[ $hook ] ) || ! isset( $wp_filter[ $hook ]->callbacks ) ) {
			return;
		}

		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $entry ) {
				$cb = $entry['function'] ?? null;
				if ( null === $cb || $this->is_ours( $cb ) ) {
					continue;
				}
				remove_action( $hook, $cb, $priority );
			}
		}
	}

	/**
	 * Whether a callback should be left alone.
	 *
	 * Ours stays, and so does WordPress core's: the update nag is a security
	 * prompt, and suppressing it so a dashboard looks tidier trades the site
	 * owner's interest for ours. What goes is third-party plugin and theme
	 * output from wp-content.
	 *
	 * Resolved from the file the callback was declared in rather than from its
	 * name, because a name is a hypothesis and plugins are free to prefix their
	 * functions with anything they like.
	 *
	 * @param callable|string|array $cb
	 */
	private function is_ours( $cb ): bool {
		try {
			if ( is_string( $cb ) && function_exists( $cb ) ) {
				$ref = new \ReflectionFunction( $cb );
			} elseif ( $cb instanceof \Closure ) {
				$ref = new \ReflectionFunction( $cb );
			} elseif ( is_array( $cb ) && 2 === count( $cb ) ) {
				$ref = new \ReflectionMethod( $cb[0], (string) $cb[1] );
			} elseif ( is_object( $cb ) && method_exists( $cb, '__invoke' ) ) {
				$ref = new \ReflectionMethod( $cb, '__invoke' );
			} else {
				// Can't place it, so leave it alone. Removing a notice we cannot
				// identify risks hiding something the site owner needs.
				return true;
			}

			$file = (string) $ref->getFileName();
			if ( '' === $file ) {
				return true;
			}
			$file = wp_normalize_path( $file );

			if ( 0 === strpos( $file, wp_normalize_path( VIREO_DIR ) ) ) {
				return true;
			}
			// Pro is the other half of this interface. Its notices are ours
			// too: stripping them hid its Freemius revenue set-up card on the
			// Settings screen, the one place it renders.
			if ( defined( 'VIREO_PRO_DIR' ) && 0 === strpos( $file, wp_normalize_path( VIREO_PRO_DIR ) ) ) {
				return true;
			}

			// Core lives outside wp-content. Anything declared there is
			// WordPress talking, not another plugin.
			$content = wp_normalize_path( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content' );
			return 0 !== strpos( $file, $content );
		} catch ( \ReflectionException $e ) {
			return true;
		}
	}
}
