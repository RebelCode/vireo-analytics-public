<?php
/**
 * Fills the gaps in a translate.wordpress.org language pack with the
 * catalogue shipped in the zip.
 *
 * WordPress loads one file per text domain: the registry looks in
 * wp-content/languages/plugins first and in the plugin's own languages/ only
 * when nothing is there. A pack approved at 55% therefore hides the complete
 * bundled catalogue, and the dashboard shows English wherever the pack has no
 * string. Loading the pack and then the bundle explicitly makes WordPress keep
 * both: the first file loaded wins per string, so the pack's wording stays
 * wherever a translator approved one, and the bundle covers the rest.
 */

declare(strict_types=1);

namespace VireoAnalytics;

final class Translations {

	private const DOMAIN = 'vireo-analytics';

	/** @var array<string, bool> Locales merged in this request. */
	private static $merged = [];

	public static function boot(): void {
		add_action( 'init', [ __CLASS__, 'merge' ] );
		// switch_to_locale() (digest emails in a user's locale) reloads the
		// domain through the registry, so the pack would win alone again.
		add_action( 'change_locale', [ __CLASS__, 'merge' ] );
	}

	/**
	 * Loads the pack and then the bundled catalogue for the locale. Returns
	 * which files were loaded, so a test can see the order.
	 *
	 * @param mixed $locale The locale, or '' for the current one.
	 * @return string[]
	 */
	public static function merge( $locale = '' ): array {
		$locale = (string) $locale;
		if ( '' === $locale ) {
			$locale = determine_locale();
		}
		if ( ! empty( self::$merged[ $locale ] ) ) {
			return [];
		}
		self::$merged[ $locale ] = true;

		$bundled = self::bundled_file( $locale );
		$pack    = self::pack_file( $locale );
		// No bundle: nothing to add. No pack: WordPress reaches the bundle on
		// its own through the path load_plugin_textdomain() registered.
		if ( ! self::exists( $bundled ) || ! self::exists( $pack ) ) {
			return [];
		}

		$loaded = [];
		foreach ( [ $pack, $bundled ] as $file ) {
			if ( load_textdomain( self::DOMAIN, $file, $locale ) ) {
				$loaded[] = $file;
			}
		}
		return $loaded;
	}

	public static function bundled_file( string $locale ): string {
		return VIREO_DIR . 'languages/' . self::DOMAIN . '-' . $locale . '.mo';
	}

	public static function pack_file( string $locale ): string {
		return WP_LANG_DIR . '/plugins/' . self::DOMAIN . '-' . $locale . '.mo';
	}

	/**
	 * A pack may ship only the PHP form of a catalogue; load_textdomain()
	 * prefers that file when it sits next to the .mo path it is given.
	 */
	private static function exists( string $mofile ): bool {
		return is_readable( $mofile ) || is_readable( substr( $mofile, 0, -3 ) . '.l10n.php' );
	}

	/** Forgets what was merged, for tests. */
	public static function reset(): void {
		self::$merged = [];
	}
}
